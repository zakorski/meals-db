<?php
/**
 * Government Invoice Generator
 *
 * Generates invoices for government agencies (SDNB and Veterans Affairs Canada)
 * in their required CSV and PDF formats. Data is sourced from meals_clients
 * joined to WooCommerce HPOS orders via MealsDB_WC_Order_Query.
 *
 * @package MealsDB
 * @since 1.0.249
 */

if (!defined('ABSPATH')) {
    exit;
}

class MealsDB_Invoice_Generator {

    /**
     * Vendor information (hardcoded as per sample)
     */
    const VENDOR_NUMBER = '60835264';
    const VENDOR_NAME = 'Meals and More';
    const VENDOR_ADDRESS = 'PO Box 6382 Sackville NB';
    const HST_NUMBER = '799244819';
    const CONTACT_PERSON = 'Janet O\'Brien';
    const CONTACT_AREA_CODE = '506';
    const CONTACT_PHONE = '5368102';
    const CONTACT_EMAIL = 'janet@mealsandmore.ca';

    /**
     * Service Center information by zone
     */
    private static $service_centers = [
        'M' => [
            'number' => '4801',
            'name' => 'Moncton',
            'address' => '770 Main Street Assumption PL., 5th Floor, Moncton NB E1C 8R3'
        ],
        'S' => [
            'number' => '4802',
            'name' => 'Sussex',
            'address' => 'Sussex Service Center Address'
        ]
    ];

    /**
     * SDNB rate tiers for two-line invoice calculations.
     *
     * Each primary rate maps to its secondary rates and HST multipliers.
     * These values come from the old billing system and are contractual.
     */
    private static $sdnb_rate_tiers = [
        '14.66' => [
            'secondary_rate_mains' => 10.18,
            'secondary_rate_sides' => 4.48,
            'hst_multiplier_line1' => 0.672,
            'hst_multiplier_line2' => 0.672,
        ],
        '15.47' => [
            'secondary_rate_mains' => 10.93,
            'secondary_rate_sides' => 4.54,
            'hst_multiplier_line1' => 0.82,
            'hst_multiplier_line2' => 0.681,
        ],
    ];

    /**
     * VAC monthly allowances by service frequency
     */
    private static $vac_allowances = [
        'day' => ['mains' => 7, 'amount' => 74.48],
        'week' => ['mains' => 31, 'amount' => 329.84],
        'month' => ['mains' => 124, 'amount' => 1319.36]
    ];

    /**
     * VAC billing constants (contractual rates).
     */
    private static $vac_billing = [
        'per_main_allowance'     => 10.64,  // Monthly allowance = mains_allowed × this
        'sides_conversion_rate'  => 4.715,  // Remaining allowance ÷ this = sides allowed
        'sides_cost_rate'        => 4.10,   // Cost per billable side
        'sides_hst_rate'         => 0.15,   // HST on taxable sides (15%)
    ];

    /**
     * Shared data-fetch: resolves orders, rates, and product types for a set of clients.
     *
     * Returns one row per order, enriched with client fields, resolved rate,
     * unit totals, basic cost, tax, and total cost.
     *
     * @param array  $client_rows Rows from meals_clients (must include client_id, wp_user_id, default_rate_id).
     * @param string $start_date  Y-m-d.
     * @param string $end_date    Y-m-d.
     *
     * @return array Enriched invoice rows.
     */
    private static function get_invoice_data_for_clients(array $client_rows, string $start_date, string $end_date): array {
        if (empty($client_rows)) {
            return [];
        }

        // Index clients by wp_user_id.
        $clients_by_user_id = [];
        $wp_user_ids        = [];
        foreach ($client_rows as $c) {
            $uid = (int) $c['wp_user_id'];
            if ($uid > 0) {
                $clients_by_user_id[$uid] = $c;
                $wp_user_ids[]            = $uid;
            }
        }

        if (empty($wp_user_ids)) {
            return [];
        }

        $order_query = new MealsDB_WC_Order_Query($GLOBALS['wpdb']);
        $orders      = $order_query->get_orders_with_items_for_users($wp_user_ids, $start_date, $end_date);

        if (empty($orders)) {
            return [];
        }

        // Collect all wc_product_ids across every item for a single product-type lookup.
        $all_product_ids = [];
        foreach ($orders as $order) {
            foreach ($order['items'] as $item) {
                $pid = (int) $item['wc_product_id'];
                if ($pid > 0) {
                    $all_product_ids[$pid] = $pid;
                }
            }
        }

        $product_types = $order_query->get_product_types_for_ids(array_values($all_product_ids));

        // Build invoice rows — one per order.
        $rows = [];
        foreach ($orders as $order) {
            $uid    = (int) $order['wp_user_id'];
            $client = isset($clients_by_user_id[$uid]) ? $clients_by_user_id[$uid] : null;
            if (!$client) {
                continue;
            }

            $rate_id       = isset($order['mealsdb_rate_id']) ? (int) $order['mealsdb_rate_id'] : 0;
            $resolved_rate = $order_query->resolve_rate_for_order($rate_id, (int) $client['client_id']);

            $total_units = 0;
            $tax_amount  = 0.0;

            foreach ($order['items'] as $item) {
                $qty = (float) $item['quantity'];
                $total_units += $qty;

                $pid    = (int) $item['wc_product_id'];
                $is_tax = isset($product_types[$pid]) && !empty($product_types[$pid]['taxable']);
                if ($is_tax) {
                    $tax_amount += (float) $item['line_tax'];
                }
            }

            $basic_cost          = $total_units * $resolved_rate;
            $client_contribution = (float) ($client['client_contribution'] ?? 0);
            $total_cost          = $basic_cost + $tax_amount - $client_contribution;

            $rows[] = array_merge($client, [
                'order_id'        => (int) $order['order_id'],
                'order_date'      => $order['date_created_gmt'],
                'resolved_rate'   => $resolved_rate,
                'total_units'     => $total_units,
                'basic_cost'      => $basic_cost,
                'tax_amount'      => $tax_amount,
                'total_cost'      => $total_cost,
                'items'           => $order['items'],
            ]);
        }

        return $rows;
    }

