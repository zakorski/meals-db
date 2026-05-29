<?php
/**
 * Tests for LB-3: MealsDB_Allocation_Rebuilder must treat finalized months as
 * immutable. A finalized client-month is a submitted government invoice and must
 * never be deleted or rewritten by the fill — neither as the rebuild TARGET nor
 * as a finalized NEIGHBOUR pulled into the 3-month window.
 *
 * These tests assert behaviour through the same mock-$wpdb seam as
 * test-allocation-rebuilder.php: we observe which DELETEs are issued, which rows
 * are INSERTed (and into which billing_month), and which dirty markers cleared.
 * "Unchanged" for a finalized month means: no DELETE touches it AND no row is
 * inserted into it — so its at-finalization detail survives byte-identical.
 *
 * Run: php tests/test-rebuilder-skips-finalized.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// WC_Order_Query type-hints wpdb; satisfy that.
if (!class_exists('wpdb')) { class wpdb {} }

/**
 * Fake wpdb that captures inserts/deletes and answers the finalized-lookup
 * get_col query from a configurable set of finalized months. Any other get_col
 * (e.g. rebuild_for_invoice's "DISTINCT client_id FROM dirty") yields the
 * configured dirty client-id list.
 */
class FinFakeWpdb extends wpdb {
    public $prefix = 'wp_';
    public array $inserts = [];        // [table => [row, ...]]
    public array $deletes = [];        // DELETE SQL strings (via query())
    public array $error_upserts = [];  // INSERT...ON DUPLICATE SQL for allocation_errors (via query()) — MAJ-2
    public array $delete_calls = [];   // [table => [where, ...]] (via delete())
    public ?int $insert_id = 1;
    public string $client_type = 'SDNB';
    public array $finalized = [];       // YYYY-MM values treated as finalized
    public array $dirty_client_ids = [1];
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function insert($table, $row, $fmt = null) {
        $this->inserts[$table][] = $row;
        return 1;
    }
    public function delete($table, $where, $fmt = null) {
        $this->delete_calls[$table][] = $where;
        return 1;
    }
    public function query($sql) {
        if (stripos($sql, 'DELETE ') === 0) { $this->deletes[] = $sql; }
        // MAJ-2: log_spillover_error now upserts via query() (INSERT ... ON
        // DUPLICATE KEY UPDATE), no longer the plain insert() seam.
        if (stripos($sql, 'INSERT INTO') !== false
            && stripos($sql, 'meals_allocation_errors') !== false) {
            $this->error_upserts[] = $sql;
        }
        return 1;
    }
    public function get_var($q) { return $this->client_type; }
    public function get_row($q, $o = null) { return null; }
    public function get_results($q, $o = null) { return []; }
    public function get_col($q) {
        if (stripos($q, 'is_finalized = 1') !== false) {
            // Return only the finalized months that appear in this query's IN(...) list.
            $out = [];
            foreach ($this->finalized as $m) {
                if (strpos($q, "'" . $m . "'") !== false) { $out[] = $m; }
            }
            return $out;
        }
        // rebuild_for_invoice: "SELECT DISTINCT client_id FROM dirty ..."
        return $this->dirty_client_ids;
    }
}

/** Fake engine: deterministic caps, records which months get re-summed. */
class FinFakeEngine extends MealsDB_Allocation_Engine {
    public array $caps = [];
    public array $recalculated = [];
    public function calculate_permitted_for_month(int $client_id, string $billing_month): array {
        return $this->caps[$billing_month] ?? ['permitted_mains' => 0, 'permitted_sides' => 0, 'effective_days' => 0];
    }
    public function recalculate_month_totals(int $client_id, string $billing_month): void {
        $this->recalculated[] = $billing_month;
    }
}

