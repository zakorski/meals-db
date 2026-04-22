<?php
/**
 * Tests for MealsDB_Task_Rules::compute_next_run().
 *
 * Covers every recurrence pattern shape (daily, weekly, monthly_weekday,
 * monthly_day, interval_days) plus malformed input handling.
 *
 * Run with: php tests/test-task-rules-compute-next-run.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default') { return $text; }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0, $depth = 512) { return json_encode($data, $flags, $depth); }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = '') { return $default; }
}

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public int $insert_id = 0;
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $output = 'OBJECT') { return []; }
        public function get_row($query, $output = 'OBJECT', $y = 0) { return null; }
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function query($query) { return 0; }
        public function insert($table, $data, $format = null) { return 1; }
        public function update($table, $data, $where, $format = null, $where_format = null) { return 1; }
    }
}

$failures = [];
$passed = 0;

function check(string $label, $actual, $expected) {
    global $failures, $passed;
    if ($actual === $expected) {
        $passed++;
    } else {
        $failures[] = sprintf('[%s] expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
    }
}

$rules = new MealsDB_Task_Rules(new wpdb());

$after = new DateTimeImmutable('2026-04-22T12:00:00Z');

// ---- daily ----
$next = $rules->compute_next_run(['type' => 'daily', 'interval' => 1, 'time' => '06:00'], $after);
// Next 06:00 UTC after 12:00 is tomorrow 06:00.
check('daily next run', $next->format('Y-m-d H:i'), '2026-04-23 06:00');

$next = $rules->compute_next_run(['type' => 'daily', 'interval' => 3, 'time' => '06:00'], $after);
check('daily interval=3 next run', $next->format('Y-m-d'), '2026-04-25');

// When "after" is 05:00 and the time is 06:00, should land same day.
$same_day = new DateTimeImmutable('2026-04-22T05:00:00Z');
$next = $rules->compute_next_run(['type' => 'daily', 'interval' => 1, 'time' => '06:00'], $same_day);
check('daily same-day jump', $next->format('Y-m-d H:i'), '2026-04-22 06:00');

// ---- weekly ----
// 2026-04-22 is a Wednesday. Requesting Wednesday at 06:00 with after=Wed 12:00 UTC
// should advance to the following Wednesday.
$next = $rules->compute_next_run([
    'type' => 'weekly', 'interval' => 1,
    'days_of_week' => ['wednesday'], 'time' => '06:00',
], $after);
check('weekly wed next is next wed', $next->format('Y-m-d'), '2026-04-29');

// Multi-day weekly; closest match should be Thursday after Wed 12:00.
$next = $rules->compute_next_run([
    'type' => 'weekly', 'interval' => 1,
    'days_of_week' => ['monday', 'thursday'], 'time' => '06:00',
], $after);
check('weekly multi-day picks soonest', $next->format('Y-m-d'), '2026-04-23');

// Empty days_of_week -> null
$next = $rules->compute_next_run(['type' => 'weekly', 'interval' => 1, 'days_of_week' => [], 'time' => '06:00'], $after);
check('weekly missing days returns null', $next, null);

// ---- monthly_day ----
// Day 15 @ 08:00 after 2026-04-22 should be 2026-05-15.
$next = $rules->compute_next_run(['type' => 'monthly_day', 'interval' => 1, 'day' => 15, 'time' => '08:00'], $after);
check('monthly_day 15 from 2026-04-22', $next->format('Y-m-d'), '2026-05-15');

// Day 30 — February clamp check.
$feb = new DateTimeImmutable('2026-02-05T00:00:00Z');
$next = $rules->compute_next_run(['type' => 'monthly_day', 'interval' => 1, 'day' => 30, 'time' => '08:00'], $feb);
check('monthly_day 30 in Feb clamps', $next->format('Y-m-d'), '2026-02-28');

// ---- monthly_weekday: 4th Tuesday ----
// 4th Tuesday of April 2026 is 2026-04-28. So after 2026-04-22 we expect 2026-04-28.
$next = $rules->compute_next_run([
    'type' => 'monthly_weekday', 'interval' => 1,
    'nth' => 4, 'day_of_week' => 'tuesday', 'time' => '08:00',
], $after);
check('monthly_weekday 4th Tuesday of April 2026', $next->format('Y-m-d'), '2026-04-28');

// 5th Monday — only some months have one. Jan 2026: Mondays are 5, 12, 19, 26 — no 5th.
// So after 2026-01-15 for the "5th Monday" pattern should skip to the next month with a 5th Monday.
$jan = new DateTimeImmutable('2026-01-15T00:00:00Z');
$next = $rules->compute_next_run([
    'type' => 'monthly_weekday', 'interval' => 1,
    'nth' => 5, 'day_of_week' => 'monday', 'time' => '08:00',
], $jan);
// March 2026: Mondays are 2, 9, 16, 23, 30 — 5th Monday = March 30.
check('monthly_weekday 5th Monday skips to March', $next->format('Y-m-d'), '2026-03-30');

// Bad day-of-week returns null.
$next = $rules->compute_next_run([
    'type' => 'monthly_weekday', 'interval' => 1,
    'nth' => 1, 'day_of_week' => 'notaday', 'time' => '08:00',
], $after);
check('monthly_weekday invalid dow returns null', $next, null);

// ---- interval_days ----
// Start 2026-01-06, every 28 days, after 2026-04-22 12:00Z.
// 2026-01-06, 2026-02-03, 2026-03-03, 2026-03-31, 2026-04-28, ...
$next = $rules->compute_next_run([
    'type' => 'interval_days', 'interval' => 28,
    'start_date' => '2026-01-06', 'time' => '08:00',
], $after);
check('interval_days 28 after 2026-04-22', $next->format('Y-m-d'), '2026-04-28');

// If the start_date is still in the future, return it.
$before_start = new DateTimeImmutable('2025-12-01T00:00:00Z');
$next = $rules->compute_next_run([
    'type' => 'interval_days', 'interval' => 28,
    'start_date' => '2026-01-06', 'time' => '08:00',
], $before_start);
check('interval_days before start date returns start', $next->format('Y-m-d'), '2026-01-06');

// Malformed start_date returns null.
$next = $rules->compute_next_run([
    'type' => 'interval_days', 'interval' => 28,
    'start_date' => 'not-a-date', 'time' => '08:00',
], $after);
check('interval_days bad start returns null', $next, null);

// ---- unknown type ----
$next = $rules->compute_next_run(['type' => 'bogus'], $after);
check('unknown type returns null', $next, null);

// ---- dow helpers ----
check('dow_to_index wednesday', MealsDB_Task_Rules::dow_to_index('Wednesday'), 3);
check('dow_to_index unknown', MealsDB_Task_Rules::dow_to_index('Weddensday'), null);
check('dow_index_to_name 0', MealsDB_Task_Rules::dow_index_to_name(0), 'Sunday');

// ---- placeholder substitution ----
$out = MealsDB_Task_Rules::apply_placeholders(
    ['name' => '{{first}} {{last}}', 'id' => '{{wp_id}}'],
    ['first' => 'Jane', 'last' => 'Doe', 'wp_id' => 42]
);
check('placeholder name', $out['name'], 'Jane Doe');
check('placeholder id', $out['id'], '42');

$out = MealsDB_Task_Rules::apply_placeholders('Just {{x}}!', ['x' => 'testing']);
check('placeholder scalar string', $out, 'Just testing!');

echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) {
    echo "FAIL: $f\n";
}
exit(empty($failures) ? 0 : 1);
