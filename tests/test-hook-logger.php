<?php
/**
 * Tests for MealsDB_Hook_Logger (Phase W).
 *
 * Covers directive tests 4, 5 (record() inserts; count_in_window
 * returns correct count) plus the hot-path discipline check —
 * record() must execute a single INSERT with no follow-up queries.
 *
 * Run with: php tests/test-hook-logger.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($d, $f = 0, $depth = 512) {
        $r = json_encode($d, $f, $depth);
        return $r === false ? false : $r;
    }
}

// $wpdb stub that counts each INSERT / SELECT / UPDATE so we can
// assert the hot path is single-query.
class HookTestWpdb {
    public $prefix     = 'wp_';
    public $insert_id  = 0;
    public $last_error = '';
    public $rows       = [];
    public $insert_count = 0;
    public $select_count = 0;
    public $update_count = 0;
    public $delete_count = 0;
    public $charset    = 'utf8mb4';
    public $collate    = 'utf8mb4_unicode_ci';

    private $next_id   = 1;
    private $count_data = [];   // mock data for count_in_window

    public function insert(string $table, array $data, $formats = null) {
        $this->insert_count++;
        $id = $this->next_id++;
        $this->insert_id = $id;
        $this->rows[$id] = array_merge(['log_id' => $id], $data);
        return 1;
    }
    public function update(string $table, array $data, array $where, $f1 = null, $f2 = null) {
        $this->update_count++;
        return 1;
    }
    public function prepare(string $sql, ...$args) { return $sql . ' /* ' . json_encode($args) . ' */'; }
    public function query($sql) {
        if (stripos($sql, 'DELETE') === 0) { $this->delete_count++; }
        return 0;
    }
    public function get_var($sql) {
        $this->select_count++;
        // Pretend count_in_window returns 7 (for the window test below).
        if (stripos($sql, 'COUNT(*)') !== false) {
            return 7;
        }
        return null;
    }
    public function get_row($sql, $o = OBJECT) { $this->select_count++; return null; }
    public function get_results($sql, $o = OBJECT) {
        $this->select_count++;
        // Return mock outcome breakdown for count_by_outcome.
        if (stripos($sql, 'GROUP BY outcome') !== false) {
            return [
                ['outcome' => 'processed', 'c' => '12'],
                ['outcome' => 'skipped',   'c' => '3'],
                ['outcome' => 'errored',   'c' => '1'],
            ];
        }
        return [];
    }
}
$wpdb = new HookTestWpdb();
$GLOBALS['wpdb'] = $wpdb;

if (!class_exists('MealsDB_DB')) {
    class MealsDB_DB {
        public static function get_table_name(string $t): string { return 'wp_' . $t; }
    }
}

$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s",
        $label, var_export($expected, true), var_export($actual, true));
}
function assert_true($v, string $label) {
    global $failures, $passed;
    if ($v) { $passed++; return; }
    $failures[] = "FAIL: $label";
}

// ---------------------------------------------------------------------------
// Test 4: record() inserts a row with hook_name and outcome.
// ---------------------------------------------------------------------------
$wpdb->insert_count = $wpdb->select_count = $wpdb->update_count = 0;
MealsDB_Hook_Logger::record('woocommerce_new_order', 'order', 99, ['k' => 'v']);
assert_equal(1, $wpdb->insert_count, 'record() executes exactly 1 INSERT');
assert_equal(0, $wpdb->select_count, 'record() executes 0 SELECTs (hot path)');
assert_equal(0, $wpdb->update_count, 'record() executes 0 UPDATEs (hot path)');

$row = end($wpdb->rows);
assert_equal('woocommerce_new_order', $row['hook_name'] ?? null, 'hook_name set');
assert_equal('order', $row['target_type'] ?? null, 'target_type set');
assert_equal(99, (int) ($row['target_id'] ?? 0), 'target_id set');
assert_equal('processed', $row['outcome'] ?? null, 'default outcome=processed');
assert_true(isset($row['context']) && strpos($row['context'], '"k":"v"') !== false, 'context encoded');

// ---------------------------------------------------------------------------
// Outcome variant: skipped with no context still does single insert.
// ---------------------------------------------------------------------------
$wpdb->insert_count = $wpdb->select_count = 0;
MealsDB_Hook_Logger::record('profile_update', 'user', 7, [], MealsDB_Hook_Logger::OUTCOME_SKIPPED);
assert_equal(1, $wpdb->insert_count, 'skipped record() still single INSERT');
assert_equal(0, $wpdb->select_count, 'skipped record() no SELECTs');
$row = end($wpdb->rows);
assert_equal('skipped', $row['outcome'] ?? null, 'outcome=skipped persisted');

// ---------------------------------------------------------------------------
// Errored: includes sanitized error_message.
// ---------------------------------------------------------------------------
MealsDB_Hook_Logger::record(
    'woocommerce_new_order', 'order', 100, ['exception' => 'X'],
    MealsDB_Hook_Logger::OUTCOME_ERRORED, 'something broke'
);
$row = end($wpdb->rows);
assert_equal('errored', $row['outcome'] ?? null, 'outcome=errored');
assert_true(
    isset($row['error_message']) && strpos($row['error_message'], 'something broke') !== false,
    'error_message persisted'
);

// ---------------------------------------------------------------------------
// Unknown outcome falls back to 'processed' (defensive).
// ---------------------------------------------------------------------------
MealsDB_Hook_Logger::record('test_hook', 'order', 1, [], 'bogus_outcome');
$row = end($wpdb->rows);
assert_equal('processed', $row['outcome'] ?? null, 'unknown outcome coerced to processed');

// ---------------------------------------------------------------------------
// Test 5: count_in_window returns the mock value (7).
// ---------------------------------------------------------------------------
$c = MealsDB_Hook_Logger::count_in_window('woocommerce_new_order', '2026-05-15 00:00:00', '2026-05-16 00:00:00');
assert_equal(7, $c, 'count_in_window returns scalar count');

// ---------------------------------------------------------------------------
// count_by_outcome returns the three buckets.
// ---------------------------------------------------------------------------
$by = MealsDB_Hook_Logger::count_by_outcome('woocommerce_new_order', '2026-05-15 00:00:00', '2026-05-16 00:00:00');
assert_equal(12, $by['processed'] ?? null, 'processed bucket');
assert_equal(3,  $by['skipped'] ?? null,   'skipped bucket');
assert_equal(1,  $by['errored'] ?? null,   'errored bucket');

// ---------------------------------------------------------------------------
// target_id<=0 is treated as "no target" — not inserted as 0.
// ---------------------------------------------------------------------------
$wpdb->rows = [];
MealsDB_Hook_Logger::record('test_hook', 'order', 0);
$row = end($wpdb->rows);
assert_true(!isset($row['target_id']), 'target_id<=0 not persisted');

// ---------------------------------------------------------------------------
// Oversized context dropped silently (not stored).
// ---------------------------------------------------------------------------
MealsDB_Hook_Logger::record('test_hook', 'order', 1, ['blob' => str_repeat('x', 10000)]);
$row = end($wpdb->rows);
assert_true(!isset($row['context']), 'oversized context dropped rather than stored');

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