/** Test seam: inject the fake engine and a fixed delivery set. */
class FinTestable extends MealsDB_Allocation_Rebuilder {
    public array $injected_deliveries = [];
    public function inject_engine(MealsDB_Allocation_Engine $e): void {
        $rp = new ReflectionProperty(MealsDB_Allocation_Rebuilder::class, 'engine');
        $rp->setAccessible(true);
        $rp->setValue($this, $e);
    }
    protected function load_deliveries_for_months(int $client_id, array $months): array {
        return $this->injected_deliveries;
    }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; } else {
        $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true);
    }
}
function cap(int $mains, int $sides): array {
    return ['permitted_mains' => $mains, 'permitted_sides' => $sides, 'effective_days' => 31];
}
function delivery(string $date, int $mains, int $tax = 0, int $nontax = 0, int $wcid = 100): array {
    return [
        'wc_order_id'   => $wcid,
        'order_date'    => $date,
        'delivery_date' => $date,
        'mains'         => $mains,
        'tax_sides'     => $tax,
        'nontax_sides'  => $nontax,
        'coverage_end'  => $date,
    ];
}
function alloc_table(): string { return MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS); }
function err_table(): string { return MealsDB_DB::get_table_name(MealsDB_Tables::ALLOCATION_ERRORS); }
function dirty_table(): string { return MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_MONTH_DIRTY); }
function inserts_into(FinFakeWpdb $w, string $month): array {
    $out = [];
    foreach (($w->inserts[alloc_table()] ?? []) as $r) {
        if ((string) ($r['billing_month'] ?? '') === $month) { $out[] = $r; }
    }
    return $out;
}
function delete_mentions(FinFakeWpdb $w, string $month): bool {
    foreach ($w->deletes as $sql) {
        if (stripos($sql, 'delivery_allocations') !== false && strpos($sql, "'" . $month . "'") !== false) {
            return true;
        }
    }
    return false;
}
function dirty_clears(FinFakeWpdb $w): array {
    return array_map(
        static fn($x) => (string) ($x['billing_month'] ?? ''),
        $w->delete_calls[dirty_table()] ?? []
    );
}

// ---------------------------------------------------------------------------
// Case 1: Finalized TARGET month. Rebuild a finalized month -> no DELETE, no
// INSERT (its submitted detail is untouched), dirty flag consumed, no recalc.
// ---------------------------------------------------------------------------
$GLOBALS['wpdb'] = new FinFakeWpdb();
$GLOBALS['wpdb']->finalized = ['2025-03'];
$eng = new FinFakeEngine();
$eng->caps = ['2025-02' => cap(31, 31), '2025-03' => cap(31, 31), '2025-04' => cap(30, 30)];
$rb = new FinTestable();
$rb->inject_engine($eng);
$rb->injected_deliveries = [ delivery('2025-03-15', 14, 0, 0, 800) ];
$res = $rb->rebuild_client_month(1, '2025-03');

chk(count($GLOBALS['wpdb']->inserts[alloc_table()] ?? []), 0, 'target: no detail rows written for a finalized target');
chk(count($GLOBALS['wpdb']->deletes), 0, 'target: no DELETE issued for a finalized target');
chk($res, ['mains_unplaced' => 0, 'sides_unplaced' => 0], 'target: returns zero unplaced');
chk(dirty_clears($GLOBALS['wpdb']), ['2025-03'], 'target: dirty flag for the finalized target is consumed');
chk($eng->recalculated, [], 'target: no summary recalculated for a finalized target');

// ---------------------------------------------------------------------------
// Case 2: Finalized NEIGHBOUR. Finalize May (prior); rebuild dirty June with an
// order. May's detail must be untouched (no DELETE, no INSERT into May); June is
// rebuilt normally; the finalized neighbour's summary is NOT recomputed.
// Window for June = {May, June, July}.
// ---------------------------------------------------------------------------
$GLOBALS['wpdb'] = new FinFakeWpdb();
$GLOBALS['wpdb']->finalized = ['2025-05'];
$eng = new FinFakeEngine();
$eng->caps = ['2025-05' => cap(31, 31), '2025-06' => cap(31, 100), '2025-07' => cap(31, 100)];
$rb = new FinTestable();
$rb->inject_engine($eng);
$rb->injected_deliveries = [ delivery('2025-06-10', 12, 0, 0, 810) ];
$res = $rb->rebuild_client_month(1, '2025-06');

