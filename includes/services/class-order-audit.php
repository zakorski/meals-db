<?php
/**
 * Weekly order audit service (spec 2026-07-30).
 *
 * Persistence + lifecycle for the weekly delivery-paperwork audit: pull the
 * week's delivered orders into an encrypted draft snapshot, let the auditor
 * confirm/correct each order, finalize when every row is resolved. Deliberately
 * copies the MealsDB_Invoice_Draft shape ({generated, current} payload,
 * draft → finalized, unfinalize-with-reason) but shares NO code with it:
 * invoice-draft finalize freezes allocation billing months and serializes
 * government CSVs — both wrong here. RECORD-KEEPING ONLY: nothing in this
 * class touches allocations, billing, or WC orders.
 *
 * Disciplines carried over (CLAUDE.md):
 *   - QW-2 fail CLOSED: payload is encrypted at rest (client names are PII);
 *     an encode failure aborts the write, never stores plaintext.
 *   - STR-LOG boundary: lifecycle + edits (committed record changes) → audit
 *     log; failures → operational trunk (degraded). Per-row CONFIRMS are
 *     attested inside the payload only (~300/week would bloat the append-only
 *     audit log for no investigative value — the discrepancies are the edits).
 *   - Pattern 7: every public method swallows its own \Throwable and returns
 *     a sentinel (0 / null / false / WP_Error).
 */
defined('ABSPATH') || exit;

class MealsDB_Order_Audit {

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_FINALIZED = 'finalized';

    public const ROW_PENDING   = 'pending';
    public const ROW_CONFIRMED = 'confirmed';
    public const ROW_EDITED    = 'edited';

    /** Payload schema version, for forward migration of the JSON shape. */
    private const PAYLOAD_SCHEMA = 1;

    public const MAX_NOTE_LEN = 500; // same cap as PO reconcile notes

    // -----------------------------------------------------------------------
    // Public API — snapshot builder
    // -----------------------------------------------------------------------

    /**
     * Pull all active clients for the week, fetch delivered orders via the
     * slip generator, and return the classified row array keyed by order_id.
     *
     * Returns null on unexpected failure (e.g. DB down), [] when no orders
     * were delivered in the window (not an error).
     *
     * @param string $week_start Y-m-d (Monday).
     * @param string $week_end   Y-m-d (Sunday).
     * @return array<int, array<string, mixed>>|null Rows keyed by order_id, or null on error.
     */
    public static function build_week_rows(string $week_start, string $week_end): ?array {
        try {
            $clients = self::get_delivery_clients();
            if (empty($clients)) {
                return [];
            }

            // MealsDB_Delivery_Slip_Generator requires a MealsDB_WC_Order_Query
            // instance, which in turn wraps $wpdb — mirror the pattern from
            // class-ajax-delivery-slips.php::make_pdf_generator().
            global $wpdb;
            $generator = new MealsDB_Delivery_Slip_Generator(
                new MealsDB_WC_Order_Query($wpdb)
            );
            $orders = $generator->get_orders_for_delivery_range($clients, $week_start, $week_end);

            return self::build_rows_from_orders($orders, $clients);
        } catch (\Throwable $e) {
            self::log_error('build_week_rows', $e);
            return null;
        }
    }

