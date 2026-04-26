<?php
/**
 * Phase T: fee SKUs are rewritten on slips.
 *
 *   product_id 5675 → "CONT"
 *   product_id 4122 → "FEE"
 *
 * Both rendered in the Fee category and ordered CONT-then-FEE.
 *
 * Run: php tests/test-pdf-slip-fee-skus-rewritten.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

$GLOBALS['_pdf_slip_options']['mealsdb_fee_product_ids'] = [
    'client_contribution' => 5675,
    'delivery_fee'        => 4122,
];
$GLOBALS['_pdf_slip_terms'] = [101 => [35]];

$wc_order = new WC_Order();
$wc_order->total = 100.00;
$wc_order->payment_method = 'cash';
$GLOBALS['_pdf_slip_wc_orders'][1] = $wc_order;

$client = [
    'client_id' => 1,
    'client_type' => 'Private',
    'delivery_initials' => 'AAA',
    'delivery_area_name' => 'Zone 1',
    'delivery_fee' => 0.00,
    'payment_method' => 'cash',
];

// Note: order is "FEE first, CONT second" in the source — exercise that
// the sorter puts CONT before FEE regardless of input order.
$orders = [[
    'order_id' => 1,
    'wp_user_id' => 1,
    'date_created_gmt' => '2025-03-10 12:00:00',
    'items' => [
        ['wc_product_id' => 4122, 'quantity' => 1, 'order_item_name' => 'Delivery Fee'],
        ['wc_product_id' => 101,  'quantity' => 3, 'order_item_name' => 'Beef Stew'],
        ['wc_product_id' => 5675, 'quantity' => 1, 'order_item_name' => 'Client Contribution'],
    ],
]];

$slips = pdf_slip_build_slips($orders, [1 => $client], false);
$items = $slips[0]['items'];

pdf_slip_assert_equal(3, count($items), 'three items kept');

// Find the fees by SKU.
$by_sku = [];
foreach ($items as $i => $item) {
    $by_sku[$item['sku']] = $i;
}

pdf_slip_assert_true(isset($by_sku['CONT']), '5675 rewritten to CONT');
pdf_slip_assert_true(isset($by_sku['FEE']),  '4122 rewritten to FEE');
pdf_slip_assert_equal('Fee', $items[$by_sku['CONT']]['category'], 'CONT category=Fee');
pdf_slip_assert_equal('Fee', $items[$by_sku['FEE']]['category'],  'FEE category=Fee');

// CONT must precede FEE in the sorted list.
pdf_slip_assert_true($by_sku['CONT'] < $by_sku['FEE'], 'CONT sorts before FEE');

pdf_slip_finish();
