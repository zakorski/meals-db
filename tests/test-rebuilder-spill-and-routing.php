<?php
/**
 * BC-1 regression tests — rebuilder spill loss & order→client routing.
 *
 * ====================================================================
 *  Regression suite for directive-BC-1-rebuilder-spill-and-routing.md
 *  (now implemented). Written test-first; each assertion notes whether
 *  it targets a bug the fix closes or is a GUARD against regression.
 * ====================================================================
 *
 * Bug A (spill loss / under-billing): rebuilding month M deletes rows
 *   whose billing_month is in the {prior,current,next} window, but the
 *   delivery loader only reloads deliveries whose DELIVERY-month is in
 *   that window. A delivery that spilled FORWARD from prior-prior into
 *   prior gets its row deleted and never re-placed → silent loss.
 *   Fix: load a 4-month window [prior-prior, prior, current, next] as a
 *   "consume-only" leading edge (placement counts, but prior-prior rows
 *   are neither deleted nor re-written).
 *
 * Bug B (routing / double- & mis-billing): the order pull keys solely on
 *   `o.customer_id = wp_user_id` with no wp_user_id>0 guard and ignores
 *   the `mealsdb_client_id` order meta. Consequences: a client with
 *   wp_user_id=0 claims every guest order; a meta-pinned order is never
 *   loaded; a dual-program user (two clients, one wp_user) double-counts.
 *
 * Run: php tests/test-rebuilder-spill-and-routing.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!class_exists('wpdb')) { class wpdb {} }

// ---------------------------------------------------------------------------
//  Stateful fake wpdb: models the delivery_allocations table (INSERT appends,
//  DELETE removes by client_id + billing_month IN (...)), and answers the
//  scalar/result queries the rebuilder issues. SQL-content-sensitive so it
//  serves the current code (single customer_id query) AND the fixed code
//  (pinned-by-meta query + guarded customer query + multi-client filter).
// ---------------------------------------------------------------------------
class BC1FakeWpdb extends wpdb {
    public $prefix = 'wp_';

    // Knobs the tests set:
    public int $wp_user_id = 0;            // what "SELECT wp_user_id ..." returns
    public int $multi_client_count = 1;    // what a COUNT(*) clients probe returns
    public string $client_type = 'SDNB';   // what "SELECT client_type ..." returns
    public array $customer_orders = [];    // rows for the customer_id = N query
    public array $pinned_orders   = [];    // rows for the mealsdb_client_id = N query
    public array $products = [];           // wc_product_id => ['product_type'=>..,'taxable'=>..]

    // State / capture:
    public array $alloc_rows = [];         // persisted delivery_allocations rows
    public array $deletes = [];            // DELETE SQL strings via query()

    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }

    public function insert($table, $row, $fmt = null) {
        // Only the delivery_allocations table is modelled as state.
        if (stripos($table, 'delivery_allocations') !== false) {
            $this->alloc_rows[] = $row;
        }
        return 1;
    }

    public function delete($table, $where, $fmt = null) {
        // dirty-flag clears etc. — not modelled, just succeed.
        return 1;
    }

    public function query($sql) {
        if (stripos($sql, 'DELETE') === 0 && stripos($sql, 'delivery_allocations') !== false) {
            $this->deletes[] = $sql;
            preg_match('/client_id = (\d+)/', $sql, $cm);
            preg_match_all("/'(\d{4}-\d{2})'/", $sql, $mm);
            $cid    = (int) ($cm[1] ?? 0);
            $months = $mm[1] ?? [];
            $this->alloc_rows = array_values(array_filter(
                $this->alloc_rows,
                static function ($r) use ($cid, $months) {
                    return !((int) $r['client_id'] === $cid
                        && in_array((string) $r['billing_month'], $months, true));
                }
            ));
        }
        // mark_dirty INSERT ... ON DUPLICATE etc. — succeed silently.
        return 1;
    }

    public function get_var($q) {
        if (stripos($q, 'COUNT') !== false)        { return $this->multi_client_count; }
        if (stripos($q, 'client_type') !== false)  { return $this->client_type; }
        if (stripos($q, 'wp_user_id') !== false)   { return $this->wp_user_id; }
        return null;
    }

    public function get_row($q, $o = null) {
        if (stripos($q, 'product_type') !== false) {
            // Pull the wc_product_id out of the prepared SQL.
            if (preg_match('/wc_product_id = (\d+)/', $q, $m)) {
                $pid = (int) $m[1];
                return $this->products[$pid] ?? ['product_type' => 'meal', 'taxable' => 0];
            }
            return ['product_type' => 'meal', 'taxable' => 0];
        }
        return null;
    }

    public function get_results($q, $o = null) {
        // Check customer_id FIRST: the fixed code's customer query also mentions
        // mealsdb_client_id inside a NOT EXISTS subquery, so we must not let that
        // misroute it to the pinned bucket.
        if (stripos($q, 'customer_id =') !== false && stripos($q, 'wc_orders') !== false) {
            return $this->customer_orders;
        }
        if (stripos($q, 'mealsdb_client_id') !== false) {
            return $this->pinned_orders;
        }
        return [];
    }

    public function get_col($q) { return []; } // finalized_months → none finalized
}

// ---------------------------------------------------------------------------
//  Plain stubs injected into the (untyped) engine / order_query properties.
// ---------------------------------------------------------------------------
class BC1StubEngine {
    public array $caps = [];               // 'YYYY-MM' => cap()
    public array $recalculated = [];
    public array $order_to_client = [];    // wc_order_id => client_id (BC-1 resolver)

    public function calculate_permitted_for_month(int $client_id, string $billing_month): array {
        return $this->caps[$billing_month]
            ?? ['permitted_mains' => 0, 'permitted_sides' => 0, 'effective_days' => 0];
    }
    public function recalculate_month_totals(int $client_id, string $billing_month): void {
        $this->recalculated[] = $billing_month;
    }
    public function calculate_delivery_schedule(int $client_id, string $month): array {
        return []; // empty → delivery_date defaults to order_date in the loader
    }
    // BC-1: the fix disambiguates a dual-program user's orders via this resolver.
    // (Name per directive-BC-1 Part 1. If the implementation chooses a different
    //  disambiguation, align B3/B4's mocks to match.)
    public function resolve_client_id_for_order(int $wc_order_id): ?int {
        return $this->order_to_client[$wc_order_id] ?? null;
    }
}

class BC1StubOrderQuery {
    public array $items = [];              // wc_order_id => [ ['wc_product_id'=>..,'quantity'=>..], ... ]
    public function get_order_items(array $order_ids): array {
        $out = [];
        foreach ($order_ids as $id) {
            foreach ($this->items[$id] ?? [] as $it) { $out[] = $it; }
        }
        return $out;
    }
}

// Rebuilder seam: inject fakes; expose the protected loader.
class BC1Rebuilder extends MealsDB_Allocation_Rebuilder {
    public function inject($wpdb, $engine, $oq): void {
        foreach (['wpdb' => $wpdb, 'engine' => $engine, 'order_query' => $oq] as $prop => $val) {
            $rp = new ReflectionProperty(MealsDB_Allocation_Rebuilder::class, $prop);
            $rp->setAccessible(true);
            $rp->setValue($this, $val);
        }
    }
    public function call_load(int $client_id, array $months): array {
        $rm = new ReflectionMethod(MealsDB_Allocation_Rebuilder::class, 'load_deliveries_for_months');
        $rm->setAccessible(true);
        return $rm->invoke($this, $client_id, $months);
    }
}

// Window-capture seam: record the month window rebuild_client_month asks for,
// without touching the DB-backed loader.
class BC1WindowRebuilder extends MealsDB_Allocation_Rebuilder {
    public array $loaded_months = [];
    public function inject($wpdb, $engine): void {
        foreach (['wpdb' => $wpdb, 'engine' => $engine] as $prop => $val) {
            $rp = new ReflectionProperty(MealsDB_Allocation_Rebuilder::class, $prop);
            $rp->setAccessible(true);
            $rp->setValue($this, $val);
        }
    }
    protected function load_deliveries_for_months(int $client_id, array $months): array {
        $this->loaded_months = $months;
        return [];
    }
}

// ---------------------------------------------------------------------------
$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function cap(int $mains, int $sides): array {
    return ['permitted_mains' => $mains, 'permitted_sides' => $sides, 'effective_days' => 31];
}
function order_row(int $id, string $date): array { return ['id' => $id, 'order_date' => $date]; }
function item(int $pid, int $qty): array { return ['wc_product_id' => $pid, 'quantity' => $qty]; }
function alloc_table(): string { return MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS); }
function totals_by_month(BC1FakeWpdb $w): array {
    $t = [];
    foreach ($w->alloc_rows as $r) {
        $m = (string) $r['billing_month'];
        $t[$m] = ($t[$m] ?? 0) + (int) $r['mains_count'];
    }
    return $t;
}

// ===========================================================================
//  Bug A — spill conservation
// ===========================================================================

// --- [A1] rebuild_client_month must load a 4-month window (prior-prior..next).
//     Rebuilding 2025-05 should load [2025-03, 2025-04, 2025-05, 2025-06].
//     Current code loads only [2025-04, 2025-05, 2025-06].  EXPECTED FAIL.
$w = new BC1FakeWpdb();
$w->client_type = 'SDNB';
$eng = new BC1StubEngine();
$eng->caps = [
    '2025-03' => cap(31, 100), '2025-04' => cap(31, 100),
    '2025-05' => cap(31, 100), '2025-06' => cap(31, 100),
];
$GLOBALS['wpdb'] = $w;
$rb = new BC1WindowRebuilder();
$rb->inject($w, $eng);
$rb->rebuild_client_month(7, '2025-05');
chk($rb->loaded_months, ['2025-03', '2025-04', '2025-05', '2025-06'],
    '[A1] rebuild loads prior-prior..next (4-month window)');

// --- [A3] CONSERVATION: a forward-spilled meal survives a later month's rebuild.
//     March delivery of 14 mains, March cap 10 → 10 March + 4 spilled to April.
//     Rebuild April (creates the rows), then rebuild May. After the May rebuild
//     the April spill row must still exist (and March must not be double-written).
//     Current code: rebuilding May deletes April's rows and never reloads the
//     March delivery (delivery-month March ∉ {Apr,May,Jun}) → 4 mains lost.
//     EXPECTED FAIL (total 10 / April 0 today; should be 14 / April 4).
$w = new BC1FakeWpdb();
$w->client_type = 'SDNB';
$w->wp_user_id  = 50;
$w->multi_client_count = 1;
$w->customer_orders = [ order_row(300, '2025-03-15') ];
$w->products = [ 1 => ['product_type' => 'meal', 'taxable' => 0] ];
$eng = new BC1StubEngine();
$eng->caps = [
    '2025-02' => cap(31, 100), '2025-03' => cap(10, 100), '2025-04' => cap(31, 100),
    '2025-05' => cap(31, 100), '2025-06' => cap(31, 100),
];
$eng->order_to_client = [ 300 => 7 ]; // customer-matched orders are routed via the resolver
$oq = new BC1StubOrderQuery();
$oq->items = [ 300 => [ item(1, 14) ] ];
$GLOBALS['wpdb'] = $w;
$rb = new BC1Rebuilder();
$rb->inject($w, $eng, $oq);

$rb->rebuild_client_month(7, '2025-04');   // step 1: place 10 March + 4 April
$after1 = totals_by_month($w);
chk($after1['2025-04'] ?? 0, 4, '[A3] setup: April holds the 4-meal spill after rebuilding April');

$w->deletes = [];                          // isolate the NEXT rebuild's DELETEs for [A2]
$rb->rebuild_client_month(7, '2025-05');   // step 2: must NOT drop the April spill
$after2 = totals_by_month($w);
chk(array_sum($after2), 14, '[A3] conservation: all 14 mains survive the May rebuild');
chk($after2['2025-04'] ?? 0, 4, '[A3] conservation: April spill row preserved');
chk($after2['2025-03'] ?? 0, 10, '[A3] conservation: March not lost and not double-written');

// --- [A2] GUARD: the MAY rebuild (its DELETEs were isolated above) must DELETE
//     the write-window {Apr,May,Jun} but NEVER the consume-only prior-prior month
//     (March). Passes today (current window is {Apr,May,Jun}); must keep passing
//     after the fix expands the LOAD window without expanding the DELETE window.
$deleted_months = [];
foreach ($w->deletes as $sql) {
    if (preg_match_all("/'(\d{4}-\d{2})'/", $sql, $mm)) {
        $deleted_months = array_merge($deleted_months, $mm[1]);
    }
}
chk(in_array('2025-03', $deleted_months, true), false,
    '[A2 GUARD] prior-prior (March) is never deleted');

// ===========================================================================
//  Bug B — order → client routing
// ===========================================================================
function load_for(BC1FakeWpdb $w, BC1StubEngine $eng, BC1StubOrderQuery $oq, int $client_id): array {
    $GLOBALS['wpdb'] = $w;
    $rb = new BC1Rebuilder();
    $rb->inject($w, $eng, $oq);
    return $rb->call_load($client_id, ['2024-12', '2025-01', '2025-02']);
}

// --- [B1] Guest isolation: a client with wp_user_id = 0 and no meta-pinned
//     orders must pull NOTHING. Current code issues `customer_id = 0`, matching
//     every guest order.  EXPECTED FAIL (loads the guest order today).
$w = new BC1FakeWpdb();
$w->wp_user_id = 0;
$w->customer_orders = [ order_row(999, '2025-01-15') ]; // a guest order (customer_id 0)
$w->pinned_orders   = [];
$w->products = [ 1 => ['product_type' => 'meal', 'taxable' => 0] ];
$eng = new BC1StubEngine();
$oq  = new BC1StubOrderQuery();
$oq->items = [ 999 => [ item(1, 12) ] ];
$deliveries = load_for($w, $eng, $oq, 7);
chk(count($deliveries), 0, '[B1] wp_user_id=0 with no meta pin pulls no orders (no guest-claim)');

// --- [B2] Meta-pinned order is loaded even when wp_user_id = 0. Current code
//     ignores mealsdb_client_id meta entirely.  EXPECTED FAIL (loads nothing).
$w = new BC1FakeWpdb();
$w->wp_user_id = 0;
$w->customer_orders = [];                              // no customer_id match
$w->pinned_orders   = [ order_row(555, '2025-01-20') ]; // pinned to client 7 by meta
$w->products = [ 1 => ['product_type' => 'meal', 'taxable' => 0] ];
$eng = new BC1StubEngine();
$oq  = new BC1StubOrderQuery();
$oq->items = [ 555 => [ item(1, 10) ] ];
$deliveries = load_for($w, $eng, $oq, 7);
chk(count($deliveries), 1, '[B2] meta-pinned order is loaded');
chk((int) ($deliveries[0]['mains'] ?? 0), 10, '[B2] meta-pinned order contributes its meals');

// --- [B3] Dual-program isolation: two clients share wp_user_id 50; orders 801→7
//     and 802→8. Rebuilding client 7 must pull ONLY order 801. Current code
//     pulls both (customer_id match), double-counting the shared user's meals.
//     EXPECTED FAIL (20 mains today; should be 10).
//     NOTE: assumes the directive's resolver-based disambiguation + a multi-client
//     probe; if the implementation differs, align multi_client_count / the
//     resolver map below to the chosen approach.
$w = new BC1FakeWpdb();
$w->wp_user_id = 50;
$w->multi_client_count = 2;
$w->customer_orders = [ order_row(801, '2025-01-10'), order_row(802, '2025-01-11') ];
$w->pinned_orders   = [];
$w->products = [ 1 => ['product_type' => 'meal', 'taxable' => 0] ];
$eng = new BC1StubEngine();
$eng->order_to_client = [ 801 => 7, 802 => 8 ];
$oq  = new BC1StubOrderQuery();
$oq->items = [ 801 => [ item(1, 10) ], 802 => [ item(1, 10) ] ];
$deliveries = load_for($w, $eng, $oq, 7);
$mains = array_sum(array_map(static fn($d) => (int) ($d['mains'] ?? 0), $deliveries));
chk($mains, 10, '[B3] dual-program user: client 7 gets only its own 10 mains, not 20');

// --- [B4] GUARD: a normal single-client user still pulls all its customer_id
//     orders (no regression from the dual-program filtering). Passes today.
$w = new BC1FakeWpdb();
$w->wp_user_id = 50;
$w->multi_client_count = 1;
$w->customer_orders = [ order_row(801, '2025-01-10') ];
$w->pinned_orders   = [];
$w->products = [ 1 => ['product_type' => 'meal', 'taxable' => 0] ];
$eng = new BC1StubEngine();
$eng->order_to_client = [ 801 => 7 ];
$oq  = new BC1StubOrderQuery();
$oq->items = [ 801 => [ item(1, 10) ] ];
$deliveries = load_for($w, $eng, $oq, 7);
$mains = array_sum(array_map(static fn($d) => (int) ($d['mains'] ?? 0), $deliveries));
chk($mains, 10, '[B4 GUARD] single-client user still pulls all its orders');

// ===========================================================================
echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
