<?php
/**
 * DIRECTIVE delivery-date-next-week-rule ITEM 2: scorecard comparison core.
 * score_pairs(stored, actual) compares two [order_number => 'Y-m-d'] maps and
 * reports match rate + misses over the orders present in BOTH (the join).
 *
 * Run: php tests/test-delivery-date-scorecard.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

$failures = []; $passed = 0;
function sc_eq($label, $expected, $actual): void {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label,
        var_export($expected, true), var_export($actual, true));
}
$f = ['MealsDB_Delivery_Date_Scorecard', 'score_pairs'];

// 3 joined, 2 match, 1 miss.
$actual = [27454 => '2026-07-01', 100 => '2026-07-08', 101 => '2026-07-15'];
$stored = [27454 => '2026-06-27', 100 => '2026-07-08', 101 => '2026-07-15', 999 => '2026-07-22'];
$r = $f($stored, $actual);
sc_eq('total counts only joined orders', 3, $r['total']);
sc_eq('matched', 2, $r['matched']);
sc_eq('match_rate', 2 / 3, $r['match_rate']);
sc_eq('one miss listed', 1, count($r['misses']));
sc_eq('miss names order+both dates', ['order' => 27454, 'stored' => '2026-06-27', 'actual' => '2026-07-01'], $r['misses'][0]);

// An actual order with no stored date is a MISS (stored missing = not matched), still joined?
// Decision: orders in `actual` but absent from `stored` are counted in total and as a miss with stored=''.
$r2 = $f([], [50 => '2026-07-01']);
sc_eq('absent stored -> total 1', 1, $r2['total']);
sc_eq('absent stored -> matched 0', 0, $r2['matched']);
sc_eq('absent stored -> miss stored empty', ['order' => 50, 'stored' => '', 'actual' => '2026-07-01'], $r2['misses'][0]);

// Empty ground truth -> zero, rate 0.0 (no division by zero).
$r3 = $f(['x' => '2026-07-01'], []);
sc_eq('empty actual -> total 0', 0, $r3['total']);
sc_eq('empty actual -> rate 0.0', 0.0, $r3['match_rate']);

if ($failures) { echo implode("\n", $failures) . "\n"; echo "FAILED ({$passed} passed)\n"; exit(1); }
echo "OK ({$passed} passed)\n";
