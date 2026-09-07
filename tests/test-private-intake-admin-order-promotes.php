<?php
/**
 * DIRECTIVE K3 regression: an order that reaches its first active state via
 * WooCommerce's "Add order" admin screen must still promote.
 *
 * WC "Add order" creates the order at 'pending' WITHOUT firing
 * woocommerce_order_status_changed, so the first status transition the plugin
 * ever sees is the operator's move to Processing — arriving as
 * from='pending', to='processing'. Both are in ACTIVE_STATUSES. The old
 * intra-active guard (return when $from was also active) discarded exactly
 * this transition, so a government client ordering this way got no client
 * record and billed nothing.
 *
 * This test drives that precise transition and asserts a meals_clients INSERT
 * happens. It would FAIL against the pre-K3 code (guard → 0 inserts).
 *
 * Run with: php tests/test-private-intake-admin-order-promotes.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// wpdb stub: no existing client (get_row → null) so promotion runs to INSERT;
// count only meals_clients inserts (Event_Log also writes rows during promotion).
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
            if (stripos($query, 'information_schema') !== false) { return 1; }
            return null;
        }
        public function get_col($query, $x = 0) { return []; }
        public function get_results($query, $output = OBJECT) { return []; }
        public function query($query) { return 0; }
        public function insert($table, $data) {
            if (strpos((string) $table, 'meals_clients') !== false) {
                $this->insert_calls++;
                $this->last_insert_data = $data;
            }
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
        public $user_email = 'gov3405@example.com';
        public $first_name = 'Gov';
        public $last_name  = 'Client';
    }
}
if (!function_exists('get_userdata')) {
    function get_userdata($id) { return $id > 0 ? new WP_User() : null; }
}
// customer_group = sdnb so the promotion resolves an SDNB record (the exact
// shape the directive describes for user 3405).
if (!function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single = true) {
        $fixtures = [
            'billing_first_name' => 'Gov',
            'billing_last_name'  => 'Client',
            'billing_phone'      => '506-555-3405',
            'customer_group'     => 'sdnb',
            'nickname'           => 'gcl',
        ];
        return $fixtures[$key] ?? '';
    }
}
if (!function_exists('current_time')) {
    function current_time($type) { return '2026-09-07 12:00:00'; }
}
if (!function_exists('current_user_can')) { function current_user_can($cap) { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value) { return json_encode($value); }
}

if (!class_exists('WC_Order')) {
    class WC_Order {
        private $customer_id;
        public function __construct(int $customer_id = 0) { $this->customer_id = $customer_id; }
        public function get_customer_id(): int { return $this->customer_id; }
        public function get_id(): int { return 29322; }
        public function get_billing_first_name(): string { return 'Gov'; }
        public function get_billing_last_name(): string { return 'Client'; }
        public function get_billing_phone(): string { return '506-555-3405'; }
        public function get_billing_address_1(): string { return '1 Government Rd'; }
        public function get_billing_city(): string { return 'Moncton'; }
        public function get_billing_state(): string { return 'NB'; }
        public function get_billing_postcode(): string { return 'E1A 1A1'; }
        public function get_shipping_address_1(): string { return '1 Government Rd'; }
        public function get_shipping_address_2(): string { return 'Zone 1'; }
        public function get_shipping_city(): string { return 'Moncton'; }
        public function get_shipping_state(): string { return 'NB'; }
        public function get_shipping_postcode(): string { return 'E1A 1A1'; }
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

// The exact failing case: pending -> processing, BOTH active.
$order = new WC_Order(3405);
MealsDB_Private_Intake::on_order_status_changed(29322, 'pending', 'processing', $order);
assert_equal(1, $wpdb->insert_calls, 'pending->processing (both active) now promotes — the intra-active guard is gone');

$data = $wpdb->last_insert_data;
assert_equal('SDNB', $data['client_type'] ?? null, 'customer_group=sdnb resolves client_type=SDNB');
assert_equal(3405, $data['wp_user_id'] ?? null, 'wp_user_id matches the WC customer_id');
assert_equal(1, $data['active'] ?? null, 'promoted row is active=1');

// Idempotency at the handler level: a SECOND active transition for a user who
// now has a record must NOT insert again. Re-point get_row at an existing row.
$wpdb2 = new class extends wpdb {
    public function get_row($query, $output = OBJECT) {
        return [
            'client_id'   => 555,
            'wp_user_id'  => 3405,
            'client_type' => 'SDNB',
            'first_name'  => 'Gov',
            'last_name'   => 'Client',
        ];
    }
};
$GLOBALS['wpdb'] = $wpdb2;
MealsDB_Private_Intake::on_order_status_changed(29322, 'processing', 'completed', $order);
assert_equal(0, $wpdb2->insert_calls, 'processing->completed for an existing client issues no second INSERT');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
