<?php
/**
 * Phase T: private + cash → collect = total + delivery_fee.
 *
 * Breakdown rows: Products / Taxes / Delivery Fee.
 *
 * Run: php tests/test-pdf-slip-collection-private-cash.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

$GLOBALS['_pdf_slip_options']['mealsdb_fee_product_ids'] = [
    'client_contribution' => 5675,
    'delivery_fee'        => 4122,
];
$GLOBALS['_pdf_slip_terms'] = [101 => [35]];

$wc_order = new WC_Order();
$wc_order->subtotal = 33.23;
$wc_order->tax = 4.98;
$wc_order->total = 38.21;
$wc_order->payment_method = 'cash';
$GLOBALS['_pdf_slip_wc_orders'][100] = $wc_order;

$client = [
    'client_id'        => 1,
    'client_type'      => 'Private',
    'delivery_initials'=> 'XYZ',
    'delivery_area_name'=> 'Zone 2',
    'first_name'       => 'Wayne',
    'last_name'        => 'Zorn',
    'delivery_street_name' => '173 Main St',
    'delivery_city'    => 'Shediac',
    'client_phone_1'   => '506-743-2285',
    'delivery_fee'     => 10.00,
    'payment_method'   => 'cash',
];

$orders = [[
    'order_id'         => 100,
    'wp_user_id'       => 1,
    'date_created_gmt' => '2025-02-20 12:00:00',
    'items' => [
        ['wc_product_id' => 101, 'quantity' => 3, 'order_item_name' => 'Meal'],
    ],
]];

$slips = pdf_slip_build_slips($orders, [1 => $client], true);
$driver = $slips[0]['driver'];

pdf_slip_assert_equal('Private', $driver['client_type'], 'driver block type');
pdf_slip_assert_equal('Wayne Zorn', $driver['client_name'], 'name decoded');
pdf_slip_assert_equal('173 Main St', $driver['street'], 'street');
pdf_slip_assert_equal('Shediac', $driver['city'], 'city');
pdf_slip_assert_equal('506-743-2285', $driver['phone'], 'phone');

// Breakdown: Products + Taxes + Delivery Fee.
pdf_slip_assert_equal(3, count($driver['breakdown']), 'three breakdown rows');
pdf_slip_assert_equal('Products',     $driver['breakdown'][0]['label'], 'row 1 label');
pdf_slip_assert_equal(33.23,          (float) $driver['breakdown'][0]['amount'], 'products = total - tax');
pdf_slip_assert_equal('Taxes',        $driver['breakdown'][1]['label'], 'row 2 label');
pdf_slip_assert_equal(4.98,           (float) $driver['breakdown'][1]['amount'], 'taxes from WC');
pdf_slip_assert_equal('Delivery Fee', $driver['breakdown'][2]['label'], 'row 3 label');
pdf_slip_assert_equal(10.00,          (float) $driver['breakdown'][2]['amount'], 'delivery_fee from client');

// Collect total = WC total + delivery_fee = 38.21 + 10.00 = 48.21.
pdf_slip_assert_equal(48.21, (float) $driver['collect_amount'], 'cash: collect = total + delivery_fee');
pdf_slip_assert_equal('Collect: $48.21', $driver['collect_label'], 'collect label formatted');

pdf_slip_finish();
