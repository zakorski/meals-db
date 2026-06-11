<?php
/**
 * BC-2 regression tests — monthly client-contribution lifecycle.
 *
 * Two bugs the fix closes:
 *   (1) deallocate_order() never released the contribution flag, even though
 *       the order-fees header claimed it did. A cancelled/refunded carrier
 *       order left contribution_applied=1 forever → the month was never
 *       re-billed the contribution.  → deallocate_order() must clear
 *       contribution_applied / contribution_order_id (keyed on the leaving
 *       order), on the unconditional path (even with no delivery rows).
 *   (2) apply_to_order() keyed the flag on gmdate('Y-m') (wall-clock month),
 *       not the order's billing month → a back-dated order flagged the wrong
 *       month.  → derive the month from the order's creation timestamp (UTC).
 *
 * Run: php tests/test-contribution-lifecycle.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

/**
 * Fake wpdb that records every query()/delete() and answers the one
 * get_results() deallocate_order issues.
 */
class BC2FakeWpdb extends wpdb {
    public $prefix = 'wp_';
    public array $queries = [];        // all SQL passed to query()
    public array $deletes = [];        // delete() table names
    public array $dealloc_rows = [];   // rows returned for the affected-months SELECT
    public $insert_id = 1;

    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function query($sql)  { $this->queries[] = $sql; return 1; }
    public function delete($table, $where, $fmt = null) { $this->deletes[] = $table; return 1; }
    public function get_results($q, $o = null) { return $this->dealloc_rows; }
    public function get_var($q) { return null; }
    public function get_col($q) { return []; }
    public function insert($t, $r, $f = null) { return 1; }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function any_query_matches(BC2FakeWpdb $w, callable $pred): bool {
    foreach ($w->queries as $sql) { if ($pred($sql)) { return true; } }
    return false;
}

// ---------------------------------------------------------------------------
// [BC2-1] Release on a contribution-only order (no delivery_allocations rows).
//   The flag-clear must run on the unconditional path, BEFORE the empty-rows
//   early-return — a contribution-only order has no meal rows but must still
//   release the month.
// ---------------------------------------------------------------------------
$w = new BC2FakeWpdb();
$w->dealloc_rows = []; // no delivery rows for this order
$GLOBALS['wpdb'] = $w;
(new MealsDB_Allocation_Engine())->deallocate_order(789);

chk(any_query_matches($w, static function ($sql) {
    return stripos($sql, 'contribution_applied = 0') !== false
        && stripos($sql, 'contribution_order_id = 789') !== false;
}), true, '[BC2-1] contribution flag released even with no delivery rows');

// ---------------------------------------------------------------------------
// [BC2-2] Release keyed on the LEAVING order id, not the client — so cancelling
//   a non-carrier order must not clear a flag set by a different order.
//   (Structural: the WHERE targets contribution_order_id = <this order>.)
// ---------------------------------------------------------------------------
chk(any_query_matches($w, static function ($sql) {
    return stripos($sql, 'WHERE contribution_order_id = 789') !== false
        && stripos($sql, 'client_id') === false; // not keyed on client
}), true, '[BC2-2] release is keyed on contribution_order_id, not client_id');

// ---------------------------------------------------------------------------
// [BC2-3] With delivery rows present: still releases the flag AND marks dirty.
// ---------------------------------------------------------------------------
$w = new BC2FakeWpdb();
$w->dealloc_rows = [ ['client_id' => 5, 'billing_month' => '2025-04'] ];
$GLOBALS['wpdb'] = $w;
(new MealsDB_Allocation_Engine())->deallocate_order(790);

chk(any_query_matches($w, static function ($sql) {
    return stripos($sql, 'contribution_applied = 0') !== false
        && stripos($sql, 'contribution_order_id = 790') !== false;
}), true, '[BC2-3] flag released when delivery rows exist');
chk(any_query_matches($w, static function ($sql) {
    return stripos($sql, 'client_month_dirty') !== false && stripos($sql, 'INSERT') !== false;
}), true, '[BC2-3] affected client-month still marked dirty');

// ---------------------------------------------------------------------------
// [BC2-4] Contribution month is the order's UTC month, not the wall-clock month.
//   contribution_month_for_timestamp(ts) === gmdate('Y-m', ts); null → now.
// ---------------------------------------------------------------------------
$march_ts = gmmktime(12, 0, 0, 3, 15, 2025); // 2025-03-15 12:00 UTC
chk(MealsDB_Order_Fees::contribution_month_for_timestamp($march_ts), '2025-03',
    '[BC2-4] month derived from the order timestamp (UTC), not now');
chk(MealsDB_Order_Fees::contribution_month_for_timestamp(null), gmdate('Y-m'),
    '[BC2-4] null timestamp falls back to the current UTC month');

// ---------------------------------------------------------------------------
echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
