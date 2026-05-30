<?php
/**
 * Tests for spawn idempotency / dedup (directive MAJ-7).
 *
 * The real guarantee is the UNIQUE `spawn_key` index: a re-run or overlapping
 * cron pass that re-spawns the same (rule, entity, date, type) is rejected by
 * the database. These tests drive that through a fake wpdb that ENFORCES the
 * unique constraint (rejects a duplicate non-NULL spawn_key the way MySQL
 * would, with a "Duplicate entry" last_error) so create_task's dedup branch is
 * exercised end-to-end.
 *
 * Run with: php tests/test-task-spawn-dedup.php
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
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }

// ---- transient stubs (drive the Layer-2 overlap lock, T-7) ----
$GLOBALS['__transients'] = [];
if (!function_exists('get_transient')) {
    function get_transient($k) { return $GLOBALS['__transients'][$k] ?? false; }
}
if (!function_exists('set_transient')) {
    function set_transient($k, $v, $ttl = 0) { $GLOBALS['__transients'][$k] = $v; return true; }
}
if (!function_exists('delete_transient')) {
    function delete_transient($k) { unset($GLOBALS['__transients'][$k]); return true; }
}

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

/**
 * Fake wpdb that ENFORCES the uniq_spawn_key UNIQUE index: a meals_tasks
 * INSERT whose non-NULL spawn_key already exists fails the way MySQL would.
 * NULL spawn_keys never collide (manual tasks). Also captures meals_event_log
 * inserts so the overlap-skip degraded event (T-7) is observable.
 */
