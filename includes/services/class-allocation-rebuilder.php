<?php
/**
 * Allocation rebuilder — phase 1 of the billing overhaul.
 *
 * Replaces the old per-order, date-prorated allocation with a STATEFUL
 * per-client-month allowance fill:
 *
 *   - Process a client's deliveries (orders) in delivery-date order.
 *   - Fill the delivery's month up to the remaining mains / sides allowance.
 *   - Anything that does not fit spills to the NEXT month (and only the next
 *     month). If it does not fit there either, log a multi-month-spillover
 *     error and stop placing those meals.
 *   - Mains and sides have independent allowance caps.
 *
 * Determinism: a client-month is rebuilt by reading all its orders for the
 * relevant window and recomputing from scratch — never incrementally adjusted.
 * Any order change just marks the client-month dirty; the rebuilder picks it
 * up next time it runs.
 *
 * The summary table (CLIENT_ALLOCATIONS) is refreshed via the existing
 * MealsDB_Allocation_Engine::recalculate_month_totals after the rebuild.
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

class MealsDB_Allocation_Rebuilder {

    /** @var wpdb */
    private $wpdb;
    /** @var MealsDB_Allocation_Engine */
    private $engine;
    /** @var MealsDB_WC_Order_Query */
    private $order_query;

    public function __construct() {
        global $wpdb;
        $this->wpdb        = $wpdb;
        $this->engine      = new MealsDB_Allocation_Engine();
        $this->order_query = new MealsDB_WC_Order_Query($wpdb);
    }

    // =====================================================================
    //  Internal — finalized-month protection (LB-3)
    // =====================================================================

    /**
     * Which of the given (client_id, month) pairs are finalized?
     *
     * Finalized months are submitted invoices and must never be deleted or
     * rewritten by the fill. Returns the subset of $months that are finalized
     * for this client.
     *
     * @param int      $client_id
     * @param string[] $months  YYYY-MM values
     * @return array<string,bool> month => true, for finalized months only
     */
    private function finalized_months(int $client_id, array $months): array {
        if (empty($months)) {
            return [];
        }
        $alloc_summary = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);
        $placeholders  = implode(',', array_fill(0, count($months), '%s'));
        $params        = array_merge([$client_id], $months);
        $rows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT billing_month FROM `{$alloc_summary}`
             WHERE client_id = %d AND billing_month IN ({$placeholders}) AND is_finalized = 1",
            $params
        ));
        $out = [];
        foreach ((array) $rows as $m) {
            $out[(string) $m] = true;
        }
        return $out;
    }

    // =====================================================================
    //  Public API
    // =====================================================================

    /**
     * Mark a (client_id, billing_month) as needing a rebuild. Idempotent.
     * Replaces synchronous allocate-on-order writes with a small write here;
     * the rebuilder later picks it up.
     */
    public function mark_dirty(int $client_id, string $billing_month): void {
        if ($client_id <= 0 || !self::is_billing_month($billing_month)) {
            return;
        }
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_MONTH_DIRTY);
        // Unique key on (client_id, billing_month) makes this idempotent.
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO `{$table}` (client_id, billing_month) VALUES (%d, %s)
             ON DUPLICATE KEY UPDATE marked_at = CURRENT_TIMESTAMP",
            $client_id,
            $billing_month
        ));
    }

    /**
     * Rebuild a single client-month. Reads ALL the client's orders that could
     * affect this month and recomputes delivery_allocations from scratch, then
     * refreshes the summaries.
     *
     * The fill window is THREE months — {prior, current, next} — because spill
     * crosses month boundaries in both directions relative to the month we are
     * rebuilding:
     *   - a PRIOR-month delivery's overflow spills INTO the current month, so
     *     prior must be present to consume the right amount of current's
     *     headroom; and
     *   - a CURRENT-month delivery's overflow spills INTO the next month, so
     *     next must be present or that overflow has nowhere to land and would
     *     be (wrongly) logged as a multi-month spillover.
     *
     * Earlier this window was only {prior, current}. That dropped every
     * current-month delivery's legitimate single-month spill on the floor: the
     * spill target (next) was absent from the headroom map, so fill_months
     * logged it as an unplaced multi_month_spillover instead of writing the
     * next-month row. The result was silent under-billing of the overflow for
     * the normal invoice/Data-Ops path (which only marks the order's own month
     * dirty). Including next fixes that.
     *
     * Spillover ERRORS are attributed only to the current (center) month — see
     * fill_months $error_month — so the prior and next months, which each earn
     * their own center rebuild, are neither double-logged nor spuriously
     * errored at the trailing edge (next's own overflow targets next+1, which
     * is intentionally outside this window).
     *
     * Returns ['mains_unplaced' => int, 'sides_unplaced' => int] if a
     * multi-month spillover occurred for the current month (error already
     * logged); zeros otherwise.
     */
    public function rebuild_client_month(int $client_id, string $billing_month): array {
        if ($client_id <= 0 || !self::is_billing_month($billing_month)) {
            return ['mains_unplaced' => 0, 'sides_unplaced' => 0];
        }

        // Skip Private clients — no allowance, not government-billed.
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $client_type   = (string) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT client_type FROM `{$clients_table}` WHERE client_id = %d LIMIT 1",
            $client_id
        ));
        if ($client_type === 'Private' || $client_type === '') {
            return ['mains_unplaced' => 0, 'sides_unplaced' => 0];
        }

        $prior_month = self::prior_month($billing_month);
        $next_month  = self::next_month($billing_month);

        // LB-3: never rebuild a finalized month — it's a submitted invoice.
        // The $finalized set covers all three window months so the fill below
        // also protects finalized PRIOR/NEXT neighbours from being deleted or
        // written into (rebuilding open June pulls in May; if May is finalized
        // its submitted detail must survive untouched).
        $finalized = $this->finalized_months($client_id, [
            $prior_month,
            $billing_month,
            $next_month,
        ]);
        if (isset($finalized[$billing_month])) {
            error_log(sprintf(
                '[MealsDB Rebuilder] Skipped finalized target month %s for client %d.',
                $billing_month,
                $client_id
            ));
            $this->clear_dirty($client_id, $billing_month); // consume the flag; nothing to do
            return ['mains_unplaced' => 0, 'sides_unplaced' => 0];
        }

        // Allowances for the three months involved.
        $cap_prior = $this->engine->calculate_permitted_for_month($client_id, $prior_month);
        $cap_curr  = $this->engine->calculate_permitted_for_month($client_id, $billing_month);
        $cap_next  = $this->engine->calculate_permitted_for_month($client_id, $next_month);

        // Gather this client's deliveries whose delivery-month is in
        // {prior, current, next}.
        $deliveries = $this->load_deliveries_for_months(
            $client_id,
            [$prior_month, $billing_month, $next_month]
        );

        // Run the fill across the three months. The fill writes
        // delivery_allocations rows for this client and returns the current
        // month's unplaceable overflow (errors attributed to the center).
        $unplaced = $this->fill_months(
            $client_id,
            [$prior_month => $cap_prior, $billing_month => $cap_curr, $next_month => $cap_next],
            $deliveries,
            $billing_month,
            $finalized              // LB-3: months in here are never deleted or written
        );

        // Refresh summaries for the affected months — but skip any finalized
        // neighbour. The engine already guards finalized months internally, but
        // skipping explicitly here keeps intent clear and avoids the log noise
        // the engine emits when asked to recalculate a finalized month.
        foreach ([$prior_month, $billing_month, $next_month] as $m) {
            if (!isset($finalized[$m])) {
                $this->engine->recalculate_month_totals($client_id, $m);
            }
        }

        // Clear ONLY the month we were asked to rebuild. The prior and next
        // months are recomputed here as context, but their own spillover
        // errors are NOT logged in this pass — so if either is independently
        // dirty it keeps its marker and earns its own center rebuild (which
        // logs its errors). Clearing them here would drop those errors.
        $this->clear_dirty($client_id, $billing_month);

        return $unplaced;
    }

    /**
     * Process all dirty entries that match a filter — used by invoice
     * generation to rebuild only the clients on this invoice for this month
     * (scope A per the spec).
     *
     * @param string             $billing_month YYYY-MM
     * @param array<int>|null    $client_ids    Restrict to these client IDs (null = all dirty for this month).
     * @return array{rebuilt:int, errors:int}
     */
    public function rebuild_for_invoice(string $billing_month, ?array $client_ids = null): array {
        if (!self::is_billing_month($billing_month)) {
            return ['rebuilt' => 0, 'errors' => 0];
        }
        $dirty_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_MONTH_DIRTY);

        if ($client_ids === null) {
            $rows = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT DISTINCT client_id FROM `{$dirty_table}` WHERE billing_month = %s",
                $billing_month
            ));
        } elseif (empty($client_ids)) {
            return ['rebuilt' => 0, 'errors' => 0];
        } else {
            $ids = implode(',', array_map('intval', $client_ids));
            $rows = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT DISTINCT client_id FROM `{$dirty_table}`
                 WHERE billing_month = %s AND client_id IN ({$ids})",
                $billing_month
            ));
        }

        $rebuilt = 0;
        $errors  = 0;
        foreach ((array) $rows as $cid) {
            $res = $this->rebuild_client_month((int) $cid, $billing_month);
            $rebuilt++;
            if (($res['mains_unplaced'] ?? 0) > 0 || ($res['sides_unplaced'] ?? 0) > 0) {
                $errors++;
            }
        }
        return ['rebuilt' => $rebuilt, 'errors' => $errors];
    }

    /**
     * Rebuild every dirty client-month (the manual "Recalculate Allocations"
     * Data Ops action).
     *
     * @return array{rebuilt:int, errors:int}
     */
    public function rebuild_all_dirty(): array {
        $dirty_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_MONTH_DIRTY);
        $rows = $this->wpdb->get_results(
            "SELECT client_id, billing_month FROM `{$dirty_table}` ORDER BY billing_month ASC, client_id ASC",
            ARRAY_A
        );
        $rebuilt = 0;
        $errors  = 0;
        foreach ((array) $rows as $r) {
            $res = $this->rebuild_client_month((int) $r['client_id'], (string) $r['billing_month']);
            $rebuilt++;
            if (($res['mains_unplaced'] ?? 0) > 0 || ($res['sides_unplaced'] ?? 0) > 0) {
                $errors++;
            }
        }
        return ['rebuilt' => $rebuilt, 'errors' => $errors];
    }

    // =====================================================================
    //  Internal — fill algorithm
    // =====================================================================

    /**
     * The core allowance fill. Deletes existing delivery_allocations rows for
     * this client across the two months (recompute-from-orders, never
     * incremental), then walks deliveries in date order placing meals into
     * their delivery month, spilling overflow only into the next month.
     *
     * @param int                                       $client_id
     * @param array<string, array{permitted_mains:int, permitted_sides:int, effective_days:int}> $caps  keyed by YYYY-MM
     * @param array<int, array<string, mixed>>          $deliveries Sorted ASC by delivery_date
     * @param string|null                               $error_month When set, a multi-month
     *        spillover error is logged (and counted in the return value) ONLY
     *        for deliveries whose delivery-month equals this month. This lets a
     *        three-month rebuild window attribute errors to its center month
     *        and leave the prior/next months for their own center rebuild —
     *        avoiding double-logging and trailing-edge false positives. When
     *        null (the test seam / legacy behaviour) errors are logged for any
     *        delivery whose overflow cannot be placed within $caps.
     * @return array{mains_unplaced:int, sides_unplaced:int}
     */
    private function fill_months(int $client_id, array $caps, array $deliveries, ?string $error_month = null, array $finalized = []): array {
        $alloc_table = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);
        $months      = array_keys($caps);

        // Wipe the slate for the affected months — we recompute from scratch.
        // LB-3: but NEVER delete a finalized month (submitted invoice). A
        // finalized month is excluded from the DELETE here and from the insert
        // path below, so it keeps exactly the detail rows it had at finalization.
        $months_to_clear = array_values(array_filter($months, static function ($m) use ($finalized) {
            return empty($finalized[$m]);
        }));
        if (!empty($months_to_clear)) {
            $placeholders = implode(',', array_fill(0, count($months_to_clear), '%s'));
            $params = array_merge([$client_id], $months_to_clear);
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM `{$alloc_table}`
                 WHERE client_id = %d AND billing_month IN ({$placeholders})",
                $params
            ));
        }

        // Independent running headroom per month.
        $headroom = [];
        foreach ($caps as $m => $cap) {
            $headroom[$m] = [
                'mains' => (int) ($cap['permitted_mains'] ?? 0),
                'sides' => (int) ($cap['permitted_sides'] ?? 0),
            ];
        }

        $total_unplaced_mains = 0;
        $total_unplaced_sides = 0;

        foreach ($deliveries as $d) {
            $delivery_month = substr((string) $d['delivery_date'], 0, 7);
            $next_month     = self::next_month($delivery_month);

            $remaining_mains = (int) $d['mains'];
            $remaining_tax_sides   = (int) $d['tax_sides'];
            $remaining_nontax_sides = (int) $d['nontax_sides'];

            // Pass 1: fill the delivery month up to headroom.
            $place_to_month = function(string $month) use (
                &$remaining_mains, &$remaining_tax_sides, &$remaining_nontax_sides,
                &$headroom, $d, $client_id, $alloc_table, $finalized
            ) {
                if (!isset($headroom[$month]) || !empty($finalized[$month])) {
                    // Outside our window, OR finalized (immutable): leave the
                    // meals for the caller to spill / count as unplaced. You
                    // cannot retroactively add meals to a submitted invoice.
                    return;
                }
                $put_mains       = min($remaining_mains,                $headroom[$month]['mains']);
                $sides_remaining = $remaining_tax_sides + $remaining_nontax_sides;
                $put_sides       = min($sides_remaining,                $headroom[$month]['sides']);

                // Within sides, allocate taxable first then non-taxable to
                // preserve the existing tax accounting convention.
                $put_tax    = min($remaining_tax_sides,    $put_sides);
                $put_nontax = $put_sides - $put_tax;

                if ($put_mains === 0 && $put_sides === 0) {
                    return;
                }

                $this->wpdb->insert($alloc_table, [
                    'client_id'          => $client_id,
                    'wc_order_id'        => (int) $d['wc_order_id'],
                    'order_date'         => (string) $d['order_date'],
                    'delivery_date'      => (string) $d['delivery_date'],
                    'billing_month'      => $month,
                    'mains_count'        => $put_mains,
                    'sides_count'        => $put_sides,
                    'tax_sides_count'    => $put_tax,
                    'nontax_sides_count' => $put_nontax,
                    'coverage_start'     => (string) $d['delivery_date'],
                    'coverage_end'       => (string) ($d['coverage_end'] ?? $d['delivery_date']),
                ]);

                $remaining_mains        -= $put_mains;
                $remaining_tax_sides    -= $put_tax;
                $remaining_nontax_sides -= $put_nontax;
                $headroom[$month]['mains'] -= $put_mains;
                $headroom[$month]['sides'] -= $put_sides;
            };

            $place_to_month($delivery_month);

            // Spill: anything that did not fit goes to the next month.
            if ($remaining_mains > 0 || $remaining_tax_sides + $remaining_nontax_sides > 0) {
                $place_to_month($next_month);
            }

            // Still left after the single-month spill? Multi-month spillover
            // error — log and stop placing those meals (do NOT cascade). When
            // an $error_month is set, only the center month "owns" the error;
            // prior/next deliveries are recomputed here for context but their
            // errors belong to their own center rebuild.
            if (($remaining_mains > 0 || $remaining_tax_sides + $remaining_nontax_sides > 0)
                && ($error_month === null || $delivery_month === $error_month)) {
                $remaining_sides = $remaining_tax_sides + $remaining_nontax_sides;
                $this->log_spillover_error(
                    $client_id,
                    $delivery_month,
                    (int) $d['wc_order_id'],
                    $remaining_mains,
                    $remaining_sides,
                    sprintf(
                        'Delivery %s could not fit in %s or %s. Mains short %d, sides short %d.',
                        (string) $d['delivery_date'],
                        $delivery_month,
                        $next_month,
                        $remaining_mains,
                        $remaining_sides
                    )
                );
                $total_unplaced_mains += $remaining_mains;
                $total_unplaced_sides += $remaining_sides;
            }
        }

        return ['mains_unplaced' => $total_unplaced_mains, 'sides_unplaced' => $total_unplaced_sides];
    }

    // =====================================================================
    //  Internal — data access
    // =====================================================================

    /**
     * Load all of this client's deliveries (one row per WC order) whose
     * delivery-month is in $months. Returns rows sorted by delivery_date ASC.
     *
     * @param int      $client_id
     * @param string[] $months   YYYY-MM strings
     * @return list<array{wc_order_id:int, order_date:string, delivery_date:string, mains:int, tax_sides:int, nontax_sides:int, coverage_end:string}>
     */
    protected function load_deliveries_for_months(int $client_id, array $months): array {
        if (empty($months)) {
            return [];
        }
        // Build month-window date range: first day of earliest .. last day of latest.
        sort($months);
        $range_start = $months[0] . '-01';
        $end_month   = end($months);
        $range_end   = (new DateTime($end_month . '-01'))->format('Y-m-t');

        $orders_table = $this->wpdb->prefix . 'wc_orders';
        $meta_table   = $this->wpdb->prefix . 'wc_orders_meta';

        // Pull orders whose date_created falls in the window AND that belong
        // to this client (either via mealsdb_client_id meta or by joining the
        // clients table on customer_id).
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $wp_user_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT wp_user_id FROM `{$clients_table}` WHERE client_id = %d",
            $client_id
        ));

        // Cast a slightly wider net on order date because delivery_date may
        // sit a few days off the order_date; the engine's schedule maps
        // order date -> delivery date downstream.
        $orders = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT o.id, DATE(o.date_created_gmt) AS order_date
             FROM `{$orders_table}` o
             WHERE o.customer_id = %d
               AND o.status NOT IN ('wc-cancelled','wc-failed','wc-refunded','wc-trash')
               AND DATE(o.date_created_gmt) BETWEEN %s AND %s",
            $wp_user_id,
            (new DateTime($range_start))->modify('-7 days')->format('Y-m-d'),
            (new DateTime($range_end))->modify('+7 days')->format('Y-m-d')
        ), ARRAY_A);

        if (empty($orders)) {
            return [];
        }

        // Extract mains/sides per order using the same product-table lookup
        // as MealsDB_Allocation_Engine::allocate_order, then resolve delivery
        // date via the engine's schedule for the appropriate month.
        $products_table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $deliveries = [];

        foreach ($orders as $o) {
            $wc_order_id = (int) $o['id'];
            $order_date  = (string) $o['order_date'];

            $items = $this->order_query->get_order_items([$wc_order_id]);
            if (empty($items)) {
                continue;
            }
            $mains = 0; $tax_sides = 0; $nontax_sides = 0;
            foreach ($items as $it) {
                $pd = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT product_type, taxable FROM `{$products_table}` WHERE wc_product_id = %d",
                    (int) $it['wc_product_id']
                ), ARRAY_A);
                if (!$pd) continue;
                $qty = (int) $it['quantity'];
                if ($pd['product_type'] === 'meal') {
                    $mains += $qty;
                } elseif ($pd['product_type'] === 'side') {
                    if ((int) $pd['taxable'] === 1) $tax_sides += $qty;
                    else                            $nontax_sides += $qty;
                }
            }
            if ($mains === 0 && $tax_sides === 0 && $nontax_sides === 0) {
                continue;
            }

            // Delivery date: match to the schedule for the order's month.
            $order_month   = substr($order_date, 0, 7);
            $schedule      = $this->engine->calculate_delivery_schedule($client_id, $order_month);
            $delivery_date = $order_date;
            $coverage_end  = $order_date;
            foreach ($schedule as $delivery) {
                if ($delivery['delivery_date'] === $order_date) {
                    $delivery_date = $delivery['delivery_date'];
                    $coverage_end  = $delivery['coverage_end'] ?? $delivery_date;
                    break;
                }
            }
            if ($delivery_date === $order_date) {
                foreach ($schedule as $delivery) {
                    if ($order_date >= ($delivery['coverage_start'] ?? '') && $order_date <= ($delivery['coverage_end'] ?? '')) {
                        $delivery_date = $delivery['delivery_date'];
                        $coverage_end  = $delivery['coverage_end'] ?? $delivery_date;
                        break;
                    }
                }
            }

            // Keep only deliveries whose delivery-month is in the requested set.
            if (!in_array(substr($delivery_date, 0, 7), $months, true)) {
                continue;
            }

            $deliveries[] = [
                'wc_order_id'   => $wc_order_id,
                'order_date'    => $order_date,
                'delivery_date' => $delivery_date,
                'mains'         => $mains,
                'tax_sides'     => $tax_sides,
                'nontax_sides'  => $nontax_sides,
                'coverage_end'  => $coverage_end,
            ];
        }

        usort($deliveries, static function($a, $b) {
            return strcmp($a['delivery_date'], $b['delivery_date']);
        });
        return $deliveries;
    }

    private function clear_dirty(int $client_id, string $billing_month): void {
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_MONTH_DIRTY);
        $this->wpdb->delete($table, [
            'client_id'     => $client_id,
            'billing_month' => $billing_month,
        ]);
    }

    private function log_spillover_error(
        int $client_id, string $billing_month, int $wc_order_id,
        int $mains_unplaced, int $sides_unplaced, string $message
    ): void {
        // Upsert on the dedup key (directive MAJ-2). The nightly rebuilder
        // re-processes dirty months, so a still-unresolved spillover on the
        // same (client, month, order, type) used to write a fresh row every
        // run — after a week of an unresolved spillover there were 7
        // identical rows burying the signal. We now bump occurrence_count and
        // refresh last_seen_at + the latest figures/message instead.
        // first_seen_at is written ONLY on the initial INSERT (it is omitted
        // from the UPDATE clause), so a long-running error keeps its true
        // first occurrence while last_seen_at advances. Relies on the
        // uniq_dedup UNIQUE index added in MealsDB_Schema.
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::ALLOCATION_ERRORS);
        $now   = gmdate('Y-m-d H:i:s');
        $sql = $this->wpdb->prepare(
            "INSERT INTO `{$table}`
                (client_id, billing_month, wc_order_id, error_type,
                 mains_unplaced, sides_unplaced, message,
                 occurrence_count, first_seen_at, last_seen_at)
             VALUES (%d, %s, %d, %s, %d, %d, %s, 1, %s, %s)
             ON DUPLICATE KEY UPDATE
                mains_unplaced   = VALUES(mains_unplaced),
                sides_unplaced   = VALUES(sides_unplaced),
                message          = VALUES(message),
                occurrence_count = occurrence_count + 1,
                last_seen_at     = VALUES(last_seen_at)",
            $client_id, $billing_month, $wc_order_id, 'multi_month_spillover',
            $mains_unplaced, $sides_unplaced, $message, $now, $now
        );
        $this->wpdb->query($sql);
    }

    // =====================================================================
    //  Helpers
    // =====================================================================

    private static function is_billing_month(string $s): bool {
        return (bool) preg_match('/^\d{4}-\d{2}$/', $s);
    }

    private static function prior_month(string $billing_month): string {
        return (new DateTime($billing_month . '-01'))->modify('-1 month')->format('Y-m');
    }

    private static function next_month(string $billing_month): string {
        return (new DateTime($billing_month . '-01'))->modify('+1 month')->format('Y-m');
    }
}
