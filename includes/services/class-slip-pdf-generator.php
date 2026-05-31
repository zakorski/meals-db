<?php
/**
 * Slip PDF Generator (Phase T)
 *
 * Produces per-order PDF slips — one slip per WC order, combined into a
 * single PDF with page breaks between orders. Two slip types:
 *
 *   - Packer slip: items table + handwritten-notes whitespace (no $)
 *   - Driver slip: packer slip + collection breakdown + customer info
 *
 * Both share the same $slip_data contract; the only difference is
 * whether the driver block is rendered into the right column.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Slip_PDF_Generator {

    private const CATEGORY_RANK_MAIN = 1;
    private const CATEGORY_RANK_SIDE = 2;
    private const CATEGORY_RANK_FEE  = 3;

    /** WC product_cat term IDs that resolve to "Side" on the slip. */
    private const SIDE_CATEGORY_IDS = [43, 37, 23, 25, 98];

    /** WC product_cat term ID for Mains. */
    private const MAIN_CATEGORY_ID = 35;

    /**
     * @var MealsDB_Delivery_Slip_Generator
     */
    private $client_query;

    /**
     * @var MealsDB_Collection_Calculator
     *
     * Held only to satisfy the directive's constructor signature; all
     * methods on the calculator are static and called directly.
     */
    private $calculator;

    public function __construct(
        MealsDB_Delivery_Slip_Generator $client_query,
        MealsDB_Collection_Calculator $calculator
    ) {
        $this->client_query = $client_query;
        $this->calculator   = $calculator;
    }

    // -----------------------------------------------------------------
    // Public entry points
    // -----------------------------------------------------------------

    public function generate_packer_slips_for_date(string $delivery_date): string {
        $clients = $this->client_query->get_clients_for_delivery_date($delivery_date);
        $orders  = $this->fetch_orders_for_clients($clients, $delivery_date, $delivery_date);
        $slips   = $this->build_slips($orders, $clients, false);
        return $this->render_pdf($slips, false);
    }

    public function generate_packer_slips_by_zones(
        array $zone_names,
        string $start_date,
        string $end_date
    ): string {
        $clients = $this->client_query->get_clients_for_zones($zone_names);
        $orders  = $this->fetch_orders_for_clients($clients, $start_date, $end_date);
        $slips   = $this->build_slips($orders, $clients, false);
        return $this->render_pdf($slips, false);
    }

    public function generate_driver_slips_for_date(string $delivery_date): string {
        $clients = $this->client_query->get_clients_for_driver_slips($delivery_date);
        $orders  = $this->fetch_orders_for_clients($clients, $delivery_date, $delivery_date);
        $slips   = $this->build_slips($orders, $clients, true);
        return $this->render_pdf($slips, true);
    }

    public function generate_driver_slips_by_zones(
        array $zone_names,
        string $start_date,
        string $end_date
    ): string {
        $clients = $this->client_query->get_clients_for_zones_driver($zone_names);
        $orders  = $this->fetch_orders_for_clients($clients, $start_date, $end_date);
        $slips   = $this->build_slips($orders, $clients, true);
        return $this->render_pdf($slips, true);
    }

    // -----------------------------------------------------------------
    // Pipeline
    // -----------------------------------------------------------------

    private function fetch_orders_for_clients(array $clients, string $start_date, string $end_date): array {
        if (empty($clients)) {
            return [];
        }
        // Both slip paths select on the DELIVERY basis (the client's
        // delivery_day + frequency), NOT the order creation date. Single-date
        // is the degenerate range [D, D]; the zone/date-range path is the same
        // occurrence filter over [start, end]. Routing both through one method
        // closes GUI-SLIP-RANGE: the range path used to keep the raw
        // creation-date query (get_orders_for_range), so a Dec-3 range slip
        // pulled every order CREATED in the window and printed scattered
        // delivery dates — only MAJ-6's single-date path had the fix. A slip
        // is about what ships on a day, so an order-ahead order must land on
        // the slip for the day it is DELIVERED.
        return $this->client_query->get_orders_for_delivery_range($clients, $start_date, $end_date);
    }

    /**
     * Build $slip_data for every order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function build_slips(array $orders, array $clients, bool $include_driver): array {
        if (empty($orders)) {
            return [];
        }

        $fee_ids     = $this->get_fee_product_ids();
        $overage_ids = $this->get_overage_product_ids();

        // Pre-fetch freezer order meta for every product on every order.
        $product_ids = [];
        foreach ($orders as $order) {
            foreach ($order['items'] as $item) {
                $pid = (int) $item['wc_product_id'];
                if ($pid > 0) {
                    $product_ids[$pid] = $pid;
                }
            }
        }
        $freezer_orders = $this->get_freezer_orders(array_values($product_ids));
        $product_skus   = $this->get_product_skus(array_values($product_ids));

        $slips = [];
        foreach ($orders as $order) {
            $uid    = (int) $order['wp_user_id'];
            $client = $clients[$uid] ?? null;
            if (!$client) {
                continue;
            }

            $slip = $this->build_single_slip(
                $order,
                $client,
                $fee_ids,
                $overage_ids,
                $freezer_orders,
                $product_skus,
                $include_driver
            );
            if ($slip !== null) {
                $slips[] = $slip;
            }
        }

        return $slips;
    }

    private function build_single_slip(
        array $order,
        array $client,
        array $fee_ids,
        array $overage_ids,
        array $freezer_orders,
        array $product_skus,
        bool $include_driver
    ): ?array {
        $items = $this->build_items($order['items'], $fee_ids, $overage_ids, $freezer_orders, $product_skus);

        $totals = $this->compute_totals($items);

        $order_id    = (int) ($order['order_id'] ?? 0);
        $order_date  = $order['date_created_gmt'] ?? $order['order_date'] ?? '';
        $delivery_dt = $this->resolve_delivery_date($order, $order_date);

        $additional_notes = $this->fetch_customer_note($order_id);
        $display_number   = $this->resolve_order_display_number($order_id);

        $slip = [
            'initials'         => (string) ($client['delivery_initials'] ?? ''),
            'zone'             => (string) ($client['delivery_area_name'] ?? ''),
            'order_number'     => '#' . $display_number,
            'delivery_date'    => $this->format_long_date($delivery_dt),
            'items'            => $items,
            'total_items'      => $totals['total_items'],
            'total_mains'      => $totals['total_mains'],
            'total_sides'      => $totals['total_sides'],
            'additional_notes' => $additional_notes,
        ];

        if ($include_driver) {
            $slip['driver'] = $this->build_driver_block($order, $client, $delivery_dt);
        }

        return $slip;
    }

    /**
     * Build the items array per the SKU + sort rules in the directive.
     *
     * @return array<int, array<string, mixed>>
     */
    private function build_items(
        array $line_items,
        array $fee_ids,
        array $overage_ids,
        array $freezer_orders,
        array $product_skus
    ): array {
        $contrib_id = (int) $fee_ids['client_contribution'];
        $deliv_id   = (int) $fee_ids['delivery_fee'];

        $items = [];
        foreach ($line_items as $item) {
            $pid = (int) ($item['wc_product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            $name = (string) ($item['order_item_name'] ?? '');

            // Defensive filter: legacy overage products never go on slips.
            if ($pid > 0 && in_array($pid, $overage_ids, true)) {
                continue;
            }

            if ($pid === $contrib_id && $contrib_id > 0) {
                $items[] = [
                    'sku'          => 'CONT',
                    'qty'          => $qty,
                    'product_name' => $name,
                    'category'     => 'Fee',
                    'sort_key'     => [self::CATEGORY_RANK_FEE, 0, 'CONT'],
                ];
                continue;
            }
            if ($pid === $deliv_id && $deliv_id > 0) {
                $items[] = [
                    'sku'          => 'FEE',
                    'qty'          => $qty,
                    'product_name' => $name,
                    'category'     => 'Fee',
                    'sort_key'     => [self::CATEGORY_RANK_FEE, 1, 'FEE'],
                ];
                continue;
            }

            $sku      = (string) ($product_skus[$pid] ?? '');
            $category = $this->resolve_category($pid);
            $rank     = $category === 'Main'
                ? self::CATEGORY_RANK_MAIN
                : self::CATEGORY_RANK_SIDE;
            $freezer  = isset($freezer_orders[$pid]) ? (int) $freezer_orders[$pid] : 9999;

            $items[] = [
                'sku'          => $sku,
                'qty'          => $qty,
                'product_name' => $name,
                'category'     => $category,
                'sort_key'     => [$rank, $freezer, $sku],
            ];
        }

        usort($items, static function ($a, $b) {
            return $a['sort_key'] <=> $b['sort_key'];
        });

        return $items;
    }

    /**
     * Resolve a product's slip category. Defaults to Side when the
     * product isn't tagged with Mains — operators have asked that
     * uncategorised products land in the side group rather than getting
     * dropped, since a missing taxonomy is more often a data issue than
     * a real "non-meal" product.
     */
    private function resolve_category(int $product_id): string {
        if ($product_id <= 0 || !function_exists('has_term')) {
            return 'Side';
        }
        if (has_term(self::MAIN_CATEGORY_ID, 'product_cat', $product_id)) {
            return 'Main';
        }
        foreach (self::SIDE_CATEGORY_IDS as $cat_id) {
            if (has_term($cat_id, 'product_cat', $product_id)) {
                return 'Side';
            }
        }
        return 'Side';
    }

    private function compute_totals(array $items): array {
        $total_mains = 0;
        $total_sides = 0;
        foreach ($items as $item) {
            $qty = (int) $item['qty'];
            if ($item['category'] === 'Main') {
                $total_mains += $qty;
            } elseif ($item['category'] === 'Side') {
                $total_sides += $qty;
            }
        }
        return [
            'total_items' => $total_mains + $total_sides,
            'total_mains' => $total_mains,
            'total_sides' => $total_sides,
        ];
    }

    /**
     * Build the driver-only block on a slip.
     */
    private function build_driver_block(array $order, array $client, string $delivery_date): array {
        $client_type_raw = (string) ($client['client_type'] ?? '');
        $client_type     = strtolower($client_type_raw);

        $first_name = (string) ($client['first_name'] ?? '');
        $last_name  = (string) ($client['last_name'] ?? '');
        $name       = trim($first_name . ' ' . $last_name);

        $delivery_fee  = (float) ($client['delivery_fee'] ?? 0);
        $contribution  = (float) ($client['client_contribution'] ?? 0);

        $order_id  = (int) ($order['order_id'] ?? 0);
        $wc_order  = $order_id > 0 && function_exists('wc_get_order') ? wc_get_order($order_id) : null;

        $subtotal       = 0.0;
        $tax            = 0.0;
        $total          = 0.0;
        $payment_method = (string) ($client['payment_method'] ?? '');
        if ($wc_order instanceof WC_Order) {
            $subtotal       = (float) $wc_order->get_subtotal();
            $tax            = (float) $wc_order->get_total_tax();
            $total          = (float) $wc_order->get_total();
            // Prefer the client's configured payment method; fall back
            // to whatever WC recorded if the client row is missing it.
            if ($payment_method === '') {
                $payment_method = (string) $wc_order->get_payment_method();
            }
        }

        $breakdown      = [];
        $collect_amount = 0.0;

        if (in_array($client_type, ['sdnb', 'veteran'], true)) {
            $is_first = $this->is_first_delivery_of_month(
                (int) ($client['client_id'] ?? 0),
                $delivery_date
            );
            $gov = MealsDB_Collection_Calculator::for_government(
                $delivery_fee,
                $contribution,
                $is_first
            );

            $breakdown[] = ['label' => 'Delivery Fee', 'amount' => round($delivery_fee, 2)];
            if ((float) $gov['contribution_due'] > 0) {
                $breakdown[] = ['label' => 'Client Contribution', 'amount' => round((float) $gov['contribution_due'], 2)];
            }
            $collect_amount = round((float) $gov['collect'], 2);
        } else {
            // Private (cash, prepaid, or non-cash with delivery fee).
            $priv     = MealsDB_Collection_Calculator::for_private($total, $delivery_fee, $payment_method);
            $products = max(0.0, $total - $tax);

            $breakdown[] = ['label' => 'Products',     'amount' => round($products, 2)];
            $breakdown[] = ['label' => 'Taxes',        'amount' => round($tax, 2)];
            $breakdown[] = ['label' => 'Delivery Fee', 'amount' => round($delivery_fee, 2)];

            $collect_amount = $priv['collect'] === null ? 0.0 : round((float) $priv['collect'], 2);
        }

        return [
            'client_name'    => $name,
            'street'         => (string) ($client['delivery_street_name'] ?? ''),
            'city'           => (string) ($client['delivery_city'] ?? ''),
            'phone'          => (string) ($client['client_phone_1'] ?? ''),
            'client_type'    => $client_type_raw !== '' ? $client_type_raw : 'Private',
            'breakdown'      => $breakdown,
            'collect_amount' => $collect_amount,
            'collect_label'  => 'Collect: $' . number_format($collect_amount, 2, '.', ''),
        ];
    }

    /**
     * Should the monthly client contribution be collected on this delivery?
     *
     * The contribution is collected once per billing month, on the first
     * delivery. Historically this was inferred from MIN(delivery_date) in
     * meals_delivery_allocations — but those detail rows only exist after the
     * allocation rebuilder has run (see LB-1). Before materialisation, the old
     * code defaulted to TRUE and over-collected the contribution on every
     * delivery (LB-4).
     *
     * The authoritative signal is the contribution_applied flag on
     * meals_client_allocations, set when the fee path bills the contribution.
     * We use that as the source of truth: if the contribution has already been
     * applied this month, do NOT collect again. When we genuinely cannot
     * determine state, we fail to the financially-safe direction (do not
     * over-collect).
     */
    private function is_first_delivery_of_month(int $client_id, string $delivery_date): bool {
        if ($client_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date)) {
            return false; // can't identify the client/month — do not over-collect
        }
        if (!isset($GLOBALS['wpdb'])) {
            return false; // no DB — do not over-collect
        }

        $wpdb          = $GLOBALS['wpdb'];
        $billing_month = substr($delivery_date, 0, 7);
        $summary_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);
        $alloc_table   = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);

        // 1) Authoritative: has the contribution already been applied/collected
        //    this month? If so, this is NOT a collect-the-contribution delivery.
        $already_applied = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT contribution_applied FROM `{$summary_table}`
             WHERE client_id = %d AND billing_month = %s",
            $client_id,
            $billing_month
        ));
        if ($already_applied === 1) {
            return false;
        }

        // 2) If allocation detail rows exist, use the genuine earliest-delivery
        //    signal (correct once the rebuilder has materialised the month).
        $earliest = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(delivery_date) FROM `{$alloc_table}`
             WHERE client_id = %d AND billing_month = %s",
            $client_id,
            $billing_month
        ));
        if ($earliest !== null && $earliest !== '') {
            return (string) $earliest === $delivery_date;
        }

        // 3) No allocation rows AND contribution not yet applied. We cannot
        //    prove this is the earliest delivery. Per LB-4, do NOT default to
        //    collecting (the old bug). Once LB-1 materialises allocations and/or
        //    the fee path sets contribution_applied, the correct delivery will
        //    collect it. Failing safe here means at worst a contribution is
        //    collected one delivery later — never over-collected every visit.
        return false;
    }

    // -----------------------------------------------------------------
    // Helpers — fee product IDs, freezer order, SKUs, customer note,
    // delivery date, long-date formatting.
    // -----------------------------------------------------------------

    /**
     * @return array{client_contribution:int, delivery_fee:int}
     */
    private function get_fee_product_ids(): array {
        if (class_exists('MealsDB_Invoice_Generator')
            && method_exists('MealsDB_Invoice_Generator', 'get_fee_product_ids')) {
            return MealsDB_Invoice_Generator::get_fee_product_ids();
        }
        $saved = get_option('mealsdb_fee_product_ids', []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $defaults = MealsDB_Operational_Constants::default_fee_product_ids();
        return [
            'client_contribution' => (int) ($saved['client_contribution'] ?? $defaults['client_contribution']),
            'delivery_fee'        => (int) ($saved['delivery_fee'] ?? $defaults['delivery_fee']),
        ];
    }

    /**
     * Overage products are filtered out of slips entirely.
     *
     * @return int[]
     */
    private function get_overage_product_ids(): array {
        $saved = get_option('mealsdb_fee_product_ids', []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $defaults = [
            'overage_main'             => MealsDB_Operational_Constants::PRODUCT_ID_OVERAGE_MAIN,
            'overage_sides_taxable'    => MealsDB_Operational_Constants::PRODUCT_ID_OVERAGE_SIDE_NONTAX,
            'overage_sides_nontaxable' => MealsDB_Operational_Constants::PRODUCT_ID_OVERAGE_SIDE_TAX,
        ];
        $ids = [];
        foreach ($defaults as $key => $default) {
            $ids[] = (int) ($saved[$key] ?? $default);
        }
        return array_values(array_filter($ids, static function ($id) { return $id > 0; }));
    }

    /**
     * Batch-fetch _freezer_order postmeta for a list of products.
     *
     * @return array<int,int>
     */
    private function get_freezer_orders(array $product_ids): array {
        if (empty($product_ids) || !isset($GLOBALS['wpdb'])) {
            return [];
        }
        $wpdb = $GLOBALS['wpdb'];
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_freezer_order' AND post_id IN ({$placeholders})",
            ...$product_ids
        ), ARRAY_A);

        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $map[(int) $r['post_id']] = (int) $r['meta_value'];
            }
        }
        return $map;
    }

    /**
     * Batch-fetch product SKUs.
     *
     * @return array<int,string>
     */
    private function get_product_skus(array $product_ids): array {
        if (empty($product_ids) || !isset($GLOBALS['wpdb'])) {
            return [];
        }
        $wpdb = $GLOBALS['wpdb'];
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_sku' AND post_id IN ({$placeholders})",
            ...$product_ids
        ), ARRAY_A);

        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $map[(int) $r['post_id']] = (string) $r['meta_value'];
            }
        }
        return $map;
    }

    private function fetch_customer_note(int $order_id): string {
        if ($order_id <= 0 || !function_exists('wc_get_order')) {
            return '';
        }
        $wc_order = wc_get_order($order_id);
        if (!($wc_order instanceof WC_Order)) {
            return '';
        }
        return trim((string) $wc_order->get_customer_note());
    }

    /**
     * Use the WC order's display number when available so plugins
     * that renumber orders (sequential numbering, prefix/suffix
     * schemes) round-trip through the slip header. Falls back to the
     * raw post ID when WC isn't loaded or the order isn't found.
     */
    private function resolve_order_display_number(int $order_id): string {
        if ($order_id <= 0) {
            return '';
        }
        if (function_exists('wc_get_order')) {
            $wc_order = wc_get_order($order_id);
            if ($wc_order instanceof WC_Order
                && method_exists($wc_order, 'get_order_number')) {
                $num = (string) $wc_order->get_order_number();
                if ($num !== '') {
                    return $num;
                }
            }
        }
        return (string) $order_id;
    }

    /**
     * Best-effort delivery date for the slip header. Resolution order:
     *
     *   1. The WC order's explicit _delivery_date meta (an order-time capture
     *      of the real delivery date — the most authoritative source).
     *   2. The computed delivery occurrence carried onto the order by
     *      MealsDB_Delivery_Slip_Generator::get_orders_for_delivery_range()
     *      (the basis the order was selected on — for an order-ahead order
     *      this is the in-range delivery date, NOT the creation date).
     *   3. The order's creation date — last-resort fallback only.
     *
     * Skipping step 2 would mean an order-ahead order (created before its
     * delivery day) prints its CREATION date even though it was correctly
     * filtered onto this slip by delivery occurrence — so a range slip's
     * content check could still show out-of-range dates (GUI-SLIP-RANGE).
     */
    private function resolve_delivery_date(array $order, string $fallback_date): string {
        $order_id = (int) ($order['order_id'] ?? 0);
        if ($order_id > 0 && function_exists('wc_get_order')) {
            $wc_order = wc_get_order($order_id);
            if ($wc_order instanceof WC_Order) {
                $meta = (string) $wc_order->get_meta('_delivery_date', true);
                if ($meta !== '' && preg_match('/\d{4}-\d{2}-\d{2}/', $meta, $m)) {
                    return $m[0];
                }
            }
        }
        $occurrence = (string) ($order['delivery_occurrence'] ?? '');
        if ($occurrence !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $occurrence)) {
            return $occurrence;
        }
        if ($fallback_date !== '' && preg_match('/\d{4}-\d{2}-\d{2}/', $fallback_date, $m)) {
            return $m[0];
        }
        return '';
    }

    private function format_long_date(string $ymd): string {
        if ($ymd === '') {
            return '';
        }
        $ts = strtotime($ymd);
        if ($ts === false) {
            return $ymd;
        }
        return date('l, F j, Y', $ts);
    }

    // -----------------------------------------------------------------
    // Rendering — HTML templates + DomPDF.
    // -----------------------------------------------------------------

    private function render_pdf(array $slips, bool $include_driver): string {
        $html = $this->render_html($slips, $include_driver);
        return $this->render_with_dompdf($html);
    }

    private function render_html(array $slips, bool $include_driver): string {
        $css = $this->slip_css();

        $body = '';
        $count = count($slips);
        foreach ($slips as $i => $slip) {
            $is_last = ($i === $count - 1);
            $body   .= $this->render_slip_html($slip, $include_driver, $is_last);
        }

        if ($body === '') {
            // DomPDF refuses to render an empty document. Emit a "no
            // orders" placeholder so the operator gets a clear PDF
            // instead of a confusing 500.
            $body = '<div class="slip slip-empty"><p>No orders found for this selection.</p></div>';
        }

        return "<!DOCTYPE html>\n<html><head><meta charset=\"UTF-8\"><style>{$css}</style></head><body>{$body}</body></html>";
    }

    private function render_slip_html(array $slip, bool $include_driver, bool $is_last): string {
        $initials      = $this->h($slip['initials']);
        $zone          = $this->h($slip['zone']);
        $order_number  = $this->h($slip['order_number']);
        $delivery_date = $this->h($slip['delivery_date']);

        $items_html = '';
        foreach ($slip['items'] as $item) {
            $items_html .= '<tr>'
                . '<td class="sku-col">' . $this->h($item['sku']) . '</td>'
                . '<td class="qty-col">' . (int) $item['qty'] . '</td>'
                . '<td class="name-col">' . $this->h($item['product_name']) . '</td>'
                . '<td class="cat-col">' . $this->h($item['category']) . '</td>'
                . '</tr>';
        }

        $totals_line = sprintf(
            'Total Items: %d | Mains: %d | Sides: %d',
            (int) $slip['total_items'],
            (int) $slip['total_mains'],
            (int) $slip['total_sides']
        );

        $notes_html = '';
        $notes = (string) ($slip['additional_notes'] ?? '');
        if ($notes !== '') {
            $notes_html = '<div class="notes-block">'
                . '<div class="notes-label">Additional Notes:</div>'
                . '<div class="notes-text">' . nl2br($this->h($notes)) . '</div>'
                . '</div>';
        }

        $driver_html = '';
        if ($include_driver && !empty($slip['driver'])) {
            $driver_html = $this->render_driver_block_html($slip['driver']);
        }

        $slip_class = 'slip' . ($is_last ? ' slip-last' : '');

        // Continuation marker: DomPDF auto-repeats the items-table
        // <thead> on each page the table spans, so a small italic row
        // inside the thead is a reliable cue on overflow pages. On
        // page 1 the giant 24pt initials and order-number block above
        // dominate visually; the operator learns "no big header at
        // the top of this page = continuation of the previous order."
        // DomPDF doesn't expose a stable way to hide an element only
        // on the first repeat of a thead, so the marker prints on
        // every page the table appears on rather than only after the
        // first break.
        return <<<HTML
<div class="{$slip_class}">
    <div class="slip-left">
        <div class="slip-header">
            <h1 class="name-line">{$initials}</h1>
            <p class="zone-order">{$zone} - Order {$order_number}</p>
            <p class="delivery-date">Delivery Date: {$delivery_date}</p>
        </div>
        <table class="items-table">
            <thead>
                <tr>
                    <th class="sku-col">SKU</th>
                    <th class="qty-col">QTY</th>
                    <th class="name-col">Product</th>
                    <th class="cat-col">Category</th>
                </tr>
                <tr class="continued-row">
                    <th colspan="4" class="continued-cell">Order {$order_number} &mdash; continued from previous page</th>
                </tr>
            </thead>
            <tbody>
                {$items_html}
            </tbody>
        </table>
        <div class="totals-row">{$totals_line}</div>
        {$notes_html}
    </div>
    <div class="slip-right">
        {$driver_html}
    </div>
</div>
HTML;
    }

    private function render_driver_block_html(array $driver): string {
        $name   = $this->h($driver['client_name']);
        $street = $this->h($driver['street']);
        $city   = $this->h($driver['city']);
        $phone  = $this->h($driver['phone']);

        $rows = '';
        foreach ($driver['breakdown'] as $row) {
            $label = $this->h($row['label']);
            $amount = '$' . number_format((float) $row['amount'], 2, '.', '');
            $rows .= '<div class="breakdown-row">'
                . '<span class="breakdown-label">' . $label . ':</span>'
                . '<span class="breakdown-amount">' . $amount . '</span>'
                . '</div>';
        }

        $collect = $this->h($driver['collect_label']);

        return <<<HTML
<div class="driver-block">
    <div class="breakdown">{$rows}</div>
    <div class="collect">{$collect}</div>
    <div class="customer-info">
        <div class="customer-name">{$name}</div>
        <div>{$street}</div>
        <div>{$city}</div>
        <div>PH: {$phone}</div>
    </div>
</div>
HTML;
    }

    private function slip_css(): string {
        // Layout note: floats rather than flex/grid because DomPDF's
        // grid/flex support has historically been brittle. Floats give
        // us deterministic column rendering. The driver block sits in
        // the bottom of the right column via absolute positioning
        // anchored inside the .slip-right relative box.
        return <<<CSS
@page { size: letter portrait; margin: 0.5in; }
body { font-family: Helvetica, Arial, sans-serif; color: #111; margin: 0; padding: 0; }
.slip { page-break-after: always; width: 100%; height: 10in; position: relative; }
.slip-last { page-break-after: auto; }
.slip-left { width: 65%; float: left; padding-right: 0.25in; box-sizing: border-box; }
.slip-right { width: 35%; float: right; padding-left: 0.25in; box-sizing: border-box; border-left: 1px solid #888; height: 10in; position: relative; }
.slip-header h1.name-line { font-size: 24pt; font-weight: bold; margin: 0 0 0.05in 0; }
.slip-header .zone-order { font-size: 14pt; margin: 0 0 0.05in 0; }
.slip-header .delivery-date { font-size: 11pt; margin: 0 0 0.15in 0; color: #444; }
.items-table { width: 100%; border-collapse: collapse; font-size: 10pt; margin-top: 0.15in; }
.items-table th, .items-table td { border: 1px solid #000; padding: 3pt 5pt; text-align: left; }
.items-table th { background-color: #eee; font-weight: bold; }
.items-table .sku-col { width: 15%; }
.items-table .qty-col { width: 10%; text-align: center; }
.items-table .name-col { width: 60%; }
.items-table .cat-col { width: 15%; }
.items-table .continued-row .continued-cell {
    background: transparent;
    border: 0;
    border-bottom: 1px solid #000;
    font-style: italic;
    font-weight: normal;
    font-size: 8pt;
    text-align: right;
    color: #555;
    padding: 1pt 5pt;
}
.totals-row { font-size: 11pt; font-weight: bold; margin-top: 0.15in; }
.notes-block { margin-top: 0.15in; font-size: 10pt; }
.notes-block .notes-label { font-weight: bold; margin-bottom: 2pt; }
.driver-block { position: absolute; bottom: 0; left: 0.25in; right: 0; font-size: 10pt; }
.driver-block .breakdown { margin-bottom: 0.1in; }
.driver-block .breakdown-row { display: block; margin-bottom: 2pt; }
.driver-block .breakdown-label { display: inline-block; width: 60%; }
.driver-block .breakdown-amount { display: inline-block; width: 35%; text-align: right; }
.driver-block .collect { border-top: 2px solid #000; padding-top: 4pt; font-size: 14pt; font-weight: bold; margin-bottom: 0.15in; }
.driver-block .customer-info { font-size: 10pt; line-height: 1.4; }
.driver-block .customer-name { font-weight: bold; font-size: 12pt; }
.slip-empty { text-align: center; font-size: 14pt; padding-top: 2in; }
CSS;
    }

    private function render_with_dompdf(string $html): string {
        if (!class_exists('Dompdf\\Dompdf')) {
            throw new RuntimeException(
                'DomPDF is not available — run `composer install` in the meals-db plugin directory.'
            );
        }

        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', defined('MEALS_DB_PLUGIN_DIR') ? MEALS_DB_PLUGIN_DIR : __DIR__);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();
        return is_string($output) ? $output : '';
    }

    private function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
