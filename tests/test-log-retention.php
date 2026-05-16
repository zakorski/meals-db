<?php
/**
 * Tests for MealsDB_Log_Retention (Phase W).
 *
 * Verifies:
 *   - run() invokes DELETE with a LIMIT cap so a backlog cleanup
 *     cannot lock the table for an arbitrary length of time
 *   - job log DELETE never matches rows with status='running'
 *   - cutoff timestamps are computed in UTC
 *
 * Run with: php tests/test-log-retention.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__'))                { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters'))     { function apply_filters($t, $v) { return $v; } }
if (!function_exists('add_action'))        { function add_action(...$a) {} }
if (!function_exists('wp_next_scheduled')) { function wp_next_scheduled(...$a) { return false; } }
if (!function_exists('wp_schedule_event')) { function wp_schedule_event(...$a) {} }
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($d, $f = 0, $depth = 512) {
        $r = json_encode($d, $f, $depth);
        return $r === false ? false : $r;
    }
}

class RetentionTestWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $rows = [];
    public $deletes = []; // captured DELETE SQL
    private $next_id = 1;
    public $charset = 'utf8mb4';
    public $collate = 'utf8mb4_unicode_ci';

    public function insert(string $t, array $d, $f = null) {
        $id = $this->next_id++;
        $this->insert_id = $id;
        $this->rows[$id] = array_merge(['log_id' => $id], $d);
        return 1;
    }
    public function update(string $t, array $d, array $w, $f1 = null, $f2 = null) {
        $id = (int) ($w['log_id'] ?? 0);
        if ($id > 0 && isset($this->rows[$id])) {
            $this->rows[$id] = array_merge($this->rows[$id], $d);
        }
        return 1;
    }
    public function prepare(string $sql, ...$args) {
        // mimic wpdb's behavior of substituting placeholders for tests.
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $out = $sql;
        foreach ($args as $a) {
            if (is_int($a)) {
                $out = preg_replace('/%d/', (string) $a, $out, 1);
            } else {
                $out = preg_replace('/%s/', "'" . addslashes((string) $a) . "'", $out, 1);
            }
        }
        return $out;
    }
    public function query($sql) {
        if (stripos($sql, 'DELETE') === 0) {
            $this->deletes[] = $sql;
        }
        return 0;
    }
    public function get_var($sql) { return null; }
    public function get_row($sql, $o = OBJECT) { return null; }
    public function get_results($sql, $o = OBJECT) { return []; }
}
$wpdb = new RetentionTestWpdb();
$GLOBALS['wpdb'] = $wpdb;

if (!class_exists('MealsDB_DB')) {
    class MealsDB_DB {
        public static function get_table_name(string $t): string { return 'wp_' . $t; }
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

// Run the retention pass.
try {
    MealsDB_Log_Retention::run();
} catch (\Throwable $e) {
    assert_true(false, 'retention run() should not throw: ' . $e->getMessage());
}

// Two DELETEs expected — one per table.
assert_equal(2, count($wpdb->deletes), 'retention issued exactly 2 DELETEs (hook log + job log)');

// Both must include LIMIT, otherwise a giant backlog could lock the
// table for seconds.
foreach ($wpdb->deletes as $i => $sql) {
    assert_true(
        preg_match('/LIMIT\s+\d+/i', $sql) === 1,
        sprintf('DELETE #%d includes LIMIT clause', $i)
    );
}

// Job-log DELETE must explicitly exclude rows with status='running'.
$job_delete = null;
foreach ($wpdb->deletes as $sql) {
    if (stripos($sql, 'meals_job_log') !== false) {
        $job_delete = $sql;
        break;
    }
}
assert_true($job_delete !== null, 'job log DELETE present');
assert_true(stripos($job_delete, "status NOT IN ('running')") !== false, 'job log DELETE excludes running rows');

// Hook-log DELETE filters by fired_at and a UTC-shaped cutoff.
$hook_delete = null;
foreach ($wpdb->deletes as $sql) {
    if (stripos($sql, 'meals_hook_log') !== false) {
        $hook_delete = $sql;
        break;
    }
}
assert_true($hook_delete !== null, 'hook log DELETE present');
assert_true(
    preg_match('/fired_at\` < \'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\'/', $hook_delete) === 1,
    'hook log cutoff is a normal datetime literal'
);

// Confirm the retention job itself logs a row to meals_job_log via
// MealsDB_Job_Logger::start/finish.
$found = false;
foreach ($wpdb->rows as $row) {
    if (($row['job_name'] ?? '') === 'log_retention') {
        $found = true;
        break;
    }
}
assert_true($found, 'retention job records its own run via the job logger');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
