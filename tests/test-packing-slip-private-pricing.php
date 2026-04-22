<?php
/**
 * Packing slip entries carry a pricing payload for Private customers
 * and null for SDNB / Veteran. Exercises the private build_pricing_for_entry
 * helper via reflection so we don't need a full order fixture.
 *
 * Run with: php tests/test-packing-slip-private-pricing.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public function prepare($query, ...$args) { return $query; }
        public function get_row($query, $output = OBJECT) { return null; }
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function get_col($query, $x = 0) { return []; }
        public function get_results($query, $output = OBJECT) { return []; }
        public function query($query) { return 0; }
    }
}
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
}

// WC_Order / item stubs.
if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
        public int $product_id;
        public string $name;
        public int $quantity;
        public float $subtotal;
        public function __construct(int $pid, string $name, int $qty, float $sub) {
            $this->product_id = $pid; $this->name = $name;
            $this->quantity = $qty; $this->subtotal = $sub;
        }
        public function get_product_id(): int { return $this->product_id; }
        public function get_name(): string { return $this->name; }
        public function get_quantity(): int { return $this->quantity; }
        public function get_subtotal(): float { return $this->subtotal; }
    }
}
if (!class_exists('WC_Order')) {
    class WC_Order {
        public array $items = [];
        public float $subtotal = 33.23;
        public float $tax = 4.98;
        public float $total = 38.21;
        public string $payment_method = 'cash';
        public function add(WC_Order_Item_Product $item): void { $this->items[] = $item; }
        public function get_items(): array { return $this->items; }
        public function get_subtotal(): float { return $this->subtotal; }
        public function get_total_tax(): float { return $this->tax; }
        public function get_total(): float { return $this->total; }
        public function get_payment_method(): string { return $this->payment_method; }
    }
}

// Build a fake order.
$wc_order = new WC_Order();
$wc_order->add(new WC_Order_Item_Product(101, 'Chicken Parmesan', 3, 25.50));
$wc_order->add(new WC_Order_Item_Product(103, 'Rice Pudding', 1, 3.25));
$wc_order->add(new WC_Order_Item_Product(102, 'Garden Salad', 1, 4.48));

$GLOBALS['_stub_wc_order'] = $wc_order;
if (!function_exists('wc_get_order')) {
    function wc_get_order($id) { return $GLOBALS['_stub_wc_order']; }
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

// Build the generator and invoke the private helper.
$generator = (new ReflectionClass('MealsDB_Delivery_Slip_Generator'))->newInstanceWithoutConstructor();
$method = new ReflectionMethod('MealsDB_Delivery_Slip_Generator', 'build_pricing_for_entry');
$method->setAccessible(true);

// Private client with cash payment.
$order = ['order_id' => 77, 'items' => []];
$private_client = [
    'client_type'    => 'Private',
    'delivery_fee'   => 10.00,
    'payment_method' => 'cash',
];
$pricing = $method->invoke($generator, $order, $private_client);
assert_equal(true, is_array($pricing), 'Private + cash: pricing is an array');
assert_equal(3, count($pricing['items']), 'pricing.items has one entry per line');
assert_equal(33.23, $pricing['subtotal'], 'subtotal pulled from WC order');
assert_equal(4.98, $pricing['tax'], 'tax pulled from WC order');
assert_equal(38.21, $pricing['grand_total'], 'grand_total pulled from WC order');
assert_equal(10.00, $pricing['delivery_fee'], 'delivery_fee from client record');
assert_equal('cash', $pricing['payment_method'], 'cash payment_method');
assert_equal(48.21, $pricing['collection_amount'], 'cash: collection = total + fee');
assert_equal(false, $pricing['is_prepaid'], 'cash: not prepaid');

// Items sorted by product id ascending.
$ids = array_column($pricing['items'], 'wc_product_id');
assert_equal([101, 102, 103], $ids, 'pricing.items sorted by wc_product_id asc');

// Non-private: pricing is null.
$sdnb_client = ['client_type' => 'SDNB', 'delivery_fee' => 10.00, 'payment_method' => 'cash'];
$pricing_sdnb = $method->invoke($generator, $order, $sdnb_client);
assert_equal(null, $pricing_sdnb, 'SDNB client: pricing is null');

$vet_client = ['client_type' => 'Veteran', 'delivery_fee' => 10.00, 'payment_method' => 'cash'];
$pricing_vet = $method->invoke($generator, $order, $vet_client);
assert_equal(null, $pricing_vet, 'Veteran client: pricing is null');

// Private + stripe (non-cash) with fee: collect only the fee.
$stripe_client = ['client_type' => 'Private', 'delivery_fee' => 7.50, 'payment_method' => 'stripe'];
$wc_order->payment_method = 'stripe'; // echo through
$pricing_stripe = $method->invoke($generator, $order, $stripe_client);
assert_equal(7.50, $pricing_stripe['collection_amount'], 'stripe + fee: collect = fee only');
assert_equal(false, $pricing_stripe['is_prepaid'], 'stripe + fee: not prepaid');

// Private + prepaid (no fee, non-cash): nothing to collect.
$prepaid_client = ['client_type' => 'Private', 'delivery_fee' => 0.00, 'payment_method' => 'bank'];
$pricing_prepaid = $method->invoke($generator, $order, $prepaid_client);
assert_equal(null, $pricing_prepaid['collection_amount'], 'prepaid: collection_amount null');
assert_equal(true, $pricing_prepaid['is_prepaid'], 'prepaid: is_prepaid true');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
