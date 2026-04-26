<?php
/**
 * Phase T: additional_notes from $order->get_customer_note().
 *
 * Empty note → empty string in slip data (template should hide the
 * label entirely). Populated → trimmed value passed through.
 *
 * Run: php tests/test-pdf-slip-additional-notes.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

$GLOBALS['_pdf_slip_options']['mealsdb_fee_product_ids'] = [
    'client_contribution' => 5675,
    'delivery_fee'        => 4122,
];
$GLOBALS['_pdf_slip_terms'] = [101 => [35]];

// First slip: empty note.
$wc_empty = new WC_Order();
$wc_empty->total = 25.00;
$wc_empty->payment_method = 'cash';
$wc_empty->customer_note = '   '; // whitespace gets trimmed away
$GLOBALS['_pdf_slip_wc_orders'][301] = $wc_empty;

// Second slip: populated note.
$wc_populated = new WC_Order();
$wc_populated->total = 25.00;
$wc_populated->payment_method = 'cash';
$wc_populated->customer_note = "  Leave at side door  \n";
$GLOBALS['_pdf_slip_wc_orders'][302] = $wc_populated;

$client = [
    'client_id' => 1, 'client_type' => 'Private',
    'delivery_initials' => 'NTE', 'delivery_area_name' => 'Zone 1',
    'first_name' => 'A', 'last_name' => 'B',
    'delivery_street_name' => '', 'delivery_city' => '',
    'client_phone_1' => '', 'delivery_fee' => 0.00, 'payment_method' => 'cash',
];

$orders = [
    [
        'order_id' => 301, 'wp_user_id' => 1,
        'date_created_gmt' => '2025-05-01 09:00:00',
        'items' => [['wc_product_id' => 101, 'quantity' => 1, 'order_item_name' => 'Meal']],
    ],
    [
        'order_id' => 302, 'wp_user_id' => 1,
        'date_created_gmt' => '2025-05-01 10:00:00',
        'items' => [['wc_product_id' => 101, 'quantity' => 1, 'order_item_name' => 'Meal']],
    ],
];

$slips = pdf_slip_build_slips($orders, [1 => $client], false);

pdf_slip_assert_equal('', $slips[0]['additional_notes'], 'whitespace-only note → empty string');
pdf_slip_assert_equal('Leave at side door', $slips[1]['additional_notes'], 'populated note trimmed');

// Render HTML and verify the empty-note slip has no notes-block, the
// populated one does.
$generator = new MealsDB_Slip_PDF_Generator(new PdfSlipFakeClientQuery(), new MealsDB_Collection_Calculator());
$render_html_method = new ReflectionMethod(MealsDB_Slip_PDF_Generator::class, 'render_html');
$render_html_method->setAccessible(true);
$html = $render_html_method->invoke($generator, $slips, false);

// Count occurrences of the notes block.
$count = substr_count($html, 'class="notes-block"');
pdf_slip_assert_equal(1, $count, 'one notes-block rendered (only populated slip)');

pdf_slip_assert_true(strpos($html, 'Leave at side door') !== false, 'populated note text in HTML');
pdf_slip_assert_true(strpos($html, 'Additional Notes:') !== false, 'Additional Notes label rendered');

pdf_slip_finish();
