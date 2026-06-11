<?php
/**
 * BC-3 regression tests — orders into a finalized month must not vanish.
 *
 * Gap 1 (this suite): when rebuild_client_month hits a FINALIZED target month
 *   it previously logged to error_log, CONSUMED the dirty flag (clear_dirty),
 *   and returned zeros — so a back-dated order for a submitted month was
 *   silently dropped with no operator-visible signal. The fix must:
 *     - NOT clear the dirty flag (leave the month queued), and
 *     - emit a `degraded` event so the operator can act,
 *   while still touching no detail rows (the invoice stays immutable).
 *
 * Gap 2 (unfinalize re-dirties affected client-months) lives in
 *   MealsDB_Invoice_Draft::unfinalize(); its dependencies (draft decryption,
 *   reference-counted locks) make it integration-tested via the existing
 *   invoice-draft suite rather than a unit fixture here.
 *
 * Run: php tests/test-finalized-order-boundary.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
// MealsDB_Event_Log::record() serializes its context via wp_json_encode().
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

/**
 * Fake wpdb: reports the target month as finalized (get_col), the client as
 * non-Private (get_var), and captures inserts (by table) + delete() calls so
 * we can prove the dirty flag is NOT cleared and a degraded event IS recorded.
 */
class BC3FakeWpdb extends wpdb {
    public $prefix = 'wp_';
    public array $finalized = [];      // months get_col reports finalized
    public string $client_type = 'SDNB';
    public array $inserts = [];        // table => [row, ...]
    public array $deleted_tables = []; // tables passed to delete()
    public $insert_id = 1;

    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function insert($table, $row, $fmt = null) { $this->inserts[$table][] = $row; return 1; }
    public function delete($table, $where, $fmt = null) { $this->deleted_tables[] = $table; return 1; }
    public function query($sql) { return 1; }
    public function get_var($q) {
        if (stripos($q, 'client_type') !== false) { return $this->client_type; }
        return null;
    }
    public function get_col($q) { return $this->finalized; }       // finalized_months()
    public function get_results($q, $o = null) { return []; }
    public function get_row($q, $o = null) { return null; }
}

class BC3StubEngine {
    public function calculate_permitted_for_month(int $c, string $m): array {
        return ['permitted_mains' => 30, 'permitted_sides' => 100, 'effective_days' => 31];
    }
    public function recalculate_month_totals(int $c, string $m): void {}
    public function calculate_delivery_schedule(int $c, string $m): array { return []; }
    public function resolve_client_id_for_order(int $id): int { return 0; }
}

class BC3Rebuilder extends MealsDB_Allocation_Rebuilder {
    public function inject($wpdb, $engine): void {
        foreach (['wpdb' => $wpdb, 'engine' => $engine] as $p => $v) {
            $rp = new ReflectionProperty(MealsDB_Allocation_Rebuilder::class, $p);
            $rp->setAccessible(true);
            $rp->setValue($this, $v);
        }
    }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}

// ---------------------------------------------------------------------------
// Rebuild a finalized target month.
// ---------------------------------------------------------------------------
$w = new BC3FakeWpdb();
$w->finalized   = ['2025-04'];   // target month is finalized
$w->client_type = 'SDNB';
$GLOBALS['wpdb'] = $w;
$rb = new BC3Rebuilder();
$rb->inject($w, new BC3StubEngine());
$res = $rb->rebuild_client_month(7, '2025-04');

$dirty_table  = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_MONTH_DIRTY);
$event_table  = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
$alloc_table  = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);

// [BC3-1] the dirty flag must NOT be cleared — the month stays queued.
chk(in_array($dirty_table, $w->deleted_tables, true), false,
    '[BC3-1] finalized-skip does NOT clear the dirty flag (order not dropped)');

// [BC3-2] a degraded event must be recorded so the operator can act.
$event_rows = $w->inserts[$event_table] ?? [];
$has_degraded = false;
foreach ($event_rows as $row) {
    if (($row['outcome'] ?? '') === 'degraded') { $has_degraded = true; break; }
}
chk($has_degraded, true, '[BC3-2] a degraded event is recorded for the finalized-month order activity');

// [BC3-3] the finalized month is untouched — no delivery_allocations written.
chk(empty($w->inserts[$alloc_table] ?? []), true,
    '[BC3-3] no detail rows written to the finalized (immutable) month');

// [BC3-4] return value is zero-unplaced (skip, not a spillover).
chk((int) ($res['mains_unplaced'] ?? -1), 0, '[BC3-4] returns zero unplaced on finalized skip');

// ---------------------------------------------------------------------------
echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
