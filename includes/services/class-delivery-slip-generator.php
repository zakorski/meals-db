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
        // delivery_day + delivery_frequency are needed by the delivery-basis
        // order filter (delivery_occurrence_for_order, MAJ-6) to map each
        // candidate order to its intended delivery occurrence.
        $sql   = $wpdb->prepare(
            "SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    client_type, delivery_fee, payment_method,
                    delivery_day, delivery_frequency
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
        // delivery_day + delivery_frequency drive the delivery-basis order
        // filter (delivery_occurrence_for_order, MAJ-6).
        $sql   = $wpdb->prepare(
            "SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    first_name, last_name, client_phone_1, delivery_fee,
                    client_contribution, payment_method, client_type,
                    delivery_day, delivery_frequency
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

        // delivery_day + delivery_frequency are required so the zone/range
        // slip can map each order to its delivery occurrence (GUI-SLIP-RANGE);
        // omitting them would leave the occurrence filter with a blank cadence
        // and silently drop every order.
        $sql = $wpdb->prepare(
            "SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    client_type, delivery_fee, payment_method,
                    delivery_day, delivery_frequency
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

        // delivery_day + delivery_frequency drive the delivery-occurrence
        // filter for the zone/range slip (GUI-SLIP-RANGE).
        $sql = $wpdb->prepare(
            "SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    first_name, last_name, client_phone_1, delivery_fee,
                    client_contribution, payment_method, client_type,
                    delivery_day, delivery_frequency
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
     * Fetch WC HPOS orders (with items) for a single delivery date, on the
     * DELIVERY basis (MAJ-6).
     *
     * The old single-date path (get_orders_for_date) filters on the order's
     * CREATION date, so an order placed ahead of its delivery day landed on
     * the wrong day's packer/driver slip. This method instead maps each
     * candidate order to its intended delivery occurrence — computed from the
     * client's stored delivery_day + delivery_frequency via
     * delivery_occurrence_for_order() — and keeps only the orders whose
     * occurrence equals $delivery_date.
     *
     * It deliberately does NOT reuse MealsDB_WC_Order_Query::get_orders_for_users
     * as the FILTER (that method's creation-date basis is correct and is still
     * used by reports/reconciliation); it only borrows it to fetch the
     * candidate window, then re-buckets in PHP.
     *
     * @param array<int, array<string, mixed>> $clients       Clients keyed by wp_user_id
     *                                                         (must carry delivery_day +
     *                                                         delivery_frequency).
     * @param string                           $delivery_date Y-m-d slip date.
     * @return array<int, array<string, mixed>> Orders (with items) due on $delivery_date.
     */
    public function get_orders_for_delivery_date(array $clients, string $delivery_date): array {
        // Single-date is just the degenerate range [D, D]. Both slip paths
        // share get_orders_for_delivery_range() so the occurrence filter can
        // never drift between them again (that divergence was GUI-SLIP-RANGE:
        // MAJ-6 fixed the single-date path but left the range path on the raw
        // creation-date query).
        return $this->get_orders_for_delivery_range($clients, $delivery_date, $delivery_date);
    }

    /**
     * Fetch WC HPOS orders (with items) whose DELIVERY occurrence falls within
     * [$start_date, $end_date] inclusive (GUI-SLIP-RANGE, generalising MAJ-6).
     *
     * This is the shared selection used by BOTH slip paths — the single-date
     * slip (range [D, D]) and the by-zone/date-range slip. The semantics are
     * "orders DELIVERED within the range," NOT "orders CREATED within the
     * range": a packer/driver slip is about what ships on a given day, so an
     * order placed ahead of its delivery day must land on the slip for the day
     * it is delivered, regardless of when it was created.
     *
     * The previous by-zone path (get_orders_for_range) selected on
     * date_created_gmt with no occurrence filter, so a range slip pulled every
     * order CREATED in the window and printed each order's own (scattered)
     * delivery date — the pre-MAJ-6 bug, still live on the range path. This
     * routes the range path through the same delivery_occurrence_for_order()
     * test the single-date path uses.
     *
     * @param array<int, array<string, mixed>> $clients    Clients keyed by wp_user_id
     *                                                      (must carry delivery_day +
     *                                                      delivery_frequency).
     * @param string                           $start_date Y-m-d range start (inclusive).
     * @param string                           $end_date   Y-m-d range end (inclusive).
     * @return array<int, array<string, mixed>> Orders (with items) delivered in the range.
     */
    public function get_orders_for_delivery_range(array $clients, string $start_date, string $end_date): array {
        if (empty($clients)) {
            return [];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            return [];
        }
        if ($start_date > $end_date) {
            return [];
        }

        // An order delivered on D was created within (D - frequency*7, D]:
        // either in D's own week (weekday still upcoming) or up to one full
        // cycle earlier (weekday already passed when it was placed). For a
        // range, the earliest in-range delivery is $start_date, so widen the
        // creation-date pre-filter by the largest frequency among the selected
        // clients RELATIVE TO $start_date; the upper bound stays $end_date
        // (an order created after its delivery date can't deliver in-range).
        // The per-order occurrence filter below is authoritative, so a
        // generous window only costs a few extra candidate rows.
        $max_freq = 1;
        foreach ($clients as $c) {
            $f = isset($c['delivery_frequency']) ? (int) $c['delivery_frequency'] : 1;
            if ($f > $max_freq) {
                $max_freq = $f;
            }
        }
        $window_start = gmdate(
            'Y-m-d',
            strtotime($start_date . ' -' . ($max_freq * 7) . ' days UTC')
        );

        $candidates = $this->order_query->get_orders_with_items_for_users(
            array_keys($clients),
            $window_start,
            $end_date
        );
        if (empty($candidates)) {
            return [];
        }

        $matched = [];
        foreach ($candidates as $order) {
            $uid    = (int) ($order['wp_user_id'] ?? 0);
            $client = $clients[$uid] ?? null;
            if ($client === null) {
                continue;
            }
            $created    = (string) ($order['date_created_gmt'] ?? '');
            $occurrence = self::delivery_occurrence_for_order($created, $client);
            // Y-m-d strings compare correctly with lexical >=/<=.
            if ($occurrence !== null && $occurrence >= $start_date && $occurrence <= $end_date) {
                $matched[] = $order;
            }
        }

        return $matched;
    }

    /**
     * Map an order to the delivery occurrence it belongs to (MAJ-6).
     *
     * The single documented occurrence/cutoff rule. Given an order's creation
     * date C and the client's stored (delivery_day, delivery_frequency):
     *
     *   - Snap C to the client's delivery weekday within C's own Sun..Sat week
     *     (S). If that weekday is still upcoming (S >= C), the order rides S —
     *     this is the directive's cutoff: an order created on or before a
     *     delivery date belongs to THAT delivery.
     *   - If the delivery weekday has already passed in C's week (S < C), the
     *     order rolls forward one full cycle (frequency weeks) to the next
     *     occurrence — so a biweekly client's late order maps two weeks out,
     *     not to the intervening weekly weekday.
     *
     * The result is a pure function of (C, delivery_day, frequency): every
     * order maps to exactly one occurrence, so no order is double-counted
     * across adjacent slip dates.
     *
     * B1 limitation (documented): this resolves the delivery DAY from the
     * client's stored delivery_day and does not re-phase against the client's
     * actual fortnightly/triweekly calendar — it assumes the next delivery
     * weekday on/after C is a real delivery, which holds when clients order
     * close to their delivery day (the operating norm). True per-client phase
     * would require order-time delivery-date capture (directive's B2).
     *
     * @param string                  $order_created_date Y-m-d or 'Y-m-d H:i:s'.
     * @param array<string, mixed>    $client             Must carry delivery_day;
     *                                                     delivery_frequency optional (default 1).
     * @return string|null Y-m-d occurrence, or null when the delivery day is
     *                     blank/unknown (order falls out of every slip).
     */
    public static function delivery_occurrence_for_order(string $order_created_date, array $client): ?string {
        $created = substr($order_created_date, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $created)) {
            return null;
        }

        $delivery_day = isset($client['delivery_day']) ? (string) $client['delivery_day'] : '';
        $frequency    = isset($client['delivery_frequency']) ? (int) $client['delivery_frequency'] : 1;
        if ($frequency <= 0) {
            $frequency = 1;
        }

        $snap = MealsDB_Date_Calculator::snap_to_delivery_day($created, $delivery_day);
        if ($snap === null) {
            return null; // blank/unknown delivery day — handled gracefully, no fatal.
        }

        // Y-m-d strings compare correctly with lexical >=.
        if ($snap >= $created) {
            return $snap; // delivery weekday still upcoming in C's week.
        }

        // Weekday already passed: roll forward one full cycle. next_date()
        // projects frequency*7 days and re-snaps (a no-op since $snap is
        // already on the delivery weekday), landing on the next occurrence.
        return MealsDB_Date_Calculator::next_date($snap, $frequency, $delivery_day);
    }

    /**
     * Fetch WC HPOS orders (with items) for a CREATION-date range.
     *
     * Creation-date basis, retained for genuine creation-date consumers
     * (reports/reconciliation). Do NOT use this to populate a packer/driver
     * slip: slips select on the DELIVERY basis via
     * get_orders_for_delivery_range() — wiring a slip back through this query
     * is exactly the GUI-SLIP-RANGE bug (orders CREATED in the window printed
     * with their own scattered delivery dates).
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
