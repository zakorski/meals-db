<?php
/**
 * Unit tests for MealsDB_Allocation_Engine::lock_allocation_month().
 *
 * Locks in the INSERT-IGNORE-then-SELECT-FOR-UPDATE invariant: without
 * this pattern, concurrent allocate/deallocate calls for the same
 * (client_id, billing_month) can deadlock on the SUM step inside
 * recalculate_month_totals().
 *
 * Run with: php tests/test-allocation-engine-lock.php
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

// Minimal wpdb shim (fallback for runs outside WordPress).
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $output = 'OBJECT') { return []; }
        public function get_row($query, $output = 'OBJECT', $y = 0) { return null; }
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function query($query) { return 0; }
        public function delete($table, $where, $format = null) { return 0; }
        public function insert($table, $data, $format = null) { return 0; }
    }
}

/**
 * Records every wpdb call in order so tests can assert sequence and
 * fingerprint the SQL shape. prepare() returns the interpolated query
 * so downstream assertions can match on the literal text.
 */
class LockTest_Wpdb extends wpdb {
    public array $query_log = [];
    public $insert_ignore_result = 1;
    public $select_for_update_result = '42';

    public function __construct() { /* no parent */ }

    public function prepare($query, ...$args) {
        // Trivial interpolation — good enough for substring assertions.
        if (!empty($args) && is_array($args[0] ?? null)) {
            $args = $args[0];
        }
        $out = $query;
        foreach ($args as $a) {
            $out = preg_replace('/%d|%s/', is_int($a) ? (string) $a : "'" . addslashes((string) $a) . "'", $out, 1);
        }
        return $out;
    }

    public function query($sql) {
        $this->query_log[] = ['method' => 'query', 'sql' => $sql];
        if (stripos($sql, 'INSERT IGNORE') === 0 && stripos($sql, 'client_allocations') !== false) {
            return $this->insert_ignore_result;
        }
        return 1;
    }

    public function get_var($sql, $x = 0, $y = 0) {
        $this->query_log[] = ['method' => 'get_var', 'sql' => $sql];
        if (stripos($sql, 'FOR UPDATE') !== false) {
            return $this->select_for_update_result;
        }
        return null;
    }
}

/**
 * Exposes the private lock_allocation_month() for direct test.
 *
 * MealsDB_Allocation_Engine::$wpdb is a private property, so we can't
 * set it from a subclass with normal assignment. Use reflection to
 * inject the test double without invoking the real constructor (which
 * would try to instantiate MealsDB_WC_Order_Query against a partial
 * stub).
 */
class LockTest_Engine extends MealsDB_Allocation_Engine {
    public function __construct(wpdb $wpdb) {
        $prop = new ReflectionProperty(parent::class, 'wpdb');
        $prop->setAccessible(true);
        $prop->setValue($this, $wpdb);
    }

    public function lock_month_public(int $client_id, string $billing_month): bool {
        $ref = new ReflectionMethod(parent::class, 'lock_allocation_month');
        $ref->setAccessible(true);
        return (bool) $ref->invoke($this, $client_id, $billing_month);
    }
}

$failures = [];
$passed   = 0;

function assert_true($value, string $label) {
    global $failures, $passed;
    if ((bool) $value) { $passed++; return; }
    $failures[] = "FAIL: $label (expected true, got " . var_export($value, true) . ')';
}
function assert_false($value, string $label) {
    global $failures, $passed;
    if (!(bool) $value) { $passed++; return; }
    $failures[] = "FAIL: $label (expected false, got " . var_export($value, true) . ')';
}
function assert_contains(string $needle, string $haystack, string $label) {
    global $failures, $passed;
    if (stripos($haystack, $needle) !== false) { $passed++; return; }
    $failures[] = "FAIL: $label (expected '$needle' in '$haystack')";
}

// ---------------------------------------------------------------------------
// Happy path: INSERT IGNORE precedes SELECT FOR UPDATE; helper returns true.
// ---------------------------------------------------------------------------
$wpdb   = new LockTest_Wpdb();
$engine = new LockTest_Engine($wpdb);

assert_true($engine->lock_month_public(42, '2026-04'), 'lock_allocation_month returns true on happy path');

// Expect exactly 2 queries: 1× INSERT IGNORE (via query()), 1× SELECT FOR UPDATE (via get_var()).
assert_true(count($wpdb->query_log) === 2, 'exactly 2 queries issued');
assert_true($wpdb->query_log[0]['method'] === 'query', 'first call is wpdb::query()');
assert_contains('INSERT IGNORE', $wpdb->query_log[0]['sql'], 'first query is INSERT IGNORE');
assert_contains('client_allocations', $wpdb->query_log[0]['sql'], 'first query targets client_allocations');
assert_contains("client_id, billing_month", $wpdb->query_log[0]['sql'], 'INSERT IGNORE includes only the key columns');

assert_true($wpdb->query_log[1]['method'] === 'get_var', 'second call is wpdb::get_var()');
assert_contains('FOR UPDATE', $wpdb->query_log[1]['sql'], 'second query is SELECT ... FOR UPDATE');
assert_contains('client_id', $wpdb->query_log[1]['sql'], 'SELECT filters by client_id');
assert_contains('billing_month', $wpdb->query_log[1]['sql'], 'SELECT filters by billing_month');
assert_contains("'2026-04'", $wpdb->query_log[1]['sql'], 'billing_month was bound to the SELECT');

// ---------------------------------------------------------------------------
// INSERT IGNORE failure: helper returns false, SELECT FOR UPDATE never fires.
// ---------------------------------------------------------------------------
$wpdb = new LockTest_Wpdb();
$wpdb->insert_ignore_result = false;
$engine = new LockTest_Engine($wpdb);

assert_false($engine->lock_month_public(42, '2026-04'), 'lock_allocation_month returns false when INSERT IGNORE fails');
assert_true(count($wpdb->query_log) === 1, 'INSERT IGNORE failure short-circuits before SELECT FOR UPDATE');

// ---------------------------------------------------------------------------
// SELECT FOR UPDATE returns null: helper returns false.
// ---------------------------------------------------------------------------
$wpdb = new LockTest_Wpdb();
$wpdb->select_for_update_result = null;
$engine = new LockTest_Engine($wpdb);

assert_false($engine->lock_month_public(42, '2026-04'), 'lock_allocation_month returns false when SELECT returns null');
assert_true(count($wpdb->query_log) === 2, 'both queries issued even when SELECT FOR UPDATE returns null');

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
