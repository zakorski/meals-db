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
        // Order lifecycle hooks — trigger allocation when orders change.
        // Each callback wraps its body in record-fire/try-catch/swallow
        // via the helpers below; instrumentation failure must never
        // propagate to WC checkout.
        add_action('woocommerce_new_order', [self::class, 'on_order_created'], 20, 1);
        add_action('woocommerce_order_status_changed', [self::class, 'on_order_status_changed'], 20, 4);
        add_action('woocommerce_order_status_cancelled', [self::class, 'on_order_cancelled'], 20, 1);
        add_action('woocommerce_order_status_refunded', [self::class, 'on_order_refunded'], 20, 1);
        add_action('woocommerce_order_status_failed', [self::class, 'on_order_failed'], 20, 1);
        // BC-6: a PARTIAL refund leaves the order in an active status, so the
        // full-status refund hook above never fires. woocommerce_order_refunded
        // fires on every refund (partial OR full); we mark the order's client-
        // month(s) dirty so the rebuilder recomputes from NET quantities. (It
        // also fires alongside the full-refund status change — marking dirty
        // twice is idempotent, so no need to distinguish.)
        add_action('woocommerce_order_refunded', [self::class, 'on_order_partially_refunded'], 20, 2);
        add_action('woocommerce_order_status_trash', [self::class, 'on_order_status_trashed'], 20, 1);
        // HPOS: orders are not posts, so trashed_post / before_delete_post never
        // fire for them. Subscribe to WooCommerce's own order-lifecycle hooks,
        // which fire with an order ID and only for orders. (LB-5)
        add_action('woocommerce_trash_order', [self::class, 'on_order_trashed'], 20, 1);
        add_action('woocommerce_delete_order', [self::class, 'on_order_deleted'], 20, 1);

        // Quick Order creates orders via wc_create_order() which fires woocommerce_new_order.
        // No additional hook needed for Quick Order.

        // Nightly cron for recalculating current and next month
        add_action('mealsdb_nightly_allocation_sync', [self::class, 'nightly_sync']);
        if (!wp_next_scheduled('mealsdb_nightly_allocation_sync')) {
            wp_schedule_event(strtotime('tomorrow 03:00:00'), 'daily', 'mealsdb_nightly_allocation_sync');
        }
    }

    /**
     * Record a hook fire if the logger is available. Never throws.
     * Wrapped in a static guard so a missing class during boot order
     * doesn't fatal — the instrumentation degrades to a no-op rather
     * than breaking the production path.
     */
    private static function safe_record_hook(
        string $hook,
        string $target_type,
        int $target_id,
        string $outcome = MealsDB_Hook_Logger::OUTCOME_PROCESSED,
        array $context = [],
        ?string $error = null
    ): void {
        if (!class_exists('MealsDB_Hook_Logger')) {
            return;
        }
        try {
            MealsDB_Hook_Logger::record($hook, $target_type, $target_id, $context, $outcome, $error);
        } catch (\Throwable $e) {
            // Last-resort safety: even if record() throws (it
            // shouldn't, but a future refactor might add a path),
            // surface it via error_log without propagating.
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::error('[Allocation Hooks] safe_record_hook threw: ' . $e->getMessage());
            }
        }
    }

    /**
     * Handle new order creation — allocate if it belongs to a mealsdb client.
     *
     * @param int $order_id
     */
    public static function on_order_created(int $order_id): void {
        try {
            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order) {
                self::safe_record_hook(
                    'woocommerce_new_order', 'order', $order_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'not_wc_order']
                );
                return;
            }

            // Do NOT pre-filter on mealsdb_client_user_id here. That meta is
            // set only by Quick Order, so requiring it silently skipped every
            // order placed through the normal WooCommerce screen — a billing
            // gap. allocate_order() and the fee applier each resolve the
            // client themselves (meta, then the order's native customer_id)
            // and no-op cleanly when the customer isn't a mealsdb client, so
            // a too-narrow gate here is both redundant and harmful. Let any
            // shop_order through and resolve downstream.

            // Apply program fees (delivery fee + monthly contribution) for
            // qualifying government clients. Idempotent and source-agnostic:
            // runs for QO and normal orders alike. Must run before
            // allocate_order so the order reflects final line items.
            MealsDB_Order_Fees::apply_to_order($order_id);

            // Advance ordering dates (last_order_date + next_order_date) for
            // the client, for ANY order regardless of source. Uses the order's
            // own date so back-dated orders don't anchor to "now".
            $order = wc_get_order($order_id);
            if ($order instanceof WC_Order) {
                $cust = (int) $order->get_customer_id();
                $odate = $order->get_date_created();
                if ($cust > 0 && $odate) {
                    MealsDB_Client_Dates::advance_on_order($cust, $odate->date('Y-m-d'));
                }
            }

            $engine = new MealsDB_Allocation_Engine();
            $engine->allocate_order($order_id);

            self::safe_record_hook('woocommerce_new_order', 'order', $order_id);
        } catch (\Throwable $e) {
            self::safe_record_hook(
                'woocommerce_new_order', 'order', $order_id,
                MealsDB_Hook_Logger::OUTCOME_ERRORED,
                ['exception' => get_class($e)],
                $e->getMessage()
            );
            // Swallow — propagating out of woocommerce_new_order would
            // break checkout. Hooks are observe-only relative to WC.
        }
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
        try {
            $cancel_statuses = ['cancelled', 'refunded', 'failed', 'trash'];
            $active_statuses = ['pending', 'processing', 'on-hold', 'completed', 'paid'];

            $engine = new MealsDB_Allocation_Engine();

            // Moving TO a cancelled state — deallocate
            if (in_array($to, $cancel_statuses, true) && in_array($from, $active_statuses, true)) {
                $engine->deallocate_order($order_id);
                self::safe_record_hook(
                    'woocommerce_order_status_changed', 'order', $order_id,
                    MealsDB_Hook_Logger::OUTCOME_PROCESSED,
                    ['from' => $from, 'to' => $to, 'action' => 'deallocate']
                );
                return;
            }

            // Moving FROM a cancelled state back to active — reallocate
            if (in_array($to, $active_statuses, true) && in_array($from, $cancel_statuses, true)) {
                MealsDB_Order_Fees::apply_to_order($order_id);
                $engine->allocate_order($order_id);
                self::safe_record_hook(
                    'woocommerce_order_status_changed', 'order', $order_id,
                    MealsDB_Hook_Logger::OUTCOME_PROCESSED,
                    ['from' => $from, 'to' => $to, 'action' => 'reallocate']
                );
                return;
            }

            // Any other status change on an active order — re-process in case items changed
            if (in_array($to, $active_statuses, true)) {
                MealsDB_Order_Fees::apply_to_order($order_id);
                $engine->allocate_order($order_id);
                self::safe_record_hook(
                    'woocommerce_order_status_changed', 'order', $order_id,
                    MealsDB_Hook_Logger::OUTCOME_PROCESSED,
                    ['from' => $from, 'to' => $to, 'action' => 'reprocess']
                );
                return;
            }

            self::safe_record_hook(
                'woocommerce_order_status_changed', 'order', $order_id,
                MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                ['from' => $from, 'to' => $to, 'reason' => 'no_matching_branch']
            );
        } catch (\Throwable $e) {
            self::safe_record_hook(
                'woocommerce_order_status_changed', 'order', $order_id,
                MealsDB_Hook_Logger::OUTCOME_ERRORED,
                ['from' => $from, 'to' => $to, 'exception' => get_class($e)],
                $e->getMessage()
            );
        }
    }

    /**
     * Handle order cancellation — deallocate.
     *
     * @param int $order_id
     */
    public static function on_order_cancelled(int $order_id): void {
        self::process_deallocation_hook('woocommerce_order_status_cancelled', $order_id);
    }

    /**
     * Handle order refunded — deallocate. Refunded orders should not
     * occupy a billing-month allocation any more than a cancelled one
     * should.
     *
     * @param int $order_id
     */
    public static function on_order_refunded(int $order_id): void {
        self::process_deallocation_hook('woocommerce_order_status_refunded', $order_id);
    }

    /**
     * Handle order failed — deallocate. Same rationale as refunded.
     *
     * @param int $order_id
     */
    public static function on_order_failed(int $order_id): void {
        self::process_deallocation_hook('woocommerce_order_status_failed', $order_id);
    }

    /**
     * BC-6: handle a (possibly partial) refund. The order may stay active, so we
     * do NOT fully deallocate or release the contribution — we just mark the
     * order's client-month(s) dirty so the rebuilder re-derives NET (post-refund)
     * meal quantities. Never throws (instrumentation must not break WC).
     *
     * @param int $order_id
     * @param int $refund_id
     */
    public static function on_order_partially_refunded(int $order_id, int $refund_id = 0): void {
        try {
            (new MealsDB_Allocation_Engine())->mark_order_months_dirty($order_id);
            self::safe_record_hook('woocommerce_order_refunded', 'order', $order_id);
        } catch (\Throwable $e) {
            self::safe_record_hook(
                'woocommerce_order_refunded', 'order', $order_id,
                MealsDB_Hook_Logger::OUTCOME_ERRORED,
                ['exception' => get_class($e)],
                $e->getMessage()
            );
        }
    }

    /**
     * Handle order moved into the trash status (via WC status transition,
     * not the post mechanism — that's on_order_trashed below).
     *
     * @param int $order_id
     */
    public static function on_order_status_trashed(int $order_id): void {
        self::process_deallocation_hook('woocommerce_order_status_trash', $order_id);
    }

    /**
     * Shared deallocation path used by the four status-cancel hooks.
     * Centralises the try/log/swallow plumbing.
     */
    private static function process_deallocation_hook(string $hook_name, int $order_id): void {
        try {
            $engine = new MealsDB_Allocation_Engine();
            $engine->deallocate_order($order_id);
            self::safe_record_hook($hook_name, 'order', $order_id);
        } catch (\Throwable $e) {
            self::safe_record_hook(
                $hook_name, 'order', $order_id,
                MealsDB_Hook_Logger::OUTCOME_ERRORED,
                ['exception' => get_class($e)],
                $e->getMessage()
            );
        }
    }

    /**
     * Handle order trashed (HPOS: woocommerce_trash_order). Fires with an
     * order ID, only for orders, so no post-type guard is needed — the old
     * get_post_type($id) === 'shop_order' guard never matched under HPOS
     * (orders aren't posts), so this handler used to be dead. (LB-5)
     *
     * @param int $order_id
     */
    public static function on_order_trashed(int $order_id): void {
        self::process_deallocation_hook('woocommerce_trash_order', $order_id);
    }

    /**
     * Handle order permanently deleted (HPOS: woocommerce_delete_order).
     * Fires with an order ID, only for orders — no post-type guard needed,
     * and deallocate_order() is self-validating (no-ops if the ID has no
     * allocations). (LB-5)
     *
     * @param int $order_id
     */
    public static function on_order_deleted(int $order_id): void {
        self::process_deallocation_hook('woocommerce_delete_order', $order_id);
    }

    /**
     * Nightly sync — recalculate current and next month for all active government clients.
     */
    public static function nightly_sync(): void {
        $log_id = class_exists('MealsDB_Job_Logger')
            ? MealsDB_Job_Logger::start('nightly_allocation_sync', [
                'months_recalculated' => [],
            ])
            : 0;

        $current_month = gmdate('Y-m');
        $next_month    = gmdate('Y-m', strtotime('first day of next month'));
        $current_count = 0;
        $next_count    = 0;

        try {
            $engine = new MealsDB_Allocation_Engine();

            // LB-1 fix: materialise any dirty client-months BEFORE re-summing.
            // bulk_recalculate_month() only RE-SUMS existing meals_delivery_allocations
            // rows; it does NOT create them. Dirty months that were never built (no
            // invoice run, no manual recalc) have no rows to sum, so the nightly job
            // used to write zero/stale summaries and report success. Consuming the
            // dirty queue via the rebuilder here is what the invoice path and the
            // manual Data-Ops button already do (rebuild_for_invoice / rebuild_all_dirty).
            // Safe to run nightly because the rebuilder now skips finalized months (LB-3).
            $rebuilder     = new MealsDB_Allocation_Rebuilder();
            $rebuild_stats = $rebuilder->rebuild_all_dirty();

            $current_count = $engine->bulk_recalculate_month($current_month);

            // Heartbeat between months: lets the daily report and
            // the hang-detector see we got past the first phase.
            if ($log_id > 0) {
                MealsDB_Job_Logger::heartbeat($log_id, [
                    'records_processed' => $current_count,
                ]);
            }

            $next_count = $engine->bulk_recalculate_month($next_month);

            error_log(sprintf(
                '[MealsDB Allocations] Nightly sync completed for %s and %s',
                $current_month,
                $next_month
            ));

            if ($log_id > 0) {
                MealsDB_Job_Logger::finish($log_id, [
                    'records_processed'   => $current_count + $next_count,
                    'records_updated'     => $current_count + $next_count,
                    'months_recalculated' => [$current_month, $next_month],
                    'current_month_count' => $current_count,
                    'next_month_count'    => $next_count,
                    'dirty_rebuild_stats' => $rebuild_stats,   // LB-1: record what was materialised
                ]);
            }
        } catch (\Throwable $e) {
            if ($log_id > 0) {
                MealsDB_Job_Logger::fail($log_id, $e->getMessage(), [
                    'records_processed'   => $current_count + $next_count,
                    'months_recalculated' => [$current_month, $next_month],
                ]);
            }
            throw $e;
        }
    }
}
