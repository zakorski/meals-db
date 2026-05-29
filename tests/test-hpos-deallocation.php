<?php
/**
 * Regression test for LB-5: HPOS-correct order trash/delete deallocation.
 *
 * Before LB-5 the trash/delete handlers gated on
 * get_post_type($id) === 'shop_order', which never matches under HPOS
 * (orders are not posts), so trashing or deleting an order never
 * deallocated its meals — they kept counting against the client's monthly
 * allowance forever. The handlers were also registered on the wp_posts
 * hooks (trashed_post / before_delete_post), which don't fire for orders
 * under HPOS at all.
 *
 * This test proves the corrected handlers run the real deallocation path:
 * given seeded meals_delivery_allocations rows for an order, calling
 * on_order_trashed / on_order_deleted marks the affected client-months
 * dirty (which is how deallocate_order defers reconciliation to the
 * rebuilder). No get_post_type gate is involved.
 *
 * Run with: php tests/test-hpos-deallocation.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__'))            { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('add_action'))    { function add_action(...$a) {} }
if (!function_exists('wp_next_scheduled')) { function wp_next_scheduled(...$a) { return false; } }
if (!function_exists('wp_schedule_event'))  { function wp_schedule_event(...$a) {} }
// Deliberately NO get_post_type stub — the handlers must not depend on it.
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($d, $f = 0, $depth = 512) {
        $r = json_encode($d, $f, $depth);
        return $r === false ? false : $r;
    }
}

// $wpdb that serves the seeded delivery-allocation rows for the order under
// test and swallows the hook-log writes. get_results() answers the
// "SELECT DISTINCT client_id, billing_month ... WHERE wc_order_id = %d"
// query deallocate_order() runs.
if (!class_exists('wpdb')) { class wpdb {} }
class DeallocTestWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $rows = [];                 // hook-log inserts
    public $seeded_allocations = [];   // wc_order_id => [['client_id'=>..,'billing_month'=>..], ...]
    private $next_id = 1;
    private $last_order_id = 0;

    public function insert(string $t, array $d, $f = null) {
        $id = $this->next_id++;
        $this->rows[$id] = array_merge(['log_id' => $id], $d);
        $this->insert_id = $id;
        return 1;
    }
    public function update(string $t, array $d, array $w, $f1 = null, $f2 = null) { return 1; }
    public function query($sql) { return 0; }
    public function prepare(string $sql, ...$args) {
        // Capture the wc_order_id argument so get_results can echo back the
        // seeded rows for that order. The deallocate query passes it as %d.
        if (!empty($args)) { $this->last_order_id = (int) $args[0]; }
        return $sql;
    }
    public function get_var($sql) { return null; }
    public function get_row($sql, $o = OBJECT) { return null; }
    public function get_results($sql, $o = OBJECT) {
        return $this->seeded_allocations[$this->last_order_id] ?? [];
    }
    public function get_col($sql) { return []; }
}
$wpdb = new DeallocTestWpdb();
$GLOBALS['wpdb'] = $wpdb;

if (!class_exists('MealsDB_DB')) {
    class MealsDB_DB {
        public static function get_table_name(string $t): string { return 'wp_' . $t; }
    }
}

// Recording rebuilder: deallocate_order() constructs one and calls
// mark_dirty() for each affected client-month. We capture those calls.
$GLOBALS['__dirty_marks'] = [];
class MealsDB_Allocation_Rebuilder {
    public function mark_dirty(int $client_id, string $billing_month): void {
        $GLOBALS['__dirty_marks'][] = $client_id . '|' . $billing_month;
    }
}

$failures = [];
$passed   = 0;
function assert_true($v, string $label) {
    global $failures, $passed;
    if ($v) { $passed++; return; }
    $failures[] = "FAIL: $label";
}
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s",
        $label, var_export($expected, true), var_export($actual, true));
}

// ---------------------------------------------------------------------------
// Seed two allocations for order #5001 (one client, two billing months) and
// trash the order. The handler must mark both client-months dirty.
// ---------------------------------------------------------------------------
$wpdb->seeded_allocations[5001] = [
    ['client_id' => 17, 'billing_month' => '2026-05'],
    ['client_id' => 17, 'billing_month' => '2026-06'],
];
$GLOBALS['__dirty_marks'] = [];

$threw = false;
try {
    MealsDB_Allocation_Hooks::on_order_trashed(5001);
} catch (\Throwable $e) {
    $threw = true;
}
assert_equal(false, $threw, 'on_order_trashed does not propagate');
assert_true(
    in_array('17|2026-05', $GLOBALS['__dirty_marks'], true)
        && in_array('17|2026-06', $GLOBALS['__dirty_marks'], true),
    'trashing an allocated order marks its client-months dirty (no get_post_type gate)'
);

// ---------------------------------------------------------------------------
// Permanently delete a different allocated order; same expectation.
// ---------------------------------------------------------------------------
$wpdb->seeded_allocations[5002] = [
    ['client_id' => 99, 'billing_month' => '2026-06'],
];
$GLOBALS['__dirty_marks'] = [];

$threw = false;
try {
    MealsDB_Allocation_Hooks::on_order_deleted(5002);
} catch (\Throwable $e) {
    $threw = true;
}
assert_equal(false, $threw, 'on_order_deleted does not propagate');
assert_true(
    in_array('99|2026-06', $GLOBALS['__dirty_marks'], true),
    'deleting an allocated order marks its client-month dirty'
);

// ---------------------------------------------------------------------------
// An order with NO allocations is a clean no-op (deallocate_order is
// self-validating — it returns before constructing the rebuilder).
// ---------------------------------------------------------------------------
$GLOBALS['__dirty_marks'] = [];
MealsDB_Allocation_Hooks::on_order_deleted(9999);
assert_equal([], $GLOBALS['__dirty_marks'], 'deleting an un-allocated order marks nothing dirty');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
