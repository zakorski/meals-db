<?php
/**
 * Tests for directive MAJ-2 — allocation_errors dedup (upsert).
 *
 * MealsDB_Allocation_Rebuilder::log_spillover_error() used to do a bare
 * insert() every time, so the nightly rebuilder re-processing a still-broken
 * dirty month wrote a fresh identical row each run (7 rows after a week of an
 * unresolved spillover). It now UPSERTs on the natural identity
 * (client_id, billing_month, wc_order_id, error_type): a repeat bumps
 * occurrence_count and refreshes last_seen_at + the latest figures/message,
 * but does NOT add a row and does NOT overwrite first_seen_at.
 *
 * The dedup itself is enforced by the DB UNIQUE index (uniq_dedup) +
 * INSERT ... ON DUPLICATE KEY UPDATE. We can't run real MySQL here, so the
 * fake wpdb below SIMULATES the unique-key store with exactly those
 * semantics, and we additionally assert the prepared SQL has the right shape
 * (ON DUPLICATE clause refreshes count/last_seen/figures, leaves first_seen
 * out) so the behavioural sim can't drift from the real statement.
 *
 * Run: php tests/test-allocation-errors-dedup.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// WC_Order_Query type-hints wpdb; satisfy that.
if (!class_exists('wpdb')) { class wpdb {} }

/**
 * Fake wpdb that simulates the allocation_errors UNIQUE-key upsert. It parses
 * the prepared INSERT, keys rows on the dedup tuple, and applies ON DUPLICATE
 * KEY UPDATE semantics: first INSERT seeds the row; a repeat on the same key
 * bumps occurrence_count, refreshes mains/sides/message/last_seen_at, and
 * KEEPS first_seen_at (the production UPDATE clause omits it).
 */
class DedupFakeWpdb extends wpdb {
    public $prefix = 'wp_';
    public ?int $insert_id = 1;
    public array $err_rows = [];   // keyed by dedup tuple
    public array $upsert_sql = []; // every error upsert SQL string seen

    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function insert($table, $row, $fmt = null) { return 1; }
    public function delete($table, $where, $fmt = null) { return 1; }
    public function query($sql) {
        if (stripos($sql, 'INSERT INTO') !== false
            && stripos($sql, 'meals_allocation_errors') !== false) {
            $this->upsert_sql[] = $sql;
            $this->apply_upsert($sql);
        }
        return 1;
    }
    public function get_var($q) { return null; }
    public function get_row($q, $o = null) { return null; }
    public function get_results($q, $o = null) { return []; }
    public function get_col($q) { return []; }

    private function apply_upsert(string $sql): void {
        if (!preg_match(
            '/VALUES\s*\((\d+),\s*\'([^\']*)\',\s*(\d+),\s*\'([^\']*)\',\s*(\d+),\s*(\d+),\s*\'([^\']*)\',\s*(\d+),\s*\'([^\']*)\',\s*\'([^\']*)\'\)/s',
            $sql, $m
        )) {
            return;
        }
        $key = $m[1] . '|' . $m[2] . '|' . $m[3] . '|' . $m[4];
        if (isset($this->err_rows[$key])) {
            // ON DUPLICATE KEY UPDATE — bump count, refresh the figures /
            // message / last_seen; first_seen_at is deliberately NOT touched.
            $row =& $this->err_rows[$key];
            $row['occurrence_count'] = (int) $row['occurrence_count'] + 1;
            $row['mains_unplaced']   = (int) $m[5];
            $row['sides_unplaced']   = (int) $m[6];
            $row['message']          = $m[7];
            $row['last_seen_at']     = $m[10];
            return;
        }
        $this->err_rows[$key] = [
            'client_id'        => (int) $m[1],
            'billing_month'    => $m[2],
            'wc_order_id'      => (int) $m[3],
            'error_type'       => $m[4],
            'mains_unplaced'   => (int) $m[5],
            'sides_unplaced'   => (int) $m[6],
            'message'          => $m[7],
            'occurrence_count' => (int) $m[8],
            'first_seen_at'    => $m[9],
            'last_seen_at'     => $m[10],
        ];
    }
}