    /**
     * Aggregate orders per client and compute allowance-based billing splits.
     *
     * Returns one row per client (not per order) with mains/sides broken into
     * billable and overage quantities, with sides further split by taxable status.
     *
     * @param array  $client_rows     Rows from meals_clients.
     * @param string $start_date      Y-m-d.
     * @param string $end_date        Y-m-d.
     * @param int    $weeks_in_month  Number of Wednesdays in the billing month.
     *
     * @return array One row per client with allowance calculations.
     */
    private static function get_allowance_data_for_clients(
        array $client_rows,
        string $start_date,
        string $end_date,
        int $weeks_in_month = 4
    ): array {
        if (empty($client_rows)) {
            return [];
        }

        $clients_by_user_id = [];
        $wp_user_ids        = [];
        foreach ($client_rows as $c) {
            $uid = (int) $c['wp_user_id'];
            if ($uid > 0) {
                $clients_by_user_id[$uid] = $c;
                $wp_user_ids[]            = $uid;
            }
        }

        if (empty($wp_user_ids)) {
            return [];
        }

        $order_query = new MealsDB_WC_Order_Query($GLOBALS['wpdb']);
        $orders      = $order_query->get_orders_with_items_for_users($wp_user_ids, $start_date, $end_date);

        if (empty($orders)) {
            return [];
        }

        // Look up product types for all items.
        $all_product_ids = [];
        foreach ($orders as $order) {
            foreach ($order['items'] as $item) {
                $pid = (int) $item['wc_product_id'];
                if ($pid > 0) {
                    $all_product_ids[$pid] = $pid;
                }
            }
        }
        $product_types = $order_query->get_product_types_for_ids(array_values($all_product_ids));

        $days_in_month = (int) date('t', strtotime($end_date));

        // Accumulate totals per client.
        $client_aggregates = [];

        foreach ($orders as $order) {
            $uid    = (int) $order['wp_user_id'];
            $client = isset($clients_by_user_id[$uid]) ? $clients_by_user_id[$uid] : null;
            if (!$client) {
                continue;
            }

            $cid = (int) $client['client_id'];
            if (!isset($client_aggregates[$cid])) {
                $rate_id       = isset($order['mealsdb_rate_id']) ? (int) $order['mealsdb_rate_id'] : 0;
                $resolved_rate = $order_query->resolve_rate_for_order($rate_id, $cid);

                $client_aggregates[$cid] = [
                    'client'               => $client,
                    'resolved_rate'        => $resolved_rate,
                    'total_mains'          => 0,
                    'total_sides_taxable'  => 0,
                    'total_sides_nontax'   => 0,
                    'total_tax_amount'     => 0.0,
                ];
            }

            foreach ($order['items'] as $item) {
                $pid  = (int) $item['wc_product_id'];
                $qty  = (float) $item['quantity'];
                $prod = isset($product_types[$pid]) ? $product_types[$pid] : null;

                $ptype  = $prod ? $prod['product_type'] : 'meal';
                $is_tax = $prod ? !empty($prod['taxable']) : false;

                if ($ptype === 'meal') {
                    $client_aggregates[$cid]['total_mains'] += $qty;
                } elseif ($ptype === 'side') {
                    if ($is_tax) {
                        $client_aggregates[$cid]['total_sides_taxable'] += $qty;
                        $client_aggregates[$cid]['total_tax_amount']    += (float) ($item['line_tax'] ?? 0);
                    } else {
                        $client_aggregates[$cid]['total_sides_nontax'] += $qty;
                    }
                }
            }
        }

        $results = [];

        foreach ($client_aggregates as $cid => $agg) {
            $client = $agg['client'];

            $user_mains   = (int) ($client['allowance_mains'] ?? 0);
            $user_sides   = (int) ($client['allowance_sides'] ?? 0);
            $user_service = strtolower(trim($client['requisition_period'] ?? 'week'));

            // --- Allowance calculation ---
            $mains_allowed = 0;
            $sides_allowed = 0;

            switch ($user_service) {
                case 'month':
                    $mains_allowed = ($user_mains == 31) ? $days_in_month : $user_mains;
                    $sides_allowed = ($user_sides == 31) ? $days_in_month : $user_sides;
                    break;

                case 'week':
                    $mains_allowed = $user_mains * $weeks_in_month;
                    $sides_allowed = $user_sides * $weeks_in_month;

                    // Override: 7 per week = every day; 14 per week = twice per day
                    if ($user_mains == 7) {
                        $mains_allowed = $days_in_month;
                    }
                    if ($user_mains == 14) {
                        $mains_allowed = 2 * $days_in_month;
                    }
                    if ($user_sides == 7) {
                        $sides_allowed = $days_in_month;
                    }
                    if ($user_sides == 14) {
                        $sides_allowed = 2 * $days_in_month;
                    }
                    break;

                case 'day':
                    $mains_allowed = $user_mains * $days_in_month;
                    $sides_allowed = $user_sides * $days_in_month;
                    break;
            }

            // --- Mains split ---
            $total_mains = (int) $agg['total_mains'];
            $bill_mains  = min($total_mains, $mains_allowed);
            $bnm_mains   = max(0, $total_mains - $mains_allowed);

            // --- Sides split (taxable first, then non-taxable with remaining allowance) ---
            $taxable_sides     = (int) $agg['total_sides_taxable'];
            $non_taxable_sides = (int) $agg['total_sides_nontax'];
            $total_sides       = $taxable_sides + $non_taxable_sides;

            // Taxable sides get priority against the allowance.
            $bill_tax_sides    = min($sides_allowed, $taxable_sides);
            $overage_tax_sides = $taxable_sides - $bill_tax_sides;

            // Remaining allowance after taxable sides.
            $remaining_sides = ($overage_tax_sides == 0)
                ? max(0, $sides_allowed - $taxable_sides)
                : 0;

            // Non-taxable sides fill whatever allowance remains.
            $bill_nontax_sides    = min($non_taxable_sides, $remaining_sides);
            $overage_nontax_sides = $non_taxable_sides - $bill_nontax_sides;

            $bill_sides = $bill_tax_sides + $bill_nontax_sides;

            $results[] = [
                'client'               => $client,
                'resolved_rate'        => $agg['resolved_rate'],
                'client_contribution'  => (float) ($client['client_contribution'] ?? 0),

                // Mains
                'total_mains'          => $total_mains,
                'mains_allowed'        => $mains_allowed,
                'bill_mains'           => $bill_mains,
                'bnm_mains'            => $bnm_mains,

                // Sides totals
                'total_sides'          => $total_sides,
                'sides_allowed'        => $sides_allowed,
                'taxable_sides'        => $taxable_sides,
                'non_taxable_sides'    => $non_taxable_sides,

                // Sides splits
                'bill_tax_sides'       => $bill_tax_sides,
                'overage_tax_sides'    => $overage_tax_sides,
                'remaining_sides'      => $remaining_sides,
                'bill_nontax_sides'    => $bill_nontax_sides,
                'overage_nontax_sides' => $overage_nontax_sides,
                'bill_sides'           => $bill_sides,

                // Tax
                'total_tax_amount'     => $agg['total_tax_amount'],

                // Service info
                'user_service'         => $user_service,
            ];
        }

        // Sort by last_name, first_name.
        usort($results, function ($a, $b) {
            $cmp = strcmp($a['client']['last_name'] ?? '', $b['client']['last_name'] ?? '');
            return $cmp !== 0 ? $cmp : strcmp($a['client']['first_name'] ?? '', $b['client']['first_name'] ?? '');
        });

        return $results;
    }

