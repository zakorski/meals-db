<?php
/**
 * Tests for MealsDB_Allocation_Rebuilder — the phase 1 delivery-month
 * allowance fill with single-month spill.
 *
 * The fill algorithm (private fill_months) is exercised through a public
 * test seam: a subclass that injects pre-built deliveries + caps. We assert
 * what gets INSERTed into delivery_allocations and what spillover errors
 * are logged.
 *
 * Run: php tests/test-allocation-rebuilder.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// WC_Order_Query type-hints wpdb; satisfy that.
if (!class_exists('wpdb')) { class wpdb {} }

/**
 * Fake wpdb that captures every INSERT into delivery_allocations and
 * allocation_errors so we can assert on the fill outcome.
 */
class RebFakeWpdb extends wpdb {
    public $prefix = 'wp_';
    public array $inserts = [];   // [table => [row, ...]]
    public array $deletes = [];   // SQL strings
    public ?int $insert_id = 1;
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
    public function delete($table, $where, $fmt = null) { return 1; }
    public function query($sql) {
        if (stripos($sql, 'DELETE ') === 0) { $this->deletes[] = $sql; }
        return 1;
    }
    public function get_var($q) { return null; }
    public function get_row($q, $o = null) { return null; }
    public function get_results($q, $o = null) { return []; }
    public function get_col($q) { return []; }
}

/**
 * Test seam: expose fill_months and skip the engine/order-pull dependencies.
 * We feed pre-built deliveries + caps directly into the private fill_months
 * by way of a public passthrough.
 */
class RebTestable extends MealsDB_Allocation_Rebuilder {
    public function call_fill(int $client_id, array $caps, array $deliveries): array {
        $rm = new ReflectionMethod(MealsDB_Allocation_Rebuilder::class, 'fill_months');
        $rm->setAccessible(true);
        return $rm->invoke($this, $client_id, $caps, $deliveries);
    }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; } else {
        $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true);
    }
}

function alloc_table_name(): string {
    return MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);
}
function err_table_name(): string {
    return MealsDB_DB::get_table_name(MealsDB_Tables::ALLOCATION_ERRORS);
}

