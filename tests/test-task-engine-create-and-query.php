<?php
/**
 * Tests for MealsDB_Task_Engine CRUD helpers.
 *
 * Exercises create_task, get_task, query_tasks with a lightweight
 * in-memory wpdb mock so we don't require a real MySQL.
 *
 * Run with: php tests/test-task-engine-create-and-query.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default') { return $text; }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0, $depth = 512) { return json_encode($data, $flags, $depth); }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return 0; }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return true; }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() { return true; }
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

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
        public function insert($table, $data, $format = null) { $this->insert_id++; return 1; }
        public function update($table, $data, $where, $format = null, $where_format = null) { return 1; }
        public function delete($table, $where, $format = null) { return 1; }
    }
}

/**
 * In-memory task store that mimics the subset of wpdb used by the engine.
 * Intentionally not a full SQL engine — just enough for CRUD asserts.
 */
class TaskEngineFakeWpdb extends wpdb {
    public array $tasks = [];
    public int $next_id = 1;
    public int $insert_id = 0;
    public array $audit_entries = [];

    public function __construct() { /* skip parent */ }

    public function prepare($query, ...$args) {
        if (!empty($args) && is_array($args[0] ?? null)) {
            $args = $args[0];
        }
        return ['sql' => $query, 'args' => $args];
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
        if (strpos((string) $table, 'meals_audit_log') !== false) {
            $this->audit_entries[] = $data;
            return 1;
        }
        return 1;
    }

    public function query($query) {
        // Logger routes INSERT INTO meals_audit_log through query(sql) after
        // prepare(sql, args). Pick those up so our audit-entry count is
        // meaningful.
        if (is_array($query)) {
            $sql = $query['sql'] ?? '';
            $args = $query['args'] ?? [];
        } else {
            $sql = (string) $query;
            $args = [];
        }
        if (stripos($sql, 'meals_audit_log') !== false && stripos($sql, 'INSERT INTO') !== false) {
            $this->audit_entries[] = $args;
            return 1;
        }
        return 0;
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        if (strpos((string) $table, 'meals_tasks') !== false) {
            $id = (int) ($where['task_id'] ?? 0);
            if ($id > 0 && isset($this->tasks[$id])) {
                foreach ($data as $k => $v) {
                    $this->tasks[$id][$k] = $v;
                }
                return 1;
            }
            return 0;
        }
        return 1;
    }

    public function get_row($query, $output = 'OBJECT', $y = 0) {
        if (is_array($query)) {
            $sql = $query['sql'];
            $args = $query['args'];
        } else {
            $sql = $query; $args = [];
        }
        if (stripos($sql, 'FROM `') !== false && stripos($sql, 'meals_tasks') !== false && !empty($args)) {
            $id = (int) $args[0];
            return isset($this->tasks[$id]) ? $this->tasks[$id] : null;
        }
        return null;
    }

    public function get_results($query, $output = 'OBJECT') {
        // The engine uses query_tasks' constructed SQL — for tests we just
        // return all tasks and let the engine-level tests exercise filters
        // by calling query_tasks against the full set. For stricter
        // filter testing we'd implement WHERE parsing, but the engine
        // builds the SQL deterministically from its filter args so unit
        // coverage for the filter SQL shape belongs in a different test.
        return array_values($this->tasks);
    }
}

$failures = [];
$passed = 0;

function assert_equals($actual, $expected, string $label) {
    global $failures, $passed;
    if ($actual === $expected) {
        $passed++;
    } else {
        $failures[] = sprintf('[%s] expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
    }
}

function assert_true($value, string $label) {
    assert_equals((bool) $value, true, $label);
}

// Register a test type.
MealsDB_Task_Registry::reset();
MealsDB_Task_Registry::register('test_type', [
    'label'         => 'Test',
    'assignee_role' => 'admin',
    'form_schema'   => [
        ['name' => 'done', 'type' => 'yesno', 'required' => true],
    ],
]);

$fake = new TaskEngineFakeWpdb();
$GLOBALS['wpdb'] = $fake;
$engine = new MealsDB_Task_Engine($fake);

// --- create_task ---
$task_id = $engine->create_task([
    'task_type'     => 'test_type',
    'payload'       => ['description' => 'hi'],
    'next_run_date' => '2026-04-22',
]);
assert_true($task_id > 0, 'create_task returns positive id');
assert_equals(count($fake->tasks), 1, 'one row persisted');

$task = $engine->get_task($task_id);
assert_true($task !== null, 'get_task returns row');
assert_equals($task['task_type'], 'test_type', 'task_type preserved');
assert_equals($task['status'], 'pending', 'default status is pending');
assert_equals($task['assignee_role'], 'admin', 'assignee inherited from registry default');
assert_equals($task['payload']['description'], 'hi', 'payload JSON decoded');

// Missing task_type rejected.
$bad = $engine->create_task(['payload' => []]);
assert_equals($bad, 0, 'missing task_type returns 0');

// Malformed date rejected.
$bad = $engine->create_task([
    'task_type'     => 'test_type',
    'payload'       => [],
    'next_run_date' => '2026/04/22',
]);
assert_equals($bad, 0, 'bad date returns 0');

// --- Status-transition logic (see separate transitions test for complete
// coverage). Here we just sanity-check skip.
$ok = $engine->skip_task($task_id, 'not needed');
assert_true($ok, 'skip returns true');
$fresh = $engine->get_task($task_id);
assert_equals($fresh['status'], 'skipped', 'status is skipped');
// Can't skip again.
$ok = $engine->skip_task($task_id, 'second try');
assert_equals($ok, false, 'skip refuses terminal task');

// --- Urgency override + tag encoding on create ---
$t2 = $engine->create_task([
    'task_type'     => 'test_type',
    'payload'       => [],
    'next_run_date' => '2026-04-23',
    'urgency'       => 'escalated',
    'tags'          => ['overdue', 'warehouse'],
]);
$row = $engine->get_task($t2);
assert_equals($row['urgency'], 'escalated', 'explicit urgency override');
assert_equals($row['tags'], ['overdue', 'warehouse'], 'tags round-tripped through JSON');

// --- Audit-log entries ---
assert_true(count($fake->audit_entries) >= 2, 'audit entries logged');

echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) {
    echo "FAIL: $f\n";
}
exit(empty($failures) ? 0 : 1);
