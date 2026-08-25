<?php
/**
 * Quick Order get_next_dates reliable derivation
 * (DIRECTIVE-quick-order-prefill-warning-fix.md, A2/B7;
 *  updated: DIRECTIVE delivery-date-next-week-rule, Task 3).
 *
 * The QO delivery-date prefill (A2) and day-mismatch warning (B7) both
 * consume the get_next_dates response. resolve_delivery_prefill() is the
 * extracted pure derivation:
 *
 *   delivery_day        stored column first (lowercased), zone-schedule
 *                       fallback when blank — the SAME precedence as
 *                       expected_day_for_wp_user() / the order-edit
 *                       screen. Zone-first would make QO and order-edit
 *                       disagree on a stale stored day.
 *
 *   next_delivery_date  ALWAYS computed via the shared
 *                       delivery_occurrence_for_order() resolver
 *                       (DIRECTIVE delivery-date-next-week-rule). The
 *                       stored next_delivery_date column is intentionally
 *                       ignored — it is NULL for all zoned clients and is
 *                       a live trap. Frequency is also not read. The
 *                       result is always the delivery weekday in the
 *                       calendar week FOLLOWING the order date
 *                       (MealsDB_Date_Calculator::next_week_delivery_date).
 *                       A blank/unresolvable day yields a null prefill.
 *
 * Run: php tests/test-quick-order-next-dates-derivation.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// get_option stub: tests control the zone schedule via $GLOBALS['qond_schedule'].
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        if ($key === MealsDB_Zone_Day::SCHEDULE_OPTION) {
            return $GLOBALS['qond_schedule'] ?? $default;
        }
        return $default;
    }
}
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('add_action')) { function add_action(...$a) {} }

$failures = []; $passed = 0;
function qond_check(string $label, $actual, $expected): void {
    global $failures, $passed;
    if ($actual === $expected) { $passed++; return; }
    $failures[] = sprintf("%s:\n  expected %s\n  got      %s", $label, var_export($expected, true), var_export($actual, true));
}

if (!method_exists('MealsDB_Quick_Order_Ajax', 'resolve_delivery_prefill')) {
    fwrite(STDERR, "FAIL: MealsDB_Quick_Order_Ajax::resolve_delivery_prefill does not exist\n");
    exit(1);
}

$GLOBALS['qond_schedule'] = [
    'Zone 1' => ['day' => 'Thursday', 'label' => 'Thursday - Moncton Downtown'],
    'Zone 5' => ['day' => 'Friday',   'label' => 'Friday - Dieppe / Riverview'],
];

// Weekday facts used below:
//   2026-08-03 Mon, 2026-08-07 Fri, 2026-08-06 Thu, 2026-08-13 Thu,
//   2026-08-10 Mon (following week from Aug 3), 2026-08-14 Fri (following week from Aug 3/7).
//
// Following-week rule: next_week_delivery_date(order_date, day) always lands
// in the ISO calendar week AFTER the order date, regardless of whether the
// delivery weekday has already passed.
//   order=Mon Aug 3, day=monday   -> following Mon = Aug 10
//   order=Mon Aug 3, day=thursday -> following Thu = Aug 13
//   order=Fri Aug 7, day=thursday -> following Thu = Aug 13
//   order=Mon Aug 3, day=friday   -> following Fri = Aug 14

// 1. Stored day preferred over zone; stored next_delivery_date is now IGNORED —
//    the computed date (following-week rule, resolved day = monday) is returned.
//    order=2026-08-03(Mon), resolved_day=monday -> following Mon = 2026-08-10.
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => 'Monday',
    'delivery_area_name' => 'Zone 1', // zone says thursday — stored must win
    'next_delivery_date' => '2026-08-10', // stored value; ignored under new rule
    'delivery_frequency' => 1,
], '2026-08-03');
qond_check('stored day preferred over zone', $r['delivery_day'], 'monday');
// Coincidentally matches the old expectation because following-monday from a
// Monday order = next Monday = Aug 10, which happened to equal the stale stored
// value in this case. Verify we are computing, not echoing: if delivery_day were
// 'thursday' the result would differ from the stored '2026-08-10'.
qond_check('stored date ignored; computed (following-week monday from Aug 3)', $r['next_delivery_date'], '2026-08-10');

// 2. Stored day blank, zone configured -> zone-derived day (the B7 case).
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => '',
    'delivery_area_name' => 'Zone 1',
    'next_delivery_date' => '2026-08-10',
    'delivery_frequency' => 1,
], '2026-08-03');
qond_check('blank stored day falls back to zone', $r['delivery_day'], 'thursday');

// 3. Stored date blank, stored day blank, zone=thursday.
//    OLD: snap to same-week Thursday (Aug 6) when delivery day is upcoming.
//    NEW: always the following-week Thursday = 2026-08-13.
//    order=2026-08-03(Mon), resolved_day=thursday -> following Thu = 2026-08-13.
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => '',
    'delivery_area_name' => 'Zone 1', // thursday
    'next_delivery_date' => '',
    'delivery_frequency' => 1,
], '2026-08-03'); // Monday
qond_check('computed date lands in following week (not same week)', $r['next_delivery_date'], '2026-08-13');

// 4. Stored date blank, day stored as thursday, order on Friday (day has passed).
//    OLD: roll one weekly cycle -> next Thursday = Aug 13.
//    NEW: following-week Thursday from a Friday = Aug 13 (same result here).
//    order=2026-08-07(Fri), resolved_day=thursday -> following Thu = 2026-08-13.
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => 'thursday',
    'delivery_area_name' => null,
    'next_delivery_date' => '',
    'delivery_frequency' => 1,
], '2026-08-07'); // Friday — Thursday has passed
qond_check('passed weekday: following-week Thursday from Friday order', $r['next_delivery_date'], '2026-08-13');

// 5. Biweekly frequency: OLD rule rolled TWO weeks -> Aug 20.
//    NEW rule ignores frequency — always one following week -> Aug 13.
//    order=2026-08-07(Fri), resolved_day=thursday, freq=2 (ignored) -> 2026-08-13.
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => 'thursday',
    'delivery_area_name' => null,
    'next_delivery_date' => '',
    'delivery_frequency' => 2,
], '2026-08-07');
qond_check('biweekly frequency ignored; following-week rule applies', $r['next_delivery_date'], '2026-08-13');

// 6. Zero/missing frequency: still computes one following week (frequency not read).
//    order=2026-08-07(Fri), resolved_day=thursday, freq=0 -> 2026-08-13.
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => 'thursday',
    'delivery_area_name' => null,
    'next_delivery_date' => '',
    'delivery_frequency' => 0,
], '2026-08-07');
qond_check('zero frequency ignored; following-week rule applies', $r['next_delivery_date'], '2026-08-13');

// 7. No day anywhere -> both null (field stays blank, slip pipeline
//    falls back to its computed occurrence — existing behaviour).
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => '',
    'delivery_area_name' => 'Zone 99', // not in schedule
    'next_delivery_date' => '',
    'delivery_frequency' => 1,
], '2026-08-03');
qond_check('no resolvable day -> delivery_day null', $r['delivery_day'], null);
qond_check('no resolvable day -> next_delivery_date null', $r['next_delivery_date'], null);

// 8. Whitespace-only stored day is blank; stored date null-vs-'' both blank.
//    zone=Zone5(friday), order=2026-08-03(Mon).
//    OLD: same-week Friday = Aug 7 (still upcoming from Monday).
//    NEW: following-week Friday = 2026-08-14.
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => '   ',
    'delivery_area_name' => 'Zone 5', // friday
    'next_delivery_date' => null,
    'delivery_frequency' => 1,
], '2026-08-03'); // Monday
qond_check('whitespace stored day falls back to zone', $r['delivery_day'], 'friday');
qond_check('null stored date ignored; computed following-week friday', $r['next_delivery_date'], '2026-08-14');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
