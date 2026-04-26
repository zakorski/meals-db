<?php
/**
 * Phase T: items sort by category rank → freezer order → SKU.
 *
 *   1. Mains (cat=35) ascend by _freezer_order
 *   2. Sides (cat in 23/25/37/43/98) ascend by _freezer_order
 *   3. Fees: CONT, then FEE
 *
 * Run: php tests/test-pdf-slip-item-sort-order.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

$GLOBALS['_pdf_slip_options']['mealsdb_fee_product_ids'] = [
    'client_contribution' => 5675,
    'delivery_fee'        => 4122,
];
$GLOBALS['_pdf_slip_terms'] = [
    101 => [35], 102 => [35], 103 => [35], // mains
    201 => [37], 202 => [43],              // sides
];

// Stub freezer-order postmeta lookup.
global $wpdb;
$wpdb->get_results_handler = function ($query, $args) {
    if (stripos($query, '_freezer_order') !== false) {
        // Expected order: 102 ahead of 101 ahead of 103 for mains.
        // Sides 202 ahead of 201.
        return [
            ['post_id' => 102, 'meta_value' => 1],
            ['post_id' => 101, 'meta_value' => 5],
            ['post_id' => 103, 'meta_value' => 9],
            ['post_id' => 202, 'meta_value' => 2],
            ['post_id' => 201, 'meta_value' => 7],
        ];
    }
    if (stripos($query, '_sku') !== false) {
        return [
            ['post_id' => 101, 'meta_value' => '12001'],
            ['post_id' => 102, 'meta_value' => '12002'],
            ['post_id' => 103, 'meta_value' => '12003'],
            ['post_id' => 201, 'meta_value' => '20001'],
            ['post_id' => 202, 'meta_value' => '20002'],
        ];
    }
    return [];
};

$wc_order = new WC_Order();
$wc_order->total = 100.00;
$wc_order->payment_method = 'cash';
$GLOBALS['_pdf_slip_wc_orders'][9] = $wc_order;

$client = [
    'client_id' => 1,
    'client_type' => 'Private',
    'delivery_initials' => 'AAA',
    'delivery_area_name' => 'Zone 1',
    'delivery_fee' => 5.00,
    'payment_method' => 'cash',
];

$orders = [[
    'order_id' => 9,
    'wp_user_id' => 1,
    'date_created_gmt' => '2025-04-01 09:00:00',
    'items' => [
        ['wc_product_id' => 4122, 'quantity' => 1, 'order_item_name' => 'Delivery Fee'],
        ['wc_product_id' => 101,  'quantity' => 1, 'order_item_name' => 'Main 101'],
        ['wc_product_id' => 5675, 'quantity' => 1, 'order_item_name' => 'Contribution'],
        ['wc_product_id' => 201,  'quantity' => 1, 'order_item_name' => 'Side 201'],
        ['wc_product_id' => 202,  'quantity' => 1, 'order_item_name' => 'Side 202'],
        ['wc_product_id' => 103,  'quantity' => 1, 'order_item_name' => 'Main 103'],
        ['wc_product_id' => 102,  'quantity' => 1, 'order_item_name' => 'Main 102'],
    ],
]];

$slips = pdf_slip_build_slips($orders, [1 => $client], false);
$items = $slips[0]['items'];

$skus = array_column($items, 'sku');

// Expected: mains sorted by freezer (102→101→103), sides (202→201), then CONT, FEE.
pdf_slip_assert_equal(['12002', '12001', '12003', '20002', '20001', 'CONT', 'FEE'], $skus, 'sort: mains→sides→CONT→FEE by freezer order');

pdf_slip_finish();
