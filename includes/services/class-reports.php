<?php
/**
 * Reporting service for Meals DB.
 */

class MealsDB_Reports {
    /**
     * @var wpdb|null
     */
    private $wpdb;

    /**
     * @var MealsDB_WC_Order_Query|null
     */
    private $order_query;

    /**
     * @param wpdb|null                  $wpdb
     * @param MealsDB_WC_Order_Query|null $order_query
     */
    public function __construct($wpdb = null, $order_query = null) {
        if ($wpdb instanceof wpdb) {
            $this->wpdb = $wpdb;
        } elseif (isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof wpdb) {
            $this->wpdb = $GLOBALS['wpdb'];
        } else {
            $this->wpdb = null;
        }

        if ($order_query instanceof MealsDB_WC_Order_Query) {
            $this->order_query = $order_query;
        } elseif ($this->wpdb instanceof wpdb) {
            $this->order_query = new MealsDB_WC_Order_Query($this->wpdb);
        } else {
            $this->order_query = null;
        }
    }

    /**
     * Calculate resupply requirements for order items within the given date range.
     *
     * @param string|int $start_date
     * @param string|int $end_date
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_resupply_requirements($start_date, $end_date): array {
        if (!$this->wpdb instanceof wpdb) {
            return [];
        }

        $dates = $this->normalise_dates($start_date, $end_date);
        if ($dates === null) {
            return [];
        }

        $order_items_table      = $this->wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta_table   = $this->wpdb->prefix . 'woocommerce_order_itemmeta';
        $orders_table           = $this->wpdb->prefix . 'wc_orders';
        $meals_products_table   = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS));

        $sql = "
            SELECT
                oi.order_item_name AS item,
                CAST(product_meta.meta_value AS UNSIGNED) AS wc_product_id,
                SUM(CAST(qty_meta.meta_value AS DECIMAL(20,6))) AS total_quantity,
                COALESCE(mp.case_size, 1) AS case_size,
                CEIL(SUM(CAST(qty_meta.meta_value AS DECIMAL(20,6))) / NULLIF(mp.case_size, 0)) AS cases_needed,
                COALESCE(mp.unit_cost, 0) AS unit_cost,
                CEIL(SUM(CAST(qty_meta.meta_value AS DECIMAL(20,6))) / NULLIF(mp.case_size, 0)) * COALESCE(mp.unit_cost, 0) AS total_cost
            FROM {$order_items_table} oi
            INNER JOIN {$order_itemmeta_table} product_meta
                ON product_meta.order_item_id = oi.order_item_id
                AND product_meta.meta_key = '_product_id'
            INNER JOIN {$order_itemmeta_table} qty_meta
                ON qty_meta.order_item_id = oi.order_item_id
                AND qty_meta.meta_key = '_qty'
            INNER JOIN {$orders_table} o
                ON o.id = oi.order_id
            LEFT JOIN `{$meals_products_table}` mp
                ON mp.wc_product_id = CAST(product_meta.meta_value AS UNSIGNED)
            WHERE o.date_created_gmt >= %s
                AND o.date_created_gmt <= %s
                AND o.status NOT IN ('wc-cancelled', 'wc-on-hold', 'wc-draft', 'draft', 'wc-trash', 'trash')
                AND o.type = 'shop_order'
                AND (mp.product_type IS NULL OR mp.product_type IN ('meal', 'side'))
            GROUP BY wc_product_id, item, mp.case_size, mp.unit_cost
            ORDER BY item ASC
        ";

        $prepared = $this->wpdb->prepare($sql, $dates['start'], $dates['end']);
        $rows     = $this->wpdb->get_results($prepared, ARRAY_A);

        return array_map([$this, 'format_resupply_row'], is_array($rows) ? $rows : []);
    }

    /**
     * Generate a breakdown of meals and sides by metadata within the given date range.
     *
     * @param string|int $start_date
     * @param string|int $end_date
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_meal_breakdown($start_date, $end_date): array {
        if (!$this->wpdb instanceof wpdb) {
            return [];
        }

        $dates = $this->normalise_dates($start_date, $end_date);
        if ($dates === null) {
            return [];
        }

        $order_items_table    = $this->wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta_table = $this->wpdb->prefix . 'woocommerce_order_itemmeta';
        $orders_table         = $this->wpdb->prefix . 'wc_orders';
        $meals_products_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS));

        $sql = "
            SELECT
                CAST(product_meta.meta_value AS UNSIGNED) AS wc_product_id,
                oi.order_item_name AS item,
                SUM(CAST(qty_meta.meta_value AS DECIMAL(20,6))) AS total_quantity,
                COALESCE(mp.product_type, 'meal') AS product_type,
                COALESCE(mp.main_ingredient, '') AS main_ingredient,
                mp.dietary_tags,
                mp.allergen_flags
            FROM {$order_items_table} oi
            INNER JOIN {$order_itemmeta_table} product_meta
                ON product_meta.order_item_id = oi.order_item_id
                AND product_meta.meta_key = '_product_id'
            INNER JOIN {$order_itemmeta_table} qty_meta
                ON qty_meta.order_item_id = oi.order_item_id
                AND qty_meta.meta_key = '_qty'
            INNER JOIN {$orders_table} o
                ON o.id = oi.order_id
            LEFT JOIN `{$meals_products_table}` mp
                ON mp.wc_product_id = CAST(product_meta.meta_value AS UNSIGNED)
            WHERE o.date_created_gmt >= %s
                AND o.date_created_gmt <= %s
                AND o.status NOT IN ('wc-cancelled', 'wc-on-hold', 'wc-draft', 'draft', 'wc-trash', 'trash')
                AND o.type = 'shop_order'
                AND (mp.product_type IS NULL OR mp.product_type IN ('meal', 'side'))
            GROUP BY wc_product_id, item, mp.product_type, mp.main_ingredient, mp.dietary_tags, mp.allergen_flags
            ORDER BY item ASC
        ";

        $prepared = $this->wpdb->prepare($sql, $dates['start'], $dates['end']);
        $rows     = $this->wpdb->get_results($prepared, ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_map([$this, 'format_meal_breakdown_row'], $rows);
    }

    /**
     * Export the provided rows to a CSV string.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return string
     */
    public function export_to_csv(array $rows): string {
        if (empty($rows)) {
            return '';
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        $headers = array_keys(reset($rows));
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $value = isset($row[$header]) ? $row[$header] : '';
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $line[] = $value;
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function format_resupply_row(array $row): array {
        $total_quantity = isset($row['total_quantity']) ? (float) $row['total_quantity'] : 0.0;
        $case_size      = isset($row['case_size']) ? (int) $row['case_size'] : 1;

        if ($case_size <= 0) {
            $case_size = 1;
        }

        $cases_needed = (int) ceil($total_quantity / $case_size);
        $unit_cost    = isset($row['unit_cost']) ? (float) $row['unit_cost'] : 0.0;
        $total_cost   = $cases_needed * $unit_cost;

        return [
            'item'            => isset($row['item']) ? (string) $row['item'] : '',
            'wc_product_id'   => isset($row['wc_product_id']) ? (int) $row['wc_product_id'] : 0,
            'total_quantity'  => $total_quantity,
            'case_size'       => $case_size,
            'cases_needed'    => $cases_needed,
            'unit_cost'       => number_format($unit_cost, 2, '.', ''),
            'total_cost'      => number_format($total_cost, 2, '.', ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function format_meal_breakdown_row(array $row): array {
        $product_type = 'meal';
        if (isset($row['product_type']) && in_array($row['product_type'], ['meal', 'side'], true)) {
            $product_type = $row['product_type'];
        }

        return [
            'wc_product_id'   => isset($row['wc_product_id']) ? (int) $row['wc_product_id'] : 0,
            'item'            => isset($row['item']) ? (string) $row['item'] : '',
            'total_quantity'  => isset($row['total_quantity']) ? (float) $row['total_quantity'] : 0.0,
            'product_type'    => $product_type,
            'main_ingredient' => isset($row['main_ingredient']) ? (string) $row['main_ingredient'] : '',
            'dietary_tags'    => $this->decode_json_array(isset($row['dietary_tags']) ? $row['dietary_tags'] : null),
            'allergen_flags'  => $this->decode_json_array(isset($row['allergen_flags']) ? $row['allergen_flags'] : null),
        ];
    }

    /**
     * Calculate average weekly demand per product over a trailing period.
     *
     * @param int $trailing_weeks Number of weeks to look back (default 8).
     *
     * @return array<int, array<string, mixed>> Keyed by wc_product_id.
     */
    public function get_demand_history(int $trailing_weeks = 8): array {
        if (!$this->wpdb instanceof wpdb) {
            return [];
        }

        if ($trailing_weeks < 1) {
            $trailing_weeks = 8;
        }

        $end_date           = gmdate('Y-m-d');
        $end_date_exclusive = gmdate('Y-m-d', strtotime($end_date . ' +1 day'));
        $start_date         = gmdate('Y-m-d', strtotime("-" . ($trailing_weeks * 7) . " days"));

        $order_items_table    = $this->wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta_table = $this->wpdb->prefix . 'woocommerce_order_itemmeta';
        $orders_table         = $this->wpdb->prefix . 'wc_orders';

        $sql = "
            SELECT
                CAST(product_meta.meta_value AS UNSIGNED) AS wc_product_id,
                oi.order_item_name AS product_name,
                YEARWEEK(o.date_created_gmt, 1) AS year_week,
                SUM(CAST(qty_meta.meta_value AS DECIMAL(10,2))) AS weekly_quantity
            FROM {$order_items_table} oi
            INNER JOIN {$order_itemmeta_table} product_meta
                ON product_meta.order_item_id = oi.order_item_id
                AND product_meta.meta_key = '_product_id'
            INNER JOIN {$order_itemmeta_table} qty_meta
                ON qty_meta.order_item_id = oi.order_item_id
                AND qty_meta.meta_key = '_qty'
            INNER JOIN {$orders_table} o
                ON o.id = oi.order_id
                AND o.type = 'shop_order'
                AND o.status NOT IN ('wc-cancelled', 'wc-on-hold', 'wc-draft', 'draft', 'wc-trash', 'trash')
            WHERE o.date_created_gmt >= %s AND o.date_created_gmt < %s
                AND oi.order_item_type = 'line_item'
            GROUP BY wc_product_id, product_name, year_week
            ORDER BY wc_product_id, year_week
        ";

        $prepared = $this->wpdb->prepare($sql, $start_date, $end_date_exclusive);
        $rows     = $this->wpdb->get_results($prepared, ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        // Aggregate per product.
        $products = [];
        foreach ($rows as $row) {
            $pid = (int) $row['wc_product_id'];
            if (!isset($products[$pid])) {
                $products[$pid] = [
                    'wc_product_id'     => $pid,
                    'product_name'      => (string) $row['product_name'],
                    'avg_weekly_demand' => 0.0,
                    'weekly_history'    => [],
                    'total_trailing'    => 0.0,
                ];
            }
            $qty = (float) $row['weekly_quantity'];
            $products[$pid]['weekly_history'][$row['year_week']] = $qty;
            $products[$pid]['total_trailing'] += $qty;
        }

        // Calculate averages using trailing_weeks as denominator.
        foreach ($products as &$p) {
            $p['avg_weekly_demand'] = round($p['total_trailing'] / $trailing_weeks, 2);
        }
        unset($p);

        return $products;
    }

    /**
     * Generate a seasonally-adjusted purchase order projection.
     *
     * Three-layer algorithm:
     * 1. Exponentially weighted recent demand (baseline)
     * 2. Seasonal index from year-over-year comparison
     * 3. Inventory subtraction (stock + future)
     *
     * @param int   $trailing_weeks       Weeks of recent history for baseline (default 12).
     * @param int   $order_horizon_weeks  Weeks of stock to order for (default 6).
     * @param float $decay_factor         Recency weight decay, 0-1 (default 0.85).
     * @return array
     */
    public function generate_purchase_order(
        int $trailing_weeks = 12,
        int $order_horizon_weeks = 6,
        float $decay_factor = 0.85
    ): array {
        if (!$this->wpdb instanceof wpdb) {
            return [];
        }

        if ($trailing_weeks < 1) {
            $trailing_weeks = 12;
        }
        if ($order_horizon_weeks < 1) {
            $order_horizon_weeks = 6;
        }
        $decay_factor = max(0.01, min(1.0, $decay_factor));

        $seasonal_min       = 0.3;
        $seasonal_max       = 3.0;
        $min_weeks_required = 2;

        $orders_table   = $this->wpdb->prefix . 'wc_orders';
        $items_table    = $this->wpdb->prefix . 'woocommerce_order_items';
        $itemmeta_table = $this->wpdb->prefix . 'woocommerce_order_itemmeta';
        $status_filter  = "('wc-cancelled','wc-on-hold','wc-draft','draft','wc-trash','trash')";

        $today = gmdate('Y-m-d');

        // --- Query A: Recent trailing period (weekly demand). ---
        $recent_start = gmdate('Y-m-d', strtotime("-" . ($trailing_weeks * 7) . " days"));
        $recent_end   = gmdate('Y-m-d', strtotime('+1 day'));

        $sql_recent = "
            SELECT
                CAST(pm.meta_value AS UNSIGNED) AS wc_product_id,
                oi.order_item_name AS product_name,
                YEARWEEK(o.date_created_gmt, 1) AS year_week,
                SUM(CAST(qm.meta_value AS DECIMAL(10,2))) AS weekly_qty
            FROM {$items_table} oi
            INNER JOIN {$itemmeta_table} pm
                ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'
            INNER JOIN {$itemmeta_table} qm
                ON qm.order_item_id = oi.order_item_id AND qm.meta_key = '_qty'
            INNER JOIN {$orders_table} o
                ON o.id = oi.order_id
                AND o.type = 'shop_order'
                AND o.status NOT IN {$status_filter}
            WHERE o.date_created_gmt >= %s AND o.date_created_gmt < %s
                AND oi.order_item_type = 'line_item'
            GROUP BY wc_product_id, product_name, year_week
            ORDER BY wc_product_id, year_week
        ";

        $recent_rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql_recent, $recent_start, $recent_end),
            ARRAY_A
        );

        if (!is_array($recent_rows) || empty($recent_rows)) {
            return [];
        }

        // Build per-product weekly history.
        $products = [];
        foreach ($recent_rows as $row) {
            $pid = (int) $row['wc_product_id'];
            if (!isset($products[$pid])) {
                $products[$pid] = [
                    'product_name'   => (string) $row['product_name'],
                    'weekly_history' => [],
                ];
            }
            $products[$pid]['weekly_history'][$row['year_week']] = (float) $row['weekly_qty'];
        }

        // --- Query B: Prior-year data for seasonal index. ---
        // Target weeks: the calendar weeks corresponding to the upcoming order horizon.
        $horizon_start = gmdate('Y-m-d', strtotime('+7 days'));
        $horizon_end   = gmdate('Y-m-d', strtotime('+' . ($order_horizon_weeks * 7) . ' days'));

        // Same calendar weeks last year.
        $ly_target_start = gmdate('Y-m-d', strtotime($horizon_start . ' -1 year'));
        $ly_target_end   = gmdate('Y-m-d', strtotime($horizon_end . ' -1 year'));

        // Preceding weeks last year (trailing_weeks before target).
        $ly_preceding_start = gmdate('Y-m-d', strtotime($ly_target_start . " -{$trailing_weeks} weeks"));
        $ly_preceding_end   = $ly_target_start;

        $sql_ly = "
            SELECT
                CAST(pm.meta_value AS UNSIGNED) AS wc_product_id,
                YEARWEEK(o.date_created_gmt, 1) AS year_week,
                SUM(CAST(qm.meta_value AS DECIMAL(10,2))) AS weekly_qty
            FROM {$items_table} oi
            INNER JOIN {$itemmeta_table} pm
                ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'
            INNER JOIN {$itemmeta_table} qm
                ON qm.order_item_id = oi.order_item_id AND qm.meta_key = '_qty'
            INNER JOIN {$orders_table} o
                ON o.id = oi.order_id
                AND o.type = 'shop_order'
                AND o.status NOT IN {$status_filter}
            WHERE o.date_created_gmt >= %s AND o.date_created_gmt < %s
                AND oi.order_item_type = 'line_item'
            GROUP BY wc_product_id, year_week
        ";

        $ly_rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql_ly, $ly_preceding_start, $ly_target_end),
            ARRAY_A
        );

        // Build prior-year lookup: pid => [year_week => qty].
        $ly_data = [];
        if (is_array($ly_rows)) {
            foreach ($ly_rows as $row) {
                $pid = (int) $row['wc_product_id'];
                $ly_data[$pid][$row['year_week']] = (float) $row['weekly_qty'];
            }
        }

        // Compute target and preceding year-week lists.
        $ly_target_weeks    = $this->get_year_weeks_for_range($ly_target_start, $ly_target_end);
        $ly_preceding_weeks = $this->get_year_weeks_for_range($ly_preceding_start, $ly_preceding_end);

        // --- Category exclusion. ---
        $excluded_cats = get_option('mealsdb_appetito_excluded_categories', [98, 104, 103, 102, 101, 88, 109]);
        if (!empty($excluded_cats) && function_exists('has_term')) {
            foreach ($products as $pid => $p) {
                if (has_term($excluded_cats, 'product_cat', $pid)) {
                    unset($products[$pid]);
                }
            }
        }

        if (empty($products)) {
            return [];
        }

        // --- Get product metadata for case_size. ---
        $product_ids  = array_keys($products);
        $product_meta = [];
        if ($this->order_query instanceof MealsDB_WC_Order_Query) {
            $product_meta = $this->order_query->get_product_types_for_ids($product_ids);
        }

        // --- Build purchase order rows. ---
        $po_rows = [];
        foreach ($products as $pid => $p) {
            // Layer 1: Weighted recent demand.
            $weekly = $p['weekly_history'];
            krsort($weekly); // Most recent first.
            $weighted_sum = 0.0;
            $weight_sum   = 0.0;
            $week_index   = 0;

            foreach ($weekly as $yw => $qty) {
                $weight = pow($decay_factor, $week_index);
                $weighted_sum += $qty * $weight;
                $weight_sum   += $weight;
                $week_index++;
            }
            // Include zero-sale weeks in denominator.
            for ($i = $week_index; $i < $trailing_weeks; $i++) {
                $weight_sum += pow($decay_factor, $i);
            }
            $weighted_avg = $weight_sum > 0 ? round($weighted_sum / $weight_sum, 2) : 0;

            // Layer 2: Seasonal index.
            $ly_target_total    = 0;
            $ly_target_count    = 0;
            $ly_preceding_total = 0;
            $ly_preceding_count = 0;

            foreach ($ly_target_weeks as $yw) {
                $ly_target_total += $ly_data[$pid][$yw] ?? 0;
                $ly_target_count++;
            }
            foreach ($ly_preceding_weeks as $yw) {
                $ly_preceding_total += $ly_data[$pid][$yw] ?? 0;
                $ly_preceding_count++;
            }

            $ly_target_avg    = $ly_target_count > 0 ? $ly_target_total / $ly_target_count : 0;
            $ly_preceding_avg = $ly_preceding_count > 0 ? $ly_preceding_total / $ly_preceding_count : 0;

            if ($ly_target_count >= $min_weeks_required
                && $ly_preceding_count >= $min_weeks_required
                && $ly_preceding_avg > 0) {
                $raw_index      = $ly_target_avg / $ly_preceding_avg;
                $seasonal_index = max($seasonal_min, min($seasonal_max, $raw_index));
            } else {
                $seasonal_index = 1.0;
            }

            // Layer 3: Inventory subtraction.
            $adjusted_weekly = round($weighted_avg * $seasonal_index, 2);
            $projected_need  = (int) ceil($adjusted_weekly * $order_horizon_weeks);

            $buffer = (int) get_post_meta($pid, 'buffer', true);
            $meta   = isset($product_meta[$pid]) ? $product_meta[$pid] : [];
            $case_size = isset($meta['case_size']) && (int) $meta['case_size'] > 0
                ? (int) $meta['case_size']
                : (int) get_post_meta($pid, 'case_size', true) ?: 1;

            $wc_product    = wc_get_product($pid);
            $sku           = $wc_product ? $wc_product->get_sku() : '';
            $current_stock = $wc_product ? max(0, (int) $wc_product->get_stock_quantity()) : 0;
            $future_inv    = max(0, (int) get_post_meta($pid, '_future_inventory_quantity', true));
            $total_available = $current_stock + $future_inv;

            $qty_needed   = $projected_need + $buffer;
            $units_needed = max(0, $qty_needed - $total_available);
            $cases_to_buy = $units_needed > 0 ? (int) ceil($units_needed / $case_size) : 0;
            $order_quantity = $cases_to_buy * $case_size;

            // Seasonal note.
            $seasonal_note = '';
            if ($seasonal_index > 1.05) {
                $pct = round(($seasonal_index - 1) * 100);
                $seasonal_note = "Seasonal uplift: +{$pct}% vs trailing baseline";
            } elseif ($seasonal_index < 0.95) {
                $pct = round((1 - $seasonal_index) * 100);
                $seasonal_note = "Seasonal dip: -{$pct}% vs trailing baseline";
            }

            // Weekly history as simple indexed array (most recent first).
            $history_values = array_values($weekly);

            $po_rows[] = [
                'sku'                 => $sku,
                'product_name'        => $p['product_name'],
                'weighted_avg_weekly' => $weighted_avg,
                'seasonal_index'      => round($seasonal_index, 2),
                'adjusted_weekly'     => $adjusted_weekly,
                'projected_need'      => $projected_need,
                'buffer'              => $buffer,
                'qty_needed'          => $qty_needed,
                'current_stock'       => $current_stock,
                'future_inventory'    => $future_inv,
                'total_available'     => $total_available,
                'units_needed'        => $units_needed,
                'case_size'           => $case_size,
                'cases_to_buy'        => $cases_to_buy,
                'order_quantity'      => $order_quantity,
                'seasonal_note'       => $seasonal_note,
                'weekly_history'      => $history_values,
            ];
        }

        // Sort by SKU ASC.
        usort($po_rows, function ($a, $b) {
            return strcmp($a['sku'], $b['sku']);
        });

        return $po_rows;
    }

    /**
     * Get YEARWEEK values for a date range.
     *
     * @param string $start Y-m-d
     * @param string $end   Y-m-d
     * @return array List of YEARWEEK strings (mode 1).
     */
    private function get_year_weeks_for_range(string $start, string $end): array {
        $weeks = [];
        $current = strtotime('monday this week', strtotime($start));
        $end_ts  = strtotime($end);

        while ($current < $end_ts) {
            // YEARWEEK mode 1: ISO week, Monday start.
            $year = (int) gmdate('o', $current);
            $week = (int) gmdate('W', $current);
            $weeks[] = sprintf('%04d%02d', $year, $week);
            $current = strtotime('+1 week', $current);
        }

        return $weeks;
    }

    /**
     * Export a seasonally-adjusted purchase order to CSV string.
     *
     * @param array $po_rows Rows from generate_purchase_order().
     *
     * @return string CSV content.
     */
    public function export_purchase_order_csv(array $po_rows): string {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, [
            'SKU', 'Product Name', 'Avg/Week', 'Seasonal Idx', 'Adj/Week',
            'Projected', 'Buffer', 'Qty Needed', 'Stock', 'Future',
            'Available', 'Units Needed', 'Case Size', 'Cases', 'Order Qty', 'Note',
        ]);

        $total_cases = 0;

        foreach ($po_rows as $row) {
            fputcsv($handle, [
                $row['sku'],
                $row['product_name'],
                $row['weighted_avg_weekly'],
                $row['seasonal_index'],
                $row['adjusted_weekly'],
                $row['projected_need'],
                $row['buffer'],
                $row['qty_needed'],
                $row['current_stock'],
                $row['future_inventory'],
                $row['total_available'],
                $row['units_needed'],
                $row['case_size'],
                $row['cases_to_buy'],
                $row['order_quantity'],
                $row['seasonal_note'],
            ]);
            $total_cases += $row['cases_to_buy'];
        }

        fputcsv($handle, []);
        fputcsv($handle, [
            'TOTAL', '', '', '', '', '', '', '', '', '', '', '', '', $total_cases, '', '',
        ]);

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /**
     * Reconcile client contributions: expected (from meals_clients) vs actual paid (fee product).
     *
     * @param string $start_date Y-m-d
     * @param string $end_date   Y-m-d
     * @return array ['rows' => [...], 'summary' => [...]]
     */
    public function contribution_reconciliation(string $start_date, string $end_date): array {
        if (!$this->order_query instanceof MealsDB_WC_Order_Query) {
            return ['rows' => [], 'summary' => self::empty_contribution_summary()];
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $clients = $this->wpdb->get_results(
            "SELECT client_id, wp_user_id, first_name, last_name, client_contribution, client_type
             FROM `{$clients_table}`
             WHERE client_contribution > 0 AND active = 1 AND wp_user_id > 0",
            ARRAY_A
        );

        if (!is_array($clients) || empty($clients)) {
            return ['rows' => [], 'summary' => self::empty_contribution_summary()];
        }

        $fee_ids = MealsDB_Invoice_Generator::get_fee_product_ids();
        $contribution_product_id = $fee_ids['client_contribution'];

        $rows = [];
        $total_expected   = 0.0;
        $total_paid       = 0.0;

        foreach ($clients as $client) {
            $wp_user_id  = (int) $client['wp_user_id'];
            $expected    = (float) $client['client_contribution'];
            $actual_paid = $this->order_query->get_total_paid_for_product(
                $wp_user_id, $contribution_product_id, $start_date, $end_date
            );
            $difference  = round($expected - $actual_paid, 2);

            $rows[] = [
                'client_id'   => (int) $client['client_id'],
                'wp_user_id'  => $wp_user_id,
                'first_name'  => $client['first_name'],
                'last_name'   => $client['last_name'],
                'client_type' => $client['client_type'],
                'expected'    => $expected,
                'actual_paid' => round($actual_paid, 2),
                'difference'  => $difference,
            ];

            $total_expected += $expected;
            $total_paid     += $actual_paid;
        }

        return [
            'rows'    => $rows,
            'summary' => [
                'total_clients'    => count($rows),
                'total_expected'   => round($total_expected, 2),
                'total_paid'       => round($total_paid, 2),
                'total_difference' => round($total_expected - $total_paid, 2),
            ],
        ];
    }

    /**
     * Reconcile delivery fees: expected (num_orders x fee) vs actual paid (fee product).
     *
     * @param string $start_date Y-m-d
     * @param string $end_date   Y-m-d
     * @return array ['rows' => [...], 'summary' => [...]]
     */
    public function delivery_fee_reconciliation(string $start_date, string $end_date): array {
        if (!$this->order_query instanceof MealsDB_WC_Order_Query) {
            return ['rows' => [], 'summary' => self::empty_delivery_summary()];
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $clients = $this->wpdb->get_results(
            "SELECT client_id, wp_user_id, first_name, last_name, delivery_fee, client_type
             FROM `{$clients_table}`
             WHERE delivery_fee > 0 AND active = 1 AND wp_user_id > 0",
            ARRAY_A
        );

        if (!is_array($clients) || empty($clients)) {
            return ['rows' => [], 'summary' => self::empty_delivery_summary()];
        }

        $fee_ids = MealsDB_Invoice_Generator::get_fee_product_ids();
        $delivery_product_id = $fee_ids['delivery_fee'];

        $rows = [];
        $total_owed = 0.0;
        $total_paid = 0.0;

        foreach ($clients as $client) {
            $wp_user_id   = (int) $client['wp_user_id'];
            $delivery_fee = (float) $client['delivery_fee'];
            $num_orders   = $this->order_query->get_order_count_for_user($wp_user_id, $start_date, $end_date);
            $owed         = round($num_orders * $delivery_fee, 2);
            $actual_paid  = $this->order_query->get_total_paid_for_product(
                $wp_user_id, $delivery_product_id, $start_date, $end_date
            );
            $difference   = round($owed - $actual_paid, 2);

            $rows[] = [
                'client_id'    => (int) $client['client_id'],
                'wp_user_id'   => $wp_user_id,
                'first_name'   => $client['first_name'],
                'last_name'    => $client['last_name'],
                'client_type'  => $client['client_type'],
                'delivery_fee' => $delivery_fee,
                'num_orders'   => $num_orders,
                'total_owed'   => $owed,
                'actual_paid'  => round($actual_paid, 2),
                'difference'   => $difference,
            ];

            $total_owed += $owed;
            $total_paid += $actual_paid;
        }

        return [
            'rows'    => $rows,
            'summary' => [
                'total_clients'    => count($rows),
                'total_owed'       => round($total_owed, 2),
                'total_paid'       => round($total_paid, 2),
                'total_difference' => round($total_owed - $total_paid, 2),
            ],
        ];
    }

    /**
     * Generate private customer sales report.
     *
     * Per-client totals of mains, sides, subtotals, tax, and final totals
     * for private (non-government) customers within a date range.
     *
     * @param string $start_date Y-m-d
     * @param string $end_date   Y-m-d
     * @return array ['rows' => [...], 'grand_totals' => [...]]
     */
    public function private_customer_report(string $start_date, string $end_date): array {
        $empty = ['rows' => [], 'grand_totals' => self::empty_private_report_totals()];

        if (!$this->wpdb instanceof wpdb) {
            return $empty;
        }

        // 1. Get active private clients with WP user IDs.
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $client_rows = $this->wpdb->get_results(
            "SELECT client_id, wp_user_id, first_name, last_name
             FROM `{$clients_table}`
             WHERE client_type = 'Private' AND active = 1 AND wp_user_id > 0",
            ARRAY_A
        );

        if (!is_array($client_rows) || empty($client_rows)) {
            return $empty;
        }

        $clients = [];
        foreach ($client_rows as $row) {
            $row['first_name'] = !empty($row['first_name']) ? MealsDB_Encryption::decrypt($row['first_name']) : '';
            $row['last_name']  = !empty($row['last_name']) ? MealsDB_Encryption::decrypt($row['last_name']) : '';
            $clients[] = $row;
        }

        // 2. Build product type lookup from meals_products, with WC category fallback.
        $product_type_map = [];
        if ($this->order_query instanceof MealsDB_WC_Order_Query) {
            $products_table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
            $prod_rows = $this->wpdb->get_results(
                "SELECT wc_product_id, product_type FROM `{$products_table}` WHERE product_type IN ('meal', 'side')",
                ARRAY_A
            );
            if (is_array($prod_rows)) {
                foreach ($prod_rows as $prow) {
                    $product_type_map[(int) $prow['wc_product_id']] = $prow['product_type'];
                }
            }
        }

        // WC category IDs for fallback: Mains=35, Sides=25,23,37,43.
        $mains_cat_ids = [35];
        $sides_cat_ids = [25, 23, 37, 43];

        // 3. Date range for WC order query.
        $start_datetime = $start_date . ' 00:00:00';
        $end_datetime   = $end_date . ' 23:59:59';
        $valid_statuses = ['wc-processing', 'wc-completed', 'wc-paid'];

        $orders_table     = $this->wpdb->prefix . 'wc_orders';
        $items_table      = $this->wpdb->prefix . 'woocommerce_order_items';
        $itemmeta_table   = $this->wpdb->prefix . 'woocommerce_order_itemmeta';

        $rows = [];
        $grand = self::empty_private_report_totals();

        foreach ($clients as $client) {
            $wp_user_id = (int) $client['wp_user_id'];

            // Get orders for this user in the date range.
            $order_sql = $this->wpdb->prepare(
                "SELECT id FROM {$orders_table}
                 WHERE customer_id = %d
                   AND date_created_gmt >= %s AND date_created_gmt <= %s
                   AND status IN ('wc-processing', 'wc-completed', 'wc-paid')
                   AND type = 'shop_order'",
                $wp_user_id, $start_datetime, $end_datetime
            );
            $order_ids = $this->wpdb->get_col($order_sql);

            if (empty($order_ids)) {
                continue;
            }

            $total_mains      = 0;
            $total_sides      = 0;
            $total_before_tax = 0.0;
            $total_tax        = 0.0;

            foreach ($order_ids as $order_id) {
                $wc_order = wc_get_order((int) $order_id);
                if (!$wc_order) {
                    continue;
                }

                $total_before_tax += (float) $wc_order->get_subtotal();
                $total_tax        += (float) $wc_order->get_total_tax();

                foreach ($wc_order->get_items() as $item) {
                    $product_id = (int) $item->get_product_id();
                    $qty        = (int) $item->get_quantity();

                    // Determine type: meals_products first, WC category fallback.
                    if (isset($product_type_map[$product_id])) {
                        if ($product_type_map[$product_id] === 'meal') {
                            $total_mains += $qty;
                        } elseif ($product_type_map[$product_id] === 'side') {
                            $total_sides += $qty;
                        }
                    } else {
                        // WC category fallback using hardcoded IDs.
                        if (function_exists('has_term') && has_term($mains_cat_ids, 'product_cat', $product_id)) {
                            $total_mains += $qty;
                        } elseif (function_exists('has_term') && has_term($sides_cat_ids, 'product_cat', $product_id)) {
                            $total_sides += $qty;
                        }
                    }
                }
            }

            // Skip clients with zero activity.
            if ($total_mains === 0 && $total_sides === 0 && $total_before_tax == 0) {
                continue;
            }

            $final_total = round($total_before_tax + $total_tax, 2);

            $rows[] = [
                'first_name'       => $client['first_name'],
                'last_name'        => $client['last_name'],
                'total_mains'      => $total_mains,
                'total_sides'      => $total_sides,
                'total_before_tax' => round($total_before_tax, 2),
                'total_tax'        => round($total_tax, 2),
                'final_total'      => $final_total,
            ];

            $grand['total_mains']      += $total_mains;
            $grand['total_sides']      += $total_sides;
            $grand['total_before_tax'] += $total_before_tax;
            $grand['total_tax']        += $total_tax;
            $grand['final_total']      += $final_total;
        }

        // Round grand totals.
        $grand['total_before_tax'] = round($grand['total_before_tax'], 2);
        $grand['total_tax']        = round($grand['total_tax'], 2);
        $grand['final_total']      = round($grand['final_total'], 2);

        // Sort by first_name ASC, then last_name ASC.
        usort($rows, function ($a, $b) {
            $cmp = strcasecmp($a['first_name'], $b['first_name']);
            return $cmp !== 0 ? $cmp : strcasecmp($a['last_name'], $b['last_name']);
        });

        return [
            'rows'         => $rows,
            'grand_totals' => $grand,
        ];
    }

    /**
     * Export private customer report to CSV string.
     *
     * @param array $data Result from private_customer_report().
     * @return string CSV content.
     */
    public function export_private_report_csv(array $data): string {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, [
            'First Name', 'Last Name', 'Total Mains', 'Total Sides',
            'Total Purchased Before Tax', 'Total Tax Charged', 'Final Total',
        ]);

        $rows = isset($data['rows']) ? $data['rows'] : [];
        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['first_name'],
                $row['last_name'],
                $row['total_mains'],
                $row['total_sides'],
                number_format($row['total_before_tax'], 2, '.', ''),
                number_format($row['total_tax'], 2, '.', ''),
                number_format($row['final_total'], 2, '.', ''),
            ]);
        }

        // Grand Total row.
        $grand = isset($data['grand_totals']) ? $data['grand_totals'] : self::empty_private_report_totals();
        fputcsv($handle, [
            'Grand Total', '',
            $grand['total_mains'],
            $grand['total_sides'],
            number_format($grand['total_before_tax'], 2, '.', ''),
            number_format($grand['total_tax'], 2, '.', ''),
            number_format($grand['final_total'], 2, '.', ''),
        ]);

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /**
     * Run data quality checks across WC orders in a date range.
     *
     * Checks for missing names, oversized initials, missing/invalid zones,
     * missing addresses, and orders with no meals_clients record.
     *
     * @param string $start_date Y-m-d
     * @param string $end_date   Y-m-d
     * @return array ['errors' => [...], 'summary' => [...]]
     */
    public function order_error_report(string $start_date, string $end_date): array {
        $empty_summary = [
            'total_orders_checked' => 0,
            'orders_with_errors'   => 0,
            'error_counts'         => [],
        ];

        if (!$this->wpdb instanceof wpdb) {
            return ['errors' => [], 'summary' => $empty_summary];
        }

        // Valid zones from option or default list.
        $valid_zones = get_option('mealsdb_valid_zones', [
            'Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5', 'Zone 6',
        ]);

        // 1. Batch-fetch all orders in the date range from HPOS.
        $orders_table = $this->wpdb->prefix . 'wc_orders';
        $start_dt = $start_date . ' 00:00:00';
        $end_dt   = $end_date . ' 23:59:59';

        $order_rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, customer_id, date_created_gmt, status
             FROM {$orders_table}
             WHERE date_created_gmt >= %s AND date_created_gmt <= %s
               AND status NOT IN ('wc-trash', 'trash')
               AND type = 'shop_order'
             ORDER BY date_created_gmt ASC",
            $start_dt, $end_dt
        ), ARRAY_A);

