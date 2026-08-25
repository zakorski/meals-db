<?php
/**
 * DIRECTIVE delivery-date-next-week-rule ITEM 1: delivery defaults to the
 * client's delivery weekday in the calendar week FOLLOWING the order date.
 * Frequency is not used. Pure function of (order_date, delivery_day).
 *
 * Run: php tests/test-next-week-delivery-rule.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function nw_eq($label, $expected, $actual): void {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label,
        var_export($expected, true), var_export($actual, true));
}
$f = ['MealsDB_Date_Calculator', 'next_week_delivery_date'];

// Wednesday client, order placed Monday 2026-08-03 -> NEXT week's Wednesday (08-12), not this week's (08-05).
nw_eq('Wed client, Mon order -> next-week Wed', '2026-08-12', $f('2026-08-03', 'wednesday'));
// Wednesday client, order placed Thursday 2026-08-06 -> next week's Wednesday.
nw_eq('Wed client, Thu order -> next-week Wed', '2026-08-12', $f('2026-08-06', 'wednesday'));
// Zone 4 Tuesday client, order placed Friday 2026-08-07 -> next week's Tuesday (08-11).
nw_eq('Tue client, Fri order -> next-week Tue', '2026-08-11', $f('2026-08-07', 'tuesday'));
// Case-insensitive day input.
nw_eq('case-insensitive day', '2026-08-12', $f('2026-08-03', 'Wednesday'));
// Sunday order (week-boundary edge): 2026-08-09 is a Sunday; following week's Monday is 08-10.
nw_eq('Sun order, Mon client -> 08-10', '2026-08-10', $f('2026-08-09', 'monday'));
nw_eq('Sun order, Sun client -> 08-16', '2026-08-16', $f('2026-08-09', 'sunday'));
// Blank / unknown day -> null (blank means blank).
nw_eq('blank day -> null', null, $f('2026-08-03', ''));
nw_eq('unknown day -> null', null, $f('2026-08-03', 'someday'));
nw_eq('null day -> null', null, $f('2026-08-03', null));
// Malformed date -> null.
nw_eq('bad date -> null', null, $f('2026/08/03', 'wednesday'));

if ($failures) { echo implode("\n", $failures) . "\n"; echo "FAILED ({$passed} passed)\n"; exit(1); }
echo "OK ({$passed} passed)\n";
