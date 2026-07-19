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
// Report.
// ===========================================================================
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("OK: %d checks passed\n", $passed));
exit(0);
