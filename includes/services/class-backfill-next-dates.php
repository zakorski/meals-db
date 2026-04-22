<?php
/**
 * Backfill next_order_date / next_delivery_date on meals_clients.
 *
 * One-time helper that reads wp_usermeta for `last_order_date` /
 * `last_delivery_date` and computes:
 *   next_order_date    = last_order_date    + ordering_frequency days
 *   next_delivery_date = last_delivery_date + delivery_frequency days
 *
 * Only populates rows where the target column is currently NULL — safe
 * to re-run, and won't overwrite an operator's manual edits.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Backfill_Next_Dates {

    /**
     * Run the backfill pass.
     *
     * @return array{processed: int, order_updated: int, delivery_updated: int, skipped: int}
     */
    public static function run(): array {
        global $wpdb;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $rows = $wpdb->get_results(
            "SELECT client_id, wp_user_id, ordering_frequency, delivery_frequency,
                    next_order_date, next_delivery_date
             FROM `{$clients_table}`
             WHERE wp_user_id > 0 AND active = 1",
            ARRAY_A
        );
        if (!is_array($rows)) {
            return ['processed' => 0, 'order_updated' => 0, 'delivery_updated' => 0, 'skipped' => 0];
        }

        $processed = 0;
        $order_updated = 0;
        $delivery_updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $processed++;
            $client_id = (int) $row['client_id'];
            $wp_user_id = (int) $row['wp_user_id'];

            $patch = [];

            if (empty($row['next_order_date'])) {
                $next_order = self::compute_next_from_meta(
                    $wp_user_id,
                    'last_order_date',
                    (int) ($row['ordering_frequency'] ?? 0)
                );
                if ($next_order !== null) {
                    $patch['next_order_date'] = $next_order;
                }
            }

            if (empty($row['next_delivery_date'])) {
                $next_delivery = self::compute_next_from_meta(
                    $wp_user_id,
                    'last_delivery_date',
                    (int) ($row['delivery_frequency'] ?? 0)
                );
                if ($next_delivery !== null) {
                    $patch['next_delivery_date'] = $next_delivery;
                }
            }

            if (empty($patch)) {
                $skipped++;
                continue;
            }

            $result = $wpdb->update($clients_table, $patch, ['client_id' => $client_id]);
            if ($result !== false) {
                if (isset($patch['next_order_date'])) {
                    $order_updated++;
                }
                if (isset($patch['next_delivery_date'])) {
                    $delivery_updated++;
                }
            }
        }

        return [
            'processed'        => $processed,
            'order_updated'    => $order_updated,
            'delivery_updated' => $delivery_updated,
            'skipped'          => $skipped,
        ];
    }

    /**
     * Read a YYYY-MM-DD string from usermeta and project forward by N days.
     */
    private static function compute_next_from_meta(int $wp_user_id, string $meta_key, int $frequency_days): ?string {
        if ($wp_user_id <= 0 || $frequency_days <= 0 || !function_exists('get_user_meta')) {
            return null;
        }

        $raw = get_user_meta($wp_user_id, $meta_key, true);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        try {
            $date = new DateTimeImmutable($raw);
        } catch (Throwable $e) {
            return null;
        }

        return $date->modify('+' . $frequency_days . ' days')->format('Y-m-d');
    }
}
