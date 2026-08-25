<?php
/**
 * Backfill Next Dates — next_delivery_date fallback
 * (DIRECTIVE-remaining-items-consolidated.md, ITEM 2).
 *
 * "Backfill Next Dates" used to populate next_order_date but ZERO
 * next_delivery_date, because run_phase_next_dates() only computed a
 * delivery date when a last_delivery_date usermeta existed — and that
 * usermeta is empty for clients with no delivery history, so the branch
 * never fired.
 *
 * resolve_backfill_delivery_date() is the extracted pure seam:
 *   - last_delivery_date present -> project via Date_Calculator::next_date
 *     (unchanged historic behaviour: full-cycle projection from history).
 *   - last_delivery_date absent   -> mirror the get_next_dates endpoint by
 *     delegating to resolve_delivery_prefill (stored day -> zone-schedule
 *     fallback -> slip-occurrence semantics), anchored on the order date.
 *   - nothing resolvable          -> null (leave the column as-is).
 *
 * Run: php tests/test-backfill-delivery-date-fallback.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// get_option stub: tests control the zone schedule via $GLOBALS['bdf_schedule'].
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        if ($key === MealsDB_Zone_Day::SCHEDULE_OPTION) {
            return $GLOBALS['bdf_schedule'] ?? $default;
        }
        return $default;
    }
}
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('add_action')) { function add_action(...$a) {} }

$failures = []; $passed = 0;
function bdf_check(string $label, $actual, $expected): void {
    global $failures, $passed;
    if ($actual === $expected) { $passed++; return; }
    $failures[] = sprintf("%s:\n  expected %s\n  got      %s", $label, var_export($expected, true), var_export($actual, true));
}

if (!method_exists('MealsDB_Migration_Consolidated', 'resolve_backfill_delivery_date')) {
    fwrite(STDERR, "FAIL: MealsDB_Migration_Consolidated::resolve_backfill_delivery_date does not exist\n");
    exit(1);
}

$GLOBALS['bdf_schedule'] = [
    'Zone 1' => ['day' => 'Thursday', 'label' => 'Thursday - Moncton Downtown'],
    'Zone 5' => ['day' => 'Friday',   'label' => 'Friday - Dieppe / Riverview'],
];

// Weekday facts: 2026-08-03 Mon, 2026-08-07 Fri, 2026-08-10 Mon, 2026-08-13 Thu.
// Under the next-week rule: following Monday from 2026-08-03 (Mon) is 2026-08-10 (+7);
// following Monday from 2026-08-07 (Fri) is also 2026-08-10 (+3). Thursday = Mon +3.

// 1. last_delivery_date present -> historic next_date projection wins,
//    independent of the zone/prefill path.
bdf_check(
    'last_delivery present projects via next_date',
    MealsDB_Migration_Consolidated::resolve_backfill_delivery_date(
        ['delivery_day' => 'thursday', 'delivery_frequency' => 1, 'next_delivery_date' => ''],
        '2026-08-06',   // last_delivery (Thu)
        '2026-08-03'    // anchor (ignored on this branch)
    ),
    '2026-08-13'
);

// 2. THE FIX: no last_delivery, blank stored day, zone gives Thursday.
//    DIRECTIVE delivery-date-next-week-rule: occurrence is always the client's
//    delivery weekday in the calendar week FOLLOWING the anchor date —
//    next Monday from 2026-08-03 (Mon) is 2026-08-10, Thursday (+3) = 2026-08-13.
//    (The old rule snapped to the same-week Thursday 2026-08-06; that was wrong.)
bdf_check(
    'no history falls back to zone + slip occurrence (following week)',
    MealsDB_Migration_Consolidated::resolve_backfill_delivery_date(
        ['delivery_day' => '', 'delivery_area_name' => 'Zone 1', 'delivery_frequency' => 1, 'next_delivery_date' => ''],
        null,           // no last_delivery usermeta
        '2026-08-03'    // Monday anchor
    ),
    '2026-08-13'
);

// 3. No history, anchor on a Friday — Thursday client.
//    Next Monday from 2026-08-07 (Fri, ISO=5) = +3 days = 2026-08-10;
//    Thursday (+3) = 2026-08-13. Same result as case 2 because the new rule
//    always targets the FOLLOWING week regardless of whether the delivery day
//    has "passed" the anchor within the current week.
bdf_check(
    'no history, Friday anchor, Thursday client -> following week Thursday',
    MealsDB_Migration_Consolidated::resolve_backfill_delivery_date(
        ['delivery_day' => 'thursday', 'delivery_area_name' => null, 'delivery_frequency' => 1, 'next_delivery_date' => ''],
        null,
        '2026-08-07'    // Friday anchor
    ),
    '2026-08-13'
);

// 4. No history and no resolvable day anywhere -> null (leave column as-is).
bdf_check(
    'no resolvable day -> null',
    MealsDB_Migration_Consolidated::resolve_backfill_delivery_date(
        ['delivery_day' => '', 'delivery_area_name' => 'Zone 99', 'delivery_frequency' => 1, 'next_delivery_date' => ''],
        null,
        '2026-08-03'
    ),
    null
);

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
