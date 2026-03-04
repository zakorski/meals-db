<?php
/**
 * Historical Order Import Utility
 *
 * One-time utility to tag existing WooCommerce orders with mealsdb_client_user_id,
 * mealsdb_client_id, and mealsdb_rate_id order meta for SDNB and Veteran clients.
 *
 * @package MealsDB
 */

class MealsDB_Historical_Import {

    const BATCH_SIZE       = 100;
    const PROGRESS_OPTION  = 'mealsdb_historical_import_progress';
    const LOG_OPTION       = 'mealsdb_historical_import_log';

    /**
     * Get all government (SDNB/Veteran) clients keyed by wp_user_id.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_government_clients(): array {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return [];
        }

        $table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $sql   = sprintf(
            "SELECT client_id, wp_user_id, default_rate_id FROM `%s`
             WHERE client_type IN ('SDNB','Veteran') AND wp_user_id > 0 AND active = 1",
            $table
        );

        $result = $conn->query($sql);
        if (!MealsDB_DB::is_mysqli_result($result)) {
            return [];
        }

        $clients = [];
        while ($row = $result->fetch_assoc()) {
            $uid = (int) $row['wp_user_id'];
            $clients[$uid] = [
                'client_id'       => (int) $row['client_id'],
                'wp_user_id'      => $uid,
                'default_rate_id' => (int) $row['default_rate_id'],
            ];
        }

        return $clients;
    }

    /**
     * Get a batch of order IDs for government clients.
     *
     * @param int   $offset              Offset for pagination.
     * @param int[] $government_user_ids  Government client WP user IDs.
     *
     * @return array<int, array{id: int, customer_id: int}>
     */
    public static function get_orders_for_batch(int $offset, array $government_user_ids): array {
        if (empty($government_user_ids)) {
            return [];
        }

        global $wpdb;
        $orders_table = $wpdb->prefix . 'wc_orders';

        $placeholders = implode(',', array_fill(0, count($government_user_ids), '%d'));
        $sql = "SELECT id, customer_id FROM {$orders_table}
                WHERE customer_id IN ({$placeholders}) AND type = 'shop_order'
                ORDER BY id ASC
                LIMIT %d OFFSET %d";

        $params   = array_merge($government_user_ids, [self::BATCH_SIZE, $offset]);
        $prepared = $wpdb->prepare($sql, $params);
        $rows     = $wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Get total count of orders for government clients.
     *
     * @param int[] $government_user_ids Government client WP user IDs.
     *
     * @return int
     */
    public static function get_total_order_count(array $government_user_ids): int {
        if (empty($government_user_ids)) {
            return 0;
        }

        global $wpdb;
        $orders_table = $wpdb->prefix . 'wc_orders';

        $placeholders = implode(',', array_fill(0, count($government_user_ids), '%d'));
        $sql = "SELECT COUNT(*) FROM {$orders_table}
                WHERE customer_id IN ({$placeholders}) AND type = 'shop_order'";

        $prepared = $wpdb->prepare($sql, $government_user_ids);

        return (int) $wpdb->get_var($prepared);
    }

    /**
     * Process a single batch of orders.
     *
     * @param int   $offset   Current offset.
     * @param bool  $dry_run  If true, no writes are performed.
     * @param array $clients  Government clients keyed by wp_user_id (from get_government_clients).
     *
     * @return array{processed: int, tagged: int, already_tagged: int, skipped: int, errors: int}
     */
    public static function process_batch(int $offset, bool $dry_run = true, array $clients = []): array {
        $stats = [
            'processed'      => 0,
            'tagged'         => 0,
            'already_tagged' => 0,
            'skipped'        => 0,
            'errors'         => 0,
        ];

        if (empty($clients)) {
            $clients = self::get_government_clients();
        }

        $user_ids = array_keys($clients);
        $orders   = self::get_orders_for_batch($offset, $user_ids);

        foreach ($orders as $row) {
            $stats['processed']++;
            $order_id    = (int) $row['id'];
            $customer_id = (int) $row['customer_id'];

            $order = wc_get_order($order_id);
            if (!($order instanceof WC_Order)) {
                $stats['errors']++;
                continue;
            }

            // Already tagged?
            $existing = $order->get_meta('mealsdb_client_user_id', true);
            if (!empty($existing) && (int) $existing > 0) {
                $stats['already_tagged']++;
                continue;
            }

            // Lookup client.
            if (!isset($clients[$customer_id])) {
                $stats['skipped']++;
                continue;
            }
            $client = $clients[$customer_id];

            // Resolve default rate.
            $rate_id = self::resolve_default_rate($client['client_id']);

            if (!$dry_run) {
                $order->update_meta_data('mealsdb_client_user_id', $client['wp_user_id']);
                $order->update_meta_data('mealsdb_client_id', $client['client_id']);
                if ($rate_id > 0) {
                    $order->update_meta_data('mealsdb_rate_id', $rate_id);
                }
                $order->save();
            }

            $stats['tagged']++;
        }

        return $stats;
    }

    /**
     * Resolve the default rate_id for a client.
     *
     * @param int $client_id External meals_clients.client_id.
     *
     * @return int rate_id or 0 if none found.
     */
    private static function resolve_default_rate(int $client_id): int {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return 0;
        }

        $table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES));
        $sql   = sprintf('SELECT rate_id FROM `%s` WHERE client_id = ? AND is_default = 1 LIMIT 1', $table);

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return 0;
        }

        $stmt->bind_param('i', $client_id);
        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }

        $result = $stmt->get_result();
        $rate_id = 0;
        if (MealsDB_DB::is_mysqli_result($result)) {
            $row = $result->fetch_assoc();
            if (is_array($row) && isset($row['rate_id'])) {
                $rate_id = (int) $row['rate_id'];
            }
        }

        $stmt->close();

        return $rate_id;
    }

    /**
     * Get current import progress.
     *
     * @return array{offset: int, total: int, complete: bool}
     */
    public static function get_progress(): array {
        $default = ['offset' => 0, 'total' => 0, 'complete' => false];
        $progress = get_option(self::PROGRESS_OPTION, $default);

        return is_array($progress) ? array_merge($default, $progress) : $default;
    }

    /**
     * Save import progress.
     *
     * @param int  $offset   Current offset.
     * @param int  $total    Total orders to process.
     * @param bool $complete Whether the import is finished.
     */
    public static function save_progress(int $offset, int $total, bool $complete = false): void {
        update_option(self::PROGRESS_OPTION, [
            'offset'   => $offset,
            'total'    => $total,
            'complete' => $complete,
        ]);
    }

    /**
     * Reset import progress and log.
     */
    public static function reset_progress(): void {
        delete_option(self::PROGRESS_OPTION);
        delete_option(self::LOG_OPTION);
    }
}
