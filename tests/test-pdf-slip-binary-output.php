<?php
/**
 * Phase T: PDF binary output smoke test.
 *
 * Drives the full pipeline through DomPDF and asserts the bytes start
 * with the PDF magic. Visual fidelity isn't covered — this just
 * confirms the pipeline produces a syntactically valid PDF.
 *
 * Skipped if DomPDF isn't installed (composer dependency).
 *
 * Run: php tests/test-pdf-slip-binary-output.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';

if (!class_exists('Dompdf\\Dompdf')) {
    fwrite(STDOUT, "skipped (DomPDF not installed; run composer install)\n");
    exit(0);
}

pdf_slip_reset_globals();

$GLOBALS['_pdf_slip_options']['mealsdb_fee_product_ids'] = [
    'client_contribution' => 5675,
    'delivery_fee'        => 4122,
];
$GLOBALS['_pdf_slip_terms'] = [101 => [35], 201 => [37]];

$wc_order = new WC_Order();
$wc_order->total = 50.00;
$wc_order->payment_method = 'cash';
$wc_order->customer_note = 'Test slip';
$GLOBALS['_pdf_slip_wc_orders'][999] = $wc_order;

$client = [
    'client_id' => 1, 'client_type' => 'Private',
    'delivery_initials' => 'TST', 'delivery_area_name' => 'Zone 1',
    'first_name' => 'Test', 'last_name' => 'User',
    'delivery_street_name' => '1 Foo Rd', 'delivery_city' => 'Moncton',
    'client_phone_1' => '506-000-0000', 'delivery_fee' => 5.00,
    'payment_method' => 'cash',
];

$orders = [[
    'order_id' => 999, 'wp_user_id' => 1,
    'date_created_gmt' => '2025-06-15 09:00:00',
    'items' => [
        ['wc_product_id' => 101, 'quantity' => 3, 'order_item_name' => 'Beef Stew'],
        ['wc_product_id' => 201, 'quantity' => 1, 'order_item_name' => 'Bran Muffin'],
    ],
]];

// Stand up the generator with the fake client query, then drive
// build_slips and the PDF render path manually so we exercise both.
$client_query = new PdfSlipFakeClientQuery();
$client_query->clients_for_date = [1 => $client];
$client_query->orders = $orders;

$generator = new MealsDB_Slip_PDF_Generator($client_query, new MealsDB_Collection_Calculator());

$pdf = $generator->generate_packer_slips_for_date('2025-06-15');
pdf_slip_assert_true(is_string($pdf), 'PDF output is a string');
pdf_slip_assert_true(strlen($pdf) > 100, 'PDF binary has reasonable size');
pdf_slip_assert_equal('%PDF-', substr($pdf, 0, 5), 'PDF starts with %PDF- magic bytes');

$client_query->clients_for_driver = [1 => $client]; // bind for driver path
$pdf_driver = $generator->generate_driver_slips_for_date('2025-06-15');
pdf_slip_assert_equal('%PDF-', substr($pdf_driver, 0, 5), 'driver PDF also valid');

pdf_slip_finish();
