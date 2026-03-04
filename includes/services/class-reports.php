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

        $order_items_table      = $this->wpdb->prefix . 'wc_order_items';
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
                AND o.status NOT IN ('wc-cancelled', 'wc-trash', 'trash')
                AND o.type = 'shop_order'
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

        $order_items_table    = $this->wpdb->prefix . 'wc_order_items';
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
                AND o.status NOT IN ('wc-cancelled', 'wc-trash', 'trash')
                AND o.type = 'shop_order'
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

        $end_date   = gmdate('Y-m-d');
        $start_date = gmdate('Y-m-d', strtotime("-" . ($trailing_weeks * 7) . " days"));

        $order_items_table    = $this->wpdb->prefix . 'wc_order_items';
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
                AND o.status NOT IN ('wc-cancelled','wc-trash','trash')
            WHERE DATE(o.date_created_gmt) BETWEEN %s AND %s
                AND oi.order_item_type = 'line_item'
            GROUP BY wc_product_id, product_name, year_week
            ORDER BY wc_product_id, year_week
        ";

        $prepared = $this->wpdb->prepare($sql, $start_date, $end_date);
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
     * Generate a purchase order projection from historical demand.
     *
     * @param int $weeks_ahead    Weeks to project forward (default 1).
     * @param int $trailing_weeks Trailing weeks for demand history (default 8).
     *
     * @return array
     */
    public function generate_purchase_order(int $weeks_ahead = 1, int $trailing_weeks = 8): array {
        $demand = $this->get_demand_history($trailing_weeks);
        if (empty($demand)) {
            return [];
        }

        // Get product metadata from meals_products.
        $product_ids = array_keys($demand);
        $product_meta = [];
        if ($this->order_query instanceof MealsDB_WC_Order_Query) {
            $product_meta = $this->order_query->get_product_types_for_ids($product_ids);
        }

        $rows = [];
        foreach ($demand as $pid => $d) {
            $meta       = isset($product_meta[$pid]) ? $product_meta[$pid] : [];
            $case_size  = isset($meta['case_size']) && (int) $meta['case_size'] > 0 ? (int) $meta['case_size'] : 1;
            $unit_cost  = isset($meta['unit_cost']) ? (float) $meta['unit_cost'] : 0.0;
            $ptype      = isset($meta['product_type']) ? (string) $meta['product_type'] : 'meal';

            $projected = $d['avg_weekly_demand'] * $weeks_ahead;
            $cases     = $projected > 0 ? (int) ceil($projected / $case_size) : 0;

            $rows[] = [
                'wc_product_id'     => $pid,
                'product_name'      => $d['product_name'],
                'product_type'      => $ptype,
                'avg_weekly_demand' => $d['avg_weekly_demand'],
                'projected_units'   => round($projected, 2),
                'case_size'         => $case_size,
                'cases_needed'      => $cases,
                'unit_cost'         => $unit_cost,
                'estimated_cost'    => round($cases * $unit_cost, 2),
            ];
        }

        // Sort by product_type ASC, then product_name ASC.
        usort($rows, function ($a, $b) {
            $cmp = strcmp($a['product_type'], $b['product_type']);
            return $cmp !== 0 ? $cmp : strcmp($a['product_name'], $b['product_name']);
        });

        return $rows;
    }

    /**
     * Export a purchase order array to CSV string.
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
            'Product Name', 'Product Type', 'Avg Weekly Units', 'Projected Units',
            'Case Size', 'Cases to Order', 'Unit Cost', 'Estimated Cost',
        ]);

        $total_cases = 0;
        $total_cost  = 0.0;

        foreach ($po_rows as $row) {
            fputcsv($handle, [
                $row['product_name'],
                $row['product_type'],
                $row['avg_weekly_demand'],
                $row['projected_units'],
                $row['case_size'],
                $row['cases_needed'],
                number_format($row['unit_cost'], 2, '.', ''),
                number_format($row['estimated_cost'], 2, '.', ''),
            ]);
            $total_cases += $row['cases_needed'];
            $total_cost  += $row['estimated_cost'];
        }

        // Blank row + totals row.
        fputcsv($handle, []);
        fputcsv($handle, [
            'TOTAL', '', '', '', '', $total_cases, '', number_format($total_cost, 2, '.', ''),
        ]);

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
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
