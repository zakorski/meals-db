<?php
/**
 * Regression tests for MAJ-5 — report end-date boundary dropped last-day orders.
 *
 * Before the fix, MealsDB_Reports::normalise_dates() returned the end day at
 * 00:00:00 and the report queries compared `date_created_gmt <= '<end> 00:00:00'`,
 * so any order placed after midnight on the final day of a range was silently
 * excluded ("report through Jan 31" lost all of Jan 31). The fix makes the
 * interval half-open [start, end_exclusive): start of the start day (inclusive)
 * to start of the day AFTER the end day (exclusive), and every query uses
 * `>= start AND < end_exclusive`.
 *
 * These tests assert the BOUND and the OPERATOR, not live data:
 *   T-1  last-day inclusion: end bound is the exclusive next day, operator `<`.
 *   T-2  start inclusive:    start bound is start-of-day, operator `>=`.
 *   T-3  all sites consistent: no `<= %s` / `$dates['end']` against date_created_gmt.
 *   T-4  reversed range still normalizes (start <= end_exclusive).
 *   T-5  malformed date -> normalise_dates returns null (caller treats as empty).
 *   T-6  single-day range -> a full single day [day 00:00:00, next-day 00:00:00).
 *
 * Run with: php tests/test-reports-date-boundary.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default') { return $text; }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return 0; }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool { return isset($GLOBALS['__mealsdb_test_logged_in']) ? (bool) $GLOBALS['__mealsdb_test_logged_in'] : false; }
}
if (!function_exists('current_user_can')) {
    function current_user_can(string $cap): bool { return isset($GLOBALS['__mealsdb_test_caps'][$cap]) ? (bool) $GLOBALS['__mealsdb_test_caps'][$cap] : false; }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args) { return $value; }
}

set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// Minimal wpdb shim for runs outside WordPress.
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $output = OBJECT) { return []; }
        public function get_row($query, $output = OBJECT, $y = 0) { return null; }
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function query($query) { return 0; }
    }
}

/**
 * Recording wpdb: captures every prepare() call (query template + bound args)
 * and resolves the placeholders just enough that the captured string reflects
 * what would hit MySQL. get_results() returns [] so the methods short-circuit
 * after the first prepared query — which is exactly the point where the date
 * bound is bound, so the capture is sufficient.
 */
class BoundaryRecordingWpdb extends wpdb {
    /** @var array<int, array{query:string, args:array, resolved:string}> */
    public array $captured = [];

    public function __construct() { $this->prefix = 'wp_'; }

    public function prepare($query, ...$args) {
        // WP allows prepare($query, [a, b]) or prepare($query, a, b).
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $resolved = $this->resolve($query, $args);
        $this->captured[] = ['query' => $query, 'args' => $args, 'resolved' => $resolved];
        return $resolved;
    }

    public function get_results($query, $output = OBJECT) { return []; }
    public function get_row($query, $output = OBJECT, $y = 0) { return null; }
    public function get_var($query, $x = 0, $y = 0) { return null; }
    public function query($query) { return 0; }

    /** Substitute %s/%d placeholders left-to-right for assertion readability. */
    private function resolve(string $query, array $args): string {
        $i = 0;
        return preg_replace_callback('/%[sdf]/', static function ($m) use (&$i, $args) {
            if (!array_key_exists($i, $args)) { return $m[0]; }
            $v = $args[$i++];
            return $m[0] === '%s' ? "'" . $v . "'" : (string) $v;
        }, $query);
    }
}

$failures = [];
$passed   = 0;

function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}
function assert_true($value, string $label) { assert_equal(true, (bool) $value, $label); }
function assert_contains(string $needle, string $haystack, string $label) {
    global $failures, $passed;
    if (str_contains($haystack, $needle)) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected to contain: %s\n  in: %s", $label, $needle, $haystack);
}
function assert_not_contains(string $needle, string $haystack, string $label) {
    global $failures, $passed;
    if (!str_contains($haystack, $needle)) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected NOT to contain: %s\n  in: %s", $label, $needle, $haystack);
}

// Authorize the caller so report methods run their bodies (mirrors authz test).
$GLOBALS['__mealsdb_test_logged_in']                  = true;
$GLOBALS['__mealsdb_test_caps']['manage_woocommerce'] = true;

