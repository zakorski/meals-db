<?php
/**
 * Tests for Doc 2 row chunking (packing-slip pagination follow-up).
 * dompdf-FREE: pins the pure chunk math and the chunked HTML the
 * renderers emit; the PDF itself is verified on the live host.
 *
 *   NL-*   doc2_notes_lines — conservative wrapped-line estimate
 *   CH-*   doc2_chunk_sizes — single page whenever content clears the
 *          0.5in print margin (chunking must never touch a fitting slip);
 *          greedy full pages + tail-reserved last page otherwise
 *   HTML-* chunked combined document: page counts, continued markers,
 *          totals/notes on last chunk only, divider on first chunk only,
 *          continuous global numbering
 *   D4-*   doc-4 blank padding keeps the physical overlay page-aligned
 *
 * Run: php tests/test-slip-doc2-chunking.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

$GLOBALS['TEST_OPTIONS'] = [];
if (!function_exists('get_option')) {
    function get_option($name, $default = false) { return $GLOBALS['TEST_OPTIONS'][$name] ?? $default; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s",
        $label, var_export($exp, true), var_export($got, true));
}
function chk_true($c, $l) { chk((bool) $c, true, $l); }
function chk_contains($hay, $needle, $l) { chk_true(strpos($hay, $needle) !== false, $l . " (contains '$needle')"); }

// Reach private instance methods without wiring the constructor deps.
$ref = new ReflectionClass('MealsDB_Slip_PDF_Generator');
$gen = $ref->newInstanceWithoutConstructor();
function call_priv($gen, $method, ...$args) {
    $m = new ReflectionMethod('MealsDB_Slip_PDF_Generator', $method);
    $m->setAccessible(true);
    return $m->invoke($gen, ...$args);
}

// ===========================================================================
// NL — notes line estimate.
// ===========================================================================
chk(MealsDB_Slip_PDF_Generator::doc2_notes_lines(''), 0, 'NL-1 empty => 0');
chk(MealsDB_Slip_PDF_Generator::doc2_notes_lines('   '), 0, 'NL-2 whitespace => 0');
chk(MealsDB_Slip_PDF_Generator::doc2_notes_lines('short note'), 1, 'NL-3 one short line');
chk(MealsDB_Slip_PDF_Generator::doc2_notes_lines("a\nb"), 2, 'NL-4 explicit newlines counted');
chk(MealsDB_Slip_PDF_Generator::doc2_notes_lines(str_repeat('x', 250)), 3, 'NL-5 250 chars wraps to 3 est. lines');

// ===========================================================================
// CH — chunk sizes. Geometry: capacity 6.74in, row 0.23in, totals 0.31in,
// notes 0.10in + 0.18in/line. Derived: 26 single-page rows (no notes),
// 28-row full pages, 26-row last page.
// ===========================================================================
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(0, 0), [0], 'CH-1 empty order => single page');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(12, 0), [12], 'CH-2 typical order => single page');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(26, 0), [26], 'CH-3 boundary: 26 rows still clears the margin');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(27, 0), [26, 1], 'CH-4 one row over => chunk engages');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(60, 0), [28, 28, 4], 'CH-5 three pages, full 28-row middles');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(24, 3), [24], 'CH-6 notes shrink the single-page budget: 24+3 lines fits');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(25, 3), [24, 1], 'CH-7 notes push one row over');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(5, 40), [5, 0], 'CH-8 degenerate giant notes => tail-only last page');

// ===========================================================================
// HTML — chunked combined document. 30 single-line items, no notes
// => sizes [28, 2] => 2 doc2 pages; with the cover, y = 3.
// ===========================================================================
$mk_item = static function (int $i): array {
    return ['sku' => 'SKU' . $i, 'qty' => 1, 'product_name' => 'Product ' . $i, 'category' => 'Main'];
};
$big_slip = [
    'initials' => 'AAA', 'zone' => 'Zone 1', 'order_number' => '#900',
    'delivery_date' => 'July 19, 2026',
    'items' => array_map($mk_item, range(1, 30)),
    'total_items' => 30, 'total_mains' => 30, 'total_sides' => 0,
    'additional_notes' => 'Ring the back doorbell',
];
$chunk_batch = [
    'order_count' => 1,
    'orders'      => [['initials' => 'AAA', 'take_from_hold' => false]],
    'created_at'  => '2026-07-19 12:00:00',
];
$html = call_priv($gen, 'render_packing_slips_combined_html', 'Moncton Downtown', '2026-07-19', $chunk_batch, [$big_slip]);

chk(substr_count($html, '<div class="doc2-page'), 2, 'HTML-1 one 30-item order => two packer pages');
chk_contains($html, 'Page 1 of 3', 'HTML-2 cover counts chunk pages');
chk_contains($html, 'Page 2 of 3', 'HTML-3 first chunk page number');
chk_contains($html, 'Page 3 of 3', 'HTML-4 second chunk page number');
chk(substr_count($html, '(continued)'), 1, 'HTML-5 exactly one continued marker');
chk(substr_count($html, 'Total Items: 30'), 1, 'HTML-6 totals once, on the last chunk only');
chk(substr_count($html, 'Ring the back doorbell'), 1, 'HTML-7 notes once, on the last chunk only');
chk(substr_count($html, '<div class="d2-divider">'), 1, 'HTML-8 divider on the FIRST chunk page only (doc-4 overlay target)');
// Row split 28 + 2: the second doc2 page carries exactly 2 item rows.
$second_page = substr($html, strrpos($html, '<div class="doc2-page'));
chk(substr_count($second_page, '<td class="d2-sku">'), 2, 'HTML-9 second page has the remaining 2 rows');
chk_contains($second_page, 'Product 30', 'HTML-10 last item lands on the last page');
// Order position is repeated on every chunk page.
chk(substr_count($html, 'Order 1 of 1'), 2, 'HTML-11 position line on both chunk pages');

// A small slip must render EXACTLY one page with no markers (no chunking).
$small = ['items' => array_map($mk_item, range(1, 3)), 'total_items' => 3] + $big_slip;
$html2 = call_priv($gen, 'render_packing_slips_combined_html', 'Moncton Downtown', '2026-07-19', $chunk_batch, [$small]);
chk(substr_count($html2, '<div class="doc2-page'), 1, 'HTML-12 fitting slip => single page');
chk(substr_count($html2, '(continued)'), 0, 'HTML-13 fitting slip => no continued marker');
chk_contains($html2, 'Page 2 of 2', 'HTML-14 fitting slip numbering unchanged');

// ===========================================================================
// D4 — blank padding keeps the overlay page-aligned. Counts [2, 1]:
// order 1 spans two doc-2 pages, so doc 4 emits block, blank, block.
// ===========================================================================
$d4_orders = [
    ['client_name' => 'First Client', 'street' => '1 First St'],
    ['client_name' => 'Second Client', 'street' => '2 Second St'],
];
$d4 = call_priv($gen, 'render_doc4_html', $d4_orders, [2, 1]);
chk(substr_count($d4, '<div class="doc4-page'), 3, 'D4-1 three pages for counts [2,1]');
chk(substr_count($d4, '<div class="d4-block">'), 2, 'D4-2 exactly two driver blocks');
chk_true(strpos($d4, 'First Client') < strpos($d4, 'Second Client'), 'D4-3 block order preserved');
// The middle page is the blank spacer: split on pages, page 2 has no block.
$pages = explode('<div class="doc4-page', $d4);
chk_true(strpos($pages[2], 'd4-block') === false, 'D4-4 continuation spacer page is empty');
// Last page carries no trailing break.
chk_true(strpos($pages[3], 'd4-break') === false, 'D4-5 no trailing break after last page');
// Default counts (all 1) preserve today's one-page-per-order output.
$d4_plain = call_priv($gen, 'render_doc4_html', $d4_orders, []);
chk(substr_count($d4_plain, '<div class="doc4-page'), 2, 'D4-6 no counts => unchanged one page per order');

// ===========================================================================
// Report.
// ===========================================================================
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("OK: %d checks passed\n", $passed));
exit(0);
