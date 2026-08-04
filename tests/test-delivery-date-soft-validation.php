<?php
/**
 * Tests for MealsDB_Delivery_Date_Advisor — the shared soft-validation
 * helper for the manual delivery-date override
 * (directives/DIRECTIVE-manual-delivery-date-override.md, Section C).
 *
 * Soft-warn semantics (operator decision): a past date or a date that is
 * not the client's delivery day yields a WARNING STRING but never blocks
 * the save. The valid-day set resolves from the client's own delivery_day
 * (zone-derived via MealsDB_Zone_Day); when unknown, fall back to a plain
 * Mon–Fri weekday check.
 *
 * Run: php tests/test-delivery-date-soft-validation.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

$failures = []; $passed = 0;
function ddv_check(string $label, $actual, $expected): void {
    global $failures, $passed;
    if ($actual === $expected) { $passed++; return; }
    $failures[] = sprintf("%s:\n  expected %s\n  got      %s", $label, var_export($expected, true), var_export($actual, true));
}
function ddv_check_contains(string $label, string $haystack, string $needle): void {
    global $failures, $passed;
    if (stripos($haystack, $needle) !== false) { $passed++; return; }
    $failures[] = sprintf("%s: expected to contain %s, got %s", $label, var_export($needle, true), var_export($haystack, true));
}

if (!class_exists('MealsDB_Delivery_Date_Advisor')) {
    fwrite(STDERR, "FAIL: class MealsDB_Delivery_Date_Advisor does not exist\n");
    exit(1);
}

// Calendar anchors (2026): 07-20 Mon, 07-22 Wed, 07-23 Thu, 07-25 Sat,
// 07-26 Sun, 07-27 Mon. "Today" is injected as 2026-07-22 throughout so
// past/future is deterministic.
$today = '2026-07-22';

// -----------------------------------------------------------------------
// sanitize_ymd(): only well-formed real calendar dates survive.
// -----------------------------------------------------------------------
ddv_check('sanitize: valid date passes', MealsDB_Delivery_Date_Advisor::sanitize_ymd('2026-07-23'), '2026-07-23');
ddv_check('sanitize: blank -> empty', MealsDB_Delivery_Date_Advisor::sanitize_ymd(''), '');
ddv_check('sanitize: null -> empty', MealsDB_Delivery_Date_Advisor::sanitize_ymd(null), '');
ddv_check('sanitize: garbage -> empty', MealsDB_Delivery_Date_Advisor::sanitize_ymd('not-a-date'), '');
ddv_check('sanitize: wrong shape -> empty', MealsDB_Delivery_Date_Advisor::sanitize_ymd('23/07/2026'), '');
ddv_check('sanitize: impossible date -> empty', MealsDB_Delivery_Date_Advisor::sanitize_ymd('2026-02-30'), '');
ddv_check('sanitize: datetime trimmed rejected', MealsDB_Delivery_Date_Advisor::sanitize_ymd('2026-07-23 10:00:00'), '');

// -----------------------------------------------------------------------
// warning_for(), Mon–Fri fallback mode (no expected day known).
// -----------------------------------------------------------------------
ddv_check('fallback: future weekday is clean', MealsDB_Delivery_Date_Advisor::warning_for('2026-07-23', null, $today), '');
ddv_check('fallback: today itself is clean', MealsDB_Delivery_Date_Advisor::warning_for('2026-07-22', null, $today), '');
$sat = MealsDB_Delivery_Date_Advisor::warning_for('2026-07-25', null, $today);
ddv_check_contains('fallback: Saturday warns', $sat, 'Saturday');
$sun = MealsDB_Delivery_Date_Advisor::warning_for('2026-07-26', null, $today);
ddv_check_contains('fallback: Sunday warns', $sun, 'Sunday');
$past = MealsDB_Delivery_Date_Advisor::warning_for('2026-07-20', null, $today);
ddv_check_contains('fallback: past date warns', $past, 'past');
ddv_check('invalid ymd -> no warning (upstream rejects it)', MealsDB_Delivery_Date_Advisor::warning_for('nope', null, $today), '');

// -----------------------------------------------------------------------
// warning_for(), client-day mode: the client's delivery day is the valid
// set; any other weekday warns (including a weekday the fallback would
// have passed).
// -----------------------------------------------------------------------
ddv_check('client day: matching weekday is clean', MealsDB_Delivery_Date_Advisor::warning_for('2026-07-22', 'wednesday', $today), '');
$thu = MealsDB_Delivery_Date_Advisor::warning_for('2026-07-23', 'wednesday', $today);
ddv_check_contains('client day: Thursday vs Wednesday client warns', $thu, 'Thursday');
ddv_check_contains('client day: warning names the client day', $thu, 'Wednesday');
// Stored-case tolerance: schedule day may arrive un-lowercased.
ddv_check('client day: case-insensitive match', MealsDB_Delivery_Date_Advisor::warning_for('2026-07-22', 'Wednesday', $today), '');
// Past + wrong day: both problems surface in one string.
$both = MealsDB_Delivery_Date_Advisor::warning_for('2026-07-20', 'wednesday', $today);
ddv_check_contains('client day: past+wrong-day mentions past', $both, 'past');
ddv_check_contains('client day: past+wrong-day mentions weekday', $both, 'Monday');
// Warnings never block: they are strings, and a warned date still
// sanitizes cleanly (the two concerns are independent).
ddv_check('warned date still sanitizes', MealsDB_Delivery_Date_Advisor::sanitize_ymd('2026-07-25'), '2026-07-25');

// -----------------------------------------------------------------------
// expected_day_for_wp_user(): reads the client's canonical delivery_day
// (zone-synced column); falls back to the zone lookup when the column is
// blank; null when no active client row.
// -----------------------------------------------------------------------
if (!class_exists('MealsDB_DB', false)) {
    class MealsDB_DB { public static function get_table_name(string $t): string { return 'wp_' . $t; } }
}
if (!class_exists('MealsDB_Tables', false)) {
    class MealsDB_Tables { const CLIENTS = 'meals_clients'; }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        if ($key === 'mealsdb_zone_delivery_schedule') { return $GLOBALS['ddv_schedule'] ?? $default; }
        return $default;
    }
}
if (!class_exists('wpdb')) {
    class wpdb {
        public $row = null;
        public array $_last_args = [];
        public function prepare($q, ...$args) { $this->_last_args = $args; return $q; }
        public function get_row($q, $output = ARRAY_A) { return $this->row; }
    }
}
global $wpdb;
$wpdb = new wpdb();

$wpdb->row = ['delivery_day' => 'thursday', 'delivery_area_name' => 'Zone 2'];
ddv_check('expected day: from delivery_day column', MealsDB_Delivery_Date_Advisor::expected_day_for_wp_user(42), 'thursday');

$GLOBALS['ddv_schedule'] = ['Zone 2' => ['day' => 'Friday', 'label' => '']];
$wpdb->row = ['delivery_day' => '', 'delivery_area_name' => 'Zone 2'];
ddv_check('expected day: blank column falls back to zone', MealsDB_Delivery_Date_Advisor::expected_day_for_wp_user(42), 'friday');

$wpdb->row = null;
ddv_check('expected day: no client row -> null', MealsDB_Delivery_Date_Advisor::expected_day_for_wp_user(42), null);
ddv_check('expected day: uid 0 -> null (no query)', MealsDB_Delivery_Date_Advisor::expected_day_for_wp_user(0), null);

// -----------------------------------------------------------------------
// resolve_action(): the WC order-edit save decision — set / clear / noop
// (directive Section B.6: valid value writes the meta, a cleared field
// DELETES it so the order reverts to the computed occurrence, anything
// else leaves the stored value alone).
// -----------------------------------------------------------------------
ddv_check(
    'action: field absent -> noop',
    MealsDB_Delivery_Date_Advisor::resolve_action(null, '2026-07-23'),
    ['action' => 'noop', 'value' => '']
);
ddv_check(
    'action: cleared with existing -> clear',
    MealsDB_Delivery_Date_Advisor::resolve_action('', '2026-07-23'),
    ['action' => 'clear', 'value' => '']
);
ddv_check(
    'action: cleared with nothing stored -> noop',
    MealsDB_Delivery_Date_Advisor::resolve_action('', ''),
    ['action' => 'noop', 'value' => '']
);
ddv_check(
    'action: valid new value -> set',
    MealsDB_Delivery_Date_Advisor::resolve_action('2026-07-25', ''),
    ['action' => 'set', 'value' => '2026-07-25']
);
ddv_check(
    'action: valid changed value -> set',
    MealsDB_Delivery_Date_Advisor::resolve_action('2026-07-25', '2026-07-23'),
    ['action' => 'set', 'value' => '2026-07-25']
);
ddv_check(
    'action: unchanged value -> noop (no audit noise)',
    MealsDB_Delivery_Date_Advisor::resolve_action('2026-07-23', '2026-07-23'),
    ['action' => 'noop', 'value' => '']
);
ddv_check(
    'action: garbage does NOT clobber the stored value -> noop',
    MealsDB_Delivery_Date_Advisor::resolve_action('not-a-date', '2026-07-23'),
    ['action' => 'noop', 'value' => '']
);

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
