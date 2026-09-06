<?php
/**
 * DIRECTIVE (2026-09-05) — VAC sides split proportionally across billing months.
 *
 * MealsDB_Allocation_Rebuilder::split_sides_proportional($sides, $m_earliest,
 * $m_later) apportions a side count in the same proportion as the delivery's
 * mains, remainder to the EARLIEST month, applied to taxable and non-taxable
 * independently. Fixtures are the directive's worked examples.
 *
 * Run: php tests/test-vac-sides-proportional-split.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
$split = ['MealsDB_Allocation_Rebuilder', 'split_sides_proportional'];

// Directive worked examples.
chk($split(16, 56, 56), [8, 8],  'order 28261: 16 sides, 56/56 mains → 8/8');
chk($split(7, 31, 30),  [4, 3],  'remainder: 7 sides, 31/30 mains → 4/3 (earliest gets +1)');

// Single-month delivery — all sides stay in the delivery month.
chk($split(10, 30, 0),  [10, 0], 'single month (m_later 0) → all sides earliest');
// All mains landed in the later month → sides follow.
chk($split(10, 0, 30),  [0, 10], 'all mains later → all sides later');

// Totals are always preserved (redistribute, never add/drop).
foreach ([[9,31,30],[13,20,41],[1,1,1],[100,7,93]] as $c) {
    [$s, $ma, $mb] = $c;
    [$a, $b] = $split($s, $ma, $mb);
    chk($a + $b, $s, "total preserved for split($s,$ma,$mb)");
    chk($a >= 0 && $b >= 0, true, "non-negative shares for split($s,$ma,$mb)");
}

// Zero sides → nothing to split. No mains at all → all to earliest (not dropped).
chk($split(0, 10, 10), [0, 0], 'zero sides → [0,0]');
chk($split(5, 0, 0),   [5, 0], 'no mains placed → all sides to earliest (not dropped)');

// The remainder always favours the EARLIEST month (never the later one).
[$e, $l] = $split(5, 1, 1); // 2.5 / 2.5 → floor 2/2, remainder 1 → earliest
chk([$e, $l], [3, 2], 'remainder favours earliest: split(5,1,1) → [3,2]');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
