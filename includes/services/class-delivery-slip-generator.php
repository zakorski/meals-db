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
     * Generate a packing slip: one entry per order, sorted by zone then initials.
     *
     * @param string $delivery_date Y-m-d.
     *
     * @return array
     */
    public function generate_packing_slip(string $delivery_date): array {
        $clients = $this->get_clients_for_delivery_date($delivery_date);
        if (empty($clients)) {
            return [];
        }

        $orders = $this->get_orders_for_date(array_keys($clients), $delivery_date);
        if (empty($orders)) {
            return [];
        }

        $product_types = $this->resolve_product_types($orders);

        $entries = [];
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
                    'name'         => $item['order_item_name'],
                    'quantity'     => (int) $item['quantity'],
                    'product_type' => $type,
                ];
            }

            $entries[] = [
                'initials'  => $client['delivery_initials'] ?: '',
                'zone'      => $client['delivery_area_zone'] ?: '',
                'area_name' => $client['delivery_area_name'] ?: '',
                'items'     => $items,
            ];
        }

        // Sort by zone ASC, then initials ASC.
        usort($entries, function ($a, $b) {
            $cmp = strcmp($a['zone'], $b['zone']);
            return $cmp !== 0 ? $cmp : strcmp($a['initials'], $b['initials']);
        });

        return $entries;
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
     * Generate a delivery slip: route-grouped list by zone/area.
     *
     * @param string $delivery_date Y-m-d.
     *
     * @return array
     */
    public function generate_delivery_slip(string $delivery_date): array {
        $clients = $this->get_clients_for_delivery_date($delivery_date);
        if (empty($clients)) {
            return [];
        }

        $orders = $this->get_orders_for_date(array_keys($clients), $delivery_date);
        if (empty($orders)) {
            return [];
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
        }

        // Sort stops within each zone by street_name ASC, street_number ASC.
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

        return $result;
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