        if (!is_array($order_rows) || empty($order_rows)) {
            return ['errors' => [], 'summary' => $empty_summary];
        }

        // 2. Collect unique customer_ids and batch-fetch meals_clients records.
        $customer_ids = array_unique(array_filter(array_column($order_rows, 'customer_id')));
        $clients_map  = [];

        if (!empty($customer_ids)) {
            $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
            $placeholders  = implode(',', array_fill(0, count($customer_ids), '%d'));
            $sql = $this->wpdb->prepare(
                "SELECT wp_user_id, delivery_initials, delivery_area_name,
                        delivery_area_zone, delivery_street_name
                 FROM `{$clients_table}`
                 WHERE wp_user_id IN ({$placeholders})",
                ...array_values($customer_ids)
            );

            $client_rows = $this->wpdb->get_results($sql, ARRAY_A);
            if (is_array($client_rows)) {
                foreach ($client_rows as $row) {
                    $clients_map[(int) $row['wp_user_id']] = $row;
                }
            }
        }

        // 3. Run checks per order.
        $errors       = [];
        $error_counts = [];
        $errored_ids  = [];

        foreach ($order_rows as $orow) {
            $order_id    = (int) $orow['id'];
            $customer_id = (int) $orow['customer_id'];
            $order_date  = substr($orow['date_created_gmt'], 0, 10);
            $client      = isset($clients_map[$customer_id]) ? $clients_map[$customer_id] : null;

            $order_errors = [];

            if (!$client && $customer_id > 0) {
                // No meals_clients record — still check WC fields via wc_get_order.
                $wc_order = wc_get_order($order_id);
                if ($wc_order) {
                    $customer_name = trim($wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name());

                    if (empty(trim($wc_order->get_billing_first_name()))) {
                        $order_errors[] = ['type' => 'missing_first_name', 'detail' => 'Billing first name is empty'];
                    }
                    if (empty(trim($wc_order->get_billing_last_name()))) {
                        $order_errors[] = ['type' => 'missing_last_name', 'detail' => 'Billing last name is empty'];
                    }
                    if (empty(trim($wc_order->get_shipping_address_1()))) {
                        $order_errors[] = ['type' => 'missing_address', 'detail' => 'No shipping/delivery address'];
                    }

                    $order_errors[] = ['type' => 'no_client_record', 'detail' => 'Customer not found in meals_clients'];
                } else {
                    $customer_name = 'Unknown';
                    $order_errors[] = ['type' => 'no_client_record', 'detail' => 'Customer not found in meals_clients'];
                }
            } elseif ($client) {
                // Have a meals_clients record — check against it.
                $wc_order = wc_get_order($order_id);
                $customer_name = $wc_order
                    ? trim($wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name())
                    : 'Unknown';

                // Check 1: Missing first name.
                if ($wc_order && empty(trim($wc_order->get_billing_first_name()))) {
                    $order_errors[] = ['type' => 'missing_first_name', 'detail' => 'Billing first name is empty'];
                }

                // Check 2: Missing last name.
                if ($wc_order && empty(trim($wc_order->get_billing_last_name()))) {
                    $order_errors[] = ['type' => 'missing_last_name', 'detail' => 'Billing last name is empty'];
                }

                // Check 3: Initials too long.
                $initials = $client['delivery_initials'] ?? '';
                if (strlen($initials) > 3) {
                    $order_errors[] = [
                        'type'   => 'nickname_too_long',
                        'detail' => 'Initials "' . $initials . '" is ' . strlen($initials) . ' chars (expected 3)',
                    ];
                }

                // Check 4: Missing zone.
                $zone = $client['delivery_area_name'] ?? '';
                if (empty(trim($zone))) {
                    $order_errors[] = ['type' => 'missing_zone', 'detail' => 'No delivery zone assigned'];
                }

                // Check 5: Missing address.
                $address = $client['delivery_street_name'] ?? '';
                if (empty(trim($address))) {
                    $wc_address = $wc_order ? $wc_order->get_shipping_address_1() : '';
                    if (empty(trim($wc_address))) {
                        $order_errors[] = ['type' => 'missing_address', 'detail' => 'No shipping/delivery address'];
                    }
                }

                // Check 6: Invalid zone format.
                if (!empty($zone) && !in_array($zone, $valid_zones)) {
                    $order_errors[] = [
                        'type'   => 'invalid_zone',
                        'detail' => 'Zone "' . $zone . '" is not a recognized zone',
                    ];
                }
            } else {
                // Guest order (customer_id = 0).
                $wc_order = wc_get_order($order_id);
                $customer_name = $wc_order
                    ? trim($wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name())
                    : 'Guest';

                $order_errors[] = ['type' => 'no_client_record', 'detail' => 'Guest order — no customer account'];
            }

            // Record each error as a separate row.
            foreach ($order_errors as $err) {
                $errors[] = [
                    'order_id'      => $order_id,
                    'order_date'    => $order_date,
                    'customer_name' => $customer_name ?: 'Unknown',
                    'wp_user_id'    => $customer_id,
                    'error_type'    => $err['type'],
                    'error_detail'  => $err['detail'],
                ];

                if (!isset($error_counts[$err['type']])) {
                    $error_counts[$err['type']] = 0;
                }
                $error_counts[$err['type']]++;
                $errored_ids[$order_id] = true;
            }
        }

