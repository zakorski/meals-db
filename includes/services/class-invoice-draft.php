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
     * INV-DRAFT-1 SKELETON: per-pipeline serialization is wired in INV-DRAFT-3.
     * For now finalize returns the (possibly-edited) `current` row set — the
     * point of doing the skeleton here is the freeze semantics, which the
     * schema and service must support from day one.
     *
     * @return mixed The serialized output (INV-DRAFT-3) or, for now, the
     *               `current` row map; null on failure/refusal.
     */
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

            // Step 6.3 — freeze every client's allocation month via the SAME
            // method LB-1/LB-3 use. finalize_month is idempotent (re-setting
            // is_finalized=1 is a no-op), so finalizing a month that a prior
            // draft already locked must NOT error (T-6).
            if ($billing_month !== '' && class_exists('MealsDB_Allocation_Engine')) {
                $engine = new MealsDB_Allocation_Engine();
                foreach (array_keys($current) as $client_id) {
                    $cid = (int) $client_id;
                    if ($cid > 0) {
                        $engine->finalize_month($cid, $billing_month);
                    }
                }
            }

            // Step 6.2 — serialize `current` through the pipeline's existing
            // serializer. Wired per-pipeline in INV-DRAFT-3 (VAC first); the
            // skeleton returns the row set so the freeze + status transition
            // are exercisable now.
            $output = $current;

            // Step 6.4 — mark the draft finalized.
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::INVOICE_DRAFTS);
            $finalized = $wpdb->update(
                $table,
                [
                    'status'       => 'finalized',
                    'finalized_by' => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
                    'finalized_at' => gmdate('Y-m-d H:i:s'),
                ],
                ['draft_id' => $draft_id, 'status' => 'draft'],
                ['%s', '%d', '%s'],
                ['%d', '%s']
            );

            if ($finalized === false) {
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
