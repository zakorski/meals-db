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

            // SKU is NOT in meals_clients/meals_products; resolve it from the QO
            // product catalogue (product_id => WC SKU) and hand it to the pure
            // builder so build_rows_from_orders stays DB-free. Added items use the
            // same catalogue, so snapshot and added SKUs share one source.
            $sku_by_pid = [];
            if (class_exists('MealsDB_Quick_Order_Products')) {
                foreach (MealsDB_Quick_Order_Products::get_all_quick_order_products() as $p) {
                    $pid = (int) ($p['product_id'] ?? 0);
                    if ($pid > 0) {
                        $sku_by_pid[$pid] = (string) ($p['sku'] ?? '');
                    }
                }
            }

            return self::build_rows_from_orders($orders, $clients, $sku_by_pid);
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
     * @param array<int, string>               $sku_by_pid Optional wc_product_id => SKU map, resolved
     *        by the caller (this method stays pure / no DB). Missing pid → ''.
     * @return array<int, array<string, mixed>> Rows keyed by order_id.
     */
    public static function build_rows_from_orders(array $orders, array $clients, array $sku_by_pid = []): array {
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
                    'sku'          => (string) ($sku_by_pid[$pid] ?? ''),
                    'qty'          => $qty,
                ];
            }

            // delivery_occurrence is injected by get_orders_for_delivery_range()
            // (the computed delivery date, not the creation date). Fall back to
            // the date portion of date_created_gmt only when absent.
            $delivery_date = (string) ($order['delivery_occurrence']
                ?? substr((string) ($order['date_created_gmt'] ?? ''), 0, 10));

            $rows[$oid] = [
                'order_id'         => $oid,
                'wp_user_id'       => $uid,
                'client_id'        => (int) ($client['client_id'] ?? 0),
                'client_name'      => trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? '')),
                'client_last_name' => (string) ($client['last_name'] ?? ''),
                'zone'             => (string) ($client['delivery_area_zone'] ?? ''),
                'delivery_date'    => $delivery_date,
                'items'            => $items,
                'mains_count'      => $mains,
                'sides_count'      => $sides,
                'audit_status'     => self::ROW_PENDING,
                'edited_items'     => [],
                'added_items'      => [],
                'note'             => '',
                'audited_by'       => 0,
                'audited_at'       => '',
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
            // Pre-encode every row's PII columns up front, so a single encryption
            // failure aborts the whole create (QW-2 fail-closed) BEFORE any write
            // — never a partially-stored audit, never plaintext. encode_row_columns
            // returns null iff detail/edits encryption failed.
            $db_rows = [];
            foreach ($rows as $entry) {
                $encoded = self::encode_row_columns((array) $entry);
                if ($encoded === null) {
                    self::record_degraded('create.encrypt_failed', 'Order audit not created: row encryption failed.');
                    return 0;
                }
                $db_rows[] = $encoded;
            }

            global $wpdb;
            $audits = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);

            // The legacy `payload` column is retired but still NOT NULL — write
            // '' (get() reconstructs from rows and never reads it again). rev
            // starts at 0; it is bumped on every draft mutation (see mutate_row).
            $ok = $wpdb->insert($audits, [
                'week_start'      => $week_start,
                'week_end'        => $week_end,
                'status'          => self::STATUS_DRAFT,
                'payload'         => '',
                'rev'             => 0,
                'row_count'       => count($rows),
                'confirmed_count' => 0,
                'edited_count'    => 0,
                'created_by'      => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ], ['%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s']);

            if ($ok === false) {
                return 0;
            }

            $audit_id = (int) $wpdb->insert_id;

            if (!self::insert_rows($audit_id, $db_rows)) {
                // Roll back a partially-created audit so a retry (find_by_week
                // returns 0) starts clean rather than resurrecting a half-row set.
                self::delete_all_rows($audit_id);
                $wpdb->delete($audits, ['audit_id' => $audit_id], ['%d']);
                self::record_degraded('create.rows_insert_failed', 'Order audit not created: row insert failed.');
                return 0;
            }

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
                "SELECT audit_id, week_start, week_end, status, payload, rev,
                        row_count, confirmed_count, edited_count,
                        created_by, created_at, finalized_by, finalized_at,
                        unfinalized_at, unfinalize_reason
                 FROM `{$table}` WHERE audit_id = %d LIMIT 1",
                $audit_id
            ), ARRAY_A);

            if (!is_array($row) || empty($row)) {
                return null;
            }

            // Rebuild the {schema, generated, current} payload shape from the
            // normalized rows so every existing caller (grid, AJAX, finalize)
            // keeps working unchanged.
            $payload = self::load_payload_from_rows($audit_id);
            if ($payload === null) {
                if ((int) ($row['row_count'] ?? 0) === 0) {
                    // A legitimately EMPTY audit (0 delivered orders) has no rows
                    // — that is correct, not a missing-backfill. Reconstruct the
                    // empty payload rather than falling through to the blob.
                    $payload = ['schema' => self::PAYLOAD_SCHEMA, 'generated' => [], 'current' => []];
                } else {
                    // row_count > 0 but no normalized rows: an audit created
                    // before this refactor that has not been backfilled yet — fall
                    // back to the legacy encrypted blob so a mid-deploy read still
                    // works. Undecodable → null, exactly as before (never raw
                    // ciphertext, never a partial array).
                    $legacy = MealsDB_Encryption::decode_payload((string) ($row['payload'] ?? ''));
                    if (!is_array($legacy)) {
                        return null;
                    }
                    $payload = $legacy;
                }
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
                $row['added_items']  = [];
                $row['note']         = '';
                $row['audited_by']   = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
                $row['audited_at']   = gmdate('Y-m-d H:i:s');
            }
            return $row;
        });
    }

    /**
     * Record a discrepancy: adjusted per-item quantities, a note, and/or items
     * that were shipped but not on the original order ($added). Quantities are a
     * map item_key => received qty for the items being changed; $added is the
     * FULL desired list of extra items (replaces the row's added_items). Product
     * name + SKU for added items are resolved server-side from the QO catalogue,
     * never trusted from the client. Edits ARE the audit's reason to exist, so
     * each is audit-logged with its deltas (added items as +product_id:qty).
     *
     * NO inventory effect (directive: the weekly audit never touches stock).
     *
     * @param array<int,int>                 $qtys  item_key => received qty (>= 0)
     * @param array<int,array<string,mixed>> $added list of ['product_id'=>int,'qty'=>int]
     * @return true|WP_Error
     */
    public static function edit_row(int $audit_id, int $order_id, array $qtys, string $note, array $added = []) {
        $note = trim($note);
        if (function_exists('mb_strlen') ? mb_strlen($note) > self::MAX_NOTE_LEN : strlen($note) > self::MAX_NOTE_LEN) {
            return new WP_Error('note_too_long', __('Note is too long (500 characters max).', 'meals-db'));
        }

        // Resolve + validate the added items against the QO catalogue BEFORE the
        // mutation. product_name / sku are taken from the catalogue, never the
        // client (same regenerate-server-side posture as Quick Order). The list
        // REPLACES added_items wholesale, so a removed line is simply absent.
        $catalogue = [];
        if (class_exists('MealsDB_Quick_Order_Products')) {
            foreach (MealsDB_Quick_Order_Products::get_all_quick_order_products() as $p) {
                $pid = (int) ($p['product_id'] ?? 0);
                if ($pid > 0) {
                    $catalogue[$pid] = [
                        'product_name' => (string) ($p['name'] ?? ''),
                        'sku'          => (string) ($p['sku'] ?? ''),
                    ];
                }
            }
        }
        $clean_added = [];
        foreach ($added as $entry) {
            if (!is_array($entry)) {
                return new WP_Error('bad_added', __('Malformed added item.', 'meals-db'));
            }
            $pid = (int) ($entry['product_id'] ?? 0);
            $qty = (int) ($entry['qty'] ?? 0);
            if (!isset($catalogue[$pid])) {
                return new WP_Error('unknown_product', __('Added item is not a known product.', 'meals-db'));
            }
            if ($qty < 1) {
                return new WP_Error('bad_added_qty', __('Added item quantity must be at least 1.', 'meals-db'));
            }
            $clean_added[] = [
                'product_id'   => $pid,
                'sku'          => $catalogue[$pid]['sku'],
                'product_name' => $catalogue[$pid]['product_name'],
                'qty'          => $qty,
            ];
        }

        $deltas = [];
        $result = self::mutate_row($audit_id, $order_id, static function (array $row) use ($qtys, $note, $clean_added, &$deltas) {
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
            foreach ($clean_added as $a) {
                $deltas[] = '+' . $a['product_id'] . ':' . $a['qty'];
            }
            $row['audit_status'] = self::ROW_EDITED;
            $row['edited_items'] = $clean;
            $row['added_items']  = $clean_added;
            $row['note']         = $note;
            $row['audited_by']   = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            $row['audited_at']   = gmdate('Y-m-d H:i:s');
            return $row;
        });
        if ($result instanceof WP_Error) {
            return $result;
        }
        // Deltas only — item keys / product ids and counts, no PII. log_lifecycle
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
            $row['added_items']  = [];
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

            // QW-2 fail closed: encode the changed row's mutable PII column BEFORE
            // any write; refuse the mutation rather than store plaintext.
            $edits_enc = MealsDB_Encryption::encode_payload(self::edits_payload($new_row));
            if ($edits_enc === false) {
                self::record_degraded('mutate.encrypt_failed', 'Order-audit row change dropped: row encryption failed.');
                return new WP_Error('encrypt_failed', __('Could not save the change (encryption unavailable).', 'meals-db'));
            }

            // A legacy audit read via the blob fallback has no normalized rows
            // yet — materialise them from the current set before we UPDATE one.
            // No-op (a single COUNT) once the rows exist, which is the steady state.
            self::ensure_rows_materialized($audit_id, $payload['current']);

            global $wpdb;
            $audits = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            // TOCTOU guard: constrain the header UPDATE to a still-draft row.
            // Between the get() above and this write another request could
            // finalize the audit; without status in the WHERE we'd mutate a
            // finalized (read-only) record. `rev = rev + 1` guarantees the value
            // changes even when the denormalized counts don't (a re-edit leaves
            // edited_count identical), so a genuine match always affects 1 row —
            // affected-rows==0 iff the WHERE no longer selects (a finalize won
            // the race). This restores the guarantee the random-IV payload used
            // to provide for free.
            $ok = $wpdb->update($audits, [
                'rev'             => (int) ($audit['rev'] ?? 0) + 1,
                'confirmed_count' => $confirmed,
                'edited_count'    => $edited,
            ], ['audit_id' => $audit_id, 'status' => self::STATUS_DRAFT], ['%d', '%d', '%d'], ['%d', '%s']);
            if ($ok === false) {
                return new WP_Error('db', __('Could not save the change.', 'meals-db'));
            }
            if ($ok === 0) {
                return new WP_Error('conflict', __('This audit changed in another window; reload and try again.', 'meals-db'));
            }

            // Persist the single changed row's mutable columns. Reached only
            // after the header write confirmed the audit was draft this instant.
            $rows_table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDIT_ROWS);
            $rok = $wpdb->update($rows_table, [
                'audit_status' => (string) $new_row['audit_status'],
                'edits_enc'    => $edits_enc,
                'audited_by'   => ((int) ($new_row['audited_by'] ?? 0)) ?: null,
                'audited_at'   => ((string) ($new_row['audited_at'] ?? '')) !== '' ? (string) $new_row['audited_at'] : null,
                'updated_at'   => gmdate('Y-m-d H:i:s'),
            ], ['audit_id' => $audit_id, 'wc_order_id' => $order_id], ['%s', '%s', '%d', '%s', '%s'], ['%d', '%d']);
            if ($rok === false) {
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
            // TOCTOU guard: only a still-draft row may transition to finalized,
            // so two concurrent finalizes can't both "succeed" ($ok === 0 = a
            // finalize/reopen landed first).
            $ok = $wpdb->update($table, [
                'status'       => self::STATUS_FINALIZED,
                'finalized_by' => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
                'finalized_at' => gmdate('Y-m-d H:i:s'),
            ], ['audit_id' => $audit_id, 'status' => self::STATUS_DRAFT], ['%s', '%d', '%s'], ['%d', '%s']);
            if ($ok === false) {
                return new WP_Error('db', __('Could not finalize the audit.', 'meals-db'));
            }
            if ($ok === 0) {
                return new WP_Error('conflict', __('This audit changed in another window; reload and try again.', 'meals-db'));
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
            // TOCTOU guard: only a still-finalized row may reopen ($ok === 0 = a
            // concurrent reopen/delete landed first).
            $ok = $wpdb->update($table, [
                'status'            => self::STATUS_DRAFT,
                'unfinalized_at'    => gmdate('Y-m-d H:i:s'),
                'unfinalize_reason' => $reason,
            ], ['audit_id' => $audit_id, 'status' => self::STATUS_FINALIZED], ['%s', '%s', '%s'], ['%d', '%s']);
            if ($ok === false) {
                return new WP_Error('db', __('Could not reopen the audit.', 'meals-db'));
            }
            if ($ok === 0) {
                return new WP_Error('conflict', __('This audit changed in another window; reload and try again.', 'meals-db'));
            }
            self::log_lifecycle('order_audit_unfinalized', $audit_id, 'reason', null, $reason);
            return true;
        } catch (\Throwable $e) {
            self::log_error('unfinalize', $e);
            return new WP_Error('internal', __('Could not reopen the audit.', 'meals-db'));
        }
    }

    /**
     * One-time migration (audit-storage normalization): materialise the
     * normalized rows for ONE audit from its legacy encrypted payload blob.
     *
     * Idempotent — an audit that already has rows is skipped, so re-running the
     * backfill is safe. Finalized audits migrate BYTE-FAITHFULLY: the frozen
     * `current` entries (their confirmed/edited state) are copied verbatim into
     * rows, so get() reconstructs identical data and no finalized record changes
     * value. Fail-closed per audit: an encryption failure aborts THIS audit
     * (no partial row set) rather than storing plaintext.
     *
     * @return array{status:string, rows:int} status ∈
     *   skipped_has_rows | migrated | empty | undecodable | error
     */
    public static function backfill_rows_for(int $audit_id, bool $dry_run = false): array {
        try {
            if ($audit_id <= 0) {
                return ['status' => 'error', 'rows' => 0];
            }
            global $wpdb;
            $audits     = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $rows_table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDIT_ROWS);

            // Idempotent: never touch an audit that already has rows.
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$rows_table}` WHERE audit_id = %d", $audit_id
            ));
            if ($existing > 0) {
                return ['status' => 'skipped_has_rows', 'rows' => 0];
            }

            $hdr = $wpdb->get_row($wpdb->prepare(
                "SELECT payload, row_count FROM `{$audits}` WHERE audit_id = %d LIMIT 1", $audit_id
            ), ARRAY_A);
            if (!is_array($hdr)) {
                return ['status' => 'error', 'rows' => 0];
            }
            if ((int) ($hdr['row_count'] ?? 0) === 0) {
                // Empty audit (0 delivered orders) — nothing to materialise; get()
                // already reconstructs the empty payload from row_count.
                return ['status' => 'empty', 'rows' => 0];
            }

            $payload = MealsDB_Encryption::decode_payload((string) ($hdr['payload'] ?? ''));
            if (!is_array($payload) || !isset($payload['current']) || !is_array($payload['current'])) {
                return ['status' => 'undecodable', 'rows' => 0];
            }

            $db_rows = [];
            foreach ($payload['current'] as $entry) {
                $encoded = self::encode_row_columns((array) $entry);
                if ($encoded === null) {
                    return ['status' => 'error', 'rows' => 0];
                }
                $db_rows[] = $encoded;
            }

            if ($dry_run) {
                return ['status' => 'migrated', 'rows' => count($db_rows)];
            }
            if (!self::insert_rows($audit_id, $db_rows)) {
                self::delete_all_rows($audit_id);
                return ['status' => 'error', 'rows' => 0];
            }
            return ['status' => 'migrated', 'rows' => count($db_rows)];
        } catch (\Throwable $e) {
            self::log_error('backfill_rows_for', $e);
            return ['status' => 'error', 'rows' => 0];
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
            // TOCTOU guard: delete only while still a draft, so a concurrent
            // finalize landing in the window can't have its record deleted out
            // from under it. $ok === 0 (WHERE no longer matches) is already
            // treated as an error below.
            $ok = $wpdb->delete($table, ['audit_id' => $audit_id, 'status' => self::STATUS_DRAFT], ['%d', '%s']);
            if ($ok === false || $ok === 0) {
                return new WP_Error('db', __('Could not delete the audit draft.', 'meals-db'));
            }
            // Header gone (draft-guarded) — now drop its normalized rows. Order
            // matters: if the header delete lost a finalize race ($ok===0 above)
            // we return before touching the rows, so a finalized audit keeps them.
            self::delete_all_rows($audit_id);
            self::log_lifecycle('order_audit_draft_deleted', $audit_id, 'week_start', (string) $audit['week_start'], null);
            return true;
        } catch (\Throwable $e) {
            self::log_error('delete_draft', $e);
            return new WP_Error('internal', __('Could not delete the audit draft.', 'meals-db'));
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers — normalized row storage
    // -----------------------------------------------------------------------

    /**
     * The immutable snapshot fields kept encrypted at rest (PII: client name +
     * item text). Wrapped so encode_payload's fail-closed contract covers them.
     */
    private static function detail_payload(array $entry): array {
        return [
            'client_name'      => (string) ($entry['client_name'] ?? ''),
            'client_last_name' => (string) ($entry['client_last_name'] ?? ''),
            'items'            => array_values((array) ($entry['items'] ?? [])),
        ];
    }

    /**
     * The mutable "current" review fields kept encrypted at rest. edited_items
     * is a map (item_key => qty) — its keys must be preserved, so NOT
     * array_values(); added_items is a plain list.
     */
    private static function edits_payload(array $entry): array {
        return [
            'edited_items' => (array) ($entry['edited_items'] ?? []),
            'added_items'  => array_values((array) ($entry['added_items'] ?? [])),
            'note'         => (string) ($entry['note'] ?? ''),
        ];
    }

    /**
     * Map one in-memory audit entry to its DB column set (sans audit_id /
     * timestamps, which insert_rows adds). Returns null iff encrypting either
     * PII column failed (QW-2 fail-closed) — the caller must then abort.
     *
     * @return array<string, mixed>|null
     */
    private static function encode_row_columns(array $entry): ?array {
        $detail = MealsDB_Encryption::encode_payload(self::detail_payload($entry));
        $edits  = MealsDB_Encryption::encode_payload(self::edits_payload($entry));
        if ($detail === false || $edits === false) {
            return null;
        }
        $delivery   = (string) ($entry['delivery_date'] ?? '');
        $audited_at = (string) ($entry['audited_at'] ?? '');
        return [
            'wc_order_id'   => (int) ($entry['order_id'] ?? 0),
            'wp_user_id'    => (int) ($entry['wp_user_id'] ?? 0),
            'client_id'     => (int) ($entry['client_id'] ?? 0),
            'zone'          => (string) ($entry['zone'] ?? ''),
            // DATE column: store NULL for a non-Y-m-d value ('' from the builder).
            'delivery_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery) ? $delivery : null,
            'mains_count'   => (int) ($entry['mains_count'] ?? 0),
            'sides_count'   => (int) ($entry['sides_count'] ?? 0),
            'audit_status'  => (string) ($entry['audit_status'] ?? self::ROW_PENDING),
            'audited_by'    => ((int) ($entry['audited_by'] ?? 0)) ?: null,
            'audited_at'    => $audited_at !== '' ? $audited_at : null,
            'detail_enc'    => $detail,
            'edits_enc'     => $edits,
        ];
    }

    /**
     * Insert a pre-encoded row set for an audit. Returns false on the first
     * failed insert (caller rolls back). $db_rows come from encode_row_columns().
     */
    private static function insert_rows(int $audit_id, array $db_rows): bool {
        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDIT_ROWS);
        $now   = gmdate('Y-m-d H:i:s');
        foreach ($db_rows as $r) {
            $r['audit_id']   = $audit_id;
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
            if ($wpdb->insert($table, $r) === false) {
                return false;
            }
        }
        return true;
    }

    /** Delete every normalized row for an audit (create rollback / delete_draft). */
    private static function delete_all_rows(int $audit_id): void {
        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDIT_ROWS);
        $wpdb->delete($table, ['audit_id' => $audit_id], ['%d']);
    }

    /**
     * Rebuild the {schema, generated, current} payload from the normalized rows.
     * Returns null when the audit has NO rows (caller falls back to the legacy
     * blob) or when a row's PII columns won't decode (treated as undecodable, as
     * the old whole-payload path did — never a partial set).
     *
     * generated = base + pristine defaults; current = base + stored mutable cols.
     * The base fields (client, items, counts) are identical in both because an
     * edit only ever touches the mutable fields, never the snapshot.
     */
    private static function load_payload_from_rows(int $audit_id): ?array {
        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDIT_ROWS);
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT wc_order_id, wp_user_id, client_id, zone, delivery_date,
                    mains_count, sides_count, audit_status, audited_by, audited_at,
                    detail_enc, edits_enc
             FROM `{$table}` WHERE audit_id = %d ORDER BY row_id ASC",
            $audit_id
        ), ARRAY_A);

        if (!is_array($rows) || count($rows) === 0) {
            return null;
        }

        $generated = [];
        $current   = [];
        foreach ($rows as $r) {
            $oid = (int) ($r['wc_order_id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            $entry = self::entry_from_row($r);
            if ($entry === null) {
                return null;
            }
            $generated[$oid] = $entry['generated'];
            $current[$oid]   = $entry['current'];
        }

        return [
            'schema'    => self::PAYLOAD_SCHEMA,
            'generated' => $generated,
            'current'   => $current,
        ];
    }

    /**
     * Reconstruct the generated + current in-memory entries from one DB row.
     * Returns null iff a PII column won't decode. Shapes match exactly what
     * build_rows_from_orders() produced, so every caller sees identical data.
     *
     * @return array{generated: array<string,mixed>, current: array<string,mixed>}|null
     */
    private static function entry_from_row(array $r): ?array {
        $detail = MealsDB_Encryption::decode_payload((string) ($r['detail_enc'] ?? ''));
        $edits  = MealsDB_Encryption::decode_payload((string) ($r['edits_enc'] ?? ''));
        if (!is_array($detail) || !is_array($edits)) {
            return null;
        }
        // Shared, immutable base (never mutated after the snapshot).
        $base = [
            'order_id'         => (int) ($r['wc_order_id'] ?? 0),
            'wp_user_id'       => (int) ($r['wp_user_id'] ?? 0),
            'client_id'        => (int) ($r['client_id'] ?? 0),
            'client_name'      => (string) ($detail['client_name'] ?? ''),
            'client_last_name' => (string) ($detail['client_last_name'] ?? ''),
            'zone'             => (string) ($r['zone'] ?? ''),
            'delivery_date'    => (string) ($r['delivery_date'] ?? ''),
            'items'            => array_values((array) ($detail['items'] ?? [])),
            'mains_count'      => (int) ($r['mains_count'] ?? 0),
            'sides_count'      => (int) ($r['sides_count'] ?? 0),
        ];
        $generated = $base + [
            'audit_status' => self::ROW_PENDING,
            'edited_items' => [],
            'added_items'  => [],
            'note'         => '',
            'audited_by'   => 0,
            'audited_at'   => '',
        ];
        $current = $base + [
            'audit_status' => (string) ($r['audit_status'] ?? self::ROW_PENDING),
            'edited_items' => (array) ($edits['edited_items'] ?? []),
            'added_items'  => array_values((array) ($edits['added_items'] ?? [])),
            'note'         => (string) ($edits['note'] ?? ''),
            'audited_by'   => (int) ($r['audited_by'] ?? 0),
            'audited_at'   => (string) ($r['audited_at'] ?? ''),
        ];
        return ['generated' => $generated, 'current' => $current];
    }

    /**
     * Materialise normalized rows for an audit that has none yet (created before
     * this refactor, read via the legacy-blob fallback, now being edited before
     * the one-time backfill ran). Idempotent: a single COUNT short-circuits once
     * rows exist, which is the steady state for every audit created after deploy.
     */
    private static function ensure_rows_materialized(int $audit_id, array $current): void {
        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDIT_ROWS);
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE audit_id = %d", $audit_id
        ));
        if ($count > 0) {
            return;
        }
        $db_rows = [];
        foreach ($current as $entry) {
            $encoded = self::encode_row_columns((array) $entry);
            if ($encoded === null) {
                // Can't materialise; the guarded header write still targets the
                // (missing) row set and the row UPDATE simply affects 0 — no
                // plaintext is written. Leave it to the operator to re-run backfill.
                return;
            }
            $db_rows[] = $encoded;
        }
        self::insert_rows($audit_id, $db_rows);
    }

    // -----------------------------------------------------------------------
    // Private helpers — logging
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
