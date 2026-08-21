<?php
/**
 * Helper utilities for fetching client records for admin screens.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Clients {

    /**
     * Fetch all client types currently stored.
     *
     * @return string[]
     */
    public static function get_client_types(): array {
        global $wpdb;
        if (!$wpdb) {
            return [];
        }

        $repository = new MealsDB_Clients_Repository();

        return $repository->get_client_types();
    }

    /**
     * Fetch a paginated list of clients for the admin table.
     *
     * @param string|array<int,string>|null $client_type  Optional client type filter (string or array for IN()).
     * @param string|null                   $search       Optional search string that matches first or last name.
     * @param bool                          $show_inactive Whether inactive clients should be included in the results.
     * @return array<int, array<string, string|null>>
     */
    public static function get_clients($client_type = null, ?string $search = null, bool $show_inactive = false, int $limit = 100, int $offset = 0): array {
        global $wpdb;
        if (!$wpdb) {
            return [];
        }

        $repository = new MealsDB_Clients_Repository();

        return $repository->search_clients($client_type, $search, $show_inactive, $limit, $offset);
    }

    /**
     * Count clients matching the given filters (for pagination UIs).
     *
     * @param string|array<int,string>|null $client_type
     */
    public static function count_clients($client_type = null, ?string $search = null, bool $show_inactive = false): int {
        global $wpdb;
        if (!$wpdb) {
            return 0;
        }
        $repository = new MealsDB_Clients_Repository();
        return $repository->count_clients($client_type, $search, $show_inactive);
    }

    /**
     * Deactivate a client by ID.
     *
     * @return bool
     */
    public static function deactivate_client(int $client_id): bool {
        return self::set_client_active_status($client_id, 0, 'deactivate_client');
    }

    /**
     * Activate a client by ID.
     *
     * @return bool
     */
    public static function activate_client(int $client_id): bool {
        return self::set_client_active_status($client_id, 1, 'activate_client');
    }

    /**
     * True iff $order_ymd (YYYY-MM-DD) falls in the same month as $today_ymd or
     * the immediately preceding month. Used to decide whether an order is recent
     * enough to reactivate a dormant client (v561 ITEM 4b) — a years-old import
     * must not resurrect anyone. Month arithmetic, DST-proof.
     */
    public static function order_month_qualifies(string $order_ymd, string $today_ymd): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $order_ymd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $today_ymd)) {
            return false;
        }
        $om   = substr($order_ymd, 0, 7);
        $cur  = substr($today_ymd, 0, 7);
        $prev = gmdate('Y-m', strtotime(substr($today_ymd, 0, 7) . '-01 -1 month'));
        return $om === $cur || $om === $prev;
    }

    /**
     * Reactivate a deactivated client when a qualifying order arrives (v561
     * ITEM 4b/4c). ALL must hold: the order is non-cancelled/refunded; its date
     * is in the current or previous billing month; the client exists and is
     * currently inactive. Audited as 'client_reactivated' carrying the order id
     * so it's never a mystery why someone reappeared on an invoice.
     *
     * @return bool true if a client was reactivated.
     */
    public static function maybe_reactivate_on_order(int $wp_user_id, string $order_ymd, string $order_status, int $order_id): bool {
        if ($wp_user_id <= 0) {
            return false;
        }
        // Tolerate 'wc-cancelled' or bare 'cancelled'.
        $status = preg_replace('/^wc-/', '', (string) $order_status);
        if (in_array($status, ['cancelled', 'refunded', 'failed', 'trash'], true)) {
            return false;
        }
        $today = function_exists('current_time') ? current_time('Y-m-d') : gmdate('Y-m-d');
        if (!self::order_month_qualifies($order_ymd, $today)) {
            return false;
        }
        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT client_id, active FROM `{$table}` WHERE wp_user_id = %d LIMIT 1",
            $wp_user_id
        ), ARRAY_A);
        if (!$row || (int) $row['active'] === 1) {
            return false;
        }
        $client_id = (int) $row['client_id'];
        if (!self::activate_client($client_id)) {
            return false;
        }
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('client_reactivated', $client_id, 'active', '0', '1', 'order:' . $order_id);
        }
        return true;
    }

    /**
     * Non-cancelled orders for a client in the current or previous billing month
     * (v561 ITEM 4a). Used to warn before deactivating someone still ordering.
     * Returns ['count' => int, 'last_date' => 'Y-m-d'|''].
     */
    public static function recent_order_summary(int $client_id): array {
        $out = ['count' => 0, 'last_date' => ''];
        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $uid   = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT wp_user_id FROM `{$table}` WHERE client_id = %d LIMIT 1",
            $client_id
        ));
        if ($uid <= 0 || !function_exists('wc_get_orders')) {
            return $out;
        }
        $prev_start = gmdate('Y-m-01', strtotime('first day of last month'));
        $orders = wc_get_orders([
            'customer_id'  => $uid,
            'limit'        => -1,
            'status'       => ['pending', 'processing', 'on-hold', 'completed'],
            'date_created' => '>=' . $prev_start,
            'return'       => 'objects',
        ]);
        $last = '';
        foreach ((array) $orders as $o) {
            if (!is_object($o) || !method_exists($o, 'get_date_created')) {
                continue;
            }
            $out['count']++;
            $d = $o->get_date_created();
            if ($d) {
                $ymd = $d->date('Y-m-d');
                if ($ymd > $last) {
                    $last = $ymd;
                }
            }
        }
        $out['last_date'] = $last;
        return $out;
    }

    /**
     * Whether the current request may perform a DESTRUCTIVE client operation.
     *
     * Fail CLOSED. The previous inline form gated the check on
     * `function_exists('current_user_can')`, so in a context where the WP
     * capability API is unavailable (a non-WP / pre-init / WP-CLI-early path)
     * the guard was skipped ENTIRELY and the destructive op ran unguarded. We
     * cannot verify permission without the capability API, so we refuse — not
     * allow through (audit low-vuln, class-clients.php).
     */
    private static function is_permitted(): bool {
        return function_exists('current_user_can')
            && function_exists('is_user_logged_in')
            && is_user_logged_in()
            && class_exists('MealsDB_Permissions')
            && MealsDB_Permissions::can_access_plugin();
    }

    /**
     * Permanently delete a client and any optionally related rows.
     */
    public static function delete_client(int $client_id): bool {
        // Defence-in-depth: enforce capability here even if a future caller
        // skips the AJAX gate. Deletes cascade across drafts, conflicts,
        // and the client row itself. Fail closed (see is_permitted).
        if (!self::is_permitted()) {
            error_log('[MealsDB] delete_client blocked: insufficient permissions.');
            return false;
        }

        if ($client_id <= 0) {
            return false;
        }

        global $wpdb;
        if (!$wpdb) {
            return false;
        }

        $repository = new MealsDB_Clients_Repository();

        $client_snapshot = null;
        $client_record = $repository->get_client_by_id($client_id);
        if (is_array($client_record)) {
            // client_email is sensitive PII (it is in the logger's SENSITIVE_FIELDS).
            // This snapshot is logged under the field name 'record' as a JSON blob,
            // which bypasses the logger's field-keyed redaction — so fingerprint the
            // email here, BEFORE encoding, rather than writing it in cleartext to the
            // append-only, long-retention audit log. (Name/type stay readable so the
            // deletion audit is still meaningful — names are not in SENSITIVE_FIELDS.)
            $client_snapshot = [
                'first_name' => $client_record['first_name'] ?? null,
                'last_name' => $client_record['last_name'] ?? null,
                'client_type' => $client_record['client_type'] ?? null,
                'client_email' => MealsDB_Logger::fingerprint_value($client_record['client_email'] ?? null),
            ];
        }

        // Verify START TRANSACTION actually succeeded before assuming we
        // have transactional safety; otherwise COMMIT/ROLLBACK below would
        // be no-ops while the destructive deletes still happen.
        //
        // When the transaction cannot be started (DB unreachable, MyISAM
        // storage engine with AUTOCOMMIT, connection in an aborted state),
        // REFUSE the delete rather than proceed with autocommitted
        // destructive work. The repository's delete_client may issue
        // multiple statements internally — without a rollback available
        // a partial failure would leave orphan rows with no recovery.
        $started = $wpdb->query('START TRANSACTION');
        $transaction_started = $started !== false;

        if (!$transaction_started) {
            error_log(sprintf(
                '[MealsDB] delete_client aborted: START TRANSACTION failed (client_id=%d, last_error=%s)',
                $client_id,
                $wpdb->last_error !== '' ? $wpdb->last_error : 'unknown'
            ));
            return false;
        }

        $success = true;

        // NOTE: No cascade to meals_drafts or meals_ignored_conflicts.
        //   - meals_drafts is keyed by `created_by` (the WP user_id of
        //     whoever saved the draft), not client_id. The client
        //     identity is buried in the encrypted JSON payload, which
        //     is intentionally opaque to direct queries. Drafts
        //     auto-prune after 30 days via MealsDB_Drafts.
        //   - meals_ignored_conflicts is keyed by `field_name` +
        //     `ignored_by`. Entries are per-conflict, not per-client.
        // A previous version of this method attempted to DELETE FROM
        // both tables WHERE client_id = X. Neither has that column,
        // so table_has_column guarded the DELETE and silently skipped.
        // The cascade was dead code; orphan drafts and ignored
        // conflicts are operationally harmless.

        if (!$repository->delete_client($client_id)) {
            $success = false;
        }

        // $transaction_started is guaranteed true here: a failed
        // START TRANSACTION returns above, so no guard is needed. The
        // transaction currently wraps a single DELETE and only earns its
        // keep if delete_client() regains a multi-statement cascade.
        if ($success) {
            if ($wpdb->query('COMMIT') === false) {
                error_log('[MealsDB] Failed to commit client deletion transaction.');
                $wpdb->query('ROLLBACK');
                $success = false;
            }
        } else {
            $wpdb->query('ROLLBACK');
        }

        if ($success) {
            $old_value = null;
            if ($client_snapshot !== null) {
                $encoded = json_encode($client_snapshot);
                if ($encoded !== false) {
                    $old_value = $encoded;
                }
            }
            MealsDB_Logger::log('delete_client', $client_id, 'record', $old_value, null);
        }

        return $success;
    }

    /**
     * Update a client's active status and log the change.
     *
     * @return bool
     */
    private static function set_client_active_status(int $client_id, int $active, string $action): bool {
        // Defence-in-depth (Pattern 1, layer 3): re-check capability here as
        // delete_client does, so a future caller reaching activate/deactivate
        // without the AJAX gate can't flip a client's active status. Fail closed.
        if (!self::is_permitted()) {
            error_log('[MealsDB] ' . $action . ' blocked: insufficient permissions.');
            return false;
        }

        global $wpdb;
        if (!$wpdb) {
            return false;
        }

        $repository = new MealsDB_Clients_Repository();

        $old_value = null;
        $existing = $repository->get_client_by_id($client_id);
        if (is_array($existing) && array_key_exists('active', $existing)) {
            $old_value = (string) $existing['active'];
        }

        if (!$repository->update_client($client_id, ['active' => $active])) {
            return false;
        }

        MealsDB_Logger::log($action, $client_id, 'active', $old_value, (string) $active);

        return true;
    }

}
