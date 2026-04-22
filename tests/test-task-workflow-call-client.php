<?php
/**
 * Integration test for call_client workflow.
 *
 * Scenarios:
 *   1. outcome=order_placed        → no follow-up spawned
 *   2. outcome=voicemail, callback → follow-up spawned +1 day
 *   3. outcome=no_answer           → no follow-up
 *
 * Run with: php tests/test-task-workflow-call-client.php
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

class CallClientWpdb extends wpdb {
    public array $tasks = [];
    public int $next_task_id = 1;
    public int $insert_id = 0;
    public function __construct() {}
    public function prepare($q, ...$a) {
        if (!empty($a) && is_array($a[0] ?? null)) { $a = $a[0]; }
        return ['sql' => $q, 'args' => $a];
    }
    public function insert($t, $d, $f = null) {
        if (strpos((string) $t, 'meals_tasks') !== false) {
            $d['task_id'] = $this->next_task_id++;
            $this->tasks[$d['task_id']] = $d;
            $this->insert_id = $d['task_id'];
            return 1;
        }
        return 1;
    }
    public function update($t, $d, $w, $f = null, $wf = null) {
        if (strpos((string) $t, 'meals_tasks') !== false) {
            $id = (int) ($w['task_id'] ?? 0);
            if (isset($this->tasks[$id])) {
                foreach ($d as $k => $v) { $this->tasks[$id][$k] = $v; }
                return 1;
            }
        }
        return 0;
    }
    public function get_row($q, $o = 'OBJECT', $y = 0) {
        if (is_array($q)) { $args = $q['args']; } else { $args = []; }
        return $this->tasks[(int) ($args[0] ?? 0)] ?? null;
    }
    public function query($q) { return 0; }
}

MealsDB_Task_Registry::reset();
MealsDB_Task_Type_Call_Client::register();

$fake = new CallClientWpdb();
$GLOBALS['wpdb'] = $fake;
$engine = new MealsDB_Task_Engine($fake);

$failures = [];
$passed = 0;
function assert_equals($a, $e, string $l) {
    global $failures, $passed;
    if ($a === $e) { $passed++; }
    else { $failures[] = sprintf('[%s] expected %s got %s', $l, var_export($e, true), var_export($a, true)); }
}
function assert_true($v, $l) { assert_equals((bool) $v, true, $l); }

$count_call_tasks = function() use ($fake) {
    $n = 0;
    foreach ($fake->tasks as $t) {
        if ($t['task_type'] === 'call_client') { $n++; }
    }
    return $n;
};

// --- Scenario 1: order_placed → no follow-up ---
$t1 = $engine->create_task([
    'task_type'           => 'call_client',
    'payload'             => ['client_name' => 'Jane Doe', 'phone' => '5551234'],
    'next_run_date'       => '2026-04-22',
    'related_entity_type' => 'client',
    'related_entity_id'   => 5,
    'assignee_role'       => 'phone',
]);
$before = $count_call_tasks();
$engine->complete_task($t1, ['outcome' => 'order_placed', 'notes' => 'placed order #123'], 42);
$after = $count_call_tasks();
assert_equals($after, $before, 'order_placed does not spawn follow-up');

// --- Scenario 2: voicemail + callback requested → follow-up ---
$t2 = $engine->create_task([
    'task_type'           => 'call_client',
    'payload'             => ['client_name' => 'Jane Doe', 'phone' => '5551234'],
    'next_run_date'       => '2026-04-22',
    'related_entity_type' => 'client',
    'related_entity_id'   => 5,
    'assignee_role'       => 'phone',
    'tags'                => ['weekly_calls'],
]);
$before = $count_call_tasks();
$engine->complete_task($t2, [
    'outcome'            => 'voicemail',
    'notes'              => 'left vm',
    'callback_requested' => 'yes',
], 42);
$after = $count_call_tasks();
assert_equals($after - $before, 1, 'voicemail + callback spawns exactly one follow-up');

// Follow-up has follow_up urgency and carries related_entity + callback tag.
$followup = null;
foreach ($fake->tasks as $t) {
    if ($t['task_type'] === 'call_client' && (int) ($t['parent_task_id'] ?? 0) === $t2) {
        $followup = $t; break;
    }
}
assert_true($followup !== null, 'follow-up task found');
assert_equals($followup['urgency'], 'follow_up', 'follow-up has follow_up urgency');
assert_equals($followup['assignee_role'], 'phone', 'follow-up assigned to phone role');
assert_equals((int) $followup['related_entity_id'], 5, 'follow-up carries related client_id');

$fu_tags = json_decode((string) $followup['tags'], true);
assert_true(in_array('callback', $fu_tags ?? [], true), 'follow-up tagged callback');
assert_true(in_array('weekly_calls', $fu_tags ?? [], true), 'follow-up inherits original tags');

// --- Scenario 3: voicemail WITHOUT callback requested → no follow-up ---
$t3 = $engine->create_task([
    'task_type' => 'call_client',
    'payload'   => ['client_name' => 'X', 'phone' => '5'],
    'next_run_date' => '2026-04-22',
]);
$before = $count_call_tasks();
$engine->complete_task($t3, ['outcome' => 'voicemail', 'callback_requested' => 'no'], 42);
$after = $count_call_tasks();
assert_equals($after, $before, 'voicemail without callback does NOT spawn follow-up');

// --- Scenario 4: no_answer → no follow-up ---
$t4 = $engine->create_task([
    'task_type' => 'call_client',
    'payload'   => ['client_name' => 'Y', 'phone' => '5'],
    'next_run_date' => '2026-04-22',
]);
$before = $count_call_tasks();
$engine->complete_task($t4, ['outcome' => 'no_answer'], 42);
$after = $count_call_tasks();
assert_equals($after, $before, 'no_answer does not spawn follow-up');

echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
