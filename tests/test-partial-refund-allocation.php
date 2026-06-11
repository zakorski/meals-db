<?php
/**
 * BC-6 regression tests — partial refunds must reduce allocations.
 *
 * Bugs the fix closes:
 *   (1) only full-status hooks were wired; a PARTIAL refund (order stays active)
 *       fires woocommerce_order_refunded, which was unhandled → the refunded
 *       meals stayed billed. The fix marks the order's client-month(s) dirty so
 *       the rebuilder recomputes from NET quantities — WITHOUT releasing the
 *       contribution (the order is still active).
 *   (2) the rebuilder counted raw ordered _qty with no refund subtraction →
 *       get_order_items now reports NET (ordered − refunded) quantity, clamped
 *       at 0.
 *
 * Run: php tests/test-partial-refund-allocation.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

class BC6FakeWpdb extends wpdb {
    public $prefix = 'wp_';
    public array $queries = [];
    public array $prepared = [];
    public array $affected = [];   // rows for the affected-months SELECT
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        $out = preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
        $this->prepared[] = $out;
        return $out;
    }
    public function query($sql) { $this->queries[] = $sql; return 1; }
    public function get_results($q, $o = null) {
        $this->queries[] = $q;
        if (stripos($q, 'DISTINCT client_id') !== false) { return $this->affected; }
        return [];
    }
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
function any(array $hay, callable $p): bool { foreach ($hay as $s) { if ($p($s)) return true; } return false; }

// ---------------------------------------------------------------------------
// [BC6-1] get_order_items reports NET quantity — its SQL subtracts refunded
//         line quantities (correlated on _refunded_item_id).
// ---------------------------------------------------------------------------
$w = new BC6FakeWpdb();
$oq = new MealsDB_WC_Order_Query($w);
$oq->get_order_items([900]);
chk(any($w->prepared, static fn($s) => stripos($s, '_refunded_item_id') !== false), true,
    '[BC6-1] get_order_items SQL subtracts refunded quantity (_refunded_item_id)');

// ---------------------------------------------------------------------------
// [BC6-2] clamp_net_quantity: ordered + refunded(signed), floored at 0.
// ---------------------------------------------------------------------------
chk(MealsDB_WC_Order_Query::clamp_net_quantity(10, -3), 7,  '[BC6-2] 10 ordered, 3 refunded → 7');
chk(MealsDB_WC_Order_Query::clamp_net_quantity(10, 0), 10,  '[BC6-2] no refund → unchanged');
chk(MealsDB_WC_Order_Query::clamp_net_quantity(5, -9), 0,   '[BC6-2] over-refund clamps to 0, never negative');

// ---------------------------------------------------------------------------
// [BC6-3] mark_order_months_dirty marks the order's client-month(s) dirty but
//         does NOT release the contribution (order is still active).
// ---------------------------------------------------------------------------
$w = new BC6FakeWpdb();
$w->affected = [ ['client_id' => 5, 'billing_month' => '2025-04'] ];
$GLOBALS['wpdb'] = $w;
(new MealsDB_Allocation_Engine())->mark_order_months_dirty(900);

chk(any($w->queries, static fn($s) => stripos($s, 'client_month_dirty') !== false && stripos($s, 'INSERT') !== false),
    true, '[BC6-3] partial refund marks the client-month dirty');
chk(any($w->queries, static fn($s) => stripos($s, 'contribution_applied = 0') !== false),
    false, '[BC6-3] partial refund does NOT release the contribution');

// ---------------------------------------------------------------------------
// [BC6-4] the refund hook handler routes through that path (marks dirty).
// ---------------------------------------------------------------------------
$w = new BC6FakeWpdb();
$w->affected = [ ['client_id' => 8, 'billing_month' => '2025-05'] ];
$GLOBALS['wpdb'] = $w;
MealsDB_Allocation_Hooks::on_order_partially_refunded(901, 5001);
chk(any($w->queries, static fn($s) => stripos($s, 'client_month_dirty') !== false && stripos($s, 'INSERT') !== false),
    true, '[BC6-4] on_order_partially_refunded marks the client-month dirty');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
