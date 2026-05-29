<?php
/**
 * Tests for MealsDB_Job_Logger AS A FACADE over the meals_event_log trunk
 * (directive STR-LOG). Asserts the facade still presents the old public
 * surface (start/finish/fail/heartbeat) while writing trunk rows:
 * category='job', event=job_name, outcome running→succeeded/failed, the
 * job-lifecycle columns preserved.
 *
 * Run with: php tests/test-job-logger.php
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

// --- $wpdb stub --------------------------------------------------------
class TestWpdb {
    public $prefix      = 'wp_';
    public $insert_id   = 0;
    public $last_error  = '';
    public $rows        = [];   // log_id => row data
    private $next_id    = 1;
    public $charset     = 'utf8mb4';
    public $collate     = 'utf8mb4_unicode_ci';

    public function insert(string $table, array $data, $formats = null) {
        $id = $this->next_id++;
        $this->insert_id = $id;
        $this->rows[$id] = array_merge(['log_id' => $id], $data);
        return 1;
    }
    public function update(string $table, array $data, array $where, $f1 = null, $f2 = null) {
        $log_id = (int) ($where['log_id'] ?? 0);
        if ($log_id <= 0 || !isset($this->rows[$log_id])) {
            return 0;
        }
        $this->rows[$log_id] = array_merge($this->rows[$log_id], $data);
        return 1;
    }
    public function prepare(string $sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        return $sql . ' /* args:' . json_encode($args) . ' */';
    }
    public function esc_like($t) { return addcslashes((string) $t, '_%\\'); }
    public function query($sql) { return 0; }
    public function get_var($sql) { return null; }
    public function get_row($sql, $output = OBJECT) { return null; }
    public function get_results($sql, $output = OBJECT) { return []; }
}
$wpdb = new TestWpdb();
$GLOBALS['wpdb'] = $wpdb;

if (!class_exists('MealsDB_DB')) {
    class MealsDB_DB {
        public static function get_table_name(string $t): string { return 'wp_' . $t; }
    }
}

// --- Asserts -----------------------------------------------------------
$failures = [];
$passed = 0;
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
// Test 1: start() inserts a category='job', outcome='running' trunk row.
// ---------------------------------------------------------------------------
MealsDB_Job_Logger::_reset_started_cache();
$id = MealsDB_Job_Logger::start('test_job_a', ['hello' => 'world']);
assert_true($id > 0, 'start() returns a positive log_id');
assert_true(isset($wpdb->rows[$id]), 'start() inserted a row');
assert_equal('job', $wpdb->rows[$id]['category'] ?? null, 'start() set category=job');
assert_equal('test_job_a', $wpdb->rows[$id]['event'] ?? null, 'start() set event=job_name');
assert_equal('running', $wpdb->rows[$id]['outcome'] ?? null, 'start() set outcome=running');
assert_true(!empty($wpdb->rows[$id]['started_at']), 'start() set started_at');
assert_true(strpos((string) $wpdb->rows[$id]['context'], 'world') !== false, 'start() encoded context');

// ---------------------------------------------------------------------------
// Test 2: finish() promotes the row to succeeded with counters + duration.
// ---------------------------------------------------------------------------
MealsDB_Job_Logger::finish($id, [
    'records_processed' => 100,
    'records_updated'   => 42,
    'records_skipped'   => 5,
    'records_errored'   => 0,
]);
assert_equal('succeeded', $wpdb->rows[$id]['outcome'] ?? null, 'finish() set outcome=succeeded');
assert_equal(100, (int) ($wpdb->rows[$id]['records_processed'] ?? 0), 'finish() recorded records_processed');
assert_equal(42, (int) ($wpdb->rows[$id]['records_updated'] ?? 0), 'finish() recorded records_updated');
assert_equal(5, (int) ($wpdb->rows[$id]['records_skipped'] ?? 0), 'finish() recorded records_skipped');
assert_true(isset($wpdb->rows[$id]['completed_at']), 'finish() set completed_at');
assert_true(isset($wpdb->rows[$id]['duration_seconds']), 'finish() set duration_seconds');

// ---------------------------------------------------------------------------
// Test 3: fail() updates outcome=failed with scrubbed message in `message`.
// ---------------------------------------------------------------------------
$id2 = MealsDB_Job_Logger::start('test_job_b');
MealsDB_Job_Logger::fail($id2, 'database is on fire', ['records_errored' => 3]);
assert_equal('failed', $wpdb->rows[$id2]['outcome'] ?? null, 'fail() set outcome=failed');
assert_equal('error', $wpdb->rows[$id2]['severity'] ?? null, 'fail() set severity=error');
assert_true(
    strpos((string) ($wpdb->rows[$id2]['message'] ?? ''), 'database is on fire') !== false,
    'fail() persisted the error message in `message`'
);
assert_equal(3, (int) ($wpdb->rows[$id2]['records_errored'] ?? 0), 'fail() recorded records_errored');

// ---------------------------------------------------------------------------
// Test 4: heartbeat() updates counters without changing outcome.
// ---------------------------------------------------------------------------
$id3 = MealsDB_Job_Logger::start('test_job_c');
MealsDB_Job_Logger::heartbeat($id3, ['records_processed' => 50]);
assert_equal('running', $wpdb->rows[$id3]['outcome'] ?? null, 'heartbeat() leaves outcome=running');
assert_equal(50, (int) ($wpdb->rows[$id3]['records_processed'] ?? 0), 'heartbeat() updated processed count');

// ---------------------------------------------------------------------------
// Test 5: oversized context is truncated rather than exploding the row.
// (A large array of short keys exceeds 16KB once encoded; it is not
//  blob-shaped, so the PII scrubber doesn't collapse it first.)
// ---------------------------------------------------------------------------
$big = [];
for ($i = 0; $i < 3000; $i++) { $big['key_' . $i] = $i; }
$id4 = MealsDB_Job_Logger::start('test_job_d', $big);
$stored = (string) $wpdb->rows[$id4]['context'];
assert_true(strpos($stored, 'truncated') !== false, 'oversized context truncated with marker');

// ---------------------------------------------------------------------------
// Test 6: invalid log_id no-ops cleanly (defensive).
// ---------------------------------------------------------------------------
MealsDB_Job_Logger::finish(0, ['records_processed' => 1]);
MealsDB_Job_Logger::heartbeat(0, ['records_processed' => 1]);
assert_true(true, 'invalid log_id finish/heartbeat does not throw');

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
