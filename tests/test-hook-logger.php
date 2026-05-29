<?php
/**
 * Tests for MealsDB_Hook_Logger AS A FACADE over the meals_event_log
 * trunk (directive STR-LOG). Asserts the facade still presents the old
 * public surface while writing trunk rows: category='hook', the legacy
 * three-way outcome mapped onto trunk outcome+severity, target_type/id →
 * entity_type/id. Also re-asserts the hot-path discipline (record() =
 * exactly one INSERT, no SELECT/UPDATE).
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

// $wpdb stub that counts each INSERT / SELECT / UPDATE so we can assert
// the hot path stays single-query.
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
    public function esc_like($t) { return addcslashes((string) $t, '_%\\'); }
    public function query($sql) {
        if (stripos($sql, 'DELETE') === 0) { $this->delete_count++; }
        return 0;
    }
    public function get_var($sql) {
        $this->select_count++;
        if (stripos($sql, 'COUNT(*)') !== false) {
            return 7; // count_in_window mock
        }
        return null;
    }
    public function get_row($sql, $o = OBJECT) {
        $this->select_count++;
        // count_by_outcome now issues one SUM(...) row instead of GROUP BY.
        if (stripos($sql, 'SUM(outcome') !== false) {
            return ['errored' => '1', 'skipped' => '3', 'processed' => '12'];
        }
        return null;
    }
    public function get_results($sql, $o = OBJECT) { $this->select_count++; return []; }
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
// record() writes ONE trunk row, category='hook', no SELECT/UPDATE.
// ---------------------------------------------------------------------------
$wpdb->insert_count = $wpdb->select_count = $wpdb->update_count = 0;
MealsDB_Hook_Logger::record('woocommerce_new_order', 'order', 99, ['k' => 'v']);
assert_equal(1, $wpdb->insert_count, 'record() executes exactly 1 INSERT');
assert_equal(0, $wpdb->select_count, 'record() executes 0 SELECTs (hot path)');
assert_equal(0, $wpdb->update_count, 'record() executes 0 UPDATEs (hot path)');

$row = end($wpdb->rows);
assert_equal('hook', $row['category'] ?? null, 'category=hook');
assert_equal('woocommerce_new_order', $row['event'] ?? null, 'event = hook name');
assert_equal('order', $row['entity_type'] ?? null, 'target_type → entity_type');
assert_equal(99, (int) ($row['entity_id'] ?? 0), 'target_id → entity_id');
// processed → trunk succeeded / severity info.
assert_equal('succeeded', $row['outcome'] ?? null, 'processed → outcome=succeeded');
assert_equal('info', $row['severity'] ?? null, 'processed → severity=info');
assert_true(isset($row['context']) && strpos($row['context'], '"k":"v"') !== false, 'context encoded');
assert_true(strpos($row['context'], '"hook_outcome":"processed"') !== false, 'original hook outcome preserved in context');

// ---------------------------------------------------------------------------
// skipped → succeeded + severity debug (the breakdown discriminator).
// ---------------------------------------------------------------------------
MealsDB_Hook_Logger::record('profile_update', 'user', 7, [], MealsDB_Hook_Logger::OUTCOME_SKIPPED);
$row = end($wpdb->rows);
assert_equal('succeeded', $row['outcome'] ?? null, 'skipped → outcome=succeeded');
assert_equal('debug', $row['severity'] ?? null, 'skipped → severity=debug');

// ---------------------------------------------------------------------------
// errored → degraded + severity warning + message.
// ---------------------------------------------------------------------------
MealsDB_Hook_Logger::record(
    'woocommerce_new_order', 'order', 100, ['exception' => 'X'],
    MealsDB_Hook_Logger::OUTCOME_ERRORED, 'something broke'
);
$row = end($wpdb->rows);
assert_equal('degraded', $row['outcome'] ?? null, 'errored → outcome=degraded');
assert_equal('warning', $row['severity'] ?? null, 'errored → severity=warning');
assert_true(
    isset($row['message']) && strpos($row['message'], 'something broke') !== false,
    'error_message → message persisted'
);

// ---------------------------------------------------------------------------
// Unknown outcome falls back to processed mapping (defensive).
// ---------------------------------------------------------------------------
MealsDB_Hook_Logger::record('test_hook', 'order', 1, [], 'bogus_outcome');
$row = end($wpdb->rows);
assert_equal('succeeded', $row['outcome'] ?? null, 'unknown outcome → succeeded');
assert_equal('info', $row['severity'] ?? null, 'unknown outcome → severity info');

// ---------------------------------------------------------------------------
// count_in_window returns the mock scalar.
// ---------------------------------------------------------------------------
$c = MealsDB_Hook_Logger::count_in_window('woocommerce_new_order', '2026-05-15 00:00:00', '2026-05-16 00:00:00');
assert_equal(7, $c, 'count_in_window returns scalar count');

// ---------------------------------------------------------------------------
// count_by_outcome reconstructs the three legacy buckets from the trunk.
// ---------------------------------------------------------------------------
$by = MealsDB_Hook_Logger::count_by_outcome('woocommerce_new_order', '2026-05-15 00:00:00', '2026-05-16 00:00:00');
assert_equal(12, $by['processed'] ?? null, 'processed bucket');
assert_equal(3,  $by['skipped'] ?? null,   'skipped bucket');
assert_equal(1,  $by['errored'] ?? null,   'errored bucket');

// ---------------------------------------------------------------------------
// target_id<=0 is treated as "no entity" — not inserted as 0.
// ---------------------------------------------------------------------------
$wpdb->rows = [];
MealsDB_Hook_Logger::record('test_hook', 'order', 0);
$row = end($wpdb->rows);
assert_true(!isset($row['entity_id']), 'target_id<=0 not persisted as entity_id');

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
