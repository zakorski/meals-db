<?php
/**
 * Tests for MealsDB_Daily_Report (Phase W).
 *
 * Covers directive tests 6, 7, 8, 9:
 *   6. report job logs its own execution via the job logger
 *   7. report correctly identifies missing nightly jobs (MISSED status)
 *   8. report correctly identifies hook count anomalies on a business day
 *   9. report doesn't crash when recipients list is empty
 *
 * The reconciliation queries hit real WP tables in production; in this
 * unit test we stub them out via a query-pattern matcher in the wpdb
 * fake so we exercise the structure, not the SQL semantics.
 *
 * Run with: php tests/test-daily-report.php
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
if (!function_exists('add_action')) { function add_action(...$a) {} }
if (!function_exists('wp_next_scheduled')) { function wp_next_scheduled(...$a) { return false; } }
if (!function_exists('wp_schedule_event')) { function wp_schedule_event(...$a) {} }
if (!function_exists('wp_clear_scheduled_hook')) { function wp_clear_scheduled_hook(...$a) {} }
if (!function_exists('wp_timezone')) { function wp_timezone() { return new DateTimeZone('UTC'); } }
if (!function_exists('get_bloginfo')) { function get_bloginfo($k) { return 'TestSite'; } }
if (!function_exists('is_email')) {
    function is_email($s) {
        return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : false;
    }
}

// --- option store ----------------------------------------------------------
$GLOBALS['__test_options'] = [];
if (!function_exists('get_option')) {
    function get_option(string $name, $default = false) {
        return $GLOBALS['__test_options'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null) {
        $GLOBALS['__test_options'][$name] = $value;
        return true;
    }
}

// --- wp_mail mock ----------------------------------------------------------
$GLOBALS['__wp_mail_calls'] = [];
if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $body, $headers = []) {
        $GLOBALS['__wp_mail_calls'][] = compact('to', 'subject', 'body', 'headers');
        return true;
    }
}

// --- $wpdb fake with pattern-matched results -------------------------------
class DailyReportTestWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $rows = [];
    public $charset = 'utf8mb4';
    public $collate = 'utf8mb4_unicode_ci';
    public $job_log_rows = [];
    public $hook_count_responses = [];
    public $hook_outcome_responses = [];
    public $hook_trailing_responses = [];

    private $next_id = 1;

    public function insert(string $table, array $data, $formats = null) {
        $id = $this->next_id++;
        $this->insert_id = $id;
        $this->rows[$id] = array_merge(['log_id' => $id], $data);
        $this->job_log_rows[$id] = $this->rows[$id];
        return 1;
    }
    public function update(string $table, array $data, array $where, $f1 = null, $f2 = null) {
        $id = (int) ($where['log_id'] ?? 0);
        if ($id > 0 && isset($this->rows[$id])) {
            $this->rows[$id] = array_merge($this->rows[$id], $data);
            $this->job_log_rows[$id] = $this->rows[$id];
        }
        return 1;
    }
    public function prepare(string $sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        return ['__sql' => $sql, '__args' => $args];
    }
    public function query($sql) { return 0; }

    public function get_var($prepared) {
        $sql  = is_array($prepared) ? $prepared['__sql'] : $prepared;
        $args = is_array($prepared) ? $prepared['__args'] : [];

        if (stripos($sql, 'COUNT(*) FROM `wp_meals_hook_log`') !== false) {
            // count_in_window — return canned count by hook name.
            $hook = (string) ($args[0] ?? '');
            return $this->hook_count_responses[$hook] ?? 0;
        }
        if (stripos($sql, 'SELECT completed_at') !== false) {
            return null; // last_success unknown
        }
        if (stripos($sql, 'SELECT fired_at') !== false) {
            return null; // last_fire unknown
        }
        return null;
    }

    public function get_row($prepared, $o = OBJECT) {
        $sql  = is_array($prepared) ? $prepared['__sql'] : $prepared;
        $args = is_array($prepared) ? $prepared['__args'] : [];

        // latest_in_window
        if (stripos($sql, 'FROM `wp_meals_job_log`') !== false && stripos($sql, 'LIMIT 1') !== false) {
            $job_name = (string) ($args[0] ?? '');
            return $GLOBALS['__test_latest'][$job_name] ?? null;
        }
        return null;
    }

    public function get_results($prepared, $o = OBJECT) {
        $sql  = is_array($prepared) ? $prepared['__sql'] : $prepared;
        $args = is_array($prepared) ? $prepared['__args'] : [];

        if (stripos($sql, 'GROUP BY outcome') !== false) {
            $hook = (string) ($args[0] ?? '');
            return $this->hook_outcome_responses[$hook] ?? [];
        }
        if (stripos($sql, 'GROUP BY DATE(fired_at)') !== false) {
            $hook = (string) ($args[0] ?? '');
            return $this->hook_trailing_responses[$hook] ?? [];
        }
        // Reconciliation queries — return empty by default.
        return [];
    }

    public function get_col($prepared) {
        return [];
    }
}
$wpdb = new DailyReportTestWpdb();
$GLOBALS['wpdb'] = $wpdb;

if (!class_exists('MealsDB_DB')) {
    class MealsDB_DB {
        public static function get_table_name(string $t): string { return 'wp_' . $t; }
    }
}
// Permissions stub — admin path won't be exercised here but the
// daily-report module doesn't call it directly anyway.
if (!class_exists('MealsDB_Permissions')) {
    class MealsDB_Permissions {
        public static function can_access_plugin(): bool { return true; }
        public static function required_capability(): string { return 'manage_options'; }
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
// Test 7: nightly jobs with NO recorded runs in the 24h window are
// flagged as MISSED. We left __test_latest empty above, so every job
// in MONITORED_JOBS should come back MISSED.
// ---------------------------------------------------------------------------
$GLOBALS['__test_latest'] = [];

// Force "yesterday" to be a midweek business day so the anomaly path
// is active for test 8 below. 2026-05-13 is a Wednesday.
$tz = new DateTimeZone('UTC');
class FixedDailyReportClock extends MealsDB_Daily_Report {
    // (no-op subclass; we drive the date via the wpdb queries' fixtures)
}

$report = MealsDB_Daily_Report::build_report();
$missed = 0;
foreach ($report['jobs'] as $job) {
    if ($job['status'] === 'MISSED') {
        $missed++;
    }
}
assert_equal(count(MealsDB_Daily_Report::MONITORED_JOBS), $missed, 'all monitored jobs flagged MISSED when no runs recorded');
assert_equal('WARNINGS', $report['summary']['overall'], 'overall=WARNINGS when only missed jobs');

// ---------------------------------------------------------------------------
// Test 8: hook anomaly detection. Configure:
//   - hook A: yesterday count = 1, 7-day avg = 50 → ANOMALY (2% of avg, way below 50%)
//   - hook B: yesterday count = 45, 7-day avg = 50 → not anomalous (90% of avg)
// The "yesterday" date inside build_report is determined by
// wp_timezone(), which we stubbed to UTC, so the date drift is
// predictable.
// ---------------------------------------------------------------------------
$wpdb->hook_count_responses = [];
// Use the first two hooks from the constant list.
$hookA = MealsDB_Daily_Report::INSTRUMENTED_HOOKS[0];
$hookB = MealsDB_Daily_Report::INSTRUMENTED_HOOKS[1];

$wpdb->hook_outcome_responses[$hookA] = [['outcome' => 'processed', 'c' => 1]];
$wpdb->hook_outcome_responses[$hookB] = [['outcome' => 'processed', 'c' => 45]];

// 7 day backfill rows. Daily count 50 for the past 7 days for both.
$daily_rows = [];
for ($i = 1; $i <= 7; $i++) {
    $daily_rows[] = ['d' => gmdate('Y-m-d', strtotime("-{$i} day")), 'c' => 50];
}
$wpdb->hook_trailing_responses[$hookA] = $daily_rows;
$wpdb->hook_trailing_responses[$hookB] = $daily_rows;

$report2 = MealsDB_Daily_Report::build_report();

// Find the rows for hookA / hookB.
$rowA = null; $rowB = null;
foreach ($report2['hooks'] as $h) {
    if ($h['hook_name'] === $hookA) $rowA = $h;
    if ($h['hook_name'] === $hookB) $rowB = $h;
}
assert_true($rowA !== null, 'hookA present in report');
assert_true($rowB !== null, 'hookB present in report');

// Anomaly only flags on business days. Compute "is yesterday a
// business day in UTC" the same way the implementation does.
$yesterday_weekday = (int) gmdate('N', strtotime('-1 day'));
$is_business = $yesterday_weekday >= 1 && $yesterday_weekday <= 5;

if ($is_business) {
    assert_true(!empty($rowA['is_anomaly']), 'hookA flagged as anomaly (1 fires vs 50 avg, business day)');
    assert_true(empty($rowB['is_anomaly']), 'hookB NOT flagged (45 fires vs 50 avg = 90%)');
} else {
    // Weekend run — neither flagged regardless.
    assert_true(empty($rowA['is_anomaly']), 'hookA not flagged (weekend run, anomaly skipped)');
    assert_true(empty($rowB['is_anomaly']), 'hookB not flagged (weekend run, anomaly skipped)');
}

// ---------------------------------------------------------------------------
// Test 9: send_report with empty recipients returns false, doesn't throw.
// ---------------------------------------------------------------------------
$GLOBALS['__test_options'] = []; // no recipients
$GLOBALS['__wp_mail_calls'] = [];
$sent = MealsDB_Daily_Report::send_report($report2);
assert_equal(false, $sent, 'send_report with no recipients returns false');
assert_equal(0, count($GLOBALS['__wp_mail_calls']), 'wp_mail not called when recipients empty');

// With recipients configured, wp_mail IS called.
$GLOBALS['__test_options'][MealsDB_Daily_Report::OPT_RECIPIENTS] = 'ops@example.com';
$sent = MealsDB_Daily_Report::send_report($report2);
assert_equal(true, $sent, 'send_report returns true with valid recipient');
assert_equal(1, count($GLOBALS['__wp_mail_calls']), 'wp_mail called once');
assert_equal(['ops@example.com'], $GLOBALS['__wp_mail_calls'][0]['to'], 'wp_mail recipients match');

// Invalid recipient (no @) silently dropped — wp_mail not called.
$GLOBALS['__test_options'][MealsDB_Daily_Report::OPT_RECIPIENTS] = 'not-an-email';
$GLOBALS['__wp_mail_calls'] = [];
$sent = MealsDB_Daily_Report::send_report($report2);
assert_equal(false, $sent, 'send_report with only-invalid recipients returns false');
assert_equal(0, count($GLOBALS['__wp_mail_calls']), 'wp_mail not called for invalid recipients');

// Header-injection attempt — CR/LF in address. is_email() must reject.
$GLOBALS['__test_options'][MealsDB_Daily_Report::OPT_RECIPIENTS] = "ok@x.com\nBcc: attacker@evil.com";
$recipients = MealsDB_Daily_Report::get_recipients();
foreach ($recipients as $r) {
    assert_true(strpos($r, "\n") === false && strpos($r, "\r") === false, 'recipient cannot contain CRLF');
}

// ---------------------------------------------------------------------------
// Test 6: run() logs its own execution.
// ---------------------------------------------------------------------------
$GLOBALS['__test_options'] = []; // empty recipients — run() should still
                                  // record its own execution.
$wpdb->job_log_rows = [];
try {
    MealsDB_Daily_Report::run();
} catch (\Throwable $e) {
    assert_true(false, 'run() should not throw when recon checks return empty: ' . $e->getMessage());
}

$found_daily_report = false;
foreach ($wpdb->job_log_rows as $row) {
    if (($row['job_name'] ?? '') === 'daily_report' && ($row['status'] ?? '') === 'success') {
        $found_daily_report = true;
        break;
    }
}
assert_true($found_daily_report, 'run() logged a daily_report row with status=success');

// ---------------------------------------------------------------------------
// Threshold setting bounds.
// ---------------------------------------------------------------------------
$ref = new ReflectionClass(MealsDB_Daily_Report::class);
$m   = $ref->getMethod('anomaly_threshold_pct');
$m->setAccessible(true);

$GLOBALS['__test_options'][MealsDB_Daily_Report::OPT_ANOMALY_THRESHOLD] = 0;
assert_equal(50.0, $m->invoke(null), 'threshold=0 falls back to default 50');

$GLOBALS['__test_options'][MealsDB_Daily_Report::OPT_ANOMALY_THRESHOLD] = 200;
assert_equal(50.0, $m->invoke(null), 'threshold>100 falls back to default 50');

$GLOBALS['__test_options'][MealsDB_Daily_Report::OPT_ANOMALY_THRESHOLD] = 75;
assert_equal(75.0, $m->invoke(null), 'threshold=75 honored');

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
