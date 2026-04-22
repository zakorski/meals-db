<?php
/**
 * Tests for MealsDB_Task_Rules fixed- and query-spawn paths.
 *
 * Run with: php tests/test-task-rules-spawn.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $tag, $value, ...$args) { return $value; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 0; } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
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
        public function get_var($q, $x = 0, $y = 0) { return null; }
        public function query($q) { return 0; }
        public function insert($t, $d, $f = null) { return 1; }
        public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        public function delete($t, $w, $f = null) { return 1; }
    }
}

class SpawnFakeWpdb extends wpdb {
    public array $tasks = [];
    public array $rules = [];
    public int $next_task_id = 1;
    public int $next_rule_id = 1;
    public int $insert_id = 0;
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
        if (strpos((string) $table, 'meals_schedule_rules') !== false) {
            $data['rule_id'] = $this->next_rule_id++;
            $this->rules[$data['rule_id']] = $data;
            $this->insert_id = $data['rule_id'];
            return 1;
        }
        return 1;
    }
    public function update($table, $data, $where, $format = null, $wf = null) {
        if (strpos((string) $table, 'meals_schedule_rules') !== false) {
            $id = (int) ($where['rule_id'] ?? 0);
            if (isset($this->rules[$id])) {
                foreach ($data as $k => $v) { $this->rules[$id][$k] = $v; }
                return 1;
            }
        }
        if (strpos((string) $table, 'meals_tasks') !== false) {
            $id = (int) ($where['task_id'] ?? 0);
            if (isset($this->tasks[$id])) {
                foreach ($data as $k => $v) { $this->tasks[$id][$k] = $v; }
                return 1;
            }
        }
        return 0;
    }
    public function get_row($q, $o = 'OBJECT', $y = 0) {
        if (is_array($q)) { $sql = $q['sql']; $args = $q['args']; }
        else { $sql = $q; $args = []; }
        $id = (int) ($args[0] ?? 0);
        if (stripos($sql, 'meals_schedule_rules') !== false) {
            return $this->rules[$id] ?? null;
        }
        if (stripos($sql, 'meals_tasks') !== false) {
            return $this->tasks[$id] ?? null;
        }
        return null;
    }
    public function get_results($q, $o = 'OBJECT') {
        if (is_array($q)) { $sql = $q['sql']; $args = $q['args']; }
        else { $sql = $q; $args = []; }
        if (stripos($sql, 'meals_schedule_rules') !== false) {
            return array_values($this->rules);
        }
        if (stripos($sql, 'meals_tasks') !== false) {
            $rows = array_values($this->tasks);
            // Honour "status NOT IN (...)" from the propagate query so the
            // test can verify completed tasks are skipped.
            if (stripos($sql, 'status NOT IN') !== false) {
                $excluded = array_slice($args, 1); // first arg is source_rule_id
                $rows = array_values(array_filter($rows, static function($r) use ($excluded) {
                    return !in_array($r['status'] ?? '', $excluded, true);
                }));
            }
            return $rows;
        }
        return [];
    }
    public function query($q) { return 0; }
}

$failures = [];
$passed = 0;
function assert_equals($a, $e, string $l) {
    global $failures, $passed;
    if ($a === $e) { $passed++; }
    else { $failures[] = sprintf('[%s] expected %s, got %s', $l, var_export($e, true), var_export($a, true)); }
}
function assert_true($v, string $l) { assert_equals((bool) $v, true, $l); }

// ---- setup ----
MealsDB_Task_Registry::reset();
MealsDB_Task_Rules::reset_strategies();
MealsDB_Task_Registry::register('reminder', [
    'label'         => 'Reminder',
    'assignee_role' => 'admin',
    'form_schema'   => [
        ['name' => 'description', 'type' => 'textarea'],
    ],
]);

$fake = new SpawnFakeWpdb();
$GLOBALS['wpdb'] = $fake;
$rules = new MealsDB_Task_Rules($fake);

// ---- fixed spawn ----
$rule_id = $rules->create_rule([
    'name'             => 'daily reminder',
    'task_type'        => 'reminder',
    'spawn_type'       => 'fixed',
    'recurrence'       => ['type' => 'daily', 'interval' => 1, 'time' => '06:00'],
    'payload_template' => ['description' => 'hi'],
    'assignee_role'    => 'admin',
    'tags'             => ['morning'],
]);
assert_true($rule_id > 0, 'create_rule returns id');
$rule = $rules->get_rule($rule_id);
assert_equals($rule['task_type'], 'reminder', 'rule type preserved');
assert_true(!empty($rule['next_run_at']), 'next_run_at computed on create');

$created = $rules->spawn_from_rule($rule);
assert_equals($created, 1, 'fixed spawn creates 1 task');
$task = array_values($fake->tasks)[0];
assert_equals($task['task_type'], 'reminder', 'spawned task has correct type');
assert_equals($task['source_rule_id'], $rule_id, 'source_rule_id set');
assert_equals($task['assignee_role'], 'admin', 'assignee inherited');
$payload = json_decode((string) $task['payload'], true);
assert_equals($payload['description'], 'hi', 'payload cloned from template');

// ---- query spawn ----
MealsDB_Task_Rules::register_strategy('fake_clients', function($rule, $params) {
    return [
        ['wp_user_id' => 101, 'first_name' => 'Jane', 'last_name' => 'Doe'],
        ['wp_user_id' => 102, 'first_name' => 'John', 'last_name' => 'Smith'],
    ];
});

$fake = new SpawnFakeWpdb();
$GLOBALS['wpdb'] = $fake;
$rules = new MealsDB_Task_Rules($fake);

$rule_id = $rules->create_rule([
    'name'             => 'clients-due reminder',
    'task_type'        => 'reminder',
    'spawn_type'       => 'query',
    'recurrence'       => ['type' => 'weekly', 'interval' => 1, 'days_of_week' => ['monday'], 'time' => '06:00'],
    'query_criteria'   => ['strategy' => 'fake_clients', 'params' => []],
    'payload_template' => ['description' => '{{first_name}} {{last_name}} (#{{wp_user_id}})'],
]);
$rule = $rules->get_rule($rule_id);
$created = $rules->spawn_from_rule($rule);
assert_equals($created, 2, 'query spawn created 2 tasks');
$task_a = $fake->tasks[1];
$payload_a = json_decode((string) $task_a['payload'], true);
assert_equals($payload_a['description'], 'Jane Doe (#101)', 'placeholder substitution rendered Jane');
$task_b = $fake->tasks[2];
$payload_b = json_decode((string) $task_b['payload'], true);
assert_equals($payload_b['description'], 'John Smith (#102)', 'placeholder substitution rendered John');

// ---- unknown strategy ----
$rule_bad = $rules->create_rule([
    'name'             => 'bad',
    'task_type'        => 'reminder',
    'spawn_type'       => 'query',
    'recurrence'       => ['type' => 'daily', 'interval' => 1, 'time' => '06:00'],
    'query_criteria'   => ['strategy' => 'does_not_exist', 'params' => []],
    'payload_template' => [],
]);
$created = $rules->spawn_from_rule($rules->get_rule($rule_bad));
assert_equals($created, 0, 'unknown strategy spawns 0');

// ---- update_rule with propagate ----
$fake = new SpawnFakeWpdb();
$GLOBALS['wpdb'] = $fake;
$rules = new MealsDB_Task_Rules($fake);

$rule_id = $rules->create_rule([
    'name' => 'x', 'task_type' => 'reminder', 'spawn_type' => 'fixed',
    'recurrence' => ['type' => 'daily', 'interval' => 1, 'time' => '06:00'],
    'payload_template' => ['description' => 'old'],
    'assignee_role' => 'admin',
]);
$rules->spawn_from_rule($rules->get_rule($rule_id));
$rules->spawn_from_rule($rules->get_rule($rule_id));
assert_equals(count($fake->tasks), 2, 'two tasks spawned');

// Propagate a new description into open tasks.
$rules->update_rule($rule_id, [
    'payload_template' => ['description' => 'new', 'extra' => 1],
    'assignee_role'    => 'warehouse',
    'tags'             => ['retired'],
], true);

foreach ($fake->tasks as $t) {
    $payload = json_decode((string) $t['payload'], true);
    assert_equals($payload['description'], 'new', 'propagated description');
    assert_equals($payload['extra'], 1, 'propagated new key');
    assert_equals($t['assignee_role'], 'warehouse', 'propagated assignee_role');
}

// After completion, propagation skips terminal tasks.
// Mark task 1 completed directly.
$fake->tasks[1]['status'] = 'completed';
$rules->update_rule($rule_id, [
    'payload_template' => ['description' => 'even newer'],
], true);
$p1 = json_decode((string) $fake->tasks[1]['payload'], true);
assert_equals($p1['description'], 'new', 'completed task payload not touched by propagate');
$p2 = json_decode((string) $fake->tasks[2]['payload'], true);
assert_equals($p2['description'], 'even newer', 'pending task payload updated');

echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
