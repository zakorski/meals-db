<?php
/**
 * Invoice draft service (directive INV-DRAFT-1).
 *
 * Persistence + service layer for the draft → review/edit → finalize → output
 * stage inserted into the government-invoicing pipelines. Pure
 * persistence/service: NO HTML, NO AJAX (those are INV-DRAFT-2).
 *
 * The draft IS the per-client billing-row set the generators assemble before
 * serialization (see MealsDB_Invoice_Generator::build_*_draft_rows). It is
 * stored encrypted at rest (it carries client/veteran PII) and holds BOTH the
 * immutable generated snapshot and the editable current working copy. On
 * finalize the draft's allocation months are frozen via the existing LB-3
 * finalize_month machinery — the draft, not the engine, is then authoritative
 * for what was billed.
 *
 * Discipline carried over from the rest of the plugin:
 *   - QW-2: fail CLOSED — never persist PII as plaintext (encode_payload).
 *   - STR-LOG boundary: a committed artifact (draft created/finalized) →
 *     audit log; an attempt/failure (encryption failed) → operational trunk.
 *   - Fail-safe: every public method swallows its own \Throwable and returns
 *     a sentinel (0 / null / false) — it never propagates out, mirroring the
 *     loggers and AJAX handlers.
 */
defined('ABSPATH') || exit;

class MealsDB_Invoice_Draft {

    public const PIPELINE_VAC         = 'vac';
    public const PIPELINE_SDNB_LEGACY = 'sdnb_legacy';
    public const PIPELINE_SDNB_NEW    = 'sdnb_new_portal';

    private const PIPELINES = [
        self::PIPELINE_VAC,
        self::PIPELINE_SDNB_LEGACY,
        self::PIPELINE_SDNB_NEW,
    ];

    /** Payload schema version, for forward migration of the JSON shape. */
    private const PAYLOAD_SCHEMA = 1;

