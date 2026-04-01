<?php
/**
 * One-time backfill: fix remaining migration data gaps for addresses,
 * delivery_area_name (zone data), and default_rate_id linkage.
 *
 * Run AFTER Phase G (address consolidation) is merged.
 *
 * @package MealsDB
 */

if (!defined('ABSPATH')) {
    exit;
}

class MealsDB_Backfill_Addresses {

    /**
     * Run the backfill (or dry-run).
     *
     * @param bool $dry_run If true, count and log but do not write.
     * @return array{total: int, zones_fixed: int, addresses_fixed: int, rates_linked: int, skipped: int, errors: int}|array{error: string}
     */
    public static function run(bool $dry_run = true): array {
        global $wpdb;

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return ['error' => 'Cannot connect to external Meals DB.'];
        }

        $clients_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $rates_table   = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES));

        // Get all meals_clients rows that have a wp_user_id.
        $sql = sprintf(
            "SELECT client_id, wp_user_id, delivery_area_name, apartment_number, delivery_apartment_number,
                    street_name, delivery_street_name, default_rate_id
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

        $stats = [
            'total'           => count($clients),
            'zones_fixed'     => 0,
            'addresses_fixed' => 0,
            'rates_linked'    => 0,
            'skipped'         => 0,
            'errors'          => 0,
        ];

        // Batch-fetch all wp_user_ids for usermeta lookup.
        $wp_user_ids = [];
        foreach ($clients as $c) {
            $uid = (int) $c['wp_user_id'];
            if ($uid > 0) {
                $wp_user_ids[] = $uid;
            }
        }

        // Build usermeta lookup keyed by user_id.
        $meta_lookup = [];
        if (!empty($wp_user_ids)) {
            $placeholders = implode(',', array_fill(0, count($wp_user_ids), '%d'));
            $meta_keys = ['billing_address_1', 'billing_address_2', 'shipping_address_1', 'shipping_address_2'];
            $meta_keys_sql = implode(',', array_map(function ($k) use ($wpdb) {
                return $wpdb->prepare('%s', $k);
            }, $meta_keys));

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $meta_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
                     WHERE user_id IN ($placeholders) AND meta_key IN ($meta_keys_sql)",
                    ...$wp_user_ids
                ),
                ARRAY_A
            );

            if (is_array($meta_rows)) {
                foreach ($meta_rows as $mr) {
                    $uid = (int) $mr['user_id'];
                    if (!isset($meta_lookup[$uid])) {
                        $meta_lookup[$uid] = [];
                    }
                    $meta_lookup[$uid][$mr['meta_key']] = $mr['meta_value'];
                }
            }
        }

        foreach ($clients as $client) {
            $client_id  = (int) $client['client_id'];
            $wp_user_id = (int) $client['wp_user_id'];
            $meta       = $meta_lookup[$wp_user_id] ?? [];

            $updates     = [];
            $bind_types  = '';
            $bind_values = [];
            $changes     = [];

            // 1. Fix delivery_area_name from billing_address_2 (zone data).
            $billing_address_2 = $meta['billing_address_2'] ?? '';
            if (empty($client['delivery_area_name']) && $billing_address_2 !== '') {
                $updates[]     = 'delivery_area_name = ?';
                $bind_types   .= 's';
                $bind_values[] = $billing_address_2;
                $changes[]     = "delivery_area_name={$billing_address_2}";
            }

            // 2. Clear zone data from apartment_number.
            if (!empty($client['apartment_number']) && strpos($client['apartment_number'], 'Zone') === 0) {
                $updates[]     = 'apartment_number = NULL';
                $changes[]     = 'apartment_number=NULL';
            }

            // 3. Clear zone data from delivery_apartment_number.
            if (!empty($client['delivery_apartment_number']) && strpos($client['delivery_apartment_number'], 'Zone') === 0) {
                $updates[]     = 'delivery_apartment_number = NULL';
                $changes[]     = 'delivery_apartment_number=NULL';
            }

            // 4. Fix street_name from billing_address_1.
            $billing_address_1 = $meta['billing_address_1'] ?? '';
            if ($billing_address_1 !== '' && (empty($client['street_name']) || $client['street_name'] !== $billing_address_1)) {
                $updates[]     = 'street_name = ?';
                $bind_types   .= 's';
                $bind_values[] = $billing_address_1;
                $changes[]     = "street_name={$billing_address_1}";
            }

            // 5. Fix delivery_street_name from shipping_address_1.
            $shipping_address_1 = $meta['shipping_address_1'] ?? '';
            if ($shipping_address_1 !== '' && ($client['delivery_street_name'] ?? '') !== $shipping_address_1) {
                $updates[]     = 'delivery_street_name = ?';
                $bind_types   .= 's';
                $bind_values[] = $shipping_address_1;
                $changes[]     = "delivery_street_name={$shipping_address_1}";
            }

            // 6. Link default_rate_id if empty.
            if (empty($client['default_rate_id'])) {
                $rate_sql = sprintf(
                    "SELECT rate_id FROM `%s` WHERE client_id = ? AND is_default = 1 LIMIT 1",
                    $rates_table
                );
                $rate_stmt = $conn->prepare($rate_sql);
                if (MealsDB_DB::is_mysqli_stmt($rate_stmt)) {
                    $rate_stmt->bind_param('i', $client_id);
                    $rate_stmt->execute();
                    $rate_result = $rate_stmt->get_result();
                    if (MealsDB_DB::is_mysqli_result($rate_result)) {
                        $rate_row = $rate_result->fetch_assoc();
                        if ($rate_row) {
                            $rate_id       = (int) $rate_row['rate_id'];
                            $updates[]     = 'default_rate_id = ?';
                            $bind_types   .= 'i';
                            $bind_values[] = $rate_id;
                            $changes[]     = "default_rate_id={$rate_id}";
                        }
                    }
                    $rate_stmt->close();
                }
            }

            // Skip if nothing to update.
            if (empty($updates)) {
                $stats['skipped']++;
                continue;
            }

            // Track stats by category.
            foreach ($changes as $change) {
                if (strpos($change, 'delivery_area_name=') === 0) {
                    $stats['zones_fixed']++;
                } elseif (strpos($change, 'street_name=') === 0 || strpos($change, 'delivery_street_name=') === 0) {
                    $stats['addresses_fixed']++;
                } elseif (strpos($change, 'default_rate_id=') === 0) {
                    $stats['rates_linked']++;
                }
            }

            if ($dry_run) {
                error_log(sprintf(
                    '[MealsDB Backfill] DRY RUN: client_id=%d wp_user_id=%d → %s',
                    $client_id, $wp_user_id, implode(', ', $changes)
                ));
                continue;
            }

            // Build and execute the UPDATE.
            $update_sql = sprintf(
                "UPDATE `%s` SET %s WHERE client_id = ?",
                $clients_table,
                implode(', ', $updates)
            );
            $bind_types  .= 'i';
            $bind_values[] = $client_id;

            $stmt = $conn->prepare($update_sql);
            if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
                $stats['errors']++;
                error_log(sprintf(
                    '[MealsDB Backfill] ERROR preparing update for client_id=%d: %s',
                    $client_id, $conn->error ?? 'unknown'
                ));
                continue;
            }

            $stmt->bind_param($bind_types, ...$bind_values);

            if ($stmt->execute()) {
                error_log(sprintf(
                    '[MealsDB Backfill] Updated client_id=%d: %s',
                    $client_id, implode(', ', $changes)
                ));
            } else {
                $stats['errors']++;
                error_log(sprintf(
                    '[MealsDB Backfill] ERROR updating client_id=%d: %s',
                    $client_id, $stmt->error ?? 'unknown'
                ));
            }
            $stmt->close();
        }

        return $stats;
    }
}
