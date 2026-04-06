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

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $rates_table   = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES);

        // Get all meals_clients rows that have a wp_user_id.
        $clients = $wpdb->get_results(
            "SELECT client_id, wp_user_id, delivery_area_name, apartment_number, delivery_apartment_number,
                    street_name, delivery_street_name, default_rate_id
             FROM `{$clients_table}`
             WHERE wp_user_id > 0
             ORDER BY client_id ASC",
            ARRAY_A
        );

        if (!is_array($clients)) {
            return ['error' => 'Failed to query meals_clients.'];
        }

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

            $set_clauses  = [];
            $bind_values  = [];
            $changes      = [];

            // 1. Fix delivery_area_name from billing_address_2 (zone data).
            $billing_address_2 = $meta['billing_address_2'] ?? '';
            if (empty($client['delivery_area_name']) && $billing_address_2 !== '') {
                $set_clauses[] = 'delivery_area_name = %s';
                $bind_values[] = $billing_address_2;
                $changes[]     = "delivery_area_name={$billing_address_2}";
            }

            // 2. Clear zone data from apartment_number.
            if (!empty($client['apartment_number']) && strpos($client['apartment_number'], 'Zone') === 0) {
                $set_clauses[] = 'apartment_number = NULL';
                $changes[]     = 'apartment_number=NULL';
            }

            // 3. Clear zone data from delivery_apartment_number.
            if (!empty($client['delivery_apartment_number']) && strpos($client['delivery_apartment_number'], 'Zone') === 0) {
                $set_clauses[] = 'delivery_apartment_number = NULL';
                $changes[]     = 'delivery_apartment_number=NULL';
            }

            // 4. Fix street_name from billing_address_1.
            $billing_address_1 = $meta['billing_address_1'] ?? '';
            if ($billing_address_1 !== '' && (empty($client['street_name']) || $client['street_name'] !== $billing_address_1)) {
                $set_clauses[] = 'street_name = %s';
                $bind_values[] = $billing_address_1;
                $changes[]     = "street_name={$billing_address_1}";
            }

            // 5. Fix delivery_street_name from shipping_address_1.
            $shipping_address_1 = $meta['shipping_address_1'] ?? '';
            if ($shipping_address_1 !== '' && ($client['delivery_street_name'] ?? '') !== $shipping_address_1) {
                $set_clauses[] = 'delivery_street_name = %s';
                $bind_values[] = $shipping_address_1;
                $changes[]     = "delivery_street_name={$shipping_address_1}";
            }

            // 6. Link default_rate_id if empty.
            if (empty($client['default_rate_id'])) {
                $rate_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT rate_id FROM `{$rates_table}` WHERE client_id = %d AND is_default = 1 LIMIT 1",
                    $client_id
                ), ARRAY_A);

                if (is_array($rate_row) && isset($rate_row['rate_id'])) {
                    $rate_id       = (int) $rate_row['rate_id'];
                    $set_clauses[] = 'default_rate_id = %d';
                    $bind_values[] = $rate_id;
                    $changes[]     = "default_rate_id={$rate_id}";
                }
            }

            // Skip if nothing to update.
            if (empty($set_clauses)) {
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
            $set_sql = implode(', ', $set_clauses);
            $bind_values[] = $client_id;

            $update_sql = $wpdb->prepare(
                "UPDATE `{$clients_table}` SET {$set_sql} WHERE client_id = %d",
                ...$bind_values
            );

            if ($wpdb->query($update_sql) !== false) {
                error_log(sprintf(
                    '[MealsDB Backfill] Updated client_id=%d: %s',
                    $client_id, implode(', ', $changes)
                ));
            } else {
                $stats['errors']++;
                error_log(sprintf(
                    '[MealsDB Backfill] ERROR updating client_id=%d: %s',
                    $client_id, $wpdb->last_error ?: 'unknown'
                ));
            }
        }

        return $stats;
    }
}
