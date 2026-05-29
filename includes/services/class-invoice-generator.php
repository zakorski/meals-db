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
     * VAC monthly allowances by service frequency
     */
    private static $vac_allowances = [
        'day' => ['mains' => 7, 'amount' => 74.48],
        'week' => ['mains' => 31, 'amount' => 329.84],
        'month' => ['mains' => 124, 'amount' => 1319.36]
    ];

    /**
     * VAC billing constants (contractual rates).
     *
     * Sourced from MealsDB_Operational_Constants so the value lives
     * in one place (directive 18 Part D wired the four literals here
     * to the corresponding constants without changing the numeric
     * values).
     */
    private static $vac_billing = [
        'per_main_allowance'     => MealsDB_Operational_Constants::VAC_PER_MAIN_ALLOWANCE,
        'sides_conversion_rate'  => MealsDB_Operational_Constants::VAC_SIDES_CONVERSION_RATE,
        'sides_cost_rate'        => MealsDB_Operational_Constants::VAC_RATE_SIDE,
        'sides_hst_rate'         => MealsDB_Operational_Constants::VAC_SIDES_HST_RATE,
    ];

    /**
     * Canonical billing data fetcher.
     *
     * Returns ONE ROW PER CLIENT for the billing month, holding what the
     * allocation engine assigned plus the contribution line-item sum for
     * the same scope. The engine already enforced the monthly allowance
     * (phase 1 fill with single-month spill), so the generators never need
     * to cap or compare to allowance anymore.
     *
     * Tax follows the allocated taxable-side count: HST = taxable sides ×
     * the (rurality-resolved) pre-tax side rate × 15%. Mains are never
     * taxed. For VAC, tax is computed downstream via
     * $vac_billing['sides_hst_rate'] (handled in the VAC generator, not here).
     *
     * @param array<int, array<string, mixed>> $client_rows  Rows from meals_clients
     *                                                       (must include client_id, wp_user_id,
     *                                                       default_rate_id, client_contribution).
     * @param string                            $billing_month YYYY-MM.
     *
     * @return array<int, array<string, mixed>> Indexed by client_id. Each row:
     *   client_id, wp_user_id, first_name, last_name, [other client cols passed through],
     *   resolved_rate (float), allocated_mains (int), allocated_tax_sides (int),
     *   allocated_nontax_sides (int), allocated_sides (int),
     *   contribution_cents (int) — summed from monthly orders' product-5675 line items,
     *   basic_cents (int), tax_cents (int) — HST per the rate tier when applicable.
     */
    private static function get_phase2_billing_data(array $client_rows, string $billing_month): array {
        if (empty($client_rows) || !preg_match('/^\d{4}-\d{2}$/', $billing_month)) {
            return [];
        }

        global $wpdb;
        $summary_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);
        $alloc_table   = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);

        // Build the client_id list once.
        $client_ids = [];
        foreach ($client_rows as $c) {
            $cid = (int) ($c['client_id'] ?? 0);
            if ($cid > 0) { $client_ids[$cid] = true; }
        }
        if (empty($client_ids)) { return []; }
        $cid_list = implode(',', array_map('intval', array_keys($client_ids)));

        // Bulk-fetch all summary rows for this (clients, month) in one shot.
        $summary_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT client_id, used_mains, used_sides, used_tax_sides, used_nontax_sides
             FROM `{$summary_table}`
             WHERE billing_month = %s AND client_id IN ({$cid_list})",
            $billing_month
        ), ARRAY_A);
        $summary_by_cid = [];
        foreach ((array) $summary_rows as $r) {
            $summary_by_cid[(int) $r['client_id']] = $r;
        }

        // Bulk-fetch the list of orders whose meals were allocated to this
        // billing month, per client. Contribution and tax both ride on
        // these orders.
        $allocated_order_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT client_id, wc_order_id
             FROM `{$alloc_table}`
             WHERE billing_month = %s AND client_id IN ({$cid_list})",
            $billing_month
        ), ARRAY_A);
        $orders_by_cid = [];
        foreach ((array) $allocated_order_rows as $r) {
            $orders_by_cid[(int) $r['client_id']][] = (int) $r['wc_order_id'];
        }

        $order_query = new MealsDB_WC_Order_Query($wpdb);

        $out = [];
        foreach ($client_rows as $client) {
            $cid = (int) ($client['client_id'] ?? 0);
            if ($cid <= 0) { continue; }

            $s = $summary_by_cid[$cid] ?? [
                'used_mains'        => 0,
                'used_sides'        => 0,
                'used_tax_sides'    => 0,
                'used_nontax_sides' => 0,
            ];

            $allocated_mains        = (int) $s['used_mains'];
            $allocated_sides        = (int) $s['used_sides'];
            $allocated_tax_sides    = (int) $s['used_tax_sides'];
            $allocated_nontax_sides = (int) $s['used_nontax_sides'];

            $rate_id       = isset($client['default_rate_id']) ? (int) $client['default_rate_id'] : 0;
            $resolved_rate = $order_query->resolve_rate_for_order($rate_id, $cid);

            // Contribution: sum of product-5675 line items across orders
            // whose meals landed in this billing month for this client.
            $contribution_cents = self::sum_contribution_for_orders($orders_by_cid[$cid] ?? []);

            // Basic = allocated_units × rate. "Allocated units" = mains for
            // most clients; legacy two-line splitting may include sides on
            // line 1 / line 2 — that lives downstream in split_into_invoice_lines.
            $basic_cents = MealsDB_Money::multiply($allocated_mains, $resolved_rate);

            // HST: taxable sides only, at the pre-tax side rate × 15%.
            // Mains are never taxed. Side rate is resolved from the
            // client's zone, NOT from the price (LB-7 — replaces the
            // obsolete net-portion multiplier table). Non-SDNB tax (VAC)
            // is computed in its own path.
            $tax_cents = 0;
            if ($allocated_tax_sides > 0) {
                $rural       = MealsDB_Operational_Constants::is_rural_zone($client['delivery_area_zone'] ?? null);
                $side_rate   = MealsDB_Operational_Constants::get_sdnb_side_rate($rural);
                $sides_cents = MealsDB_Money::multiply($allocated_tax_sides, $side_rate);
                $tax_cents   = MealsDB_Money::percent_of($sides_cents, MealsDB_Operational_Constants::HST_RATE);
            }

            $out[$cid] = array_merge($client, [
                'allocated_mains'        => $allocated_mains,
                'allocated_sides'        => $allocated_sides,
                'allocated_tax_sides'    => $allocated_tax_sides,
                'allocated_nontax_sides' => $allocated_nontax_sides,
                'resolved_rate'          => $resolved_rate,
                'contribution_cents'     => $contribution_cents,
                'basic_cents'            => $basic_cents,
                'tax_cents'              => $tax_cents,
            ]);
        }
        return $out;
    }

    /**
     * Sum the cents of product-5675 (Client Contribution) line items across
     * the given wc_order_ids. Reads WC HPOS order_itemmeta directly so it
     * runs against the same data WC uses.
     */
    private static function sum_contribution_for_orders(array $wc_order_ids): int {
        if (empty($wc_order_ids)) { return 0; }
        global $wpdb;

        $order_list = implode(',', array_map('intval', $wc_order_ids));
        $items_table = $wpdb->prefix . 'woocommerce_order_items';
        $meta_table  = $wpdb->prefix . 'woocommerce_order_itemmeta';

        // Sum line_total for items whose _product_id meta is 5675 across the orders.
        // line_total is stored as a string-formatted decimal; CAST to handle.
        $sql = "
            SELECT COALESCE(SUM(CAST(lt.meta_value AS DECIMAL(12,4))), 0)
            FROM `{$items_table}` i
            INNER JOIN `{$meta_table}` pm ON pm.order_item_id = i.order_item_id
                                          AND pm.meta_key = '_product_id'
                                          AND pm.meta_value = '5675'
            INNER JOIN `{$meta_table}` lt ON lt.order_item_id = i.order_item_id
                                          AND lt.meta_key = '_line_total'
            WHERE i.order_id IN ({$order_list})
              AND i.order_item_type = 'line_item'
        ";
        $sum_decimal = (string) $wpdb->get_var($sql);
        // Convert decimal dollars to integer cents without float drift.
        return MealsDB_Money::to_cents($sum_decimal);
    }




    /**
     * Split a client's allowance row into one or two invoice lines.
     *
     * @param array $row Single client row from get_phase2_billing_data()
     *                   adapted into the shape this method expects
     *                   (bill_mains/bill_sides/bill_tax_sides/bill_nontax_sides/
     *                   client_contribution/resolved_rate + nested 'client').
     * @return array Array of 1 or 2 invoice line arrays.
     */
    private static function split_into_invoice_lines(array $row): array {
        $rate = (float) $row['resolved_rate'];

        $client = $row['client'];
        // Rurality comes from the client's delivery zone, NOT the rate
        // value — the price must not be the source of truth for which
        // rate tier applies (LB-7). The side rate and the line-2 main
        // rate are then sourced from MealsDB_Operational_Constants.
        $rural     = MealsDB_Operational_Constants::is_rural_zone($client['delivery_area_zone'] ?? null);
        $side_rate = MealsDB_Operational_Constants::get_sdnb_side_rate($rural);

        $bill_mains        = (int) $row['bill_mains'];
        $bill_sides        = (int) $row['bill_sides'];
        $bill_tax_sides    = (int) $row['bill_tax_sides'];
        $bill_nontax_sides = (int) $row['bill_nontax_sides'];
        $client_contribution_cents = MealsDB_Money::to_cents($row['client_contribution'] ?? 0);

        // Line 1 calculations.
        $mains_on_line_1 = ($bill_sides == 0) ? $bill_mains : min($bill_mains, $bill_sides);
        $tax_sides_on_line_1 = ($bill_sides == 0 || $bill_tax_sides == 0)
            ? 0 : min($mains_on_line_1, $bill_tax_sides);
        $nontax_sides_on_line_1 = ($bill_sides == 0 || $bill_nontax_sides == 0)
            ? 0 : min($mains_on_line_1 - $tax_sides_on_line_1, $bill_nontax_sides);
        // HST per line: taxable sides × pre-tax side rate × 15%. (LB-7)
        $hst_line_1_cents = ($tax_sides_on_line_1 > 0)
            ? MealsDB_Money::percent_of(MealsDB_Money::multiply($tax_sides_on_line_1, $side_rate), MealsDB_Operational_Constants::HST_RATE)
            : 0;

        // Line 2 calculations.
        $mains_on_line_2        = max(0, $bill_mains - $mains_on_line_1);
        $tax_sides_on_line_2    = $bill_tax_sides - $tax_sides_on_line_1;
        $nontax_sides_on_line_2 = $bill_nontax_sides - $nontax_sides_on_line_1;
        $hst_line_2_cents = ($tax_sides_on_line_2 > 0)
            ? MealsDB_Money::percent_of(MealsDB_Money::multiply($tax_sides_on_line_2, $side_rate), MealsDB_Operational_Constants::HST_RATE)
            : 0;

        $has_second_line = ($mains_on_line_2 + $tax_sides_on_line_2 + $nontax_sides_on_line_2 + $hst_line_2_cents) > 0;

        // Line-2 rate from constants, not the deleted tier table. (LB-7)
        // A line-2 carrying mains bills at the secondary main rate; a
        // sides-only line-2 bills at the side rate.
        $second_line_rate = 0;
        if ($has_second_line) {
            $second_line_rate = ($mains_on_line_2 > 0)
                ? MealsDB_Operational_Constants::get_sdnb_main_rate('secondary', $rural)
                : (($tax_sides_on_line_2 + $nontax_sides_on_line_2 > 0)
                    ? $side_rate
                    : 0);
        }

        $lines = [];

        // Line 1.
        $units_l1 = $mains_on_line_1;
        $lines[] = [
            'service_id'                => $client['service_id'] ?? '',
            'requisition_id'            => $client['requisition_id'] ?? '',
            'individual_id'             => $client['individual_id'] ?? '',
            'last_name'                 => $client['last_name'] ?? '',
            'first_name'                => $client['first_name'] ?? '',
            'units'                     => $units_l1,
            'unit_type'                 => 'Meal',
            'rate'                      => $rate,
            'basic_cost_cents'          => MealsDB_Money::multiply($units_l1, $rate),
            'client_contribution_cents' => $client_contribution_cents,
            'tax_cents'                 => $hst_line_1_cents,
        ];

        // Line 2 (if needed).
        if ($has_second_line) {
            $units_l2 = $mains_on_line_2 + $tax_sides_on_line_2 + $nontax_sides_on_line_2;
            $lines[] = [
                'service_id'                => $client['service_id'] ?? '',
                'requisition_id'            => $client['requisition_id'] ?? '',
                'individual_id'             => $client['individual_id'] ?? '',
                'last_name'                 => $client['last_name'] ?? '',
                'first_name'                => $client['first_name'] ?? '',
                'units'                     => $units_l2,
                'unit_type'                 => 'Meal',
                'rate'                      => $second_line_rate,
                'basic_cost_cents'          => MealsDB_Money::multiply($units_l2, $second_line_rate),
                'client_contribution_cents' => 0, // Always 0 on second line
                'tax_cents'                 => $hst_line_2_cents,
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

        // Threshold check via deterministic individual_id hash.
        // "Duplicate person" was the historical wording but it's
        // misleading for legitimate multi-person households sharing
        // an individual_id across more than the threshold — which is
        // the designed-for behaviour, not an error. Reword so the
        // admin reading the error list understands what actually
        // tripped the check.
        $id_index = $client['individual_id_index'] ?? '';
        if ($id_index !== '' && isset($duplicate_counts[$id_index]) && $duplicate_counts[$id_index] > $duplicate_threshold) {
            $errors[] = 'Shared individual_id exceeds household threshold';
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

        // `user_registered` is stored in UTC. The billing period boundaries
        // are typed by a human in site-local time. Parse each in its own
        // timezone, then compare — PHP DateTime handles the cross-tz
        // comparison correctly. Without this, a server set to a non-UTC
        // default timezone re-interprets `user_registered` as local time
        // and can drop the registration into the wrong billing month.
        $site_tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get());
        $utc_tz  = new DateTimeZone('UTC');

        try {
            $registered   = new DateTime($user->user_registered, $utc_tz);
            $period_start = new DateTime($start_date . ' 00:00:00', $site_tz);
            $period_end   = new DateTime($end_date . ' 23:59:59', $site_tz);
        } catch (Exception $e) {
            return '';
        }

        if ($registered >= $period_start && $registered <= $period_end) {
            // Render the registration date in the site timezone so the
            // flag text matches what the user sees in wp-admin.
            $registered->setTimezone($site_tz);
            return 'New - account - user created on ' . $registered->format('Y-m-d');
        }

        return '';
    }

    /**
     * Phase 1 integration: rebuild any dirty (client_id, billing_month)
     * entries for the clients on this invoice for this billing month.
     * Scope A — only the clients matching the generator's own filter, only
     * this month — so other invoices' clients are not touched.
     *
     * @param string                          $start_date  YYYY-MM-DD (first day of the billing month).
     * @param array<int, array<string,mixed>> $client_rows Already-filtered client rows.
     */
    private static function rebuild_dirty_for_invoice(string $start_date, array $client_rows): void {
        if (empty($client_rows) || !preg_match('/^(\d{4}-\d{2})/', $start_date, $m)) {
            return;
        }
        $billing_month = $m[1];

        $client_ids = [];
        foreach ($client_rows as $c) {
            $cid = (int) ($c['client_id'] ?? 0);
            if ($cid > 0) {
                $client_ids[$cid] = true;
            }
        }
        if (empty($client_ids)) {
            return;
        }

        (new MealsDB_Allocation_Rebuilder())->rebuild_for_invoice($billing_month, array_keys($client_ids));
    }

    /**
     * Query meals_clients from the DB with a prepared statement.
     *
     * @param string $sql    SQL with %s/%d placeholders (wpdb format).
     * @param array  $params Parameters to bind (empty array if none).
     *
     * @return array Client rows.
     */
    private static function query_clients(string $sql, array $params = []): array {
        global $wpdb;

        if (!empty($params)) {
            $prepared = $wpdb->prepare($sql, ...$params);
        } else {
            $prepared = $sql;
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
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

        // Format invoice number: "2025 Jan 31 M". $end_date is a user-typed
        // Y-m-d string, so parse it in the site timezone rather than the
        // server default (which on some hosts is UTC and can shift the
        // label across the midnight boundary).
        $site_tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get());
        try {
            $end_date_obj = new DateTime($end_date, $site_tz);
        } catch (Exception $e) {
            $end_date_obj = new DateTime('now', $site_tz);
        }
        $invoice_number = $end_date_obj->format('Y M d') . ' ' . $zone;

        // Query eligible clients from external DB.
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $sql = "SELECT client_id, wp_user_id, first_name, last_name, service_id, requisition_id,
                    individual_id, individual_id_index, client_contribution, delivery_area_zone,
                    default_rate_id, allowance_mains, allowance_sides, requisition_period
             FROM `{$clients_table}`
             WHERE client_type = %s AND use_legacy_billing = 1
               AND delivery_area_zone = %s AND active = 1 AND wp_user_id > 0";

        $client_type = 'SDNB';
        $client_rows = self::query_clients($sql, [$client_type, $zone]);

        // Phase 1: before computing this invoice, rebuild any dirty
        // client-months for the clients in this filter (scope A: only the
        // clients on THIS invoice for THIS month).
        self::rebuild_dirty_for_invoice($start_date, $client_rows);

        // Decrypt encrypted PII fields.
        foreach ($client_rows as &$c) {
            foreach (['requisition_id', 'individual_id'] as $field) {
                if (!empty($c[$field])) {
                    $c[$field] = MealsDB_Encryption::safe_decrypt($c[$field]);
                }
            }
        }
        unset($c);

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

        // Phase 2: bill what the allocation engine assigned to this month.
        // No min(used, permitted) cap — the engine's fill (phase 1) already
        // enforced allowance, so allocated_* IS the billable count.
        $billing_month  = substr($start_date, 0, 7);
        $billing_by_cid = self::get_phase2_billing_data($client_rows, $billing_month);

        // Adapt phase-2 rows into the shape split_into_invoice_lines expects:
        // bill_mains / bill_sides / bill_tax_sides / bill_nontax_sides come
        // straight from allocated_* (the engine's monthly summary).
        $invoice_rows = [];
        foreach ($client_rows as $c) {
            $cid = (int) ($c['client_id'] ?? 0);
            $b   = $billing_by_cid[$cid] ?? null;
            if (!$b || (int) $b['allocated_mains'] <= 0) {
                continue; // No allocation in this month → no line.
            }
            $invoice_rows[] = [
                'client'              => $c,
                'resolved_rate'       => $b['resolved_rate'],
                'bill_mains'          => (int) $b['allocated_mains'],
                'bill_sides'          => (int) $b['allocated_sides'],
                'bill_tax_sides'      => (int) $b['allocated_tax_sides'],
                'bill_nontax_sides'   => (int) $b['allocated_nontax_sides'],
                // Contribution is the sum of product-5675 line items on
                // orders allocated to this month. Stored on the row as a
                // float so the existing split_into_invoice_lines path
                // (which converts back to cents via to_cents) works
                // unchanged.
                'client_contribution' => (int) $b['contribution_cents'] / 100,
            ];
        }

        // Apply two-line splits to get final invoice lines.
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

        // Accumulate totals for header, in integer cents.
        // Summing many rounded floats drifts; the header total must agree
        // with the penny-exact sum of every line's (basic + tax − contribution).
        $total_invoice_amount_cents = 0;
        $total_tax_amount_cents     = 0;
        foreach ($all_invoice_lines as $line) {
            $line_total_cents = $line['basic_cost_cents'] + $line['tax_cents'] - $line['client_contribution_cents'];
            $total_invoice_amount_cents += $line_total_cents;
            $total_tax_amount_cents     += $line['tax_cents'];
        }

        // Build CSV content.
        $csv = [];

        // Row 1: Blank row with commas (unchanged from current implementation)
        $csv[] = str_repeat(',', 99);

        // Row 3: Header with version (unchanged)
        //
        // The four top-of-file rows below were previously concatenated
        // with implode(',', $rowN). That broke on any value containing
        // a comma (notably the service-centre addresses with street
        // names like "770 Main Street Assumption PL., 5th Floor,
        // Moncton NB E1C 8R3") — the row's cells silently shifted right
        // and misaligned every downstream column. Routing through
        // MealsDB_CSV::row() fixes the RFC-4180 quoting AND
        // neutralises formula-injection triggers in the same pass.
        $row3 = array_fill(0, 100, '');
        $row3[0] = '1';
        $row3[1] = 'Social Development';
        $row3[5] = 'Electronic Invoice Datasheet';
        $row3[9] = 'version 36e';
        $csv[] = MealsDB_CSV::row($row3);

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
        $csv[] = MealsDB_CSV::row($row4);

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
        $row5[16] = MealsDB_Money::format($total_tax_amount_cents);
        $row5[17] = MealsDB_Money::format($total_invoice_amount_cents);
        $row5[18] = self::CONTACT_PERSON;
        $row5[20] = self::CONTACT_AREA_CODE;
        $row5[21] = self::CONTACT_PHONE;
        $row5[22] = self::CONTACT_EMAIL;
        $row5[23] = count($all_invoice_lines);
        $row5[24] = 'F';
        $csv[] = MealsDB_CSV::row($row5);

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
        $csv[] = MealsDB_CSV::row($row6);

        // Data rows — one per invoice line.
        foreach ($all_invoice_lines as $line) {
            $basic_cost_cents  = (int) $line['basic_cost_cents'];
            $tax_cents         = (int) $line['tax_cents'];
            $contribution_cents = (int) $line['client_contribution_cents'];
            $total_line_cost_cents = $basic_cost_cents + $tax_cents - $contribution_cents;

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
            $row[9]  = MealsDB_Money::format($basic_cost_cents);
            $row[23] = MealsDB_Money::format($contribution_cents);
            // Dept. Cost (col 25 in the spec / row[24] zero-indexed) is
            // Basic Cost minus Client Contribution — what the department
            // (government) pays. Confirmed against Janet's Jan 2025 Moncton
            // submission (Brammah: basic 366.50, contrib 10.24, dept 356.26).
            $row[24] = MealsDB_Money::format($basic_cost_cents - $contribution_cents);
            $row[27] = number_format(0, 2, '.', '');
            $row[30] = number_format(0, 2, '.', '');
            $row[33] = number_format(0, 2, '.', '');
            $row[34] = MealsDB_Money::format($tax_cents);
            $row[35] = MealsDB_Money::format($total_line_cost_cents);
            $row[36] = 'I';
            $csv[] = MealsDB_CSV::row($row);
        }

        // Finalize the billing month for all included clients.
        $billing_month = substr($start_date, 0, 7);
        $engine = new MealsDB_Allocation_Engine();
        foreach ($client_rows as $client) {
            $engine->finalize_month((int) $client['client_id'], $billing_month);
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
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        // delivery_area_zone is required so HST resolves the correct
        // (urban vs rural) side rate in get_phase2_billing_data — without
        // it, Sussex/rural clients with taxable sides would silently bill
        // at the urban side rate and under-report HST (LB-7).
        $sql = "SELECT client_id, wp_user_id, first_name, last_name, sdnb_service_request_id,
                    client_contribution, default_rate_id, delivery_area_zone
             FROM `{$clients_table}`
             WHERE client_type = %s AND use_legacy_billing = 0
               AND active = 1 AND wp_user_id > 0";

        $client_type = 'SDNB';
        $client_rows = self::query_clients($sql, [$client_type]);

        // Phase 1: pre-rebuild dirty client-months in this filter (scope A).
        self::rebuild_dirty_for_invoice($start_date, $client_rows);

        // Phase 2: read allocated quantities + contribution-line sum + tax
        // from the engine summary. One row per client.
        $billing_month   = substr($start_date, 0, 7);
        $billing_by_cid  = self::get_phase2_billing_data($client_rows, $billing_month);

        $csv = [];

        // Header row — 18 columns, matches Janet's Nov 2025 submission.
        $csv[] = 'Service Confirmation Item Id,Product Name,Service Request Id,Client Name,No. Of Units,Unit Type,Rate,Kilometres,Kilometre Rate,Other Cost (transportation),Other Cost (meals),Other Cost (sundry),Other Cost (admin fees),Other Cost (recreation),Other Cost (parking),Client Contribution,Stat Holiday Units,Tax';

        // Sort clients by name for stable output.
        usort($client_rows, function ($a, $b) {
            $na = strtoupper(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
            $nb = strtoupper(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
            return strcmp($na, $nb);
        });

        foreach ($client_rows as $c) {
            $cid = (int) ($c['client_id'] ?? 0);
            $b   = $billing_by_cid[$cid] ?? null;
            if (!$b) {
                continue; // No allocation in this month → no invoice line.
            }
            $allocated_mains = (int) $b['allocated_mains'];
            if ($allocated_mains <= 0) {
                continue; // Skip zero-meal rows; nothing to bill.
            }

            $client_name = strtoupper($c['first_name'] ?? '') . ' ' . strtoupper($c['last_name'] ?? '');

            // The new-portal CSV has no Total column — the portal computes
            // the total from Units, Rate, Contribution, Tax. The plugin
            // emits those four as separate fields and lets the portal do
            // the math. (Confirmed against Janet's Nov 2025 submission.)
            $csv[] = MealsDB_CSV::row([
                '', // Service Confirmation Item Id — assigned by the SDNB portal on upload, left blank.
                'Meal Services - Services de repas',
                $c['sdnb_service_request_id'] ?: '',
                $client_name,
                $allocated_mains,
                'Meal',
                number_format((float) $b['resolved_rate'], 2, '.', ''),
                '', // Kilometres
                '', // Kilometre Rate
                '', // Other Cost (transportation)
                '', // Other Cost (meals)
                '', // Other Cost (sundry)
                '', // Other Cost (admin fees)
                '', // Other Cost (recreation)
                '', // Other Cost (parking)
                (int) $b['contribution_cents'] > 0 ? MealsDB_Money::format((int) $b['contribution_cents']) : '',
                '', // Stat Holiday Units
                (int) $b['tax_cents'] > 0 ? MealsDB_Money::format((int) $b['tax_cents']) : '0',
            ]);
        }

        // Finalize the billing month for all included clients.
        $engine = new MealsDB_Allocation_Engine();
        foreach ($client_rows as $client) {
            $engine->finalize_month((int) $client['client_id'], $billing_month);
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
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $sql = "SELECT client_id, wp_user_id, first_name, last_name, requisition_id,
                    vet_health_card, requisition_period, client_contribution, default_rate_id,
                    street_name, city, postal_code, client_phone_1,
                    allowance_mains, allowance_sides, individual_id, individual_id_index
             FROM `{$clients_table}`
             WHERE client_type = %s AND active = 1 AND wp_user_id > 0";

        $client_type = 'Veteran';
        $client_rows = self::query_clients($sql, [$client_type]);

        // Phase 1: pre-rebuild dirty client-months in this filter (scope A).
        self::rebuild_dirty_for_invoice($start_date, $client_rows);

        // Decrypt encrypted PII fields.
        foreach ($client_rows as &$c) {
            foreach (['requisition_id', 'individual_id'] as $field) {
                if (!empty($c[$field])) {
                    $c[$field] = MealsDB_Encryption::safe_decrypt($c[$field]);
                }
            }
        }
        unset($c);

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

        // Phase 2: bill what the engine allocated to this month.
        // No min/cap, no overage, no contribution subtraction (per VAC
        // per old vet-invoice line 521: new_total = mains_cost + sides_cost + HST).
        $billing_month   = substr($start_date, 0, 7);
        $billing_by_cid  = self::get_phase2_billing_data($client_rows, $billing_month);

        $engine      = new MealsDB_Allocation_Engine();
        $order_query = new MealsDB_WC_Order_Query($GLOBALS['wpdb']);

        $vet_aggregates = [];
        foreach ($client_rows as $client) {
            $cid = (int) $client['client_id'];
            $b   = $billing_by_cid[$cid] ?? null;
            if (!$b) {
                continue; // no allocation this month -> no row
            }

            $vet_aggregates[$cid] = [
                'client'                => $client,
                'resolved_rate'         => $b['resolved_rate'],
                'allocated_mains'       => (int) $b['allocated_mains'],
                'allocated_tax_sides'   => (int) $b['allocated_tax_sides'],
                'allocated_nontax_sides'=> (int) $b['allocated_nontax_sides'],
                // For info columns only (Monthly Allowance / Allowance Remaining):
                // expose the engine's monthly permitted figure as context.
                'permitted_for_info'    => $engine->calculate_permitted_for_month($cid, $billing_month),
            ];
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
                $health_card = MealsDB_Encryption::safe_decrypt($vet['vet_health_card']);
            }

            $billing_address  = trim($vet['street_name'] ?? '');
            $billing_city     = $vet['city'] ?? '';
            $billing_postcode = $vet['postal_code'] ?? '';
            $billing_phone    = $vet['client_phone_1'] ?? '';
            $service          = strtolower($vet['requisition_period'] ?: 'week');

            $resolved_rate          = $agg['resolved_rate'];
            $allocated_mains        = $agg['allocated_mains'];
            $allocated_tax_sides    = $agg['allocated_tax_sides'];
            $allocated_nontax_sides = $agg['allocated_nontax_sides'];

            // Phase 2: bill the allocated quantities. No caps at this layer
            // (the engine's fill already enforced allowance, with spill to
            // next month or a logged error if a delivery overran both
            // months). bnm_mains / overage_* are therefore always 0.
            $bill_mains           = $allocated_mains;
            $bill_tax_sides       = $allocated_tax_sides;
            $bill_nontax_sides    = $allocated_nontax_sides;
            $bill_sides           = $bill_tax_sides + $bill_nontax_sides;
            $bnm_mains            = 0;
            $overage_tax_sides    = 0;
            $overage_nontax_sides = 0;

            // VAC cost components.
            $vet_mains_cost_cents = MealsDB_Money::multiply($bill_mains, $resolved_rate);
            $sides_cost_cents     = MealsDB_Money::multiply($bill_sides, self::$vac_billing['sides_cost_rate']);
            $tax_sides_base_cents = MealsDB_Money::multiply($bill_tax_sides, self::$vac_billing['sides_cost_rate']);
            $sides_tax_cents      = MealsDB_Money::percent_of($tax_sides_base_cents, self::$vac_billing['sides_hst_rate']);
            // VAC new_total = mains_cost + sides_cost + HST  (confirmed
            // against old vet-invoice: NO contribution subtraction).
            $new_total_cents      = $vet_mains_cost_cents + $sides_cost_cents + $sides_tax_cents;

            // Informational columns (not used in billing decisions anymore).
            $permitted          = $agg['permitted_for_info'];
            $mains_allowance    = (int) ($permitted['permitted_mains'] ?? 0);
            $sides_allowance    = (int) ($permitted['permitted_sides'] ?? 0);
            $remaining_sides    = max(0, $sides_allowance - $bill_tax_sides);
            // Keep the "Monthly Allowance" dollar field for compatibility
            // with the existing column layout — derived from the mains cap.
            $monthly_allowance_cents   = MealsDB_Money::multiply($mains_allowance, self::$vac_billing['per_main_allowance']);
            $allowance_remaining_cents = max(0, $monthly_allowance_cents - $vet_mains_cost_cents);

            $errors = self::validate_client_row($vet, 'Veteran', $vet_duplicate_counts, 1);

            $csv[] = MealsDB_CSV::row([
                $health_card,
                $vet['last_name'] ?: '',
                $vet['first_name'] ?: '',
                $billing_address, // MealsDB_CSV::cell() handles embedded commas via quoting.
                $billing_city ?: '',
                $billing_postcode ?: '',
                $billing_phone ?: '',
                'Meal',
                number_format($resolved_rate, 2, '.', ''),
                $allocated_mains,     // Mains Ordered (under phase 2: what the engine allocated)
                $mains_allowance,
                $bill_mains,
                $bnm_mains,
                $allocated_tax_sides + $allocated_nontax_sides, // Sides Ordered
                $sides_allowance,
                0, // Desserts (track separately if needed)
                0, // Muffins (track separately if needed)
                $allocated_tax_sides, // Total Tax Sides Ordered
                $bill_tax_sides,
                $overage_tax_sides,
                $remaining_sides,
                0, // Cereal (track separately if needed)
                $allocated_nontax_sides, // Soup
                $allocated_nontax_sides, // Total Non-Tax Sides Ordered
                $bill_nontax_sides,
                $overage_nontax_sides,
                $bill_sides,
                $service,
                MealsDB_Money::format($monthly_allowance_cents),
                MealsDB_Money::format($vet_mains_cost_cents),
                MealsDB_Money::format($allowance_remaining_cents),
                MealsDB_Money::format($sides_cost_cents),
                MealsDB_Money::format($sides_tax_cents),
                MealsDB_Money::format($new_total_cents),
                $errors,
                self::check_new_user_flag((int) ($vet['wp_user_id'] ?? 0), $start_date, $end_date) ?: 'No',
            ]);
        }

        // Finalize the billing month for all included clients.
        foreach ($client_rows as $client) {
            $engine->finalize_month((int) $client['client_id'], $billing_month);
        }

        return implode("\n", $csv);
    }

    /**
     * Generate the VAC reimbursement PDF — one form per veteran, merged into
     * a single multi-page Legal-size PDF for submission to Blue Cross /
     * Veterans Affairs.
     *
     * STAGE 2 of the VAC pipeline: generate_vac_csv (phase 2) produces the
     * data CSV; this method stamps each row onto a pre-printed Blue Cross
     * "Provider Reimbursement Form / Access to Nutrition" template.
     *
     * Approach: HTML + CSS absolute positioning rendered by dompdf, with the
     * blank Blue Cross form as a full-page background image. Field
     * coordinates are ported from the legacy print.php (FPDF) generator —
     * the background image is the same 2550x4200 px (Legal ratio) scan it
     * was calibrated against, so positions transfer 1:1 in Legal points.
     *
     * Column mapping into the phase-2 VAC CSV (one row per veteran):
     *   data[0]  K# (Health Identification Card no)
     *   data[1]  Client Last Name
     *   data[2]  Client First Name
     *   data[3]  Billing Address 1
     *   data[4]  Billing City
     *   data[5]  Billing Postcode
     *   data[6]  Billing Phone
     *   data[11] Bill Mains (Number of Meals)
     *   data[32] Bill HST
     *   data[33] New Total
     *
     * @param string $start_date YYYY-MM-DD (first day of billing month).
     * @param string $end_date   YYYY-MM-DD (last day of billing month —
     *                           also stamped as the date of service / signature date).
     * @return string PDF bytes (caller writes to disk or streams).
     */
    public static function generate_vac_pdf($start_date, $end_date) {
        if (!class_exists('Dompdf\\Dompdf')) {
            throw new RuntimeException(
                'DomPDF is not available — run `composer install` in the meals-db plugin directory.'
            );
        }

        // Date-of-service / signature date in DD/MM/YY format (same as the
        // pre-printed form expects).
        $site_tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get());
        try {
            $end_date_obj = new DateTime($end_date, $site_tz);
        } catch (Exception $e) {
            $end_date_obj = new DateTime('now', $site_tz);
        }
        $formatted_date = $end_date_obj->format('d/m/y');

        // Pull the phase-2 VAC CSV as our data source. Parsing it back into
        // rows keeps stage-2 a pure renderer — the same CSV the operator can
        // download is what feeds the PDF.
        $csv_content = self::generate_vac_csv($start_date, $end_date);
        $lines       = explode("\n", $csv_content);
        if (!empty($lines)) {
            array_shift($lines); // drop header
        }

        // Background-image path. dompdf chroot constrains where the renderer
        // can read files from; the image MUST live under MEALS_DB_PLUGIN_DIR.
        $plugin_dir = defined('MEALS_DB_PLUGIN_DIR') ? MEALS_DB_PLUGIN_DIR : (dirname(__DIR__, 2) . '/');
        $bg_path    = $plugin_dir . 'assets/images/vac-blue-cross-form.jpg';
        if (!file_exists($bg_path)) {
            throw new RuntimeException(
                'VAC form background image not found at ' . $bg_path
            );
        }

        // Parse the CSV lines into structured rows; the HTML builder is
        // testable independently of dompdf and the database.
        $data_rows = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            $data = str_getcsv($line);
            if (count($data) < 34) { continue; }
            $data_rows[] = $data;
        }
        if (empty($data_rows)) {
            return ''; // nothing to render
        }

        $bg_url = 'file://' . $bg_path;
        $html   = self::build_vac_pdf_html($data_rows, $formatted_date, $bg_url);

        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        // The background-image url uses file:// — dompdf requires either
        // isRemoteEnabled or a chroot that includes the file path. The
        // image lives under MEALS_DB_PLUGIN_DIR; chroot to that dir lets
        // dompdf read it without permitting arbitrary remote fetches.
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', $plugin_dir);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0, 0, 612, 1008], 'portrait'); // Legal: 8.5" x 14"
        $dompdf->render();

        $output = $dompdf->output();
        return is_string($output) ? $output : '';
    }

    /**
     * Build the multi-page HTML document that dompdf renders into the VAC
     * PDF. Extracted from generate_vac_pdf() so it can be tested without
     * touching the DB or dompdf.
     *
     * Field coordinates are in Legal points (612 wide x 1008 tall), sourced
     * verbatim from the legacy print.php positions array — the background
     * image is the same 2550x4200 px scan it was calibrated against.
     *
     * FPDF::Text(x, y) anchors text at the BASELINE. dompdf positions an
     * absolutely-positioned element by its TOP edge. We translate by
     * subtracting ~font_pt * 0.85 (approximate Helvetica ascent) so the
     * visible glyphs land in the same place the legacy generator placed
     * them.
     *
     * @param array<int, array<int, string>> $data_rows Parsed CSV rows.
     * @param string                          $formatted_date DD/MM/YY.
     * @param string                          $bg_url file:// URL of the
     *                                                Blue Cross form image.
     */
    public static function build_vac_pdf_html(array $data_rows, string $formatted_date, string $bg_url): string {
        $coords = [
            'fullname'   => ['x' => 90,  'y' => 743, 'font_pt' => 12],
            'knumber'    => ['x' => 270, 'y' => 761, 'font_pt' => 12],
            'address'    => ['x' => 110, 'y' => 785, 'font_pt' => 12],
            'city'       => ['x' => 380, 'y' => 785, 'font_pt' => 12],
            'province'   => ['x' => 75,  'y' => 809, 'font_pt' => 12],
            'postal'     => ['x' => 275, 'y' => 808, 'font_pt' => 12],
            'telephone'  => ['x' => 463, 'y' => 809, 'font_pt' => 12],
            'meals'      => ['x' => 320, 'y' => 450, 'font_pt' => 14],
            'hst'        => ['x' => 360, 'y' => 490, 'font_pt' => 12],
            'report_dt'  => ['x' => 130, 'y' => 429, 'font_pt' => 14],
            'total'      => ['x' => 508, 'y' => 697, 'font_pt' => 12],
            'second_dt'  => ['x' => 483, 'y' => 909, 'font_pt' => 12],
        ];

        $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        $html .= '<style>
            @page { size: 612pt 1008pt; margin: 0; }
            body  { margin: 0; padding: 0; font-family: Helvetica, Arial, sans-serif; }
            .vac-page {
                position: relative;
                width: 612pt;
                height: 1008pt;
                page-break-after: always;
                background-image: url("' . self::h($bg_url) . '");
                background-size: 612pt 1008pt;
                background-repeat: no-repeat;
                background-position: 0 0;
            }
            .vac-page:last-child { page-break-after: auto; }
            .vac-field { position: absolute; line-height: 1; }
        </style></head><body>';

        foreach ($data_rows as $data) {
            // Column mapping (phase-2 VAC CSV, same indices as legacy print.php):
            $full_name = trim(($data[2] ?? '') . ' ' . ($data[1] ?? '')); // First Last
            $knumber   = $data[0]  ?? '';
            $address   = $data[3]  ?? '';
            $city      = $data[4]  ?? '';
            $postal    = $data[5]  ?? '';
            $phone     = $data[6]  ?? '';
            $meals     = $data[11] ?? '0';
            $hst_amt   = number_format((float) ($data[32] ?? 0), 2, '.', '');
            $total_amt = '$' . number_format((float) ($data[33] ?? 0), 2, '.', '');

            $fields = [
                ['key' => 'fullname',  'text' => $full_name],
                ['key' => 'knumber',   'text' => $knumber],
                ['key' => 'address',   'text' => $address],
                ['key' => 'city',      'text' => $city],
                ['key' => 'province',  'text' => 'NB'],
                ['key' => 'postal',    'text' => $postal],
                ['key' => 'telephone', 'text' => $phone],
                ['key' => 'meals',     'text' => (string) $meals],
                ['key' => 'hst',       'text' => $hst_amt],
                ['key' => 'report_dt', 'text' => $formatted_date],
                ['key' => 'total',     'text' => $total_amt],
                ['key' => 'second_dt', 'text' => $formatted_date],
            ];

            $html .= '<div class="vac-page">';
            foreach ($fields as $f) {
                $c    = $coords[$f['key']];
                $top  = $c['y'] - ($c['font_pt'] * 0.85);
                $html .= sprintf(
                    '<span class="vac-field" style="left:%spt;top:%spt;font-size:%spt;">%s</span>',
                    self::h((string) $c['x']),
                    self::h((string) $top),
                    self::h((string) $c['font_pt']),
                    self::h((string) $f['text'])
                );
            }
            $html .= '</div>';
        }
        $html .= '</body></html>';
        return $html;
    }

    /**
     * HTML-escape helper for the dompdf templating.
     */
    private static function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }



    /**
     * Get WooCommerce product IDs for fee line items (contribution and delivery fee).
     *
     * @return array{client_contribution: int, delivery_fee: int}
     */
    public static function get_fee_product_ids(): array {
        $defaults = MealsDB_Operational_Constants::default_fee_product_ids();

        $saved = get_option('mealsdb_fee_product_ids', []);
        if (!is_array($saved)) {
            $saved = [];
        }

        return [
            'client_contribution' => (int) ($saved['client_contribution'] ?? $defaults['client_contribution']),
            'delivery_fee'        => (int) ($saved['delivery_fee'] ?? $defaults['delivery_fee']),
        ];
    }


}
