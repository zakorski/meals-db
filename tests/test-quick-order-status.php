<?php
/**
 * Quick Order must create orders in a slip-eligible (active) status
 * (DIRECTIVE-quick-order-status-fix.md).
 *
 * wc_create_order() defaults new orders to wc-pending, which the slip
 * query excludes and which never transitions — so QO orders were absent
 * from every slip run and never received their fee/contribution lines
 * (woocommerce_new_order fires inside wc_create_order() on a still-empty
 * order, so the fee applier no-ops at creation). create_wc_order() must
 * set `processing` so the caller's save() fires the pending->processing
 * transition the fee/allocation reprocess branch listens for.
 *
 * Asserts:
 *  1. The happy path returns an order set to `processing`, exactly once,
 *     with the Quick Order note.
 *  2. The rejection paths (no valid products, negative total) delete the
 *     order WITHOUT ever activating it (guards stay before the status set).
 *  3. The slip-eligibility tie: `wc-processing` is absent from
 *     MealsDB_WC_Order_Query::get_orders_for_users()'s default excluded
 *     statuses while `wc-pending` is present — i.e. the created status is
 *     slip-eligible where the old default was not.
 *
 * Run: php tests/test-quick-order-status.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// The dropped-item and negative-total paths error_log(); keep test output clean.
ini_set('error_log', '/dev/null');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('add_action')) { function add_action(...$a) {} }
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0) { return json_encode($d, $f); } }
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(public string $code = '', public string $message = '') {}
        public function get_error_message(): string { return $this->message; }
    }
}

$failures = []; $passed = 0;
function qos_check(string $label, $actual, $expected): void {
    global $failures, $passed;
    if ($actual === $expected) { $passed++; return; }
    $failures[] = sprintf("%s:\n  expected %s\n  got      %s", $label, var_export($expected, true), var_export($actual, true));
}

// ---- WC doubles -----------------------------------------------------------
// create_wc_order() only needs class_exists('WC_Order') and is_wp_error();
// it never instanceof-checks the order itself, so a recording fake suffices.
// Every fake wc_create_order() hands out is kept in $qos_orders so rejection
// paths (which return WP_Error, not the order) can still be inspected.
class WC_Order {}
class QosFakeOrder extends WC_Order {
    public string $status = 'pending'; // wc_create_order() default
    public array $status_calls = [];   // [status, note] per set_status()
    public bool $deleted = false;
    public float $total;
    public function __construct(float $total = 25.0) { $this->total = $total; }
    public function set_customer_id($id): void {}
    public function add_product($product, $qty, $args = []): void {}
    public function calculate_totals(): void {}
    public function get_total() { return $this->total; }
    public function get_id(): int { return 27700; }
    public function get_status(): string { return $this->status; }
    public function set_status($status, $note = ''): void {
        $this->status_calls[] = [$status, $note];
        $this->status = $status;
    }
    public function set_date_created($date): void {}
    public function delete($force = false): void { $this->deleted = true; }
    // Absorb set_billing_*/set_shipping_* calls from apply_client_address_to_order().
    public function __call(string $name, array $args): void {}
}

// MealsDB_Clients_Repository needs $wpdb which is absent in the test harness.
// Return a null client row; apply_client_address_to_order() handles null cleanly.
// Second arg false: don't trigger the autoloader — we're the stub.
if (!class_exists('MealsDB_Clients_Repository', false)) {
    class MealsDB_Clients_Repository {
        public function get_client_by_id(int $id): ?array { return null; }
    }
}
class WC_Product {
    public function get_status(): string { return 'publish'; }
}
class WC_Product_Variation extends WC_Product {}

$qos_orders     = [];   // every fake order created, in creation order
$qos_next_total = 25.0; // total the NEXT created fake order will report
function wc_create_order() {
    global $qos_orders, $qos_next_total;
    $order = new QosFakeOrder($qos_next_total);
    $qos_orders[] = $order;
    return $order;
}
// Product 100 resolves; anything else doesn't (drops the line).
function wc_get_product($id) {
    return ((int) $id === 100) ? new WC_Product() : null;
}

$create = new ReflectionMethod('MealsDB_Quick_Order_Ajax', 'create_wc_order');
$create->setAccessible(true);

// ---- 1. Happy path: order comes back active -------------------------------
$items = [['product_id' => 100, 'variation_id' => 0, 'quantity' => 2]];
$order = $create->invokeArgs(null, [$items, null, 5, 42]);

qos_check('happy: returns the order, not WP_Error', $order instanceof QosFakeOrder, true);
qos_check('happy: status is processing', $order->status, 'processing');
qos_check('happy: set_status called exactly once', count($order->status_calls), 1);
qos_check('happy: status note names Quick Order',
    isset($order->status_calls[0][1]) && stripos((string) $order->status_calls[0][1], 'quick order') !== false,
    true
);
qos_check('happy: order not deleted', $order->deleted, false);

// ---- 2. Rejection paths never activate the order --------------------------
// No resolvable products -> WP_Error + delete, and set_status must never run
// (don't activate an order you're about to reject).
$items  = [['product_id' => 999, 'variation_id' => 0, 'quantity' => 1]];
$result = $create->invokeArgs(null, [$items, null, 5, 42]);
$reject = end($qos_orders);
qos_check('no-products: returns WP_Error', $result instanceof WP_Error, true);
qos_check('no-products: order deleted', $reject->deleted, true);
qos_check('no-products: set_status never called', $reject->status_calls, []);

// Negative total -> WP_Error + delete, never activated.
$qos_next_total = -5.0;
$items  = [['product_id' => 100, 'variation_id' => 0, 'quantity' => 1]];
$result = $create->invokeArgs(null, [$items, null, 5, 42]);
$reject = end($qos_orders);
qos_check('negative-total: returns WP_Error', $result instanceof WP_Error, true);
qos_check('negative-total: order deleted', $reject->deleted, true);
qos_check('negative-total: set_status never called', $reject->status_calls, []);
$qos_next_total = 25.0;

// ---- 3. Slip-eligibility tie ----------------------------------------------
// The status QO now creates with must be slip-eligible where the old default
// was not: `wc-processing` absent from the order query's default excluded
// list, `wc-pending` present. Read the default straight off the signature so
// this stays true if the list ever moves.
$ref = new ReflectionMethod('MealsDB_WC_Order_Query', 'get_orders_for_users');
$excluded = null;
foreach ($ref->getParameters() as $p) {
    if ($p->getName() === 'exclude_statuses') { $excluded = $p->getDefaultValue(); }
}
qos_check('slip query exposes a default excluded-status list', is_array($excluded), true);
qos_check('wc-pending is slip-EXCLUDED', in_array('wc-pending', (array) $excluded, true), true);
qos_check('wc-processing is slip-ELIGIBLE', in_array('wc-processing', (array) $excluded, true), false);
// And the tie itself: the status the happy-path order ended up in is eligible.
qos_check('created status is slip-eligible', in_array('wc-' . $order->status, (array) $excluded, true), false);

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
