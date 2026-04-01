<?php
/**
 * One-time backfill: populate allowance_mains, allowance_sides, and fix
 * requisition_period on meals_clients from legacy wp_usermeta values.
 *
 * @package MealsDB
 */

if (!defined('ABSPATH')) {
    exit;
}

class MealsDB_Backfill_Allowances {

    const BATCH_SIZE = 100;

    /**
     * Run the backfill (or dry-run).
     *
     * @param bool $dry_run If true, count and log but do not write.
     * @return array{updated: int, skipped: int, errors: int, total: int}
     */
    public static function run(bool $dry_run = true): array {
        global $wpdb;

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return ['error' => 'Cannot connect to external Meals DB.'];
        }

        $clients_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));

        // Get all meals_clients rows that have a wp_user_id.
        $sql = sprintf(
            "SELECT client_id, wp_user_id, requisition_period, allowance_mains, allowance_sides
             FROM `%s`
             WHERE wp_user_id > 0
             ORDER BY client_id ASC",
            $clients_table
        );

        $result = $conn->query($sql);
        if (!MealsDB_DB::is_mysqli_result($result)) {
            return ['error' => 'Failed to query meals_clients.'];
        }

        $clients = [];
        while ($row = $result->fetch_assoc()) {
            $clients[] = $row;
        }
        $result->free();

        $stats = ['updated' => 0, 'skipped' => 0, 'errors' => 0, 'total' => count($clients)];

        // Prepare the UPDATE statement.
        $update_sql = sprintf(
            "UPDATE `%s`
             SET allowance_mains = ?, allowance_sides = ?, requisition_period = ?
             WHERE client_id = ?",
            $clients_table
        );

        $stmt = null;
        if (!$dry_run) {
            $stmt = $conn->prepare($update_sql);
            if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
                return ['error' => 'Failed to prepare UPDATE statement: ' . ($conn->error ?? 'unknown')];
            }
        }

        foreach ($clients as $client) {
            $wp_user_id = (int) $client['wp_user_id'];
            $client_id  = (int) $client['client_id'];

            // Read the three legacy user meta values from wp_usermeta.
            $meta_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->usermeta}
                 WHERE user_id = %d AND meta_key IN ('mains', 'sides', 'service')",
                $wp_user_id
            ), ARRAY_A);

            $meta = [];
            if (is_array($meta_rows)) {
                foreach ($meta_rows as $mr) {
                    $meta[$mr['meta_key']] = $mr['meta_value'];
                }
            }

            $old_mains   = isset($meta['mains']) && $meta['mains'] !== '' ? (int) $meta['mains'] : null;
            $old_sides   = isset($meta['sides']) && $meta['sides'] !== '' ? (int) $meta['sides'] : null;
            $old_service = isset($meta['service']) && $meta['service'] !== '' ? strtolower(trim($meta['service'])) : null;

            // Normalize service to the expected values.
            $period_map = [
                'day'     => 'Day',
                'week'    => 'Week',
                'weekly'  => 'Week',
                'month'   => 'Month',
                'monthly' => 'Month',
                'daily'   => 'Day',
            ];
            $normalized_period = isset($period_map[$old_service]) ? $period_map[$old_service] : null;

            // Skip if there's nothing to write.
            if ($old_mains === null && $old_sides === null && $normalized_period === null) {
                $stats['skipped']++;
                continue;
            }

            if ($dry_run) {
                $stats['updated']++;
                error_log(sprintf(
                    '[MealsDB Backfill] DRY RUN: client_id=%d wp_user_id=%d → mains=%s sides=%s period=%s',
                    $client_id, $wp_user_id,
                    $old_mains ?? 'NULL', $old_sides ?? 'NULL', $normalized_period ?? 'NULL'
                ));
                continue;
            }

            // Write to meals_clients.
            $bind_mains  = $old_mains;
            $bind_sides  = $old_sides;
            $bind_period = $normalized_period;
            $bind_id     = $client_id;

            $stmt->bind_param('iisi', $bind_mains, $bind_sides, $bind_period, $bind_id);

            if ($stmt->execute()) {
                $stats['updated']++;
            } else {
                $stats['errors']++;
                error_log(sprintf(
                    '[MealsDB Backfill] ERROR updating client_id=%d: %s',
                    $client_id, $stmt->error ?? 'unknown'
                ));
            }
        }

        if ($stmt) {
            $stmt->close();
        }

        return $stats;
    }
}