    /**
     * Fetch all active clients with a linked WP user, keyed by wp_user_id.
     * first_name / last_name are NOT encrypted columns — no decrypt step needed.
     *
     * @return array<int, array<string, mixed>> Clients keyed by wp_user_id.
     */
    private static function get_delivery_clients(): array {
        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // wp_user_id > 0 guards against the (valid but edge) case of a client
        // record that was never linked to a WP user — those have no orders.
        $rows = $wpdb->get_results(
            "SELECT client_id, wp_user_id, first_name, last_name,
                    delivery_area_zone, delivery_day, delivery_frequency
             FROM `{$table}`
             WHERE active = 1 AND wp_user_id > 0",
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        $keyed = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['wp_user_id'] ?? 0);
            if ($uid > 0) {
                $keyed[$uid] = $row;
            }
        }
        return $keyed;
    }

    /**
     * Classify a set of orders (as returned by get_orders_for_delivery_range)
     * into the audit row shape. Pure data transformation — no DB access.
     *
     * Fee and overage product lines are stripped from the items list; mains vs
     * sides are counted using the same has_term() check as the slip PDF
     * (MealsDB_Slip_PDF_Generator::resolve_category, ~line 327). Client data is
     * joined from $clients (keyed by wp_user_id).
     *
     * @param array<int, array<string, mixed>> $orders  Orders from get_orders_for_delivery_range().
     * @param array<int, array<string, mixed>> $clients Clients keyed by wp_user_id.
     * @return array<int, array<string, mixed>> Rows keyed by order_id.
     */
    public static function build_rows_from_orders(array $orders, array $clients): array {
        // Build the excluded PID set once. get_fee_product_ids() returns a
        // named assoc ['client_contribution' => int, 'delivery_fee' => int] —
        // use array_values to get the int list, same pattern used elsewhere
        // that calls the method (e.g. class-invoice-generator.php ~line 347).
        $fee_ids = [];
        if (class_exists('MealsDB_Invoice_Generator')) {
            $fee_ids = array_map('intval', array_values(MealsDB_Invoice_Generator::get_fee_product_ids()));
        }
        $overage_ids = array_map('intval', (array) get_option('mealsdb_overage_product_ids', []));
        $excluded    = array_merge($fee_ids, $overage_ids);

        $rows = [];

        foreach ($orders as $order) {
            $oid = (int) ($order['order_id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }

            $uid    = (int) ($order['wp_user_id'] ?? 0);
            $client = $clients[$uid] ?? [];

            $mains = 0;
            $sides = 0;
            $items = [];

            foreach ((array) ($order['items'] ?? []) as $item) {
                $pid = (int) ($item['wc_product_id'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 1);

                // Strip fee / overage lines entirely — they are billing
                // artefacts, not delivery items. The auditor never needs them.
                if ($pid > 0 && in_array($pid, $excluded, true)) {
                    continue;
                }

                // Mirror MealsDB_Slip_PDF_Generator::resolve_category():
                // has_term() on CATEGORY_ID_MAINS → Main, else Side.
                $is_main = $pid > 0
                    && function_exists('has_term')
                    && has_term(MealsDB_Operational_Constants::CATEGORY_ID_MAINS, 'product_cat', $pid);

                if ($is_main) {
                    $mains += $qty;
                } else {
                    $sides += $qty;
                }

                $items[] = [
                    'item_key'     => (int) ($item['order_item_id'] ?? 0),
                    'product_name' => (string) ($item['order_item_name'] ?? ''),
                    'qty'          => $qty,
                ];
            }

            // delivery_occurrence is injected by get_orders_for_delivery_range()
            // (the computed delivery date, not the creation date). Fall back to
            // the date portion of date_created_gmt only when absent.
            $delivery_date = (string) ($order['delivery_occurrence']
                ?? substr((string) ($order['date_created_gmt'] ?? ''), 0, 10));

            $rows[$oid] = [
                'order_id'      => $oid,
                'wp_user_id'    => $uid,
                'client_id'     => (int) ($client['client_id'] ?? 0),
                'client_name'   => trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? '')),
                'zone'          => (string) ($client['delivery_area_zone'] ?? ''),
                'delivery_date' => $delivery_date,
                'items'         => $items,
                'mains_count'   => $mains,
                'sides_count'   => $sides,
                'audit_status'  => self::ROW_PENDING,
                'edited_items'  => [],
                'note'          => '',
                'audited_by'    => 0,
                'audited_at'    => '',
            ];
        }

        return $rows;
    }

    // -----------------------------------------------------------------------
    // Public API — persistence
    // -----------------------------------------------------------------------

    /**
     * Persist a new audit for the given week from a pre-built row array.
     * The payload is encrypted at rest (QW-2 fail CLOSED). Returns the new
     * audit_id, or 0 on any failure (never throws, never stores plaintext).
     *
     * @param string                           $week_start Y-m-d.
     * @param string                           $week_end   Y-m-d.
     * @param array<int, array<string, mixed>> $rows       From build_rows_from_orders().
     * @return int audit_id, or 0 on failure.
     */
    public static function create_for_week(string $week_start, string $week_end, array $rows): int {
        try {
            $payload = [
                'schema'    => self::PAYLOAD_SCHEMA,
                'generated' => $rows,   // immutable snapshot of what the system produced
                'current'   => $rows,   // editable working copy — starts identical
            ];

            $encoded = MealsDB_Encryption::encode_payload($payload);
            if ($encoded === false) {
                // QW-2 fail-closed: refuse to store PII as plaintext. Surface
                // as a degraded event on the operational trunk (STR-LOG: this
                // is an attempt/failure, NOT a committed artifact change).
                self::record_degraded('create.encrypt_failed', 'Order audit not created: payload encryption failed.');
                return 0;
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);

            $ok = $wpdb->insert($table, [
                'week_start'      => $week_start,
                'week_end'        => $week_end,
                'status'          => self::STATUS_DRAFT,
                'payload'         => $encoded,
                'row_count'       => count($rows),
                'confirmed_count' => 0,
                'edited_count'    => 0,
                'created_by'      => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ], ['%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s']);

            if ($ok === false) {
                return 0;
            }

            $audit_id = (int) $wpdb->insert_id;

            // Audit: a new audit record was created (committed artifact → audit
            // log, NOT the operational trunk — STR-LOG boundary). log_lifecycle
            // isolates the write so a broken audit-log backend cannot suppress
            // the returned ID or roll back the record.
            self::log_lifecycle('order_audit_created', $audit_id, 'week_start', null, $week_start);

            return $audit_id;
        } catch (\Throwable $e) {
            self::log_error('create_for_week', $e);
            return 0;
        }
    }

    /**
     * Return the audit_id for the audit whose week_start matches, or 0 if
     * none exists. The caller (AJAX) uses this to guard against duplicate
     * creation — one audit per week.
     *
     * @param string $week_start Y-m-d.
     * @return int audit_id or 0.
     */
    public static function find_by_week(string $week_start): int {
        try {
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT audit_id FROM `{$table}` WHERE week_start = %s LIMIT 1",
                $week_start
            ), ARRAY_A);

            return isset($row['audit_id']) ? (int) $row['audit_id'] : 0;
        } catch (\Throwable $e) {
            self::log_error('find_by_week', $e);
            return 0;
        }
    }

    /**
     * Load and decrypt an audit by ID. Returns null if missing, undecryptable,
     * or on any error. The 'payload' key in the returned array is the decoded
     * array (['schema', 'generated', 'current']).
     *
     * @param int $audit_id
     * @return array|null Audit row with decoded payload, or null.
     */
    public static function get(int $audit_id): ?array {
        try {
            if ($audit_id <= 0) {
                return null;
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT audit_id, week_start, week_end, status, payload,
                        row_count, confirmed_count, edited_count,
                        created_by, created_at, finalized_by, finalized_at,
                        unfinalized_at, unfinalize_reason
                 FROM `{$table}` WHERE audit_id = %d LIMIT 1",
                $audit_id
            ), ARRAY_A);

            if (!is_array($row) || empty($row)) {
                return null;
            }

            $payload = MealsDB_Encryption::decode_payload((string) ($row['payload'] ?? ''));
            if (!is_array($payload)) {
                // Undecryptable / corrupt — return null rather than handing
                // back raw ciphertext or a partial array.
                return null;
            }

            $row['payload'] = $payload;
            return $row;
        } catch (\Throwable $e) {
            self::log_error('get', $e);
            return null;
        }
    }

    /**
     * Return a lightweight list of all audits (no payload decryption).
     * Sorted newest-first, capped at 200 rows (a weekly audit produces ~52/year).
     *
     * @return array<int, array<string, mixed>> List of audit meta rows.
     */
    public static function list_audits(): array {
        try {
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);

            $rows = $wpdb->get_results(
                "SELECT audit_id, week_start, week_end, status,
                        row_count, confirmed_count, edited_count,
                        created_by, created_at, finalized_by, finalized_at
                 FROM `{$table}`
                 ORDER BY week_start DESC
                 LIMIT 200",
                ARRAY_A
            );

            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            self::log_error('list_audits', $e);
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Row mutations (draft only)
    // ------------------------------------------------------------------

    /**
     * Toggle a row confirmed <-> pending. Returns the NEW row status string,
     * or WP_Error. Confirms are attested in the payload (audited_by/at), NOT
     * the audit log — see the class docblock for the volume rationale.
     */
    public static function confirm_row(int $audit_id, int $order_id) {
        return self::mutate_row($audit_id, $order_id, static function (array $row) {
            if ($row['audit_status'] === self::ROW_CONFIRMED) {
                $row['audit_status'] = self::ROW_PENDING;
                $row['audited_by']   = 0;
                $row['audited_at']   = '';
            } else {
                // From pending OR edited: an explicit confirm supersedes.
                $row['audit_status'] = self::ROW_CONFIRMED;
                $row['edited_items'] = [];
                $row['note']         = '';
                $row['audited_by']   = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
                $row['audited_at']   = gmdate('Y-m-d H:i:s');
            }
            return $row;
        });
    }

    /**
     * Record a discrepancy: adjusted per-item quantities + note. Quantities
     * are a map item_key => received qty for the items being changed; items
     * not in the map keep their snapshot qty. Edits ARE the audit's reason
     * to exist, so each one is audit-logged with its deltas.
     *
     * @param array<int,int> $qtys item_key => received qty (>= 0)
     * @return true|WP_Error
     */
    public static function edit_row(int $audit_id, int $order_id, array $qtys, string $note) {
        $note = trim($note);
        if (function_exists('mb_strlen') ? mb_strlen($note) > self::MAX_NOTE_LEN : strlen($note) > self::MAX_NOTE_LEN) {
            return new WP_Error('note_too_long', __('Note is too long (500 characters max).', 'meals-db'));
        }
        $deltas = [];
        $result = self::mutate_row($audit_id, $order_id, static function (array $row) use ($qtys, $note, &$deltas) {
            $known = [];
            foreach ($row['items'] as $item) {
                $known[(int) $item['item_key']] = (int) $item['qty'];
            }
            $clean = [];
            foreach ($qtys as $key => $qty) {
                $key = (int) $key;
                $qty = (int) $qty;
                if (!array_key_exists($key, $known)) {
                    return new WP_Error('unknown_item', __('Unknown order item.', 'meals-db'));
                }
                if ($qty < 0) {
                    return new WP_Error('bad_qty', __('Quantities must be zero or more.', 'meals-db'));
                }
                $clean[$key] = $qty;
                if ($qty !== $known[$key]) {
                    $deltas[] = $key . ':' . $known[$key] . '→' . $qty;
                }
            }
            $row['audit_status'] = self::ROW_EDITED;
            $row['edited_items'] = $clean;
            $row['note']         = $note;
            $row['audited_by']   = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            $row['audited_at']   = gmdate('Y-m-d H:i:s');
            return $row;
        });
        if ($result instanceof WP_Error) {
            return $result;
        }
        // Deltas only — item keys and counts, no PII in old/new. log_lifecycle
        // isolates the write: a broken audit-log backend must not make a
        // successfully stored edit report failure (same rationale as create_for_week).
        self::log_lifecycle('order_audit_row_edited', $audit_id, 'order_' . $order_id,
            null, implode(', ', $deltas) . ($note !== '' ? ' (note)' : ''));
        return true;
    }

    /** Discard an edit (or a confirm) back to pristine pending. @return true|WP_Error */
    public static function revert_row(int $audit_id, int $order_id) {
        $result = self::mutate_row($audit_id, $order_id, static function (array $row) {
            $row['audit_status'] = self::ROW_PENDING;
            $row['edited_items'] = [];
            $row['note']         = '';
            $row['audited_by']   = 0;
            $row['audited_at']   = '';
            return $row;
        });
        return ($result instanceof WP_Error) ? $result : true;
    }

    /**
     * Shared load → mutate one row → re-encrypt → persist path. $mutator gets
     * the current row and returns the replacement (or WP_Error to abort).
     * Returns the new audit_status string, or WP_Error. Draft-only.
     */
    private static function mutate_row(int $audit_id, int $order_id, callable $mutator) {
        try {
            $audit = self::get($audit_id);
            if ($audit === null) {
                return new WP_Error('not_found', __('Audit not found.', 'meals-db'));
            }
            if ($audit['status'] !== self::STATUS_DRAFT) {
                return new WP_Error('finalized', __('This audit is finalized and read-only.', 'meals-db'));
            }
            $payload = $audit['payload'];
            if (!isset($payload['current'][$order_id])) {
                return new WP_Error('row_not_found', __('Order not found in this audit.', 'meals-db'));
            }
            $new_row = $mutator($payload['current'][$order_id]);
            if ($new_row instanceof WP_Error) {
                return $new_row;
            }
            $payload['current'][$order_id] = $new_row;

            $confirmed = 0; $edited = 0;
            foreach ($payload['current'] as $r) {
                if (($r['audit_status'] ?? '') === self::ROW_CONFIRMED) { $confirmed++; }
                if (($r['audit_status'] ?? '') === self::ROW_EDITED)    { $edited++; }
            }

            $encoded = MealsDB_Encryption::encode_payload($payload);
            if ($encoded === false) {
                // QW-2 fail closed: refuse the mutation rather than store plaintext.
                self::record_degraded('mutate.encrypt_failed', 'Order-audit row change dropped: payload encryption failed.');
                return new WP_Error('encrypt_failed', __('Could not save the change (encryption unavailable).', 'meals-db'));
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $ok = $wpdb->update($table, [
                'payload'         => $encoded,
                'confirmed_count' => $confirmed,
                'edited_count'    => $edited,
            ], ['audit_id' => $audit_id], ['%s', '%d', '%d'], ['%d']);
            if ($ok === false) {
                return new WP_Error('db', __('Could not save the change.', 'meals-db'));
            }
            return (string) $new_row['audit_status'];
        } catch (\Throwable $e) {
            self::log_error('mutate_row', $e);
            return new WP_Error('internal', __('Could not save the change.', 'meals-db'));
        }
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    /**
     * Finalize: every row must be confirmed or edited (server-side gate — the
     * JS disable is a convenience, not the enforcement). Locks the audit
     * read-only. No output artifact: the record IS the artifact.
     * @return true|WP_Error
     */
    public static function finalize(int $audit_id) {
        try {
            $audit = self::get($audit_id);
            if ($audit === null) {
                return new WP_Error('not_found', __('Audit not found.', 'meals-db'));
            }
            if ($audit['status'] !== self::STATUS_DRAFT) {
                return new WP_Error('not_draft', __('Only a draft audit can be finalized.', 'meals-db'));
            }
            foreach ($audit['payload']['current'] as $row) {
                if (($row['audit_status'] ?? self::ROW_PENDING) === self::ROW_PENDING) {
                    return new WP_Error('pending_rows',
                        __('Every order must be confirmed or edited before the audit can be saved.', 'meals-db'));
                }
            }
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $ok = $wpdb->update($table, [
                'status'       => self::STATUS_FINALIZED,
                'finalized_by' => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
                'finalized_at' => gmdate('Y-m-d H:i:s'),
            ], ['audit_id' => $audit_id], ['%s', '%d', '%s'], ['%d']);
            if ($ok === false) {
                return new WP_Error('db', __('Could not finalize the audit.', 'meals-db'));
            }
            self::log_lifecycle('order_audit_finalized', $audit_id, 'status', self::STATUS_DRAFT, self::STATUS_FINALIZED);
            return true;
        } catch (\Throwable $e) {
            self::log_error('finalize', $e);
            return new WP_Error('internal', __('Could not finalize the audit.', 'meals-db'));
        }
    }

    /**
     * Reopen a finalized audit. Requires a non-blank typed reason (mirrors the
     * invoice-draft unfinish flow). Row states are untouched. No cascade
     * concept — nothing downstream consumes the audit. @return true|WP_Error
     */
    public static function unfinalize(int $audit_id, string $reason) {
        try {
            $reason = trim($reason);
            if ($reason === '') {
                return new WP_Error('reason_required', __('A reason is required to reopen a finalized audit.', 'meals-db'));
            }
            if (function_exists('mb_strlen') ? mb_strlen($reason) > self::MAX_NOTE_LEN : strlen($reason) > self::MAX_NOTE_LEN) {
                return new WP_Error('reason_too_long', __('Reason is too long (500 characters max).', 'meals-db'));
            }
            $audit = self::get($audit_id);
            if ($audit === null) {
                return new WP_Error('not_found', __('Audit not found.', 'meals-db'));
            }
            if ($audit['status'] !== self::STATUS_FINALIZED) {
                return new WP_Error('not_finalized', __('Only a finalized audit can be reopened.', 'meals-db'));
            }
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $ok = $wpdb->update($table, [
                'status'            => self::STATUS_DRAFT,
                'unfinalized_at'    => gmdate('Y-m-d H:i:s'),
                'unfinalize_reason' => $reason,
            ], ['audit_id' => $audit_id], ['%s', '%s', '%s'], ['%d']);
            if ($ok === false) {
                return new WP_Error('db', __('Could not reopen the audit.', 'meals-db'));
            }
            self::log_lifecycle('order_audit_unfinalized', $audit_id, 'reason', null, $reason);
            return true;
        } catch (\Throwable $e) {
            self::log_error('unfinalize', $e);
            return new WP_Error('internal', __('Could not reopen the audit.', 'meals-db'));
        }
    }

    /**
     * Delete a DRAFT (never a finalized record) so a bad pull can be redone —
     * find_by_week() otherwise blocks regenerating the week. @return true|WP_Error
     */
    public static function delete_draft(int $audit_id) {
        try {
            $audit = self::get($audit_id);
            if ($audit === null) {
                return new WP_Error('not_found', __('Audit not found.', 'meals-db'));
            }
            if ($audit['status'] !== self::STATUS_DRAFT) {
                return new WP_Error('not_draft', __('A finalized audit cannot be deleted.', 'meals-db'));
            }
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $ok = $wpdb->delete($table, ['audit_id' => $audit_id], ['%d']);
            if ($ok === false || $ok === 0) {
                return new WP_Error('db', __('Could not delete the audit draft.', 'meals-db'));
            }
            self::log_lifecycle('order_audit_draft_deleted', $audit_id, 'week_start', (string) $audit['week_start'], null);
            return true;
        } catch (\Throwable $e) {
            self::log_error('delete_draft', $e);
            return new WP_Error('internal', __('Could not delete the audit draft.', 'meals-db'));
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Push a degraded event to the operational trunk (STR-LOG: attempt/failure,
     * not a committed record change). Guarded against missing class so the
     * service is safe to load in test stubs that don't boot the full plugin.
     */
    private static function record_degraded(string $event, string $message): void {
        if (class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'error',
                'category'  => 'audit',
                'subsystem' => 'order_audit',
                'event'     => $event,
                'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message'   => $message,
            ]);
        }
    }

    /**
     * Audit-log a committed change, isolated so a broken audit-log backend
     * cannot make an already-persisted change report failure (the
     * swallowed-error-reported-as-success class, Pattern 7 — but inverted:
     * here the WORK succeeded and only the logging failed).
     */
    private static function log_lifecycle(string $action, int $audit_id, string $field, ?string $old, ?string $new): void {
        if (!class_exists('MealsDB_Logger')) {
            return;
        }
        try {
            MealsDB_Logger::log($action, $audit_id, $field, $old, $new);
        } catch (\Throwable $e) {
            error_log('[MealsDB Order_Audit] audit log write failed: ' . $e->getMessage());
        }
    }

    /**
     * Log an unexpected exception: error_log breadcrumb + degraded event on
     * the operational trunk. Both calls are class_exists-guarded so the
     * service loads safely in CLI / test contexts.
     */
    private static function log_error(string $op, \Throwable $e): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Order_Audit] ' . $op . ' failed: ' . $e->getMessage());
        }
        self::record_degraded($op . '.failed', $e->getMessage());
    }
}
