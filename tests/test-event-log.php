<?php
/**
 * Tests for MealsDB_Event_Log — the central event-log trunk (directive
 * STR-LOG). Covers the five inherited disciplines plus the new `degraded`
 * outcome:
 *   - record() writes all the recognised fields
 *   - PII is scrubbed at WRITE time on both message and context
 *   - context is capped (16KB / depth 10) — truncation marker on overflow
 *   - fail-safe: a throwing $wpdb does NOT propagate out of record()
 *   - UTC timestamps (occurred_at is gmdate-shaped)
 *   - the `degraded` outcome is stored
 *   - query() builds a bounded, filtered SELECT
 *
 * Run with: php tests/test-event-log.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__'))            { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($d, $f = 0, $depth = 512) {
        $r = json_encode($d, $f, $depth);
        return $r === false ? false : $r;
    }
}

// --- $wpdb stub --------------------------------------------------------
class EventLogTestWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $rows = [];
    public $last_prepared = '';
    private $next_id = 1;

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
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $this->last_prepared = $sql;
        return ['__sql' => $sql, '__args' => $args];
    }
    public function esc_like($t) { return addcslashes((string) $t, '_%\\'); }
    public function query($sql) { return 0; }
    public function get_var($sql) { return null; }
    public function get_row($sql, $o = OBJECT) { return null; }
    public function get_results($sql, $o = OBJECT) { return []; }
}

// A $wpdb whose insert() throws — to prove record() is fail-safe.
class ThrowingEventLogWpdb extends EventLogTestWpdb {
    public function insert(string $t, array $d, $f = null) {
        throw new RuntimeException('db is on fire');
    }
}

$wpdb = new EventLogTestWpdb();
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

// ---------------------------------------------------------------------------
// record() writes the recognised fields, UTC occurred_at, degraded outcome.
// ---------------------------------------------------------------------------
$id = MealsDB_Event_Log::record([
    'severity'       => 'error',
    'category'       => 'allocation',
    'subsystem'      => 'allocation_rebuilder',
    'event'          => 'rebuild.dirty_month',
    'outcome'        => 'degraded',
    'message'        => 'Dirty month could not be materialised',
    'context'        => ['client_id' => 42, 'month' => '2026-05'],
    'entity_type'    => 'client',
    'entity_id'      => 42,
    'correlation_id' => 'run-abc',
]);
assert_true($id > 0, 'record() returns a positive log_id');
$row = $wpdb->rows[$id];
assert_equal('error', $row['severity'], 'severity stored');
assert_equal('allocation', $row['category'], 'category stored');
assert_equal('allocation_rebuilder', $row['subsystem'], 'subsystem stored');
assert_equal('rebuild.dirty_month', $row['event'], 'event stored');
assert_equal('degraded', $row['outcome'], 'degraded outcome stored');
assert_equal('client', $row['entity_type'], 'entity_type stored');
assert_equal(42, (int) $row['entity_id'], 'entity_id stored');
assert_equal('run-abc', $row['correlation_id'], 'correlation_id stored');
assert_true(
    preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['occurred_at']) === 1,
    'occurred_at is a UTC datetime'
);
assert_true(strpos((string) $row['context'], '"client_id":42') !== false, 'context stored');

// ---------------------------------------------------------------------------
// Invalid severity/outcome coerced to safe defaults.
// ---------------------------------------------------------------------------
$id = MealsDB_Event_Log::record(['event' => 'x', 'severity' => 'bogus', 'outcome' => 'bogus']);
$row = $wpdb->rows[$id];
assert_equal('info', $row['severity'], 'invalid severity → info');
assert_equal('succeeded', $row['outcome'], 'invalid outcome → succeeded');
assert_equal('general', $row['category'], 'missing category → general');

// ---------------------------------------------------------------------------
// PII scrub at WRITE time: email in the message is fingerprinted.
// ---------------------------------------------------------------------------
$id = MealsDB_Event_Log::record([
    'event'   => 'sync.note',
    'message' => 'failed for alice@example.com please retry',
]);
$row = $wpdb->rows[$id];
assert_true(strpos((string) $row['message'], 'alice@example.com') === false, 'email scrubbed out of message');
assert_true(strpos((string) $row['message'], '[email:') !== false, 'email replaced with fingerprint');

// ---------------------------------------------------------------------------
// PII scrub of context: a sensitive KEY is fingerprinted; an email VALUE
// inside free text is scrubbed too.
// ---------------------------------------------------------------------------
$id = MealsDB_Event_Log::record([
    'event'   => 'client.touch',
    'context' => [
        'individual_id' => 'SECRET-123',
        'note'          => 'contact bob@example.com',
    ],
]);
$ctx = (string) $wpdb->rows[$id]['context'];
assert_true(strpos($ctx, 'SECRET-123') === false, 'sensitive context key value fingerprinted');
assert_true(strpos($ctx, '[redacted:sha256=') !== false, 'sensitive key replaced with fingerprint');
assert_true(strpos($ctx, 'bob@example.com') === false, 'email inside context free text scrubbed');

// ---------------------------------------------------------------------------
// Context size cap: a large non-blob structure is truncated.
// ---------------------------------------------------------------------------
$big = [];
for ($i = 0; $i < 3000; $i++) { $big['key_' . $i] = $i; }
$id = MealsDB_Event_Log::record(['event' => 'big', 'context' => $big]);
$ctx = (string) $wpdb->rows[$id]['context'];
assert_true(strlen($ctx) <= 16384, 'oversized context capped at 16KB');
assert_true(strpos($ctx, 'truncated') !== false, 'truncation marker present');

// ---------------------------------------------------------------------------
// Fail-safe: a throwing $wpdb must NOT propagate out of record().
// ---------------------------------------------------------------------------
// NB: at top-level script scope $wpdb and $GLOBALS['wpdb'] are aliases of
// the same slot, so hold the original in a separate variable to restore
// it (assigning $GLOBALS['wpdb'] would otherwise clobber $wpdb too).
$saved_wpdb = $wpdb;
$GLOBALS['wpdb'] = new ThrowingEventLogWpdb();
$threw = false;
$ret   = null;
try {
    $ret = MealsDB_Event_Log::record(['event' => 'boom']);
} catch (\Throwable $e) {
    $threw = true;
}
assert_equal(false, $threw, 'record() swallows a throwing $wpdb (fail-safe)');
assert_equal(0, $ret, 'record() returns 0 on failure');
$GLOBALS['wpdb'] = $saved_wpdb; // restore
$wpdb = $saved_wpdb;

// ---------------------------------------------------------------------------
// Job lifecycle: start_job → running row; finish_job degraded → degraded.
// ---------------------------------------------------------------------------
MealsDB_Event_Log::_reset_started_cache();
$jid = MealsDB_Event_Log::start_job('nightly_sync', ['phase' => 1]);
assert_equal('running', $wpdb->rows[$jid]['outcome'] ?? null, 'start_job → outcome=running');
assert_equal('job', $wpdb->rows[$jid]['category'] ?? null, 'start_job → category=job');
MealsDB_Event_Log::finish_job($jid, ['records_processed' => 5], MealsDB_Event_Log::OUTCOME_DEGRADED);
assert_equal('degraded', $wpdb->rows[$jid]['outcome'] ?? null, 'finish_job degraded → outcome=degraded');
assert_equal('warning', $wpdb->rows[$jid]['severity'] ?? null, 'degraded finish bumps severity to warning');

// ---------------------------------------------------------------------------
// query() builds a bounded SELECT with the filters bound.
// ---------------------------------------------------------------------------
MealsDB_Event_Log::query([
    'category' => 'billing',
    'outcome'  => ['failed', 'degraded'],
    'search'   => 'hst',
    'limit'    => 50,
]);
$sql = $wpdb->last_prepared;
assert_true(stripos($sql, 'FROM `wp_meals_event_log`') !== false, 'query targets the trunk');
assert_true(stripos($sql, 'LIMIT %d OFFSET %d') !== false, 'query is bounded by LIMIT/OFFSET');
assert_true(stripos($sql, '`outcome` IN (%s,%s)') !== false, 'query binds the outcome IN list');
assert_true(stripos($sql, 'ORDER BY occurred_at DESC') !== false, 'query orders newest-first');

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
