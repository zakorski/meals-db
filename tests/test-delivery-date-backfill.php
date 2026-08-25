<?php
/**
 * DIRECTIVE delivery-date-next-week-rule ITEM 2: per-order backfill decision.
 * decide_backfill_write() returns the action for one order given its current
 * _delivery_date, provenance marker, order date, and resolved delivery_day.
 *
 * Run: php tests/test-delivery-date-backfill.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

$failures = []; $passed = 0;
function bf_eq($label, $expected, $actual): void {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label,
        var_export($expected, true), var_export($actual, true));
}
$d = ['MealsDB_Migration_Consolidated', 'decide_backfill_write'];

// (order_date, delivery_day, existing _delivery_date, src marker) => [action, value]
bf_eq('no existing -> write auto', ['write', '2026-08-12'],
    $d('2026-08-03', 'wednesday', '', ''));
bf_eq('auto existing -> overwrite', ['write', '2026-08-12'],
    $d('2026-08-03', 'wednesday', '2026-08-05', 'auto'));
bf_eq('human, no marker -> skip', ['skip_human', '2026-07-01'],
    $d('2026-08-03', 'wednesday', '2026-07-01', ''));
bf_eq('manual marker -> skip', ['skip_human', '2026-07-01'],
    $d('2026-08-03', 'wednesday', '2026-07-01', 'manual'));
bf_eq('no day -> blank', ['blank', null],
    $d('2026-08-03', '', '', ''));
bf_eq('auto already correct', ['write', '2026-08-12'],
    $d('2026-08-03', 'wednesday', '2026-08-12', 'auto'));

if ($failures) { echo implode("\n", $failures) . "\n"; echo "FAILED ({$passed} passed)\n"; exit(1); }
echo "OK ({$passed} passed)\n";
