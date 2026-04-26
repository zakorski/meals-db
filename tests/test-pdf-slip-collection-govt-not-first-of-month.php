<?php
/**
 * Phase T: government client, NOT first delivery of billing month.
 *
 * Breakdown drops Client Contribution; collect = delivery fee only.
 *
 * Run: php tests/test-pdf-slip-collection-govt-not-first-of-month.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

$GLOBALS['_pdf_slip_options']['mealsdb_fee_product_ids'] = [
    'client_contribution' => 5675,
    'delivery_fee'        => 4122,
];
$GLOBALS['_pdf_slip_terms'] = [101 => [35]];

// MIN(delivery_date) for this client+month is earlier than today, so
// today is NOT the first delivery → no contribution.
global $wpdb;
$wpdb->get_var_handler = static function ($query, $args) {
    return '2025-02-06'; // earlier than the order's 2025-02-20
};

$wc_order = new WC_Order();
$wc_order->total = 0.00;
$wc_order->payment_method = '';
$GLOBALS['_pdf_slip_wc_orders'][202] = $wc_order;

$client = [
    'client_id' => 33,
    'client_type' => 'SDNB',
    'delivery_initials' => 'GOV',
    'delivery_area_name' => 'Zone 3',
    'first_name' => 'Gov',
    'last_name' => 'Client',
    'delivery_street_name' => '5 Beech Ave',
    'delivery_city' => 'Moncton',
    'client_phone_1' => '506-555-0001',
    'delivery_fee' => 10.00,
    'client_contribution' => 60.00,
    'payment_method' => '',
];

$orders = [[
    'order_id' => 202,
    'wp_user_id' => 33,
    'date_created_gmt' => '2025-02-20 12:00:00',
    'items' => [['wc_product_id' => 101, 'quantity' => 5, 'order_item_name' => 'Meal']],
]];

$slips = pdf_slip_build_slips($orders, [33 => $client], true);
$driver = $slips[0]['driver'];

pdf_slip_assert_equal(1, count($driver['breakdown']), 'only delivery fee row when not first delivery');
pdf_slip_assert_equal('Delivery Fee', $driver['breakdown'][0]['label'], 'delivery fee row');
pdf_slip_assert_equal(10.00, (float) $driver['breakdown'][0]['amount'], 'fee amount');
pdf_slip_assert_equal(10.00, (float) $driver['collect_amount'], 'collect = delivery fee only');

// Veteran client: same logic.
$client2 = $client;
$client2['client_id']   = 34;
$client2['client_type'] = 'Veteran';

$orders2 = $orders;
$orders2[0]['order_id']    = 203;
$orders2[0]['wp_user_id']  = 34;
$GLOBALS['_pdf_slip_wc_orders'][203] = $wc_order;

$slips2 = pdf_slip_build_slips($orders2, [34 => $client2], true);
$driver2 = $slips2[0]['driver'];
pdf_slip_assert_equal(10.00, (float) $driver2['collect_amount'], 'Veteran behaves same as SDNB');
pdf_slip_assert_equal(1, count($driver2['breakdown']), 'Veteran: only delivery fee row');

pdf_slip_finish();
