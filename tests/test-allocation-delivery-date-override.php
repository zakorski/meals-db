<?php
/**
 * Tests for MealsDB_Allocation_Rebuilder::resolve_delivery_date() — the
 * override-aware delivery-date resolver at the heart of the 2026-08 fix
 * (audit B04: the rebuilder billed overridden deliveries to the wrong
 * month because it resolved the delivery date solely from the computed
 * schedule and never read the operator's _delivery_date override).
 *
 * This is the sibling of the slip generator's occurrence/override rule
 * (PR #479): a well-formed _delivery_date (Y-m-d) is AUTHORITATIVE and
 * decides which billing month the order's meals land in. Without an
 * override the order maps to the client's computed delivery schedule.
 *
 * Pure function — no DB — so it is unit-tested directly.
 *
 * Run: php tests/test-allocation-delivery-date-override.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function eq($actual, $expected, string $label): void {
    global $failures, $passed;
    if ($actual === $expected) { $passed++; return; }
    $failures[] = sprintf("%s\n  expected: %s\n  actual:   %s",
        $label, var_export($expected, true), var_export($actual, true));
}

$R = 'MealsDB_Allocation_Rebuilder';

// A two-delivery schedule where coverage_end differs from delivery_date, so
// we can prove the resolver carries the schedule's coverage_end through.
$schedule = [
    ['delivery_date' => '2026-06-04', 'coverage_start' => '2026-05-29', 'coverage_end' => '2026-06-07'],
    ['delivery_date' => '2026-06-11', 'coverage_start' => '2026-06-08', 'coverage_end' => '2026-06-14'],
];

// --- Override wins outright (the fix) -------------------------------------
// A well-formed override is authoritative: schedule + order date ignored,
// coverage collapses to the override day. This is what makes allocation agree
// with slip selection.
eq($R::resolve_delivery_date('2026-06-04', $schedule, '2026-07-15'),
   ['2026-07-15', '2026-07-15'], 'override wins over an exact schedule match');
eq($R::resolve_delivery_date('2026-06-06', $schedule, '2026-07-15'),
   ['2026-07-15', '2026-07-15'], 'override wins over a coverage-window match');

// Month-crossing override — the core defect: order created in June, delivery
// overridden into July, must bill to 2026-07 not 2026-06.
$res = $R::resolve_delivery_date('2026-06-30', $schedule, '2026-07-02');
eq($res, ['2026-07-02', '2026-07-02'], 'month-crossing override resolves to override date');
eq(substr($res[0], 0, 7), '2026-07', 'month-crossing override bills to July');

// --- Malformed / absent override falls back to the schedule ---------------
eq($R::resolve_delivery_date('2026-06-04', $schedule, ''),
   ['2026-06-04', '2026-06-07'], 'no override: exact date match carries coverage_end');
eq($R::resolve_delivery_date('2026-06-10', $schedule, ''),
   ['2026-06-11', '2026-06-14'], 'no override: coverage-window match');
eq($R::resolve_delivery_date('2026-06-20', $schedule, ''),
   ['2026-06-20', '2026-06-20'], 'no override, no schedule match: falls back to order date');

// Malformed overrides are treated as "no override" (mirrors slip selection's
// /^\d{4}-\d{2}-\d{2}$/ guard) — must NOT be used as a delivery date.
eq($R::resolve_delivery_date('2026-06-04', $schedule, 'garbage'),
   ['2026-06-04', '2026-06-07'], 'malformed override ignored -> schedule');
eq($R::resolve_delivery_date('2026-06-04', $schedule, '2026-6-4'),
   ['2026-06-04', '2026-06-07'], 'non-zero-padded override ignored -> schedule');
eq($R::resolve_delivery_date('2026-06-04', $schedule, '2026-07-15 00:00:00'),
   ['2026-06-04', '2026-06-07'], 'override with time component ignored -> schedule');

// --- Empty schedule + no override: order date is the delivery date --------
eq($R::resolve_delivery_date('2026-06-20', [], ''),
   ['2026-06-20', '2026-06-20'], 'empty schedule, no override: order date');
// Empty schedule + override: override still wins.
eq($R::resolve_delivery_date('2026-06-20', [], '2026-08-01'),
   ['2026-08-01', '2026-08-01'], 'empty schedule, override still wins');

$total = $passed + count($failures);
echo "Ran {$total} checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
