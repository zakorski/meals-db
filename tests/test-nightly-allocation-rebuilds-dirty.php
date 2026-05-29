<?php
/**
 * Tests for LB-1: the nightly allocation sync must MATERIALISE dirty
 * client-months, not merely re-sum existing detail.
 *
 * Why this test exists (recon-12.5): the rebuilder works in isolation
 * (test-allocation-rebuilder.php) and the re-sum works in isolation — but
 * nothing asserted that the NIGHTLY path actually CALLS the rebuilder. The bug
 * was a re-sum-only nightly_sync(): a client-month marked dirty but never
 * materialised (no invoice run, no manual recalc) had no meals_delivery_allocations
 * detail to sum, so the job wrote a zero/stale summary and reported success.
 *
 * The decisive assertion is that DETAIL rows are CREATED for a dirty,
 * previously-unbuilt client-month — a re-sum-only implementation would pass a
 * "summary exists" check but fail this one.
 *
 * nightly_sync() is static and constructs its own engine/rebuilder (no DI), so
 * per the directive we (a) exercise the rebuild step directly through the same
 * dirty-queue entry point the handler uses (rebuild_all_dirty), and (b) assert
 * the handler source is wired to call rebuild_all_dirty() BEFORE the re-sum.
 *
 * Run: php tests/test-nightly-allocation-rebuilds-dirty.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!class_exists('wpdb')) { class wpdb {} }

/**
 * Fake wpdb. Seeds ONE dirty (client, month) pair via get_results, reports the
 * client as government (SDNB), reports NO finalized months, and captures the
 * detail-row INSERTs and dirty-flag clears so we can prove materialisation.
 *
 * Crucially it returns NO existing meals_delivery_allocations detail (get_var /
 * get_results for sums are empty) — so the only way detail can exist after the
 * run is if the rebuilder CREATED it.
 */
class NightlyFakeWpdb extends wpdb {
    public $prefix = 'wp_';
    public array $inserts = [];        // [table => [row, ...]]
    public array $deletes = [];        // DELETE SQL strings
    public array $delete_calls = [];   // [table => [where, ...]]
    public ?int $insert_id = 1;
    /** @var array<int,array{client_id:int,billing_month:string}> */
    public array $dirty_rows = [['client_id' => 1, 'billing_month' => '2025-01']];
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function insert($table, $row, $fmt = null) { $this->inserts[$table][] = $row; return 1; }
    public function delete($table, $where, $fmt = null) { $this->delete_calls[$table][] = $where; return 1; }
    public function query($sql) { if (stripos($sql, 'DELETE ') === 0) { $this->deletes[] = $sql; } return 1; }
    public function get_var($q) { return 'SDNB'; }   // government client_type
    public function get_row($q, $o = null) { return null; }
    public function get_results($q, $o = null) {
        // The dirty-queue read (rebuild_all_dirty): "SELECT client_id, billing_month FROM ... dirty".
        if (stripos($q, 'client_id, billing_month') !== false) {
            return $this->dirty_rows;
        }
        return [];
    }
    public function get_col($q) {
        return []; // no finalized months; no other DISTINCT lists needed here
    }
}

/** Fake engine: caps for the rebuild window; records re-sum requests. */
class NightlyFakeEngine extends MealsDB_Allocation_Engine {
    public array $caps = [];
    public array $recalculated = [];
    public function calculate_permitted_for_month(int $client_id, string $billing_month): array {
        return $this->caps[$billing_month] ?? ['permitted_mains' => 0, 'permitted_sides' => 0, 'effective_days' => 0];
    }
    public function recalculate_month_totals(int $client_id, string $billing_month): void {
        $this->recalculated[] = $billing_month;
    }
}

/** Test seam: inject the fake engine and a fixed delivery set for the dirty month. */
class NightlyTestable extends MealsDB_Allocation_Rebuilder {
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
function dirty_table(): string { return MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_MONTH_DIRTY); }

// ---------------------------------------------------------------------------
// Test A: a dirty client-month with orders but NO detail rows is MATERIALISED.
// This is the nightly entry point's rebuild step (rebuild_all_dirty), the exact
// method the fixed handler calls. After it runs, detail rows must EXIST for the
// dirty month and the dirty flag must be cleared.
// ---------------------------------------------------------------------------
$GLOBALS['wpdb'] = new NightlyFakeWpdb();
$eng = new NightlyFakeEngine();
$eng->caps = ['2024-12' => cap(31, 31), '2025-01' => cap(31, 100), '2025-02' => cap(28, 100)];
$rb = new NightlyTestable();
$rb->inject_engine($eng);
$rb->injected_deliveries = [ delivery('2025-01-15', 10, 4, 3, 900) ];

// Pre-condition: no detail rows for the dirty month yet.
chk(count($GLOBALS['wpdb']->inserts[alloc_table()] ?? []), 0, 'pre: no detail rows exist before the run');

$stats = $rb->rebuild_all_dirty();

$detail = array_values(array_filter(
    $GLOBALS['wpdb']->inserts[alloc_table()] ?? [],
    static fn($r) => (string) ($r['billing_month'] ?? '') === '2025-01'
));
chk(count($detail) >= 1, true, 'materialise: DETAIL rows created for the dirty client-month (not just a summary)');
chk((int) $detail[0]['mains_count'], 10, 'materialise: the delivery\'s mains were written');
chk((int) $detail[0]['tax_sides_count'] + (int) $detail[0]['nontax_sides_count'], 7, 'materialise: the delivery\'s sides were written');
chk((int) $stats['rebuilt'], 1, 'materialise: one dirty client-month processed');

// Dirty flag for the materialised month must be consumed.
$cleared = array_map(
    static fn($w) => (string) ($w['billing_month'] ?? ''),
    $GLOBALS['wpdb']->delete_calls[dirty_table()] ?? []
);
chk(in_array('2025-01', $cleared, true), true, 'materialise: the dirty flag is cleared/consumed');

// ---------------------------------------------------------------------------
// Test B: the nightly handler is WIRED to call rebuild_all_dirty() BEFORE the
// bulk_recalculate_month re-sum. A re-sum-only handler (the bug) would have no
// rebuild_all_dirty() call at all. We assert against the handler's own source.
// ---------------------------------------------------------------------------
$rm  = new ReflectionMethod('MealsDB_Allocation_Hooks', 'nightly_sync');
$src = file($rm->getFileName());
$body = implode('', array_slice($src, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));

// Match the actual method-call syntax ("->method(") so prose in comments
// (which also mentions these names) can't fool the ordering assertion.
$pos_rebuild = strpos($body, '->rebuild_all_dirty(');
$pos_resum   = strpos($body, '->bulk_recalculate_month(');
chk($pos_rebuild !== false, true, 'wired: nightly_sync calls rebuild_all_dirty()');
chk(strpos($body, 'new MealsDB_Allocation_Rebuilder(') !== false, true, 'wired: nightly_sync instantiates the rebuilder');
chk($pos_rebuild !== false && $pos_resum !== false && $pos_rebuild < $pos_resum, true,
    'wired: rebuild happens BEFORE the re-sum (materialise, then re-sum non-empty detail)');
chk(strpos($body, 'dirty_rebuild_stats') !== false, true, 'wired: rebuild stats are recorded in the finish() payload');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
