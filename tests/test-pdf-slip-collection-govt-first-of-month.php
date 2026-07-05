<?php
/**
 * Phase T: government client, the delivery that COLLECTS the monthly contribution.
 *
 * U06-slips-3: the door contribution is collected on the delivery of the ORDER
 * that carries the client_contribution fee product (SKU CONT) — the one order
 * per client per month that the fee path billed it onto. So this order's items
 * include product 5675, and the breakdown shows Delivery Fee + Client
 * Contribution; collect = sum. (Previously this was driven by the
 * contribution_applied summary flag, which was already set at order time and so
 * suppressed door collection entirely.)
 *
 * Run: php tests/test-pdf-slip-collection-govt-first-of-month.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

$GLOBALS['_pdf_slip_options']['mealsdb_fee_product_ids'] = [
    'client_contribution' => 5675,
    'delivery_fee'        => 4122,
];
$GLOBALS['_pdf_slip_terms'] = [101 => [35]];

$wc_order = new WC_Order();
$wc_order->total = 0.00;
$wc_order->payment_method = '';
$GLOBALS['_pdf_slip_wc_orders'][201] = $wc_order;

$client = [
    'client_id'        => 22,
    'client_type'      => 'SDNB',
    'delivery_initials'=> 'GOV',
    'delivery_area_name'=> 'Zone 3',
    'first_name'       => 'Gov',
    'last_name'        => 'Client',
    'delivery_street_name' => '5 Beech Ave',
    'delivery_city'    => 'Moncton',
    'client_phone_1'   => '506-555-0001',
    'delivery_fee'     => 10.00,
    'client_contribution' => 60.00,
    'payment_method'   => '',
];

$orders = [[
    'order_id'         => 201,
    'wp_user_id'       => 22,
    'date_created_gmt' => '2025-02-20 12:00:00',
    // This order carries the contribution fee line (product 5675) — the fee
    // path billed the monthly contribution onto it, so this is the delivery
    // that collects it at the door.
    'items' => [
        ['wc_product_id' => 101,  'quantity' => 5, 'order_item_name' => 'Meal'],
        ['wc_product_id' => 5675, 'quantity' => 1, 'order_item_name' => 'Client Contribution'],
    ],
]];

$slips = pdf_slip_build_slips($orders, [22 => $client], true);
$driver = $slips[0]['driver'];

pdf_slip_assert_equal(2, count($driver['breakdown']), 'two breakdown rows on first delivery');
pdf_slip_assert_equal('Delivery Fee',         $driver['breakdown'][0]['label'], 'row 1 = Delivery Fee');
pdf_slip_assert_equal(10.00,                  (float) $driver['breakdown'][0]['amount'], 'delivery fee');
pdf_slip_assert_equal('Client Contribution',  $driver['breakdown'][1]['label'], 'row 2 = Client Contribution');
pdf_slip_assert_equal(60.00,                  (float) $driver['breakdown'][1]['amount'], 'contribution amount');

pdf_slip_assert_equal(70.00, (float) $driver['collect_amount'], 'collect = delivery + contribution');
pdf_slip_assert_equal('Collect: $70.00', $driver['collect_label'], 'collect label');

pdf_slip_finish();
