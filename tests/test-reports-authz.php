<?php
/**
 * Defence-in-depth tests for MealsDB_Reports capability gating.
 *
 * AJAX handlers already check capability before instantiating the
 * reports service. These tests lock in the behaviour that every
 * data-fetching report method returns its empty shape — without
 * querying the database — when the permission layer denies access.
 *
 * Run with: php tests/test-reports-authz.php
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

// Fail the test run loudly on any unexpected error.
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// OBJECT is a WP constant; define for standalone test runs.
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

// Minimal wpdb shim for runs outside WordPress. If WP has already
// loaded wpdb (e.g. integration test harness), leave it alone.
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
 * Reports service holds a reference to wpdb; every method under test
 * should bail on the authz guard BEFORE calling into the connection.
 * If any method reaches this stub, the corresponding test fails.
 */
class AuthzTest_Wpdb extends wpdb {
    public int $query_calls = 0;

    public function __construct() { /* no parent */ }

    public function prepare($query, ...$args) {
        $this->query_calls++;
        throw new RuntimeException('wpdb::prepare() must not be reached when caller is unauthorized.');
    }
    public function get_results($query, $output = OBJECT) {
        $this->query_calls++;
        throw new RuntimeException('wpdb::get_results() must not be reached when caller is unauthorized.');
    }
    public function get_row($query, $output = OBJECT, $y = 0) {
        $this->query_calls++;
        throw new RuntimeException('wpdb::get_row() must not be reached when caller is unauthorized.');
    }
    public function get_var($query, $x = 0, $y = 0) {
        $this->query_calls++;
        throw new RuntimeException('wpdb::get_var() must not be reached when caller is unauthorized.');
    }
    public function query($query) {
        $this->query_calls++;
        throw new RuntimeException('wpdb::query() must not be reached when caller is unauthorized.');
    }
}

$failures = [];
$passed   = 0;

function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) {
        $passed++;
        return;
    }
    $failures[] = sprintf(
        "FAIL: %s\n  expected: %s\n  actual:   %s",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

function assert_true($value, string $label) {
    assert_equal(true, (bool) $value, $label);
}

// ---------------------------------------------------------------------------
// Scenario 1: unauthorized caller — every data-fetching method returns the
// documented empty shape and never calls into $wpdb.
// ---------------------------------------------------------------------------
$GLOBALS['__mealsdb_test_logged_in'] = false;
$GLOBALS['__mealsdb_test_caps']      = [];

$wpdb    = new AuthzTest_Wpdb();
$reports = new MealsDB_Reports($wpdb);

assert_equal([], $reports->generate_purchase_order(12, 6, 0.85), 'generate_purchase_order returns []');

$contribution = $reports->contribution_reconciliation('2026-01-01', '2026-01-31');
assert_equal([], $contribution['rows'], 'contribution_reconciliation rows empty');
assert_equal(0, $contribution['summary']['total_clients'], 'contribution_reconciliation summary zeroed');

$delivery = $reports->delivery_fee_reconciliation('2026-01-01', '2026-01-31');
assert_equal([], $delivery['rows'], 'delivery_fee_reconciliation rows empty');
assert_equal(0, $delivery['summary']['total_clients'], 'delivery_fee_reconciliation summary zeroed');

$private = $reports->private_customer_report('2026-01-01', '2026-01-31');
assert_equal([], $private['rows'], 'private_customer_report rows empty');
assert_equal(0, $private['grand_totals']['total_mains'], 'private_customer_report totals zeroed');

$errors = $reports->order_error_report('2026-01-01', '2026-01-31');
assert_equal([], $errors['errors'], 'order_error_report errors empty');
assert_equal(0, $errors['summary']['total_orders_checked'], 'order_error_report summary zeroed');

assert_equal(0, $wpdb->query_calls, 'no SQL executed while unauthorized');

// ---------------------------------------------------------------------------
// Scenario 2: authorized caller — the guard passes through and the method
// body runs until the first wpdb call (which our stub turns into an
// exception; catching confirms we got past the guard).
// ---------------------------------------------------------------------------
$GLOBALS['__mealsdb_test_logged_in']                    = true;
$GLOBALS['__mealsdb_test_caps']['manage_woocommerce']   = true;

$wpdb    = new AuthzTest_Wpdb();
$reports = new MealsDB_Reports($wpdb);

$guard_bypassed = false;
try {
    $reports->generate_purchase_order(12, 6, 0.85);
} catch (RuntimeException $e) {
    $guard_bypassed = str_contains($e->getMessage(), 'must not be reached') === false
        ? false
        : true;
}
assert_true($guard_bypassed, 'authorized caller proceeds past the authz guard');

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
