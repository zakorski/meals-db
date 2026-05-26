<?php
/**
 * Advances a client's ordering dates when an order is placed.
 *
 * On order placement:
 *   - last_order_date (WP usermeta) <- the order's date
 *   - next_order_date (meals_clients column) <- recomputed via the canonical
 *     calculator: last_order_date + ordering_frequency weeks, snapped to the
 *     client's delivery day within that Sunday..Saturday week.
 *
 * WHY A HOOK (not just Quick Order):
 * last_order_date was previously written ONLY by Quick Order, so an order
 * placed through the normal WooCommerce screen never advanced it and the
 * client's next_order_date went stale — the same QO-only gap the fee applier
 * had. This runs from the allocation lifecycle hook so EVERY order advances
 * the dates, regardless of how it was placed.
 *
 * Delivery dates are NOT advanced here — a delivery is recorded by the
 * explicit "Mark as Delivered" action on the client_delivery task, which
 * advances last_delivery_date / next_delivery_date separately.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Client_Dates {

    /**
     * Advance ordering dates for the client behind a WooCommerce order.
     *
     * @param int    $wp_user_id  The order's customer (WP user) id.
     * @param string $order_date  Y-m-d the order was placed.
     * @return bool True if next_order_date was recomputed and written.
     */
    public static function advance_on_order(int $wp_user_id, string $order_date): bool {
        if ($wp_user_id <= 0) {
            return false;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $order_date)) {
            return false;
        }

        global $wpdb;
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT client_id, ordering_frequency, delivery_day
             FROM `{$clients_table}` WHERE wp_user_id = %d AND active = 1 LIMIT 1",
            $wp_user_id
        ), ARRAY_A);

        if (!is_array($client)) {
            return false; // not a tracked client
        }

        // Record the last order date (usermeta, matching existing convention).
        if (function_exists('update_user_meta')) {
            update_user_meta($wp_user_id, 'last_order_date', $order_date);
        }

        $next = MealsDB_Date_Calculator::next_date(
            $order_date,
            (int) ($client['ordering_frequency'] ?? 0),
            $client['delivery_day'] ?? null
        );
        if ($next === null) {
            return false; // no frequency configured — nothing to recompute
        }

        $wpdb->update(
            $clients_table,
            ['next_order_date' => $next],
            ['client_id' => (int) $client['client_id']]
        );
        return true;
    }

    /**
     * Record a delivery as completed (the "Mark as Delivered" action):
     *   - last_delivery_date <- delivered date
     *   - next_delivery_date <- recomputed via the calculator (delivery
     *     frequency, snapped to delivery day).
     *
     * @param int    $client_id      meals_clients id.
     * @param string $delivered_date Y-m-d.
     * @return bool True if next_delivery_date was recomputed and written.
     */
    public static function mark_delivered(int $client_id, string $delivered_date): bool {
        if ($client_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivered_date)) {
            return false;
        }

        global $wpdb;
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT client_id, delivery_frequency, delivery_day
             FROM `{$clients_table}` WHERE client_id = %d LIMIT 1",
            $client_id
        ), ARRAY_A);
        if (!is_array($client)) {
            return false;
        }

        $next = MealsDB_Date_Calculator::next_date(
            $delivered_date,
            (int) ($client['delivery_frequency'] ?? 0),
            $client['delivery_day'] ?? null
        );

        $update = ['last_delivery_date' => $delivered_date];
        if ($next !== null) {
            $update['next_delivery_date'] = $next;
        }
        $wpdb->update($clients_table, $update, ['client_id' => (int) $client['client_id']]);
        return $next !== null;
    }
}
