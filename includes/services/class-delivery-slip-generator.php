<?php
/**
 * Slip data provider — client + order queries for the Phase T PDF
 * pipeline.
 *
 * Class name retained as MealsDB_Delivery_Slip_Generator for binary
 * compatibility with the new MealsDB_Slip_PDF_Generator constructor
 * signature documented in directives/phase-T-pdf-slips.md. The four
 * screen-rendered slip generators that used to live here (packing,
 * picking, delivery, driver, plus their _by_zones counterparts) were
 * retired in Phase T.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Delivery_Slip_Generator {

    /**
     * @var MealsDB_WC_Order_Query
     */
    private $order_query;

    public function __construct(MealsDB_WC_Order_Query $order_query) {
        $this->order_query = $order_query;
    }

    /**
     * Get clients scheduled for delivery on the given date.
     *
     * Matches by day-of-week against the client's delivery_day column.
     * Returns the lighter-weight columns required by the packer
     * pipeline (no encrypted PII).
     *
     * @return array<int, array<string, mixed>> Keyed by wp_user_id.
     */
    public function get_clients_for_delivery_date(string $delivery_date): array {
        global $wpdb;

        // strtotime() falls back to "now" on unparseable input, so a
        // malformed $delivery_date (e.g. "2026-13-01" or a typo) would
        // silently return clients scheduled for today instead of
        // erroring. Require strict Y-m-d format up front.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date)) {
            return [];
        }
        $ts = strtotime($delivery_date);
        if ($ts === false) {
            return [];
        }

        $day_lower = strtolower(date('l', $ts));

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $sql   = $wpdb->prepare(
            "SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    client_type, delivery_fee, payment_method
             FROM `{$table}`
             WHERE active = 1 AND wp_user_id > 0 AND LOWER(delivery_day) = %s",
            $day_lower
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $clients = [];
        foreach ($rows as $row) {
            $uid = (int) $row['wp_user_id'];
            $clients[$uid] = $row;
        }

        return $clients;
    }

    /**
     * Get clients with full PII for driver delivery slips.
     *
     * Includes first_name, last_name, phone, delivery_fee,
     * payment_method, client_contribution, and client_type — all
     * needed for the driver-facing slip.
     *
     * @return array<int, array<string, mixed>> Keyed by wp_user_id.
     */
    public function get_clients_for_driver_slips(string $delivery_date): array {
        global $wpdb;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date)) {
            return [];
        }
        $ts = strtotime($delivery_date);
        if ($ts === false) {
            return [];
        }

        $day_lower = strtolower(date('l', $ts));

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $sql   = $wpdb->prepare(
            "SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    first_name, last_name, client_phone_1, delivery_fee,
                    client_contribution, payment_method, client_type
             FROM `{$table}`
             WHERE active = 1 AND wp_user_id > 0 AND LOWER(delivery_day) = %s",
            $day_lower
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $clients = [];
        foreach ($rows as $row) {
            $uid = (int) $row['wp_user_id'];

            // first_name / last_name are stored plaintext on modern
            // installs but historical rows may still carry the legacy
            // ciphertext. safe_decrypt is tolerant of either.
            if (!empty($row['first_name'])) {
                $row['first_name'] = MealsDB_Encryption::safe_decrypt($row['first_name']);
            }
            if (!empty($row['last_name'])) {
                $row['last_name'] = MealsDB_Encryption::safe_decrypt($row['last_name']);
            }

            $clients[$uid] = $row;
        }

        return $clients;
    }

    /**
     * Get active clients in the specified zones (for zone-based mode).
     *
     * @param array $zone_names Zone names to include (e.g. ['Zone 1', 'Zone 3']).
     * @return array<int, array<string, mixed>> Keyed by wp_user_id.
     */
    public function get_clients_for_zones(array $zone_names): array {
        global $wpdb;

        if (empty($zone_names)) {
            return [];
        }

        $table        = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $placeholders = implode(',', array_fill(0, count($zone_names), '%s'));

        $sql = $wpdb->prepare(
            "SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    client_type, delivery_fee, payment_method
             FROM `{$table}`
             WHERE active = 1 AND wp_user_id > 0 AND delivery_area_name IN ({$placeholders})",
            ...$zone_names
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $clients = [];
        foreach ($rows as $row) {
            $uid = (int) $row['wp_user_id'];
            $clients[$uid] = $row;
        }

        return $clients;
    }

    /**
     * Get clients with full PII for driver slips, filtered by zone.
     *
     * @param array $zone_names Zone names to include.
     * @return array<int, array<string, mixed>> Keyed by wp_user_id.
     */
    public function get_clients_for_zones_driver(array $zone_names): array {
        global $wpdb;

        if (empty($zone_names)) {
            return [];
        }

        $table        = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $placeholders = implode(',', array_fill(0, count($zone_names), '%s'));

        $sql = $wpdb->prepare(
            "SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    first_name, last_name, client_phone_1, delivery_fee,
                    client_contribution, payment_method, client_type
             FROM `{$table}`
             WHERE active = 1 AND wp_user_id > 0 AND delivery_area_name IN ({$placeholders})",
            ...$zone_names
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $clients = [];
        foreach ($rows as $row) {
            $uid = (int) $row['wp_user_id'];

            if (!empty($row['first_name'])) {
                $row['first_name'] = MealsDB_Encryption::safe_decrypt($row['first_name']);
            }
            if (!empty($row['last_name'])) {
                $row['last_name'] = MealsDB_Encryption::safe_decrypt($row['last_name']);
            }

            $clients[$uid] = $row;
        }

        return $clients;
    }

    /**
     * Fetch WC HPOS orders (with items) for a single delivery date.
     *
     * @param int[] $wp_user_ids WordPress user IDs.
     */
    public function get_orders_for_date(array $wp_user_ids, string $delivery_date): array {
        if (empty($wp_user_ids)) {
            return [];
        }

        return $this->order_query->get_orders_with_items_for_users(
            $wp_user_ids,
            $delivery_date,
            $delivery_date
        );
    }

    /**
     * Fetch WC HPOS orders (with items) for a date range.
     *
     * @param int[] $wp_user_ids WordPress user IDs.
     */
    public function get_orders_for_range(array $wp_user_ids, string $start_date, string $end_date): array {
        if (empty($wp_user_ids)) {
            return [];
        }

        return $this->order_query->get_orders_with_items_for_users(
            $wp_user_ids,
            $start_date,
            $end_date
        );
    }

    /**
     * Batch-fetch _freezer_order product meta for the given product IDs.
     *
     * @param array $product_ids WC product IDs.
     * @return array<int, int> Keyed by product ID → freezer order value.
     */
    public function get_freezer_orders(array $product_ids): array {
        if (empty($product_ids) || !isset($GLOBALS['wpdb'])) {
            return [];
        }
        $wpdb = $GLOBALS['wpdb'];
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_freezer_order' AND post_id IN ($placeholders)",
            ...$product_ids
        ), ARRAY_A);

        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $map[(int) $r['post_id']] = (int) $r['meta_value'];
            }
        }
        return $map;
    }

    /**
     * Categorize order items into mains/sides counts with side breakdown.
     *
     * Side categories: Soup=43, Muffins=37, Cereal=23, Dessert=25.
     *
     * @return array {mains_count, sides_count, side_detail: {soup, muffins, cereal, dessert}}
     */
    public function categorize_items(array $items, array $product_types): array {
        $mains   = 0;
        $sides   = 0;
        $soup    = 0;
        $muffins = 0;
        $cereal  = 0;
        $dessert = 0;

        $side_cat_map = [43 => 'soup', 37 => 'muffins', 23 => 'cereal', 25 => 'dessert'];

        foreach ($items as $item) {
            $pid  = (int) $item['wc_product_id'];
            $qty  = (int) $item['quantity'];
            $type = isset($product_types[$pid]) ? $product_types[$pid]['product_type'] : 'meal';

            if ($type === 'meal') {
                $mains += $qty;
            } elseif ($type === 'side') {
                $sides += $qty;
                $categorized = false;
                if (function_exists('has_term')) {
                    foreach ($side_cat_map as $cat_id => $cat_key) {
                        if (has_term($cat_id, 'product_cat', $pid)) {
                            $$cat_key += $qty;
                            $categorized = true;
                            break;
                        }
                    }
                }
                if (!$categorized) {
                    $dessert += $qty;
                }
            }
        }

        return [
            'mains_count' => $mains,
            'sides_count' => $sides,
            'side_detail' => [
                'soup'    => $soup,
                'muffins' => $muffins,
                'cereal'  => $cereal,
                'dessert' => $dessert,
            ],
        ];
    }

    /**
     * Collect all product IDs from orders and look up their types.
     *
     * @return array<int, array<string, mixed>> Keyed by wc_product_id.
     */
    public function resolve_product_types(array $orders): array {
        $ids = [];
        foreach ($orders as $order) {
            foreach ($order['items'] as $item) {
                $pid = (int) $item['wc_product_id'];
                if ($pid > 0) {
                    $ids[$pid] = $pid;
                }
            }
        }

        return $this->order_query->get_product_types_for_ids(array_values($ids));
    }
}
