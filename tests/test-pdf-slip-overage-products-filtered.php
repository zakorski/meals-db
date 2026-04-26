<?php
/**
 * Phase T: legacy overage products are filtered out completely.
 *
 * Defaults 5056/5059/5180 — present in historical orders but never
 * appear on slips.
 *
 * Run: php tests/test-pdf-slip-overage-products-filtered.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

$GLOBALS['_pdf_slip_options']['mealsdb_fee_product_ids'] = [
    'client_contribution' => 5675,
    'delivery_fee'        => 4122,
    // Defaults — explicitly populated to confirm they get picked up.
    'overage_main'             => 5056,
    'overage_sides_taxable'    => 5059,
    'overage_sides_nontaxable' => 5180,
];

$GLOBALS['_pdf_slip_terms'] = [
    101 => [35],
    201 => [37],
];

$wc_order = new WC_Order();
$wc_order->total = 50.00;
$wc_order->payment_method = 'cash';
$GLOBALS['_pdf_slip_wc_orders'][555] = $wc_order;

$client = [
    'client_id' => 5,
    'client_type' => 'Private',
    'delivery_initials' => 'ABC',
    'delivery_area_name' => 'Zone 1',
    'delivery_fee' => 5.00,
    'payment_method' => 'cash',
];

$orders = [[
    'order_id' => 555,
    'wp_user_id' => 1,
    'date_created_gmt' => '2025-01-15 09:00:00',
    'items' => [
        ['wc_product_id' => 101, 'quantity' => 5, 'order_item_name' => 'Meal'],
        ['wc_product_id' => 5056, 'quantity' => 2, 'order_item_name' => 'Overage Main (legacy)'],
        ['wc_product_id' => 5059, 'quantity' => 1, 'order_item_name' => 'Overage Side Taxable (legacy)'],
        ['wc_product_id' => 5180, 'quantity' => 1, 'order_item_name' => 'Overage Side Nontaxable (legacy)'],
        ['wc_product_id' => 201, 'quantity' => 3, 'order_item_name' => 'Bran Muffin'],
    ],
]];

$slips = pdf_slip_build_slips($orders, [1 => $client], false);
$items = $slips[0]['items'];

// Three legitimate items remain; three overages are dropped.
pdf_slip_assert_equal(2, count($items), 'overage products dropped from slip');

$skus_or_names = array_map(static function ($i) { return $i['product_name']; }, $items);
foreach ($skus_or_names as $name) {
    if (stripos($name, 'overage') !== false) {
        pdf_slip_assert_true(false, 'no overage product in items: ' . $name);
    }
}

// Totals reflect the filtering.
pdf_slip_assert_equal(8, $slips[0]['total_items'], 'totals exclude overages');
pdf_slip_assert_equal(5, $slips[0]['total_mains'], 'mains exclude overages');
pdf_slip_assert_equal(3, $slips[0]['total_sides'], 'sides exclude overages');

pdf_slip_finish();