    /**
     * Split a client's allowance row into one or two invoice lines.
     *
     * @param array $row Single client row from get_allowance_data_for_clients().
     * @return array Array of 1 or 2 invoice line arrays.
     */
    private static function split_into_invoice_lines(array $row): array {
        $rate = (float) $row['resolved_rate'];
        $rate_key = number_format($rate, 2, '.', '');
        $tier = isset(self::$sdnb_rate_tiers[$rate_key]) ? self::$sdnb_rate_tiers[$rate_key] : null;

        $bill_mains        = (int) $row['bill_mains'];
        $bill_sides        = (int) $row['bill_sides'];
        $bill_tax_sides    = (int) $row['bill_tax_sides'];
        $bill_nontax_sides = (int) $row['bill_nontax_sides'];
        $client_contribution = (float) $row['client_contribution'];

        $hst_mult_l1 = $tier ? $tier['hst_multiplier_line1'] : 0;
        $hst_mult_l2 = $tier ? $tier['hst_multiplier_line2'] : 0;

        // Line 1 calculations.
        $mains_on_line_1 = ($bill_sides == 0) ? $bill_mains : min($bill_mains, $bill_sides);
        $tax_sides_on_line_1 = ($bill_sides == 0 || $bill_tax_sides == 0)
            ? 0 : min($mains_on_line_1, $bill_tax_sides);
        $nontax_sides_on_line_1 = ($bill_sides == 0 || $bill_nontax_sides == 0)
            ? 0 : min($mains_on_line_1 - $tax_sides_on_line_1, $bill_nontax_sides);
        $hst_line_1 = ($tax_sides_on_line_1 != 0) ? round($tax_sides_on_line_1 * $hst_mult_l1, 2) : 0;

        // Line 2 calculations.
        $mains_on_line_2        = max(0, $bill_mains - $mains_on_line_1);
        $tax_sides_on_line_2    = $bill_tax_sides - $tax_sides_on_line_1;
        $nontax_sides_on_line_2 = $bill_nontax_sides - $nontax_sides_on_line_1;
        $hst_line_2 = ($tax_sides_on_line_2 != 0) ? round($tax_sides_on_line_2 * $hst_mult_l2, 2) : 0;

        $has_second_line = ($mains_on_line_2 + $tax_sides_on_line_2 + $nontax_sides_on_line_2 + $hst_line_2) > 0;

        // Determine second line rate.
        $second_line_rate = 0;
        if ($has_second_line && $tier) {
            $second_line_rate = ($mains_on_line_2 > 0)
                ? $tier['secondary_rate_mains']
                : (($tax_sides_on_line_2 + $nontax_sides_on_line_2 > 0)
                    ? $tier['secondary_rate_sides']
                    : 0);
        }

        $client = $row['client'];
        $lines = [];

        // Line 1.
        $units_l1 = $mains_on_line_1;
        $lines[] = [
            'service_id'          => $client['service_id'] ?? '',
            'requisition_id'      => $client['requisition_id'] ?? '',
            'individual_id'       => $client['individual_id'] ?? '',
            'last_name'           => $client['last_name'] ?? '',
            'first_name'          => $client['first_name'] ?? '',
            'units'               => $units_l1,
            'unit_type'           => 'Meal',
            'rate'                => $rate,
            'basic_cost'          => $units_l1 * $rate,
            'client_contribution' => $client_contribution,
            'tax'                 => $hst_line_1,
        ];

        // Line 2 (if needed).
        if ($has_second_line) {
            $units_l2 = $mains_on_line_2 + $tax_sides_on_line_2 + $nontax_sides_on_line_2;
            $lines[] = [
                'service_id'          => $client['service_id'] ?? '',
                'requisition_id'      => $client['requisition_id'] ?? '',
                'individual_id'       => $client['individual_id'] ?? '',
                'last_name'           => $client['last_name'] ?? '',
                'first_name'          => $client['first_name'] ?? '',
                'units'               => $units_l2,
                'unit_type'           => 'Meal',
                'rate'                => $second_line_rate,
                'basic_cost'          => $units_l2 * $second_line_rate,
                'client_contribution' => 0, // Always 0 on second line
                'tax'                 => $hst_line_2,
            ];
        }

        return $lines;
    }

    /**
     * Validate a client row and return error messages.
     *
     * @param array  $client       Client row from meals_clients.
     * @param string $client_type  'SDNB' or 'Veteran'.
     * @param array  $duplicate_counts Map of individual_id_index => count of clients sharing that index.
     * @param int    $duplicate_threshold How many is too many (SDNB = 2, Veteran = 1).
     * @return string Comma-separated error messages, or 'No' if none.
     */
    private static function validate_client_row(
        array $client,
        string $client_type,
        array $duplicate_counts,
        int $duplicate_threshold = 2
    ): string {
        $errors = [];

        // Missing field checks.
        if ($client_type === 'SDNB') {
            if (empty($client['service_id'])) {
                $errors[] = 'Missing service ID';
            }
            if (empty($client['requisition_id'])) {
                $errors[] = 'Missing requisition ID';
            }
        }

        if (empty($client['individual_id'])) {
            $errors[] = 'Missing individual ID';
        }

        // Duplicate check via deterministic index.
        $id_index = $client['individual_id_index'] ?? '';
        if ($id_index !== '' && isset($duplicate_counts[$id_index]) && $duplicate_counts[$id_index] > $duplicate_threshold) {
            $errors[] = 'Duplicate person';
        }

        return !empty($errors) ? implode(', ', $errors) : 'No';
    }

