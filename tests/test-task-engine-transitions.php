<?php
/**
 * Tests for MealsDB_Task_Engine state transitions.
 *
 * Covers: complete, defer, skip, start, bulk_skip, terminal-state guards,
 * on_complete callback invocation, update_task_payload.
 *
 * Run with: php tests/test-task-engine-transitions.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) {
    function __(string $t, string $d = 'default') { return $t; }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0, $depth = 512) { return json_encode($data, $flags, $depth); }
}
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 7; } }
if (!function_exists('current_user_can')) { function current_user_can($cap) { return true; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public int $insert_id = 0;
        public int $rows_affected = 0;
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $output = 'OBJECT') { return []; }
        public function get_row($query, $output = 'OBJECT', $y = 0) { return null; }
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function query($query) { return 0; }
        public function insert($table, $data, $format = null) { return 1; }
        public function update($table, $data, $where, $format = null, $where_format = null) { return 1; }
        public function delete($table, $where, $format = null) { return 1; }
    }
}

class TransitionFakeWpdb extends wpdb {
    public array $tasks = [];
    public int $next_id = 1;
    public int $insert_id = 0;
    public array $audit = [];
    public function __construct() { /* skip parent */ }

    public function prepare($q, ...$args) {
        if (!empty($args) && is_array($args[0] ?? null)) { $args = $args[0]; }
        return ['sql' => $q, 'args' => $args];
    }
    public function insert($table, $data, $format = null) {
        if (strpos((string) $table, 'meals_tasks') !== false) {
            $data['task_id'] = $this->next_id++;
            $data['created_at'] = gmdate('Y-m-d H:i:s');
            $data['updated_at'] = gmdate('Y-m-d H:i:s');
            $this->tasks[$data['task_id']] = $data;
            $this->insert_id = $data['task_id'];
            return 1;
        }
        return 1;
    }
    public function update($table, $data, $where, $format = null, $where_format = null) {
        if (strpos((string) $table, 'meals_tasks') !== false) {
            $id = (int) ($where['task_id'] ?? 0);
            if ($id > 0 && isset($this->tasks[$id])) {
                foreach ($data as $k => $v) { $this->tasks[$id][$k] = $v; }
                return 1;
            }
        }
        return 0;
    }
    public function get_row($q, $output = 'OBJECT', $y = 0) {
        if (is_array($q)) {
            $args = $q['args'];
        } else { $args = []; }
        if (empty($args)) return null;
        $id = (int) $args[0];
        return $this->tasks[$id] ?? null;
    }
    public function get_results($q, $output = 'OBJECT') { return array_values($this->tasks); }
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

$failures = [];
$passed = 0;

function assert_equals($actual, $expected, string $label) {
    global $failures, $passed;
    if ($actual === $expected) { $passed++; }
    else { $failures[] = sprintf('[%s] expected %s, got %s', $label, var_export($expected, true), var_export($actual, true)); }
}
function assert_true($v, string $l) { assert_equals((bool) $v, true, $l); }

// ---- Setup ----
$called = [];
MealsDB_Task_Registry::reset();
MealsDB_Task_Registry::register('test_type', [
    'form_schema' => [
        ['name' => 'done', 'type' => 'yesno', 'required' => true],
        ['name' => 'notes', 'type' => 'textarea', 'required' => false],
    ],
    'on_complete' => function(array $task, array $form_data, int $user) use (&$called) {
        $called['on_complete'] = compact('task', 'form_data', 'user');
    },
    'on_skip' => function(array $task, ?string $reason) use (&$called) {
        $called['on_skip'] = compact('task', 'reason');
    },
    'on_defer' => function(array $task, string $new_date, ?string $reason) use (&$called) {
        $called['on_defer'] = compact('task', 'new_date', 'reason');
    },
]);

$fake = new TransitionFakeWpdb();
$GLOBALS['wpdb'] = $fake;
$engine = new MealsDB_Task_Engine($fake);

$t1 = $engine->create_task(['task_type' => 'test_type', 'payload' => [], 'next_run_date' => '2026-04-22']);
assert_true($t1 > 0, 'create task');

