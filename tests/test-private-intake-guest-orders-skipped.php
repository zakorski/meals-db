<?php
/**
 * Guest orders (customer_id = 0) never trigger Private promotion.
 * meals_clients requires wp_user_id > 0 for every downstream sync
 * path, so an anonymous checkout must not produce a phantom record.
 *
 * Run with: php tests/test-private-intake-guest-orders-skipped.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!class_exists('WC_Order')) {
    class WC_Order {
        private $customer_id;
        public function __construct(int $customer_id = 0) { $this->customer_id = $customer_id; }
        public function get_customer_id(): int { return $this->customer_id; }
        public function get_id(): int { return 42; }
        public function get_billing_first_name(): string { return 'Anon'; }
        public function get_billing_last_name(): string { return 'User'; }
        public function get_billing_phone(): string { return ''; }
    }
}

// Track whether maybe_promote ever reaches get_userdata. We use a
// global counter that the stub get_userdata increments; a guest-order
// path must never reach it.
$GLOBALS['mealsdb_get_userdata_calls'] = 0;
if (!function_exists('get_userdata')) {
    function get_userdata($id) {
        $GLOBALS['mealsdb_get_userdata_calls']++;
        return null;
    }
}

$failures = [];
$passed = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}

// Guest order: customer_id = 0.
$guest = new WC_Order(0);
MealsDB_Private_Intake::on_order_status_changed(42, 'checkout-draft', 'processing', $guest);
assert_equal(0, $GLOBALS['mealsdb_get_userdata_calls'], 'guest order does not reach get_userdata');

// maybe_promote called directly with 0 also bails without doing work.
$result = MealsDB_Private_Intake::maybe_promote(0);
assert_equal(null, $result, 'maybe_promote(0) returns null');
assert_equal(0, $GLOBALS['mealsdb_get_userdata_calls'], 'maybe_promote(0) does not reach get_userdata');

// Negative user id: also bail.
$result2 = MealsDB_Private_Intake::maybe_promote(-1);
assert_equal(null, $result2, 'maybe_promote(-1) returns null');
assert_equal(0, $GLOBALS['mealsdb_get_userdata_calls'], 'maybe_promote(-1) does not reach get_userdata');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
