<?php
/**
 * Tests for MealsDB_Allocation_Engine idempotency helpers.
 *
 * Covers fingerprint_allocation_rows() and build_desired_allocation_rows()
 * — the two private helpers the G3 idempotency check depends on.
 *
 * Run with: php tests/test-allocation-engine-fingerprint.php
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

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $output = 'OBJECT') { return []; }
        public function get_row($query, $output = 'OBJECT', $y = 0) { return null; }
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function query($query) { return 0; }
    }
}

/**
 * Reflection helpers to exercise the private statics / instance methods
 * without going through the full allocate_order entry point (which
 * would need a forest of WC / HPOS stubs we don't care about here).
 */
function fingerprint(array $rows): string {
    $m = new ReflectionMethod('MealsDB_Allocation_Engine', 'fingerprint_allocation_rows');
    $m->setAccessible(true);
    return (string) $m->invoke(null, $rows);
}

function build_rows(
    int $client_id,
    int $wc_order_id,
    string $order_date_str,
    string $delivery_date,
    string $coverage_start,
    string $coverage_end,
    string $month1,
    string $month2,
    int $mains,
    int $sides,
    int $tax_sides,
    int $nontax_sides
): array {
    $cov_start_dt = new DateTime($coverage_start);
    $cov_end_dt   = new DateTime($coverage_end);

    $engine = new class extends MealsDB_Allocation_Engine {
        public function __construct() { /* skip real ctor */ }
    };

    $m = new ReflectionMethod('MealsDB_Allocation_Engine', 'build_desired_allocation_rows');
    $m->setAccessible(true);
    return (array) $m->invoke(
        $engine, $client_id, $wc_order_id, $order_date_str, $delivery_date,
        $coverage_start, $coverage_end, $month1, $month2,
        $mains, $sides, $tax_sides, $nontax_sides,
        $cov_start_dt, $cov_end_dt
    );
}

$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}
function assert_not_equal($a, $b, string $label) {
    global $failures, $passed;
    if ($a !== $b) { $passed++; return; }
    $failures[] = "FAIL: $label (values unexpectedly equal: " . var_export($a, true) . ')';
}

// ---------------------------------------------------------------------------
// fingerprint_allocation_rows
// ---------------------------------------------------------------------------
assert_equal('', fingerprint([]), 'empty input → empty fingerprint (caller treats as "no match")');

$row = [
    'client_id' => 42, 'wc_order_id' => 100, 'order_date' => '2026-04-01',
    'delivery_date' => '2026-04-05', 'coverage_start' => '2026-04-05',
    'coverage_end' => '2026-04-11', 'billing_month' => '2026-04',
    'mains_count' => 5, 'sides_count' => 3, 'tax_sides_count' => 1, 'nontax_sides_count' => 2,
];
$fp_int = fingerprint([$row]);
assert_not_equal('', $fp_int, 'single-row fingerprint is non-empty');

// Same row — same fingerprint.
assert_equal($fp_int, fingerprint([$row]), 'identical input → identical fingerprint');

// Type normalisation: wpdb returns numeric columns as strings.
$row_as_strings = array_map(static function ($v) { return is_int($v) ? (string) $v : $v; }, $row);
assert_equal($fp_int, fingerprint([$row_as_strings]), 'stringified ints produce the same fingerprint');

// Order independence: a two-row set hashes identically regardless of
// input order, because the helper sorts by (billing_month, coverage_start).
$row_a = $row;
$row_b = array_merge($row, ['billing_month' => '2026-05', 'coverage_start' => '2026-05-01', 'coverage_end' => '2026-05-04']);
assert_equal(fingerprint([$row_a, $row_b]), fingerprint([$row_b, $row_a]), 'row order is irrelevant');

// Different counts → different fingerprint.
$row_changed = $row;
$row_changed['mains_count'] = 6;
assert_not_equal($fp_int, fingerprint([$row_changed]), 'changed count → different fingerprint');

// ---------------------------------------------------------------------------
// build_desired_allocation_rows — single month
// ---------------------------------------------------------------------------
$single = build_rows(42, 100, '2026-04-01', '2026-04-05', '2026-04-05', '2026-04-11', '2026-04', '2026-04', 7, 3, 2, 1);
assert_equal(1, count($single), 'single-month delivery produces 1 row');
assert_equal(7, $single[0]['mains_count'], 'single-month mains passed through unchanged');
assert_equal('2026-04', $single[0]['billing_month'], 'single-month billing_month');
assert_equal('2026-04-05', $single[0]['coverage_start'], 'single-month coverage_start');
assert_equal('2026-04-11', $single[0]['coverage_end'], 'single-month coverage_end');

// ---------------------------------------------------------------------------
// build_desired_allocation_rows — two-month straddle
// Jan 30 .. Feb 2 = 4 days total, 2 in Jan (30, 31), 2 in Feb (1, 2).
// 10 mains split 50/50 → 5 and 5.
// ---------------------------------------------------------------------------
$double = build_rows(42, 200, '2026-01-30', '2026-01-30', '2026-01-30', '2026-02-02', '2026-01', '2026-02', 10, 6, 4, 2);
assert_equal(2, count($double), 'two-month delivery produces 2 rows');
assert_equal('2026-01', $double[0]['billing_month'], 'first row → month1');
assert_equal('2026-02', $double[1]['billing_month'], 'second row → month2');
assert_equal(5, $double[0]['mains_count'], 'month1 gets 5 mains');
assert_equal(5, $double[1]['mains_count'], 'month2 gets remaining 5 mains');
assert_equal(10, $double[0]['mains_count'] + $double[1]['mains_count'], 'mains split sums to original');
assert_equal(6,  $double[0]['sides_count'] + $double[1]['sides_count'], 'sides split sums to original');
assert_equal(4,  $double[0]['tax_sides_count'] + $double[1]['tax_sides_count'], 'tax_sides split sums to original');
assert_equal(2,  $double[0]['nontax_sides_count'] + $double[1]['nontax_sides_count'], 'nontax_sides split sums to original');
assert_equal('2026-01-31', $double[0]['coverage_end'], 'month1 row ends on last day of Jan');
assert_equal('2026-02-01', $double[1]['coverage_start'], 'month2 row starts on first day of Feb');

// ---------------------------------------------------------------------------
// End-to-end: desired fingerprint matches an "existing" SELECT result
// (simulated as strings, as wpdb would return them).
// ---------------------------------------------------------------------------
$existing = array_map(static function ($r) {
    return array_map(static function ($v) { return is_int($v) ? (string) $v : $v; }, $r);
}, $single);

assert_equal(fingerprint($single), fingerprint($existing), 'desired rows match stringified existing rows → fingerprints equal');

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
