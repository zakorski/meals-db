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

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return ['rows' => [], 'summary' => self::empty_contribution_summary()];
        }

        $clients_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $sql = sprintf(
            'SELECT client_id, wp_user_id, first_name, last_name, client_contribution, client_type
             FROM `%s`
             WHERE client_contribution > 0 AND active = 1 AND wp_user_id > 0',
            $clients_table
        );

        $result = $conn->query($sql);
        if (!MealsDB_DB::is_mysqli_result($result)) {
            return ['rows' => [], 'summary' => self::empty_contribution_summary()];
        }

        $clients = [];
        while ($row = $result->fetch_assoc()) {
            $clients[] = $row;
        }
        $result->free();

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

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return ['rows' => [], 'summary' => self::empty_delivery_summary()];
        }

        $clients_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $sql = sprintf(
            'SELECT client_id, wp_user_id, first_name, last_name, delivery_fee, client_type
             FROM `%s`
             WHERE delivery_fee > 0 AND active = 1 AND wp_user_id > 0',
            $clients_table
        );

        $result = $conn->query($sql);
        if (!MealsDB_DB::is_mysqli_result($result)) {
            return ['rows' => [], 'summary' => self::empty_delivery_summary()];
        }

        $clients = [];
        while ($row = $result->fetch_assoc()) {
            $clients[] = $row;
        }
        $result->free();

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

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return $empty;
        }

        // 1. Get active private clients with WP user IDs.
        $clients_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $sql = sprintf(
            "SELECT client_id, wp_user_id, first_name, last_name
             FROM `%s`
             WHERE client_type = 'Private' AND active = 1 AND wp_user_id > 0",
            $clients_table
        );

        $result = $conn->query($sql);
        if (!MealsDB_DB::is_mysqli_result($result)) {
            return $empty;
        }

        $clients = [];
        while ($row = $result->fetch_assoc()) {
            $row['first_name'] = !empty($row['first_name']) ? MealsDB_Encryption::decrypt($row['first_name']) : '';
            $row['last_name']  = !empty($row['last_name']) ? MealsDB_Encryption::decrypt($row['last_name']) : '';
            $clients[] = $row;
        }
        $result->free();

        if (empty($clients)) {
            return $empty;
        }

        // 2. Build product type lookup from meals_products, with WC category fallback.
        $product_type_map = [];
        if ($this->order_query instanceof MealsDB_WC_Order_Query) {
            $products_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS));
            $prod_result = $conn->query(sprintf(
                "SELECT wc_product_id, product_type FROM `%s` WHERE product_type IN ('meal', 'side')",
                $products_table
            ));
            if (MealsDB_DB::is_mysqli_result($prod_result)) {
                while ($prow = $prod_result->fetch_assoc()) {
                    $product_type_map[(int) $prow['wc_product_id']] = $prow['product_type'];
                }
                $prod_result->free();
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