function rows_for($wpdb, string $table): array {
    return $wpdb->inserts[$table] ?? [];
}
function fill($caps, $deliveries): RebFakeWpdb {
    $GLOBALS['wpdb'] = new RebFakeWpdb();
    $rb = new RebTestable();
    $rb->call_fill(1, $caps, $deliveries);
    return $GLOBALS['wpdb'];
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
function cap(int $mains, int $sides): array {
    return ['permitted_mains' => $mains, 'permitted_sides' => $sides, 'effective_days' => 31];
}

// ---------------------------------------------------------------------------
// Test 1: a single delivery that fits entirely in its month — no spill, one row.
// ---------------------------------------------------------------------------
$wpdb = fill(
    ['2025-01' => cap(31, 31), '2025-02' => cap(28, 28)],
    [delivery('2025-01-15', 14, 4, 3, 100)]
);
$rows = rows_for($wpdb, alloc_table_name());
chk(count($rows), 1, 'fits: one row written');
chk((int) $rows[0]['mains_count'], 14, 'fits: all mains in jan');
chk((int) $rows[0]['billing_month'], 2025, 'fits: row month 2025 (parse check)');
chk((string) $rows[0]['billing_month'], '2025-01', 'fits: row month is 2025-01');
chk((int) $rows[0]['tax_sides_count'], 4, 'fits: tax sides 4');
chk((int) $rows[0]['nontax_sides_count'], 3, 'fits: nontax sides 3');
chk(empty($wpdb->inserts[err_table_name()] ?? []), true, 'fits: no spill error');

// ---------------------------------------------------------------------------
// Test 2: single-month spill — delivery doesn't fit, overflow lands in next month.
// Jan cap 10 mains, delivery 14 mains -> 10 in Jan, 4 in Feb.
// ---------------------------------------------------------------------------
$wpdb = fill(
    ['2025-01' => cap(10, 100), '2025-02' => cap(28, 100)],
    [delivery('2025-01-15', 14, 0, 0, 200)]
);
$rows = rows_for($wpdb, alloc_table_name());
chk(count($rows), 2, 'spill: two rows written');
// Order: place_to_month called for delivery_month first (Jan), then next_month (Feb)
chk((string) $rows[0]['billing_month'], '2025-01', 'spill: first row Jan');
chk((int) $rows[0]['mains_count'], 10, 'spill: Jan filled to cap=10');
chk((string) $rows[1]['billing_month'], '2025-02', 'spill: second row Feb');
chk((int) $rows[1]['mains_count'], 4, 'spill: Feb takes overflow=4');
chk(empty($wpdb->inserts[err_table_name()] ?? []), true, 'spill: no error logged');

// ---------------------------------------------------------------------------
// Test 3: multi-month spillover error — neither month can hold the delivery.
// Jan cap 5, Feb cap 5, delivery 20 mains -> 5 to Jan, 5 to Feb, 10 unplaced -> error.
// ---------------------------------------------------------------------------
$wpdb = fill(
    ['2025-01' => cap(5, 100), '2025-02' => cap(5, 100)],
    [delivery('2025-01-15', 20, 0, 0, 300)]
);
$rows = rows_for($wpdb, alloc_table_name());
$errs = rows_for($wpdb, err_table_name());
chk(count($rows), 2, 'overflow: two rows written before error');
chk((int) $rows[0]['mains_count'], 5, 'overflow: Jan filled to cap');
chk((int) $rows[1]['mains_count'], 5, 'overflow: Feb filled to cap');
chk(count($errs), 1, 'overflow: one error row');
chk((int) $errs[0]['mains_unplaced'], 10, 'overflow: error reports 10 mains unplaced');
chk((string) $errs[0]['billing_month'], '2025-01', 'overflow: error attributed to delivery month');
chk((string) $errs[0]['error_type'], 'multi_month_spillover', 'overflow: error type tagged');

// ---------------------------------------------------------------------------
// Test 4: mains and sides are INDEPENDENT caps.
// Mains cap 5 (overflowing), sides cap 100 (plenty). Sides do not spill
// just because mains do; the mains overflow alone goes to Feb.
// ---------------------------------------------------------------------------
$wpdb = fill(
    ['2025-01' => cap(5, 100), '2025-02' => cap(28, 100)],
    [delivery('2025-01-15', 10, 4, 3, 400)]
);
$rows = rows_for($wpdb, alloc_table_name());
chk(count($rows), 2, 'independent caps: two rows (mains spill, sides do not)');
chk((int) $rows[0]['mains_count'], 5, 'independent: Jan mains capped at 5');
chk((int) $rows[0]['tax_sides_count'] + (int) $rows[0]['nontax_sides_count'], 7, 'independent: Jan all 7 sides');
chk((int) $rows[1]['mains_count'], 5, 'independent: Feb gets 5 mains overflow');
chk((int) $rows[1]['tax_sides_count'] + (int) $rows[1]['nontax_sides_count'], 0, 'independent: no sides in Feb');

// ---------------------------------------------------------------------------
// Test 5: in-month order matters — earlier delivery fills first, later delivery
// gets the remaining headroom. Two deliveries Jan 10 + Jan 25 of 8 mains each
// against a Jan cap of 10: first delivery places 8, second places 2, then
// the remaining 6 of the second spills to Feb.
// ---------------------------------------------------------------------------
$wpdb = fill(
    ['2025-01' => cap(10, 100), '2025-02' => cap(28, 100)],
    [
        delivery('2025-01-10', 8, 0, 0, 500),
        delivery('2025-01-25', 8, 0, 0, 501),
    ]
);
$rows = rows_for($wpdb, alloc_table_name());
chk(count($rows), 3, 'date order: 3 rows (jan x2, feb spill x1)');
chk((string) $rows[0]['billing_month'] . '/' . (int) $rows[0]['mains_count'], '2025-01/8', 'date order: first Jan delivery takes 8');
chk((string) $rows[1]['billing_month'] . '/' . (int) $rows[1]['mains_count'], '2025-01/2', 'date order: second fills remaining 2 in Jan');
chk((string) $rows[2]['billing_month'] . '/' . (int) $rows[2]['mains_count'], '2025-02/6', 'date order: 6 spills to Feb');

// ---------------------------------------------------------------------------
// Test 6: delete-then-fill is recompute-from-orders. Each fill_months call
// issues a DELETE for the affected months before writing.
// ---------------------------------------------------------------------------
$wpdb = fill(
    ['2025-01' => cap(31, 31), '2025-02' => cap(28, 28)],
    [delivery('2025-01-15', 5, 0, 0, 600)]
);
$has_delete = false;
foreach ($wpdb->deletes as $sql) {
    if (stripos($sql, 'delivery_allocations') !== false
        && stripos($sql, 'client_id = 1') !== false
        && stripos($sql, "'2025-01'") !== false
        && stripos($sql, "'2025-02'") !== false) {
        $has_delete = true; break;
    }
}
chk($has_delete, true, 'recompute: DELETE for both months issued before fill');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
