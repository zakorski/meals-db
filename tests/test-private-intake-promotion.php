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
// Per-uid nickname overrides; falls through to the shared fixtures
// when not set. Lets each promotion call exercise a different
// nickname value without redefining get_user_meta.
$GLOBALS['nickname_override'] = [];
if (!function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single = true) {
        if ($key === 'nickname' && isset($GLOBALS['nickname_override'][$uid])) {
            return $GLOBALS['nickname_override'][$uid];
        }
        $fixtures = [
            'billing_first_name'      => 'Billing',
            'billing_last_name'       => 'Last',
            'billing_phone'           => '555-1234',
            // Usermeta-only fallbacks for users who have a WP profile
            // address but aren't placing the triggering order (e.g.
            // profile-saved addresses). The promotion path still
            // prefers $order when one is supplied — see the user 88
            // assertions below.
            'shipping_city'           => 'Meta-Ship-City',
            // Service / ordering / notes meta — pulled by the
            // wp-custom-user-fields-map mapping. These have no order
            // source; they only flow from usermeta.
            'payment_method'          => 'Stripe',
            'ordering_frequency'      => '7',
            'ordering_contact_method' => 'Email',
            'delivery_frequency'      => '14',
            'freeze_capacity'         => 'Medium',
            'delivery_fee'            => '5.50',
            'customer_comments'       => 'Likes the front porch',
            'dietary_needs'           => 'No shellfish',
            'nickname'                => 'abc', // lowercased deliberately — should be uppercased
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
        public function get_billing_address_1(): string { return '12 Order Lane'; }
        public function get_billing_city(): string { return 'Order City'; }
        public function get_billing_state(): string { return 'NB'; }
        public function get_billing_postcode(): string { return 'E1A 1A1'; }
        public function get_shipping_address_1(): string { return '34 Ship Way'; }
        public function get_shipping_address_2(): string { return 'Zone 3'; }
        public function get_shipping_city(): string { return 'Ship City'; }
        public function get_shipping_state(): string { return 'NS'; }
        public function get_shipping_postcode(): string { return 'B2B 2B2'; }
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

// Address fields: $order beats usermeta (the get_user_meta stub
// returns '' for the billing_/shipping_ address keys, so the order
// values flow through). For shipping_city the stub returns
// 'Meta-Ship-City' but the order's 'Ship City' wins per resolve_address
// priority order.
assert_equal('12 Order Lane', $data['street_name'] ?? null, 'street_name from order billing_address_1');
assert_equal('Order City', $data['city'] ?? null, 'city from order billing_city');
assert_equal('NB', $data['province'] ?? null, 'province from order billing_state');
assert_equal('E1A 1A1', $data['postal_code'] ?? null, 'postal_code from order billing_postcode');
assert_equal('34 Ship Way', $data['delivery_street_name'] ?? null, 'delivery_street_name from order shipping_address_1');
assert_equal('Ship City', $data['delivery_city'] ?? null, 'delivery_city: order beats usermeta when both set');
assert_equal('NS', $data['delivery_province'] ?? null, 'delivery_province from order shipping_state');
assert_equal('B2B 2B2', $data['delivery_postal_code'] ?? null, 'delivery_postal_code from order shipping_postcode');

// Delivery zone (shipping_address_2) — order priority again.
assert_equal('Zone 3', $data['delivery_area_name'] ?? null, 'delivery_area_name from order shipping_address_2');

// Service / ordering / notes — usermeta-only mappings.
assert_equal('Stripe', $data['payment_method'] ?? null, 'payment_method from usermeta');
assert_equal(7, $data['ordering_frequency'] ?? null, 'ordering_frequency cast to int');
assert_equal('Email', $data['ordering_contact_method'] ?? null, 'ordering_contact_method from usermeta');
assert_equal(14, $data['delivery_frequency'] ?? null, 'delivery_frequency cast to int');
assert_equal('Medium', $data['freezer_capacity'] ?? null, 'freezer_capacity from freeze_capacity meta');
assert_equal('5.50', $data['delivery_fee'] ?? null, 'delivery_fee normalized to 2dp string');
// customer_comments / diet_concerns are encrypted by the repository
// before insert, so they no longer match the plaintext fixture here.
// Just check the columns are populated and aren't the plaintext.
assert_true(isset($data['customer_comments']) && $data['customer_comments'] !== '', 'customer_comments populated');
assert_true(isset($data['customer_comments']) && $data['customer_comments'] !== 'Likes the front porch', 'customer_comments encrypted on insert');
assert_true(isset($data['diet_concerns']) && $data['diet_concerns'] !== '', 'diet_concerns populated from dietary_needs meta');
assert_true(isset($data['diet_concerns']) && $data['diet_concerns'] !== 'No shellfish', 'diet_concerns encrypted on insert');

// Bag initials from wp_usermeta.nickname — uppercased, exact-3-letters only.
assert_equal('ABC', $data['delivery_initials'] ?? null, 'delivery_initials uppercased from nickname meta');

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

// ---------------------------------------------------------------------------
// maybe_promote called without an order falls back to WP usermeta only.
// shipping_city is the one address field the stub seeds, so it should
// land in delivery_city; address fields with no usermeta source stay
// empty. Service / notes meta still flow through unchanged.
// ---------------------------------------------------------------------------
$wpdb->insert_calls = 0;
$wpdb->last_insert_data = [];
MealsDB_Private_Intake::maybe_promote(404, null);
assert_equal(1, $wpdb->insert_calls, 'maybe_promote(uid, null) still INSERTs');
$nodata = $wpdb->last_insert_data;
assert_equal('', $nodata['street_name'] ?? null, 'no order, no usermeta → street_name blank');
assert_equal('Meta-Ship-City', $nodata['delivery_city'] ?? null, 'no order → delivery_city falls back to shipping_city usermeta');
assert_equal('', $nodata['delivery_street_name'] ?? null, 'no order, no usermeta → delivery_street_name blank');
assert_equal('Stripe', $nodata['payment_method'] ?? null, 'payment_method usermeta still flows without order');
assert_equal(7, $nodata['ordering_frequency'] ?? null, 'ordering_frequency usermeta still flows without order');
assert_true(isset($nodata['customer_comments']) && $nodata['customer_comments'] !== '', 'customer_comments still flows without order');

// ---------------------------------------------------------------------------
// Nickname → delivery_initials format validation. Only exact 3-letter
// values land in the column; anything else is treated as unfilled.
// ---------------------------------------------------------------------------
$cases = [
    ['nickname' => 'AB',    'expected_present' => false, 'label' => 'too-short nickname dropped'],
    ['nickname' => 'ABCD',  'expected_present' => false, 'label' => 'too-long nickname dropped'],
    ['nickname' => 'A1B',   'expected_present' => false, 'label' => 'non-letter nickname dropped'],
    ['nickname' => 'a b',   'expected_present' => false, 'label' => 'space inside nickname dropped'],
    ['nickname' => 'Bob',   'expected' => 'BOB',         'label' => 'mixed-case 3-letter nickname uppercased'],
    ['nickname' => '  jdoe ',  'expected_present' => false, 'label' => 'trim-then-too-long nickname dropped'],
    ['nickname' => '  abc  ', 'expected' => 'ABC',       'label' => 'trim-then-3-letter nickname accepted'],
];
$next_uid = 4040;
foreach ($cases as $case) {
    $uid = $next_uid++;
    $GLOBALS['nickname_override'][$uid] = $case['nickname'];
    $wpdb->insert_calls = 0;
    $wpdb->last_insert_data = [];
    MealsDB_Private_Intake::maybe_promote($uid, null);
    $row = $wpdb->last_insert_data;
    if (array_key_exists('expected', $case)) {
        assert_equal($case['expected'], $row['delivery_initials'] ?? null, $case['label']);
    } else {
        assert_true(!array_key_exists('delivery_initials', $row), $case['label']);
    }
}

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