chk(delete_mentions($GLOBALS['wpdb'], '2025-05'), false, 'neighbour: finalized May is excluded from the DELETE');
chk(count(inserts_into($GLOBALS['wpdb'], '2025-05')), 0, 'neighbour: nothing inserted into finalized May');
chk(delete_mentions($GLOBALS['wpdb'], '2025-06'), true, 'neighbour: open June IS cleared');
chk(count(inserts_into($GLOBALS['wpdb'], '2025-06')), 1, 'neighbour: June rebuilt with its delivery');
chk((int) inserts_into($GLOBALS['wpdb'], '2025-06')[0]['mains_count'], 12, 'neighbour: June row has 12 mains');
chk(in_array('2025-05', $eng->recalculated, true), false, 'neighbour: finalized May summary NOT recomputed');
chk(in_array('2025-06', $eng->recalculated, true), true, 'neighbour: open June summary recomputed');

// ---------------------------------------------------------------------------
// Case 3: Spill INTO a finalized month. Finalize June (next); rebuild May whose
// delivery overflows. The overflow cannot be added to finalized June -> it is
// counted as unplaced (spillover error), and NO row is written into June.
// Window for May = {Apr, May, June}; May cap small forces the spill.
// ---------------------------------------------------------------------------
$GLOBALS['wpdb'] = new FinFakeWpdb();
$GLOBALS['wpdb']->finalized = ['2025-06'];
$eng = new FinFakeEngine();
$eng->caps = ['2025-04' => cap(0, 0), '2025-05' => cap(5, 100), '2025-06' => cap(31, 100)];
$rb = new FinTestable();
$rb->inject_engine($eng);
$rb->injected_deliveries = [ delivery('2025-05-15', 20, 0, 0, 820) ];
$res = $rb->rebuild_client_month(1, '2025-05');

chk(count(inserts_into($GLOBALS['wpdb'], '2025-05')), 1, 'spill: May filled to its cap (1 row)');
chk((int) inserts_into($GLOBALS['wpdb'], '2025-05')[0]['mains_count'], 5, 'spill: May row capped at 5 mains');
chk(count(inserts_into($GLOBALS['wpdb'], '2025-06')), 0, 'spill: NO row written into finalized June');
chk(delete_mentions($GLOBALS['wpdb'], '2025-06'), false, 'spill: finalized June not deleted');
chk((int) $res['mains_unplaced'], 15, 'spill: 15 mains counted as unplaced (cannot enter finalized June)');
$errs = $GLOBALS['wpdb']->error_upserts;  // MAJ-2: upserted via query()
chk(count($errs), 1, 'spill: one spillover error logged');
chk(stripos($errs[0], 'multi_month_spillover') !== false, true, 'spill: error tagged multi_month_spillover');
chk(stripos($errs[0], 'ON DUPLICATE KEY UPDATE') !== false, true, 'spill: error write is an upsert (dedup)');

// ---------------------------------------------------------------------------
// Case 4: Re-invoice no-op. rebuild_for_invoice() funnels through
// rebuild_client_month, so a finalized month is a no-op for its detail.
// ---------------------------------------------------------------------------
$GLOBALS['wpdb'] = new FinFakeWpdb();
$GLOBALS['wpdb']->finalized = ['2025-03'];
$GLOBALS['wpdb']->dirty_client_ids = [1];
$eng = new FinFakeEngine();
$eng->caps = ['2025-02' => cap(31, 31), '2025-03' => cap(31, 31), '2025-04' => cap(30, 30)];
$rb = new FinTestable();
$rb->inject_engine($eng);
$rb->injected_deliveries = [ delivery('2025-03-15', 14, 0, 0, 830) ];
$stats = $rb->rebuild_for_invoice('2025-03', [1]);

chk(count($GLOBALS['wpdb']->inserts[alloc_table()] ?? []), 0, 're-invoice: no detail written for finalized month');
chk(count($GLOBALS['wpdb']->deletes), 0, 're-invoice: no DELETE issued for finalized month');
chk((int) $stats['rebuilt'], 1, 're-invoice: the client-month was visited (counted) but produced no writes');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
