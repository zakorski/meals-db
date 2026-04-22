<?php
/**
 * Verifies that a status transition from any inactive status into an
 * active status triggers MealsDB_Private_Intake::maybe_promote, and
 * that maybe_promote ultimately writes an INSERT when no row exists
 * for the wp_user_id. The WC hook registration is also verified.
 *
 * Run with: php tests/test-private-intake-promotion.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// Stub wpdb: get_by_wp_user_id returns null so the promotion path
// runs to the INSERT.
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public int $insert_id = 0;
        public int $insert_calls = 0;
        public array $last_insert_data = [];
        public function prepare($query, ...$args) {
            if (empty($args)) return $query;
            $flat = $args;
            if (count($flat) === 1 && is_array($flat[0])) {
                $flat = $flat[0];
            }
            foreach ($flat as $arg) {
                $pos = strpos($query, '%d');
                if ($pos === false) $pos = strpos($query, '%s');
                if ($pos === false) break;
                $query = substr($query, 0, $pos) . (is_int($arg) ? (string)$arg : "'" . addslashes((string)$arg) . "'") . substr($query, $pos + 2);
            }
            return $query;
        }
        public function get_row($query, $output = OBJECT) { return null; }
        public function get_var($query, $x = 0, $y = 0) {
            // information_schema lookups: pretend the table exists.
            if (stripos($query, 'information_schema') !== false) {
                return 1;
            }
            return null;
        }
        public function get_col($query, $x = 0) { return []; }
        public function get_results($query, $output = OBJECT) { return []; }
        public function query($query) { return 0; }
        public function insert($table, $data) {
            $this->insert_calls++;
            $this->last_insert_data = $data;
            $this->insert_id = 4242;
            return 1;
        }
    }
}
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
}

if (!class_exists('WP_User')) {
    class WP_User {
        public $user_email = 'promoted@example.com';
        public $first_name = 'Test';
        public $last_name  = 'User';
    }
}
if (!function_exists('get_userdata')) {
    function get_userdata($id) { return $id > 0 ? new WP_User() : null; }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single = true) {
        $fixtures = [
            'billing_first_name' => 'Billing',
            'billing_last_name'  => 'Last',
            'billing_phone'      => '555-1234',
        ];
        return $fixtures[$key] ?? '';
    }
}
if (!function_exists('current_time')) {
    function current_time($type) { return '2026-04-22 12:00:00'; }
}
if (!function_exists('current_user_can')) { function current_user_can($cap) { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }

// add_action stub — captures registered hooks so we can assert the
// init() call wires the right event.
$GLOBALS['mealsdb_registered_actions'] = [];
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $argc = 1) {
        $GLOBALS['mealsdb_registered_actions'][$hook] = [
            'callback' => $callback,
            'priority' => $priority,
            'argc'     => $argc,
        ];
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value) { return json_encode($value); }
}

if (!class_exists('WC_Order')) {
    class WC_Order {
        private $customer_id;
        public function __construct(int $customer_id = 0) { $this->customer_id = $customer_id; }
        public function get_customer_id(): int { return $this->customer_id; }
        public function get_id(): int { return 77; }
        public function get_billing_first_name(): string { return 'Order-First'; }
        public function get_billing_last_name(): string { return 'Order-Last'; }
        public function get_billing_phone(): string { return '555-0000'; }
    }
}

global $wpdb;
$wpdb = new wpdb();

$failures = [];
$passed = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}
function assert_true($cond, string $label) {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = "FAIL: $label";
}

// ---------------------------------------------------------------------------
// init() registers the WC status-changed hook
// ---------------------------------------------------------------------------
MealsDB_Private_Intake::init();
assert_true(isset($GLOBALS['mealsdb_registered_actions']['woocommerce_order_status_changed']),
    'init() registers woocommerce_order_status_changed');
$registered = $GLOBALS['mealsdb_registered_actions']['woocommerce_order_status_changed'] ?? null;
assert_equal(4, $registered['argc'] ?? null, 'hook registered with 4 args (id, from, to, order)');

// ---------------------------------------------------------------------------
// checkout-draft → processing triggers promotion; row is inserted
// ---------------------------------------------------------------------------
$order = new WC_Order(501);
MealsDB_Private_Intake::on_order_status_changed(77, 'checkout-draft', 'processing', $order);
assert_equal(1, $wpdb->insert_calls, 'processing status triggers INSERT');

$data = $wpdb->last_insert_data;
assert_equal('Private', $data['client_type'] ?? null, 'new row is client_type=Private');
assert_equal(1, $data['active'] ?? null, 'new row is active=1');
assert_equal(501, $data['wp_user_id'] ?? null, 'wp_user_id matches the WC customer_id');
// billing_first_name takes priority over WP profile first_name and over the order billing
assert_equal('Billing', $data['first_name'] ?? null, 'first_name prefers billing_first_name meta');
assert_equal('Last', $data['last_name'] ?? null, 'last_name prefers billing_last_name meta');
assert_equal('555-1234', $data['client_phone_1'] ?? null, 'phone prefers billing_phone meta');
assert_equal('promoted@example.com', $data['client_email'] ?? null, 'email from WP user');

// ---------------------------------------------------------------------------
// pending is also an active status — same behaviour
// ---------------------------------------------------------------------------
$wpdb->insert_calls = 0;
$order2 = new WC_Order(502);
MealsDB_Private_Intake::on_order_status_changed(88, 'checkout-draft', 'pending', $order2);
assert_equal(1, $wpdb->insert_calls, 'pending status triggers INSERT');

// ---------------------------------------------------------------------------
// completed direct from a WC-external status (e.g. imported) also promotes
// ---------------------------------------------------------------------------
$wpdb->insert_calls = 0;
$order3 = new WC_Order(503);
MealsDB_Private_Intake::on_order_status_changed(99, 'failed', 'completed', $order3);
assert_equal(1, $wpdb->insert_calls, 'failed → completed triggers INSERT');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
