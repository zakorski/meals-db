<?php
/**
 * Reporting service for Meals DB.
 */

defined('ABSPATH') || exit;

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
     * Defence-in-depth capability gate for report data access.
     *
     * AJAX handlers already run their own capability check before
     * instantiating this service. This guard ensures that any future
     * caller (WP-CLI, REST, custom cron) that reaches a report method
     * without the plugin's required capability receives an empty result
     * instead of silently exfiltrating aggregate PII.
     *
     * Returns true when the plugin's permission layer is not loaded
     * (e.g. during bootstrap or in test fixtures) so unit tests that
     * exercise the reporting SQL without a full WP stack still pass.
     */
    private static function is_authorized_to_read_reports(): bool {
        if (!class_exists('MealsDB_Permissions')) {
            return true;
        }

        if (MealsDB_Permissions::can_access_plugin()) {
            return true;
        }

        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        error_log(sprintf('[MealsDB Reports] Unauthorized report access attempt by user_id=%d', $user_id));

        return false;
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
        // Route every row through MealsDB_CSV::row() so cells that start
        // with =, +, -, @, tab, or CR are prefixed with a single quote
        // to neutralise formula injection (CWE-1236). fputcsv() handles
        // RFC-4180 quoting but NOT formula neutralisation, so client
        // names / product names sourced from user input were previously
        // executable when the CSV was opened in Excel or Sheets.
        fwrite($handle, MealsDB_CSV::row($headers) . "\n");

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $line[] = $value;
            }
            fwrite($handle, MealsDB_CSV::row($line) . "\n");
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /**
     * Generate a seasonally-adjusted purchase order projection.
     *
     * Three-layer algorithm:
     * 1. Exponentially weighted recent demand (baseline)
     * 2. Seasonal index from year-over-year comparison
     * 3. Inventory subtraction (stock + future)
     *
     * The forecasting model is FIXED to the configuration validated by the 2025
     * back-test and is NOT configurable by design (no params, meta, settings, or
     * filters): 12-week recency-weighted history (decay 0.85, most-recent week
     * weight 1.0), a 6-week order horizon PLUS a demand-proportional 3-week
     * safety buffer = 9 weeks of coverage, and a year-over-year seasonal index
     * clamped to [0.3, 3.0] (default 1.0 with no prior-year data). The 3-week
     * buffer was chosen because it cut order volatility below the status quo
     * (0.245 → 0.165) and dropped stockouts to a single month at an acceptable
     * inventory level. The buffer is the 3 extra weeks of demand — NOT a flat
     * per-product unit count.
     *
     * @return array
     */
    public function generate_purchase_order(): array {
        if (!self::is_authorized_to_read_reports()) {
            return [];
        }

        if (!$this->wpdb instanceof wpdb) {
            return [];
        }

        // Locked to the back-test-validated 3-week-buffer model. Not configurable.
        $trailing_weeks      = 12;
        $order_horizon_weeks = 6;
        $decay_factor        = 0.85;
        $buffer_weeks        = 3;
        $coverage_weeks      = $order_horizon_weeks + $buffer_weeks; // 9

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

        // An empty recent-sales result must NOT empty the PO now — the full
        // catalog is still forecast (everything just has zero recent demand and
        // orders only to cover stock gaps). The empty($products) guard after the
        // catalog seed + category exclusion (below) is the real gate.
        if (!is_array($recent_rows)) {
            $recent_rows = [];
        }

        // --- Seed the product universe from the full active catalog. ---
        // Forecast EVERY published meal/side product in meals_products, not just
        // those sold in the trailing window — mirrors the validated back-test
        // simulation, which never dropped a product for a quiet 12 weeks. Query A
        // (below) only ATTACHES recent weekly history to these entries.
        $products_table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $catalog = $this->wpdb->get_results(
            "SELECT wc_product_id, product_type, case_size
             FROM `{$products_table}`
             WHERE product_type IN ('meal','side')
               AND is_published = 1",
            ARRAY_A
        );

        $products = [];
        if (is_array($catalog)) {
            foreach ($catalog as $row) {
                $pid = (int) $row['wc_product_id'];
                if ($pid <= 0) {
                    continue;
                }
                $products[$pid] = [
                    'product_name'   => '',                 // filled from sales or WC below
                    'weekly_history' => [],                 // zero until Query A fills weeks
                    'case_size'      => (int) $row['case_size'],
                ];
            }
        }

        // Query A now ATTACHES recent weekly history to the catalog entries. A
        // product SOLD but absent from meals_products (e.g. unsynced) is still
        // included so we don't lose real demand (seeded with case_size 0, floored
        // to 1 at row-build time).
        foreach ($recent_rows as $row) {
            $pid = (int) $row['wc_product_id'];
            if ($pid <= 0) {
                continue;
            }
            if (!isset($products[$pid])) {
                $products[$pid] = [
                    'product_name'   => (string) $row['product_name'],
                    'weekly_history' => [],
                    'case_size'      => 0,
                ];
            }
            if ($products[$pid]['product_name'] === '') {
                $products[$pid]['product_name'] = (string) $row['product_name'];
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
        // NOTE: this loop now spans the FULL published catalog (~120+ products),
        // not just the recently-sold subset — each iteration does a wc_get_product()
        // + get_post_meta() (and has_term() in the exclusion step above). Acceptable
        // for an on-demand report; batch-prime caches here if it ever drags.
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
            // 6-week horizon + 3-week demand-proportional safety buffer = 9
            // weeks of coverage. The buffer IS these 3 extra weeks of demand;
            // the old flat per-product `buffer` meta is intentionally NOT read
            // here (it is superseded and would double-count — over-ordering).
            $adjusted_weekly = round($weighted_avg * $seasonal_index, 2);
            $projected_need  = (int) ceil($adjusted_weekly * $coverage_weeks);

            $meta = isset($product_meta[$pid]) ? $product_meta[$pid] : [];
            // Prefer the catalog case_size (authoritative once Case Count Sync has
            // run); fall back to the product-meta lookup, then the legacy postmeta,
            // and finally a floor of 1 — NEVER 0. The units/case_size division
            // below would divide by zero for a fallback-seeded (case_size 0)
            // sold-but-uncatalogued product otherwise.
            $catalog_case = (int) ($p['case_size'] ?? 0);
            $meta_case    = isset($meta['case_size']) ? (int) $meta['case_size'] : 0;
            if ($catalog_case > 0) {
                $case_size = $catalog_case;
            } elseif ($meta_case > 0) {
                $case_size = $meta_case;
            } else {
                $case_size = (int) get_post_meta($pid, 'case_size', true) ?: 1;
            }

            $wc_product    = wc_get_product($pid);
            // Catalog products with no recent sales have an empty product_name;
            // fall back to the WC product title (the WC object is already loaded
            // here for SKU/stock).
            $product_name  = $p['product_name'] !== ''
                ? $p['product_name']
                : ($wc_product ? $wc_product->get_name() : '');
            $sku           = $wc_product ? $wc_product->get_sku() : '';
            $current_stock = $wc_product ? max(0, (int) $wc_product->get_stock_quantity()) : 0;
            // _future_inventory_quantity is DELIBERATELY NOT read: it is owned by
            // a retired future-dated-inventory plugin from the old system whose
            // data is unreliable (stale/garbage). Folding it into total_available
            // inflated availability and SUPPRESSED units_needed, silently
            // under-ordering (stockout risk) on any product still carrying a
            // leftover value. Availability is now on-hand stock only.
            $total_available = $current_stock;

            $units_needed = max(0, $projected_need - $total_available);
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
                'product_name'        => $product_name,
                'weighted_avg_weekly' => $weighted_avg,
                'seasonal_index'      => round($seasonal_index, 2),
                'adjusted_weekly'     => $adjusted_weekly,
                'projected_need'      => $projected_need,
                'current_stock'       => $current_stock,
                // future_inventory field removed — the retired plugin's meta is
                // no longer read (see total_available above). total_available is
                // now on-hand stock only.
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
     * NOTE (2026-07-11): no production caller since the forecast-tab preview
     * was removed (one-click draft flow); kept as a tested pure exporter —
     * the draft detail page builds its CSV client-side via Report.csvCell.
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

        // MealsDB_CSV::row() neutralises formula injection in every cell
        // — matters here because product_name is user-controlled and
        // seasonal_note is a server-generated string that could be
        // edited by a future caller.
        fwrite($handle, MealsDB_CSV::row([
            'SKU', 'Product Name', 'Avg/Week', 'Seasonal Idx', 'Adj/Week',
            'Projected', 'Stock',
            'Available', 'Units Needed', 'Case Size', 'Cases', 'Order Qty', 'Note',
        ]) . "\n");

        $total_cases = 0;

        foreach ($po_rows as $row) {
            fwrite($handle, MealsDB_CSV::row([
                $row['sku'],
                $row['product_name'],
                $row['weighted_avg_weekly'],
                $row['seasonal_index'],
                $row['adjusted_weekly'],
                $row['projected_need'],
                $row['current_stock'],
                $row['total_available'],
                $row['units_needed'],
                $row['case_size'],
                $row['cases_to_buy'],
                $row['order_quantity'],
                $row['seasonal_note'],
            ]) . "\n");
            $total_cases += $row['cases_to_buy'];
        }

        // Blank separator row then grand-total row. The Cases total sits under
        // the 'Cases' column (index 11 of the 14-column layout).
        fwrite($handle, "\n");
        fwrite($handle, MealsDB_CSV::row([
            'TOTAL', '', '', '', '', '', '', '', '', '', '', $total_cases, '', '',
        ]) . "\n");

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /**
     * OPTIONAL freight optimisation: snap a purchase order to whole Apetito
     * pallets to avoid paying to ship (or waste the deck space of) a partial
     * pallet.
     *
     * This is a PURE post-processor over generate_purchase_order() rows — it
     * takes no I/O, reads no settings, and NEVER mutates the base forecast in
     * place (returns a modified COPY). It is only invoked when the operator
     * ticks the "optimise for pallets" toggle; the untouched forecast remains
     * the default.
     *
     * Algorithm (deterministic):
     *   base_cases = Σ cases_to_buy;  partial = base_cases % CASES_PER_PALLET.
     *   - partial == 0            → nothing to do (already whole pallets).
     *   - partial >= ⅓ pallet     → FILL up to the next whole pallet
     *                               (gap = CASES_PER_PALLET - partial).
     *   - partial <  ⅓ pallet     → DROP the partial cases back off
     *                               (gap = partial) IF it can be done without
     *                               breaching the 7-week floor; if the drop
     *                               gets stuck, ROUND UP instead (fill from
     *                               base) — never leave stock under-covered to
     *                               chase a round pallet.
     *
     * Each single-case step is applied to the current least-covered (fill) or
     * most-covered (drop) ELIGIBLE row, then the pool is RE-RANKED before the
     * next step so the adjustment spreads across many products instead of
     * dumping a whole pallet onto one SKU. Coverage = (current_stock +
     * order_quantity) / adjusted_weekly weeks — the same metric the base
     * forecast targets (9 weeks), floored at 7 and ceilinged at 52 so freight
     * tuning can never create a stockout or a year of dead stock.
     *
     * current_stock (NOT total_available) is used deliberately, per the
     * directive: the retired future-inventory meta is unreliable, so on-hand
     * stock is the real availability. This is consistent with the companion
     * change that stops the base forecast folding that meta into
     * total_available (after which current_stock == total_available anyway).
     *
     * Changed rows are tagged with `freight_delta_cases` (+added / -removed) and
     * a note appended to `seasonal_note` so the operator can see exactly what
     * the pass moved.
     *
     * @param array<int, array<string, mixed>> $rows generate_purchase_order() output
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public static function optimize_po_for_pallets(array $rows): array {
        $cpp = (int) MealsDB_Operational_Constants::APETITO_CASES_PER_PALLET;

        $base_cases = 0;
        foreach ($rows as $r) {
            $base_cases += max(0, (int) ($r['cases_to_buy'] ?? 0));
        }

        // Guard: no pallet size configured, or already sitting on whole pallets.
        $partial = $cpp > 0 ? ($base_cases % $cpp) : 0;
        if ($cpp <= 0 || $partial === 0) {
            return [
                'rows'    => $rows,
                'summary' => [
                    'base_cases'    => $base_cases,
                    'final_cases'   => $base_cases,
                    'partial'       => $partial,
                    'pallets_base'  => $cpp > 0 ? $base_cases / $cpp : 0.0,
                    'pallets'       => $cpp > 0 ? $base_cases / $cpp : 0.0,
                    'action'        => 'none',
                    'cases_changed' => 0,
                    'incomplete'    => false,
                ],
            ];
        }

        // Coverage in weeks for a row at its CURRENT order_quantity. A row with
        // no demand (adjusted_weekly <= 0) is treated as infinitely covered so
        // the fill picker never selects it and the drop picker always would —
        // but the eligibility gate below excludes it from both anyway.
        $coverage = static function (array $row): float {
            $aw = (float) ($row['adjusted_weekly'] ?? 0);
            if ($aw <= 0) {
                return INF;
            }
            return ((int) ($row['current_stock'] ?? 0) + (int) ($row['order_quantity'] ?? 0)) / $aw;
        };

        // A row can only be freight-adjusted if it has real demand AND a real
        // case size (case_size 0 rows are the divide-by-zero floor case; never
        // touch them).
        $eligible = static function (array $row): bool {
            return (float) ($row['adjusted_weekly'] ?? 0) > 0 && (int) ($row['case_size'] ?? 0) > 0;
        };

        // Add $gap whole cases, one at a time, each to the least-covered row
        // that stays at/under the 52-week ceiling. Returns cases actually added
        // (< $gap only if every eligible row hit the ceiling — near-impossible).
        $do_fill = static function (array &$work, int $gap) use ($coverage, $eligible): int {
            $added = 0;
            for ($i = 0; $i < $gap; $i++) {
                $pick = null;
                $pick_cov = INF;
                foreach ($work as $idx => $row) {
                    if (!$eligible($row)) {
                        continue;
                    }
                    $cs = (int) $row['case_size'];
                    $aw = (float) $row['adjusted_weekly'];
                    $after = ((int) $row['current_stock'] + (int) $row['order_quantity'] + $cs) / $aw;
                    if ($after > 52.0) {
                        continue; // ceiling
                    }
                    $cov = $coverage($row);
                    if ($cov < $pick_cov) {
                        $pick_cov = $cov;
                        $pick = $idx;
                    }
                }
                if ($pick === null) {
                    break; // fill-stuck: everything at the ceiling
                }
                $work[$pick]['order_quantity'] = (int) $work[$pick]['order_quantity'] + (int) $work[$pick]['case_size'];
                $work[$pick]['cases_to_buy']   = (int) $work[$pick]['cases_to_buy'] + 1;
                $added++;
            }
            return $added;
        };

        // Remove $gap whole cases, one at a time, each from the most-covered row
        // that stays at/above the 7-week floor and still has a case to give.
        // Returns cases actually removed (< $gap = drop-stuck).
        $do_drop = static function (array &$work, int $gap) use ($coverage, $eligible): int {
            $removed = 0;
            for ($i = 0; $i < $gap; $i++) {
                $pick = null;
                $pick_cov = -INF;
                foreach ($work as $idx => $row) {
                    if (!$eligible($row) || (int) $row['cases_to_buy'] < 1) {
                        continue;
                    }
                    $cs = (int) $row['case_size'];
                    $aw = (float) $row['adjusted_weekly'];
                    $after = ((int) $row['current_stock'] + (int) $row['order_quantity'] - $cs) / $aw;
                    if ($after < 7.0) {
                        continue; // floor
                    }
                    $cov = $coverage($row);
                    if ($cov > $pick_cov) {
                        $pick_cov = $cov;
                        $pick = $idx;
                    }
                }
                if ($pick === null) {
                    break; // drop-stuck
                }
                $work[$pick]['order_quantity'] = (int) $work[$pick]['order_quantity'] - (int) $work[$pick]['case_size'];
                $work[$pick]['cases_to_buy']   = (int) $work[$pick]['cases_to_buy'] - 1;
                $removed++;
            }
            return $removed;
        };

        if ($partial >= $cpp / 3) {
            // Nearer the next pallet than the last — round UP.
            $action = 'fill';
            $work   = $rows;
            $gap    = $cpp - $partial;
            $done   = $do_fill($work, $gap);
            $incomplete = ($done < $gap);
        } else {
            // Small partial — try to shed it back to the pallet below.
            $action = 'drop';
            $work   = $rows;
            $gap    = $partial;
            $done   = $do_drop($work, $gap);
            if ($done < $gap) {
                // Drop-stuck: the shed would push a product under the 7-week
                // floor. Round UP from the ORIGINAL base instead (never leave
                // stock under-covered to chase a round pallet). Discard the
                // partial drops by restarting from $rows.
                $action = 'fill';
                $work   = $rows;
                $gap    = $cpp - $partial;
                $done   = $do_fill($work, $gap);
                $incomplete = ($done < $gap);
            } else {
                $incomplete = false;
            }
        }

        // Tag changed rows and total the result.
        $cases_changed = 0;
        $final_cases   = 0;
        foreach ($work as $idx => &$row) {
            $delta = (int) $row['cases_to_buy'] - (int) ($rows[$idx]['cases_to_buy'] ?? 0);
            $row['freight_delta_cases'] = $delta;
            if ($delta !== 0) {
                $cases_changed += abs($delta);
                $noun = abs($delta) === 1 ? 'case' : 'cases';
                $tag  = $delta > 0
                    ? sprintf('Freight fill +%d %s', $delta, $noun)
                    : sprintf('Freight trim %d %s', $delta, $noun);
                $existing = (string) ($row['seasonal_note'] ?? '');
                $row['seasonal_note'] = $existing === '' ? $tag : $existing . ' | ' . $tag;
            }
            $final_cases += max(0, (int) $row['cases_to_buy']);
        }
        unset($row);

        return [
            'rows'    => $work,
            'summary' => [
                'base_cases'    => $base_cases,
                'final_cases'   => $final_cases,
                'partial'       => $partial,
                'pallets_base'  => $base_cases / $cpp,
                'pallets'       => $final_cases / $cpp,
                'action'        => $action,
                'cases_changed' => $cases_changed,
                'incomplete'    => $incomplete,
            ],
        ];
    }

    /**
     * Reconcile client contributions: expected (from meals_clients) vs actual paid (fee product).
     *
     * @param string $start_date Y-m-d
     * @param string $end_date   Y-m-d
     * @return array ['rows' => [...], 'summary' => [...]]
     */
    public function contribution_reconciliation(string $start_date, string $end_date): array {
        if (!self::is_authorized_to_read_reports()) {
            return ['rows' => [], 'summary' => self::empty_contribution_summary()];
        }

        if (!$this->order_query instanceof MealsDB_WC_Order_Query) {
            return ['rows' => [], 'summary' => self::empty_contribution_summary()];
        }

        // BC-4: the client contribution is a per-BILLING-MONTH charge (applied to
        // the first qualifying order of the month). "Expected" is one month's
        // flat contribution, so reconciling it over a multi-month range compares
        // one month's expected against several months' paid and ALWAYS reports a
        // false discrepancy. Require the range to fall within a single calendar
        // month. (Delivery-fee reconciliation, by contrast, scales expected by
        // the order count, so it is range-safe.)
        if (substr((string) $start_date, 0, 7) !== substr((string) $end_date, 0, 7)) {
            return [
                'rows'    => [],
                'summary' => self::empty_contribution_summary(),
                'error'   => __('Contribution reconciliation must be run for a single calendar month.', 'meals-db'),
            ];
        }

        // BC-4: only SDNB/Veteran clients are billed the contribution by
        // MealsDB_Order_Fees. A Private client with a non-zero client_contribution
        // column would otherwise show as permanently "underpaid" (paid $0).
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $clients = $this->wpdb->get_results(
            "SELECT client_id, wp_user_id, first_name, last_name, client_contribution, client_type
             FROM `{$clients_table}`
             WHERE client_contribution > 0 AND active = 1 AND wp_user_id > 0
               AND client_type IN ('SDNB', 'Veteran')",
            ARRAY_A
        );

        if (!is_array($clients) || empty($clients)) {
            return ['rows' => [], 'summary' => self::empty_contribution_summary()];
        }

        // get_total_fee_paid_for_user covers both fee mechanisms:
        // legacy product-ID line items (Enzebra, product 5675) AND
        // Quick Order's WC_Order_Item_Fee named "Client Contribution".
        // A previous version only checked the legacy line items, so
        // Quick Order contributions showed as $0 paid and the report
        // surfaced false discrepancies. CRIT-3 in the v1.0.346 audit.
        $rows = [];
        $total_expected   = 0.0;
        $total_paid       = 0.0;

        foreach ($clients as $client) {
            $wp_user_id  = (int) $client['wp_user_id'];
            $expected    = (float) $client['client_contribution'];
            $actual_paid = $this->order_query->get_total_fee_paid_for_user(
                $wp_user_id, 'contribution', $start_date, $end_date
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
        if (!self::is_authorized_to_read_reports()) {
            return ['rows' => [], 'summary' => self::empty_delivery_summary()];
        }

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

        // get_total_fee_paid_for_user covers both fee mechanisms:
        // legacy product-ID line items (product 4122) AND Quick Order's
        // WC_Order_Item_Fee named "Delivery Fee". See contribution
        // reconciliation above and CRIT-3 in the v1.0.346 audit.
        $rows = [];
        $total_owed = 0.0;
        $total_paid = 0.0;

        foreach ($clients as $client) {
            $wp_user_id   = (int) $client['wp_user_id'];
            $delivery_fee = (float) $client['delivery_fee'];
            $num_orders   = $this->order_query->get_order_count_for_user($wp_user_id, $start_date, $end_date);
            $owed         = round($num_orders * $delivery_fee, 2);
            $actual_paid  = $this->order_query->get_total_fee_paid_for_user(
                $wp_user_id, 'delivery_fee', $start_date, $end_date
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

        if (!self::is_authorized_to_read_reports()) {
            return $empty;
        }

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
            // first_name/last_name are stored plaintext in the current schema
            // but this report historically called decrypt() directly, which
            // would throw on any plaintext row. safe_decrypt is tolerant.
            $row['first_name'] = !empty($row['first_name']) ? MealsDB_Encryption::safe_decrypt($row['first_name']) : '';
            $row['last_name']  = !empty($row['last_name']) ? MealsDB_Encryption::safe_decrypt($row['last_name']) : '';
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

        // WC category IDs for fallback. Sourced from MealsDB_Operational_Constants
        // (the documented single source of truth for category IDs) so a taxonomy
        // rebuild that shifts an ID is fixed in one place instead of silently
        // misclassifying mains/sides here (U05-reports-7). Values are identical to
        // the previous literals: Mains=35; Sides=25,23,37,43 (Dessert,Cereal,Muffins,Soup).
        $mains_cat_ids = [MealsDB_Operational_Constants::CATEGORY_ID_MAINS];
        $sides_cat_ids = [
            MealsDB_Operational_Constants::CATEGORY_ID_DESSERT,
            MealsDB_Operational_Constants::CATEGORY_ID_CEREAL,
            MealsDB_Operational_Constants::CATEGORY_ID_MUFFINS,
            MealsDB_Operational_Constants::CATEGORY_ID_SOUP,
        ];

        // 3. Date range for WC order query.
        // Half-open interval [start, end_exclusive): start of the start day to
        // start of the day AFTER the end day. The previous `<= '... 23:59:59'`
        // bound dropped any order in the final sub-second and was inconsistent
        // with the half-open convention used elsewhere in this file (audit MAJ-5).
        $start_datetime = gmdate('Y-m-d 00:00:00', strtotime($start_date));
        $end_datetime   = gmdate('Y-m-d 00:00:00', strtotime($end_date . ' +1 day'));

        $orders_table     = $this->wpdb->prefix . 'wc_orders';

        $rows = [];
        $grand = self::empty_private_report_totals();

        // Build the set of wp_user_ids in one pass, and look up each client
        // row by user_id for the aggregation phase.
        $clients_by_user = [];
        $wp_user_ids     = [];
        foreach ($clients as $client) {
            $uid = (int) $client['wp_user_id'];
            if ($uid > 0) {
                $clients_by_user[$uid] = $client;
                $wp_user_ids[]         = $uid;
            }
        }

        if (empty($wp_user_ids)) {
            return ['rows' => [], 'grand_totals' => $grand];
        }

        // One query for every qualifying order across every client — the
        // previous implementation called wc_get_order() once per order
        // inside a nested loop, which on a month of private-customer
        // activity meant thousands of HPOS fetches. The batched path issues
        // three queries regardless of client count.
        $user_placeholders = implode(',', array_fill(0, count($wp_user_ids), '%d'));
        $order_sql = $this->wpdb->prepare(
            "SELECT id, customer_id, tax_amount
             FROM {$orders_table}
             WHERE customer_id IN ($user_placeholders)
               AND date_created_gmt >= %s AND date_created_gmt < %s
               AND status IN ('wc-processing', 'wc-completed', 'wc-paid')
               AND type = 'shop_order'",
            ...array_merge($wp_user_ids, [$start_datetime, $end_datetime])
        );
        $order_rows = $this->wpdb->get_results($order_sql, ARRAY_A);

        if (empty($order_rows)) {
            return ['rows' => [], 'grand_totals' => $grand];
        }

        $orders_by_id   = [];
        $order_ids      = [];
        $orders_by_user = [];
        foreach ($order_rows as $or) {
            $oid = (int) $or['id'];
            $uid = (int) $or['customer_id'];
            $orders_by_id[$oid]    = $or;
            $order_ids[]           = $oid;
            $orders_by_user[$uid][] = $oid;
        }

        // Line items across every order, via the canonical order-query layer so
        // the join shape, status rules, AND the refund subtraction live in ONE
        // place (consolidation of the hand-rolled items query). NOTE: quantity is
        // now NET of refunds (BC-6) — a partial refund reduces the mains/sides
        // counts below; the before-tax subtotal remains the original
        // _line_subtotal (refund money handling is out of scope for this report).
        $item_rows = ($this->order_query instanceof MealsDB_WC_Order_Query)
            ? $this->order_query->get_order_items($order_ids)
            : [];

        // Aggregate per-user totals from the two result sets. The original
        // code summed WC_Order::get_subtotal() (= sum of _line_subtotal
        // across line_item entries) and WC_Order::get_total_tax() (= the
        // HPOS tax_amount column). Preserve those exact semantics.
        $user_totals = [];
        foreach ($wp_user_ids as $uid) {
            $user_totals[$uid] = [
                'mains' => 0, 'sides' => 0,
                'before_tax' => 0.0, 'tax' => 0.0,
            ];
        }

        // Tax: one entry per order, summed per customer.
        foreach ($order_rows as $or) {
            $uid = (int) $or['customer_id'];
            if (isset($user_totals[$uid])) {
                $user_totals[$uid]['tax'] += (float) $or['tax_amount'];
            }
        }

        // Mains/sides + before-tax subtotal from the item rows. Fall back
        // to product_cat taxonomy when meals_products doesn't know the
        // product — has_term() is internally cached by WP per term/object
        // after the first hit.
        foreach ($item_rows as $ir) {
            $oid = (int) $ir['order_id'];
            if (!isset($orders_by_id[$oid])) {
                continue;
            }
            $uid = (int) $orders_by_id[$oid]['customer_id'];
            if (!isset($user_totals[$uid])) {
                continue;
            }

            $product_id = (int) $ir['wc_product_id']; // get_order_items() key
            $qty        = (int) $ir['quantity'];      // NET of refunds (BC-6)
            $user_totals[$uid]['before_tax'] += (float) $ir['line_subtotal'];

            if (isset($product_type_map[$product_id])) {
                if ($product_type_map[$product_id] === 'meal') {
                    $user_totals[$uid]['mains'] += $qty;
                } elseif ($product_type_map[$product_id] === 'side') {
                    $user_totals[$uid]['sides'] += $qty;
                }
            } elseif (function_exists('has_term')) {
                if (has_term($mains_cat_ids, 'product_cat', $product_id)) {
                    $user_totals[$uid]['mains'] += $qty;
                } elseif (has_term($sides_cat_ids, 'product_cat', $product_id)) {
                    $user_totals[$uid]['sides'] += $qty;
                }
            }
        }

        foreach ($wp_user_ids as $wp_user_id) {
            if (empty($orders_by_user[$wp_user_id])) {
                continue;
            }
            $client           = $clients_by_user[$wp_user_id];
            $total_mains      = $user_totals[$wp_user_id]['mains'];
            $total_sides      = $user_totals[$wp_user_id]['sides'];
            $total_before_tax = $user_totals[$wp_user_id]['before_tax'];
            $total_tax        = $user_totals[$wp_user_id]['tax'];

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

        // first_name / last_name are decrypted user-supplied strings —
        // route through MealsDB_CSV::row() so a client whose first_name
        // starts with '=' or '@' can't ship executable formula payloads
        // in the private customer sales export.
        fwrite($handle, MealsDB_CSV::row([
            'First Name', 'Last Name', 'Total Mains', 'Total Sides',
            'Total Purchased Before Tax', 'Total Tax Charged', 'Final Total',
        ]) . "\n");

        $rows = $data['rows'] ?? [];
        foreach ($rows as $row) {
            fwrite($handle, MealsDB_CSV::row([
                $row['first_name'],
                $row['last_name'],
                $row['total_mains'],
                $row['total_sides'],
                number_format($row['total_before_tax'], 2, '.', ''),
                number_format($row['total_tax'], 2, '.', ''),
                number_format($row['final_total'], 2, '.', ''),
            ]) . "\n");
        }

        // Grand Total row.
        $grand = $data['grand_totals'] ?? self::empty_private_report_totals();
        fwrite($handle, MealsDB_CSV::row([
            'Grand Total', '',
            $grand['total_mains'],
            $grand['total_sides'],
            number_format($grand['total_before_tax'], 2, '.', ''),
            number_format($grand['total_tax'], 2, '.', ''),
            number_format($grand['final_total'], 2, '.', ''),
        ]) . "\n");

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

        if (!self::is_authorized_to_read_reports()) {
            return ['errors' => [], 'summary' => $empty_summary];
        }

        if (!$this->wpdb instanceof wpdb) {
            return ['errors' => [], 'summary' => $empty_summary];
        }

        // Valid zones for the invalid_zone check are the keys of the
        // operator-editable delivery schedule (mealsdb_zone_delivery_schedule),
        // which is keyed by delivery_area_name — the same vocabulary this report
        // validates against. The old mealsdb_valid_zones option was READ here but
        // written nowhere (no settings UI, no update_option), so once an operator
        // added or renamed a zone in the schedule this check kept validating
        // against a frozen default and emitted false 'invalid_zone' positives —
        // two sources of truth (audit U05-reports-8). Fall back to the historical
        // default list only when the schedule is empty/unset.
        $zone_schedule = get_option('mealsdb_zone_delivery_schedule', []);
        $valid_zones   = (is_array($zone_schedule) && !empty($zone_schedule))
            ? array_keys($zone_schedule)
            : ['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5', 'Zone 6'];

        // 1. Batch-fetch all orders in the date range from HPOS.
        $orders_table = $this->wpdb->prefix . 'wc_orders';
        // Half-open interval [start, end_exclusive) — see private_customer_report
        // above; the old `<= '... 23:59:59'` bound was inconsistent and clipped
        // the final sub-second (audit MAJ-5).
        $start_dt = gmdate('Y-m-d 00:00:00', strtotime($start_date));
        $end_dt   = gmdate('Y-m-d 00:00:00', strtotime($end_date . ' +1 day'));

        $order_rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, customer_id, date_created_gmt, status
             FROM {$orders_table}
             WHERE date_created_gmt >= %s AND date_created_gmt < %s
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
                        delivery_street_name
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

        // 2b. Batch-fetch the WC billing name + shipping address_1 for EVERY
        // order in ONE query against the HPOS addresses table, instead of a
        // per-order wc_get_order() inside the loop below. The loop only ever
        // reads three string fields (billing first/last name, shipping
        // address_1), but wc_get_order() fully hydrates each order (items,
        // meta, every address); on a wide range (~16k orders/yr) that was up to
        // 16k HPOS fetches in one synchronous request, risking
        // max_execution_time. This mirrors the batching private_customer_report
        // already uses above (audit U05-reports-9 / X4-sql-efficiency-1).
        $billing_names  = []; // order_id => ['first' => string, 'last' => string]
        $shipping_addr1 = []; // order_id => string
        $all_order_ids  = array_map('intval', array_column($order_rows, 'id'));
        if (!empty($all_order_ids)) {
            $addresses_table = $this->wpdb->prefix . 'wc_order_addresses';
            $addr_ph         = implode(',', array_fill(0, count($all_order_ids), '%d'));
            $addr_rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT order_id, address_type, first_name, last_name, address_1
                 FROM `{$addresses_table}`
                 WHERE order_id IN ({$addr_ph})
                   AND address_type IN ('billing', 'shipping')",
                ...$all_order_ids
            ), ARRAY_A);
            if (is_array($addr_rows)) {
                foreach ($addr_rows as $ar) {
                    $oid = (int) $ar['order_id'];
                    if ($ar['address_type'] === 'billing') {
                        $billing_names[$oid] = [
                            'first' => (string) $ar['first_name'],
                            'last'  => (string) $ar['last_name'],
                        ];
                    } elseif ($ar['address_type'] === 'shipping') {
                        $shipping_addr1[$oid] = (string) $ar['address_1'];
                    }
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

            // WC billing/shipping fields from the batched maps (2b) — a missing
            // address row means the field is genuinely empty, which is exactly
            // what WC_Order::get_billing_first_name() etc. returned before and is
            // itself one of the errors this report flags.
            $billing_first = $billing_names[$order_id]['first'] ?? '';
            $billing_last  = $billing_names[$order_id]['last'] ?? '';
            $ship_addr1    = $shipping_addr1[$order_id] ?? '';

            $order_errors = [];

            if (!$client && $customer_id > 0) {
                // No meals_clients record — still check WC fields.
                $customer_name = trim($billing_first . ' ' . $billing_last);

                if (empty(trim($billing_first))) {
                    $order_errors[] = ['type' => 'missing_first_name', 'detail' => 'Billing first name is empty'];
                }
                if (empty(trim($billing_last))) {
                    $order_errors[] = ['type' => 'missing_last_name', 'detail' => 'Billing last name is empty'];
                }
                if (empty(trim($ship_addr1))) {
                    $order_errors[] = ['type' => 'missing_address', 'detail' => 'No shipping/delivery address'];
                }

                $order_errors[] = ['type' => 'no_client_record', 'detail' => 'Customer not found in meals_clients'];
            } elseif ($client) {
                // Have a meals_clients record — check against it.
                $customer_name = trim($billing_first . ' ' . $billing_last);

                // Check 1: Missing first name.
                if (empty(trim($billing_first))) {
                    $order_errors[] = ['type' => 'missing_first_name', 'detail' => 'Billing first name is empty'];
                }

                // Check 2: Missing last name.
                if (empty(trim($billing_last))) {
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
                    if (empty(trim($ship_addr1))) {
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
                $customer_name = trim($billing_first . ' ' . $billing_last);

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

        // Half-open interval: start of the start day (inclusive) to start of the
        // day AFTER the end day (exclusive). Previously this returned the end day
        // at 00:00:00 and the queries compared with `<= %s`, so an order at any
        // time after midnight on the final day was `> end` and silently excluded —
        // "report through Jan 31" dropped all of Jan 31 (audit MAJ-5). gmdate/UTC
        // so the boundary does not drift across DST (mirrors
        // MealsDB_WC_Order_Query::get_orders_for_users). Every consumer must use
        // `>= start AND < end_exclusive`; the key is renamed from `end` to
        // `end_exclusive` deliberately, so any missed call site trips an
        // undefined-index notice rather than silently inheriting old semantics.
        return [
            'start'         => gmdate('Y-m-d 00:00:00', $start),
            'end_exclusive' => gmdate('Y-m-d 00:00:00', strtotime('+1 day', $end)),
        ];
    }

    /**
     * Over-allowance spillover report.
     *
     * Lists deliveries where an order's meals could not fit entirely within
     * the delivery month's allowance and spilled into the next month, OR
     * where the spill could not fit there either (multi-month-spillover
     * error, logged by MealsDB_Allocation_Rebuilder).
     *
     * Source: rows in meals_allocation_errors (the rebuilder logs there on
     * multi-month overflow) PLUS deliveries with rows in delivery_allocations
     * for both the delivery month and the next month referencing the same
     * order (the "normal" single-month spill case — not an error, just
     * visibility).
     *
     * @param string $billing_month YYYY-MM (the delivery month being reported).
     * @return array<int, array<string,mixed>> One row per (client, order) that spilled,
     *   with: client_id, client_name, wc_order_id, delivery_date,
     *   mains_in_month, sides_in_month, mains_spilled, sides_spilled,
     *   is_multi_month_error (bool), error_message (string|null).
     */
    public function spillover_report(string $billing_month): array {
        // Defence-in-depth: the AJAX handler already gates on capability, but a
        // direct service caller (WP-CLI, REST, custom cron) must not reach the
        // PII-bearing client-name/order-id queries below without the plugin's
        // required capability. Mirrors every other report method in this class.
        if (!self::is_authorized_to_read_reports()) {
            return [];
        }

        // Strict month validation: a bare \d{2} would accept impossible months
        // like 2025-13 (DateTime throws -> 500) and 2025-00 (DateTime silently
        // normalises to the previous December, querying the wrong month).
        // MealsDB_Helpers::is_valid_ym() applies the same YYYY-MM + 01-12 check
        // used at the AJAX boundary, so bad input is rejected cleanly here
        // before any DateTime/SQL work (U05-reports-12; behavior-identical to
        // the previous inline /^\d{4}-(0[1-9]|1[0-2])$/).
        if (!MealsDB_Helpers::is_valid_ym($billing_month)) {
            return [];
        }

        $next_month_obj  = new DateTime($billing_month . '-01');
        $next_month_obj->modify('+1 month');
        $next_month      = $next_month_obj->format('Y-m');

        $alloc_table    = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);
        $errors_table   = MealsDB_DB::get_table_name(MealsDB_Tables::ALLOCATION_ERRORS);
        $clients_table  = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // 1. Single-month spills: orders whose delivery date is in the
        //    selected month but which have rows in BOTH this month and
        //    next month (the rebuilder's spill behaviour). DATE_FORMAT
        //    handles the delivery_date -> YYYY-MM extraction inline.
        $spill_rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT
                a1.client_id,
                a1.wc_order_id,
                a1.delivery_date,
                a1.mains_count    AS mains_in_month,
                a1.sides_count    AS sides_in_month,
                a2.mains_count    AS mains_spilled,
                a2.sides_count    AS sides_spilled,
                c.first_name,
                c.last_name
             FROM `{$alloc_table}` a1
             INNER JOIN `{$alloc_table}` a2
                     ON a2.wc_order_id   = a1.wc_order_id
                    AND a2.client_id     = a1.client_id
                    AND a2.billing_month = %s
             LEFT JOIN `{$clients_table}` c ON c.client_id = a1.client_id
             WHERE a1.billing_month = %s
               AND DATE_FORMAT(a1.delivery_date, '%%Y-%%m') = %s
               AND (a2.mains_count > 0 OR a2.sides_count > 0)
             ORDER BY a1.delivery_date ASC, c.last_name ASC, c.first_name ASC",
            $next_month,
            $billing_month,
            $billing_month
        ), ARRAY_A);

        $out = [];
        foreach ((array) $spill_rows as $r) {
            $out[] = [
                'client_id'            => (int) $r['client_id'],
                'client_name'          => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'wc_order_id'          => (int) $r['wc_order_id'],
                'delivery_date'        => (string) $r['delivery_date'],
                'mains_in_month'       => (int) $r['mains_in_month'],
                'sides_in_month'       => (int) $r['sides_in_month'],
                'mains_spilled'        => (int) $r['mains_spilled'],
                'sides_spilled'        => (int) $r['sides_spilled'],
                'is_multi_month_error' => false,
                'error_message'        => null,
            ];
        }

        // 2. Multi-month-spillover errors: rebuilder logs to allocation_errors
        //    when even the next month can't absorb the overflow. These are
        //    real problems that need attention.
        $err_rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT
                e.client_id,
                e.wc_order_id,
                e.mains_unplaced,
                e.sides_unplaced,
                e.message,
                a.delivery_date,
                a.mains_count AS mains_in_month,
                a.sides_count AS sides_in_month,
                c.first_name,
                c.last_name
             FROM `{$errors_table}` e
             LEFT JOIN `{$alloc_table}` a
                    ON a.client_id     = e.client_id
                   AND a.wc_order_id   = e.wc_order_id
                   AND a.billing_month = e.billing_month
             LEFT JOIN `{$clients_table}` c ON c.client_id = e.client_id
             WHERE e.billing_month = %s
               AND e.error_type = 'multi_month_spillover'
             ORDER BY a.delivery_date ASC, c.last_name ASC",
            $billing_month
        ), ARRAY_A);

        foreach ((array) $err_rows as $r) {
            $out[] = [
                'client_id'            => (int) $r['client_id'],
                'client_name'          => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'wc_order_id'          => (int) $r['wc_order_id'],
                'delivery_date'        => (string) ($r['delivery_date'] ?? ''),
                'mains_in_month'       => (int) ($r['mains_in_month'] ?? 0),
                'sides_in_month'       => (int) ($r['sides_in_month'] ?? 0),
                'mains_spilled'        => (int) $r['mains_unplaced'],
                'sides_spilled'        => (int) $r['sides_unplaced'],
                'is_multi_month_error' => true,
                'error_message'        => (string) ($r['message'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * CSV export for the over-allowance spillover report.
     *
     * @param array<int, array<string,mixed>> $rows from spillover_report().
     */
    public function export_spillover_csv(array $rows): string {
        $out = [];
        $out[] = 'Delivery Date,Client,Order ID,Mains in Month,Sides in Month,Mains Spilled,Sides Spilled,Multi-Month Error,Error Detail';
        foreach ($rows as $r) {
            $out[] = MealsDB_CSV::row([
                $r['delivery_date'] ?? '',
                $r['client_name'] ?? '',
                $r['wc_order_id'] ?? 0,
                $r['mains_in_month'] ?? 0,
                $r['sides_in_month'] ?? 0,
                $r['mains_spilled'] ?? 0,
                $r['sides_spilled'] ?? 0,
                !empty($r['is_multi_month_error']) ? 'Yes' : 'No',
                $r['error_message'] ?? '',
            ]);
        }
        return implode("\n", $out);
    }
}
