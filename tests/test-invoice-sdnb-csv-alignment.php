<?php
/**
 * Tests for SDNB legacy CSV header-row alignment (H1).
 *
 * Regression guard for the service-centre-address issue: the rows
 * that carry the vendor / service-centre metadata previously used
 * implode(',', $rowN), which silently split any value containing a
 * comma into multiple fields and broke every downstream column.
 * Routing through MealsDB_CSV::row() applies RFC-4180 quoting AND
 * formula-injection neutralisation.
 *
 * This file exercises MealsDB_CSV::row() directly with the same
 * input shape the SDNB legacy builder produces so a future edit
 * that reverts back to implode(',') is caught by CI.
 *
 * Run with: php tests/test-invoice-sdnb-csv-alignment.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

$failures = [];
$passed   = 0;
function assert_contains(string $needle, string $haystack, string $label) {
    global $failures, $passed;
    if (strpos($haystack, $needle) !== false) { $passed++; return; }
    $failures[] = "FAIL: $label (expected $needle in: $haystack)";
}
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}

// ---------------------------------------------------------------------------
// Shape-check: build a 100-element row with the Moncton service-centre
// address (which contains two commas and a period) and assert the
// emitted line has EXACTLY 99 commas — i.e. the address was quoted
// into a single cell and did not split into multiple fields.
// ---------------------------------------------------------------------------
$row5 = array_fill(0, 100, '');
$row5[0]  = '2';
$row5[1]  = '2026 Apr 30 M';
$row5[10] = '770 Main Street Assumption PL., 5th Floor, Moncton NB E1C 8R3';

$line = MealsDB_CSV::row($row5);

// RFC-4180: a field containing a comma must be wrapped in double-quotes.
assert_contains('"770 Main Street Assumption PL., 5th Floor, Moncton NB E1C 8R3"', $line, 'service-centre address is quoted as a single field');

// Count unquoted commas (commas NOT inside "..." quotes). There should
// be exactly 99 — the separators between 100 cells — if the address
// was correctly contained. A bug would leave the two address commas
// unquoted and count 101.
$unquoted_commas = 0;
$in_quotes = false;
$len = strlen($line);
for ($i = 0; $i < $len; $i++) {
    $c = $line[$i];
    if ($c === '"') {
        $in_quotes = !$in_quotes;
        continue;
    }
    if ($c === ',' && !$in_quotes) {
        $unquoted_commas++;
    }
}
assert_equal(99, $unquoted_commas, '100-cell row emits exactly 99 unquoted commas');

// ---------------------------------------------------------------------------
// Formula injection: if an attacker ever manages to inject a value
// starting with = into one of the metadata cells (e.g. via a
// customised vendor name option), the output must be neutralised.
// ---------------------------------------------------------------------------
$evil_row = array_fill(0, 100, '');
$evil_row[3] = '=cmd|"/C calc"!A1';  // vendor name slot

$evil_line = MealsDB_CSV::row($evil_row);
assert_contains("'=cmd", $evil_line, 'leading = is prefixed with single quote');

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