        return [
            'errors'  => $errors,
            'summary' => [
                'total_orders_checked' => count($order_rows),
                'orders_with_errors'   => count($errored_ids),
                'error_counts'         => $error_counts,
            ],
        ];
    }

    /**
     * @return array
     */
    private static function empty_private_report_totals(): array {
        return [
            'total_mains' => 0, 'total_sides' => 0,
            'total_before_tax' => 0, 'total_tax' => 0, 'final_total' => 0,
        ];
    }

    /**
     * @return array
     */
    private static function empty_contribution_summary(): array {
        return ['total_clients' => 0, 'total_expected' => 0, 'total_paid' => 0, 'total_difference' => 0];
    }

    /**
     * @return array
     */
    private static function empty_delivery_summary(): array {
        return ['total_clients' => 0, 'total_owed' => 0, 'total_paid' => 0, 'total_difference' => 0];
    }

    /**
     * Normalise date inputs to MySQL datetime strings.
     *
     * @param string|int $start_date
     * @param string|int $end_date
     *
     * @return array<string, string>|null
     */
    private function normalise_dates($start_date, $end_date): ?array {
        $start = is_int($start_date) ? $start_date : strtotime((string) $start_date);
        $end   = is_int($end_date) ? $end_date : strtotime((string) $end_date);

        if ($start === false || $end === false) {
            return null;
        }

        if ($start > $end) {
            $tmp   = $start;
            $start = $end;
            $end   = $tmp;
        }

        return [
            'start' => gmdate('Y-m-d H:i:s', $start),
            'end'   => gmdate('Y-m-d H:i:s', $end),
        ];
    }

    /**
     * Decode a JSON field into an array.
     *
     * @param mixed $value
     *
     * @return array<int|string, mixed>
     */
    private function decode_json_array($value): array {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