    /**
     * Check if a WordPress user was created during the billing period.
     *
     * @param int    $wp_user_id  WordPress user ID.
     * @param string $start_date  Y-m-d.
     * @param string $end_date    Y-m-d.
     * @return string Flag text or empty string.
     */
    private static function check_new_user_flag(int $wp_user_id, string $start_date, string $end_date): string {
        if ($wp_user_id <= 0) {
            return '';
        }

        $user = get_userdata($wp_user_id);
        if (!$user || empty($user->user_registered)) {
            return '';
        }

        $registered   = new DateTime($user->user_registered);
        $period_start = new DateTime($start_date);
        $period_end   = new DateTime($end_date);

        if ($registered >= $period_start && $registered <= $period_end) {
            return 'New - account - user created on ' . $registered->format('Y-m-d');
        }

        return '';
    }

    /**
     * Query meals_clients from the external DB with a prepared statement.
     *
     * @param string $sql    SQL with ? placeholders.
     * @param string $types  bind_param type string.
     * @param array  $params Parameters to bind.
     *
     * @return array Client rows.
     */
    private static function query_clients(string $sql, string $types = '', array $params = []): array {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return [];
        }

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return [];
        }

        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        $rows   = [];
        if (MealsDB_DB::is_mysqli_result($result)) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        $stmt->close();

        return $rows;
    }

    /**
     * Generate SDNB Legacy Zone-Based Invoice
     *
     * @param string $zone Zone code (M=Moncton, S=Sussex)
     * @param string $start_date Start date (Y-m-d format)
     * @param string $end_date End date (Y-m-d format)
     * @return string CSV content
     */
    public static function generate_sdnb_legacy($zone, $start_date, $end_date, $weeks_in_month = 4) {
        // Get service center info
        $service_center = isset(self::$service_centers[$zone]) ? self::$service_centers[$zone] : self::$service_centers['M'];

        // Format invoice number: "2025 Jan 31 M"
        $end_date_obj = new DateTime($end_date);
        $invoice_number = $end_date_obj->format('Y M d') . ' ' . $zone;

        // Query eligible clients from external DB.
        $clients_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $sql = sprintf(
            'SELECT client_id, wp_user_id, first_name, last_name, service_id, requisition_id,
                    individual_id, individual_id_index, client_contribution, delivery_area_zone,
                    default_rate_id, allowance_mains, allowance_sides, requisition_period
             FROM `%s`
             WHERE client_type = ? AND use_legacy_billing = 1
               AND delivery_area_zone = ? AND active = 1 AND wp_user_id > 0',
            $clients_table
        );

        $client_type = 'SDNB';
        $client_rows = self::query_clients($sql, 'ss', [$client_type, $zone]);

        // Pre-compute duplicate individual_id counts for error checking.
        $sdnb_duplicate_counts = [];
        foreach ($client_rows as $c) {
            $idx = $c['individual_id_index'] ?? '';
            if ($idx !== '') {
                if (!isset($sdnb_duplicate_counts[$idx])) {
                    $sdnb_duplicate_counts[$idx] = 0;
                }
                $sdnb_duplicate_counts[$idx]++;
            }
        }

        // Fetch invoice data via allowance engine.
        $invoice_rows = self::get_allowance_data_for_clients($client_rows, $start_date, $end_date, $weeks_in_month);

        // Apply allowance engine + two-line splits to get final invoice lines.
        $all_invoice_lines = [];
        foreach ($invoice_rows as $row) {
            $client = $row['client'];
            $error_string  = self::validate_client_row($client, 'SDNB', $sdnb_duplicate_counts, 2);
            $new_user_flag = self::check_new_user_flag((int) ($client['wp_user_id'] ?? 0), $start_date, $end_date);

            $lines = self::split_into_invoice_lines($row);
            foreach ($lines as $line) {
                $line['errors']        = $error_string;
                $line['new_user_flag'] = $new_user_flag;
                $all_invoice_lines[]   = $line;
            }
        }

        // Accumulate totals for header.
        $total_invoice_amount = 0;
        $total_tax_amount     = 0;
        foreach ($all_invoice_lines as $line) {
            $total_cost = $line['basic_cost'] + $line['tax'] - $line['client_contribution'];
            $total_invoice_amount += $total_cost;
            $total_tax_amount     += $line['tax'];
        }

        // Build CSV content.
        $csv = [];

        // Row 1: Blank row with commas (unchanged from current implementation)
        $csv[] = str_repeat(',', 99);

        // Row 3: Header with version (unchanged)
        $row3 = array_fill(0, 100, '');
        $row3[0] = '1';
        $row3[1] = 'Social Development';
        $row3[5] = 'Electronic Invoice Datasheet';
        $row3[9] = 'version 36e';
        $csv[] = implode(',', $row3);

        // Row 4: Invoice metadata header row (unchanged from current)
        $row4 = array_fill(0, 100, '');
        $row4[0] = '1';
        $row4[1] = 'Invoice No.';
        $row4[2] = 'Vendor No.';
        $row4[3] = 'Vendor Name';
        $row4[5] = 'Vendor Address';
        $row4[6] = 'Service Center No';
        $row4[7] = 'Service Center Name';
        $row4[10] = 'Service Center Address';
        $row4[12] = 'Billing Period Start Date';
        $row4[13] = 'Billing Period End Date';
        $row4[14] = 'Tax Indicator';
        $row4[15] = 'HST / GST #';
        $row4[16] = 'Tax Amount';
        $row4[17] = 'Total Invoice Amount';
        $row4[18] = 'Contact Person';
        $row4[20] = 'Contact Area Code';
        $row4[21] = 'Contact Phone No.';
        $row4[22] = 'Contact E-mail';
        $row4[23] = '# of Invoice Lines';
        $csv[] = implode(',', $row4);

        // Row 5: Invoice metadata values (unchanged structure, updated totals)
        $row5 = array_fill(0, 100, '');
        $row5[0] = '2';
        $row5[1] = $invoice_number;
        $row5[2] = self::VENDOR_NUMBER;
        $row5[3] = self::VENDOR_NAME;
        $row5[5] = self::VENDOR_ADDRESS;
        $row5[6] = $service_center['number'];
        $row5[7] = $service_center['name'];
        $row5[10] = $service_center['address'];
        $row5[12] = str_replace('-', '', $start_date);
        $row5[13] = str_replace('-', '', $end_date);
        $row5[14] = 'Full';
        $row5[15] = self::HST_NUMBER;
        $row5[16] = number_format($total_tax_amount, 2, '.', '');
        $row5[17] = number_format($total_invoice_amount, 2, '.', '');
        $row5[18] = self::CONTACT_PERSON;
        $row5[20] = self::CONTACT_AREA_CODE;
        $row5[21] = self::CONTACT_PHONE;
        $row5[22] = self::CONTACT_EMAIL;
        $row5[23] = count($all_invoice_lines);
        $row5[24] = 'F';
        $csv[] = implode(',', $row5);

        // Row 6: Column headers for data rows (unchanged)
        $row6 = array_fill(0, 100, '');
        $row6[0] = '1';
        $row6[1] = 'Service Id';
        $row6[2] = 'Requisition Id';
        $row6[3] = 'Individual Id';
        $row6[4] = 'Client Last Name';
        $row6[5] = 'Client First Name';
        $row6[6] = 'No. of Units';
        $row6[7] = 'Unit Type';
        $row6[8] = 'Rate';
        $row6[9] = 'Basic Cost';
        $row6[10] = 'Total Kilometers - (transportation - home support)';
        $row6[11] = 'Other Cost (transportation - home support)';
        $row6[12] = 'Total Kilometers (transportation - family support worker)';
        $row6[13] = 'Other Cost (transportation - family support worker)';
        $row6[14] = 'Other Cost (transportation - medical)';
        $row6[15] = 'Other Cost (transportation - daycare)';
        $row6[16] = 'Other Cost (transportation - other)';
        $row6[17] = 'Other Cost (meals)';
        $row6[18] = 'Other Cost (sundry)';
        $row6[19] = 'Other Cost  (admin fees)';
        $row6[20] = 'Other Cost (lodging)';
        $row6[21] = 'Other Cost (recreation)';
        $row6[22] = 'Other Cost (parking)';
        $row6[23] = 'Client Contribution';
        $row6[24] = 'Dept. Cost';
        $row6[25] = 'Mileage Cost Indicator';
        $row6[26] = 'Mileage Cost';
        $row6[27] = 'Stat Holiday Units';
        $row6[28] = 'Stat. Holiday Amt';
        $row6[29] = 'Shift Diff. Units';
        $row6[30] = 'Shift Diff. Rate';
        $row6[31] = 'Shift Diff. Cost';
        $row6[32] = 'Shift Diff. Stat Holiday Units';
        $row6[33] = 'Shift Diff. Stat Holiday Cost';
        $row6[34] = 'Tax';
        $row6[35] = 'Total Invoice Line Cost';
        $csv[] = implode(',', $row6);

        // Data rows — one per invoice line.
        foreach ($all_invoice_lines as $line) {
            $basic_cost = $line['basic_cost'];
            $total_line_cost = $basic_cost + $line['tax'] - $line['client_contribution'];

            $row = array_fill(0, 100, '');
            $row[0]  = '3';
            $row[1]  = $line['service_id'] ?: '356029';
            $row[2]  = $line['requisition_id'] ?: '';
            $row[3]  = $line['individual_id'] ?: '';
            $row[4]  = $line['last_name'] ?: '';
            $row[5]  = $line['first_name'] ?: '';
            $row[6]  = number_format($line['units'], 2, '.', '');
            $row[7]  = 'Meal';
            $row[8]  = number_format($line['rate'], 2, '.', '');
            $row[9]  = number_format($basic_cost, 2, '.', '');
            $row[23] = number_format($line['client_contribution'], 2, '.', '');
            $row[24] = number_format($basic_cost, 2, '.', '');
            $row[27] = number_format(0, 2, '.', '');
            $row[30] = number_format(0, 2, '.', '');
            $row[33] = number_format(0, 2, '.', '');
            $row[34] = number_format($line['tax'], 2, '.', '');
            $row[35] = number_format($total_line_cost, 2, '.', '');
            $row[36] = 'I';
            $csv[] = implode(',', $row);
        }

        return implode("\n", $csv);
    }

    /**
     * Generate SDNB New Portal Format Invoice
     *
     * @param string $start_date Start date (Y-m-d format)
     * @param string $end_date End date (Y-m-d format)
     * @return string CSV content
     */
    public static function generate_sdnb_new_portal($start_date, $end_date) {
        // Query eligible clients from external DB.
        $clients_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $sql = sprintf(
            'SELECT client_id, wp_user_id, first_name, last_name, sdnb_service_request_id,
                    client_contribution, default_rate_id
             FROM `%s`
             WHERE client_type = ? AND use_legacy_billing = 0
               AND active = 1 AND wp_user_id > 0',
            $clients_table
        );

        $client_type = 'SDNB';
        $client_rows = self::query_clients($sql, 's', [$client_type]);

        // Fetch invoice data via WC HPOS.
        $invoice_rows = self::get_invoice_data_for_clients($client_rows, $start_date, $end_date);

        $csv = [];

        // Header row
        $csv[] = 'Service Confirmation Item Id,Product Name,Service Request Id,Client Name,No. Of Units,Unit Type,Rate,Kilometres,Kilometre Rate,Other Cost (transportation),Other Cost (meals),Other Cost (sundry),Other Cost (admin fees),Other Cost (recreation),Other Cost (parking),Client Contribution,Stat Holiday Units,Tax';

        // Data rows — sorted by client name.
        usort($invoice_rows, function ($a, $b) {
            $name_a = strtoupper($a['first_name'] . ' ' . $a['last_name']);
            $name_b = strtoupper($b['first_name'] . ' ' . $b['last_name']);
            return strcmp($name_a, $name_b);
        });

        foreach ($invoice_rows as $r) {
            $sci_id      = 'SCI-' . str_pad($r['order_id'], 8, '0', STR_PAD_LEFT);
            $client_name = strtoupper($r['first_name']) . ' ' . strtoupper($r['last_name']);

            $csv[] = sprintf(
                '%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s',
                $sci_id,
                'Meal Services - Services de repas',
                $r['sdnb_service_request_id'] ?: '',
                $client_name,
                intval($r['total_units']),
                'Meal',
                number_format($r['resolved_rate'], 2, '.', ''),
                '', // Kilometres
                '', // Kilometre Rate
                '', // Other Cost (transportation)
                '', // Other Cost (meals)
                '', // Other Cost (sundry)
                '', // Other Cost (admin fees)
                '', // Other Cost (recreation)
                '', // Other Cost (parking)
                '', // Client Contribution
                '', // Stat Holiday Units
                number_format($r['tax_amount'], 2, '.', '')
            );
        }

        return implode("\n", $csv);
    }

    /**
     * Generate VAC CSV Invoice
     *
     * @param string $start_date Start date (Y-m-d format)
     * @param string $end_date End date (Y-m-d format)
     * @return string CSV content
     */
    public static function generate_vac_csv($start_date, $end_date) {
        // Query eligible veteran clients from external DB.
        $clients_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $sql = sprintf(
            'SELECT client_id, wp_user_id, first_name, last_name, requisition_id,
                    vet_health_card, requisition_period, client_contribution, default_rate_id,
                    apartment_number, street_number, street_name, city, postal_code, client_phone_1,
                    allowance_mains, allowance_sides, individual_id, individual_id_index
             FROM `%s`
             WHERE client_type = ? AND active = 1 AND wp_user_id > 0',
            $clients_table
        );

        $client_type = 'Veteran';
        $client_rows = self::query_clients($sql, 's', [$client_type]);

        if (empty($client_rows)) {
            return '';
        }

        // Pre-compute duplicate individual_id counts for error checking.
        $vet_duplicate_counts = [];
        foreach ($client_rows as $c) {
            $idx = $c['individual_id_index'] ?? '';
            if ($idx !== '') {
                if (!isset($vet_duplicate_counts[$idx])) {
                    $vet_duplicate_counts[$idx] = 0;
                }
                $vet_duplicate_counts[$idx]++;
            }
        }

        // Fetch WC HPOS orders for all veterans.
        $order_query = new MealsDB_WC_Order_Query($GLOBALS['wpdb']);
        $wp_user_ids = [];
        $clients_by_user = [];
        foreach ($client_rows as $c) {
            $uid = (int) $c['wp_user_id'];
            if ($uid > 0) {
                $wp_user_ids[]       = $uid;
                $clients_by_user[$uid] = $c;
            }
        }

        $orders = $order_query->get_orders_with_items_for_users($wp_user_ids, $start_date, $end_date);

        // Collect all product IDs for a single product-type lookup.
        $all_product_ids = [];
        foreach ($orders as $order) {
            foreach ($order['items'] as $item) {
                $pid = (int) $item['wc_product_id'];
                if ($pid > 0) {
                    $all_product_ids[$pid] = $pid;
                }
            }
        }
        $product_types = $order_query->get_product_types_for_ids(array_values($all_product_ids));

        // Aggregate orders per veteran (client_id).
        $vet_aggregates = [];
        foreach ($orders as $order) {
            $uid    = (int) $order['wp_user_id'];
            $client = isset($clients_by_user[$uid]) ? $clients_by_user[$uid] : null;
            if (!$client) {
                continue;
            }

            $cid = (int) $client['client_id'];
            if (!isset($vet_aggregates[$cid])) {
                // Resolve the rate from the first order for this client.
                $rate_id       = isset($order['mealsdb_rate_id']) ? (int) $order['mealsdb_rate_id'] : 0;
                $resolved_rate = $order_query->resolve_rate_for_order($rate_id, $cid);

                $vet_aggregates[$cid] = [
                    'client'               => $client,
                    'resolved_rate'        => $resolved_rate,
                    'mains_ordered'        => 0,
                    'sides_ordered_taxable' => 0,
                    'sides_ordered_nontax' => 0,
                    'sides_cost'           => 0.0,
                    'sides_tax'            => 0.0,
                ];
            }

            foreach ($order['items'] as $item) {
                $pid  = (int) $item['wc_product_id'];
                $qty  = (float) $item['quantity'];
                $prod = isset($product_types[$pid]) ? $product_types[$pid] : null;

                $ptype  = $prod ? $prod['product_type'] : 'meal';
                $is_tax = $prod ? !empty($prod['taxable']) : false;

                if ($ptype === 'meal') {
                    $vet_aggregates[$cid]['mains_ordered'] += $qty;
                } elseif ($ptype === 'side') {
                    if ($is_tax) {
                        $vet_aggregates[$cid]['sides_ordered_taxable'] += $qty;
                        $vet_aggregates[$cid]['sides_cost'] += (float) $item['line_subtotal'];
                        $vet_aggregates[$cid]['sides_tax']  += (float) $item['line_tax'];
                    } else {
                        $vet_aggregates[$cid]['sides_ordered_nontax'] += $qty;
                    }
                }
            }
        }

        // Sort by last_name, first_name.
        uasort($vet_aggregates, function ($a, $b) {
            $cmp = strcmp($a['client']['last_name'] ?? '', $b['client']['last_name'] ?? '');
            return $cmp !== 0 ? $cmp : strcmp($a['client']['first_name'] ?? '', $b['client']['first_name'] ?? '');
        });

        $csv = [];

        // Header row
        $csv[] = 'K#,Client Last Name,Client First Name,Billing Address 1,Billing City,Billing Postcode,Billing Phone,Unit Type,Rate,Mains Ordered,Mains Allowance,Bill Mains,BNM Mains,Sides Ordered,Sides Allowance,Desserts,Muffin,Total Tax Sides Ordered,Bill Tax Sides,Overage Tax Sides,Remaining Sides,Cereal,Soup,Total Non-Tax Sides Ordered,Bill Non-Taxable Sides,Overage Non Taxable Sides,Bill Sides,Service,Monthly Allowance,Vet Mains Cost,Allowance Remaining,Sides Cost,Bill HST,New Total,Errors,New User flag';

        foreach ($vet_aggregates as $agg) {
            $vet = $agg['client'];

            // Decrypt vet_health_card (encrypted PII field).
            $health_card = '';
            if (!empty($vet['vet_health_card'])) {
                try {
                    $health_card = MealsDB_Encryption::decrypt($vet['vet_health_card']);
                } catch (Exception $e) {
                    $health_card = '';
                }
            }

            // Build billing address from component fields.
            $billing_address = '';
            if (!empty($vet['apartment_number'])) {
                $billing_address .= $vet['apartment_number'] . ' - ';
            }
            $billing_address .= ($vet['street_number'] ?? '') . ' ' . ($vet['street_name'] ?? '');
            $billing_address  = trim($billing_address);

            $billing_city    = $vet['city'] ?? '';
            $billing_postcode = $vet['postal_code'] ?? '';
            $billing_phone   = $vet['client_phone_1'] ?? '';

            $resolved_rate         = $agg['resolved_rate'];
            $mains_ordered         = $agg['mains_ordered'];
            $sides_ordered_taxable = $agg['sides_ordered_taxable'];
            $sides_ordered_nontax  = $agg['sides_ordered_nontax'];
            $sides_cost            = $agg['sides_cost'];
            $sides_tax             = $agg['sides_tax'];

            // --- Veteran allowance calculation ---
            $user_mains   = (int) ($vet['allowance_mains'] ?? 0);
            $user_sides   = (int) ($vet['allowance_sides'] ?? 0);
            $service      = strtolower($vet['requisition_period'] ?: 'week');
            $days_in_month = (int) date('t', strtotime($end_date));

            // Calculate mains allowance from service frequency.
            $mains_allowance     = 0;
            $sides_allowance_raw = 0;

            switch ($service) {
                case 'month':
                    $mains_allowance     = min($user_mains, $days_in_month);
                    $sides_allowance_raw = min($user_sides, $days_in_month);
                    break;
                case 'day':
                    $mains_allowance     = $user_mains * $days_in_month;
                    $sides_allowance_raw = $user_sides * $days_in_month;
                    break;
                case 'week':
                default:
                    if ($user_mains == 7) {
                        $mains_allowance = $days_in_month;
                    } elseif ($user_mains == 14) {
                        $mains_allowance = 2 * $days_in_month;
                    } elseif ($user_mains <= 6) {
                        $mains_allowance = $user_mains * 4;
                    }
                    if ($user_sides == 7) {
                        $sides_allowance_raw = $days_in_month;
                    } elseif ($user_sides == 14) {
                        $sides_allowance_raw = 2 * $days_in_month;
                    } elseif ($user_sides <= 6) {
                        $sides_allowance_raw = $user_sides * 4;
                    }
                    break;
            }

            // 5-week month corrections.
            if ($mains_allowance == 35) { $mains_allowance = 31; }
            elseif ($mains_allowance == 70) { $mains_allowance = 62; }
            if ($sides_allowance_raw == 35) { $sides_allowance_raw = 31; }
            elseif ($sides_allowance_raw == 70) { $sides_allowance_raw = 62; }

            // Mains billing.
            $bill_mains     = min($mains_ordered, $mains_allowance);
            $bnm_mains      = max(0, $mains_ordered - $mains_allowance);
            $vet_mains_cost = $bill_mains * $resolved_rate;

            // Monetary allowance → sides conversion.
            $monthly_allowance   = $mains_allowance * self::$vac_billing['per_main_allowance'];
            $allowance_remaining = max(0, $monthly_allowance - $vet_mains_cost);
            $new_sides           = max(0, (int) floor($allowance_remaining / self::$vac_billing['sides_conversion_rate']));

            // Use the derived sides count as the actual sides allowance.
            $sides_allowance = $new_sides;

            // Taxable sides first against the derived allowance.
            $bill_tax_sides       = min($sides_ordered_taxable, $sides_allowance);
            $overage_tax_sides    = max(0, $sides_ordered_taxable - $sides_allowance);
            $remaining_sides      = max(0, $sides_allowance - $bill_tax_sides);

            // Non-taxable sides fill the remainder.
            $bill_nontax_sides    = min($sides_ordered_nontax, $remaining_sides);
            $overage_nontax_sides = max(0, $sides_ordered_nontax - $bill_nontax_sides);

            $bill_sides = $bill_tax_sides + $bill_nontax_sides;

            // Cost calculations.
            $sides_cost = ($bill_tax_sides + $bill_nontax_sides) * self::$vac_billing['sides_cost_rate'];
            $sides_tax  = round(($bill_tax_sides * self::$vac_billing['sides_cost_rate']) * self::$vac_billing['sides_hst_rate'], 2);
            $new_total  = $vet_mains_cost + $sides_cost + $sides_tax;

            // Check for errors/warnings
            $errors = self::validate_client_row($vet, 'Veteran', $vet_duplicate_counts, 1);

            $csv[] = sprintf(
                '%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s',
                $health_card,
                $vet['last_name'] ?: '',
                $vet['first_name'] ?: '',
                str_replace(',', '', $billing_address), // Remove commas from address
                $billing_city ?: '',
                $billing_postcode ?: '',
                $billing_phone ?: '',
                'Meal',
                number_format($resolved_rate, 2, '.', ''),
                $mains_ordered,
                $mains_allowance,
                $bill_mains,
                $bnm_mains,
                $sides_ordered_taxable,
                $sides_allowance,
                0, // Desserts (track separately if needed)
                0, // Muffins (track separately if needed)
                $sides_ordered_taxable,
                $bill_tax_sides,
                $overage_tax_sides,
                $remaining_sides,
                0, // Cereal (track separately if needed)
                $sides_ordered_nontax, // Soup counted as non-tax sides
                $sides_ordered_nontax,
                $bill_nontax_sides,
                $overage_nontax_sides,
                $bill_sides,
                $service,
                number_format($monthly_allowance, 2, '.', ''),
                number_format($vet_mains_cost, 2, '.', ''),
                number_format($allowance_remaining, 2, '.', ''),
                number_format($sides_cost, 2, '.', ''),
                number_format($sides_tax, 2, '.', ''),
                number_format($new_total, 2, '.', ''),
                $errors,
                self::check_new_user_flag((int) ($vet['wp_user_id'] ?? 0), $start_date, $end_date) ?: 'No'
            );
        }

        return implode("\n", $csv);
    }

    /**
     * Generate VAC PDF Invoice
     *
     * Uses TCPDF library to generate multi-page PDF (one page per veteran)
     *
     * @param string $start_date Start date (Y-m-d format)
     * @param string $end_date End date (Y-m-d format)
     * @return string PDF file path (temporary file)
     */
    public static function generate_vac_pdf($start_date, $end_date) {
        // Check if TCPDF is available
        if (!class_exists('TCPDF')) {
            // Try to load WordPress bundled TCPDF if available
            $tcpdf_path = ABSPATH . 'wp-includes/class-tcpdf.php';
            if (file_exists($tcpdf_path)) {
                require_once($tcpdf_path);
            } else {
                throw new Exception('TCPDF library not found. Please install TCPDF to generate PDF invoices.');
            }
        }

        // Get CSV data and parse it
        $csv_content = self::generate_vac_csv($start_date, $end_date);
        $lines = explode("\n", $csv_content);
        $headers = str_getcsv(array_shift($lines)); // Remove header row

        // Create PDF
        $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('Meals DB');
        $pdf->SetAuthor(self::VENDOR_NAME);
        $pdf->SetTitle('VAC Invoice - ' . date('Y-m-d'));
        $pdf->SetSubject('Veterans Affairs Canada Invoice');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Set font
        $pdf->SetFont('helvetica', '', 10);

        // Process each veteran (one page per veteran)
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;

            $data = str_getcsv($line);

            // Add a page for this veteran
            $pdf->AddPage();

            // Title
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->Cell(0, 10, 'Veterans Affairs Canada - Meal Invoice', 0, 1, 'C');
            $pdf->Ln(5);

            // Veteran Information
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 8, 'Veteran Information', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);

            $pdf->Cell(50, 6, 'Health Card #:', 0, 0);
            $pdf->Cell(0, 6, $data[0], 0, 1);

            $pdf->Cell(50, 6, 'Name:', 0, 0);
            $pdf->Cell(0, 6, $data[2] . ' ' . $data[1], 0, 1);

            $pdf->Cell(50, 6, 'Address:', 0, 0);
            $pdf->Cell(0, 6, $data[3], 0, 1);

            $pdf->Cell(50, 6, 'City:', 0, 0);
            $pdf->Cell(0, 6, $data[4] . ', ' . $data[5], 0, 1);

            $pdf->Cell(50, 6, 'Phone:', 0, 0);
            $pdf->Cell(0, 6, $data[6], 0, 1);

            $pdf->Ln(5);

            // Billing Period
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 8, 'Billing Period', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 6, $start_date . ' to ' . $end_date, 0, 1);
            $pdf->Ln(5);

            // Meal Details
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 8, 'Meal Details', 0, 1, 'L');

            // Table header
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(220, 220, 220);
            $pdf->Cell(60, 6, 'Description', 1, 0, 'L', true);
            $pdf->Cell(30, 6, 'Ordered', 1, 0, 'C', true);
            $pdf->Cell(30, 6, 'Allowance', 1, 0, 'C', true);
            $pdf->Cell(30, 6, 'Billed', 1, 0, 'C', true);
            $pdf->Cell(30, 6, 'Amount', 1, 1, 'R', true);

            // Mains row
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(60, 6, 'Main Meals', 1, 0);
            $pdf->Cell(30, 6, $data[9], 1, 0, 'C');
            $pdf->Cell(30, 6, $data[10], 1, 0, 'C');
            $pdf->Cell(30, 6, $data[11], 1, 0, 'C');
            $pdf->Cell(30, 6, '$' . $data[29], 1, 1, 'R');

            // Sides row
            $pdf->Cell(60, 6, 'Side Items (Taxable)', 1, 0);
            $pdf->Cell(30, 6, $data[13], 1, 0, 'C');
            $pdf->Cell(30, 6, $data[14], 1, 0, 'C');
            $pdf->Cell(30, 6, $data[26], 1, 0, 'C');
            $pdf->Cell(30, 6, '$' . $data[31], 1, 1, 'R');

            // Non-tax sides row
            $pdf->Cell(60, 6, 'Side Items (Non-Taxable)', 1, 0);
            $pdf->Cell(30, 6, $data[23], 1, 0, 'C');
            $pdf->Cell(30, 6, '-', 1, 0, 'C');
            $pdf->Cell(30, 6, $data[24], 1, 0, 'C');
            $pdf->Cell(30, 6, '-', 1, 1, 'R');

            $pdf->Ln(3);

            // Summary
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 8, 'Summary', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);

            $pdf->Cell(120, 6, 'Service Frequency:', 0, 0);
            $pdf->Cell(0, 6, ucfirst($data[27]), 0, 1);

            $pdf->Cell(120, 6, 'Monthly Allowance:', 0, 0);
            $pdf->Cell(0, 6, '$' . $data[28], 0, 1);

            $pdf->Cell(120, 6, 'Mains Cost:', 0, 0);
            $pdf->Cell(0, 6, '$' . $data[29], 0, 1);

            $pdf->Cell(120, 6, 'Sides Cost:', 0, 0);
            $pdf->Cell(0, 6, '$' . $data[31], 0, 1);

            $pdf->Cell(120, 6, 'HST:', 0, 0);
            $pdf->Cell(0, 6, '$' . $data[32], 0, 1);

            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(120, 8, 'Total Amount:', 0, 0);
            $pdf->Cell(0, 8, '$' . $data[33], 0, 1);

            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(120, 6, 'Allowance Remaining:', 0, 0);
            $pdf->Cell(0, 6, '$' . $data[30], 0, 1);

            // Errors/Notes
            if (!empty($data[34])) {
                $pdf->Ln(5);
                $pdf->SetFont('helvetica', 'B', 10);
                $pdf->SetTextColor(255, 0, 0);
                $pdf->Cell(0, 6, 'Notes: ' . $data[34], 0, 1);
                $pdf->SetTextColor(0, 0, 0);
            }
        }

        // Save to temporary file
        $temp_file = tempnam(sys_get_temp_dir(), 'vac_invoice_') . '.pdf';
        $pdf->Output($temp_file, 'F');

        return $temp_file;
    }
}
