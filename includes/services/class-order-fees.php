<?php
/**
 * Applies program fees (delivery fee + monthly client contribution) to
 * government-client orders as WooCommerce line items.
 *
 * WHY THIS EXISTS
 * ---------------
 * Previously Quick Order added these fees itself, as WC_Order_Item_Fee
 * objects, inside its order-creation code. That had two problems:
 *
 *   1. Fees only appeared on orders placed THROUGH Quick Order. An order
 *      for the same government client placed through the normal WooCommerce
 *      screen got no fee and no contribution tracking — a silent billing
 *      gap, and exactly the "we miss orders not placed through QO" problem
 *      this plugin is meant to avoid.
 *   2. WC_Order_Item_Fee is a different on-order shape than the fee PRODUCTS
 *      (5675 / 4122) that legacy and normal orders use, forcing every fee
 *      reader to understand two formats (the CRIT-3 dual-mechanism code).
 *
 * This class centralises fee application so it runs for EVERY qualifying
 * order regardless of how it was created, driven from the allocation hook
 * on the same active-status transition that triggers allocation. Quick
 * Order no longer applies fees itself; it is now purely a data-entry UI.
 *
 * TAXATION
 * --------
 * This class does NOT set or override tax on the order or its line items.
 * It adds the fee PRODUCT and lets WooCommerce tax it per that product's
 * own configuration. Tax correctness for fees is owned by the WooCommerce
 * product setup (products 5675 / 4122 are expected to be non-taxable),
 * never asserted here. The plugin reads tax; it does not set it.
 *
 * QUALIFYING RULE
 * ---------------
 *   - Delivery fee:  client is SDNB/Veteran and delivery_fee  > 0.
 *   - Contribution:  client is SDNB/Veteran and client_contribution > 0,
 *                    applied to the FIRST qualifying order of the billing
 *                    month only (guarded by meals_client_allocations
 *                    contribution_applied / contribution_order_id).
 *   Clients with no contribution portion (needs-assessed to $0) simply
 *   never trip the contribution branch.
 *
 * IDEMPOTENCY
 * -----------
 * The allocation hook fires allocate_order on every active-status
 * transition (pending -> processing -> completed all fire), so this
 * applier may run several times for one order. It must not stack fees:
 *   - Delivery fee: skipped if the order already carries the delivery-fee
 *     product as a line item.
 *   - Contribution: guarded by the monthly flag; additionally skipped if
 *     the order already carries the contribution product.
 * Cancellation/refund release is handled elsewhere: deallocate_order()
 * already resets contribution_applied / contribution_order_id when the
 * contribution-bearing order leaves an active status, so the next
 * qualifying order re-applies it.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Order_Fees {

    /**
     * Apply program fees to an order if its customer is a qualifying
     * government client. Safe to call repeatedly (idempotent). Never
     * throws to its caller — the allocation hook swallows, and fee
     * application must not break checkout or status transitions.
     *
     * @param int $wc_order_id
     * @return void
     */
    public static function apply_to_order(int $wc_order_id): void {
        try {
            // Shadow mode: do not mutate WooCommerce orders. The allocation
            // hook still observes the order and populates meals_*; we just
            // don't reach back and add fee line items the legacy system
            // would see.
            if (MealsDB_Shadow_Mode::is_enabled()) {
                return;
            }

            if (!function_exists('wc_get_order') || !class_exists('WC_Order')) {
                return;
            }

            $order = wc_get_order($wc_order_id);
            if (!$order instanceof WC_Order) {
                return;
            }

            $client = self::resolve_government_client($order);
            if ($client === null) {
                return; // Not a government client, or no client record.
            }

            $fee_ids = self::fee_product_ids();
            $delivery_fee        = (float) ($client['delivery_fee'] ?? 0);
            $client_contribution = (float) ($client['client_contribution'] ?? 0);
            $client_id           = (int) $client['client_id'];

            $dirty = false;

            // ---- Delivery fee -------------------------------------------
            if ($delivery_fee > 0
                && $fee_ids['delivery_fee'] > 0
                && !self::order_has_product($order, $fee_ids['delivery_fee'])) {
                if (self::add_fee_product($order, $fee_ids['delivery_fee'], $delivery_fee)) {
                    $dirty = true;
                }
            }

            // ---- Monthly client contribution ----------------------------
            if ($client_contribution > 0 && $fee_ids['client_contribution'] > 0) {
                $billing_month = gmdate('Y-m');
                if (!self::contribution_applied_this_month($client_id, $billing_month)
                    && !self::order_has_product($order, $fee_ids['client_contribution'])) {

                    if (self::add_fee_product($order, $fee_ids['client_contribution'], $client_contribution)) {
                        $dirty = true;
                        self::mark_contribution_applied($client_id, $billing_month, $wc_order_id);
                    }
                }
            }

            if ($dirty) {
                // Recalculate with WooCommerce's own engine (which applies
                // each product's configured tax) and persist.
                $order->calculate_totals();
                $order->save();
            }
        } catch (\Throwable $e) {
            // Observe-only relative to WC. Log and swallow.
            error_log('[MealsDB Order Fees] apply_to_order failed for order '
                . $wc_order_id . ': ' . $e->getMessage());
            // STR-LOG: a fee that failed to apply means the order is
            // mis-billed — visible as degraded, scoped to the order.
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'    => 'error',
                    'category'    => 'billing',
                    'subsystem'   => 'order_fees',
                    'event'       => 'apply_to_order.failed',
                    'outcome'     => MealsDB_Event_Log::OUTCOME_DEGRADED,
                    'message'     => $e->getMessage(),
                    'entity_type' => 'order',
                    'entity_id'   => (int) $wc_order_id,
                ]);
            }
        }
    }

    /**
     * Resolve the order's government (SDNB/Veteran) client row, or null.
     *
     * Resolution mirrors MealsDB_Allocation_Engine::allocate_order so the
     * fee path and the allocation path agree on identity:
     *   1. mealsdb_client_id order meta (set by Quick Order)
     *   2. mealsdb_client_user_id order meta -> wp_user_id
     *   3. the order's native customer_id (covers NORMAL orders that carry
     *      no plugin meta — this is the gate fix that lets non-QO orders
     *      get fees)
     * Only active SDNB/Veteran clients qualify; Private clients return null.
     *
     * @param WC_Order $order
     * @return array<string,mixed>|null
     */
    private static function resolve_government_client(WC_Order $order): ?array {
        global $wpdb;
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $client_id = (int) $order->get_meta('mealsdb_client_id');

        if ($client_id <= 0) {
            $wp_user_id = (int) $order->get_meta('mealsdb_client_user_id');
            if ($wp_user_id <= 0) {
                // Normal-order path: fall back to the WC customer.
                $wp_user_id = (int) $order->get_customer_id();
            }
            if ($wp_user_id > 0) {
                // MAJ-1: a WP user may map to multiple active client records
                // (operator-confirmed dual-program case: SDNB + Veteran).
                // Disambiguate by the order's rate so the fee path and the
                // allocation path agree on which client an order belongs to.
                $client_id = self::resolve_client_id_by_wp_user($wp_user_id, $order);
            }
        }

        if ($client_id <= 0) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT client_id, client_type, delivery_fee, client_contribution
             FROM {$clients_table} WHERE client_id = %d LIMIT 1",
            $client_id
        ), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }
        if (!in_array($row['client_type'], ['SDNB', 'Veteran'], true)) {
            return null; // Private clients are billed differently; no program fees.
        }

        return $row;
    }

    /**
     * Resolve an active meals_clients.client_id from a WP user id for this
     * order, deterministically when the user maps to MULTIPLE client records.
     *
     * Mirrors MealsDB_Allocation_Engine::resolve_client_id_by_wp_user so the
     * fee path and the allocation path agree on identity (directive MAJ-1). A
     * single-client user is returned unchanged (the 99% path); a multi-client
     * (dual-program) user is disambiguated by the order's mealsdb_rate_id,
     * which pins exactly one client. When the order pins none, the first
     * candidate is kept but a `degraded` trunk event is emitted so the
     * ambiguity is logged rather than a fee silently billed to the wrong
     * program.
     *
     * @param int      $wp_user_id WP user / WC customer id.
     * @param WC_Order $order      Order being priced (source of the rate signal).
     * @return int client_id, or 0 if the user maps to no active client.
     */
    private static function resolve_client_id_by_wp_user(int $wp_user_id, WC_Order $order): int {
        if ($wp_user_id <= 0) {
            return 0;
        }

        global $wpdb;
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $candidates = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT client_id FROM {$clients_table} WHERE wp_user_id = %d AND active = 1 ORDER BY client_id ASC",
            $wp_user_id
        )));

        $count = count($candidates);
        if ($count === 0) {
            return 0;
        }
        if ($count === 1) {
            return $candidates[0];
        }

        $rate_id = (int) $order->get_meta('mealsdb_rate_id');
        if ($rate_id > 0) {
            $rates_table  = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES);
            $placeholders = implode(',', array_fill(0, $count, '%d'));
            $matched = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT client_id FROM {$rates_table} WHERE rate_id = %d AND client_id IN ({$placeholders}) LIMIT 1",
                array_merge([$rate_id], $candidates)
            ));
            if ($matched > 0) {
                return $matched;
            }
        }

        if (class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'    => 'warning',
                'category'    => 'allocation',
                'subsystem'   => 'order_fees',
                'event'       => 'resolver.ambiguous_multi_client',
                'outcome'     => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message'     => sprintf(
                    'Order %d: WP user %d maps to %d clients and the order pins none — fee routed to client %d by fallback.',
                    (int) $order->get_id(),
                    $wp_user_id,
                    $count,
                    $candidates[0]
                ),
                'entity_type' => 'order',
                'entity_id'   => (int) $order->get_id(),
                'context'     => [
                    'wp_user_id' => $wp_user_id,
                    'candidates' => $candidates,
                    'chosen'     => $candidates[0],
                ],
            ]);
        }

        return $candidates[0];
    }

    /**
     * Effective fee product IDs, honoring the mealsdb_fee_product_ids
     * option override the readers use, falling back to the constants.
     *
     * @return array{client_contribution:int, delivery_fee:int}
     */
    private static function fee_product_ids(): array {
        if (class_exists('MealsDB_Invoice_Generator')
            && method_exists('MealsDB_Invoice_Generator', 'get_fee_product_ids')) {
            $ids = MealsDB_Invoice_Generator::get_fee_product_ids();
            return [
                'client_contribution' => (int) ($ids['client_contribution'] ?? 0),
                'delivery_fee'        => (int) ($ids['delivery_fee'] ?? 0),
            ];
        }
        $defaults = MealsDB_Operational_Constants::default_fee_product_ids();
        return [
            'client_contribution' => (int) $defaults['client_contribution'],
            'delivery_fee'        => (int) $defaults['delivery_fee'],
        ];
    }

    /**
     * True if the order already carries the given product as a line item.
     * Used for idempotency across repeated hook fires.
     */
    private static function order_has_product(WC_Order $order, int $product_id): bool {
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            if ((int) $item->get_product_id() === $product_id
                || (int) $item->get_variation_id() === $product_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Add a fee product to the order as a quantity-1 line item, priced at the
     * per-client amount (NOT the product's catalog price).
     *
     * LB-2: previously this added the product at its catalog price, ignoring
     * the client's negotiated client_contribution / delivery_fee. Every client
     * whose negotiated fee differed from the catalog default was billed the
     * wrong amount while the per-client dollar values were loaded and discarded.
     * We keep using the fee PRODUCT (5675/4122) so the on-order shape matches
     * legacy/normal orders and the reconciliation/order-query layer can find it
     * uniformly (see file header) — but we override the line subtotal/total to
     * the per-client value. Reconciliation sums _line_subtotal
     * (MealsDB_WC_Order_Query::get_total_paid_for_product), so the override is
     * read back correctly.
     *
     * Does NOT touch tax — WooCommerce applies the product's configured tax
     * during calculate_totals(). Returns true if added.
     *
     * @param float $amount Per-client fee amount in DOLLARS.
     */
    private static function add_fee_product(WC_Order $order, int $product_id, float $amount): bool {
        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product) {
            error_log('[MealsDB Order Fees] Fee product ' . $product_id
                . ' not found; cannot apply fee to order ' . $order->get_id());
            return false;
        }

        // Normalise to a clean 2dp dollar value via MealsDB_Money (integer
        // cents, half-up) so we never push float drift like 40.00000001 into
        // the WC line total. MealsDB_Money has no dollars-out helper, so round
        // through cents -> format() (a "%.2f" string) and cast back to float.
        $amount = (float) MealsDB_Money::format(MealsDB_Money::to_cents($amount));

        $item_id = $order->add_product($product, 1, [
            'subtotal' => $amount,
            'total'    => $amount,
        ]);

        if (!$item_id) {
            return false;
        }
        return true;
    }

    /**
     * Whether this client's monthly contribution is already recorded for
     * the billing month.
     */
    private static function contribution_applied_this_month(int $client_id, string $billing_month): bool {
        global $wpdb;
        $alloc_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);
        $applied = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT contribution_applied FROM {$alloc_table}
             WHERE client_id = %d AND billing_month = %s",
            $client_id,
            $billing_month
        ));
        return $applied === 1;
    }

    /**
     * Record that the contribution has been applied for the month, tagging
     * the order that carries it. deallocate_order() clears this (resets to
     * 0 / NULL keyed on contribution_order_id) when that order is
     * cancelled/refunded, releasing the month for the next qualifying order.
     */
    private static function mark_contribution_applied(int $client_id, string $billing_month, int $wc_order_id): void {
        global $wpdb;
        $alloc_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$alloc_table} (client_id, billing_month, contribution_applied, contribution_order_id)
             VALUES (%d, %s, 1, %d)
             ON DUPLICATE KEY UPDATE contribution_applied = 1, contribution_order_id = %d",
            $client_id,
            $billing_month,
            $wc_order_id,
            $wc_order_id
        ));
    }
}
