<?php
/**
 * Phase T: total_items counts only Main + Side qty.
 *
 * Fees (CONT/FEE) and any product not categorised as Main count as
 * Side per the resolver, but the example slip's "30 items" was wrong
 * because it counted CONT+FEE — going forward, fees are excluded.
 *
 * Run: php tests/test-pdf-slip-total-items-excludes-fees.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

$GLOBALS['_pdf_slip_options']['mealsdb_fee_product_ids'] = [
    'client_contribution' => 5675,
    'delivery_fee'        => 4122,
];
$GLOBALS['_pdf_slip_terms'] = [101 => [35], 201 => [25]];

$wc_order = new WC_Order();
$wc_order->total = 50.00;
$wc_order->payment_method = 'cash';
$GLOBALS['_pdf_slip_wc_orders'][20] = $wc_order;

$client = [
    'client_id' => 1,
    'client_type' => 'Private',
    'delivery_initials' => 'AAA',
    'delivery_area_name' => 'Zone 1',
    'delivery_fee' => 5.00,
    'payment_method' => 'cash',
];

$orders = [[
    'order_id' => 20,
    'wp_user_id' => 1,
    'date_created_gmt' => '2025-02-20 12:00:00',
    'items' => [
        ['wc_product_id' => 101,  'quantity' => 14, 'order_item_name' => 'Macaroni Meat Casserole'],
        ['wc_product_id' => 201,  'quantity' => 14, 'order_item_name' => 'Bran Muffin'], // counted as side via cat=25
        ['wc_product_id' => 5675, 'quantity' => 1,  'order_item_name' => 'Contribution'],
        ['wc_product_id' => 4122, 'quantity' => 1,  'order_item_name' => 'Delivery Fee'],
    ],
]];

$slips = pdf_slip_build_slips($orders, [1 => $client], false);
$slip = $slips[0];

// 14 mains + 14 sides + 2 fees = 30 raw, but fees are excluded → 28.
pdf_slip_assert_equal(28, $slip['total_items'], 'total_items excludes fees');
pdf_slip_assert_equal(14, $slip['total_mains'], 'total_mains');
pdf_slip_assert_equal(14, $slip['total_sides'], 'total_sides');

pdf_slip_finish();
