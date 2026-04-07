<?php
/**
 * Wires the allocation engine into WordPress order lifecycle hooks and cron.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Allocation_Hooks {

    /**
     * Register all hooks for order lifecycle and nightly cron.
     */
    public static function init(): void {
        // Order lifecycle hooks — trigger allocation when orders change
        add_action('woocommerce_new_order', [self::class, 'on_order_created'], 20, 1);
        add_action('woocommerce_order_status_changed', [self::class, 'on_order_status_changed'], 20, 4);
        add_action('woocommerce_order_status_cancelled', [self::class, 'on_order_cancelled'], 20, 1);
        add_action('woocommerce_order_status_trash', [self::class, 'on_order_cancelled'], 20, 1);
        add_action('trashed_post', [self::class, 'on_order_trashed'], 20, 1);
        add_action('before_delete_post', [self::class, 'on_order_deleted'], 20, 1);

        // Quick Order creates orders via wc_create_order() which fires woocommerce_new_order.
        // No additional hook needed for Quick Order.

        // Nightly cron for recalculating current and next month
        add_action('mealsdb_nightly_allocation_sync', [self::class, 'nightly_sync']);
        if (!wp_next_scheduled('mealsdb_nightly_allocation_sync')) {
            wp_schedule_event(strtotime('tomorrow 03:00:00'), 'daily', 'mealsdb_nightly_allocation_sync');
        }
    }

    /**
     * Handle new order creation — allocate if it belongs to a mealsdb client.
     *
     * @param int $order_id
     */
    public static function on_order_created(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) return;

        // Only process orders that have a mealsdb client
        $client_user_id = (int) $order->get_meta('mealsdb_client_user_id');
        if ($client_user_id <= 0) return;

        MealsDB_Allocation_Engine::allocate_order($order_id);
    }

    /**
     * Handle order status transitions — allocate, deallocate, or re-process.
     *
     * @param int      $order_id
     * @param string   $from
     * @param string   $to
     * @param WC_Order $order
     */
    public static function on_order_status_changed(int $order_id, string $from, string $to, $order): void {
        $cancel_statuses = ['cancelled', 'refunded', 'failed', 'trash'];
        $active_statuses = ['pending', 'processing', 'on-hold', 'completed', 'paid'];

        // Moving TO a cancelled state — deallocate
        if (in_array($to, $cancel_statuses, true) && in_array($from, $active_statuses, true)) {
            MealsDB_Allocation_Engine::deallocate_order($order_id);
            return;
        }

        // Moving FROM a cancelled state back to active — reallocate
        if (in_array($to, $active_statuses, true) && in_array($from, $cancel_statuses, true)) {
            MealsDB_Allocation_Engine::allocate_order($order_id);
            return;
        }

        // Any other status change on an active order — re-process in case items changed
        if (in_array($to, $active_statuses, true)) {
            MealsDB_Allocation_Engine::allocate_order($order_id);
        }
    }

    /**
     * Handle order cancellation — deallocate.
     *
     * @param int $order_id
     */
    public static function on_order_cancelled(int $order_id): void {
        MealsDB_Allocation_Engine::deallocate_order($order_id);
    }

    /**
     * Handle order trashed via post mechanism.
     *
     * @param int $post_id
     */
    public static function on_order_trashed(int $post_id): void {
        if (get_post_type($post_id) !== 'shop_order') return;
        MealsDB_Allocation_Engine::deallocate_order($post_id);
    }

    /**
     * Handle order permanently deleted via post mechanism.
     *
     * @param int $post_id
     */
    public static function on_order_deleted(int $post_id): void {
        if (get_post_type($post_id) !== 'shop_order') return;
        MealsDB_Allocation_Engine::deallocate_order($post_id);
    }

    /**
     * Nightly sync — recalculate current and next month for all active government clients.
     */
    public static function nightly_sync(): void {
        $current_month = gmdate('Y-m');
        $next_month = gmdate('Y-m', strtotime('first day of next month'));

        MealsDB_Allocation_Engine::bulk_recalculate_month($current_month);
        MealsDB_Allocation_Engine::bulk_recalculate_month($next_month);

        error_log(sprintf(
            '[MealsDB Allocations] Nightly sync completed for %s and %s',
            $current_month,
            $next_month
        ));
    }
}
