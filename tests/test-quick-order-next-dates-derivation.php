<?php
/**
 * Quick Order get_next_dates reliable derivation
 * (DIRECTIVE-quick-order-prefill-warning-fix.md, A2/B7).
 *
 * The QO delivery-date prefill (A2) and day-mismatch warning (B7) both
 * consume the get_next_dates response, which used to echo the STORED
 * delivery_day / next_delivery_date columns verbatim — blank columns
 * (a not-yet-resynced client) starved the already-correct JS of data.
 * resolve_delivery_prefill() is the extracted pure derivation:
 *
 *   delivery_day        stored column first (lowercased), zone-schedule
 *                       fallback when blank — the SAME precedence as
 *                       expected_day_for_wp_user() / the order-edit
 *                       screen. Zone-first would make QO and order-edit
 *                       disagree on a stale stored day.
 *   next_delivery_date  stored column first; when blank, computed with
 *                       the SLIP occurrence semantics
 *                       (delivery_occurrence_for_order: same-week snap,
 *                       roll a cycle only if the weekday passed) — NOT
 *                       next_date(), which always projects a full cycle
 *                       and would prefill one week late whenever the
 *                       delivery day is still upcoming. The prefill is
 *                       written as the _delivery_date override, so a
 *                       late prefill would actively delay deliveries.
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

// Weekday facts used below: 2026-08-03 Mon, 2026-08-06 Thu, 2026-08-07 Fri,
// 2026-08-13 Thu (next week), 2026-08-20 Thu (week after).

// 1. Stored columns present -> echoed (day lowercased), no derivation.
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => 'Monday',
    'delivery_area_name' => 'Zone 1', // zone says thursday — stored must win
    'next_delivery_date' => '2026-08-10',
    'delivery_frequency' => 1,
], '2026-08-03');
qond_check('stored day preferred over zone', $r['delivery_day'], 'monday');
qond_check('stored date echoed', $r['next_delivery_date'], '2026-08-10');

// 2. Stored day blank, zone configured -> zone-derived day (the B7 case).
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => '',
    'delivery_area_name' => 'Zone 1',
    'next_delivery_date' => '2026-08-10',
    'delivery_frequency' => 1,
], '2026-08-03');
qond_check('blank stored day falls back to zone', $r['delivery_day'], 'thursday');

// 3. Stored date blank, day upcoming THIS week -> SAME week's occurrence
//    (slip parity — the amendment's next_date() trap: Mon anchor, Thu day
//    must give this Thursday, not next week's).
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => '',
    'delivery_area_name' => 'Zone 1', // thursday
    'next_delivery_date' => '',
    'delivery_frequency' => 1,
], '2026-08-03'); // Monday
qond_check('computed date lands in the current week', $r['next_delivery_date'], '2026-08-06');

// 4. Stored date blank, day already passed this week -> next cycle.
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => 'thursday',
    'delivery_area_name' => null,
    'next_delivery_date' => '',
    'delivery_frequency' => 1,
], '2026-08-07'); // Friday — Thursday has passed
qond_check('passed weekday rolls one cycle (weekly)', $r['next_delivery_date'], '2026-08-13');

// 5. Same, biweekly -> rolls two weeks.
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => 'thursday',
    'delivery_area_name' => null,
    'next_delivery_date' => '',
    'delivery_frequency' => 2,
], '2026-08-07');
qond_check('passed weekday rolls one cycle (biweekly)', $r['next_delivery_date'], '2026-08-20');

// 6. Zero/missing frequency -> weekly assumption (deliberate parity with
//    delivery_occurrence_for_order, which defaults <=0 to 1).
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => 'thursday',
    'delivery_area_name' => null,
    'next_delivery_date' => '',
    'delivery_frequency' => 0,
], '2026-08-07');
qond_check('zero frequency computes as weekly', $r['next_delivery_date'], '2026-08-13');

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
$r = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill([
    'delivery_day'       => '   ',
    'delivery_area_name' => 'Zone 5', // friday
    'next_delivery_date' => null,
    'delivery_frequency' => 1,
], '2026-08-03'); // Monday
qond_check('whitespace stored day falls back to zone', $r['delivery_day'], 'friday');
qond_check('null stored date computes', $r['next_delivery_date'], '2026-08-07');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
