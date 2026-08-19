<?php
/**
 * Apetito PO 'accepted' status + inventory-commit move + cadence
 * (directive DIRECTIVE-apetito-po-accepted-status.md).
 *
 *   A-1..A-4  mark_accepted commits stock once; mark_received is a pure marker
 *   A-5       unaccept reverses the EXACT committed quantities (reason required)
 *   A-6       double-click Accept bumps once
 *   A-7..A-8  po_schedule_from_order_date cadence + off-cycle + invalid
 *
 * Run with: php tests/test-po-accepted-status.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $tag, $v, ...$a) { return $v; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 7; } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!function_exists('current_time')) { function current_time($fmt) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('get_option')) { function get_option($k, $d = '') { return $d; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $s))); } }
if (!function_exists('do_action')) { function do_action(string $hook, ...$args) { $GLOBALS['fired_actions'][] = ['hook' => $hook, 'args' => $args]; } }
if (!function_exists('add_action')) { function add_action(string $hook, $cb, int $prio = 10, int $accepted = 1) {} }
$GLOBALS['fired_actions'] = [];
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code; private $message; private $data;
        public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!class_exists('wpdb')) { class wpdb {} }

// In-memory wpdb honoring the guarded-update contract (WHERE status mismatch → 0 rows).
class AcceptWpdb extends wpdb {
    public $prefix = 'wp_'; public $insert_id = 0; public $last_error = '';
    public array $pos = []; public array $audit = []; private int $next_id = 1;
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function insert($table, $data, $formats = null) {
        if (strpos($table, 'meals_purchase_orders') !== false) {
            $id = $this->next_id++; $data['po_id'] = $id;
            $data += ['reconciled_at' => null];
            $this->pos[$id] = $data; $this->insert_id = $id; return 1;
        }
        $this->insert_id = 1; return 1;
    }
    public function get_row($q, $o = null) {
        if (stripos($q, 'meals_purchase_orders') !== false && preg_match('/po_id = (\d+)/', $q, $m)) { return $this->pos[(int) $m[1]] ?? null; }
        return null;
    }
    public function get_results($q, $o = null) {
        if (stripos($q, 'meals_purchase_orders') !== false) { return array_values($this->pos); }
        return [];
    }
    public function update($table, $data, $where, $df = null, $wf = null) {
        if (strpos($table, 'meals_purchase_orders') !== false) {
            $id = (int) ($where['po_id'] ?? 0);
            if (!isset($this->pos[$id])) { return 0; }
            if (isset($where['status']) && ($this->pos[$id]['status'] ?? '') !== $where['status']) { return 0; }
            foreach ($data as $k => $v) { $this->pos[$id][$k] = $v; }
            return 1;
        }
        return 0;
    }
    public function query($q) { if (stripos($q, 'meals_audit_log') !== false) { $this->audit[] = $q; } return 1; }
}

// WC stock stub — 'decrease' op works (used by unaccept).
class FakeWCProduct2 {
    public int $product_id;
    public function __construct(int $id) { $this->product_id = $id; }
    public function get_stock_quantity() { return $GLOBALS['wc_stock'][$this->product_id]; }
}
$GLOBALS['wc_sku_map'] = ['CD-001' => 101, 'SD-002' => 102];
if (!function_exists('wc_get_product_id_by_sku')) { function wc_get_product_id_by_sku($sku) { return $GLOBALS['wc_sku_map'][$sku] ?? 0; } }
if (!function_exists('wc_get_product')) { function wc_get_product($id) { return isset($GLOBALS['wc_stock'][$id]) ? new FakeWCProduct2($id) : null; } }
if (!function_exists('wc_update_product_stock')) {
    function wc_update_product_stock($product, $qty, $op = 'increase') {
        $id = $product->product_id;
        $GLOBALS['wc_stock'][$id] += ($op === 'increase' ? $qty : -$qty);
        return $GLOBALS['wc_stock'][$id];
    }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) { global $failures, $passed; if ($got === $exp) { $passed++; } else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); } }
function chk_true($cond, $label) { chk((bool) $cond, true, $label); }
function forecast_rows(): array {
    return [
        ['sku' => 'CD-001', 'product_name' => 'Chicken Dinner', 'weighted_avg_weekly' => 10.0, 'seasonal_index' => 1.1, 'adjusted_weekly' => 11.0, 'projected_need' => 99, 'current_stock' => 40, 'total_available' => 40, 'units_needed' => 59, 'case_size' => 6, 'cases_to_buy' => 10, 'order_quantity' => 60, 'seasonal_note' => '', 'weekly_history' => []],
        ['sku' => 'SD-002', 'product_name' => 'Side Salad', 'weighted_avg_weekly' => 4.0, 'seasonal_index' => 1.0, 'adjusted_weekly' => 4.0, 'projected_need' => 36, 'current_stock' => 20, 'total_available' => 20, 'units_needed' => 16, 'case_size' => 12, 'cases_to_buy' => 2, 'order_quantity' => 24, 'seasonal_note' => '', 'weekly_history' => []],
    ];
}
function fresh_accept(): AcceptWpdb {
    $w = new AcceptWpdb(); $GLOBALS['wpdb'] = $w;
    $GLOBALS['wc_stock'] = [101 => 50, 102 => 20];
    return $w;
}

// ===========================================================================
// A-1..A-4: accept commits stock once; received is a pure marker.
// ===========================================================================
$w = fresh_accept();
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
$svc->approve($id);

// A-1: mark_received is UNREACHABLE from placed (only route is via accepted).
chk($svc->mark_received($id)->get_error_code(), 'locked', 'A-1: received unavailable on placed PO');
chk($GLOBALS['wc_stock'][101], 50, 'A-1: no stock change from a refused receive');

// A-2: mark_accepted commits stock exactly once.
$r = $svc->mark_accepted($id);
chk($r, true, 'A-2: mark_accepted succeeds');
$po = $svc->get_with_payload($id);
chk($po['status'], 'accepted', 'A-2: status → accepted');
chk_true(!empty($po['accepted_at']), 'A-2: accepted_at set');
chk((int) $po['accepted_by'], 7, 'A-2: accepted_by = current user');
// CD-001: 10×6=60 onto 50 → 110; SD-002: 2×12=24 onto 20 → 44.
chk($GLOBALS['wc_stock'][101], 110, 'A-2: CD-001 committed at accept');
chk($GLOBALS['wc_stock'][102], 44, 'A-2: SD-002 committed at accept');

// A-3: mark_received now changes NO inventory (the double-count check).
$r = $svc->mark_received($id);
chk($r, true, 'A-3: mark_received succeeds from accepted');
chk($svc->get_with_payload($id)['status'], 'arrived', 'A-3: status → arrived');
chk($GLOBALS['wc_stock'][101], 110, 'A-3: received does NOT re-bump CD-001');
chk($GLOBALS['wc_stock'][102], 44, 'A-3: received does NOT re-bump SD-002');

// A-4: accept is unreachable from anything but placed (double-accept loses guard).
chk($svc->mark_accepted($id)->get_error_code(), 'locked', 'A-4: accept refused once past placed');

// ===========================================================================
// A-5: unaccept reverses the EXACT committed quantities; reason required.
// ===========================================================================
$w = fresh_accept();
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
$svc->approve($id);
$svc->mark_accepted($id);
chk($GLOBALS['wc_stock'][101], 110, 'A-5: precondition committed');

chk($svc->unaccept($id, '')->get_error_code(), 'reason_required', 'A-5: empty reason rejected');
chk($svc->unaccept($id, '   ')->get_error_code(), 'reason_required', 'A-5: whitespace reason rejected');
$r = $svc->unaccept($id, 'Vendor cancelled the confirmation');
chk($r, true, 'A-5: unaccept succeeds');
$po = $svc->get_with_payload($id);
chk($po['status'], 'placed', 'A-5: status back to placed (Approved)');
chk($po['accepted_by'], null, 'A-5: accepted_by cleared');
chk($po['accepted_at'], null, 'A-5: accepted_at cleared');
chk($GLOBALS['wc_stock'][101], 50, 'A-5: CD-001 stock reversed to pre-accept');
chk($GLOBALS['wc_stock'][102], 20, 'A-5: SD-002 stock reversed to pre-accept');
chk($svc->unaccept($id, 'again')->get_error_code(), 'locked', 'A-5: unaccept from placed rejected');

// ===========================================================================
// A-6: double-click Accept bumps once (guard on the transition).
// ===========================================================================
$w = fresh_accept();
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
$svc->approve($id);
$svc->mark_accepted($id);
chk($svc->mark_accepted($id)->get_error_code(), 'locked', 'A-6: second accept rejected');
chk($GLOBALS['wc_stock'][101], 110, 'A-6: no double bump on CD-001');

// ===========================================================================
// A-7..A-8: cadence helper.
// ===========================================================================
$s = MealsDB_Purchase_Orders::po_schedule_from_order_date('2026-08-04'); // Tuesday
chk_true(is_array($s), 'A-7: schedule returns array for a valid date');
chk($s['order_date'], '2026-08-04', 'A-7: order_date echoed');
chk($s['inventory_due'], '2026-08-12', 'A-7: inventory due T+8');
chk($s['ship_date'], '2026-08-14', 'A-7: ship T+10');
chk($s['expected_arrival'], '2026-08-17', 'A-7: arrival T+13 (Mon after Fri)');
chk($s['next_order_date'], '2026-09-01', 'A-7: next order T+28');
chk($s['is_off_cycle'], false, 'A-7: Tuesday is on-cycle');

$s2 = MealsDB_Purchase_Orders::po_schedule_from_order_date('2026-08-05'); // Wednesday
chk($s2['is_off_cycle'], true, 'A-8: Wednesday flagged off-cycle');
chk($s2['expected_arrival'], '2026-08-18', 'A-8: off-cycle still derives from its own date (T+13)');
chk(MealsDB_Purchase_Orders::po_schedule_from_order_date('nonsense'), null, 'A-8: invalid date → null');
chk(MealsDB_Purchase_Orders::po_schedule_from_order_date('2026-13-40'), null, 'A-8: impossible date → null');

echo "\n" . $passed . " passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
