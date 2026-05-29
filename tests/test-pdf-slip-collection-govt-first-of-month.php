<?php
/**
 * Phase T: government client, FIRST delivery of billing month.
 *
 * Breakdown shows Delivery Fee + Client Contribution; collect = sum.
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

// is_first_delivery_of_month: contribution not yet applied this month, and the
// MIN(delivery_date) equals today's delivery_date — so this IS the genuine first
// delivery and the contribution should be collected (LB-4 case 3).
global $wpdb;
$wpdb->get_var_handler = static function ($query, $args) {
    // Args: [client_id, billing_month]
    if (strpos($query, 'contribution_applied') !== false) {
        return 0; // contribution not yet applied this month
    }
    return '2025-02-20'; // earliest delivery in meals_delivery_allocations
};

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
    'items' => [['wc_product_id' => 101, 'quantity' => 5, 'order_item_name' => 'Meal']],
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
