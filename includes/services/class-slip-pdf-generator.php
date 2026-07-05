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

    // ----------------------------------------------------------------- //
    //  Midland packing-document calibration (SPEC-midland-packing-documents).
    //  Letter landscape, inches from page top-left. These are the SINGLE
    //  SOURCE OF TRUTH for the divider/driver-block geometry — the merge
    //  engine (unit 03) anchors its overlay to the SAME constants, so doc 2's
    //  drawn divider and doc 4's text never drift apart. Measured from the
    //  real doc 3 reference scan; tune ONLY these if the plugin's own divider
    //  y differs at the final live calibration pass.
    // ----------------------------------------------------------------- //

    /** Right-region divider drawn on doc 2 (doc 4 text anchors just below it). */
    public const DOC2_DIVIDER_LEFT_IN  = 7.59;
    public const DOC2_DIVIDER_TOP_IN   = 4.50;
    public const DOC2_DIVIDER_WIDTH_IN = 3.25;

    /** Doc 4 driver block placement (clears the item table + handwriting band). */
    public const DOC4_BLOCK_LEFT_IN  = 7.4;
    public const DOC4_BLOCK_TOP_IN   = 4.62;
    public const DOC4_BLOCK_WIDTH_IN = 3.2;

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
            $slip['driver'] = $this->build_driver_block($order, $client, $delivery_dt, $fee_ids);
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
    private function build_driver_block(array $order, array $client, string $delivery_date, array $fee_ids): array {
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
            // U06-slips-3: collect the monthly contribution on the delivery of
            // the ORDER that carries it — the order the fee path billed the
            // contribution fee product (SKU CONT) onto. apply_to_order adds that
            // product to EXACTLY ONE order per client per billing month (the
            // atomic claim in MealsDB_Order_Fees), so this signal collects the
            // contribution once, on the right delivery, and can never over- or
            // under-collect. The old signal (the contribution_applied summary
            // flag) was set at ORDER time — before ANY delivery — so it was
            // already 1 by the time the first slip generated, and the door
            // contribution was silently never collected. Keying off the
            // delivered order's own line items makes billed == collected.
            $collect_contribution = self::order_carries_contribution(
                $order,
                (int) ($fee_ids['client_contribution'] ?? 0)
            );
            $gov = MealsDB_Collection_Calculator::for_government(
                $delivery_fee,
                $contribution,
                $collect_contribution
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

        // Secondary phone + alternate contact (Midland doc 4 / resolved open
        // item, 2026-06-26). These fields exist on meals_clients but are absent
        // for many clients — carry them through ONLY when populated; the doc 4
        // renderer skips empties so a blank field never prints a stray label.
        // The existing daily driver slip ignores these extra keys, so adding
        // them here is non-breaking.
        $contact_phone = (string) ($client['alternate_contact_phone_1'] ?? '');
        if ($contact_phone === '') {
            $contact_phone = (string) ($client['alternate_contact_phone_2'] ?? '');
        }

        return [
            'client_name'     => $name,
            'street'          => (string) ($client['delivery_street_name'] ?? ''),
            'city'            => (string) ($client['delivery_city'] ?? ''),
            // Doc 4 address line = street; city + postal. delivery_postal_code
            // is the delivery-address group's postal (matches street/city above).
            'postal'          => (string) ($client['delivery_postal_code'] ?? ''),
            'phone'           => (string) ($client['client_phone_1'] ?? ''),
            'phone_secondary' => (string) ($client['client_phone_2'] ?? ''),
            'contact_name'    => (string) ($client['alternate_contact_name'] ?? ''),
            'contact_phone'   => $contact_phone,
            'client_type'     => $client_type_raw !== '' ? $client_type_raw : 'Private',
            'breakdown'       => $breakdown,
            'collect_amount'  => $collect_amount,
            'collect_label'   => 'Collect: $' . number_format($collect_amount, 2, '.', ''),
        ];
    }

    /**
     * Whether the delivered order carries the monthly client-contribution fee
     * line (the client_contribution fee product, rendered as SKU CONT).
     *
     * This is the authoritative "collect the contribution on this delivery"
     * signal (see build_driver_block). MealsDB_Order_Fees::apply_to_order bills
     * that product onto EXACTLY ONE order per client per billing month via an
     * atomic claim, so the driver collects the contribution once — on that
     * order's delivery — with no possibility of over- or under-collection.
     *
     * This replaced the previous contribution_applied summary-flag lookup (and
     * the MIN(delivery_date) fallback), which read state set at ORDER time,
     * before any delivery, and so silently suppressed door collection for every
     * government client whose fee path had run.
     */
    private static function order_carries_contribution(array $order, int $contribution_product_id): bool {
        if ($contribution_product_id <= 0) {
            return false;
        }
        foreach (($order['items'] ?? []) as $item) {
            if ((int) ($item['wc_product_id'] ?? 0) === $contribution_product_id) {
                return true;
            }
        }
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
@page { size: letter landscape; margin: 0.5in; }
body { font-family: Helvetica, Arial, sans-serif; color: #111; margin: 0; padding: 0; }
.slip { page-break-after: always; width: 100%; height: 7.5in; position: relative; }
.slip-last { page-break-after: auto; }
.slip-left { width: 65%; float: left; padding-right: 0.25in; box-sizing: border-box; }
.slip-right { width: 35%; float: right; padding-left: 0.25in; box-sizing: border-box; border-left: 1px solid #888; height: 7.5in; position: relative; }
.slip-header h1.name-line { font-size: 24pt; font-weight: bold; margin: 0 0 0.05in 0; }
.slip-header .zone-order { font-size: 14pt; margin: 0 0 0.05in 0; }
.slip-header .delivery-date { font-size: 11pt; margin: 0 0 0.15in 0; color: #444; }
.items-table { width: 6.0in; table-layout: fixed; border-collapse: collapse; font-size: 11pt; margin-top: 0.15in; }
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
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->render();

        $output = $dompdf->output();
        return is_string($output) ? $output : '';
    }

    private function h(string $s): string {
        return self::esc($s);
    }

    /** Static escaper so the shared (static) doc 4 fragment can reuse it. */
    private static function esc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ================================================================= //
    //  Midland packing documents (doc 1 cover, doc 2 packer, doc 4 driver).
    //
    //  These are PURPOSE-BUILT, calibrated renderers for the two-phase
    //  Midland workflow — deliberately SEPARATE from the live daily-slip
    //  render path above (which the existing delivery-slips UI depends on
    //  and is left byte-identical). They REUSE the data pipeline above
    //  (build_slips / build_driver_block / fetch_customer_note /
    //  format_long_date) so content stays consistent. Visual calibration
    //  (exact coordinates vs the reference scans) is verified on the live
    //  host — see SPEC-midland-packing-documents-COMBINED.md.
    // ================================================================= //

    /**
     * Assemble the persistable batch data for a zone export: the ordered,
     * positional doc 4 driver-block payloads (element N ↔ doc 3 page N+1 at
     * merge time) plus the per-order metadata doc 1 needs (initials +
     * take-from-hold flag), captured as a SNAPSHOT so doc 1 / doc 4 render
     * identically from the persisted batch regardless of later data changes.
     *
     * @return array{order_count:int, doc4_orders:array<int,array>}
     */
    public function build_batch_data(array $zone_names, string $start_date, string $end_date): array {
        $clients = $this->client_query->get_clients_for_zones_driver($zone_names);
        $orders  = $this->fetch_orders_for_clients($clients, $start_date, $end_date);
        $slips   = $this->build_slips($orders, $clients, true);

        $doc4_orders = [];
        foreach ($slips as $slip) {
            $driver = is_array($slip['driver'] ?? null) ? $slip['driver'] : [];
            $note   = (string) ($slip['additional_notes'] ?? '');

            $doc4_orders[] = [
                'order_number'    => (string) ($slip['order_number'] ?? ''),
                'initials'        => (string) ($slip['initials'] ?? ''),
                // Snapshot the "take from hold" decision at generation time so
                // doc 1's initials line is reproducible from the persisted batch
                // (case-insensitive CONTAINS — "TAKE FROM HOLD - back door" etc.
                // all qualify).
                'take_from_hold'  => ($note !== '' && stripos($note, 'take from hold') !== false),
                'client_name'     => (string) ($driver['client_name'] ?? ''),
                'street'          => (string) ($driver['street'] ?? ''),
                'city'            => (string) ($driver['city'] ?? ''),
                'postal'          => (string) ($driver['postal'] ?? ''),
                'phone'           => (string) ($driver['phone'] ?? ''),
                'phone_secondary' => (string) ($driver['phone_secondary'] ?? ''),
                'contact_name'    => (string) ($driver['contact_name'] ?? ''),
                'contact_phone'   => (string) ($driver['contact_phone'] ?? ''),
                'collect_amount'  => (float)  ($driver['collect_amount'] ?? 0),
                'collect_label'   => (string) ($driver['collect_label'] ?? ''),
            ];
        }

        return [
            'order_count' => count($doc4_orders),
            'doc4_orders' => $doc4_orders,
        ];
    }

    // ----------------------------------------------------------------- //
    //  DOC 1 — cover sheet BODY builder (per zone)
    // ----------------------------------------------------------------- //

    /**
     * The DOC 1 cover BODY fragment (no <html> wrapper), so the combined
     * Packing-Slips document can place it as page 1 ahead of the doc 2 slips.
     * Standalone doc 1 passes $page_break=false (a lone page needs no break);
     * the combined doc passes true so a page break separates the cover from the
     * first packer slip. Page numbering ("Page 1 of {1+order_count}") is
     * unchanged — the caller sets order_count to the count it wants reflected.
     */
    private function doc1_body_html(string $zone_name, string $delivery_date, array $batch, bool $page_break = false): string {
        $orders      = is_array($batch['orders'] ?? null) ? $batch['orders'] : [];
        $order_count = (int) ($batch['order_count'] ?? count($orders));
        $created_at  = (string) ($batch['created_at'] ?? '');

        $zone_number  = $this->resolve_zone_number($zone_name);
        $zone_title   = $zone_number !== null ? 'Zone ' . $zone_number : self::esc($zone_name);
        $delivery_lbl = self::esc($this->format_long_date($delivery_date));

        // Initials line: delivery_initials of orders flagged take_from_hold at
        // generation, joined " | ". NONE when none qualify (operator decision
        // 2026-06-26 — explicit "none", not a blank line). Always from the
        // persisted snapshot, even in the combined doc.
        $initials = [];
        foreach ($orders as $o) {
            if (!empty($o['take_from_hold'])) {
                $ini = trim((string) ($o['initials'] ?? ''));
                if ($ini !== '') {
                    $initials[] = $ini;
                }
            }
        }
        $initials_line = empty($initials) ? 'NONE' : implode(' | ', array_map([self::class, 'esc'], $initials));

        // "Orders Exported <Month D, YYYY @ h:mm am/pm>" = generation time, shown
        // in the site timezone. created_at is stored UTC; convert for display.
        $exported_line = self::esc($this->format_export_timestamp($created_at));

        $legend_rows  = $this->build_legend_rows();
        $legend_html  = '';
        foreach ($legend_rows as $r) {
            $legend_html .= '<tr>'
                . '<td>' . self::esc($r['zone']) . '</td>'
                . '<td>' . self::esc($r['weekday']) . '</td>'
                . '<td>' . self::esc($r['area']) . '</td>'
                . '</tr>';
        }
        if ($legend_html === '') {
            // No schedule configured — render an empty body rather than fake rows.
            $legend_html = '<tr><td colspan="3" class="legend-empty">(delivery schedule not configured)</td></tr>';
        }

        $page_y = 1 + $order_count; // cover is page 1; one page per order after.
        $brk    = $page_break ? ' d2-break' : '';

        return <<<HTML
<div class="doc1-page{$brk}">
    <div class="d1-zone">{$zone_title}</div>
    <div class="d1-date">Delivery Date: {$delivery_lbl}</div>
    <div class="d1-gap"></div>
    <div class="d1-hold-label">ORDERS - TAKE FROM HOLD</div>
    <div class="d1-initials">{$initials_line}</div>
    <div class="d1-gap"></div>
    <table class="d1-legend">
        <thead>
            <tr><th colspan="3" class="legend-title">LEGEND: DELIVERY SCHEDULE FOR PACKING</th></tr>
            <tr><th>ZONE #</th><th>WEEKDAY</th><th>AREA</th></tr>
        </thead>
        <tbody>{$legend_html}</tbody>
    </table>
    <div class="d1-exported">Orders Exported {$exported_line}</div>
    <div class="d1-gap"></div>
    <div class="d1-count">{$order_count} Orders</div>
    <div class="d1-footer">Page 1 of {$page_y}</div>
</div>
HTML;
    }

    // ----------------------------------------------------------------- //
    //  DOC 2 — packer slip page renderer (item list left, divider right)
    // ----------------------------------------------------------------- //

    private function render_doc2_page(array $slip, int $n, int $m, int $page_x, int $page_y, bool $is_last): string {
        $initials      = self::esc((string) ($slip['initials'] ?? ''));
        $zone          = self::esc((string) ($slip['zone'] ?? ''));
        $order_number  = self::esc((string) ($slip['order_number'] ?? ''));
        $delivery_date = self::esc((string) ($slip['delivery_date'] ?? ''));

        $items_html = '';
        foreach (($slip['items'] ?? []) as $item) {
            $items_html .= '<tr>'
                . '<td class="d2-sku">' . self::esc((string) $item['sku']) . '</td>'
                . '<td class="d2-qty">' . (int) $item['qty'] . '</td>'
                . '<td class="d2-name">' . self::esc((string) $item['product_name']) . '</td>'
                . '<td class="d2-cat">' . self::esc((string) $item['category']) . '</td>'
                . '</tr>';
        }

        // Totals wording corrected per directive: Total Mains / Total Sides.
        $totals = sprintf(
            'Total Items: %d | Total Mains: %d | Total Sides: %d',
            (int) ($slip['total_items'] ?? 0),
            (int) ($slip['total_mains'] ?? 0),
            (int) ($slip['total_sides'] ?? 0)
        );

        $notes      = (string) ($slip['additional_notes'] ?? '');
        $notes_html = '';
        if ($notes !== '') {
            $notes_html = '<div class="d2-notes"><span class="d2-notes-label">Additional Notes:</span> '
                . nl2br(self::esc($notes)) . '</div>';
        }

        $page_class = 'doc2-page' . ($is_last ? '' : ' d2-break');

        return <<<HTML
<div class="{$page_class}">
    <div class="d2-name-line">Name: {$initials}</div>
    <div class="d2-zone-order">{$zone} - Order {$order_number}</div>
    <div class="d2-delivery">Delivery Date: {$delivery_date}</div>
    <div class="d2-position">Order {$n} of {$m}</div>
    <table class="d2-items">
        <thead>
            <tr><th class="d2-sku">SKU</th><th class="d2-qty">Qty</th><th class="d2-name">Product</th><th class="d2-cat">Category</th></tr>
        </thead>
        <tbody>{$items_html}</tbody>
    </table>
    <div class="d2-totals">{$totals}<span class="d2-page">Page {$page_x} of {$page_y}</span></div>
    {$notes_html}
    <div class="d2-divider"></div>
</div>
HTML;
    }

    // ----------------------------------------------------------------- //
    //  COMBINED — cover (page 1) + packer slips, one document.
    // ----------------------------------------------------------------- //

    /**
     * Render ONE PDF = doc 1 cover (page 1) followed by the doc 2 packer slips.
     * Both are produced by dompdf from the same batch, so we emit them into a
     * SINGLE document — no PDF-concatenation dependency. The cover's "N Orders"
     * and the global "Page X of Y" are derived from the LIVE packer slip count
     * (not the batch snapshot) so the combined document numbers consistently
     * even if orders changed since generation. The take-from-hold initials line
     * still comes from the persisted snapshot ($batch['orders']).
     *
     * @param array $batch decoded batch: ['orders'=>array, 'created_at'=>UTC, ...]
     */
    public function generate_packing_slips_combined(string $zone_name, string $delivery_date, array $batch): string {
        $clients = $this->client_query->get_clients_for_zones([$zone_name]);
        $orders  = $this->fetch_orders_for_clients($clients, $delivery_date, $delivery_date);
        $slips   = $this->build_slips($orders, $clients, false);
        $html    = $this->render_packing_slips_combined_html($zone_name, $delivery_date, $batch, $slips);
        return $this->render_with_dompdf($html);
    }

    /**
     * The combined document HTML (dompdf-free, unit-testable). Cover body first
     * with a page break after it (only when slips follow, so a zero-order batch
     * doesn't emit a trailing blank page), then each packer slip page.
     *
     * @param array<int,array> $slips live packer slips from build_slips()
     */
    private function render_packing_slips_combined_html(string $zone_name, string $delivery_date, array $batch, array $slips): string {
        $css   = $this->midland_doc_css();
        $count = count($slips);
        $y     = 1 + $count; // cover (1) + one page per order.

        // Cover reflects the LIVE count; break after it only if slips follow.
        $cover_batch = $batch;
        $cover_batch['order_count'] = $count;
        $body = $this->doc1_body_html($zone_name, $delivery_date, $cover_batch, $count > 0);

        foreach ($slips as $i => $slip) {
            $n       = $i + 1;       // order N within the zone batch
            $page_x  = $n + 1;       // global page # (cover is page 1)
            $is_last = ($i === $count - 1);
            $body   .= $this->render_doc2_page($slip, $n, $count, $page_x, $y, $is_last);
        }

        return "<!DOCTYPE html>\n<html><head><meta charset=\"UTF-8\"><style>{$css}</style></head><body>{$body}</body></html>";
    }

    // ----------------------------------------------------------------- //
    //  DOC 4 — driver block(s), standalone (manual-fallback download)
    // ----------------------------------------------------------------- //

    /**
     * Render the saved doc 4 driver blocks as standalone landscape pages — one
     * per order, the block alone at the calibrated right-region position, NO
     * item table and NO divider (this is the print-on-top manual fallback; the
     * physical slip it overlays already carries the divider).
     *
     * @param array<int,array> $doc4_orders persisted, positional driver blocks
     */
    public function generate_doc4_driver_blocks(array $doc4_orders): string {
        $css   = $this->midland_doc_css();
        $count = count($doc4_orders);

        $body = '';
        foreach (array_values($doc4_orders) as $i => $order) {
            $is_last    = ($i === $count - 1);
            $page_class = 'doc4-page' . ($is_last ? '' : ' d4-break');
            $block      = self::driver_block_inner_html(is_array($order) ? $order : []);
            $body      .= "<div class=\"{$page_class}\"><div class=\"d4-block\">{$block}</div></div>";
        }
        if ($body === '') {
            $body = '<div class="doc4-page"><div class="d2-empty">No driver blocks in this batch.</div></div>';
        }

        $html = "<!DOCTYPE html>\n<html><head><meta charset=\"UTF-8\"><style>{$css}</style></head><body>{$body}</body></html>";
        return $this->render_with_dompdf($html);
    }

    /**
     * The doc 4 driver-block CONTENT fragment (no positioning, no divider).
     * Single source of truth for the standalone Doc 4 driver blocks, which wrap
     * it on a blank page at the calibrated DOC4_BLOCK_* coordinates for the
     * team's manual overlay onto the printed packing slip. Skips every empty
     * field so an absent secondary phone / contact never prints a stray label
     * or dangling "()".
     *
     * @param array $order a persisted doc 4 order payload
     */
    public static function driver_block_inner_html(array $order): string {
        $collect = self::esc((string) ($order['collect_label'] ?? ''));
        $name    = self::esc((string) ($order['client_name'] ?? ''));
        $street  = trim((string) ($order['street'] ?? ''));
        $city    = trim((string) ($order['city'] ?? ''));
        $postal  = trim((string) ($order['postal'] ?? ''));

        // City + postal on one line; emit only the parts present.
        $city_postal = trim($city . ' ' . $postal);

        $lines = '';
        if ($collect !== '') {
            $lines .= '<div class="d4-collect">' . $collect . '</div>';
        }
        if ($name !== '') {
            $lines .= '<div class="d4-name">' . $name . '</div>';
        }
        if ($street !== '') {
            $lines .= '<div class="d4-addr">' . self::esc($street) . '</div>';
        }
        if ($city_postal !== '') {
            $lines .= '<div class="d4-addr">' . self::esc($city_postal) . '</div>';
        }

        // Phone(s): primary; secondary if present; alternate contact
        // "(Name) phone" if present — each only when populated.
        $phone     = trim((string) ($order['phone'] ?? ''));
        $phone2    = trim((string) ($order['phone_secondary'] ?? ''));
        $c_name    = trim((string) ($order['contact_name'] ?? ''));
        $c_phone   = trim((string) ($order['contact_phone'] ?? ''));
        if ($phone !== '') {
            $lines .= '<div class="d4-phone">' . self::esc($phone) . '</div>';
        }
        if ($phone2 !== '') {
            $lines .= '<div class="d4-phone">' . self::esc($phone2) . '</div>';
        }
        if ($c_name !== '' || $c_phone !== '') {
            $contact = $c_name !== '' ? '(' . $c_name . ')' : '';
            $contact = trim($contact . ' ' . $c_phone);
            $lines  .= '<div class="d4-phone">' . self::esc($contact) . '</div>';
        }

        return $lines;
    }

    // ----------------------------------------------------------------- //
    //  Shared helpers for the Midland docs
    // ----------------------------------------------------------------- //

    /**
     * Resolve a zone's display NUMBER from the configured delivery schedule
     * (mealsdb_zone_delivery_schedule). The option is keyed by zone NAME, so
     * the number is the 1-based position of that key — matching the legend's
     * ZONE # column and the existing zone ordering. Returns null when the zone
     * is not configured (caller falls back to the raw name).
     */
    private function resolve_zone_number(string $zone_name): ?int {
        $schedule = get_option('mealsdb_zone_delivery_schedule', []);
        if (!is_array($schedule) || empty($schedule)) {
            return null;
        }
        $i = 0;
        foreach ($schedule as $key => $cfg) {
            $i++;
            if ((string) $key === $zone_name) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Build the doc 1 legend rows from the configured delivery schedule rather
     * than hardcoding them (resolved open item, 2026-06-26). ZONE # = 1-based
     * position; WEEKDAY = the schedule label (carries the "morning/afternoon"
     * granularity) or the bare day; AREA = the zone name (the schedule key).
     *
     * @return array<int,array{zone:string,weekday:string,area:string}>
     */
    private function build_legend_rows(): array {
        $schedule = get_option('mealsdb_zone_delivery_schedule', []);
        if (!is_array($schedule) || empty($schedule)) {
            return [];
        }
        $rows = [];
        $i    = 0;
        foreach ($schedule as $zone_name => $cfg) {
            $i++;
            $cfg     = is_array($cfg) ? $cfg : [];
            $label   = trim((string) ($cfg['label'] ?? ''));
            $day     = trim((string) ($cfg['day'] ?? ''));
            $weekday = $label !== '' ? $label : $day;
            $rows[]  = [
                'zone'    => (string) $i,
                'weekday' => $weekday,
                'area'    => (string) $zone_name,
            ];
        }
        return $rows;
    }

    /**
     * Format the batch's UTC created_at as "Month D, YYYY @ h:mm am/pm" in the
     * site timezone for doc 1's "Orders Exported" line.
     */
    private function format_export_timestamp(string $utc): string {
        if ($utc === '') {
            return '';
        }
        $ts = strtotime($utc . ' UTC');
        if ($ts === false) {
            return $utc;
        }
        if (function_exists('wp_timezone')) {
            try {
                $dt = new \DateTime('@' . $ts);
                $dt->setTimezone(wp_timezone());
                return $dt->format('F j, Y @ g:i a');
            } catch (\Throwable $e) {
                // fall through to server-local formatting
            }
        }
        return date('F j, Y @ g:i a', $ts);
    }

    /**
     * Shared CSS for the calibrated Midland documents (doc 1 / 2 / 4). Letter
     * landscape, ZERO page margin so the inch coordinates below are measured
     * from the true page top-left (matching the reference-scan measurements).
     * The divider + driver block use the DOC2_DIVIDER_* / DOC4_BLOCK_*
     * constants so doc 2 and doc 4 share one geometry.
     */
    private function midland_doc_css(): string {
        $div_l = self::DOC2_DIVIDER_LEFT_IN;
        $div_t = self::DOC2_DIVIDER_TOP_IN;
        $div_w = self::DOC2_DIVIDER_WIDTH_IN;
        $b_l   = self::DOC4_BLOCK_LEFT_IN;
        $b_t   = self::DOC4_BLOCK_TOP_IN;
        $b_w   = self::DOC4_BLOCK_WIDTH_IN;

        return <<<CSS
@page { size: letter landscape; margin: 0; }
body { font-family: Helvetica, Arial, sans-serif; color: #000; margin: 0; padding: 0; }

/* ---- shared page box (full Letter landscape, inch-addressable) ---- */
.doc1-page, .doc2-page, .doc4-page { position: relative; width: 11in; height: 8.5in; overflow: hidden; }
.d2-break, .d4-break { page-break-after: always; }
.d2-empty { position: absolute; top: 3in; width: 11in; text-align: center; font-size: 16pt; }

/* ---- DOC 1 cover sheet (centered vertical stack) ---- */
.doc1-page { text-align: center; }
.d1-zone { font-size: 48pt; font-weight: bold; padding-top: 0.6in; }
.d1-date { font-size: 22pt; margin-top: 0.1in; }
.d1-gap { height: 0.3in; }
.d1-hold-label { font-size: 20pt; font-weight: bold; }
.d1-initials { font-size: 20pt; font-weight: bold; margin-top: 0.08in; }
.d1-legend { margin: 0.1in auto; width: 6in; border-collapse: collapse; font-size: 12pt; }
.d1-legend th, .d1-legend td { border: 1px solid #000; padding: 4pt 8pt; text-align: center; }
.d1-legend .legend-title { font-weight: bold; }
.d1-legend .legend-empty { font-style: italic; }
.d1-exported { font-size: 20pt; font-weight: bold; margin-top: 0.1in; }
.d1-count { font-size: 26pt; font-weight: bold; }
.d1-footer { font-size: 9pt; margin-top: 0.2in; }

/* ---- DOC 2 packer slip (left region; right blank but for divider) ---- */
.d2-name-line   { position: absolute; left: 0.31in; top: 0.38in; font-size: 15pt; font-weight: bold; }
.d2-zone-order  { position: absolute; left: 4.6in;  top: 0.36in; font-size: 16pt; font-weight: bold; }
.d2-delivery    { position: absolute; left: 0.31in; top: 0.76in; font-size: 10pt; font-weight: bold; }
.d2-position    { position: absolute; left: 0.31in; top: 1.08in; font-size: 10pt; font-weight: bold; }
.d2-items       { position: absolute; left: 0.24in; top: 1.26in; width: 6.9in; border-collapse: collapse; font-size: 11pt; table-layout: fixed; }
.d2-items th, .d2-items td { border: 1px solid #000; padding: 1pt 5pt; text-align: left; }
.d2-items th    { background: #fff; font-weight: bold; }    /* white header (no grey) */
.d2-items td.d2-sku, .d2-items th.d2-sku { width: 1.0in; font-weight: bold; }
.d2-items .d2-qty { width: 0.6in; text-align: center; }
.d2-items .d2-name { width: 4.0in; }
.d2-items .d2-cat  { width: 1.1in; }
.d2-totals      { position: absolute; left: 0.24in; top: 4.18in; font-size: 10pt; font-weight: bold; }
.d2-totals .d2-page { margin-left: 1.0in; }
.d2-notes       { position: absolute; left: 0.24in; top: 4.46in; width: 6.9in; font-size: 10pt; }
.d2-notes .d2-notes-label { font-weight: bold; }
/* Divider: a filled bar (background-color), NOT border-top (which bleeds full
   width in dompdf). Shared geometry with doc 4's anchor. */
.d2-divider     { position: absolute; left: {$div_l}in; top: {$div_t}in; width: {$div_w}in; height: 2px; background-color: #000; font-size: 0; line-height: 0; }

/* ---- DOC 4 driver block (right-region overlay position; NO divider) ---- */
.d4-block       { position: absolute; left: {$b_l}in; top: {$b_t}in; width: {$b_w}in; font-size: 12pt; line-height: 1.5; }
.d4-block .d4-collect { font-size: 16pt; font-weight: bold; margin-bottom: 0.08in; }
.d4-block .d4-name    { font-size: 16pt; font-weight: bold; }
.d4-block .d4-addr    { font-size: 12pt; }
.d4-block .d4-phone   { font-size: 12pt; }
CSS;
    }
}
