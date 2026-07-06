<?php
/**
 * Integration test for the PO workflow chain:
 *   place_po completes → meals_purchase_orders row + confirm_po_arrival task
 *   confirm_po_arrival completes (arrived=yes) → PO status becomes 'arrived',
 *     inventory bumped, physical_count task spawned
 *   physical_count completes → PO status becomes 'reconciled',
 *     stock adjusted by delta
 *
 * Run with: php tests/test-task-workflow-po-chain.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $tag, $v, ...$a) { return $v; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 0; } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!function_exists('current_time')) { function current_time($fmt) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('get_option')) { function get_option($k, $d = '') { return $d; } }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public int $insert_id = 0;
        public function prepare($q, ...$a) { return $q; }
        public function get_results($q, $o = 'OBJECT') { return []; }
        public function get_row($q, $o = 'OBJECT', $y = 0) { return null; }
        public function query($q) { return 0; }
        public function insert($t, $d, $f = null) { return 1; }
        public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        public function delete($t, $w, $f = null) { return 1; }
    }
}

// --- WooCommerce stub — lightweight SKU→stock registry ---
class FakeWCProduct {
    public int $product_id;
    public int $stock;
    public function __construct(int $id, int $stock) { $this->product_id = $id; $this->stock = $stock; }
    public function get_stock_quantity() { return $this->stock; }
    public function set_stock_quantity($q) { $this->stock = (int) $q; }
    public function save() { $GLOBALS['wc_stock'][$this->product_id] = $this->stock; }
}
$GLOBALS['wc_sku_map'] = ['CD-001' => 101, 'CD-002' => 102];
$GLOBALS['wc_stock']   = [101 => 50, 102 => 20];
if (!function_exists('wc_get_product_id_by_sku')) {
    function wc_get_product_id_by_sku($sku) {
        return $GLOBALS['wc_sku_map'][$sku] ?? 0;
    }
}
if (!function_exists('wc_get_product')) {
    function wc_get_product($id) {
        if (!isset($GLOBALS['wc_stock'][$id])) { return null; }
        return new FakeWCProduct($id, $GLOBALS['wc_stock'][$id]);
    }
}
// U18-tasks-ui-3: apply_inventory_bump now uses WooCommerce's atomic
// wc_update_product_stock($product, $amount, 'increase') (SQL stock=stock+amount)
// instead of a read-modify-write set/save. Model it against the registry so the
// bump is applied and returns the new total (null when the product manages no
// stock).
if (!function_exists('wc_update_product_stock')) {
    function wc_update_product_stock($product, $amount = null, $mode = 'set') {
        $id = $product->product_id;
        if (!isset($GLOBALS['wc_stock'][$id])) { return null; }
        $current = (int) $GLOBALS['wc_stock'][$id];
        if ($mode === 'increase')      { $new = $current + (int) $amount; }
        elseif ($mode === 'decrease')  { $new = $current - (int) $amount; }
        else                           { $new = (int) $amount; }
        $GLOBALS['wc_stock'][$id] = $new;
        $product->stock = $new;
        return $new;
    }
}

// --- Fake in-memory wpdb that backs meals_tasks, meals_purchase_orders, meals_audit_log ---
class PoChainWpdb extends wpdb {
    public array $tasks = [];
    public array $pos = [];
    public int $next_task_id = 1;
    public int $next_po_id = 1;
    public int $insert_id = 0;
    public array $audit = [];
    public function __construct() {}

    public function prepare($q, ...$a) {
        if (!empty($a) && is_array($a[0] ?? null)) { $a = $a[0]; }
        return ['sql' => $q, 'args' => $a];
    }
    public function insert($table, $data, $format = null) {
        if (strpos((string) $table, 'meals_tasks') !== false) {
            $data['task_id'] = $this->next_task_id++;
            $this->tasks[$data['task_id']] = $data;
            $this->insert_id = $data['task_id'];
            return 1;
        }
        if (strpos((string) $table, 'meals_purchase_orders') !== false) {
            $data['po_id'] = $this->next_po_id++;
            $this->pos[$data['po_id']] = $data;
            $this->insert_id = $data['po_id'];
            return 1;
        }
        return 1;
    }
    public function update($table, $data, $where, $f = null, $wf = null) {
        if (strpos((string) $table, 'meals_tasks') !== false) {
            $id = (int) ($where['task_id'] ?? 0);
            if (isset($this->tasks[$id])) {
                foreach ($data as $k => $v) { $this->tasks[$id][$k] = $v; }
                return 1;
            }
        }
        if (strpos((string) $table, 'meals_purchase_orders') !== false) {
            $id = (int) ($where['po_id'] ?? 0);
            if (isset($this->pos[$id])) {
                foreach ($data as $k => $v) { $this->pos[$id][$k] = $v; }
                return 1;
            }
        }
        return 0;
    }
    public function get_row($q, $o = 'OBJECT', $y = 0) {
        if (is_array($q)) { $sql = $q['sql']; $args = $q['args']; }
        else { $sql = $q; $args = []; }
        $id = (int) ($args[0] ?? 0);
        if (stripos($sql, 'meals_tasks') !== false) {
            return $this->tasks[$id] ?? null;
        }
        if (stripos($sql, 'meals_purchase_orders') !== false) {
            return $this->pos[$id] ?? null;
        }
        return null;
    }
    public function get_results($q, $o = 'OBJECT') { return []; }
    public function query($q) {
        if (is_array($q)) { $sql = $q['sql']; $args = $q['args']; }
        else { $sql = (string) $q; $args = []; }
        if (stripos($sql, 'meals_audit_log') !== false && stripos($sql, 'INSERT INTO') !== false) {
            $this->audit[] = $args;
            return 1;
        }
        return 0;
    }
}

// --- Register all task types ---
MealsDB_Task_Registry::reset();
MealsDB_Task_Type_Generic_Reminder::register();
MealsDB_Task_Type_Place_PO::register();
MealsDB_Task_Type_Confirm_PO_Arrival::register();
MealsDB_Task_Type_Physical_Count::register();

$fake = new PoChainWpdb();
$GLOBALS['wpdb'] = $fake;
$engine = new MealsDB_Task_Engine($fake);
$po_service = new MealsDB_Purchase_Orders($fake);

$failures = [];
$passed = 0;
function assert_equals($a, $e, string $l) {
    global $failures, $passed;
    if ($a === $e) { $passed++; }
    else { $failures[] = sprintf('[%s] expected %s got %s', $l, var_export($e, true), var_export($a, true)); }
}
function assert_true($v, $l) { assert_equals((bool) $v, true, $l); }

// Step 1: create place_po task (as if spawned by rule).
$place_id = $engine->create_task([
    'task_type'     => 'place_po',
    'payload'       => ['supplier' => 'Appetito'],
    'next_run_date' => '2026-04-22',
    'assignee_role' => 'admin',
]);
assert_true($place_id > 0, 'place_po task created');

// Step 2: operator completes place_po.
$ok = $engine->complete_task($place_id, [
    'supplier'         => 'Appetito',
    'po_number'        => 'PO-9001',
    'placed_date'      => '2026-04-22',
    'expected_arrival' => '2026-04-29',
    'items_csv'        => "CD-001, Chicken Dinner, 48\nCD-002, Fish Dinner, 24",
    'notes'            => 'first batch',
], 42);
assert_equals($ok, true, 'place_po complete succeeds');

// The PO row should now exist.
assert_equals(count($fake->pos), 1, '1 PO created');
$po = array_values($fake->pos)[0];
assert_equals($po['po_number'], 'PO-9001', 'PO number saved');
assert_equals($po['status'], 'placed', 'PO status is placed');
$items = json_decode((string) $po['items'], true);
assert_equals(count($items), 2, '2 items parsed from CSV');
assert_equals($items[0]['sku'], 'CD-001', 'first item SKU');
assert_equals((int) $items[0]['quantity_ordered'], 48, 'first item qty');

// A follow-up confirm_po_arrival task should have been spawned.
$arrival_task = null;
foreach ($fake->tasks as $t) {
    if ($t['task_type'] === 'confirm_po_arrival') { $arrival_task = $t; break; }
}
assert_true($arrival_task !== null, 'confirm_po_arrival task spawned');
assert_equals($arrival_task['next_run_date'], '2026-04-29', 'arrival task due on expected_arrival');
assert_equals((int) $arrival_task['related_entity_id'], $po['po_id'], 'arrival task linked to PO');

// Step 3: operator completes confirm_po_arrival with arrived=yes.
$before_stock_101 = $GLOBALS['wc_stock'][101];
$before_stock_102 = $GLOBALS['wc_stock'][102];

$ok = $engine->complete_task($arrival_task['task_id'], [
    'po_number'        => 'PO-9001',
    'expected_arrival' => '2026-04-29',
    'arrived'          => 'yes',
    'arrival_date'     => '2026-04-30',
    'complete_order'   => 'yes',
], 42);
assert_equals($ok, true, 'arrival complete succeeds');

// PO status advanced, inventory bumped by ordered quantities.
$po_fresh = $po_service->get($po['po_id']);
assert_equals($po_fresh['status'], 'arrived', 'PO status is arrived');
assert_equals($po_fresh['arrival_date'], '2026-04-30', 'arrival_date saved');
assert_equals($GLOBALS['wc_stock'][101], $before_stock_101 + 48, 'stock 101 bumped by 48');
assert_equals($GLOBALS['wc_stock'][102], $before_stock_102 + 24, 'stock 102 bumped by 24');

// A physical_count task should have spawned for +7 days after arrival.
$count_task = null;
foreach ($fake->tasks as $t) {
    if ($t['task_type'] === 'physical_count') { $count_task = $t; break; }
}
assert_true($count_task !== null, 'physical_count task spawned');
assert_equals($count_task['next_run_date'], '2026-05-07', 'physical_count due +7 days from arrival');

// Step 4: physical_count with an adjustment — actual count is 46 instead of 48
// on CD-001 (2 damaged), matches on CD-002.
$count_stock_101 = $GLOBALS['wc_stock'][101];
$count_stock_102 = $GLOBALS['wc_stock'][102];

$ok = $engine->complete_task($count_task['task_id'], [
    'po_number'      => 'PO-9001',
    'count_received' => 'yes',
    'sku_adjustments' => [
        ['sku' => 'CD-001', 'quantity_ordered' => 48, 'actual_count' => 46, 'reason' => 'damaged', 'reason_notes' => ''],
        ['sku' => 'CD-002', 'quantity_ordered' => 24, 'actual_count' => 24],
    ],
    'overall_notes'  => 'two boxes crushed in shipping',
], 42);
assert_equals($ok, true, 'physical_count complete succeeds');

// Inventory delta applied only for mismatched SKU.
assert_equals($GLOBALS['wc_stock'][101], $count_stock_101 - 2, 'stock 101 reduced by 2');
assert_equals($GLOBALS['wc_stock'][102], $count_stock_102, 'stock 102 unchanged');

// PO reconciled.
$po_reconciled = $po_service->get($po['po_id']);
assert_equals($po_reconciled['status'], 'reconciled', 'PO reconciled');
assert_true(!empty($po_reconciled['reconciled_at']), 'reconciled_at timestamp set');

// Deferral path: arrived='no' should auto-defer instead of completing.
$defer_task_id = $engine->create_task([
    'task_type'           => 'confirm_po_arrival',
    'payload'             => ['po_number' => 'PO-9002'],
    'next_run_date'       => '2026-05-01',
    'related_entity_type' => 'po',
    'related_entity_id'   => 999,
]);
$engine->complete_task($defer_task_id, ['arrived' => 'no', 'notes' => 'truck delayed'], 42);
$task_after = $engine->get_task($defer_task_id);
assert_equals($task_after['status'], 'deferred', 'arrived=no auto-defers the task');
assert_equals((int) $task_after['deferral_count'], 1, 'deferral counter incremented');

echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
