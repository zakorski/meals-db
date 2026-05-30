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

// STR-LOG trunk: retention prunes the trunk (meals_event_log) in 4 passes
// — three severity bands plus the aged degraded/failed pass. Directive
// MAJ-2 adds ONE MORE pass for meals_allocation_errors, for 5 total.
assert_equal(5, count($wpdb->deletes), 'retention issued 5 DELETEs (3 severity bands + unresolved + allocation_errors)');

// Every pass must be bounded by LIMIT (a giant backlog could otherwise
// lock the table for seconds) and target one of the two pruned tables.
foreach ($wpdb->deletes as $i => $sql) {
    assert_true(
        preg_match('/LIMIT\s+\d+/i', $sql) === 1,
        sprintf('DELETE #%d includes LIMIT clause', $i)
    );
    assert_true(
        stripos($sql, 'meals_event_log') !== false
            || stripos($sql, 'meals_allocation_errors') !== false,
        sprintf('DELETE #%d targets the event_log trunk or allocation_errors', $i)
    );
}

// MAJ-2: exactly one pass prunes allocation_errors, bounded, and BY
// last_seen_at (never first_seen_at — a recurring error stays active).
$alloc_deletes = array_values(array_filter($wpdb->deletes, function ($sql) {
    return stripos($sql, 'meals_allocation_errors') !== false;
}));
assert_equal(1, count($alloc_deletes), 'exactly one allocation_errors prune pass');
$alloc_sql = $alloc_deletes[0] ?? '';
assert_true(
    preg_match('/LIMIT\s+\d+/i', $alloc_sql) === 1,
    'allocation_errors DELETE is bounded by LIMIT'
);
assert_true(
    stripos($alloc_sql, 'last_seen_at') !== false,
    'allocation_errors prune keys on last_seen_at'
);
assert_true(
    stripos($alloc_sql, 'first_seen_at') === false,
    'allocation_errors prune does NOT key on first_seen_at (recurring errors stay active)'
);

// No pass may ever delete a 'running' row (hang detection). The band
// passes exclude it explicitly; the unresolved pass targets only
// degraded/failed (neither of which is running).
foreach ($wpdb->deletes as $sql) {
    assert_true(
        stripos($sql, "outcome IN ('running'") === false,
        'no DELETE targets running rows'
    );
}

// At least one band pass must exclude running/degraded/failed (those are
// deferred to the long-window unresolved pass).
$band_excludes = false;
foreach ($wpdb->deletes as $sql) {
    if (stripos($sql, "outcome NOT IN ('running', 'degraded', 'failed')") !== false) {
        $band_excludes = true;
        break;
    }
}
assert_true($band_excludes, 'severity-band DELETE excludes running/degraded/failed');

// The unresolved pass deletes aged degraded/failed only.
$unresolved = null;
foreach ($wpdb->deletes as $sql) {
    if (stripos($sql, "outcome IN ('degraded', 'failed')") !== false) {
        $unresolved = $sql;
        break;
    }
}
assert_true($unresolved !== null, 'aged degraded/failed DELETE present');
assert_true(
    preg_match('/occurred_at < \'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\'/', $unresolved) === 1,
    'cutoff is a normal UTC datetime literal'
);

// Confirm the retention job itself logs a run to the trunk via the
// Job_Logger facade (category='job', event='log_retention').
$found = false;
foreach ($wpdb->rows as $row) {
    if (($row['event'] ?? '') === 'log_retention' && ($row['category'] ?? '') === 'job') {
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
