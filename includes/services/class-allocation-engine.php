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
     * The previous synchronous per-order, date-prorated write path lived
     * here; private helpers below (build_desired_allocation_rows,
     * lock_allocation_month) are remnants of that path and are now unused.
     * They are left in place to keep the public surface stable; a later
     * cleanup phase can remove them.
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
                $client_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT client_id FROM {$clients_table} WHERE wp_user_id = %d LIMIT 1",
                    $wp_user_id
                ));
            }
        }

        if (!$client_id) {
            $customer_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT customer_id FROM {$orders_table} WHERE id = %d",
                $wc_order_id
            ));
            if ($customer_id > 0) {
                $client_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT client_id FROM {$clients_table} WHERE wp_user_id = %d AND active = 1 LIMIT 1",
                    $customer_id
                ));
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
     * Build the desired delivery_allocations rows for an order.
     *
     * Factored out so allocate_order can fingerprint the intended state
     * without duplicating the single/double-month branch — the same
     * array produced here is compared against the existing rows for the
     * idempotency check and, if the fingerprints differ, fed into the
     * INSERT loop.
     *
     * @return array<int, array<string, mixed>> One row for a single-month
     *         delivery, two rows for a month-straddling delivery (pro-rated).
     */
    private function build_desired_allocation_rows(
        int $client_id,
        int $wc_order_id,
        string $order_date_str,
        string $delivery_date,
        string $coverage_start,
        string $coverage_end,
        string $month1,
        string $month2,
        int $mains,
        int $sides,
        int $tax_sides,
        int $nontax_sides,
        DateTime $cov_start_dt,
        DateTime $cov_end_dt
    ): array {
        if ($month1 === $month2) {
            return [
                [
                    'client_id'          => $client_id,
                    'wc_order_id'        => $wc_order_id,
                    'order_date'         => $order_date_str,
                    'delivery_date'      => $delivery_date,
                    'coverage_start'     => $coverage_start,
                    'coverage_end'       => $coverage_end,
                    'billing_month'      => $month1,
                    'mains_count'        => $mains,
                    'sides_count'        => $sides,
                    'tax_sides_count'    => $tax_sides,
                    'nontax_sides_count' => $nontax_sides,
                ],
            ];
        }

        $end_of_month1       = new DateTime($cov_start_dt->format('Y-m-t'));
        $start_of_month2     = new DateTime($cov_end_dt->format('Y-m-01'));
        $days_in_month_1     = (int) $cov_start_dt->diff($end_of_month1)->days + 1;
        $days_in_month_2     = (int) $start_of_month2->diff($cov_end_dt)->days + 1;
        $total_coverage_days = $days_in_month_1 + $days_in_month_2;

        if ($total_coverage_days <= 0) {
            return [];
        }

        // Proportional split with residual conservation.
        //
        // One side gets round(total * days_in_month_1 / total_days);
        // the other side gets (total - that_rounded), NOT a second
        // round() call. That guarantees the two month values always
        // sum back to the original, so a month-straddling order never
        // inflates or deflates the client's overall consumption by
        // a rounding penny.
        //
        // This is the allocation equivalent of the M1/M2 money-cents
        // pattern: accumulate in one direction, absorb the residual
        // at the boundary.
        $m1_mains        = (int) round($mains * $days_in_month_1 / $total_coverage_days);
        $m2_mains        = $mains - $m1_mains;
        $m1_sides        = (int) round($sides * $days_in_month_1 / $total_coverage_days);
        $m2_sides        = $sides - $m1_sides;
        $m1_tax_sides    = (int) round($tax_sides * $days_in_month_1 / $total_coverage_days);
        $m2_tax_sides    = $tax_sides - $m1_tax_sides;
        $m1_nontax_sides = (int) round($nontax_sides * $days_in_month_1 / $total_coverage_days);
        $m2_nontax_sides = $nontax_sides - $m1_nontax_sides;

        return [
            [
                'client_id'          => $client_id,
                'wc_order_id'        => $wc_order_id,
                'order_date'         => $order_date_str,
                'delivery_date'      => $delivery_date,
                'coverage_start'     => $coverage_start,
                'coverage_end'       => $end_of_month1->format('Y-m-d'),
                'billing_month'      => $month1,
                'mains_count'        => $m1_mains,
                'sides_count'        => $m1_sides,
                'tax_sides_count'    => $m1_tax_sides,
                'nontax_sides_count' => $m1_nontax_sides,
            ],
            [
                'client_id'          => $client_id,
                'wc_order_id'        => $wc_order_id,
                'order_date'         => $order_date_str,
                'delivery_date'      => $delivery_date,
                'coverage_start'     => $start_of_month2->format('Y-m-d'),
                'coverage_end'       => $coverage_end,
                'billing_month'      => $month2,
                'mains_count'        => $m2_mains,
                'sides_count'        => $m2_sides,
                'tax_sides_count'    => $m2_tax_sides,
                'nontax_sides_count' => $m2_nontax_sides,
            ],
        ];
    }

    /**
     * Compute a stable fingerprint for a set of delivery_allocations
     * rows. Used for the idempotency check in allocate_order so a
     * webhook retry that would land on byte-identical state can skip
     * the DELETE+INSERT+recalculate cycle.
     *
     * The returned hash is order-independent (rows are sorted by
     * billing_month + coverage_start before hashing) and type-
     * independent (wpdb returns numeric columns as strings while the
     * desired rows hold PHP ints, so we normalise before comparison).
     * Returns '' for an empty input — which the caller treats as
     * "no fingerprint match" so an empty existing set never falsely
     * matches a non-empty desired set.
     */
    private static function fingerprint_allocation_rows(array $rows): string {
        if (empty($rows)) {
            return '';
        }

        $normalised = array_map(static function ($row) {
            return [
                (int)    ($row['client_id']          ?? 0),
                (int)    ($row['wc_order_id']        ?? 0),
                (string) ($row['order_date']         ?? ''),
                (string) ($row['delivery_date']      ?? ''),
                (string) ($row['coverage_start']     ?? ''),
                (string) ($row['coverage_end']       ?? ''),
                (string) ($row['billing_month']      ?? ''),
                (int)    ($row['mains_count']        ?? 0),
                (int)    ($row['sides_count']        ?? 0),
                (int)    ($row['tax_sides_count']    ?? 0),
                (int)    ($row['nontax_sides_count'] ?? 0),
            ];
        }, $rows);

        usort($normalised, static function ($a, $b) {
            // Sort by billing_month (index 6) then coverage_start (index 4).
            return [$a[6], $a[4]] <=> [$b[6], $b[4]];
        });

        return hash('sha256', serialize($normalised));
    }

    /**
     * Acquire an exclusive row lock on the summary row for a
     * (client_id, billing_month) pair so concurrent allocate/deallocate
     * calls for the same pair serialise. Creates a zeroed stub row if
     * one does not already exist — recalculate_month_totals() will
     * overwrite those zeros with the correct values before the
     * transaction commits, so peer transactions never see the stub.
     *
     * MUST be called inside an open transaction. The returned lock is
     * released on COMMIT / ROLLBACK.
     */
    private function lock_allocation_month(int $client_id, string $billing_month): bool {
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);

        // INSERT IGNORE creates the lock target when missing and is a
        // no-op when the unique (client_id, billing_month) index hits.
        // All other columns in client_allocations have DEFAULT 0 or
        // nullable, so supplying just the two key columns is sufficient.
        $insert_result = $this->wpdb->query($this->wpdb->prepare(
            "INSERT IGNORE INTO {$table} (client_id, billing_month) VALUES (%d, %s)",
            $client_id,
            $billing_month
        ));
        if ($insert_result === false) {
            error_log(sprintf(
                '[MealsDB Allocation Engine] lock_allocation_month failed to ensure row for client %d month %s: %s',
                $client_id,
                $billing_month,
                $this->wpdb->last_error
            ));
            return false;
        }

        $locked = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$table} WHERE client_id = %d AND billing_month = %s FOR UPDATE",
            $client_id,
            $billing_month
        ));
        if ($locked === null) {
            error_log(sprintf(
                '[MealsDB Allocation Engine] lock_allocation_month SELECT FOR UPDATE returned null for client %d month %s: %s',
                $client_id,
                $billing_month,
                $this->wpdb->last_error
            ));
            return false;
        }

        return true;
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
