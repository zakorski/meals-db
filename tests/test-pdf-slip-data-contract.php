<?php
/**
 * Phase T: $slip_data shape contract.
 *
 * Verifies the build_slips pipeline produces the documented array
 * structure for a representative private order with mixed line items.
 *
 * Run: php tests/test-pdf-slip-data-contract.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

// -- Fee product config + categories -------------------------------------
$GLOBALS['_pdf_slip_options']['mealsdb_fee_product_ids'] = [
    'client_contribution' => 5675,
    'delivery_fee'        => 4122,
];

// 101 / 102 are mains, 201 is a side, 5675/4122 are fees.
$GLOBALS['_pdf_slip_terms'] = [
    101 => [35],
    102 => [35],
    201 => [37], // muffins → side
];

// -- Fake order ----------------------------------------------------------
$wc_order = new WC_Order();
$wc_order->id = 10542;
// WC order total covers products + tax (delivery fee is the
// client's separate invoice line). For a cash customer the driver
// collects total + delivery_fee = 338.03 + 10.00 = 348.03.
$wc_order->subtotal = 338.03;
$wc_order->tax = 0.00;
$wc_order->total = 338.03;
$wc_order->payment_method = 'cash';
$wc_order->customer_note = 'TAKE FROM HOLD';
$GLOBALS['_pdf_slip_wc_orders'][10542] = $wc_order;

$client = [
    'client_id'           => 7,
    'wp_user_id'          => 42,
    'delivery_initials'   => 'WZN',
    'delivery_area_name'  => 'Zone 4',
    'delivery_area_zone'  => '4',
    'client_type'         => 'Private',
    'delivery_fee'        => 10.00,
    'payment_method'      => 'cash',
];

$orders = [[
    'order_id'         => 10542,
    'wp_user_id'       => 42,
    'date_created_gmt' => '2025-02-20 12:00:00',
    'items' => [
        ['wc_product_id' => 101, 'quantity' => 14, 'order_item_name' => 'Macaroni Meat Casserole'],
        ['wc_product_id' => 102, 'quantity' => 14, 'order_item_name' => 'Beef Stew'],
        ['wc_product_id' => 201, 'quantity' => 4,  'order_item_name' => 'Bran Muffin'],
        ['wc_product_id' => 4122, 'quantity' => 1, 'order_item_name' => 'Delivery Fee'],
    ],
]];

$clients = [42 => $client];

$slips = pdf_slip_build_slips($orders, $clients, true);
pdf_slip_assert_equal(1, count($slips), 'one slip per order');

$slip = $slips[0];

// Header.
pdf_slip_assert_equal('WZN', $slip['initials'], 'initials');
pdf_slip_assert_equal('Zone 4', $slip['zone'], 'zone');
pdf_slip_assert_equal('#10542', $slip['order_number'], 'order_number formatted with #');
pdf_slip_assert_equal('Thursday, February 20, 2025', $slip['delivery_date'], 'long-form delivery date');

// Items present.
pdf_slip_assert_equal(4, count($slip['items']), 'four items kept');

// First two items are mains, then the side, then the fee.
pdf_slip_assert_equal('Main', $slip['items'][0]['category'], 'sorted: mains first');
pdf_slip_assert_equal('Side', $slip['items'][2]['category'], 'sorted: side after mains');
pdf_slip_assert_equal('Fee',  $slip['items'][3]['category'], 'sorted: fee last');

// Totals exclude fees.
pdf_slip_assert_equal(32, $slip['total_items'], 'total_items = mains + sides only');
pdf_slip_assert_equal(28, $slip['total_mains'], 'total_mains');
pdf_slip_assert_equal(4,  $slip['total_sides'], 'total_sides');

// Notes pulled from customer_note.
pdf_slip_assert_equal('TAKE FROM HOLD', $slip['additional_notes'], 'additional_notes from customer_note');

// order_number prefers WC's get_order_number() over the raw post ID.
$wc_order->display_number = 'INV-2025-042';
$slips_renumbered = pdf_slip_build_slips($orders, $clients, true);
pdf_slip_assert_equal('#INV-2025-042', $slips_renumbered[0]['order_number'], 'order_number uses WC get_order_number()');
$wc_order->display_number = null;

// HTML render: every slip's items table carries the continuation
// marker row in its <thead> so DomPDF auto-repeats it on overflow.
$gen = new MealsDB_Slip_PDF_Generator(new PdfSlipFakeClientQuery(), new MealsDB_Collection_Calculator());
$render_html = new ReflectionMethod(MealsDB_Slip_PDF_Generator::class, 'render_html');
$render_html->setAccessible(true);
$html = $render_html->invoke($gen, $slips, true);
pdf_slip_assert_true(strpos($html, 'continued from previous page') !== false, 'continuation marker present in slip HTML');
pdf_slip_assert_true(strpos($html, 'class="continued-row"') !== false, 'continued-row thead row rendered');

// Driver block populated for driver slips.
pdf_slip_assert_true(isset($slip['driver']), 'driver block present');
pdf_slip_assert_equal('Private', $slip['driver']['client_type'], 'driver.client_type');
pdf_slip_assert_equal(348.03, (float) $slip['driver']['collect_amount'], 'private cash: collect = total + delivery_fee');

pdf_slip_finish();
