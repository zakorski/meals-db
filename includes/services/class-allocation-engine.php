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

        // Calculate permitted based on requisition_period.
        $mains = 0;
        $sides = 0;

        switch ($requisition_period) {
            case 'month':
                $mains = ($allowance_mains == 31) ? $effective_days : $allowance_mains;
                $sides = ($allowance_sides == 31) ? $effective_days : $allowance_sides;
                break;

            case 'week':
                if ($allowance_mains == 7) {
                    $mains = $effective_days;
                } elseif ($allowance_mains == 14) {
                    $mains = 2 * $effective_days;
                } else {
                    $weeks = $effective_days / 7;
                    $mains = (int) round($allowance_mains * $weeks);
                }

                if ($allowance_sides == 7) {
                    $sides = $effective_days;
                } elseif ($allowance_sides == 14) {
                    $sides = 2 * $effective_days;
                } else {
                    $weeks = $effective_days / 7;
                    $sides = (int) round($allowance_sides * $weeks);
                }
                break;

            case 'day':
                $mains = $allowance_mains * $effective_days;
                $sides = $allowance_sides * $effective_days;
                break;

            default:
                $mains = 0;
                $sides = 0;
        }

        return [
            'permitted_mains' => $mains,
            'permitted_sides' => $sides,
            'effective_days'  => $effective_days,
        ];
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
            $periods_to_skip = max(0, (int) floor($diff_days / $delivery_frequency) - 1);
            if ($periods_to_skip > 0) {
                $cursor->modify('+' . ($periods_to_skip * $delivery_frequency) . ' days');
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
            $coverage_end->modify('+' . ($delivery_frequency - 1) . ' days');

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

            $cursor->modify('+' . $delivery_frequency . ' days');
        }

        return $results;
    }

    /**
     * Allocate a WooCommerce order's items across billing months based on delivery schedule.
     *
     * @param int $wc_order_id
     */
    public function allocate_order(int $wc_order_id): void {
        $meta_table   = $this->wpdb->prefix . 'wc_orders_meta';
        $orders_table = $this->wpdb->prefix . 'wc_orders';

        // Resolve client_id from order meta.
        $client_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_value FROM {$meta_table} WHERE order_id = %d AND meta_key = 'mealsdb_client_id' LIMIT 1",
            $wc_order_id
        ));

        if (!$client_id) {
            // Try resolving via mealsdb_client_user_id.
            $wp_user_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT meta_value FROM {$meta_table} WHERE order_id = %d AND meta_key = 'mealsdb_client_user_id' LIMIT 1",
                $wc_order_id
            ));

            if ($wp_user_id) {
                $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
                $client_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT client_id FROM {$clients_table} WHERE wp_user_id = %d LIMIT 1",
                    $wp_user_id
                ));
            }
        }

        if (!$client_id) {
            // Fallback: resolve via WC order's native customer_id field.
            $customer_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT customer_id FROM {$orders_table} WHERE id = %d",
                $wc_order_id
            ));

            if ($customer_id > 0) {
                $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
                $client_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT client_id FROM {$clients_table} WHERE wp_user_id = %d AND active = 1 LIMIT 1",
                    $customer_id
                ));
            }
        }

        if (!$client_id) {
            return;
        }

        // Get order date.
        $order_date_str = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT DATE(date_created_gmt) FROM {$orders_table} WHERE id = %d",
            $wc_order_id
        ));

        if (!$order_date_str) {
            return;
        }

        // Get order items via MealsDB_WC_Order_Query.
        $order_items = $this->order_query->get_order_items([$wc_order_id]);
        if (empty($order_items)) {
            return;
        }

        // Count items by type.
        $products_table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $mains         = 0;
        $sides         = 0;
        $tax_sides     = 0;
        $nontax_sides  = 0;

        foreach ($order_items as $item) {
            $product_id = (int) $item['wc_product_id'];
            $qty        = (int) $item['quantity'];

            $product_data = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT product_type, taxable FROM {$products_table} WHERE wc_product_id = %d",
                $product_id
            ), ARRAY_A);

            if (!$product_data) {
                continue;
            }

            if ($product_data['product_type'] === 'meal') {
                $mains += $qty;
            } elseif ($product_data['product_type'] === 'side') {
                $sides += $qty;
                if ((int) $product_data['taxable'] === 1) {
                    $tax_sides += $qty;
                } else {
                    $nontax_sides += $qty;
                }
            }
        }

        if ($mains === 0 && $sides === 0) {
            return;
        }

        // Determine delivery date from schedule.
        $order_date    = new DateTime($order_date_str);
        $order_month   = $order_date->format('Y-m');
        $schedule      = $this->calculate_delivery_schedule($client_id, $order_month);
        $delivery_date = $order_date_str;
        $coverage_start = $order_date_str;
        $coverage_end   = $order_date_str;

        // Find matching delivery from schedule.
        foreach ($schedule as $delivery) {
            if ($delivery['delivery_date'] === $order_date_str) {
                $delivery_date  = $delivery['delivery_date'];
                $coverage_start = $delivery['coverage_start'];
                $coverage_end   = $delivery['coverage_end'];
                break;
            }
        }

        // If no exact match, use order date as delivery date and compute coverage.
        if ($delivery_date === $order_date_str && !empty($schedule)) {
            // Find the closest delivery that covers the order date.
            foreach ($schedule as $delivery) {
                if ($order_date_str >= $delivery['coverage_start'] && $order_date_str <= $delivery['coverage_end']) {
                    $delivery_date  = $delivery['delivery_date'];
                    $coverage_start = $delivery['coverage_start'];
                    $coverage_end   = $delivery['coverage_end'];
                    break;
                }
            }
        }

        // Check if coverage spans two calendar months.
        $cov_start_dt = new DateTime($coverage_start);
        $cov_end_dt   = new DateTime($coverage_end);
        $month1       = $cov_start_dt->format('Y-m');
        $month2       = $cov_end_dt->format('Y-m');

        $delivery_alloc_table = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);

        // Wrap the destructive delete + the new insert(s) in a single
        // transaction so a concurrent deallocate (status-change hook,
        // cron) can't race between the rows being cleared and the new
        // rows being written and leave the DB in a half-written state.
        $started = $this->wpdb->query('START TRANSACTION');
        $transaction_started = $started !== false;

        $affected_months = [];
        $insert_failed   = false;

        try {
            // Delete existing allocations for this order (for re-processing).
            $deleted = $this->wpdb->delete($delivery_alloc_table, ['wc_order_id' => $wc_order_id], ['%d']);
            if ($deleted === false) {
                $insert_failed = true;
            }

            if (!$insert_failed && $month1 === $month2) {
                // Single month — insert one row.
                $rows = $this->wpdb->insert($delivery_alloc_table, [
                    'client_id'        => $client_id,
                    'wc_order_id'      => $wc_order_id,
                    'order_date'       => $order_date_str,
                    'delivery_date'    => $delivery_date,
                    'coverage_start'   => $coverage_start,
                    'coverage_end'     => $coverage_end,
                    'billing_month'    => $month1,
                    'mains_count'      => $mains,
                    'sides_count'      => $sides,
                    'tax_sides_count'  => $tax_sides,
                    'nontax_sides_count' => $nontax_sides,
                ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d']);
                if ($rows === false) {
                    $insert_failed = true;
                } else {
                    $affected_months[] = $month1;
                }
            } elseif (!$insert_failed) {
                // Coverage spans two months — split proportionally.
                $end_of_month1    = new DateTime($cov_start_dt->format('Y-m-t'));
                $start_of_month2  = new DateTime($cov_end_dt->format('Y-m-01'));

                $days_in_month_1     = (int) $cov_start_dt->diff($end_of_month1)->days + 1;
                $days_in_month_2     = (int) $start_of_month2->diff($cov_end_dt)->days + 1;
                $total_coverage_days = $days_in_month_1 + $days_in_month_2;

                $m1_mains        = (int) round($mains * $days_in_month_1 / $total_coverage_days);
                $m2_mains        = $mains - $m1_mains;
                $m1_sides        = (int) round($sides * $days_in_month_1 / $total_coverage_days);
                $m2_sides        = $sides - $m1_sides;
                $m1_tax_sides    = (int) round($tax_sides * $days_in_month_1 / $total_coverage_days);
                $m2_tax_sides    = $tax_sides - $m1_tax_sides;
                $m1_nontax_sides = (int) round($nontax_sides * $days_in_month_1 / $total_coverage_days);
                $m2_nontax_sides = $nontax_sides - $m1_nontax_sides;

                // Month 1 row.
                $rows1 = $this->wpdb->insert($delivery_alloc_table, [
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
                ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d']);

                // Month 2 row.
                $rows2 = false;
                if ($rows1 !== false) {
                    $rows2 = $this->wpdb->insert($delivery_alloc_table, [
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
                    ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d']);
                }

                if ($rows1 === false || $rows2 === false) {
                    $insert_failed = true;
                } else {
                    $affected_months[] = $month1;
                    $affected_months[] = $month2;
                }
            }

            if ($transaction_started) {
                if ($insert_failed) {
                    $this->wpdb->query('ROLLBACK');
                } else {
                    $this->wpdb->query('COMMIT');
                }
            }
        } catch (\Throwable $e) {
            if ($transaction_started) {
                $this->wpdb->query('ROLLBACK');
            }
            throw $e;
        }

        if ($insert_failed) {
            error_log('[MealsDB Allocation Engine] allocate_order rolled back for wc_order_id=' . $wc_order_id . ': ' . $this->wpdb->last_error);
            return;
        }

        // Recalculate affected months (outside the transaction so the
        // recompute reads committed state and doesn't deadlock against
        // the INSERTs we just did).
        foreach ($affected_months as $bm) {
            $this->recalculate_month_totals($client_id, $bm);
        }
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
     * Remove delivery allocations for a cancelled/trashed/deleted order and recalculate.
     *
     * @param int $wc_order_id
     */
    public function deallocate_order(int $wc_order_id): void {
        $delivery_alloc_table = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);

        // Fetch affected client + billing_month pairs before deleting.
        $affected = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT DISTINCT client_id, billing_month FROM {$delivery_alloc_table} WHERE wc_order_id = %d",
            $wc_order_id
        ), ARRAY_A);

        // Delete all rows for this order.
        $this->wpdb->delete($delivery_alloc_table, ['wc_order_id' => $wc_order_id], ['%d']);

        // Check if this order carried a client contribution and clear it.
        $alloc_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$alloc_table} SET contribution_applied = 0, contribution_order_id = NULL
             WHERE contribution_order_id = %d",
            $wc_order_id
        ));

        // Recalculate affected months.
        if (is_array($affected)) {
            foreach ($affected as $row) {
                $this->recalculate_month_totals((int) $row['client_id'], $row['billing_month']);
            }
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

        $clients = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT client_id FROM {$clients_table}
             WHERE active = 1 AND client_type IN (%s, %s)",
            'SDNB',
            'Veteran'
        ), ARRAY_A);

        if (!is_array($clients)) {
            return 0;
        }

        $count = 0;
        foreach ($clients as $client) {
            $this->recalculate_month_totals((int) $client['client_id'], $billing_month);
            $count++;
        }

        return $count;
    }
}
