<?php
/**
 * Backfill WC users who placed active-status orders in the lookback
 * window but don't yet have a meals_clients record. One-time intake
 * sweep to complement the live promotion trigger.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Backfill_Private_Clients {

    /**
     * Default lookback window when callers don't specify one.
     */
    public const DEFAULT_LOOKBACK_MONTHS = 24;

    /**
     * Identify WC users eligible for promotion. Read-only; used for the
     * preview dry-run and as the input to run().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function preview(int $lookback_months = self::DEFAULT_LOOKBACK_MONTHS): array {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return [];
        }

        $lookback_months = max(1, $lookback_months);
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$lookback_months} months"));

        $orders_table    = $wpdb->prefix . 'wc_orders';
        $addresses_table = $wpdb->prefix . 'wc_order_addresses';

        if (!self::table_exists($orders_table) || !self::table_exists($addresses_table)) {
            return [];
        }

        // Active WC status strings — use the wc- prefixed form that
        // the HPOS orders table actually stores.
        $active_statuses = [
            'wc-pending',
            'wc-processing',
            'wc-on-hold',
            'wc-completed',
            'wc-paid',
        ];
        $placeholders = implode(',', array_fill(0, count($active_statuses), '%s'));

        $sql = "
            SELECT DISTINCT o.customer_id
            FROM `{$orders_table}` o
            INNER JOIN `{$addresses_table}` a
                ON a.order_id = o.id AND a.address_type = 'shipping'
            WHERE o.customer_id > 0
              AND o.status IN ({$placeholders})
              AND o.type = 'shop_order'
              AND o.date_created_gmt >= %s
              AND TRIM(COALESCE(a.address_1, '')) <> ''
        ";

        $params = $active_statuses;
        $params[] = $cutoff_date;
        $prepared = $wpdb->prepare($sql, $params);

        $qualifying_user_ids = $wpdb->get_col($prepared);
        if (!is_array($qualifying_user_ids) || empty($qualifying_user_ids)) {
            return [];
        }
        $qualifying_user_ids = array_map('intval', $qualifying_user_ids);

        // Exclude users already tracked in meals_clients.
        $existing_user_ids = MealsDB_Clients_Repository::get_all_wp_user_ids();
        $existing_set = array_flip($existing_user_ids);
        $to_promote = [];
        foreach ($qualifying_user_ids as $uid) {
            if ($uid > 0 && !isset($existing_set[$uid])) {
                $to_promote[] = $uid;
            }
        }

        $rows = [];
        foreach ($to_promote as $uid) {
            $u = get_userdata($uid);
            if (!($u instanceof WP_User)) {
                continue;
            }
            $display_name = trim(((string) ($u->first_name ?? '')) . ' ' . ((string) ($u->last_name ?? '')));
            if ($display_name === '') {
                $display_name = (string) ($u->display_name ?? '');
            }
            $rows[] = [
                'wp_user_id'  => $uid,
                'email'       => (string) ($u->user_email ?? ''),
                'name'        => $display_name,
                'order_count' => function_exists('wc_get_customer_order_count')
                    ? (int) wc_get_customer_order_count($uid)
                    : 0,
            ];
        }

        return $rows;
    }

    /**
     * Promote all eligible users. Individual-user failures do not abort
     * the batch — the stats report how many succeeded vs. errored.
     *
     * @return array{eligible:int, promoted:int, errors:int, skipped:int}
     */
    public static function run(int $lookback_months = self::DEFAULT_LOOKBACK_MONTHS, bool $dry_run = false): array {
        $preview_rows = self::preview($lookback_months);

        $stats = [
            'eligible' => count($preview_rows),
            'promoted' => 0,
            'errors'   => 0,
            'skipped'  => 0,
        ];

        if ($dry_run) {
            return $stats;
        }

        foreach ($preview_rows as $row) {
            $uid = isset($row['wp_user_id']) ? (int) $row['wp_user_id'] : 0;
            if ($uid <= 0) {
                $stats['skipped']++;
                continue;
            }
            try {
                $client_id = MealsDB_Private_Intake::maybe_promote($uid);
                if ($client_id) {
                    $stats['promoted']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log('[MealsDB Backfill] Failed to promote user ' . $uid . ': ' . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Deactivate existing Private meals_clients records whose WC user
     * has no active orders in the lookback window. Returns a preview
     * list before the caller confirms the sweep.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function deactivation_sweep_preview(int $lookback_months = self::DEFAULT_LOOKBACK_MONTHS): array {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return [];
        }

        $lookback_months = max(1, $lookback_months);
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$lookback_months} months"));

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $escaped_clients = str_replace('`', '``', $clients_table);

        $orders_table = $wpdb->prefix . 'wc_orders';
        if (!self::table_exists($orders_table)) {
            return [];
        }

        $private_rows = $wpdb->get_results(
            "SELECT client_id, wp_user_id, first_name, last_name
             FROM `{$escaped_clients}`
             WHERE client_type = 'Private' AND active = 1 AND wp_user_id > 0",
            ARRAY_A
        );

        if (!is_array($private_rows) || empty($private_rows)) {
            return [];
        }

        $active_statuses = ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-paid'];
        $status_placeholders = implode(',', array_fill(0, count($active_statuses), '%s'));

        $stale = [];
        foreach ($private_rows as $row) {
            $uid = (int) $row['wp_user_id'];
            if ($uid <= 0) {
                continue;
            }

            $params = $active_statuses;
            $params[] = $uid;
            $params[] = $cutoff_date;

            $recent = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$orders_table}`
                 WHERE status IN ({$status_placeholders})
                   AND type = 'shop_order'
                   AND customer_id = %d
                   AND date_created_gmt >= %s",
                $params
            ));

            if ($recent === 0) {
                $stale[] = [
                    'client_id' => (int) $row['client_id'],
                    'wp_user_id' => $uid,
                    'name' => trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? ''))),
                ];
            }
        }

        return $stale;
    }

    /**
     * Deactivate the rows identified by deactivation_sweep_preview().
     *
     * @return array{candidates:int, deactivated:int, errors:int}
     */
    public static function deactivation_sweep_run(int $lookback_months = self::DEFAULT_LOOKBACK_MONTHS): array {
        $candidates = self::deactivation_sweep_preview($lookback_months);
        $stats = [
            'candidates' => count($candidates),
            'deactivated' => 0,
            'errors' => 0,
        ];

        foreach ($candidates as $row) {
            $client_id = (int) ($row['client_id'] ?? 0);
            if ($client_id <= 0) {
                $stats['errors']++;
                continue;
            }
            try {
                if (MealsDB_Clients::deactivate_client($client_id)) {
                    $stats['deactivated']++;
                } else {
                    $stats['errors']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log('[MealsDB Backfill] Deactivation sweep failed for client ' . $client_id . ': ' . $e->getMessage());
            }
        }

        return $stats;
    }

    private static function table_exists(string $table_name): bool {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return false;
        }
        $row = $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s LIMIT 1',
            $table_name
        ));
        return $row !== null;
    }
}