// ---------------------------------------------------------------------------
// normalise_dates() via reflection — the unit under test for the bound itself.
// ---------------------------------------------------------------------------
$reports = new MealsDB_Reports(new BoundaryRecordingWpdb());
$ref     = new ReflectionMethod(MealsDB_Reports::class, 'normalise_dates');
$ref->setAccessible(true);

// T-2 / T-1: a Jan 1 -> Jan 31 range.
$d = $ref->invoke($reports, '2026-01-01', '2026-01-31');
assert_equal('2026-01-01 00:00:00', $d['start'], 'T-2 start bound is start-of-day');
assert_equal('2026-02-01 00:00:00', $d['end_exclusive'], 'T-1 end bound is exclusive next day (2026-02-01)');
assert_true(!array_key_exists('end', $d), "T-1 no legacy 'end' key remains");

// T-4: reversed range normalizes to the same window (start <= end_exclusive).
$d_rev = $ref->invoke($reports, '2026-01-31', '2026-01-01');
assert_equal('2026-01-01 00:00:00', $d_rev['start'], 'T-4 reversed range start');
assert_equal('2026-02-01 00:00:00', $d_rev['end_exclusive'], 'T-4 reversed range end_exclusive');
assert_true($d_rev['start'] <= $d_rev['end_exclusive'], 'T-4 start <= end_exclusive');

// T-6: single-day range -> a full single day [day, next-day).
$d_one = $ref->invoke($reports, '2026-01-15', '2026-01-15');
assert_equal('2026-01-15 00:00:00', $d_one['start'], 'T-6 single-day start');
assert_equal('2026-01-16 00:00:00', $d_one['end_exclusive'], 'T-6 single-day end_exclusive (next day)');

// T-5: malformed end date -> null (caller turns this into an empty result).
$d_bad = $ref->invoke($reports, '2026-01-01', 'not-a-date');
assert_equal(null, $d_bad, 'T-5 malformed date -> normalise_dates returns null');

// Malformed input -> caller returns empty array, no fatal.
$rec_bad = new BoundaryRecordingWpdb();
$reports_bad = new MealsDB_Reports($rec_bad);
assert_equal([], $reports_bad->get_resupply_requirements('2026-01-01', 'not-a-date'), 'T-5 caller returns [] on bad date');
assert_equal([], $rec_bad->captured, 'T-5 no query prepared on bad date');

// ---------------------------------------------------------------------------
// T-1 / T-3: live query bound + operator for the two normalise_dates consumers.
// ---------------------------------------------------------------------------
foreach (['get_resupply_requirements', 'get_meal_breakdown'] as $method) {
    $rec = new BoundaryRecordingWpdb();
    $GLOBALS['wpdb'] = $rec; // MealsDB_DB::table() reads the global for the prefix.
    $r   = new MealsDB_Reports($rec);
    $r->$method('2026-01-01', '2026-01-31');

    assert_true(count($rec->captured) >= 1, "$method prepared a query");
    $cap = $rec->captured[0];

    // Operator against date_created_gmt is `<`, never `<=`.
    assert_contains('o.date_created_gmt < ', $cap['resolved'], "$method uses `<` against date_created_gmt");
    assert_not_contains('date_created_gmt <= ', $cap['resolved'], "$method does not use `<=` against date_created_gmt");
    assert_contains('o.date_created_gmt >= ', $cap['resolved'], "$method uses `>=` for the start bound");

    // The exclusive next-day bound is the one bound (not the midnight 31st).
    assert_true(in_array('2026-02-01 00:00:00', $cap['args'], true), "$method binds the exclusive end (2026-02-01 00:00:00)");
    assert_true(!in_array('2026-01-31 00:00:00', $cap['args'], true), "$method does NOT bind the midnight last-day end");
}

// ---------------------------------------------------------------------------
// T-3 (full-file): no `<= %s` against date_created_gmt and no `$dates['end']`
// survive anywhere in the reports service. This catches the sites that
// short-circuit on an empty stub (private_customer_report, order_error_report).
// ---------------------------------------------------------------------------
$src = file_get_contents(__DIR__ . '/../includes/services/class-reports.php');
assert_not_contains('date_created_gmt <= %s', $src, 'T-3 no `<= %s` against date_created_gmt anywhere in the file');
assert_true(!preg_match("/\\\$dates\\['end'\\]/", $src), "T-3 no legacy \$dates['end'] reference remains");

// ---------------------------------------------------------------------------
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
