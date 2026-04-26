<?php
/**
 * Phase T: government client with zero client_contribution.
 *
 * Breakdown shows Delivery Fee only — no Client Contribution row.
 *
 * Run: php tests/test-pdf-slip-collection-govt-no-contribution.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

$GLOBALS['_pdf_slip_options']['mealsdb_fee_product_ids'] = [
    'client_contribution' => 5675,
    'delivery_fee'        => 4122,
];
$GLOBALS['_pdf_slip_terms'] = [101 => [35]];

global $wpdb;
// Even if first delivery, contribution=0 means no contribution row.
$wpdb->get_var_handler = static function () {
    return '2025-02-20'; // first delivery of month
};

$wc_order = new WC_Order();
$wc_order->total = 0.00;
$wc_order->payment_method = '';
$GLOBALS['_pdf_slip_wc_orders'][200] = $wc_order;

$client = [
    'client_id'        => 11,
    'client_type'      => 'SDNB',
    'delivery_initials'=> 'GOV',
    'delivery_area_name'=> 'Zone 3',
    'first_name'       => 'Gov',
    'last_name'        => 'Client',
    'delivery_street_name' => '5 Beech Ave',
    'delivery_city'    => 'Moncton',
    'client_phone_1'   => '506-555-0001',
    'delivery_fee'     => 10.00,
    'client_contribution' => 0.00,
    'payment_method'   => '',
];

$orders = [[
    'order_id'         => 200,
    'wp_user_id'       => 11,
    'date_created_gmt' => '2025-02-20 12:00:00',
    'items' => [['wc_product_id' => 101, 'quantity' => 5, 'order_item_name' => 'Meal']],
]];

$slips = pdf_slip_build_slips($orders, [11 => $client], true);
$driver = $slips[0]['driver'];

pdf_slip_assert_equal('SDNB', $driver['client_type'], 'govt: client_type kept');
pdf_slip_assert_equal(1, count($driver['breakdown']), 'one row when no contribution');
pdf_slip_assert_equal('Delivery Fee', $driver['breakdown'][0]['label'], 'only Delivery Fee row');
pdf_slip_assert_equal(10.00, (float) $driver['breakdown'][0]['amount'], 'delivery fee amount');
pdf_slip_assert_equal(10.00, (float) $driver['collect_amount'], 'collect = delivery fee only');

pdf_slip_finish();