// ---- complete: invalid form_data (missing required) ----
$called = [];
$ok = $engine->complete_task($t1, [], 42);
assert_equals($ok, false, 'complete rejects missing required field');
assert_equals($fake->tasks[$t1]['status'], 'pending', 'status unchanged on validation failure');
assert_equals(isset($called['on_complete']), false, 'callback did not fire on validation failure');

// ---- complete: valid ----
$ok = $engine->complete_task($t1, ['done' => 'yes', 'notes' => 'all good'], 42);
assert_equals($ok, true, 'complete succeeds with valid data');
assert_equals($fake->tasks[$t1]['status'], 'completed', 'status is completed');
assert_equals((int) $fake->tasks[$t1]['completed_by'], 42, 'completed_by recorded');
assert_true(isset($called['on_complete']), 'on_complete callback fired');
assert_equals($called['on_complete']['form_data']['done'], 'yes', 'callback received form_data');

// ---- completed tasks cannot be re-completed / deferred / skipped ----
$ok = $engine->complete_task($t1, ['done' => 'yes'], 42);
assert_equals($ok, false, 'cannot re-complete terminal task');
$ok = $engine->defer_task($t1, '2026-05-01');
assert_equals($ok, false, 'cannot defer terminal task');
$ok = $engine->skip_task($t1);
assert_equals($ok, false, 'cannot skip terminal task');

// ---- defer ----
$t2 = $engine->create_task(['task_type' => 'test_type', 'payload' => [], 'next_run_date' => '2026-04-22']);
$called = [];
$ok = $engine->defer_task($t2, '2026-04-23', 'not today');
assert_equals($ok, true, 'defer succeeds');
assert_equals($fake->tasks[$t2]['status'], 'deferred', 'status is deferred');
assert_equals($fake->tasks[$t2]['next_run_date'], '2026-04-23', 'date advanced');
assert_equals((int) $fake->tasks[$t2]['deferral_count'], 1, 'deferral_count = 1');
assert_true(isset($called['on_defer']), 'on_defer callback fired');

// Defer again — counter increments.
$engine->defer_task($t2, '2026-04-24');
assert_equals((int) $fake->tasks[$t2]['deferral_count'], 2, 'deferral_count = 2');

// Malformed date refused.
$ok = $engine->defer_task($t2, 'bad-date');
assert_equals($ok, false, 'defer rejects bad date');

// ---- skip ----
$t3 = $engine->create_task(['task_type' => 'test_type', 'payload' => [], 'next_run_date' => '2026-04-22']);
$called = [];
$ok = $engine->skip_task($t3, 'done manually');
assert_equals($ok, true, 'skip succeeds');
assert_equals($fake->tasks[$t3]['status'], 'skipped', 'status is skipped');
assert_true(isset($called['on_skip']), 'on_skip callback fired');

// ---- start ----
$t4 = $engine->create_task(['task_type' => 'test_type', 'payload' => [], 'next_run_date' => '2026-04-22']);
$ok = $engine->start_task($t4, 9);
assert_equals($ok, true, 'start succeeds');
assert_equals($fake->tasks[$t4]['status'], 'in_progress', 'status in_progress');
// Idempotent.
$ok = $engine->start_task($t4, 9);
assert_equals($ok, true, 'start is idempotent');

// ---- bulk_skip ----
$a = $engine->create_task(['task_type' => 'test_type', 'payload' => [], 'next_run_date' => '2026-04-22']);
$b = $engine->create_task(['task_type' => 'test_type', 'payload' => [], 'next_run_date' => '2026-04-23']);
$c = $engine->create_task(['task_type' => 'test_type', 'payload' => [], 'next_run_date' => '2026-04-24']);
$count = $engine->bulk_skip(['task_type' => 'test_type']);
assert_true($count >= 3, 'bulk_skip skipped at least 3 tasks');

// ---- update_task_payload ----
$t5 = $engine->create_task(['task_type' => 'test_type', 'payload' => ['k' => 1], 'next_run_date' => '2026-04-22']);
$ok = $engine->update_task_payload($t5, ['k' => 2, 'new' => 'x']);
assert_equals($ok, true, 'payload update succeeds');
$fresh = $engine->get_task($t5);
assert_equals($fresh['payload']['k'], 2, 'payload k overridden');
assert_equals($fresh['payload']['new'], 'x', 'payload new key added');
// Status unchanged.
assert_equals($fresh['status'], 'pending', 'update_payload does not change status');

echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
