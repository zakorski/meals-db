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

    /**
     * X4 (SQL efficiency): per-instance memo caches for load_deliveries_for_months.
     * A rebuilder instance lives only for the duration of ONE batch — every
     * consumer does `new MealsDB_Allocation_Rebuilder()` for its run (see
     * rebuild_all_dirty / rebuild_for_invoice / the nightly allocation sync) —
     * and that batch never writes the products, clients, order-meta or rate rows
     * these lookups read, so their results are stable for the instance's life.
     * Caching collapses the nested N+1 in the loader: the same product SKU, the
     * same (client, month) delivery schedule, and the same candidate order id all
     * recur across the overlapping 4-month windows of adjacent dirty client-months
     * on the nightly / invoice paths.
     *
     * @var array<int,int|null> wc_order_id => resolved client_id
     */
    private $resolved_client_cache = [];
    /** @var array<int,array|null> wc_product_id => products row (product_type/taxable), or null when unknown. */
    private $product_type_cache = [];
    /** @var array<string,array> "client_id|YYYY-MM" => delivery schedule. */
    private $schedule_cache = [];

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
        // BC-1: a delivery's meals spill FORWARD when their month is over cap, so
        // a row billed to $prior_month may originate from a delivery in the month
        // BEFORE it. To recompute $prior_month's rows correctly we must replay
        // that earlier month's deliveries too — otherwise the fill deletes the
        // spilled-in row (it's in the write window) but never reloads its source
        // (whose delivery-month is outside the window) and the meals vanish.
        // $prior_prior is loaded as a CONSUME-ONLY leading edge: its deliveries
        // are placed so their forward spill into $prior is reproduced, but its
        // OWN rows are neither deleted nor rewritten (they already exist and are
        // billed there).
        $prior_prior_month = self::prior_month($prior_month);

        // LB-3: never rebuild a finalized month — it's a submitted invoice.
        // The $finalized set covers all three WRITE-window months so the fill
        // below also protects finalized PRIOR/NEXT neighbours from being deleted
        // or written into (rebuilding open June pulls in May; if May is finalized
        // its submitted detail must survive untouched). $prior_prior is never in
        // this set — it is already protected from writes/deletes by being
        // consume-only.
        $finalized = $this->finalized_months($client_id, [
            $prior_month,
            $billing_month,
            $next_month,
        ]);
        if (isset($finalized[$billing_month])) {
            // BC-3: a dirty marker on a FINALIZED month means an order arrived
            // (or changed) for a submitted invoice. We cannot rewrite the
            // invoice, but we must NOT pretend the work is done. Leave the dirty
            // flag in place so it resurfaces (the operator can unfinalize-and-
            // rebuild or handle it out of band), and emit a degraded event so
            // the situation is visible. Consuming the flag here — the pre-BC-3
            // behaviour — silently dropped the order with no signal at all.
            // (The flag persisting means the nightly sweep re-emits this event
            // until the month is handled; that recurring nag is intentional —
            // finalized-month order activity is rare and should not be ignored.)
            error_log(sprintf(
                '[MealsDB Rebuilder] Dirty marker on FINALIZED month %s for client %d — left queued, not rebuilt.',
                $billing_month,
                $client_id
            ));
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'  => 'warning',
                    'category'  => 'allocation',
                    'subsystem' => 'allocation_rebuilder',
                    'event'     => 'rebuild.dirty_finalized_month',
                    'outcome'   => 'degraded',
                    'message'   => 'Order activity for a finalized (submitted) month was not materialised.',
                    'context'   => ['client_id' => $client_id, 'billing_month' => $billing_month],
                ]);
            }
            // Deliberately NO clear_dirty(): the month stays queued.
            return ['mains_unplaced' => 0, 'sides_unplaced' => 0];
        }

        // Allowances for the four months involved (write window + the
        // consume-only leading edge).
        $cap_prior_prior = $this->engine->calculate_permitted_for_month($client_id, $prior_prior_month);
        $cap_prior       = $this->engine->calculate_permitted_for_month($client_id, $prior_month);
        $cap_curr        = $this->engine->calculate_permitted_for_month($client_id, $billing_month);
        $cap_next        = $this->engine->calculate_permitted_for_month($client_id, $next_month);

        // Gather this client's deliveries whose delivery-month is in
        // {prior_prior, prior, current, next}.
        $deliveries = $this->load_deliveries_for_months(
            $client_id,
            [$prior_prior_month, $prior_month, $billing_month, $next_month]
        );

        // Run the fill across the four months. The fill writes
        // delivery_allocations rows for this client and returns the current
        // month's unplaceable overflow (errors attributed to the center).
        // $prior_prior is consume-only: it grants headroom (so its forward spill
        // into $prior is computed) but is never deleted or written.
        $unplaced = $this->fill_months(
            $client_id,
            [
                $prior_prior_month => $cap_prior_prior,
                $prior_month       => $cap_prior,
                $billing_month     => $cap_curr,
                $next_month        => $cap_next,
            ],
            $deliveries,
            $billing_month,
            $finalized,                          // LB-3: months in here are never deleted or written
            [$prior_prior_month => true]         // BC-1: consume-only — placed but never deleted/written
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
    private function fill_months(int $client_id, array $caps, array $deliveries, ?string $error_month = null, array $finalized = [], array $consume_only = []): array {
        $alloc_table = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);
        $months      = array_keys($caps);

        // Wipe the slate for the affected months — we recompute from scratch.
        // LB-3: but NEVER delete a finalized month (submitted invoice). A
        // finalized month is excluded from the DELETE here and from the insert
        // path below, so it keeps exactly the detail rows it had at finalization.
        // BC-1: a consume-only month (the prior-prior leading edge) is likewise
        // excluded from the DELETE — its rows already exist and are billed there;
        // we only replay it to reproduce its forward spill into the write window.
        $months_to_clear = array_values(array_filter($months, static function ($m) use ($finalized, $consume_only) {
            return empty($finalized[$m]) && empty($consume_only[$m]);
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
                &$headroom, $d, $client_id, $alloc_table, $finalized, $consume_only
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

                // BC-1: a consume-only month (prior-prior leading edge) consumes
                // headroom — so the delivery's overflow into the write window is
                // computed correctly — but its row is NOT written. Its existing,
                // already-billed row was deliberately left in place (not deleted
                // above), so re-inserting here would duplicate it.
                if (empty($consume_only[$month])) {
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
                }

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

    /**
     * Resolve an order's delivery date + coverage-end for allocation.
     *
     * An operator-set `_delivery_date` override (Y-m-d) is AUTHORITATIVE: it
     * decides which billing month the order's meals land in, exactly as it
     * decides slip membership (delivery-date-override directive / PR #479 —
     * this is the allocation-side sibling of the slip generator's
     * resolve_delivery_date). A well-formed override wins outright and the
     * schedule is never consulted; coverage collapses to the override day.
     *
     * Without an override the order maps to the client's computed delivery
     * schedule for the order's month: an exact delivery-date match first, then
     * the coverage window that contains the order date, and finally — if the
     * schedule yields nothing — the order date itself.
     *
     * Malformed overrides (not zero-padded Y-m-d, carrying a time component,
     * etc.) are treated as "no override", mirroring the /^\d{4}-\d{2}-\d{2}$/
     * guard the slip selection uses — a bad value must never become a
     * delivery date. Pure function (no DB) so it is unit-tested directly; see
     * tests/test-allocation-delivery-date-override.php. Before this existed the
     * rebuilder ignored the override entirely and billed overridden deliveries
     * to the schedule-derived (wrong) month (audit-2026-08 B04, WONT_WORK HIGH).
     *
     * @param string                    $order_date Y-m-d (order created date).
     * @param list<array<string,mixed>> $schedule   Rows from calculate_delivery_schedule().
     * @param string                    $override   Raw _delivery_date meta ('' if none).
     * @return array{0:string,1:string} [delivery_date, coverage_end] as Y-m-d.
     */
    public static function resolve_delivery_date(string $order_date, array $schedule, string $override = ''): array {
        // Operator override wins outright — same rule as slip selection.
        if ($override !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $override)) {
            return [$override, $override];
        }

        // Exact delivery-date match for the order's own date.
        foreach ($schedule as $delivery) {
            if (($delivery['delivery_date'] ?? null) === $order_date) {
                return [
                    (string) $delivery['delivery_date'],
                    (string) ($delivery['coverage_end'] ?? $delivery['delivery_date']),
                ];
            }
        }
        // Otherwise the coverage window that contains the order date.
        foreach ($schedule as $delivery) {
            if ($order_date >= ($delivery['coverage_start'] ?? '')
                && $order_date <= ($delivery['coverage_end'] ?? '')) {
                return [
                    (string) $delivery['delivery_date'],
                    (string) ($delivery['coverage_end'] ?? $delivery['delivery_date']),
                ];
            }
        }
        // No schedule match: the order date is its own delivery date.
        return [$order_date, $order_date];
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

        // Pull orders whose date_created falls in the window AND that belong to
        // this client. BC-1: two sources, merged and de-duplicated by order id —
        //   (a) orders pinned to THIS client by mealsdb_client_id meta
        //       (authoritative; works even when the client has no wp_user link); and
        //   (b) orders matched by WC customer_id, but ONLY when this client owns
        //       the wp_user link (wp_user_id > 0), and each filtered through the
        //       canonical resolver so a dual-program user's orders (MAJ-1) route
        //       to exactly one client and orders pinned elsewhere are excluded.
        // Previously this keyed solely on `customer_id = wp_user_id` with no
        // wp_user_id>0 guard and ignored the meta — so a client with no link
        // claimed every guest order (customer_id 0), meta-pinned orders were
        // never loaded, and dual-program users were double-counted.
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $wp_user_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT wp_user_id FROM `{$clients_table}` WHERE client_id = %d",
            $client_id
        ));

        // Cast a slightly wider net on order date because delivery_date may
        // sit a few days off the order_date; the engine's schedule maps
        // order date -> delivery date downstream.
        $date_lo = (new DateTime($range_start))->modify('-7 days')->format('Y-m-d');
        $date_hi = (new DateTime($range_end))->modify('+7 days')->format('Y-m-d');
        $excluded_statuses = "('wc-cancelled','wc-failed','wc-refunded','wc-trash','wc-checkout-draft')";

        // (a) Meta-pinned orders for this client.
        $pinned = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT o.id, DATE(o.date_created_gmt) AS order_date
             FROM `{$orders_table}` o
             INNER JOIN `{$meta_table}` m ON m.order_id = o.id
                 AND m.meta_key = 'mealsdb_client_id' AND m.meta_value = %s
             WHERE o.type = 'shop_order'
               AND o.status NOT IN {$excluded_statuses}
               AND DATE(o.date_created_gmt) BETWEEN %s AND %s",
            (string) $client_id,
            $date_lo,
            $date_hi
        ), ARRAY_A);

        // (b) customer_id-matched orders, resolved to a single client.
        $by_customer = [];
        if ($wp_user_id > 0) {
            $candidate_orders = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT o.id, DATE(o.date_created_gmt) AS order_date
                 FROM `{$orders_table}` o
                 WHERE o.customer_id = %d
                   AND o.type = 'shop_order'
                   AND o.status NOT IN {$excluded_statuses}
                   AND DATE(o.date_created_gmt) BETWEEN %s AND %s",
                $wp_user_id,
                $date_lo,
                $date_hi
            ), ARRAY_A);
            foreach ((array) $candidate_orders as $co) {
                $oid = (int) $co['id'];
                // X4: resolve fires 1-4 queries and its inputs (order meta, client
                // links, rates) are not mutated by the rebuild, so memoise per
                // order id — the same candidate recurs across adjacent client-
                // months' overlapping windows on the nightly / invoice paths.
                if (!array_key_exists($oid, $this->resolved_client_cache)) {
                    $this->resolved_client_cache[$oid] = $this->engine->resolve_client_id_for_order($oid);
                }
                if ($this->resolved_client_cache[$oid] === $client_id) {
                    $by_customer[] = $co;
                }
            }
        }

        // (c) Override-loaded orders. An operator `_delivery_date` override can
        // move a delivery arbitrarily far from the order-created date — outside
        // the ±7-day padded creation window (a)/(b) scan — so such orders are
        // invisible above. Load any order for this client whose override lands
        // inside the requested month range, keyed on the override VALUE rather
        // than the creation date (mirrors the override-aware slip selection).
        // resolve_delivery_date() lets the override win, and the delivery-month
        // filter downstream keeps only those actually in $months.
        //   (c1) meta-pinned to this client — authoritative.
        $pinned_override = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT o.id, DATE(o.date_created_gmt) AS order_date
             FROM `{$orders_table}` o
             INNER JOIN `{$meta_table}` m ON m.order_id = o.id
                 AND m.meta_key = 'mealsdb_client_id' AND m.meta_value = %s
             INNER JOIN `{$meta_table}` dd ON dd.order_id = o.id
                 AND dd.meta_key = '_delivery_date'
             WHERE o.type = 'shop_order'
               AND o.status NOT IN {$excluded_statuses}
               AND dd.meta_value BETWEEN %s AND %s",
            (string) $client_id,
            $range_start,
            $range_end
        ), ARRAY_A);
        $pinned = array_merge((array) $pinned, (array) $pinned_override);

        //   (c2) customer_id-owned — routed through the same single-client
        //   resolver as (b) so a dual-program user's override order (MAJ-1)
        //   routes to exactly one client.
        if ($wp_user_id > 0) {
            $candidate_override = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT o.id, DATE(o.date_created_gmt) AS order_date
                 FROM `{$orders_table}` o
                 INNER JOIN `{$meta_table}` dd ON dd.order_id = o.id
                     AND dd.meta_key = '_delivery_date'
                 WHERE o.customer_id = %d
                   AND o.type = 'shop_order'
                   AND o.status NOT IN {$excluded_statuses}
                   AND dd.meta_value BETWEEN %s AND %s",
                $wp_user_id,
                $range_start,
                $range_end
            ), ARRAY_A);
            foreach ((array) $candidate_override as $co) {
                $oid = (int) $co['id'];
                if (!array_key_exists($oid, $this->resolved_client_cache)) {
                    $this->resolved_client_cache[$oid] = $this->engine->resolve_client_id_for_order($oid);
                }
                if ($this->resolved_client_cache[$oid] === $client_id) {
                    $by_customer[] = $co;
                }
            }
        }

        // Merge + de-dup by order id (a pinned order may also match customer_id,
        // and an override source may re-surface an order already in (a)/(b)).
        $orders = [];
        foreach (array_merge((array) $pinned, $by_customer) as $o) {
            $orders[(int) $o['id']] = $o;
        }
        $orders = array_values($orders);

        if (empty($orders)) {
            return [];
        }

        // Fetch operator `_delivery_date` overrides for every loaded order in one
        // query, so resolve_delivery_date() can let a well-formed override win
        // over the computed schedule (it decides the billing month).
        $override_map = $this->order_query->get_delivery_date_overrides(
            array_map(static function ($o) { return (int) $o['id']; }, $orders)
        );

        // Extract mains/sides per order using the same product-table lookup
        // as MealsDB_Allocation_Engine::allocate_order, then resolve delivery
        // date via the engine's schedule for the appropriate month.
        $products_table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $deliveries = [];

        // Legacy overage products: the OLD system injects these SKUs into
        // orders for its own accounting. The new system must NOT count them
        // (they would inflate mains/sides during the parallel run). We
        // exclude by product ID — the products' meals_products rows are left
        // intact (the old system still needs them), so this is the ONLY
        // place they are filtered out of the new allocation count. IDs come
        // from the canonical constants, not hardcoded here.
        //
        // Filter the UNION of the operator-CONFIGURED overage IDs
        // (mealsdb_overage_product_ids, via overage_product_ids()) and the
        // seed defaults: on installs where the operator re-pointed an
        // overage SKU, the configured ID is what the old system now injects,
        // while orders placed before the change still carry the seed ID —
        // both appear within the parallel-run window and both must be
        // excluded. Overage SKUs are never legitimate meals, so a superset
        // filter can never under-count a real main/side.
        //
        // X4: this set is invariant per call — computed ONCE here as an
        // id=>true membership map rather than rebuilt (and array_unique'd) on
        // every order iteration as it was before.
        $overage_ids = array_fill_keys(array_map('intval', array_merge(
            array_values(MealsDB_Operational_Constants::overage_product_ids()),
            array_values(MealsDB_Operational_Constants::default_overage_product_ids())
        )), true);

        foreach ($orders as $o) {
            $wc_order_id = (int) $o['id'];
            $order_date  = (string) $o['order_date'];

            $items = $this->order_query->get_order_items([$wc_order_id]);
            if (empty($items)) {
                continue;
            }
            $mains = 0; $tax_sides = 0; $nontax_sides = 0;
            foreach ($items as $it) {
                $wc_pid = (int) $it['wc_product_id'];
                if (isset($overage_ids[$wc_pid])) {
                    continue; // legacy overage SKU — never counted by the new system
                }
                // X4: memoise the product-type/taxable read per wc_product_id.
                // The products table is static config the rebuild never writes,
                // so the same SKU (the standard main/side) recurs across many
                // orders and items and would otherwise fire an identical
                // single-row SELECT each time — the dominant N+1 here.
                if (!array_key_exists($wc_pid, $this->product_type_cache)) {
                    $this->product_type_cache[$wc_pid] = $this->wpdb->get_row($this->wpdb->prepare(
                        "SELECT product_type, taxable FROM `{$products_table}` WHERE wc_product_id = %d",
                        $wc_pid
                    ), ARRAY_A);
                }
                $pd = $this->product_type_cache[$wc_pid];
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

            // Delivery date: an operator `_delivery_date` override wins outright;
            // otherwise match to the client's schedule for the order's month.
            // X4: the schedule is deterministic per (client, month) and client_id
            // is fixed for this call, so memoise it — several orders share a month
            // and the same (client, month) recurs across adjacent dirty months'
            // overlapping 4-month windows.
            $order_month   = substr($order_date, 0, 7);
            $sched_key     = $client_id . '|' . $order_month;
            if (!array_key_exists($sched_key, $this->schedule_cache)) {
                $this->schedule_cache[$sched_key] = $this->engine->calculate_delivery_schedule($client_id, $order_month);
            }
            $schedule = $this->schedule_cache[$sched_key];
            [$delivery_date, $coverage_end] = self::resolve_delivery_date(
                $order_date,
                $schedule,
                (string) ($override_map[$wc_order_id] ?? '')
            );

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
            // Row-alias form (INSERT ... AS new): the VALUES(col) referencing
            // form inside ON DUPLICATE KEY UPDATE is deprecated in MySQL 8.0.20
            // (warning-only, removal-path). new.col is the equivalent replacement
            // and needs MySQL 8.0.19+ (deployment target is MySQL 8.x). The
            // occurrence_count bump reads the EXISTING column (not new.), so it
            // stays as-is; first_seen_at remains omitted from the UPDATE clause.
            "INSERT INTO `{$table}`
                (client_id, billing_month, wc_order_id, error_type,
                 mains_unplaced, sides_unplaced, message,
                 occurrence_count, first_seen_at, last_seen_at)
             VALUES (%d, %s, %d, %s, %d, %d, %s, 1, %s, %s) AS new
             ON DUPLICATE KEY UPDATE
                mains_unplaced   = new.mains_unplaced,
                sides_unplaced   = new.sides_unplaced,
                message          = new.message,
                occurrence_count = occurrence_count + 1,
                last_seen_at     = new.last_seen_at",
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
