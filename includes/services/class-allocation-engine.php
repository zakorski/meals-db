<?php
/**
 * Allocation Engine — Calculation Service.
 *
 * Core service that calculates allowances, splits deliveries across billing
 * months, and maintains the running totals in meals_client_allocations and
 * meals_delivery_allocations.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Allocation_Engine {

    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * @var MealsDB_WC_Order_Query
     */
    private $order_query;

    public function __construct() {
        global $wpdb;
        $this->wpdb        = $wpdb;
        $this->order_query = new MealsDB_WC_Order_Query($wpdb);
    }

    /**
     * Calculate how many mains and sides a client is allowed for a given calendar month.
     *
     * @param int    $client_id    meals_clients.client_id
     * @param string $billing_month Format "YYYY-MM"
     * @return array{permitted_mains: int, permitted_sides: int, effective_days: int}
     */
    public function calculate_permitted_for_month(int $client_id, string $billing_month): array {
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $client = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT allowance_mains, allowance_sides, requisition_period, service_commence_date, termination_date
             FROM {$clients_table} WHERE client_id = %d",
            $client_id
        ), ARRAY_A);

        if (!$client) {
            return ['permitted_mains' => 0, 'permitted_sides' => 0, 'effective_days' => 0];
        }

        $allowance_mains    = (int) $client['allowance_mains'];
        $allowance_sides    = (int) $client['allowance_sides'];
        $requisition_period = strtolower(trim($client['requisition_period'] ?? ''));

        // Parse billing month boundaries.
        $year  = (int) substr($billing_month, 0, 4);
        $month = (int) substr($billing_month, 5, 2);
        // Use DateTime->format('t') instead of cal_days_in_month so we
        // don't depend on the optional php-calendar extension being built
        // into the runtime.
        $days_in_month = (int) (new DateTime(sprintf('%04d-%02d-01', $year, $month)))->format('t');

        $month_start = sprintf('%04d-%02d-01', $year, $month);
        $month_end   = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

        // Determine effective start/end with proration.
        $effective_start = $month_start;
        $effective_end   = $month_end;

        if (!empty($client['service_commence_date']) && $client['service_commence_date'] > $month_start && $client['service_commence_date'] <= $month_end) {
            $effective_start = $client['service_commence_date'];
        }

        if (!empty($client['termination_date']) && $client['termination_date'] >= $month_start && $client['termination_date'] < $month_end) {
            $effective_end = $client['termination_date'];
        }

        // If client hasn't started yet or already terminated before this month.
        if (!empty($client['service_commence_date']) && $client['service_commence_date'] > $month_end) {
            return ['permitted_mains' => 0, 'permitted_sides' => 0, 'effective_days' => 0];
        }
        if (!empty($client['termination_date']) && $client['termination_date'] < $month_start) {
            return ['permitted_mains' => 0, 'permitted_sides' => 0, 'effective_days' => 0];
        }

        // Calculate effective days (inclusive).
        $start_dt = new DateTime($effective_start);
        $end_dt   = new DateTime($effective_end);
        $effective_days = (int) $start_dt->diff($end_dt)->days + 1;

        // Normalize any "X meals per period" requisition into a monthly
        // permitted count via a daily rate, scaled to the effective days:
        //
        //   permitted = floor( (meals_per_period / days_in_period) * effective_days )
        //
        // days_in_period: 1 (day), 7 (week), or the full days in this month
        // (month). This replaces the old hardcoded == 7 / == 14 / == 31
        // branches — those were a crude attempt to cope with the legacy
        // non-standardized requisitions (1/day, 7/week, 30/month, 2/week, ...).
        // The formula handles every X-per-Y uniformly. Mains and sides are
        // floored independently. floor() applies only to the final figure.
        $days_in_period = self::days_in_period($requisition_period, $days_in_month);

        if ($days_in_period <= 0) {
            // Unknown requisition_period — no permitted allowance.
            $mains = 0;
            $sides = 0;
        } else {
            $mains = (int) floor(($allowance_mains / $days_in_period) * $effective_days);
            $sides = (int) floor(($allowance_sides / $days_in_period) * $effective_days);
        }

        return [
            'permitted_mains' => $mains,
            'permitted_sides' => $sides,
            'effective_days'  => $effective_days,
        ];
    }

    /**
     * Days in the requisition period, used to convert an "X per period"
     * allowance into a daily rate. Returns 0 for an unrecognised period.
     *
     * @param string $requisition_period day | week | month (any case).
     * @param int    $days_in_month      Calendar days in the billing month.
     */
    private static function days_in_period(string $requisition_period, int $days_in_month): int {
        switch ($requisition_period) {
            case 'day':   return 1;
            case 'week':  return 7;
            case 'month': return $days_in_month;
            default:      return 0;
        }
    }

    /**
     * Determine the scheduled delivery dates for a client within and around a billing month.
     *
     * @param int    $client_id
     * @param string $billing_month Format "YYYY-MM"
     * @return array Array of ['delivery_date' => string, 'coverage_start' => string, 'coverage_end' => string]
     */
    public function calculate_delivery_schedule(int $client_id, string $billing_month): array {
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $client = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT delivery_day, delivery_frequency, service_commence_date
             FROM {$clients_table} WHERE client_id = %d",
            $client_id
        ), ARRAY_A);

        if (!$client || empty($client['service_commence_date'])) {
            return [];
        }

        $delivery_frequency = (int) $client['delivery_frequency'];
        if ($delivery_frequency <= 0) {
            return [];
        }

        // Parse billing month boundaries.
        $year  = (int) substr($billing_month, 0, 4);
        $month = (int) substr($billing_month, 5, 2);
        // Use DateTime->format('t') instead of cal_days_in_month so we
        // don't depend on the optional php-calendar extension being built
        // into the runtime.
        $days_in_month = (int) (new DateTime(sprintf('%04d-%02d-01', $year, $month)))->format('t');

        $month_start = new DateTime(sprintf('%04d-%02d-01', $year, $month));
        $month_end   = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $days_in_month));

        $commence = new DateTime($client['service_commence_date']);

        // We need deliveries whose coverage overlaps the billing month.
        // Start stepping from service_commence_date.
        // To avoid iterating from the very beginning for long-running clients,
        // jump ahead if commence is far before month_start.
        $cursor = clone $commence;
        if ($cursor < $month_start) {
            $diff_days = (int) $cursor->diff($month_start)->days;
            // Jump forward in delivery_frequency steps, leaving a buffer of one period before month_start.
            // delivery_frequency is a WEEK count, so a period is (frequency * 7) days.
            $period_days     = $delivery_frequency * 7;
            $periods_to_skip = max(0, (int) floor($diff_days / $period_days) - 1);
            if ($periods_to_skip > 0) {
                $cursor->modify('+' . ($periods_to_skip * $period_days) . ' days');
            }
        }

        $results = [];
        // Iterate until the delivery date is past the billing month end.
        // Coverage could still overlap if delivery is before month, so we check coverage_end.
        $safety_limit = 500;
        $iterations   = 0;

        while ($iterations < $safety_limit) {
            $iterations++;

            $delivery_date = clone $cursor;
            $coverage_start = clone $delivery_date;
            $coverage_end   = clone $delivery_date;
            $coverage_end->modify('+' . (($delivery_frequency * 7) - 1) . ' days');

            // If this delivery's coverage_start is past the billing month, stop.
            if ($coverage_start > $month_end) {
                break;
            }

            // Include if the delivery falls within the billing month OR coverage overlaps.
            if ($coverage_end >= $month_start && $coverage_start <= $month_end) {
                $results[] = [
                    'delivery_date'  => $delivery_date->format('Y-m-d'),
                    'coverage_start' => $coverage_start->format('Y-m-d'),
                    'coverage_end'   => $coverage_end->format('Y-m-d'),
                ];
            }

            $cursor->modify('+' . ($delivery_frequency * 7) . ' days');
        }

        return $results;
    }

    /**
     * Allocate a WooCommerce order. Under the new model this is a
     * mark-dirty operation: resolve the order's client + billing month and
     * record (client_id, billing_month) in meals_client_month_dirty. The
     * actual allowance fill happens later via MealsDB_Allocation_Rebuilder,
     * triggered either by invoice generation (scoped to that month + clients)
     * or by the manual "Recalculate Allocations" Data Ops action.
     *
     * @param int $wc_order_id
     */
    public function allocate_order(int $wc_order_id): void {
        $client_id = $this->resolve_client_for_order($wc_order_id);
        if ($client_id <= 0) {
            return;
        }
        $billing_month = $this->resolve_billing_month_for_order($wc_order_id);
        if ($billing_month === null) {
            return;
        }
        (new MealsDB_Allocation_Rebuilder())->mark_dirty($client_id, $billing_month);
    }

    /**
     * Public seam over resolve_client_for_order() for callers that need to
     * route a single order to its owning client through the ONE canonical chain
     * (meta -> customer_id -> wp_user, with MAJ-1 rate disambiguation).
     *
     * BC-1: the allocation rebuilder uses this to filter customer_id-matched
     * orders down to the client actually being rebuilt — so a dual-program user
     * (two clients sharing one wp_user_id) no longer double-counts, and an order
     * pinned by meta to a different client is excluded. Returns 0 when the order
     * routes to no (non-Private) client.
     *
     * @param int $wc_order_id
     * @return int client_id, or 0.
     */
    public function resolve_client_id_for_order(int $wc_order_id): int {
        return $this->resolve_client_for_order($wc_order_id);
    }

    /**
     * Resolve the meals_clients.client_id for a WC order via the same chain
     * the old allocate_order used: mealsdb_client_id meta -> mealsdb_client_user_id
     * meta -> WC native customer_id -> clients.wp_user_id. Skips Private
     * clients (no allowance).
     */
    private function resolve_client_for_order(int $wc_order_id): int {
        $meta_table   = $this->wpdb->prefix . 'wc_orders_meta';
        $orders_table = $this->wpdb->prefix . 'wc_orders';
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $client_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_value FROM {$meta_table} WHERE order_id = %d AND meta_key = 'mealsdb_client_id' LIMIT 1",
            $wc_order_id
        ));

        if (!$client_id) {
            $wp_user_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT meta_value FROM {$meta_table} WHERE order_id = %d AND meta_key = 'mealsdb_client_user_id' LIMIT 1",
                $wc_order_id
            ));
            if ($wp_user_id) {
                $client_id = $this->resolve_client_id_by_wp_user($wp_user_id, $wc_order_id, false);
            }
        }

        if (!$client_id) {
            $customer_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT customer_id FROM {$orders_table} WHERE id = %d",
                $wc_order_id
            ));
            if ($customer_id > 0) {
                $client_id = $this->resolve_client_id_by_wp_user($customer_id, $wc_order_id, true);
            }
        }

        if (!$client_id) {
            return 0;
        }

        // Skip Private clients — they have no monthly allowance and are not
        // government-billed; running them through the rebuilder would just
        // produce zero-filled noise.
        $client_type = (string) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT client_type FROM {$clients_table} WHERE client_id = %d LIMIT 1",
            $client_id
        ));
        if ($client_type === 'Private') {
            return 0;
        }

        return $client_id;
    }

    /**
     * Resolve a meals_clients.client_id from a WordPress user id for a given
     * order, deterministically when the user maps to MULTIPLE client records.
     *
     * Directive MAJ-1: a WP user may legitimately own two client records (a
     * person who is both an SDNB recipient and a Veteran). The historical
     * `wp_user_id ... LIMIT 1` lookup would pick one arbitrarily and silently
     * mis-route the order's billing. When the user has exactly one client the
     * behaviour is unchanged (the program signal is irrelevant — the 99% path).
     * When the user has several, we disambiguate by the order's mealsdb_rate_id:
     * a rate row (meals_client_rates) is unique and carries its owning
     * client_id, so it pins exactly one of the candidates. If the order carries
     * no rate (or it matches none of the candidates) we keep the first
     * candidate but emit a `degraded` trunk event so the mis-route is visible,
     * not silent — turning the original "silently mis-routes" risk into a
     * logged, greppable event.
     *
     * @param int  $wp_user_id   WP user / WC customer id.
     * @param int  $wc_order_id  Order being routed (source of the rate signal).
     * @param bool $active_only  Restrict to active clients (branch-3 semantics).
     * @return int client_id, or 0 if the user maps to no client.
     */
    private function resolve_client_id_by_wp_user(int $wp_user_id, int $wc_order_id, bool $active_only): int {
        if ($wp_user_id <= 0) {
            return 0;
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $active_clause = $active_only ? ' AND active = 1' : '';

        $candidates = array_map('intval', (array) $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT client_id FROM {$clients_table} WHERE wp_user_id = %d{$active_clause} ORDER BY client_id ASC",
            $wp_user_id
        )));

        $count = count($candidates);
        if ($count === 0) {
            return 0;
        }
        if ($count === 1) {
            // Single client: today's behaviour, untouched. The order's program
            // signal is irrelevant when there is only one record to route to.
            return $candidates[0];
        }

        // Multiple clients share this WP user — disambiguate by the order's
        // rate, which pins exactly one client (rate_id is unique in
        // meals_client_rates and carries the owning client_id).
        $meta_table = $this->wpdb->prefix . 'wc_orders_meta';
        $rate_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_value FROM {$meta_table} WHERE order_id = %d AND meta_key = 'mealsdb_rate_id' LIMIT 1",
            $wc_order_id
        ));

        if ($rate_id > 0) {
            $rates_table  = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES);
            $placeholders = implode(',', array_fill(0, $count, '%d'));
            $matched = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT client_id FROM {$rates_table} WHERE rate_id = %d AND client_id IN ({$placeholders}) LIMIT 1",
                array_merge([$rate_id], $candidates)
            ));
            if ($matched > 0) {
                return $matched;
            }
        }

        // Could not pin a single client from the order. Keep the historical
        // first-row pick, but make the ambiguity VISIBLE (degraded) so it can
        // be investigated rather than silently mis-billing.
        if (class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'    => 'warning',
                'category'    => 'allocation',
                'subsystem'   => 'allocation_engine',
                'event'       => 'resolver.ambiguous_multi_client',
                'outcome'     => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message'     => sprintf(
                    'Order %d: WP user %d maps to %d clients and the order pins none — routed to client %d by fallback.',
                    $wc_order_id,
                    $wp_user_id,
                    $count,
                    $candidates[0]
                ),
                'entity_type' => 'order',
                'entity_id'   => $wc_order_id,
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
     * Resolve the delivery month for a WC order from the client's schedule.
     * Returns YYYY-MM or null if the order has no usable date.
     */
    private function resolve_billing_month_for_order(int $wc_order_id): ?string {
        $orders_table = $this->wpdb->prefix . 'wc_orders';
        $order_date = (string) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT DATE(date_created_gmt) FROM {$orders_table} WHERE id = %d",
            $wc_order_id
        ));
        if (!$order_date) {
            return null;
        }
        // Delivery month = the month the order's delivery falls in. For
        // mark-dirty purposes the order-date month is sufficient: the
        // rebuilder rebuilds (prior, month, next) together, so this order's
        // overflow spill into the next month is materialised by the same
        // rebuild, and any month-boundary delivery still lands in one of those.
        return substr($order_date, 0, 7);
    }



    /**
     * Rebuild the summary row in meals_client_allocations from delivery allocation details.
     *
     * @param int    $client_id
     * @param string $billing_month Format "YYYY-MM"
     */
    public function recalculate_month_totals(int $client_id, string $billing_month): void {
        $allocations_table     = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);
        $delivery_alloc_table  = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);

        // Check if month is finalized.
        $is_finalized = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT is_finalized FROM {$allocations_table} WHERE client_id = %d AND billing_month = %s",
            $client_id,
            $billing_month
        ));

        if ($is_finalized === 1) {
            error_log(sprintf(
                '[MealsDB Allocation Engine] Attempted to recalculate finalized month %s for client %d.',
                $billing_month,
                $client_id
            ));
            return;
        }

        // Sum delivery allocations.
        $sums = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT
                COALESCE(SUM(mains_count), 0) as used_mains,
                COALESCE(SUM(sides_count), 0) as used_sides,
                COALESCE(SUM(tax_sides_count), 0) as used_tax_sides,
                COALESCE(SUM(nontax_sides_count), 0) as used_nontax_sides
             FROM {$delivery_alloc_table}
             WHERE client_id = %d AND billing_month = %s",
            $client_id,
            $billing_month
        ), ARRAY_A);

        $used_mains        = (int) $sums['used_mains'];
        $used_sides        = (int) $sums['used_sides'];
        $used_tax_sides    = (int) $sums['used_tax_sides'];
        $used_nontax_sides = (int) $sums['used_nontax_sides'];

        // Calculate permitted values.
        $permitted = $this->calculate_permitted_for_month($client_id, $billing_month);
        $permitted_mains = $permitted['permitted_mains'];
        $permitted_sides = $permitted['permitted_sides'];

        // Calculate overages.
        $overage_mains = max($used_mains - $permitted_mains, 0);
        $overage_sides = max($used_sides - $permitted_sides, 0);

        // Upsert into meals_client_allocations.
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$allocations_table}
                (client_id, billing_month, permitted_mains, permitted_sides,
                 used_mains, used_sides, used_tax_sides, used_nontax_sides,
                 overage_mains, overage_sides)
             VALUES (%d, %s, %d, %d, %d, %d, %d, %d, %d, %d)
             ON DUPLICATE KEY UPDATE
                 permitted_mains = VALUES(permitted_mains),
                 permitted_sides = VALUES(permitted_sides),
                 used_mains = VALUES(used_mains),
                 used_sides = VALUES(used_sides),
                 used_tax_sides = VALUES(used_tax_sides),
                 used_nontax_sides = VALUES(used_nontax_sides),
                 overage_mains = VALUES(overage_mains),
                 overage_sides = VALUES(overage_sides)",
            $client_id, $billing_month,
            $permitted_mains, $permitted_sides,
            $used_mains, $used_sides, $used_tax_sides, $used_nontax_sides,
            $overage_mains, $overage_sides
        ));
    }


    /**
     * Deallocate (cancel/trash/delete) an order. Under the new model this
     * marks every (client_id, billing_month) that had rows for this order
     * as dirty; the rebuilder later recomputes from scratch, which will
     * naturally drop this order's rows since cancelled/refunded statuses are
     * excluded from the order pull.
     *
     * The previous synchronous DELETE + UPDATE + recalculate path lived
     * here; that complexity (transactions, deterministic row locking) is no
     * longer needed because each affected client-month is rebuilt atomically.
     *
     * @param int $wc_order_id
     */
    public function deallocate_order(int $wc_order_id): void {
        $summary_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);

        // BC-2: release the monthly client contribution if THIS order was the
        // carrier, so the next qualifying order in the month re-applies it.
        // Keyed on contribution_order_id so a non-carrier order never clears
        // another order's flag. Runs BEFORE the empty-rows early-return: a
        // contribution-only order (fee, no meals) has no delivery_allocations
        // rows but must still release the month. Previously this reset did not
        // exist (despite the order-fees docblock claiming it did), so a
        // cancelled/refunded carrier left contribution_applied=1 forever and the
        // month was never re-billed the contribution.
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE `{$summary_table}`
             SET contribution_applied = 0, contribution_order_id = NULL
             WHERE contribution_order_id = %d",
            $wc_order_id
        ));

        $this->mark_order_months_dirty($wc_order_id);
    }

    /**
     * Mark every (client_id, billing_month) that had rows for this order as
     * dirty, so the rebuilder recomputes them from scratch. Unlike
     * deallocate_order() this does NOT release the contribution flag — it is the
     * right action for a PARTIAL refund (BC-6), where the order stays active and
     * still carries its contribution; only the meal quantities change, and the
     * rebuilder picks up the NET (post-refund) quantities on its next pass.
     *
     * @param int $wc_order_id
     */
    public function mark_order_months_dirty(int $wc_order_id): void {
        $delivery_alloc_table = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);

        $affected = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT DISTINCT client_id, billing_month FROM {$delivery_alloc_table} WHERE wc_order_id = %d",
            $wc_order_id
        ), ARRAY_A);

        if (empty($affected)) {
            return;
        }

        $rebuilder = new MealsDB_Allocation_Rebuilder();
        foreach ($affected as $row) {
            $rebuilder->mark_dirty((int) $row['client_id'], (string) $row['billing_month']);
        }
    }

    /**
     * Get the current allocation summary for a client in a given month.
     *
     * @param int    $client_id
     * @param string $billing_month Format "YYYY-MM"
     * @return array|null The meals_client_allocations row, or null if no data.
     */
    public function get_client_month_summary(int $client_id, string $billing_month): ?array {
        $allocations_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$allocations_table} WHERE client_id = %d AND billing_month = %s",
            $client_id,
            $billing_month
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Get all delivery allocation detail rows for a client in a given month.
     *
     * @param int    $client_id
     * @param string $billing_month Format "YYYY-MM"
     * @return array Array of meals_delivery_allocations rows ordered by delivery_date ASC.
     */
    public function get_client_month_details(int $client_id, string $billing_month): array {
        $delivery_alloc_table = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$delivery_alloc_table}
             WHERE client_id = %d AND billing_month = %s
             ORDER BY delivery_date ASC",
            $client_id,
            $billing_month
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Get allocation history for a client across multiple months.
     *
     * @param int $client_id
     * @param int $months Number of months of history to return (default 12).
     * @return array Rows from meals_client_allocations ordered by billing_month DESC.
     */
    public function get_client_history(int $client_id, int $months = 12): array {
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE client_id = %d ORDER BY billing_month DESC LIMIT %d",
            $client_id,
            $months
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Lock a month from further changes (called at invoice generation time).
     *
     * @param int    $client_id
     * @param string $billing_month Format "YYYY-MM"
     * @return bool True if a row was updated, false otherwise.
     */
    public function finalize_month(int $client_id, string $billing_month): bool {
        $allocations_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);

        $updated = $this->wpdb->update(
            $allocations_table,
            ['is_finalized' => 1, 'finalized_at' => current_time('mysql')],
            ['client_id' => $client_id, 'billing_month' => $billing_month],
            ['%d', '%s'],
            ['%d', '%s']
        );

        return $updated !== false && $updated > 0;
    }

    /**
     * Reverse of finalize_month: clears the finalized lock on a client-month so
     * the rebuilder can recompute it again. Used ONLY by the audited un-finalize
     * flow (MealsDB_Invoice_Draft::unfinalize). Returns true if the row was
     * updated (or was already not finalized).
     */
    public function unfinalize_month(int $client_id, string $billing_month): bool {
        $allocations_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);

        $updated = $this->wpdb->update(
            $allocations_table,
            ['is_finalized' => 0, 'finalized_at' => null],
            ['client_id' => $client_id, 'billing_month' => $billing_month],
            ['%d', '%s'],
            ['%d', '%s']
        );

        return $updated !== false;
    }

    /**
     * Recalculate all active government clients for a billing month.
     *
     * @param string $billing_month Format "YYYY-MM"
     * @return int Count of clients processed.
     */
    public function bulk_recalculate_month(string $billing_month): int {
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // Walk the client set in bounded batches ordered by client_id so
        // a cron run with thousands of clients can't OOM the worker by
        // materialising every ID at once, and a timeout partway through
        // has a predictable restart point via the log.
        $batch_size = 500;
        $last_seen  = 0;
        $count      = 0;
        $started_at = microtime(true);

        while (true) {
            $clients = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT client_id FROM {$clients_table}
                 WHERE active = 1 AND client_type IN (%s, %s) AND client_id > %d
                 ORDER BY client_id ASC
                 LIMIT %d",
                'SDNB',
                'Veteran',
                $last_seen,
                $batch_size
            ), ARRAY_A);

            if (!is_array($clients) || empty($clients)) {
                break;
            }

            foreach ($clients as $client) {
                $client_id = (int) $client['client_id'];
                $this->recalculate_month_totals($client_id, $billing_month);
                $count++;
                $last_seen = $client_id;
            }

            if (count($clients) < $batch_size) {
                break;
            }
        }

        error_log(sprintf(
            '[MealsDB Allocation Engine] bulk_recalculate_month(%s): %d clients in %.2fs.',
            $billing_month,
            $count,
            microtime(true) - $started_at
        ));

        return $count;
    }
}