    /**
     * Create a draft from a set of phase-2 client rows. Caller (the generator
     * row-builder, Step 5) supplies already-decrypted, serializer-ready rows
     * keyed by client_id. Returns draft_id, or 0 on failure (never throws).
     *
     * @param string              $pipeline      one of the PIPELINE_* constants
     * @param string              $billing_month 'YYYY-MM'
     * @param string              $period_start  'Y-m-d'
     * @param string              $period_end    'Y-m-d'
     * @param array<string,array> $rows          client_id => row
     * @param array               $params        pipeline params minus the period (e.g. ['zone'=>'M'])
     * @return int draft_id, or 0 on failure.
     */
    public static function create(string $pipeline, string $billing_month,
                                  string $period_start, string $period_end,
                                  array $rows, array $params = []): int {
        try {
            if (!in_array($pipeline, self::PIPELINES, true)) {
                return 0;
            }

            $payload = [
                'schema'    => self::PAYLOAD_SCHEMA,
                'generated' => $rows,   // immutable snapshot of what the system produced
                'current'   => $rows,   // editable working copy — starts identical
            ];

            $encoded = MealsDB_Encryption::encode_payload($payload);
            if ($encoded === false) {
                // QW-2 fail-closed: no plaintext PII at rest. Surface as a
                // degraded operational event (STR-LOG: an attempt/failure goes
                // to the trunk, not the audit log), then bail.
                if (class_exists('MealsDB_Event_Log')) {
                    MealsDB_Event_Log::record([
                        'severity'  => 'error',
                        'category'  => 'billing',
                        'subsystem' => 'invoice_draft',
                        'event'     => 'create.encrypt_failed',
                        'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                        'message'   => 'Invoice draft not created: payload encryption failed.',
                    ]);
                }
                return 0;
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::INVOICE_DRAFTS);

            $ok = $wpdb->insert($table, [
                'pipeline'      => $pipeline,
                'billing_month' => $billing_month,
                'period_start'  => $period_start,
                'period_end'    => $period_end,
                'params'        => function_exists('wp_json_encode') ? wp_json_encode($params) : json_encode($params),
                'status'        => 'draft',
                'payload'       => $encoded,
                'row_count'     => count($rows),
                'edit_count'    => 0,
                'created_by'    => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
                'created_at'    => gmdate('Y-m-d H:i:s'),
            ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s']);

            if ($ok === false) {
                return 0;
            }
            $draft_id = (int) $wpdb->insert_id;

            // Audit: a draft was created (committed billing artifact → audit
            // log, NOT the operational trunk — STR-LOG boundary).
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log('invoice_draft_created', $draft_id, 'pipeline', '', $pipeline);
            }

            return $draft_id;
        } catch (\Throwable $e) {
            self::log_error('create', $e);
            return 0;
        }
    }

    /**
     * Load + decrypt a draft. Returns null if missing or undecryptable.
     *
     * @return array|null Associative array of meta columns plus a decoded
     *                    'payload' key (['schema','generated','current']).
     */
    public static function get(int $draft_id): ?array {
        try {
            if ($draft_id <= 0) {
                return null;
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::INVOICE_DRAFTS);

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT draft_id, pipeline, billing_month, period_start, period_end, params,
                        status, payload, row_count, edit_count, created_by, created_at,
                        finalized_by, finalized_at
                 FROM `{$table}` WHERE draft_id = %d",
                $draft_id
            ), ARRAY_A);

            if (!is_array($row) || empty($row)) {
                return null;
            }

            $payload = MealsDB_Encryption::decode_payload((string) ($row['payload'] ?? ''));
            if ($payload === null) {
                // Undecryptable / corrupt payload — surface as missing rather
                // than handing back ciphertext.
                return null;
            }

            $row['payload'] = $payload;
            $row['params']  = isset($row['params']) && $row['params'] !== null
                ? (json_decode((string) $row['params'], true) ?: [])
                : [];

            return $row;
        } catch (\Throwable $e) {
            self::log_error('get', $e);
            return null;
        }
    }

    /**
     * List drafts (newest first), optionally filtered by pipeline / month /
     * status. Meta only — does NOT decrypt or return the payload, so the list
     * view never touches PII.
     *
     * @param array $filters ['pipeline'=>..., 'billing_month'=>..., 'status'=>...]
     * @return array<int,array> meta rows.
     */
    public static function list(array $filters = []): array {
        try {
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::INVOICE_DRAFTS);

            $where  = [];
            $params = [];
            if (!empty($filters['pipeline'])) {
                $where[]  = 'pipeline = %s';
                $params[] = (string) $filters['pipeline'];
            }
            if (!empty($filters['billing_month'])) {
                $where[]  = 'billing_month = %s';
                $params[] = (string) $filters['billing_month'];
            }
            if (!empty($filters['status'])) {
                $where[]  = 'status = %s';
                $params[] = (string) $filters['status'];
            }

            // Payload is deliberately NOT selected — list view needs no PII.
            $sql = "SELECT draft_id, pipeline, billing_month, period_start, period_end,
                           status, row_count, edit_count, created_by, created_at,
                           finalized_by, finalized_at
                    FROM `{$table}`";
            if (!empty($where)) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY created_at DESC, draft_id DESC';

            $prepared = !empty($params) ? $wpdb->prepare($sql, ...$params) : $sql;
            $rows     = $wpdb->get_results($prepared, ARRAY_A);

            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            self::log_error('list', $e);
            return [];
        }
    }

    /**
     * Apply a single field edit to current[client_id][field].
     *
     * Returns the OLD value (for the caller to audit) on success, or false on
     * refusal/failure (status !== 'draft', draft missing, client/row missing,
     * or a DB error). The old value diffs against the CURRENT working copy, not
     * the generated baseline — so a second edit to the same field reports the
     * first edit's value as "old" (T-4 pins this).
     *
     * Bumps edit_count. Leaves generated[client_id][field] untouched (the
     * baseline survives). Validation of $new_value is the CALLER's job
     * (INV-DRAFT-2 server-side validation); this method stores what it's given
     * and does NOT write the audit row (the UI controls action/field naming).
     *
     * @return mixed The prior value on success; false on refusal/failure.
     */
    public static function edit_field(int $draft_id, string $client_id, string $field, $new_value) {
        try {
            $draft = self::get($draft_id);
            if ($draft === null) {
                return false;
            }
            if (($draft['status'] ?? '') !== 'draft') {
                // Refuse edits to a finalized/superseded draft (immutable).
                return false;
            }

            $payload = $draft['payload'];
            if (!isset($payload['current']) || !is_array($payload['current'])) {
                return false;
            }
            if (!array_key_exists($client_id, $payload['current'])
                || !is_array($payload['current'][$client_id])) {
                return false;
            }

            $old_value = $payload['current'][$client_id][$field] ?? null;
            $payload['current'][$client_id][$field] = $new_value;

            $encoded = MealsDB_Encryption::encode_payload($payload);
            if ($encoded === false) {
                return false;
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::INVOICE_DRAFTS);

            // edit_count = edit_count + 1 in the same UPDATE.
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET payload = %s, edit_count = edit_count + 1 WHERE draft_id = %d AND status = 'draft'",
                $encoded,
                $draft_id
            ));

            if ($updated === false) {
                return false;
            }

            return $old_value;
        } catch (\Throwable $e) {
            self::log_error('edit_field', $e);
            return false;
        }
    }

    /**
     * Finalize: freeze the draft and hand its `current` rows to the pipeline's
     * serializer. Refuses if already finalized. Returns the output on success
     * or null on failure.
     *
     * The freeze reuses LB-3 immutability (finalize_month) — NOT a parallel
     * freeze flag on the draft table: the allocation-month finalize IS the
     * source of truth for "this month's billing is locked", so a later
     * allocation rebuild cannot silently recompute a month whose invoice has
     * been finalized.
     *
     * INV-DRAFT-3: per-pipeline serialization is now wired. finalize
     * serializes `current` through the pipeline's PURE serializer and encrypts
     * the bytes FIRST, then — only once the artifact exists — freezes the
     * months (the one-way LB-3 lock) and persists the EXACT bytes
     * (finalized_output) in one guarded status transition. Ordering the
     * fallible work before the freeze means a serialize/encrypt failure leaves
     * the draft editable and its month rebuildable (PR #393 review). The
     * download endpoint (Step 3) streams the persisted bytes — they are NOT
     * regenerated on download.
     *
     * @return array|null The structured output map
     *                    (['pipeline'=>..., 'files'=>[...]]) on success;
     *                    null on failure/refusal.
     */
    /**
     * Reverse a finalize: restore the draft to editable `draft` status and clear
     * the per-client-month finalized locks, so it can be edited or regenerated.
     * Audited WITH a reason. manage_options is enforced at the AJAX boundary.
     *
     * SHARED-LOCK SAFETY (PR #418 review): is_finalized is one row per
     * (client_id, billing_month) — it is SHARED by every finalized draft that
     * covers that client-month, and the flow deliberately allows more than one
     * (generation always makes a NEW draft; finalize treats an already-finalized
     * month as an idempotent no-op). Clearing the lock unconditionally would
     * therefore let the rebuilder mutate allocations behind a STILL-finalized
     * sibling invoice, breaking its immutability. So:
     *   - If other finalized drafts share any of this draft's clients for the
     *     month and $cascade is false, we REFUSE (the AJAX layer surfaces a
     *     confirmation naming the client(s)/month/other invoice and re-calls
     *     with $cascade=true — operator decision, PR #418).
     *   - When we DO proceed we un-finalize the target AND (on cascade) the
     *     conflicting siblings together, then clear each client-month lock only
     *     if NO finalized draft OUTSIDE the un-finalized set still covers it
     *     (reference-counted — also handles a transitive third invoice safely).
     *
     * @param int    $draft_id
     * @param string $reason   Operator-supplied reason (audited; required).
     * @param bool   $cascade  Confirmed sweep of sibling finalized invoices that
     *                          share a client-month with this draft.
     * @return bool  true if the target was un-finalized; false if not found /
     *               not finalized / a refused shared-lock conflict / lost race.
     */
    public static function unfinalize(int $draft_id, string $reason, bool $cascade = false): bool {
        try {
            $draft = self::get($draft_id);
            if ($draft === null) {
                return false;
            }
            if (($draft['status'] ?? '') !== 'finalized') {
                // Only a finalized draft can be un-finalized.
                return false;
            }

            $billing_month = (string) ($draft['billing_month'] ?? '');

            // Map every finalized draft covering this month → its clients (one
            // decrypted pass, reused for the conflict gate AND the reference-
            // counted lock clearing). $has_opaque flags a sibling we could not
            // decrypt — then we cannot prove a lock is free, so we skip clearing.
            $has_opaque = false;
            $map            = self::finalized_client_map($billing_month, $has_opaque);
            $target_clients = $map[$draft_id]['clients'] ?? self::clients_from_draft($draft);

            // Direct conflicts: OTHER finalized drafts sharing >= 1 client.
            $conflict_ids = [];
            foreach ($map as $did => $entry) {
                if ((int) $did === $draft_id) {
                    continue;
                }
                if (!empty(array_intersect_key($entry['clients'], $target_clients))) {
                    $conflict_ids[] = (int) $did;
                }
            }

            if (!empty($conflict_ids) && !$cascade) {
                // Clearing would orphan another finalized invoice's lock. Refuse
                // here (defense in depth for non-AJAX callers); the AJAX layer
                // turns this into a confirm prompt and re-calls with cascade.
                return false;
            }

            // The drafts we will un-finalize: target + (on cascade) the siblings.
            $set_ids = $cascade ? array_merge([$draft_id], $conflict_ids) : [$draft_id];
            $set_ids = array_values(array_unique(array_map('intval', $set_ids)));

            // Flip each draft finalized → draft via the guarded UPDATE. The
            // target MUST flip (else a concurrent change won the race → bail
            // before touching any lock, so we never half-apply).
            $flipped = [];
            foreach ($set_ids as $sid) {
                if (self::flip_draft_to_editable($sid)) {
                    $flipped[] = $sid;
                } elseif ($sid === $draft_id) {
                    return false;
                }
            }

            // Reference-counted lock clearing: clear a client-month lock only if
            // NO finalized draft other than the ones we just flipped covers it.
            if (!$has_opaque && $billing_month !== '' && class_exists('MealsDB_Allocation_Engine')) {
                $flipped_set = array_flip($flipped);

                // Union of clients across every draft we un-finalized (always
                // include the target's clients, even if the map missed it).
                $union = $target_clients;
                foreach ($flipped as $sid) {
                    if (isset($map[$sid]['clients'])) {
                        $union += $map[$sid]['clients'];
                    }
                }

                $engine = new MealsDB_Allocation_Engine();
                foreach (array_keys($union) as $cid) {
                    $cid = (int) $cid;
                    if ($cid <= 0) {
                        continue;
                    }
                    $still_covered = false;
                    foreach ($map as $did => $entry) {
                        if (isset($flipped_set[(int) $did])) {
                            continue; // these are no longer finalized
                        }
                        if (array_key_exists($cid, $entry['clients'])) {
                            $still_covered = true;
                            break;
                        }
                    }
                    if (!$still_covered) {
                        $engine->unfinalize_month($cid, $billing_month);
                        // BC-3: unlocking a month exists precisely so it can
                        // rebuild and pick up orders that arrived while it was
                        // finalized — which the rebuilder skipped and left
                        // queued (a finalized month consumes no dirty flag). Re-
                        // queue the now-open client-month so the next rebuild
                        // materialises them. Without this, the unlock appears to
                        // do nothing until some unrelated order re-dirties it.
                        if (class_exists('MealsDB_Allocation_Rebuilder')) {
                            (new MealsDB_Allocation_Rebuilder())->mark_dirty($cid, $billing_month);
                        }
                    }
                }
            }

            // Audit each un-finalized draft WITH the reason (committed artifact
            // change → audit log). Cascaded siblings note the sweep.
            if (class_exists('MealsDB_Logger')) {
                foreach ($flipped as $sid) {
                    $r = ($sid === $draft_id)
                        ? (string) $reason
                        : sprintf('%s [cascade: un-finalized with draft #%d]', (string) $reason, $draft_id);
                    MealsDB_Logger::log('invoice_draft_unfinalized', $sid, 'reason', 'finalized', $r);
                }
            }

            return in_array($draft_id, $flipped, true);
        } catch (\Throwable $e) {
            self::log_error('unfinalize', $e);
            return false;
        }
    }

    /**
     * The OTHER finalized drafts that would lose a shared client-month lock if
     * this draft were un-finalized. Each entry:
     *   ['draft_id'=>int, 'pipeline'=>string, 'billing_month'=>string,
     *    'shared_clients'=>[client_id => display name]]
     * Empty when the draft is missing, not finalized, or shares no client with
     * any sibling. Pure read (used by the AJAX layer to build the confirm prompt).
     */
    public static function get_unfinalize_conflicts(int $draft_id): array {
        try {
            $draft = self::get($draft_id);
            if ($draft === null || ($draft['status'] ?? '') !== 'finalized') {
                return [];
            }
            $billing_month  = (string) ($draft['billing_month'] ?? '');
            $has_opaque     = false;
            $map            = self::finalized_client_map($billing_month, $has_opaque);
            $target_clients = $map[$draft_id]['clients'] ?? self::clients_from_draft($draft);

            $out = [];
            foreach ($map as $did => $entry) {
                if ((int) $did === $draft_id) {
                    continue;
                }
                $shared = array_intersect_key($entry['clients'], $target_clients);
                if (!empty($shared)) {
                    $out[] = [
                        'draft_id'       => (int) $did,
                        'pipeline'       => $entry['pipeline'],
                        'billing_month'  => $billing_month,
                        'shared_clients' => $shared,
                    ];
                }
            }
            return $out;
        } catch (\Throwable $e) {
            self::log_error('get_unfinalize_conflicts', $e);
            return [];
        }
    }

    /**
     * Map of every FINALIZED draft covering $billing_month → its metadata +
     * client set (decrypted). Shape: [draft_id => ['pipeline'=>string,
     * 'clients'=>[client_id => display name]]]. $has_opaque is set true if a
     * listed finalized draft could not be decrypted (its coverage is unknown).
     */
    private static function finalized_client_map(string $billing_month, bool &$has_opaque = false): array {
        $map = [];
        if ($billing_month === '') {
            return $map;
        }
        $rows = self::list(['billing_month' => $billing_month, 'status' => 'finalized']);
        foreach ($rows as $meta) {
            $did = (int) ($meta['draft_id'] ?? 0);
            if ($did <= 0) {
                continue;
            }
            $full = self::get($did);
            if ($full === null) {
                // Undecryptable finalized draft — coverage unknown. Caller must
                // not clear locks it cannot prove are free.
                $has_opaque = true;
                continue;
            }
            $map[$did] = [
                'pipeline' => (string) ($meta['pipeline'] ?? ($full['pipeline'] ?? '')),
                'clients'  => self::clients_from_draft($full),
            ];
        }
        return $map;
    }

    /** [client_id => display name] from a decrypted draft's `current` rows. */
    private static function clients_from_draft(array $draft): array {
        $current = (isset($draft['payload']['current']) && is_array($draft['payload']['current']))
            ? $draft['payload']['current'] : [];
        $out = [];
        foreach ($current as $cid => $row) {
            $cidi = (int) $cid;
            if ($cidi <= 0) {
                continue;
            }
            $fn = is_array($row) && isset($row['first_name']) ? trim((string) $row['first_name']) : '';
            $ln = is_array($row) && isset($row['last_name'])  ? trim((string) $row['last_name'])  : '';
            $out[$cidi] = trim($fn . ' ' . $ln);
        }
        return $out;
    }

    /**
     * Guarded status transition finalized → draft, clearing the finalized
     * metadata + captured output. Returns true only if a still-finalized row
     * actually flipped (atomic: a lost race / already-changed row affects 0).
     */
    private static function flip_draft_to_editable(int $draft_id): bool {
        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::INVOICE_DRAFTS);
        $updated = $wpdb->update(
            $table,
            [
                'status'           => 'draft',
                'finalized_by'     => null,
                'finalized_at'     => null,
                'finalized_output' => null,
            ],
            ['draft_id' => $draft_id, 'status' => 'finalized'],
            ['%s', '%d', '%s', '%s'],
            ['%d', '%s']
        );
        return $updated !== false && (int) $updated > 0;
    }

    public static function finalize(int $draft_id) {
        try {
            $draft = self::get($draft_id);
            if ($draft === null) {
                return null;
            }
            if (($draft['status'] ?? '') !== 'draft') {
                // Already finalized (or superseded) — refuse to re-finalize.
                return null;
            }

            $payload       = $draft['payload'];
            $current        = (isset($payload['current']) && is_array($payload['current']))
                ? $payload['current'] : [];
            $billing_month = (string) ($draft['billing_month'] ?? '');
            $pipeline      = (string) ($draft['pipeline'] ?? '');

            // ORDER MATTERS (PR #393 review): serialize + encrypt BEFORE
            // freezing the months. finalize_month is a one-way lock — once a
            // client-month is finalized the rebuilder will not touch it (LB-3).
            // If we froze first and THEN failed to produce/encrypt the artifact
            // (unknown pipeline, serializer threw, encryption unavailable), we
            // would leave an editable, un-finalized draft whose allocations are
            // nonetheless locked — so a retry or correction could no longer
            // rebuild the billed month even though NO finalized invoice exists.
            // Doing the fallible work first means an early return leaves the
            // months untouched and the draft fully recoverable.

            // Step 6.2 (INV-DRAFT-3) — serialize `current` through the
            // pipeline's PURE serializer. The serializer takes ROWS, not a
            // date, and never re-queries — so it formats Janet's EDITED rows,
            // not a fresh generation, and is side-effect-free.
            $output = self::serialize_current($pipeline, $current, $draft);
            if ($output === null) {
                // Unknown pipeline / serializer failure — do NOT finalize (and,
                // critically, do NOT freeze) a draft we cannot produce a
                // downloadable artifact for.
                return null;
            }

            // Capture the EXACT bytes at finalize time (immutable government
            // artifact — Step 2 rationale). Encrypt at rest (QW-2 fail-closed):
            // the output carries the same PII as the payload; the VAC PDF rides
            // as base64 inside the encrypted blob.
            $encoded_output = MealsDB_Encryption::encode_payload($output);
            if ($encoded_output === false) {
                if (class_exists('MealsDB_Event_Log')) {
                    MealsDB_Event_Log::record([
                        'severity'  => 'error',
                        'category'  => 'billing',
                        'subsystem' => 'invoice_draft',
                        'event'     => 'finalize.encrypt_failed',
                        'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                        'message'   => 'Invoice draft not finalized: finalized-output encryption failed.',
                    ]);
                }
                // Months are still untouched — the draft remains editable and
                // its month rebuildable. Bail before any freeze.
                return null;
            }

            // Step 6.3 — NOW that the artifact exists and is encrypted, freeze
            // every client's allocation month via the SAME method LB-1/LB-3 use.
            // finalize_month is idempotent (re-setting is_finalized=1 is a
            // no-op), so finalizing a month a prior draft already locked must
            // NOT error (T-6). This sits immediately before the guarded UPDATE
            // so the freeze and the status transition share the same tail: if a
            // concurrent request already finalized this draft (lost race below),
            // the winner also froze, so the months SHOULD be frozen — the
            // freeze here is then a harmless idempotent no-op.
            if ($billing_month !== '' && class_exists('MealsDB_Allocation_Engine')) {
                $engine = new MealsDB_Allocation_Engine();
                foreach (array_keys($current) as $client_id) {
                    $cid = (int) $client_id;
                    if ($cid > 0) {
                        $engine->finalize_month($cid, $billing_month);
                    }
                }
            }

            // Step 6.4 — mark finalized + persist the captured artifact in ONE
            // guarded UPDATE (the status='draft' WHERE clause keeps the
            // transition atomic).
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::INVOICE_DRAFTS);
            $finalized = $wpdb->update(
                $table,
                [
                    'status'           => 'finalized',
                    'finalized_by'     => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
                    'finalized_at'     => gmdate('Y-m-d H:i:s'),
                    'finalized_output' => $encoded_output,
                ],
                ['draft_id' => $draft_id, 'status' => 'draft'],
                ['%s', '%d', '%s', '%s'],
                ['%d', '%s']
            );

            // $wpdb->update() returns the affected-row count, or false on
            // error. ZERO affected rows means our guarded WHERE (status =
            // 'draft') matched nothing — i.e. another request finalized or
            // superseded this draft between self::get() above and here. Treat
            // that lost race as a refusal: do NOT audit-log or return $output
            // as if THIS request produced the finalized artifact, which would
            // let a caller emit a duplicate/stale finalized invoice. (The
            // per-client finalize_month locks above are idempotent, so the
            // winner's lock stands; only the draft-row transition is ours to
            // claim, and we didn't win it.)
            if ($finalized === false || (int) $finalized === 0) {
                return null;
            }

            // Step 6.5 — audit (committed artifact → audit log, STR-LOG).
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log('invoice_draft_finalized', $draft_id, 'status', 'draft', 'finalized');
            }

            return $output;
        } catch (\Throwable $e) {
            self::log_error('finalize', $e);
            return null;
        }
    }

    /**
     * Serialize a draft's `current` rows into the per-pipeline downloadable
     * artifact(s) (INV-DRAFT-3 Step 2). Returns a structured map:
     *
     *   ['pipeline' => <pipeline>, 'files' => [
     *       'csv' => ['mime'=>'text/csv', 'filename'=>..., 'content'=><string>],
     *       'pdf' => ['mime'=>'application/pdf', 'filename'=>..., 'b64'=><base64>], // VAC only
     *   ]]
     *
     * The CSV content is a string; the (VAC) PDF rides as base64 ('b64') so the
     * whole map survives JSON encoding inside the encrypted blob. Returns null
     * on an unknown pipeline.
     *
     * PDF generation is BEST-EFFORT: if dompdf is unavailable (or throws), the
     * CSV still finalizes and the PDF file is simply omitted — the download
     * endpoint then reports the PDF unavailable rather than the finalize 500ing
     * on an environment without the renderer.
     */
    private static function serialize_current(string $pipeline, array $current, array $draft): ?array {
        $billing_month = (string) ($draft['billing_month'] ?? '');
        $period_start  = (string) ($draft['period_start'] ?? '');
        $period_end    = (string) ($draft['period_end'] ?? '');
        $params        = is_array($draft['params'] ?? null) ? $draft['params'] : [];

        // Filename-safe month slug (e.g. "2025-01").
        $slug_month = preg_replace('/[^0-9A-Za-z\-]/', '', $billing_month);
        if ($slug_month === '' || $slug_month === null) {
            $slug_month = 'month';
        }

        if (!class_exists('MealsDB_Invoice_Generator')) {
            return null;
        }

        switch ($pipeline) {
            case self::PIPELINE_VAC:
                $csv   = MealsDB_Invoice_Generator::serialize_vac_csv($current);
                $files = [
                    'csv' => [
                        'mime'     => 'text/csv',
                        'filename' => 'vac-' . $slug_month . '.csv',
                        'content'  => $csv,
                    ],
                ];
                // Stage 2 PDF over the EDITED CSV (not a fresh generation).
                try {
                    $pdf = MealsDB_Invoice_Generator::serialize_vac_pdf_from_csv($csv, $period_end);
                    if (is_string($pdf) && $pdf !== '') {
                        $files['pdf'] = [
                            'mime'     => 'application/pdf',
                            'filename' => 'vac-' . $slug_month . '.pdf',
                            'b64'      => base64_encode($pdf),
                        ];
                    }
                } catch (\Throwable $e) {
                    // Best-effort: CSV still finalizes. Surface as degraded.
                    self::log_error('finalize_vac_pdf', $e);
                }
                return ['pipeline' => $pipeline, 'files' => $files];

            case self::PIPELINE_SDNB_LEGACY:
                $zone = isset($params['zone']) ? (string) $params['zone'] : 'M';
                $csv  = MealsDB_Invoice_Generator::serialize_sdnb_legacy($current, [
                    'zone'       => $zone,
                    'start_date' => $period_start,
                    'end_date'   => $period_end,
                ]);
                return ['pipeline' => $pipeline, 'files' => [
                    'csv' => [
                        'mime'     => 'text/csv',
                        'filename' => 'sdnb-legacy-' . strtolower($zone) . '-' . $slug_month . '.csv',
                        'content'  => $csv,
                    ],
                ]];

            case self::PIPELINE_SDNB_NEW:
                $csv = MealsDB_Invoice_Generator::serialize_sdnb_new_portal($current);
                return ['pipeline' => $pipeline, 'files' => [
                    'csv' => [
                        'mime'     => 'text/csv',
                        'filename' => 'sdnb-new-portal-' . $slug_month . '.csv',
                        'content'  => $csv,
                    ],
                ]];
        }

        return null;
    }

    /**
     * Load + decrypt a finalized draft's captured output (INV-DRAFT-3 Step 3,
     * for the download endpoint). Returns the structured ['pipeline','files']
     * map, or null if the draft is missing, NOT finalized, or has no
     * decryptable finalized_output.
     */
    public static function get_finalized_output(int $draft_id): ?array {
        try {
            if ($draft_id <= 0) {
                return null;
            }
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::INVOICE_DRAFTS);
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT status, finalized_output FROM `{$table}` WHERE draft_id = %d",
                $draft_id
            ), ARRAY_A);

            if (!is_array($row) || ($row['status'] ?? '') !== 'finalized') {
                return null;
            }
            $stored = (string) ($row['finalized_output'] ?? '');
            if ($stored === '') {
                return null;
            }
            $decoded = MealsDB_Encryption::decode_payload($stored);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            self::log_error('get_finalized_output', $e);
            return null;
        }
    }

    /**
     * Internal: breadcrumb a swallowed exception. Fail-safe — never throws.
     */
    private static function log_error(string $op, \Throwable $e): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Invoice_Draft] ' . $op . ' failed: ' . $e->getMessage());
        } else {
            error_log('[MealsDB Invoice_Draft] ' . $op . ' failed: ' . $e->getMessage());
        }
    }
}
