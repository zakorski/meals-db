<?php
/**
 * Tests for the backfill service and next-date anchor arithmetic.
 *
 * Covers:
 *   - Backfill computes next_order_date = last_order_date + ordering_frequency
 *   - Backfill skips rows whose next_order_date is already set
 *   - Manual override sticks as the new anchor (direct arithmetic check,
 *     since the actual persistence happens in QuickOrder AJAX code).
 *
 * Run with: php tests/test-task-workflow-next-order-date-anchoring.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $tag, $v, ...$a) { return $v; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }

$GLOBALS['test_user_meta'] = [];
if (!function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single = false) {
        return $GLOBALS['test_user_meta'][$uid][$key] ?? '';
    }
}

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public function prepare($q, ...$a) { return $q; }
        public function get_results($q, $o = 'OBJECT') { return []; }
        public function get_row($q, $o = 'OBJECT', $y = 0) { return null; }
        public function query($q) { return 0; }
        public function insert($t, $d, $f = null) { return 1; }
        public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
    }
}

class BackfillWpdb extends wpdb {
    public array $clients = [];
    public function __construct(array $clients) {
        $this->clients = $clients;
    }
    public function get_var($q = null, $x = 0, $y = 0) {
        // The chunked next-dates phase issues a COUNT(*) for the progress
        // total. Return the client count so 'total' is sane; control flow
        // keys off the row count, not this value.
        if (stripos((string) $q, 'COUNT(') !== false) {
            return (string) count($this->clients);
        }
        return null;
    }
    public function get_results($q, $o = 'OBJECT') {
        if (stripos((string) $q, 'meals_clients') !== false) {
            return array_values($this->clients);
        }
        return [];
    }
    public function update($t, $d, $w, $f = null, $wf = null) {
        if (strpos((string) $t, 'meals_clients') !== false) {
            $id = (int) ($w['client_id'] ?? 0);
            if (isset($this->clients[$id])) {
                foreach ($d as $k => $v) { $this->clients[$id][$k] = $v; }
                return 1;
            }
        }
        return 0;
    }
}

$failures = [];
$passed = 0;
function assert_equals($a, $e, string $l) {
    global $failures, $passed;
    if ($a === $e) { $passed++; }
    else { $failures[] = sprintf('[%s] expected %s got %s', $l, var_export($e, true), var_export($a, true)); }
}
function assert_true($v, $l) { assert_equals((bool) $v, true, $l); }

// Seed three clients: one empty (eligible), one with next_order_date already
// set (should be preserved), and one missing last_order_date meta (skipped).
$GLOBALS['test_user_meta'] = [
    101 => ['last_order_date' => '2026-04-01', 'last_delivery_date' => '2026-04-03'],
    102 => ['last_order_date' => '2026-04-05', 'last_delivery_date' => '2026-04-07'],
    103 => [],
];

$clients = [
    1 => [
        'client_id' => 1, 'wp_user_id' => 101, 'active' => 1,
        'ordering_frequency' => 1, 'delivery_frequency' => 1,
        'next_order_date' => null, 'next_delivery_date' => null,
    ],
    2 => [
        'client_id' => 2, 'wp_user_id' => 102, 'active' => 1,
        'ordering_frequency' => 2, 'delivery_frequency' => 2,
        'next_order_date' => '2026-05-01',  // Already set — should stay.
        'next_delivery_date' => null,
    ],
    3 => [
        'client_id' => 3, 'wp_user_id' => 103, 'active' => 1,
        'ordering_frequency' => 1, 'delivery_frequency' => 1,
        'next_order_date' => null, 'next_delivery_date' => null,
    ],
];

$fake = new BackfillWpdb($clients);
$GLOBALS['wpdb'] = $fake;

$result = MealsDB_Migration_Consolidated::drain_phase_next_dates();

assert_equals($result['processed'], 3, 'processed all 3 clients');
assert_equals($result['order_updated'], 1, 'only 1 order_date updated (client 1; client 2 preserved, client 3 has no meta)');
assert_equals($result['delivery_updated'], 2, 'both eligible delivery_dates updated');

// Client 1: next_order_date = 2026-04-01 + 1wk = 2026-04-08.
assert_equals($fake->clients[1]['next_order_date'], '2026-04-08', 'client 1 next_order_date computed');
assert_equals($fake->clients[1]['next_delivery_date'], '2026-04-10', 'client 1 next_delivery_date computed');

// Client 2: next_order_date preserved (already set).
assert_equals($fake->clients[2]['next_order_date'], '2026-05-01', 'client 2 next_order_date preserved');
assert_equals($fake->clients[2]['next_delivery_date'], '2026-04-21', 'client 2 next_delivery_date computed from last_delivery + 2wk');

// Client 3: nothing to backfill, no meta available.
assert_equals($fake->clients[3]['next_order_date'], null, 'client 3 next_order_date stays null');

// Re-running is a no-op when all dates already present.
$result2 = MealsDB_Migration_Consolidated::drain_phase_next_dates();
assert_equals($result2['order_updated'], 0, 'second run does not reupdate');
assert_equals($result2['delivery_updated'], 0, 'second run does not reupdate delivery');

// --- Anchor arithmetic sanity check ---
// Manual override sticks: if the operator confirms next_order_date =
// 2026-04-29 on today's order, the next cycle's "Normally" should show
// 2026-04-29 + 7 = 2026-05-06. This is the math the QO ajax uses.
$anchor = new DateTimeImmutable('2026-04-29');
$next_cycle = $anchor->modify('+7 days')->format('Y-m-d');
assert_equals($next_cycle, '2026-05-06', 'anchor + frequency arithmetic');

echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