class DedupFakeWpdb extends wpdb {
    public array $tasks = [];
    public array $rules = [];
    public array $events = [];
    public int $next_task_id = 1;
    public int $next_rule_id = 1;
    public int $insert_id = 0;
    public string $last_error = '';
    public function __construct() {}
    public function prepare($q, ...$a) {
        if (!empty($a) && is_array($a[0] ?? null)) { $a = $a[0]; }
        return ['sql' => $q, 'args' => $a];
    }
    public function insert($table, $data, $format = null) {
        $this->last_error = '';
        if (strpos((string) $table, 'meals_tasks') !== false) {
            // Enforce the UNIQUE spawn_key (non-NULL only).
            $key = $data['spawn_key'] ?? null;
            if ($key !== null && $key !== '') {
                foreach ($this->tasks as $existing) {
                    if (($existing['spawn_key'] ?? null) === $key) {
                        $this->last_error = "Duplicate entry '" . $key . "' for key 'uniq_spawn_key'";
                        $this->insert_id = 0;
                        return false;
                    }
                }
            }
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
        if (strpos((string) $table, 'meals_event_log') !== false) {
            $this->events[] = $data;
            $this->insert_id = count($this->events);
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
            return array_values($this->tasks);
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

// =====================================================================
//  T-1 / T-2  fixed re-spawn dedup; next date NOT deduped
// =====================================================================
$fake = new DedupFakeWpdb();
$GLOBALS['wpdb'] = $fake;
$rules = new MealsDB_Task_Rules($fake);

$rule_id = $rules->create_rule([
    'name'             => 'daily reminder',
    'task_type'        => 'reminder',
    'spawn_type'       => 'fixed',
    'recurrence'       => ['type' => 'daily', 'interval' => 1, 'time' => '06:00'],
    'payload_template' => ['description' => 'hi'],
    'assignee_role'    => 'admin',
]);
$rule = $rules->get_rule($rule_id);

$created_1 = $rules->spawn_from_rule($rule);
assert_equals($created_1, 1, 'T-1 first fixed spawn creates 1 task');
$created_2 = $rules->spawn_from_rule($rule); // same rule, same next_run_date
assert_equals($created_2, 0, 'T-1 second fixed spawn (same date) creates 0 — deduped');
assert_equals(count($fake->tasks), 1, 'T-1 task count stays 1 after re-spawn');

// T-2: a DIFFERENT next_run_date is a legitimate next occurrence — NOT deduped.
$rule_next = $rule;
$rule_next['next_run_at'] = '2030-01-02 06:00:00';
$created_3 = $rules->spawn_from_rule($rule_next);
assert_equals($created_3, 1, 'T-2 different next_run_date spawns a new task');
assert_equals(count($fake->tasks), 2, 'T-2 task count now 2 (recurrence still works)');

// =====================================================================
//  T-3 / T-4  query re-spawn per-entity dedup; new entity spawns
// =====================================================================
$matched = [
    ['__related_entity_type' => 'client', '__related_entity_id' => 101, 'first_name' => 'Jane'],
    ['__related_entity_type' => 'client', '__related_entity_id' => 102, 'first_name' => 'John'],
];
MealsDB_Task_Rules::register_strategy('fake_clients', function ($rule, $params) use (&$matched) {
    return $matched;
});

$fake = new DedupFakeWpdb();
$GLOBALS['wpdb'] = $fake;
$rules = new MealsDB_Task_Rules($fake);

$qrule_id = $rules->create_rule([
    'name'             => 'clients-due reminder',
    'task_type'        => 'reminder',
    'spawn_type'       => 'query',
    'recurrence'       => ['type' => 'daily', 'interval' => 1, 'time' => '06:00'],
    'query_criteria'   => ['strategy' => 'fake_clients', 'params' => []],
    'payload_template' => ['description' => '{{first_name}}'],
]);
$qrule = $rules->get_rule($qrule_id);

$qc1 = $rules->spawn_from_rule($qrule);
assert_equals($qc1, 2, 'T-3 first query pass creates 2 tasks (A,B)');
$qc2 = $rules->spawn_from_rule($qrule); // same date, same entities
assert_equals($qc2, 0, 'T-3 second query pass (same date/entities) creates 0 — deduped');
assert_equals(count($fake->tasks), 2, 'T-3 task count stays 2, not 4');

// T-4: query now also matches entity C → only C spawns; A,B untouched.
$matched[] = ['__related_entity_type' => 'client', '__related_entity_id' => 103, 'first_name' => 'Carol'];
$qc3 = $rules->spawn_from_rule($qrule);
assert_equals($qc3, 1, 'T-4 new entity C spawns exactly 1 task');
assert_equals(count($fake->tasks), 3, 'T-4 task count now 3 (A,B not re-created)');

// =====================================================================
//  T-5  manual tasks (no spawn_key) are NEVER deduped
// =====================================================================
$fake = new DedupFakeWpdb();
$GLOBALS['wpdb'] = $fake;
$engine = new MealsDB_Task_Engine($fake);

$m1 = $engine->create_task(['task_type' => 'reminder', 'next_run_date' => '2030-05-01', 'payload' => ['description' => 'a']]);
$m2 = $engine->create_task(['task_type' => 'reminder', 'next_run_date' => '2030-05-01', 'payload' => ['description' => 'a']]);
assert_true($m1 > 0, 'T-5 first manual task created');
assert_true($m2 > 0, 'T-5 second identical manual task ALSO created (not deduped)');
assert_equals(count($fake->tasks), 2, 'T-5 two manual tasks coexist');

// =====================================================================
//  T-6  a dedup hit is NOT logged as a create_task insert failure
// =====================================================================
$fake = new DedupFakeWpdb();
$GLOBALS['wpdb'] = $fake;
$engine = new MealsDB_Task_Engine($fake);

$key = MealsDB_Task_Rules::build_spawn_key(7, null, '2030-06-01', 'reminder');
$first = $engine->create_task(['task_type' => 'reminder', 'next_run_date' => '2030-06-01', 'spawn_key' => $key]);
assert_true($first > 0, 'T-6 first keyed create returns a task_id');

// Capture error_log output for the deduped second call.
$err_log_file = tempnam(sys_get_temp_dir(), 'mealsdb_errlog_');
$prev_log = ini_get('error_log');
ini_set('error_log', $err_log_file);
$dup = $engine->create_task(['task_type' => 'reminder', 'next_run_date' => '2030-06-01', 'spawn_key' => $key]);
ini_set('error_log', $prev_log === false ? '' : $prev_log);

assert_equals($dup, 0, 'T-6 deduped re-spawn returns 0 (idempotent skip)');
$logged = is_readable($err_log_file) ? (string) file_get_contents($err_log_file) : '';
@unlink($err_log_file);
assert_true(strpos($logged, 'create_task insert failed') === false, 'T-6 dedup hit emits NO "insert failed" error line');
assert_equals(count($fake->tasks), 1, 'T-6 only one task persisted');

// =====================================================================
//  T-7  overlap lock: nightly_sync skips, logs degraded, creates nothing
// =====================================================================
$fake = new DedupFakeWpdb();
$GLOBALS['wpdb'] = $fake;
$GLOBALS['__transients']['mealsdb_task_spawn_running'] = 1; // lock held

MealsDB_Task_Cron::nightly_sync();

assert_equals(count($fake->tasks), 0, 'T-7 no tasks created while lock held');
$overlap = array_values(array_filter($fake->events, static function ($e) {
    return ($e['event'] ?? '') === 'nightly_sync.overlap_skipped';
}));
assert_equals(count($overlap), 1, 'T-7 overlap_skipped event recorded once');
assert_equals($overlap[0]['outcome'] ?? '', MealsDB_Event_Log::OUTCOME_DEGRADED, 'T-7 overlap event is degraded');
// Lock is left intact (held by the "other" pass); the running pass must NOT
// delete a lock it didn't acquire — the early return skips the finally.
assert_true(isset($GLOBALS['__transients']['mealsdb_task_spawn_running']), 'T-7 held lock not cleared by the skipped pass');
unset($GLOBALS['__transients']['mealsdb_task_spawn_running']);

// =====================================================================
//  T-8  spawn_key shape
// =====================================================================
assert_equals(
    MealsDB_Task_Rules::build_spawn_key(5, null, '2030-07-01', 'reminder'),
    '5:-:2030-07-01:reminder',
    'T-8 FIXED key uses "-" placeholder for NULL entity'
);
assert_equals(
    MealsDB_Task_Rules::build_spawn_key(5, 42, '2030-07-01', 'reminder'),
    '5:42:2030-07-01:reminder',
    'T-8 QUERY key carries the entity id'
);

echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
