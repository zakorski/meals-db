<?php
/**
 * Delivery Slip Generator
 *
 * Produces packing, picking, and delivery slips from WC HPOS order data
 * joined with meals_clients delivery information. Uses delivery_initials
 * (3-letter) rather than full names on routing documents for privacy.
 *
 * @package MealsDB
 */

class MealsDB_Delivery_Slip_Generator {

    /**
     * @var MealsDB_WC_Order_Query
     */
    private $order_query;

    /**
     * @param MealsDB_WC_Order_Query $order_query
     */
    public function __construct(MealsDB_WC_Order_Query $order_query) {
        $this->order_query = $order_query;
    }

    /**
     * Get clients scheduled for delivery on the given date.
     *
     * Matches by day-of-week against the client's delivery_day column.
     *
     * @param string $delivery_date Y-m-d.
     *
     * @return array<int, array<string, mixed>> Keyed by wp_user_id.
     */
    public function get_clients_for_delivery_date(string $delivery_date): array {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return [];
        }

        $day_name = date('l', strtotime($delivery_date));

        $table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $sql   = sprintf(
            'SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name
             FROM `%s`
             WHERE active = 1 AND wp_user_id > 0 AND LOWER(delivery_day) = ?',
            $table
        );

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return [];
        }

        $day_lower = strtolower($day_name);
        $stmt->bind_param('s', $day_lower);

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $result  = $stmt->get_result();
        $clients = [];
        if (MealsDB_DB::is_mysqli_result($result)) {
            while ($row = $result->fetch_assoc()) {
                $uid = (int) $row['wp_user_id'];
                $clients[$uid] = $row;
            }
        }

        $stmt->close();

        return $clients;
    }

    /**
     * Get clients with full PII for driver delivery slips.
     *
     * Unlike get_clients_for_delivery_date() which fetches only initials,
     * this includes first_name, last_name, phone, delivery_fee, payment_method,
     * and client_type — all needed for the driver-facing slip.
     *
     * @param string $delivery_date Y-m-d.
     *
     * @return array<int, array<string, mixed>> Keyed by wp_user_id.
     */
    public function get_clients_for_driver_slips(string $delivery_date): array {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return [];
        }

        $day_name = date('l', strtotime($delivery_date));

        $table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $sql   = sprintf(
            'SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    first_name, last_name, client_phone_1, delivery_fee,
                    payment_method, client_type
             FROM `%s`
             WHERE active = 1 AND wp_user_id > 0 AND LOWER(delivery_day) = ?',
            $table
        );

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return [];
        }

        $day_lower = strtolower($day_name);
        $stmt->bind_param('s', $day_lower);

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $result  = $stmt->get_result();
        $clients = [];
        if (MealsDB_DB::is_mysqli_result($result)) {
            while ($row = $result->fetch_assoc()) {
                $uid = (int) $row['wp_user_id'];

                // Decrypt encrypted PII fields.
                if (!empty($row['first_name'])) {
                    $row['first_name'] = MealsDB_Encryption::decrypt($row['first_name']);
                }
                if (!empty($row['last_name'])) {
                    $row['last_name'] = MealsDB_Encryption::decrypt($row['last_name']);
                }

                $clients[$uid] = $row;
            }
        }

        $stmt->close();

        return $clients;
    }

    /**
     * Get active clients in the specified zones (for zone-based mode).
     *
     * @param array $zone_names Zone names to include (e.g. ['Zone 1', 'Zone 3']).
     * @return array<int, array<string, mixed>> Keyed by wp_user_id.
     */
    public function get_clients_for_zones(array $zone_names): array {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn) || empty($zone_names)) {
            return [];
        }

        $table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $placeholders = implode(',', array_fill(0, count($zone_names), '?'));

        $sql = sprintf(
            'SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name
             FROM `%s`
             WHERE active = 1 AND wp_user_id > 0 AND delivery_area_name IN (%s)',
            $table,
            $placeholders
        );

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return [];
        }

        $types = str_repeat('s', count($zone_names));
        $stmt->bind_param($types, ...$zone_names);

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $result  = $stmt->get_result();
        $clients = [];
        if (MealsDB_DB::is_mysqli_result($result)) {
            while ($row = $result->fetch_assoc()) {
                $uid = (int) $row['wp_user_id'];
                $clients[$uid] = $row;
            }
        }

        $stmt->close();

        return $clients;
    }

    /**
     * Get clients with full PII for driver slips, filtered by zone.
     *
     * @param array $zone_names Zone names to include.
     * @return array<int, array<string, mixed>> Keyed by wp_user_id.
     */
    public function get_clients_for_zones_driver(array $zone_names): array {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn) || empty($zone_names)) {
            return [];
        }

        $table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $placeholders = implode(',', array_fill(0, count($zone_names), '?'));

        $sql = sprintf(
            'SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    first_name, last_name, client_phone_1, delivery_fee,
                    payment_method, client_type
             FROM `%s`
             WHERE active = 1 AND wp_user_id > 0 AND delivery_area_name IN (%s)',
            $table,
            $placeholders
        );

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return [];
        }

        $types = str_repeat('s', count($zone_names));
        $stmt->bind_param($types, ...$zone_names);

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $result  = $stmt->get_result();
        $clients = [];
        if (MealsDB_DB::is_mysqli_result($result)) {
            while ($row = $result->fetch_assoc()) {
                $uid = (int) $row['wp_user_id'];

                if (!empty($row['first_name'])) {
                    $row['first_name'] = MealsDB_Encryption::decrypt($row['first_name']);
                }
                if (!empty($row['last_name'])) {
                    $row['last_name'] = MealsDB_Encryption::decrypt($row['last_name']);
                }

                $clients[$uid] = $row;
            }
        }

        $stmt->close();

        return $clients;
    }

    /**
     * Fetch WC HPOS orders (with items) for a single delivery date.
     *
     * @param int[]  $wp_user_ids   WordPress user IDs.
     * @param string $delivery_date Y-m-d.
     *
     * @return array
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
     * @param int[]  $wp_user_ids WordPress user IDs.
     * @param string $start_date  Start date (Y-m-d).
     * @param string $end_date    End date (Y-m-d).
     *
     * @return array
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
     * Generate a packing slip: one entry per order, sorted by zone then initials.
     *
     * Returns structured data with zone summaries, mains/sides subtotals,
     * freezer-ordered items, and a separate no-zone section.
     *
     * @param string $delivery_date Y-m-d.
     *
     * @return array {entries: [...], no_zone: [...], zone_summaries: [...]}
     */
    public function generate_packing_slip(string $delivery_date): array {
        $clients = $this->get_clients_for_delivery_date($delivery_date);
        if (empty($clients)) {
            return ['entries' => [], 'no_zone' => [], 'zone_summaries' => []];
        }

        $orders = $this->get_orders_for_date(array_keys($clients), $delivery_date);

        return $this->build_packing_slip($clients, $orders);
    }

    /**
     * Generate a packing slip from zone-based selection.
     *
     * @param array  $zone_names Zone names (e.g. ['Zone 1', 'Zone 3']).
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     *
     * @return array {entries: [...], no_zone: [...], zone_summaries: [...]}
     */
    public function generate_packing_slip_by_zones(array $zone_names, string $start_date, string $end_date): array {
        $clients = $this->get_clients_for_zones($zone_names);
        if (empty($clients)) {
            return ['entries' => [], 'no_zone' => [], 'zone_summaries' => []];
        }

        $orders = $this->get_orders_for_range(array_keys($clients), $start_date, $end_date);

        return $this->build_packing_slip($clients, $orders);
    }

    /**
     * Build packing slip data from pre-fetched clients and orders.
     *
     * @param array $clients Keyed by wp_user_id.
     * @param array $orders  Orders with items attached.
     *
     * @return array {entries: [...], no_zone: [...], zone_summaries: [...]}
     */
    private function build_packing_slip(array $clients, array $orders): array {
        if (empty($orders)) {
            return ['entries' => [], 'no_zone' => [], 'zone_summaries' => []];
        }

        $product_types = $this->resolve_product_types($orders);

        // Batch-fetch freezer order meta for all products.
        $all_product_ids = [];
        foreach ($orders as $order) {
            foreach ($order['items'] as $item) {
                $pid = (int) $item['wc_product_id'];
                if ($pid > 0) {
                    $all_product_ids[$pid] = $pid;
                }
            }
        }
        $freezer_orders = $this->get_freezer_orders(array_values($all_product_ids));

        $entries = [];
        $no_zone = [];

        // Track per-zone aggregates for zone summaries.
        $zone_agg = [];

        foreach ($orders as $order) {
            $uid    = (int) $order['wp_user_id'];
            $client = isset($clients[$uid]) ? $clients[$uid] : null;
            if (!$client) {
                continue;
            }

            $items = [];
            foreach ($order['items'] as $item) {
                $pid  = (int) $item['wc_product_id'];
                $type = isset($product_types[$pid]) ? $product_types[$pid]['product_type'] : 'meal';

                $items[] = [
                    'name'          => $item['order_item_name'],
                    'quantity'      => (int) $item['quantity'],
                    'product_type'  => $type,
                    'wc_product_id' => $pid,
                ];
            }

            // Sort items by freezer order ASC (items without meta go last).
            usort($items, function ($a, $b) use ($freezer_orders) {
                $fa = $freezer_orders[$a['wc_product_id']] ?? 9999;
                $fb = $freezer_orders[$b['wc_product_id']] ?? 9999;
                return $fa - $fb;
            });

            // Calculate mains/sides subtotals.
            $cats = $this->categorize_items($order['items'], $product_types);

            $zone_code = $client['delivery_area_zone'] ?: '';
            $zone_name = $client['delivery_area_name'] ?: '';

            $entry = [
                'initials'    => $client['delivery_initials'] ?: '',
                'zone'        => $zone_code,
                'area_name'   => $zone_name,
                'items'       => $items,
                'mains_count' => $cats['mains_count'],
                'sides_count' => $cats['sides_count'],
                'side_detail' => $cats['side_detail'],
            ];

            // Separate entries with no zone assignment.
            if (empty(trim($zone_code)) && empty(trim($zone_name))) {
                $no_zone[] = $entry;
            } else {
                $entries[] = $entry;

                // Accumulate zone summary data.
                $zkey = $zone_code . '|' . $zone_name;
                if (!isset($zone_agg[$zkey])) {
                    $zone_agg[$zkey] = [
                        'zone'          => $zone_name,
                        'zone_code'     => $zone_code,
                        'total_orders'  => 0,
                        'total_mains'   => 0,
                        'total_sides'   => 0,
                        'side_breakdown' => ['soup' => 0, 'muffins' => 0, 'cereal' => 0, 'dessert' => 0],
                        'products'      => [],
                    ];
                }
                $zone_agg[$zkey]['total_orders']++;
                $zone_agg[$zkey]['total_mains'] += $cats['mains_count'];
                $zone_agg[$zkey]['total_sides'] += $cats['sides_count'];
                foreach ($cats['side_detail'] as $sk => $sv) {
                    $zone_agg[$zkey]['side_breakdown'][$sk] += $sv;
                }

                // Accumulate per-product quantities for zone.
                foreach ($items as $item) {
                    $pid = $item['wc_product_id'];
                    if (!isset($zone_agg[$zkey]['products'][$pid])) {
                        $zone_agg[$zkey]['products'][$pid] = [
                            'name' => $item['name'],
                            'qty'  => 0,
                            'type' => $item['product_type'],
                        ];
                    }
                    $zone_agg[$zkey]['products'][$pid]['qty'] += $item['quantity'];
                }
            }
        }

        // Sort entries by zone ASC, then initials ASC.
        usort($entries, function ($a, $b) {
            $cmp = strcmp($a['zone'], $b['zone']);
            return $cmp !== 0 ? $cmp : strcmp($a['initials'], $b['initials']);
        });

        // Build zone summaries array.
        $zone_summaries = [];
        foreach ($zone_agg as $za) {
            // Convert products map to indexed array.
            $prods = [];
            foreach ($za['products'] as $pid => $p) {
                $prods[] = ['name' => $p['name'], 'qty' => $p['qty'], 'type' => $p['type']];
            }
            usort($prods, function ($a, $b) {
                $cmp = strcmp($a['type'], $b['type']);
                return $cmp !== 0 ? $cmp : strcmp($a['name'], $b['name']);
            });
            $za['products'] = $prods;
            $zone_summaries[] = $za;
        }

        usort($zone_summaries, function ($a, $b) {
            return strcmp($a['zone_code'], $b['zone_code']);
        });

        return [
            'entries'        => $entries,
            'no_zone'        => $no_zone,
            'zone_summaries' => $zone_summaries,
        ];
    }

    /**
     * Generate a picking slip: product-grouped summary across all orders.
     *
     * @param string $delivery_date Y-m-d.
     *
     * @return array
     */
    public function generate_picking_slip(string $delivery_date): array {
        $clients = $this->get_clients_for_delivery_date($delivery_date);
        if (empty($clients)) {
            return [];
        }

        $orders = $this->get_orders_for_date(array_keys($clients), $delivery_date);

        return $this->build_picking_slip($orders);
    }

    /**
     * Generate a picking slip from zone-based selection.
     *
     * @param array  $zone_names Zone names.
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     *
     * @return array
     */
    public function generate_picking_slip_by_zones(array $zone_names, string $start_date, string $end_date): array {
        $clients = $this->get_clients_for_zones($zone_names);
        if (empty($clients)) {
            return [];
        }

        $orders = $this->get_orders_for_range(array_keys($clients), $start_date, $end_date);

        return $this->build_picking_slip($orders);
    }

    /**
     * Build picking slip data from pre-fetched orders.
     *
     * @param array $orders Orders with items attached.
     *
     * @return array
     */
    private function build_picking_slip(array $orders): array {
        if (empty($orders)) {
            return [];
        }

        $product_types = $this->resolve_product_types($orders);

        // Aggregate by wc_product_id.
        $agg = [];
        foreach ($orders as $order) {
            foreach ($order['items'] as $item) {
                $pid = (int) $item['wc_product_id'];
                if (!isset($agg[$pid])) {
                    $type = isset($product_types[$pid]) ? $product_types[$pid]['product_type'] : 'meal';
                    $agg[$pid] = [
                        'product_name'   => $item['order_item_name'],
                        'product_type'   => $type,
                        'total_quantity' => 0,
                    ];
                }
                $agg[$pid]['total_quantity'] += (int) $item['quantity'];
            }
        }

        $result = array_values($agg);

        // Sort by product_type ASC, then product_name ASC.
        usort($result, function ($a, $b) {
            $cmp = strcmp($a['product_type'], $b['product_type']);
            return $cmp !== 0 ? $cmp : strcmp($a['product_name'], $b['product_name']);
        });

        return $result;
    }

    /**
     * Generate a delivery slip: route-grouped list by zone/area with cover sheet.
     *
     * @param string $delivery_date Y-m-d.
     *
     * @return array {zones: [...], cover: [...]}
     */
    public function generate_delivery_slip(string $delivery_date): array {
        $clients = $this->get_clients_for_delivery_date($delivery_date);
        if (empty($clients)) {
            return ['zones' => [], 'cover' => []];
        }

        $orders = $this->get_orders_for_date(array_keys($clients), $delivery_date);

        return $this->build_delivery_slip($clients, $orders);
    }

    /**
     * Generate a delivery slip from zone-based selection.
     *
     * @param array  $zone_names Zone names.
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     *
     * @return array {zones: [...], cover: [...]}
     */
    public function generate_delivery_slip_by_zones(array $zone_names, string $start_date, string $end_date): array {
        $clients = $this->get_clients_for_zones($zone_names);
        if (empty($clients)) {
            return ['zones' => [], 'cover' => []];
        }

        $orders = $this->get_orders_for_range(array_keys($clients), $start_date, $end_date);

        return $this->build_delivery_slip($clients, $orders);
    }

    /**
     * Build delivery slip data from pre-fetched clients and orders.
     *
     * @param array $clients Keyed by wp_user_id.
     * @param array $orders  Orders with items attached.
     *
     * @return array {zones: [...], cover: [...]}
     */
    private function build_delivery_slip(array $clients, array $orders): array {
        if (empty($orders)) {
            return ['zones' => [], 'cover' => []];
        }

        $product_types = $this->resolve_product_types($orders);

        // Group orders by zone → area → stops.
        $zones = [];
        foreach ($orders as $order) {
            $uid    = (int) $order['wp_user_id'];
            $client = isset($clients[$uid]) ? $clients[$uid] : null;
            if (!$client) {
                continue;
            }

            $zone = $client['delivery_area_zone'] ?: '';
            $area = $client['delivery_area_name'] ?: '';
            $key  = $zone . '|' . $area;

            if (!isset($zones[$key])) {
                $zones[$key] = [
                    'zone'  => $zone,
                    'area'  => $area,
                    'stops' => [],
                    'order_count' => 0,
                    'total_items' => 0,
                ];
            }

            $address = trim($client['delivery_street_name'] ?? '');
            if (!empty($client['delivery_city'])) {
                $address .= ', ' . $client['delivery_city'];
            }

            // Build item summary (e.g. "2x Meal + 1x Side").
            $meal_count = 0;
            $side_count = 0;
            foreach ($order['items'] as $item) {
                $pid  = (int) $item['wc_product_id'];
                $type = isset($product_types[$pid]) ? $product_types[$pid]['product_type'] : 'meal';
                $qty  = (int) $item['quantity'];
                if ($type === 'side') {
                    $side_count += $qty;
                } else {
                    $meal_count += $qty;
                }
            }

            $summary_parts = [];
            if ($meal_count > 0) {
                $summary_parts[] = $meal_count . 'x Meal';
            }
            if ($side_count > 0) {
                $summary_parts[] = $side_count . 'x Side';
            }

            $zones[$key]['stops'][] = [
                'initials'      => $client['delivery_initials'] ?: '',
                'address'       => $address,
                'item_summary'  => implode(' + ', $summary_parts),
                'street_name'   => $client['delivery_street_name'] ?: '',
            ];
            $zones[$key]['order_count']++;
            $zones[$key]['total_items'] += $meal_count + $side_count;
        }

        // Sort stops within each zone by street_name ASC.
        foreach ($zones as &$group) {
            usort($group['stops'], function ($a, $b) {
                return strcmp($a['street_name'], $b['street_name']);
            });

            // Remove sort helper fields.
            foreach ($group['stops'] as &$stop) {
                unset($stop['street_name']);
            }
            unset($stop);
        }
        unset($group);

        // Sort groups by zone ASC, then area ASC.
        $result = array_values($zones);
        usort($result, function ($a, $b) {
            $cmp = strcmp($a['zone'], $b['zone']);
            return $cmp !== 0 ? $cmp : strcmp($a['area'], $b['area']);
        });

        // Build cover sheet from zone data.
        $cover = [];
        foreach ($result as $z) {
            $cover[] = [
                'zone'        => $z['zone'],
                'area'        => $z['area'],
                'order_count' => $z['order_count'],
                'total_items' => $z['total_items'],
            ];
        }

        return [
            'zones' => $result,
            'cover' => $cover,
        ];
    }

    /**
     * Generate driver delivery slips for a specific date.
     *
     * Unlike packing/picking/delivery slips which use initials for privacy,
     * these show full customer info for the delivery driver plus cash
     * collection amounts.
     *
     * @param string $delivery_date Y-m-d.
     *
     * @return array Array of slip data grouped by zone.
     */
    public function generate_driver_slips(string $delivery_date): array {
        $clients = $this->get_clients_for_driver_slips($delivery_date);
        if (empty($clients)) {
            return [];
        }

        $orders = $this->get_orders_for_date(array_keys($clients), $delivery_date);

        return $this->build_driver_slips($clients, $orders);
    }

    /**
     * Generate driver slips from zone-based selection.
     *
     * @param array  $zone_names Zone names.
     * @param string $start_date Start date (Y-m-d).
     * @param string $end_date   End date (Y-m-d).
     *
     * @return array
     */
    public function generate_driver_slips_by_zones(array $zone_names, string $start_date, string $end_date): array {
        $clients = $this->get_clients_for_zones_driver($zone_names);
        if (empty($clients)) {
            return [];
        }

        $orders = $this->get_orders_for_range(array_keys($clients), $start_date, $end_date);

        return $this->build_driver_slips($clients, $orders);
    }

    /**
     * Build driver slip data from pre-fetched clients and orders.
     *
     * @param array $clients Keyed by wp_user_id (with PII fields).
     * @param array $orders  Orders with items attached.
     *
     * @return array
     */
    private function build_driver_slips(array $clients, array $orders): array {
        if (empty($orders)) {
            return [];
        }

        // Group orders by zone, keyed by zone code.
        $zones = [];
        foreach ($orders as $order) {
            $uid    = (int) $order['wp_user_id'];
            $client = isset($clients[$uid]) ? $clients[$uid] : null;
            if (!$client) {
                continue;
            }

            $zone_name = $client['delivery_area_name'] ?: '';
            $zone_code = $client['delivery_area_zone'] ?: '';
            $zone_key  = $zone_code . '|' . $zone_name;

            if (!isset($zones[$zone_key])) {
                $zones[$zone_key] = [
                    'zone'      => $zone_name,
                    'zone_code' => $zone_code,
                    'orders'    => [],
                ];
            }

            // Get financial data from the WC order object (Option B per directive).
            $order_id = (int) $order['order_id'];
            $wc_order = wc_get_order($order_id);

            if (!$wc_order) {
                continue;
            }

            $subtotal       = (float) $wc_order->get_subtotal();
            $tax            = (float) $wc_order->get_total_tax();
            $total          = (float) $wc_order->get_total();
            $payment_method = $wc_order->get_payment_method();

            // Collection calculation — reproduces old export-orders.php logic exactly.
            $collect      = null;
            $client_type  = strtolower($client['client_type'] ?? '');
            $delivery_fee = (float) ($client['delivery_fee'] ?? 0);

            if ($payment_method === 'cash' && $client_type === 'private') {
                $collect = $total + $delivery_fee;
            } elseif ($payment_method !== 'cash' && $delivery_fee > 0) {
                $collect = $delivery_fee;
            }

            $zones[$zone_key]['orders'][] = [
                'order_id'       => $order_id,
                'first_name'     => $client['first_name'] ?? '',
                'last_name'      => $client['last_name'] ?? '',
                'address'        => trim($client['delivery_street_name'] ?? ''),
                'city'           => $client['delivery_city'] ?? '',
                'phone'          => $client['client_phone_1'] ?? '',
                'subtotal'       => $subtotal,
                'tax'            => $tax,
                'total'          => $total,
                'collect'        => $collect,
                'delivery_fee'   => $delivery_fee,
                'payment_method' => $payment_method,
                'client_type'    => $client_type,
            ];
        }

        // Sort zones by zone_code ASC.
        $result = array_values($zones);
        usort($result, function ($a, $b) {
            return strcmp($a['zone_code'], $b['zone_code']);
        });

        // Sort orders within each zone by last_name ASC, then first_name ASC.
        foreach ($result as &$zone) {
            usort($zone['orders'], function ($a, $b) {
                $cmp = strcmp($a['last_name'], $b['last_name']);
                return $cmp !== 0 ? $cmp : strcmp($a['first_name'], $b['first_name']);
            });
        }
        unset($zone);

        return $result;
    }

    /**
     * Batch-fetch _freezer_order product meta for the given product IDs.
     *
     * @param array $product_ids WC product IDs.
     * @return array<int, int> Keyed by product ID → freezer order value.
     */
    private function get_freezer_orders(array $product_ids): array {
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
     * @param array $items         Order items with wc_product_id and quantity.
     * @param array $product_types Product type lookup keyed by wc_product_id.
     * @return array {mains_count, sides_count, side_detail: {soup, muffins, cereal, dessert}}
     */
    private function categorize_items(array $items, array $product_types): array {
        $mains   = 0;
        $sides   = 0;
        $soup    = 0;
        $muffins = 0;
        $cereal  = 0;
        $dessert = 0;

        // WC category IDs for side breakdown fallback.
        $side_cat_map = [43 => 'soup', 37 => 'muffins', 23 => 'cereal', 25 => 'dessert'];

        foreach ($items as $item) {
            $pid = (int) $item['wc_product_id'];
            $qty = (int) $item['quantity'];
            $type = isset($product_types[$pid]) ? $product_types[$pid]['product_type'] : 'meal';

            if ($type === 'meal') {
                $mains += $qty;
            } elseif ($type === 'side') {
                $sides += $qty;
                // Try to determine side sub-category via WC taxonomy.
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
                    // Default uncategorized sides to dessert bucket.
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
     * @param array $orders Orders with items attached.
     *
     * @return array<int, array<string, mixed>> Keyed by wc_product_id.
     */
    private function resolve_product_types(array $orders): array {
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
