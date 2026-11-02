<?php
/**
 * DIRECTIVE K20 — a phone value with no real number in it is empty (slips).
 *
 * Enzebra stores `0` as a "no phone" placeholder in shipping_phone for 157
 * clients; K19 mirrors it into client_phone_2 verbatim, so before K20 the doc 4
 * driver block printed `0` as a second phone line.
 *
 *   T3   doc 4: client_phone_2 = '0' → ONE phone line. FAILS against v1.0.590.
 *   T4   doc 4: client_phone_1 = '0', real client_phone_2 → the real number
 *        prints (the placeholder primary must not hide it via is_same_number).
 *   T6   doc 4: multi-number and 7-digit values still print unchanged.
 *   AC   doc 4: a placeholder contact phone prints no contact line digits.
 *   D1   daily driver slip: placeholder primary never prints; falls back to a
 *        real secondary.
 *   B1   build_driver_block: a placeholder alternate_contact_phone_1 does not
 *        shadow a real alternate_contact_phone_2.
 *
 * Run: php tests/test-pdf-slip-k20-placeholder-phones.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

$count_phone_lines = static fn (string $html): int => substr_count($html, 'class="d4-phone"');
$base = ['client_name' => 'Test Client', 'street' => '1 Main St', 'city' => 'Moncton', 'postal' => 'E1C 1A1'];

// T3 — placeholder secondary.
$html = MealsDB_Slip_PDF_Generator::driver_block_inner_html($base + ['phone' => '506-858-1234', 'phone_secondary' => '0']);
pdf_slip_assert_equal(1, $count_phone_lines($html), 'T3 one phone line when client_phone_2 is 0');
pdf_slip_assert_true(strpos($html, '>0<') === false, 'T3 "0" never printed');

// T4 — placeholder primary, real secondary.
$html = MealsDB_Slip_PDF_Generator::driver_block_inner_html($base + ['phone' => '0', 'phone_secondary' => '506-858-1234']);
pdf_slip_assert_equal(1, $count_phone_lines($html), 'T4 one phone line when client_phone_1 is 0');
pdf_slip_assert_true(strpos($html, '506-858-1234') !== false, 'T4 the real client_phone_2 prints');

// Both placeholders → no phone lines at all.
$html = MealsDB_Slip_PDF_Generator::driver_block_inner_html($base + ['phone' => '-', 'phone_secondary' => '0']);
pdf_slip_assert_equal(0, $count_phone_lines($html), 'both placeholders → no phone line');

// T6 — real-but-unusual values keep printing, verbatim.
$multi = '506-204-7747 or 1-506-345-0237 (sister Diane)';
$local = '204-7570 (Joyce)';
$html = MealsDB_Slip_PDF_Generator::driver_block_inner_html($base + ['phone' => $local, 'phone_secondary' => $multi]);
pdf_slip_assert_equal(2, $count_phone_lines($html), 'T6 7-digit local + multi-number both print');
pdf_slip_assert_true(strpos($html, htmlspecialchars($local, ENT_QUOTES)) !== false, 'T6 7-digit local printed verbatim');
pdf_slip_assert_true(strpos($html, htmlspecialchars($multi, ENT_QUOTES)) !== false, 'T6 multi-number printed verbatim');

// AC — a placeholder contact phone: the name still prints, the `0` does not.
$html = MealsDB_Slip_PDF_Generator::driver_block_inner_html($base + ['phone' => '506-858-1234', 'contact_name' => 'Gail', 'contact_phone' => '0']);
pdf_slip_assert_true(strpos($html, '(Gail)') !== false, 'AC contact name still prints');
pdf_slip_assert_true(strpos($html, '(Gail) 0') === false, 'AC placeholder contact phone not printed');
$html = MealsDB_Slip_PDF_Generator::driver_block_inner_html($base + ['phone' => '506-858-1234', 'contact_phone' => '0']);
pdf_slip_assert_equal(1, $count_phone_lines($html), 'AC placeholder-only contact → no contact line');

// D1 — daily driver slip (render_driver_block_html) shows ONE number.
$gen = (new ReflectionClass('MealsDB_Slip_PDF_Generator'))->newInstanceWithoutConstructor();
$render = new ReflectionMethod('MealsDB_Slip_PDF_Generator', 'render_driver_block_html');
$render->setAccessible(true);
$driver = ['client_name' => 'X', 'street' => 'S', 'city' => 'C', 'breakdown' => [], 'collect_label' => 'Collect: $0.00'];
$daily = $render->invoke($gen, $driver + ['phone' => '0', 'phone_secondary' => '506-858-1234']);
pdf_slip_assert_true(strpos($daily, 'PH: 506-858-1234') !== false, 'D1 placeholder primary falls back to real secondary');
$daily = $render->invoke($gen, $driver + ['phone' => '0', 'phone_secondary' => '0']);
pdf_slip_assert_true(strpos($daily, 'PH: 0') === false, 'D1 placeholder never printed after PH:');
$daily = $render->invoke($gen, $driver + ['phone' => '506-743-2285', 'phone_secondary' => '506-858-1234']);
pdf_slip_assert_true(strpos($daily, 'PH: 506-743-2285') !== false, 'D1 real primary still wins');

// B1 — build_driver_block contact fallback skips a placeholder in _1.
$GLOBALS['_pdf_slip_options']['mealsdb_fee_product_ids'] = ['client_contribution' => 5675, 'delivery_fee' => 4122];
$GLOBALS['_pdf_slip_terms'] = [101 => [35]];
$wc_order = new WC_Order();
$wc_order->subtotal = 10.00; $wc_order->tax = 0.0; $wc_order->total = 10.00; $wc_order->payment_method = 'cash';
$GLOBALS['_pdf_slip_wc_orders'][100] = $wc_order;
$client = [
    'client_id' => 1, 'client_type' => 'Private', 'delivery_initials' => 'XYZ', 'delivery_area_name' => 'Zone 2',
    'first_name' => 'Wayne', 'last_name' => 'Zorn', 'delivery_street_name' => '173 Main St', 'delivery_city' => 'Shediac',
    'client_phone_1' => '506-743-2285', 'client_phone_2' => '0',
    'alternate_contact_name' => 'Gail', 'alternate_contact_phone_1' => '0', 'alternate_contact_phone_2' => '506-536-1126',
    'delivery_fee' => 0.0, 'payment_method' => 'cash',
];
$orders = [[
    'order_id' => 100, 'wp_user_id' => 1, 'date_created_gmt' => '2025-02-20 12:00:00',
    'items' => [['wc_product_id' => 101, 'quantity' => 1, 'order_item_name' => 'Meal']],
]];
$slips = pdf_slip_build_slips($orders, [1 => $client], true);
pdf_slip_assert_equal('506-536-1126', $slips[0]['driver']['contact_phone'] ?? null, 'B1 placeholder alternate_contact_phone_1 falls through to _2');
// Stored values are untouched: the driver payload still carries the raw '0';
// suppression happens only at render time.
pdf_slip_assert_equal('0', $slips[0]['driver']['phone_secondary'] ?? null, 'B1 client_phone_2 carried verbatim (render-time guard only)');

pdf_slip_finish();