/** Expose the private log_spillover_error for direct exercise. */
class DedupTestable extends MealsDB_Allocation_Rebuilder {
    public function log_error(
        int $client_id, string $billing_month, int $wc_order_id,
        int $mains, int $sides, string $message
    ): void {
        $rm = new ReflectionMethod(MealsDB_Allocation_Rebuilder::class, 'log_spillover_error');
        $rm->setAccessible(true);
        $rm->invoke($this, $client_id, $billing_month, $wc_order_id, $mains, $sides, $message);
    }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; } else {
        $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true);
    }
}

$GLOBALS['wpdb'] = new DedupFakeWpdb();
$rb = new DedupTestable();

// ---------------------------------------------------------------------------
// T-1: first occurrence inserts one row, occurrence_count = 1, timestamps set.
// ---------------------------------------------------------------------------
$rb->log_error(1, '2025-01', 300, 10, 0, 'first spillover');
chk(count($GLOBALS['wpdb']->err_rows), 1, 'T-1: one row after first occurrence');
$row = array_values($GLOBALS['wpdb']->err_rows)[0];
chk((int) $row['occurrence_count'], 1, 'T-1: occurrence_count starts at 1');
chk($row['error_type'], 'multi_month_spillover', 'T-1: error type tagged');
chk($row['first_seen_at'] !== '' && $row['first_seen_at'] !== null, true, 'T-1: first_seen_at set');
chk($row['last_seen_at'] !== '' && $row['last_seen_at'] !== null, true, 'T-1: last_seen_at set');
$first_seen_initial = $row['first_seen_at'];

// ---------------------------------------------------------------------------
// T-2: a repeat on the SAME key dedups — no new row, count bumps to 2, the
// figures + message refresh, first_seen_at is preserved.
// ---------------------------------------------------------------------------
$rb->log_error(1, '2025-01', 300, 14, 5, 'second spillover, worse');
chk(count($GLOBALS['wpdb']->err_rows), 1, 'T-2: still one row after repeat (dedup)');
$row = array_values($GLOBALS['wpdb']->err_rows)[0];
chk((int) $row['occurrence_count'], 2, 'T-2: occurrence_count bumped to 2');
chk((int) $row['mains_unplaced'], 14, 'T-2: mains_unplaced refreshed to latest');
chk((int) $row['sides_unplaced'], 5, 'T-2: sides_unplaced refreshed to latest');
chk($row['message'], 'second spillover, worse', 'T-2: message refreshed to latest');
chk($row['first_seen_at'], $first_seen_initial, 'T-2: first_seen_at unchanged');

// Structural guard: the prepared statement must refresh count/last_seen and
// NOT include first_seen_at in the UPDATE clause (that's what preserves it).
$last_sql = end($GLOBALS['wpdb']->upsert_sql);
$update_clause = substr($last_sql, stripos($last_sql, 'ON DUPLICATE KEY UPDATE'));
chk(stripos($update_clause, 'occurrence_count = occurrence_count + 1') !== false, true,
    'T-2: UPDATE clause increments occurrence_count');
chk(stripos($update_clause, 'last_seen_at') !== false, true,
    'T-2: UPDATE clause refreshes last_seen_at');
chk(stripos($update_clause, 'first_seen_at') === false, true,
    'T-2: UPDATE clause does NOT touch first_seen_at');

// ---------------------------------------------------------------------------
// T-3: a different key (different order id) inserts a separate row.
// ---------------------------------------------------------------------------
$rb->log_error(1, '2025-01', 301, 3, 0, 'different order');
chk(count($GLOBALS['wpdb']->err_rows), 2, 'T-3: different order_id is a separate row');

// A different month and a different error_type also key separately.
$rb->log_error(1, '2025-02', 300, 1, 0, 'different month');
chk(count($GLOBALS['wpdb']->err_rows), 3, 'T-3: different month is a separate row');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
