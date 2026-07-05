<?php
/**
 * Tests for MealsDB_Order_Fees — the uniform fee applier that replaced
 * Quick Order's inline fee logic.
 *
 * Load-bearing behaviors:
 *   A. Delivery fee added once, NOT stacked across repeated hook fires.
 *   B. Contribution respects the monthly guard.
 *   C. Private clients get nothing.
 *   D. SDNB with $0 contribution: delivery fee only, month not marked.
 *   E. Normal order (no plugin meta) resolves the client via customer_id.
 *
 * Run: php tests/test-order-fees.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
// This test validates LIVE fee writes, so force shadow mode OFF (the
// fail-safe default is ON, which would suppress everything under test).
if (!defined('MEALSDB_SHADOW_MODE')) { define('MEALSDB_SHADOW_MODE', false); }

if (!class_exists('WC_Product')) {
    class WC_Product {
        protected int $pid;
        // LB-2: a deliberately wrong catalog price. The applier must NOT bill
        // this — it must override each fee line to the per-client amount.
        public float $catalog_price = 999.99;
        public function __construct(int $pid = 0) { $this->pid = $pid; }
        public function get_id() { return $this->pid; }
        public function get_price() { return $this->catalog_price; }
    }
}
if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
        protected int $pid;
        public function __construct(int $pid = 0) { $this->pid = $pid; }
        public function get_product_id() { return $this->pid; }
        public function get_variation_id() { return 0; }
    }
}
if (!class_exists('WC_Order')) {
    class WC_Order {
        public int $id;
        public int $customer_id;
        public array $meta;
        public array $items = [];
        public int $calculate_calls = 0;
        public int $save_calls = 0;
        // LB-2: record the per-line subtotal/total the applier passes so the
        // test can assert each fee line is priced at the client's negotiated
        // amount, not the product catalog price. Keyed by product id.
        public array $line_amounts = [];
        public int $next_item_id = 0;
        public function __construct(int $id = 0, int $customer_id = 0, array $meta = []) {
            $this->id = $id; $this->customer_id = $customer_id; $this->meta = $meta;
        }
        public function get_id() { return $this->id; }
        public function get_customer_id() { return $this->customer_id; }
        public function get_meta($k) { return $this->meta[$k] ?? ''; }
        // BC-2: the applier derives the contribution's billing month from the
        // order's creation timestamp (UTC). A fixed date keeps it deterministic.
        public function get_date_created() {
            return new class {
                public function getTimestamp() { return gmmktime(12, 0, 0, 6, 15, 2026); }
            };
        }
        public function get_items($type = 'line_item') {
            $out = [];
            foreach ($this->items as $pid => $qty) { $out[] = new WC_Order_Item_Product((int) $pid); }
            return $out;
        }
        public function add_product($product, $qty = 1, $args = []) {
            $pid = (int) $product->get_id();
            $this->items[$pid] = ($this->items[$pid] ?? 0) + $qty;
            // Capture the overridden line amount (LB-2). The applier always
            // passes subtotal/total; record total (== subtotal for fees).
            if (isset($args['total'])) {
                $this->line_amounts[$pid] = (float) $args['total'];
            } elseif (isset($args['subtotal'])) {
                $this->line_amounts[$pid] = (float) $args['subtotal'];
            }
            // Modern WC_Order::add_product returns the new item id; the applier
            // treats a falsy return as failure, so hand back a truthy id.
            return ++$this->next_item_id;
        }
        public function calculate_totals() { $this->calculate_calls++; }
        public function save() { $this->save_calls++; }
    }
}

$GLOBALS['__fake_orders'] = [];
$GLOBALS['__fake_products'] = [];
if (!function_exists('wc_get_order')) {
    function wc_get_order($id) { return $GLOBALS['__fake_orders'][$id] ?? false; }
}
if (!function_exists('wc_get_product')) {
    function wc_get_product($id) { return $GLOBALS['__fake_products'][$id] ?? false; }
}
if (!function_exists('__')) { function __($t, $d = 'default') { return $t; } }
if (!function_exists('get_option')) {
    // No saved override -> applier falls back to constants 5675 / 4122.
    function get_option($name, $default = false) { return $default; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

class OrderFeesWpdb {
    public $prefix = 'wp_';
    public array $client;
    public int $contribution_applied = 0;
    public ?int $contribution_order_id = null;
    public function __construct(array $client) { $this->client = $client; }
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[dsf]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . $v . "'" : (string) (int) $v;
        }, $q);
    }
    public function get_var($q) {
        if (stripos($q, 'contribution_applied') !== false && stripos($q, 'SELECT') !== false) {
            return (string) $this->contribution_applied;
        }
        if (stripos($q, 'client_id FROM') !== false) {
            return (string) ($this->client['client_id'] ?? 0);
        }
        return null;
    }
    public function get_row($q, $o = OBJECT) { return $this->client; }
    public function get_col($q, $x = 0) {
        // MAJ-1: the wp_user fallback now resolves the candidate client list
        // via get_col. A single active client is the common (single-program)
        // case, returned straight away with no rate disambiguation.
        if (stripos($q, 'client_id FROM') !== false) {
            $id = (int) ($this->client['client_id'] ?? 0);
            return $id > 0 ? [$id] : [];
        }
        return [];
    }
    public function insert($table, $data, $formats = null) { return 1; }
    public function query($q) {
        // U04 atomic claim: INSERT .. ON DUPLICATE KEY UPDATE on
        // (client_id, billing_month). Emulate MySQL rows-changed semantics
        // (WP connects without CLIENT_FOUND_ROWS): a claim on an UNCLAIMED
        // month wins (returns 1) and tags the carrier order; a claim on an
        // ALREADY-applied month is a conditional no-op (returns 0), which is
        // how claim_contribution_month() detects it must NOT add a second fee.
        if (stripos($q, 'INSERT') !== false && stripos($q, 'contribution_applied') !== false) {
            if ($this->contribution_applied === 1) {
                return 0; // ON DUPLICATE KEY UPDATE IF(applied=0,...) is a no-op
            }
            $this->contribution_applied = 1;
            // contribution_order_id is the 4th VALUES(...) column (the order id).
            if (preg_match("/VALUES\s*\(\s*\d+\s*,\s*'[^']*'\s*,\s*1\s*,\s*(\d+)\s*\)/i", $q, $m)) {
                $this->contribution_order_id = (int) $m[1];
            }
            return 1;
        }
        // release_contribution_claim(): UPDATE .. SET contribution_applied = 0.
        if (stripos($q, 'UPDATE') !== false && stripos($q, 'contribution_applied = 0') !== false) {
            $this->contribution_applied = 0;
            $this->contribution_order_id = null;
            return 1;
        }
        return 1;
    }
}

$failures = [];
$passed = 0;
function check($cond, $label) {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = $label; }
}

function scenario(array $client, int $customer_id, array $meta = []): array {
    $GLOBALS['__fake_orders'] = [];
    $GLOBALS['__fake_products'] = [];
    $GLOBALS['__fake_products'][5675] = new WC_Product(5675);
    $GLOBALS['__fake_products'][4122] = new WC_Product(4122);
    $order = new WC_Order(900, $customer_id, $meta);
    $GLOBALS['__fake_orders'][900] = $order;
    $GLOBALS['wpdb'] = new OrderFeesWpdb($client);
    return [$order, $GLOBALS['wpdb']];
}

[$order, $db] = scenario(
    ['client_id' => 5, 'client_type' => 'SDNB', 'delivery_fee' => 8.50, 'client_contribution' => 40.00],
    700, ['mealsdb_client_id' => 5]
);
MealsDB_Order_Fees::apply_to_order(900);
MealsDB_Order_Fees::apply_to_order(900);
MealsDB_Order_Fees::apply_to_order(900);
check(($order->items[4122] ?? 0) === 1, 'A: delivery fee added exactly once across 3 fires');
check(($order->items[5675] ?? 0) === 1, 'A: contribution added exactly once');
check($db->contribution_applied === 1, 'A: contribution marked applied');
check($db->contribution_order_id === 900, 'A: contribution tagged with order id');
// LB-2: the fee lines must carry the client's per-client amounts, NOT the
// product catalog price (999.99). These are the assertions that previously
// did not exist and let the catalog-price bug hide.
check(($order->line_amounts[4122] ?? null) === 8.50, 'A: delivery fee line priced at per-client 8.50 (not catalog)');
check(($order->line_amounts[5675] ?? null) === 40.00, 'A: contribution line priced at per-client 40.00 (not catalog)');

[$order, $db] = scenario(
    ['client_id' => 6, 'client_type' => 'Veteran', 'delivery_fee' => 5.00, 'client_contribution' => 30.00],
    701, ['mealsdb_client_id' => 6]
);
$db->contribution_applied = 1;
MealsDB_Order_Fees::apply_to_order(900);
check(($order->items[4122] ?? 0) === 1, 'B: delivery fee still applied');
check(!isset($order->items[5675]), 'B: contribution not re-applied when month marked');

[$order, $db] = scenario(
    ['client_id' => 7, 'client_type' => 'Private', 'delivery_fee' => 8.00, 'client_contribution' => 0.0],
    702, ['mealsdb_client_id' => 7]
);
MealsDB_Order_Fees::apply_to_order(900);
check(empty($order->items), 'C: Private client gets no program fees');
check($order->save_calls === 0, 'C: order not saved when nothing applied');

[$order, $db] = scenario(
    ['client_id' => 8, 'client_type' => 'SDNB', 'delivery_fee' => 6.00, 'client_contribution' => 0.0],
    703, ['mealsdb_client_id' => 8]
);
MealsDB_Order_Fees::apply_to_order(900);
check(($order->items[4122] ?? 0) === 1, 'D: delivery fee applied');
check(!isset($order->items[5675]), 'D: no contribution when $0');
check($db->contribution_applied === 0, 'D: month not marked when nothing due');

[$order, $db] = scenario(
    ['client_id' => 9, 'client_type' => 'SDNB', 'delivery_fee' => 7.25, 'client_contribution' => 0.0],
    704, []
);
MealsDB_Order_Fees::apply_to_order(900);
check(($order->items[4122] ?? 0) === 1, 'E: normal order gets delivery fee via customer_id fallback');

// F: arbitrary per-client amounts (distinct from each other and from the
// catalog price) must flow through to the order's fee line subtotals/totals.
[$order, $db] = scenario(
    ['client_id' => 10, 'client_type' => 'SDNB', 'delivery_fee' => 12.34, 'client_contribution' => 55.67],
    705, ['mealsdb_client_id' => 10]
);
MealsDB_Order_Fees::apply_to_order(900);
check(($order->line_amounts[4122] ?? null) === 12.34, 'F: delivery fee line reflects per-client 12.34');
check(($order->line_amounts[5675] ?? null) === 55.67, 'F: contribution line reflects per-client 55.67');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
