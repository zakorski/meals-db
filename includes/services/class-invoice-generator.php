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

    // INV-DRAFT-3 Step 4c: the old VAC billing-constant tables ($vac_allowances
    // and $vac_billing — per_main_allowance / sides_conversion_rate /
    // sides_cost_rate / sides_hst_rate) are RETIRED. The corrected VAC model
    // (build_vac_draft_rows + serialize_vac_csv) bills mains-only and reads its
    // rates LIVE from MealsDB_Rate_Definitions ('vac_per_main_coverage',
    // 'vac_side'); the dead VAC_SIDES_CONVERSION_RATE is no longer referenced;
    // and HST stays WC-sourced (LB-7). Sides are folded by hand on the draft
    // grid (fold_amount / fold_hst), not priced from a constant here.

    /**
     * Resolve the HST rate (as a decimal fraction, e.g. 0.15) from
     * WooCommerce's configured STANDARD tax rate.
     *
     * Per the operator's decision (LB-7 follow-up), the SDNB
     * government-invoice HST rate is sourced LIVE from WooCommerce
     * rather than from a plugin constant, so a rate change is made once
     * in WC Settings → Tax. This mirrors the Quick Order preview path
     * (MealsDB_Admin_UI::resolve_quick_order_tax_rate) so the invoice
     * and the QO preview agree on the rate.
     *
     * NO FALLBACK — by design: if WooCommerce is unavailable, tax is
     * disabled, or no standard rate is configured, this returns 0.0 and
     * the invoice's HST is 0. That means a misconfigured WC tax table
     * silently produces a 0% HST government invoice. The zero case is
     * LOGGED (logging does not change the returned value — it is not a
     * fallback) so the condition is at least traceable after the fact.
     *
     * NOTE: under the corrected VAC model (INV-DRAFT-3) the VAC serializer
     * does NOT compute HST at all — VAC is billed mains-only and the HST on
     * folded sides (fold_hst) is hand-entered on the draft grid. If the
     * operator later asks the system to auto-seed fold_hst, that seed would
     * use THIS WC-sourced rate (LB-7) — there is no VAC HST constant.
     */
    private static function resolve_hst_rate(): float {
        if (!class_exists('WC_Tax')) {
            MealsDB_Logger::error('[MealsDB Invoice] HST rate: WC_Tax unavailable — invoice HST will be 0%.');
            return 0.0;
        }

        try {
            $rates = \WC_Tax::get_rates('');
            if (is_array($rates) && !empty($rates)) {
                $first_rate = reset($rates);
                if (is_array($first_rate) && isset($first_rate['rate'])) {
                    $rate = (float) $first_rate['rate'];
                    if ($rate > 0) {
                        return $rate / 100;
                    }
                }
            }
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Invoice] HST rate read from WC failed: ' . $e->getMessage());
            // STR-LOG: swallowed, but we press on with HST=0 — that under-
            // reports tax on the government CSV, so surface it as degraded.
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'  => 'error',
                    'category'  => 'billing',
                    'subsystem' => 'invoice_generator',
                    'event'     => 'resolve_hst_rate.failed',
                    'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                    'message'   => 'WC_Tax HST read failed; invoice HST resolved to 0: ' . $e->getMessage(),
                ]);
            }
            return 0.0;
        }

        MealsDB_Logger::error('[MealsDB Invoice] HST rate from WC resolved to 0% — invoice HST will be 0.');
        return 0.0;
    }

    /**
     * Canonical billing data fetcher.
     *
     * Returns ONE ROW PER CLIENT for the billing month, holding what the
     * allocation engine assigned plus the contribution line-item sum for
     * the same scope. The engine already enforced the monthly allowance
     * (phase 1 fill with single-month spill), so the generators never need
     * to cap or compare to allowance anymore.
     *
     * Clients with no mains AND no sides attributed this month are omitted
     * entirely — a contribution line alone does not put a client on the
     * invoice (operator ruling 2026-07-30).
     *
     * Tax follows the allocated taxable-side count: HST = taxable sides ×
     * the (rurality-resolved) pre-tax side rate × the WC-sourced HST rate
     * (see resolve_hst_rate). Mains are never taxed. The VAC path does NOT use
     * this tax figure — under the corrected mains-only model (INV-DRAFT-3) VAC
     * sides are folded by hand on the draft grid (fold_hst), not taxed here.
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
        $all_order_ids = [];
        foreach ((array) $allocated_order_rows as $r) {
            $oid = (int) $r['wc_order_id'];
            $orders_by_cid[(int) $r['client_id']][] = $oid;
            $all_order_ids[$oid] = true;
        }

        // BC-5: compute each order's PRIMARY (earliest) billing month so an order
        // that spilled across a month boundary has its contribution counted in
        // exactly one month (its primary), not deducted twice.
        $primary_month_by_order = [];
        if (!empty($all_order_ids)) {
            $oid_list = implode(',', array_map('intval', array_keys($all_order_ids)));
            $primary_rows = $wpdb->get_results(
                "SELECT wc_order_id, MIN(billing_month) AS primary_month
                 FROM `{$alloc_table}`
                 WHERE wc_order_id IN ({$oid_list})
                 GROUP BY wc_order_id",
                ARRAY_A
            );
            foreach ((array) $primary_rows as $pr) {
                // Only record a real month — a missing/empty value must default
                // to "keep" (contribution_orders_for_month treats an absent entry
                // as the current month), never silently drop the contribution.
                $pm = (string) ($pr['primary_month'] ?? '');
                if ($pm !== '') {
                    $primary_month_by_order[(int) $pr['wc_order_id']] = $pm;
                }
            }
        }

        $order_query = new MealsDB_WC_Order_Query($wpdb);

        // Resolve the HST rate once for the whole batch (sourced live from
        // WooCommerce — see resolve_hst_rate). No fallback: a 0 here means
        // 0% HST on the invoice.
        $hst_rate = self::resolve_hst_rate();

        $out = [];
        // SDNB clients billed with a blank delivery_area_zone default to
        // URBAN rates (main-rate fallback and the HST side rate below).
        // Rural rates are higher, so that default UNDER-bills — collect the
        // affected client_ids and surface them as one degraded event after
        // the loop, instead of defaulting silently. (VAC rows never select
        // the zone column and are exempt.)
        $sdnb_missing_zone = [];
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
            $resolved_rate = $order_query->resolve_rate_for_order($rate_id, $cid, $client['client_type'] ?? '', $client['delivery_area_zone'] ?? null);

            // Contribution: sum of Client Contribution line items across orders
            // whose meals landed in this billing month for this client. BC-5:
            // restrict to orders whose PRIMARY month is this month, so a
            // boundary-spanning order is counted once (in its primary month).
            $contribution_order_ids = self::contribution_orders_for_month(
                $orders_by_cid[$cid] ?? [],
                $primary_month_by_order,
                $billing_month
            );
            $contribution_cents = self::sum_contribution_for_orders($contribution_order_ids);

            // Basic = allocated_units × rate. "Allocated units" = mains for
            // most clients; legacy two-line splitting may include sides on
            // line 1 / line 2 — that lives downstream in split_into_invoice_lines.
            $basic_cents = MealsDB_Money::multiply($allocated_mains, $resolved_rate);

            // HST: taxable sides only, at the pre-tax side rate × the
            // WC-sourced HST rate. Mains are never taxed. Side rate is
            // resolved from the client's zone, NOT from the price (LB-7 —
            // replaces the obsolete net-portion multiplier table).
            // Non-SDNB tax (VAC) is computed in its own path.
            $tax_cents = 0;
            if ($allocated_tax_sides > 0) {
                $rural       = MealsDB_Operational_Constants::is_rural_zone($client['delivery_area_zone'] ?? null);
                $side_rate   = MealsDB_Operational_Constants::get_sdnb_side_rate($rural);
                $sides_cents = MealsDB_Money::multiply($allocated_tax_sides, $side_rate);
                $tax_cents   = MealsDB_Money::percent_of($sides_cents, $hst_rate);
            }

            // Suppress zero-attribution clients: per the operator's ruling
            // (2026-07-30), a client with no mains and no sides attributed in
            // the month does NOT appear on the invoice — even when a
            // contribution line item exists on one of their orders. (The
            // previous behavior kept contribution-only rows, which produced
            // units-0 lines the serializers had to skip and a negative
            // Dept. Cost on the legacy layout.)
            $has_attribution = ($allocated_mains > 0)
                || ($allocated_tax_sides > 0)
                || ($allocated_nontax_sides > 0);
            if (!$has_attribution) {
                continue;
            }

            // Only flag the missing zone for clients actually billed this
            // month — an idle client's data gap costs nothing yet.
            if (strtoupper((string) ($client['client_type'] ?? '')) === 'SDNB'
                && trim((string) ($client['delivery_area_zone'] ?? '')) === '') {
                $sdnb_missing_zone[] = $cid;
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

        if (!empty($sdnb_missing_zone) && class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'billing',
                'subsystem' => 'invoice_generator',
                'event'     => 'sdnb_zone.missing',
                'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message'   => count($sdnb_missing_zone) . ' SDNB client(s) on the ' . $billing_month
                    . ' invoice have no delivery_area_zone and were billed at URBAN rates by default.'
                    . ' Set the zone on each client (Sussex = S) and regenerate if any are rural.',
                'context'   => ['billing_month' => $billing_month, 'client_ids' => $sdnb_missing_zone],
            ]);
        }
        return $out;
    }

    /**
     * Sum the cents of Client Contribution line items across the given
     * wc_order_ids. Reads WC HPOS order_itemmeta directly so it runs against
     * the same data WC uses.
     *
     * BC-5: resolve the contribution product id the SAME way the fee engine and
     * the reconciliation layer do (get_fee_product_ids) — never hardcode 5675,
     * or a changed mealsdb_fee_product_ids option silently zeroes the invoice's
     * contribution and over-bills the department. Sum _line_subtotal to match
     * MealsDB_WC_Order_Query (one basis across invoice + reconciliation).
     */
    private static function sum_contribution_for_orders(array $wc_order_ids): int {
        if (empty($wc_order_ids)) { return 0; }
        global $wpdb;

        $fee_ids = self::get_fee_product_ids();
        $contribution_pid = (int) ($fee_ids['client_contribution'] ?? 0);
        if ($contribution_pid <= 0) { return 0; }

        $order_list = implode(',', array_map('intval', $wc_order_ids));
        $items_table = $wpdb->prefix . 'woocommerce_order_items';
        $meta_table  = $wpdb->prefix . 'woocommerce_order_itemmeta';

        // _line_subtotal is stored as a string-formatted decimal; CAST to handle.
        $sql = $wpdb->prepare("
            SELECT COALESCE(SUM(CAST(ls.meta_value AS DECIMAL(12,4))), 0)
            FROM `{$items_table}` i
            INNER JOIN `{$meta_table}` pm ON pm.order_item_id = i.order_item_id
                                          AND pm.meta_key = '_product_id'
                                          AND pm.meta_value = %s
            INNER JOIN `{$meta_table}` ls ON ls.order_item_id = i.order_item_id
                                          AND ls.meta_key = '_line_subtotal'
            WHERE i.order_id IN ({$order_list})
              AND i.order_item_type = 'line_item'
        ", (string) $contribution_pid);

        $sum_decimal = (string) $wpdb->get_var($sql);
        // Convert decimal dollars to integer cents without float drift.
        return MealsDB_Money::to_cents($sum_decimal);
    }

    /**
     * BC-5: keep only the orders whose PRIMARY (earliest) billing month is the
     * month being invoiced. An order whose meals spilled across a month boundary
     * has delivery_allocations rows in BOTH months, so it appears in both
     * months' order lists; without this, its contribution line is deducted
     * twice. An order with no recorded primary month defaults to the current
     * month (kept) — defensive, should not happen for an allocated order.
     *
     * @param int[]                 $order_ids
     * @param array<int,string>     $primary_month_by_order order_id => YYYY-MM
     * @param string                $billing_month
     * @return int[]
     */
    private static function contribution_orders_for_month(array $order_ids, array $primary_month_by_order, string $billing_month): array {
        $out = [];
        foreach ($order_ids as $oid) {
            $oid = (int) $oid;
            $primary = $primary_month_by_order[$oid] ?? $billing_month;
            if ($primary === $billing_month) {
                $out[] = $oid;
            }
        }
        return $out;
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
        // HST rate sourced live from WooCommerce (no fallback) — see resolve_hst_rate.
        $hst_rate  = self::resolve_hst_rate();

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
            ? MealsDB_Money::percent_of(MealsDB_Money::multiply($tax_sides_on_line_1, $side_rate), $hst_rate)
            : 0;

        // Line 2 calculations.
        $mains_on_line_2        = max(0, $bill_mains - $mains_on_line_1);
        $tax_sides_on_line_2    = $bill_tax_sides - $tax_sides_on_line_1;
        $nontax_sides_on_line_2 = $bill_nontax_sides - $nontax_sides_on_line_1;
        $hst_line_2_cents = ($tax_sides_on_line_2 > 0)
            ? MealsDB_Money::percent_of(MealsDB_Money::multiply($tax_sides_on_line_2, $side_rate), $hst_rate)
            : 0;

        // $hst_line_2_cents is intentionally NOT summed here: it is nonzero only
        // when $tax_sides_on_line_2 > 0 (see above), which is already a term of
        // this sum, so it can never change the > 0 outcome — and it is money
        // cents, not a unit count, so mixing it in would be a units/money
        // confusion for the next reader.
        $has_second_line = ($mains_on_line_2 + $tax_sides_on_line_2 + $nontax_sides_on_line_2) > 0;

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
     * Adapt a stored SDNB-legacy phase-2 row into the shape
     * split_into_invoice_lines expects. SINGLE source of truth shared by
     * serialize_sdnb_legacy (finalize) and recompute_sdnb_legacy_lines (the
     * draft grid) — directive INVOICE-DRAFT-SPREADSHEET SDNB scope 3a.
     *
     * bill_sides is DERIVED from tax+nontax rather than read from
     * allocated_sides. This is provably a no-op for generated data —
     * MealsDB_Allocation_Rebuilder writes sides_count = tax_sides + nontax_sides
     * on every detail row (put_nontax = put_sides − put_tax), so the summed
     * allocated_sides always equals tax+nontax — AND it keeps an edited side
     * count consistent with the line-1 gate (editing tax/nontax on the grid
     * would otherwise desync against a stale allocated_sides).
     *
     * Returns null for a zero-mains row (matching the serializer's `continue`).
     */
    private static function adapt_sdnb_legacy_row(array $b): ?array {
        if ((int) ($b['allocated_mains'] ?? 0) <= 0) {
            return null;
        }
        $tax    = (int) ($b['allocated_tax_sides'] ?? 0);
        $nontax = (int) ($b['allocated_nontax_sides'] ?? 0);
        return [
            'client'              => $b,
            'resolved_rate'       => $b['resolved_rate'] ?? 0,
            'bill_mains'          => (int) $b['allocated_mains'],
            'bill_sides'          => $tax + $nontax, // derived; == allocated_sides by construction
            'bill_tax_sides'      => $tax,
            'bill_nontax_sides'   => $nontax,
            // Contribution stored as cents on the row; split_into_invoice_lines
            // converts the float back to cents via to_cents.
            'client_contribution' => (int) ($b['contribution_cents'] ?? 0) / 100,
        ];
    }

    /**
     * Derive the ORDERED 1–2 invoice lines for one stored SDNB-legacy row —
     * the shared compute fn the draft grid renders and the live recompute
     * endpoint returns (directive INVOICE-DRAFT-SPREADSHEET SDNB scope 3a).
     * Each line carries split_into_invoice_lines' fields plus a 1-based
     * line_number and line_total_cents (= basic + tax − contribution, the same
     * per-line total the serializer sums for the header). Returns [] for a
     * zero-mains row.
     *
     * @return array<int,array> ordered list of line arrays.
     */
    public static function recompute_sdnb_legacy_lines(array $row): array {
        $adapted = self::adapt_sdnb_legacy_row($row);
        if ($adapted === null) {
            return [];
        }
        $out = [];
        foreach (self::split_into_invoice_lines($adapted) as $i => $line) {
            $line['line_number']      = $i + 1;
            $line['line_total_cents'] = (int) $line['basic_cost_cents']
                + (int) $line['tax_cents']
                - (int) $line['client_contribution_cents'];
            $out[] = $line;
        }
        return $out;
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

    // ------------------------------------------------------------------
    // Shared "collect client rows" top-halves (INV-DRAFT-1 Step 5).
    //
    // Each pipeline's generate_*() and its build_*_draft_rows() builder run
    // the IDENTICAL query → dirty-rebuild → PII-decrypt sequence. Factoring it
    // here keeps exactly one code path producing the rows (refactor, don't
    // fork) and is output-preserving: the generators' serialization is
    // unchanged, they just source their rows from here.
    // ------------------------------------------------------------------

    /**
     * Veteran clients for a VAC billing run: query, pre-rebuild dirty
     * client-months, decrypt the PII fields the generator reads. Note
     * vet_health_card is NOT decrypted here — the VAC serializer decrypts it
     * inline at output time (matching pre-refactor behaviour).
     *
     * @return array Decrypted client rows (DB-side column names).
     */
    private static function collect_vac_client_rows(string $start_date): array {
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $sql = "SELECT client_id, wp_user_id, first_name, last_name, client_type, requisition_id,
                    vet_health_card, requisition_period, client_contribution, default_rate_id,
                    street_name, city, postal_code, client_phone_1,
                    individual_id, individual_id_index
             FROM `{$clients_table}`
             WHERE client_type = %s AND active = 1 AND wp_user_id > 0";

        $client_rows = self::query_clients($sql, ['Veteran']);

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

        return $client_rows;
    }

    /**
     * SDNB legacy (zone-based) clients: query, pre-rebuild dirty months,
     * decrypt PII fields the generator reads.
     *
     * @return array Decrypted client rows (DB-side column names).
     */
    private static function collect_sdnb_legacy_client_rows($zone, string $start_date): array {
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $sql = "SELECT client_id, wp_user_id, first_name, last_name, client_type, service_id, requisition_id,
                    individual_id, individual_id_index, client_contribution, delivery_area_zone,
                    default_rate_id
             FROM `{$clients_table}`
             WHERE client_type = %s AND use_legacy_billing = 1
               AND delivery_area_zone = %s AND active = 1 AND wp_user_id > 0";

        $client_rows = self::query_clients($sql, ['SDNB', $zone]);

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

        return $client_rows;
    }

    /**
     * SDNB new-portal clients: query and pre-rebuild dirty months. No PII
     * decryption — the new-portal CSV reads no encrypted columns directly.
     * delivery_area_zone is selected so get_phase2_billing_data resolves the
     * correct urban/rural side rate for HST (LB-7).
     *
     * @return array Client rows (DB-side column names).
     */
    private static function collect_sdnb_new_portal_client_rows(string $start_date): array {
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $sql = "SELECT client_id, wp_user_id, first_name, last_name, client_type, sdnb_service_request_id,
                    client_contribution, default_rate_id, delivery_area_zone
             FROM `{$clients_table}`
             WHERE client_type = %s AND use_legacy_billing = 0
               AND active = 1 AND wp_user_id > 0";

        $client_rows = self::query_clients($sql, ['SDNB']);

        // Phase 1: pre-rebuild dirty client-months in this filter (scope A).
        self::rebuild_dirty_for_invoice($start_date, $client_rows);

        return $client_rows;
    }

    // ------------------------------------------------------------------
    // Draft-row builders (INV-DRAFT-1 Step 5).
    //
    // These run the SAME query + phase-2 assembly the generators do, but
    // RETURN the per-client billing-row map (keyed by client_id) instead of
    // serializing. They are what INV-DRAFT-2's "Generate draft" button will
    // call, then hand to MealsDB_Invoice_Draft::create(). The rows are
    // self-contained (identity fields + phase-2 figures) so finalize can
    // serialize without re-querying.
    //
    // NOTE (half-done state, per the directive's allowance): pipeline-specific
    // finalize SERIALIZATION over these rows lands in INV-DRAFT-3 (VAC first).
    // VAC-specific editable extras (the fold/HST hand-work, vet_health_card
    // decryption for display) are also INV-DRAFT-3 — the payload row is an
    // open associative array, so those keys can be added without a schema or
    // shape change here.
    // ------------------------------------------------------------------

    /**
     * Build the VAC per-client billing-row map for a draft.
     *
     * @param string $start_date Y-m-d (first day of billing month).
     * @param string $end_date   Y-m-d (last day). Kept for signature parity with
     *                           the sibling build_*_draft_rows() entry points and
     *                           used for the new-user check; the billing month
     *                           itself keys off $start_date.
     * @return array<int,array> client_id => phase-2 row (+ identity fields).
     */
    public static function build_vac_draft_rows($start_date, $end_date): array {
        $client_rows = self::collect_vac_client_rows($start_date);
        if (empty($client_rows)) {
            return [];
        }
        $billing_month = substr($start_date, 0, 7);
        $rows = self::get_phase2_billing_data($client_rows, $billing_month);

        // INV-DRAFT-3 Step 4a — the CORRECTED VAC billing row.
        //
        // VAC is invoiced MAINS-ONLY: a single "Food and Delivery / of N
        // Meals" line and a total whose "(includes HST)" figure is the HST on
        // sides FOLDED into the per-main gap — never a separate side line
        // (verified against the 27 Jan-2025 reimbursement PDFs). The fold is
        // Janet's hand-work and is NOT a formula, so we do NOT reproduce it
        // here: we produce a correct mains-only starting point and expose the
        // fold as explicit, editable, audited draft fields she fills on the
        // grid (the entire reason the draft layer exists).
        //
        // The serializer (serialize_vac_csv) reads ONLY these row fields and
        // never re-queries, so the draft's edited values flow straight to the
        // output. new_user_flag is computed HERE (it depends on the billing
        // PERIOD, not "now") to keep the serializer a pure rows→string fn.
        $engine        = class_exists('MealsDB_Allocation_Engine') ? new MealsDB_Allocation_Engine() : null;
        $vac_side_rate = MealsDB_Rate_Definitions::get('vac_side');
        $vac_side_rate = is_float($vac_side_rate) ? $vac_side_rate : 0.0;
        $coverage      = MealsDB_Rate_Definitions::get('vac_per_main_coverage');
        $coverage      = is_float($coverage) ? $coverage : 0.0;

        foreach ($rows as $cid => &$row) {
            $cid_int = (int) $cid;

            // Decrypt vet_health_card for display/PDF. The draft payload is
            // encrypted at rest (encode_payload), so carrying the plaintext
            // here is consistent with the rest of the draft's PII.
            $row['vet_health_card'] = !empty($row['vet_health_card'])
                ? MealsDB_Encryption::safe_decrypt($row['vet_health_card'])
                : '';

            // U04-billing-9: get_phase2_billing_data() computes tax_cents from the
            // SDNB side rate for ANY row with taxable sides, but collect_vac_client_rows()
            // never selects delivery_area_zone — so a veteran's tax_cents is an
            // urban-SDNB-rate figure with no meaning under the corrected VAC model
            // (VAC is billed mains-only; HST rides on the hand-entered fold_hst).
            // serialize_vac_csv() and compute_vac_row_derived() ignore tax_cents,
            // and the VAC draft grid omits it, so zeroing it changes NO output — it
            // just stops a bogus SDNB-derived value from riding on the encrypted VAC
            // draft row for a future consumer to trust. Shape-stable (keeps the key).
            $row['tax_cents'] = 0;

            // --- Editable corrected-model fields (Step 4a) ---
            $row['bill_mains'] = (int) ($row['allocated_mains'] ?? 0);
            // bill_rate: the per-main dollar figure ON THE WIRE.
            // DECISION-GATE DEFAULT (directive INV-DRAFT-3): seeds from the
            // per-client resolved_rate (the COST rate), NOT the VAC coverage
            // ceiling. The Decision gate (cost-rate vs coverage on the wire)
            // is the one open operator question — if the operator answers
            // "seed coverage", change this single line to:
            //   $row['bill_rate'] = $coverage;
            // That is a seed change, not a re-architecture.
            $row['bill_rate'] = (float) ($row['resolved_rate'] ?? 0);
            // fold_amount: dollar value of sides folded into the per-main gap.
            // SEEDS TO 0 — Janet enters it per veteran on the grid (her
            // hand-work, now captured + audited). Stored as a dollar float so
            // the edit layer (classify_field → money) round-trips it as
            // dollars.
            $row['fold_amount'] = 0.0;
            // fold_hst: HST on the folded TAXABLE sides — the "(includes HST)"
            // figure on the Blue Cross form. SEEDS TO 0 (Decision-gate
            // default). If the operator later asks to auto-seed it, compute
            // the taxable portion of the fold × resolve_hst_rate() HERE — a
            // one-line seed change. HST stays WC-sourced (LB-7); do NOT
            // reintroduce a VAC HST constant.
            $row['fold_hst'] = 0.0;

            // --- Informational context (NEVER summed into the VAC total) ---
            // Permitted figures for the operator's reference while deciding
            // the fold. Computed here (build time) so the serializer never
            // re-queries.
            if ($engine !== null) {
                $permitted = $engine->calculate_permitted_for_month($cid_int, $billing_month);
                $row['info_mains_allowance'] = (int) ($permitted['permitted_mains'] ?? 0);
                $row['info_sides_allowance'] = (int) ($permitted['permitted_sides'] ?? 0);
            } else {
                $row['info_mains_allowance'] = 0;
                $row['info_sides_allowance'] = 0;
            }
            // Monthly coverage dollars — sourced from Definitions
            // (vac_per_main_coverage), NOT the dead VAC_SIDES_CONVERSION_RATE
            // or a constant. Informational only.
            $row['info_monthly_allowance_cents'] = MealsDB_Money::multiply($row['info_mains_allowance'], $coverage);
            // Side COST for reference (Definitions vac_side × allocated sides),
            // explicitly NOT part of the billed total.
            $allocated_sides = (int) ($row['allocated_tax_sides'] ?? 0) + (int) ($row['allocated_nontax_sides'] ?? 0);
            $row['info_sides_cost_cents'] = MealsDB_Money::multiply($allocated_sides, $vac_side_rate);

            $row['new_user_flag'] = self::check_new_user_flag((int) ($row['wp_user_id'] ?? 0), $start_date, $end_date) ?: 'No';
        }
        unset($row);

        return $rows;
    }

    /**
     * Build the SDNB legacy per-client billing-row map for a draft.
     *
     * @return array<int,array> client_id => phase-2 row (+ identity fields).
     */
    public static function build_sdnb_legacy_draft_rows($zone, $start_date, $end_date): array {
        $client_rows = self::collect_sdnb_legacy_client_rows($zone, $start_date);
        if (empty($client_rows)) {
            return [];
        }
        $billing_month = substr($start_date, 0, 7);
        return self::get_phase2_billing_data($client_rows, $billing_month);
    }

    /**
     * Build the SDNB new-portal per-client billing-row map for a draft.
     *
     * @return array<int,array> client_id => phase-2 row (+ identity fields).
     */
    public static function build_sdnb_new_portal_draft_rows($start_date, $end_date): array {
        $client_rows = self::collect_sdnb_new_portal_client_rows($start_date);
        if (empty($client_rows)) {
            return [];
        }
        $billing_month = substr($start_date, 0, 7);
        return self::get_phase2_billing_data($client_rows, $billing_month);
    }

    /**
     * Pure serializer for the SDNB legacy zone-based CSV (INV-DRAFT-3 Step 1).
     *
     * Takes phase-2 rows (from build_sdnb_legacy_draft_rows OR a draft's
     * edited `current`) plus invoice context; returns the CSV string. NO DB
     * access, NO finalize_month — output is byte-identical to the
     * pre-refactor generate_sdnb_legacy for the same rows with no edits
     * (characterization test T-A1). The merged phase-2 row carries every
     * client identity field, so it doubles as the 'client' sub-array
     * split_into_invoice_lines expects.
     *
     * @param array<int|string,array> $rows phase-2 rows keyed by client_id.
     * @param array                   $ctx  ['zone','start_date','end_date'].
     */
    public static function serialize_sdnb_legacy(array $rows, array $ctx): string {
        $zone       = (string) ($ctx['zone'] ?? 'M');
        $start_date = (string) ($ctx['start_date'] ?? '');
        $end_date   = (string) ($ctx['end_date'] ?? '');

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

        // Pre-compute duplicate individual_id counts for error checking
        // (over ALL rows handed in, matching the pre-refactor pass over
        // every queried client_row).
        $sdnb_duplicate_counts = [];
        foreach ($rows as $c) {
            $idx = is_array($c) ? ($c['individual_id_index'] ?? '') : '';
            if ($idx !== '') {
                if (!isset($sdnb_duplicate_counts[$idx])) {
                    $sdnb_duplicate_counts[$idx] = 0;
                }
                $sdnb_duplicate_counts[$idx]++;
            }
        }

        // Adapt phase-2 rows into the shape split_into_invoice_lines expects
        // via the SHARED adapter (also used by the draft grid's
        // recompute_sdnb_legacy_lines — directive INVOICE-DRAFT-SPREADSHEET
        // SDNB scope 3a). adapt_sdnb_legacy_row returns null for a zero-mains
        // row (no allocation this month → no line). bill_sides is derived from
        // tax+nontax there; this is a no-op for generated data (the ledger
        // writes sides_count = tax + nontax) and keeps an edited side count
        // consistent with the line-1 gate.
        $invoice_rows = [];
        foreach ($rows as $b) {
            if (!is_array($b)) {
                continue;
            }
            $adapted = self::adapt_sdnb_legacy_row($b);
            if ($adapted !== null) {
                $invoice_rows[] = $adapted;
            }
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

        return implode("\n", $csv);
    }

    /**
     * Pure serializer for the SDNB new-portal CSV (INV-DRAFT-3 Step 1).
     * Takes phase-2 rows (build or a draft's edited `current`) → CSV string.
     * NO DB access, NO finalize — byte-identical to the pre-refactor output
     * for the same rows (characterization test T-A2).
     *
     * @param array<int|string,array> $rows phase-2 rows keyed by client_id.
     */
    public static function serialize_sdnb_new_portal(array $rows): string {
        $csv = [];

        // Header row — 18 columns, matches Janet's Nov 2025 submission.
        $csv[] = 'Service Confirmation Item Id,Product Name,Service Request Id,Client Name,No. Of Units,Unit Type,Rate,Kilometres,Kilometre Rate,Other Cost (transportation),Other Cost (meals),Other Cost (sundry),Other Cost (admin fees),Other Cost (recreation),Other Cost (parking),Client Contribution,Stat Holiday Units,Tax';

        // Sort rows by name for stable output. The merged phase-2 row carries
        // first_name/last_name, so we sort the row VALUES directly (keys are
        // client_id and are irrelevant to serialization order).
        $list = array_values($rows);
        usort($list, function ($a, $b) {
            $na = strtoupper((($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')));
            $nb = strtoupper((($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')));
            return strcmp($na, $nb);
        });

        foreach ($list as $b) {
            if (!is_array($b)) {
                continue;
            }
            $allocated_mains = (int) ($b['allocated_mains'] ?? 0);
            if ($allocated_mains <= 0) {
                continue; // Skip zero-meal rows; nothing to bill.
            }

            $client_name = strtoupper($b['first_name'] ?? '') . ' ' . strtoupper($b['last_name'] ?? '');

            // The new-portal CSV has no Total column — the portal computes
            // the total from Units, Rate, Contribution, Tax. The plugin
            // emits those four as separate fields and lets the portal do
            // the math. (Confirmed against Janet's Nov 2025 submission.)
            $csv[] = MealsDB_CSV::row([
                '', // Service Confirmation Item Id — assigned by the SDNB portal on upload, left blank.
                'Meal Services - Services de repas',
                $b['sdnb_service_request_id'] ?? '',
                $client_name,
                $allocated_mains,
                'Meal',
                number_format((float) ($b['resolved_rate'] ?? 0), 2, '.', ''),
                '', // Kilometres
                '', // Kilometre Rate
                '', // Other Cost (transportation)
                '', // Other Cost (meals)
                '', // Other Cost (sundry)
                '', // Other Cost (admin fees)
                '', // Other Cost (recreation)
                '', // Other Cost (parking)
                (int) ($b['contribution_cents'] ?? 0) > 0 ? MealsDB_Money::format((int) $b['contribution_cents']) : '',
                '', // Stat Holiday Units
                (int) ($b['tax_cents'] ?? 0) > 0 ? MealsDB_Money::format((int) $b['tax_cents']) : '0',
            ]);
        }

        return implode("\n", $csv);
    }

    /**
     * Pure serializer for the VAC data CSV (INV-DRAFT-3 Steps 1 + 4b).
     *
     * Takes VAC draft rows (from build_vac_draft_rows OR a draft's edited
     * `current`) → 37-column CSV string. NO DB access, NO finalize, NO
     * re-derivation: it serializes EXACTLY the fields on the rows, so Janet's
     * grid edits (bill_rate, fold_amount, fold_hst) flow straight to output.
     *
     * THE CORRECTED VAC MODEL (Step 4b): VAC is billed MAINS-ONLY. The total is
     *     vac_total = bill_mains × bill_rate + fold_amount + fold_hst
     * NOT the old `mains_cost + sides_cost + sides_HST`. Sides are NOT a billed
     * line — they are folded by hand into the per-main gap (fold_amount), and
     * the HST on the taxable portion of that fold is the "(includes HST)"
     * figure (fold_hst). Both seed to 0 (Decision-gate default) and are
     * hand-entered on the grid.
     *
     * COLUMN LAYOUT: positions 0-35 are the legacy 36-column Blue Cross layout
     * and are LOAD-BEARING — the VAC PDF renderer (serialize_vac_pdf_from_csv →
     * build_vac_pdf_html) maps positionally onto indices
     * 0-6 (identity), 11 (Bill Mains = meal count), 32 (Bill HST = fold_hst),
     * 33 (New Total = vac_total). DO NOT reorder or remove a column before
     * index 33 or the PDF stamps the wrong cells. "Fold Amount" is APPENDED at
     * index 36 so the hand-entered fold is visible/auditable in the data file
     * without disturbing the PDF contract. "Sides Cost" (31) is relabelled
     * Info Only — it is reference-only and is NEVER summed into New Total.
     *
     * @param array<int|string,array> $rows VAC draft rows keyed by client_id.
     */
    /**
     * Derive the VAC per-row money figures from a stored `current` row.
     *
     * SINGLE SOURCE OF TRUTH for VAC derivation (directive
     * INVOICE-DRAFT-SPREADSHEET 3a): both serialize_vac_csv (finalize) and the
     * draft grid (render + live recompute over the edit endpoint) call THIS
     * function, so what Janet sees on the grid is byte-for-byte what finalize
     * emits. Never reimplement this math in JavaScript.
     *
     * VAC is billed MAINS-ONLY. fold_amount / fold_hst are INPUTS — Janet
     * hand-enters them per veteran (operator decision 2026-06-29); this fn
     * READS them, it does not derive them. Sides are NOT billed; they surface
     * only in the informational remaining_sides figure. bill_mains / bill_rate
     * fall back to allocated_mains / resolved_rate so a bare phase-2 row (no
     * bill_* yet) still derives correctly.
     *
     * @param array $row A VAC `current` row (build_vac_draft_rows shape).
     * @return array{vet_mains_cost_cents:int, vac_total_cents:int,
     *               remaining_sides:int, allowance_remaining_cents:int}
     */
    public static function compute_vac_row_derived(array $row): array {
        $bill_rate           = (float) ($row['bill_rate'] ?? ($row['resolved_rate'] ?? 0));
        $bill_mains          = (int) ($row['bill_mains'] ?? ($row['allocated_mains'] ?? 0));
        $allocated_tax_sides = (int) ($row['allocated_tax_sides'] ?? 0);

        $fold_amount_cents    = MealsDB_Money::to_cents($row['fold_amount'] ?? 0);
        $fold_hst_cents       = MealsDB_Money::to_cents($row['fold_hst'] ?? 0);
        $vet_mains_cost_cents = MealsDB_Money::multiply($bill_mains, $bill_rate);
        $vac_total_cents      = $vet_mains_cost_cents + $fold_amount_cents + $fold_hst_cents;

        // Informational — reference only, NEVER summed into the billed total.
        $sides_allowance         = (int) ($row['info_sides_allowance'] ?? 0);
        $monthly_allowance_cents = (int) ($row['info_monthly_allowance_cents'] ?? 0);

        return [
            'vet_mains_cost_cents'      => $vet_mains_cost_cents,
            'vac_total_cents'           => $vac_total_cents,
            'remaining_sides'           => max(0, $sides_allowance - $allocated_tax_sides),
            'allowance_remaining_cents' => max(0, $monthly_allowance_cents - $vet_mains_cost_cents),
        ];
    }

    public static function serialize_vac_csv(array $rows): string {
        // Pre-compute duplicate individual_id counts (over ALL rows handed in).
        $vet_duplicate_counts = [];
        foreach ($rows as $c) {
            $idx = is_array($c) ? ($c['individual_id_index'] ?? '') : '';
            if ($idx !== '') {
                if (!isset($vet_duplicate_counts[$idx])) {
                    $vet_duplicate_counts[$idx] = 0;
                }
                $vet_duplicate_counts[$idx]++;
            }
        }

        // Sort by last_name, first_name (stable output). Sort the row VALUES;
        // the client_id keys are irrelevant to serialization order.
        $list = array_values($rows);
        usort($list, function ($a, $b) {
            $cmp = strcmp((string) ($a['last_name'] ?? ''), (string) ($b['last_name'] ?? ''));
            return $cmp !== 0 ? $cmp : strcmp((string) ($a['first_name'] ?? ''), (string) ($b['first_name'] ?? ''));
        });

        $csv = [];

        // Header — 36 legacy columns + appended "Fold Amount" (37th).
        $csv[] = 'K#,Client Last Name,Client First Name,Billing Address 1,Billing City,Billing Postcode,Billing Phone,Unit Type,Rate,Mains Ordered,Mains Allowance,Bill Mains,BNM Mains,Sides Ordered,Sides Allowance,Desserts,Muffin,Total Tax Sides Ordered,Bill Tax Sides,Overage Tax Sides,Remaining Sides,Cereal,Soup,Total Non-Tax Sides Ordered,Bill Non-Taxable Sides,Overage Non Taxable Sides,Bill Sides,Service,Monthly Allowance,Vet Mains Cost,Allowance Remaining,Sides Cost (Info Only),Bill HST,New Total,Errors,New User flag,Fold Amount';

        foreach ($list as $vet) {
            if (!is_array($vet)) {
                continue;
            }

            // vet_health_card was decrypted at build time (build_vac_draft_rows);
            // safe_decrypt again would be a no-op on plaintext, but read it as-is.
            $health_card      = (string) ($vet['vet_health_card'] ?? '');
            $billing_address  = trim((string) ($vet['street_name'] ?? ''));
            $billing_city     = $vet['city'] ?? '';
            $billing_postcode = $vet['postal_code'] ?? '';
            $billing_phone    = $vet['client_phone_1'] ?? '';
            $service          = strtolower((string) ($vet['requisition_period'] ?? '') ?: 'week');

            // Corrected mains-only billing fields (editable on the grid).
            $bill_rate              = (float) ($vet['bill_rate'] ?? ($vet['resolved_rate'] ?? 0));
            $bill_mains             = (int) ($vet['bill_mains'] ?? ($vet['allocated_mains'] ?? 0));
            $allocated_mains        = (int) ($vet['allocated_mains'] ?? 0);
            $allocated_tax_sides    = (int) ($vet['allocated_tax_sides'] ?? 0);
            $allocated_nontax_sides = (int) ($vet['allocated_nontax_sides'] ?? 0);
            $allocated_sides        = $allocated_tax_sides + $allocated_nontax_sides;

            // fold_amount / fold_hst are stored as DOLLAR floats (the edit
            // layer round-trips them as dollars) and are emitted directly in
            // the Fold Amount / Bill HST columns below.
            $fold_amount_cents = MealsDB_Money::to_cents($vet['fold_amount'] ?? 0);
            $fold_hst_cents    = MealsDB_Money::to_cents($vet['fold_hst'] ?? 0);

            // Derived money — the SINGLE source of truth shared with the draft
            // grid (directive INVOICE-DRAFT-SPREADSHEET 3a). vac_total is
            // mains-only plus the hand-entered fold and its HST; sides are NOT
            // billed (they surface only as the informational remaining_sides).
            $derived                   = self::compute_vac_row_derived($vet);
            $vet_mains_cost_cents      = $derived['vet_mains_cost_cents'];
            $vac_total_cents           = $derived['vac_total_cents'];
            $remaining_sides           = $derived['remaining_sides'];
            $allowance_remaining_cents = $derived['allowance_remaining_cents'];

            // Informational figures — reference only, NEVER summed into the total.
            $mains_allowance         = (int) ($vet['info_mains_allowance'] ?? 0);
            $sides_allowance         = (int) ($vet['info_sides_allowance'] ?? 0);
            $monthly_allowance_cents = (int) ($vet['info_monthly_allowance_cents'] ?? 0);
            $info_sides_cost_cents   = (int) ($vet['info_sides_cost_cents'] ?? 0);

            $errors        = self::validate_client_row($vet, 'Veteran', $vet_duplicate_counts, 1);
            $new_user_flag = (string) ($vet['new_user_flag'] ?? 'No');

            $csv[] = MealsDB_CSV::row([
                $health_card,                                      // 0  K#
                $vet['last_name'] ?? '',                           // 1  Last Name
                $vet['first_name'] ?? '',                          // 2  First Name
                $billing_address, // MealsDB_CSV::cell() quotes embedded commas. // 3 Address
                $billing_city ?: '',                               // 4  City
                $billing_postcode ?: '',                           // 5  Postcode
                $billing_phone ?: '',                              // 6  Phone
                'Meal',                                            // 7  Unit Type
                number_format($bill_rate, 2, '.', ''),             // 8  Rate (bill_rate, on the wire)
                $allocated_mains,                                  // 9  Mains Ordered
                $mains_allowance,                                  // 10 Mains Allowance (info)
                $bill_mains,                                       // 11 Bill Mains  ← PDF: meal count
                0,                                                 // 12 BNM Mains
                $allocated_sides,                                  // 13 Sides Ordered (info)
                $sides_allowance,                                  // 14 Sides Allowance (info)
                0,                                                 // 15 Desserts
                0,                                                 // 16 Muffin
                $allocated_tax_sides,                              // 17 Total Tax Sides Ordered (info)
                $allocated_tax_sides,                              // 18 Bill Tax Sides (info — not billed)
                0,                                                 // 19 Overage Tax Sides
                $remaining_sides,                                  // 20 Remaining Sides (info)
                0,                                                 // 21 Cereal
                $allocated_nontax_sides,                           // 22 Soup
                $allocated_nontax_sides,                           // 23 Total Non-Tax Sides Ordered (info)
                $allocated_nontax_sides,                           // 24 Bill Non-Taxable Sides (info — not billed)
                0,                                                 // 25 Overage Non Taxable Sides
                $allocated_sides,                                  // 26 Bill Sides (info — not billed)
                $service,                                          // 27 Service
                MealsDB_Money::format($monthly_allowance_cents),   // 28 Monthly Allowance (info, Definitions coverage)
                MealsDB_Money::format($vet_mains_cost_cents),       // 29 Vet Mains Cost = bill_mains × bill_rate
                MealsDB_Money::format($allowance_remaining_cents),  // 30 Allowance Remaining (info)
                MealsDB_Money::format($info_sides_cost_cents),      // 31 Sides Cost (INFO ONLY — NOT summed)
                MealsDB_Money::format($fold_hst_cents),             // 32 Bill HST = fold_hst  ← PDF: "(includes HST)"
                MealsDB_Money::format($vac_total_cents),            // 33 New Total = vac_total ← PDF: total
                $errors,                                           // 34 Errors
                $new_user_flag,                                    // 35 New User flag
                MealsDB_Money::format($fold_amount_cents),          // 36 Fold Amount (appended — auditable)
            ]);
        }

        return implode("\n", $csv);
    }

    /**
     * Render a VAC reimbursement PDF from an already-built VAC data CSV
     * (INV-DRAFT-3 Step 2). PURE renderer: it parses the CSV and stamps each
     * row onto the Blue Cross form — it does NOT query the DB or regenerate
     * the data. Called by the draft-finalize path (the draft's edited CSV),
     * so the bytes a finalized draft yields are exactly the bytes Janet
     * reviewed.
     *
     * The signature date stamped on the form (date of service / signature) is
     * the billing-period END — passed in rather than re-derived, since the
     * CSV itself carries no per-invoice date.
     *
     * @param string $csv_content The VAC data CSV (serialize_vac_csv output).
     * @param string $end_date    YYYY-MM-DD billing-period end (stamped date).
     * @return string PDF bytes ('' when there is nothing to render).
     */
    public static function serialize_vac_pdf_from_csv(string $csv_content, string $end_date): string {
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

        // Parse the CSV back into rows. Stage 2 is a pure renderer — the same
        // CSV the operator can download is what feeds the PDF.
        $lines = explode("\n", $csv_content);
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
     * PDF. Extracted from the VAC PDF render path (serialize_vac_pdf_from_csv)
     * so it can be tested without touching the DB or dompdf.
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
