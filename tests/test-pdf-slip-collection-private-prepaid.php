<?php
/**
 * Phase T: private + non-cash + zero delivery_fee → prepaid, collect 0.
 *
 * Breakdown still itemises Products / Taxes / Delivery Fee so the
 * driver sees what's settled, just nothing to collect at the door.
 *
 * Run: php tests/test-pdf-slip-collection-private-prepaid.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

$GLOBALS['_pdf_slip_options']['mealsdb_fee_product_ids'] = [
    'client_contribution' => 5675,
    'delivery_fee'        => 4122,
];
$GLOBALS['_pdf_slip_terms'] = [101 => [35]];

$wc_order = new WC_Order();
$wc_order->subtotal = 25.00;
$wc_order->tax = 0.00;
$wc_order->total = 25.00;
$wc_order->payment_method = 'stripe';
$GLOBALS['_pdf_slip_wc_orders'][101] = $wc_order;

$client = [
    'client_id' => 1,
    'client_type' => 'Private',
    'delivery_initials' => 'PPD',
    'delivery_area_name' => 'Zone 1',
    'first_name' => 'Pre',
    'last_name'  => 'Paid',
    'delivery_street_name' => '1 Foo Rd',
    'delivery_city' => 'Moncton',
    'client_phone_1' => '555-0000',
    'delivery_fee' => 0.00,
    'payment_method' => 'stripe',
];

$orders = [[
    'order_id' => 101,
    'wp_user_id' => 1,
    'date_created_gmt' => '2025-02-20 09:00:00',
    'items' => [
        ['wc_product_id' => 101, 'quantity' => 2, 'order_item_name' => 'Meal'],
    ],
]];

$slips = pdf_slip_build_slips($orders, [1 => $client], true);
$driver = $slips[0]['driver'];

pdf_slip_assert_equal(0.00, (float) $driver['collect_amount'], 'prepaid: collect = 0.00');
pdf_slip_assert_equal('Collect: $0.00', $driver['collect_label'], 'collect label = $0.00');

// Breakdown still has the three rows.
pdf_slip_assert_equal(3, count($driver['breakdown']), 'three breakdown rows even when prepaid');
pdf_slip_assert_equal(0.00, (float) $driver['breakdown'][2]['amount'], 'delivery fee row = 0');

pdf_slip_finish();
