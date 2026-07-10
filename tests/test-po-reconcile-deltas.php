<?php
/**
 * PO reconcile session tests (spec 2026-07-10):
 *   edit_reconcile_row → session persistence, validation, no early stock effect
 *   complete_reconcile → notes required for adjusted rows, exactly-once stock
 *                        deltas, audit rows, status flip, double-complete guard
 *
 * Run with: php tests/test-po-reconcile-deltas.php
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
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code; private $message; private $data;
        public function __construct($code = '', $message = '', $data = null) {
            $this->code = $code; $this->message = $message; $this->data = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }

if (!class_exists('wpdb')) { class wpdb {} }

/**
 * In-memory wpdb stub for meals_purchase_orders. Honors the guarded-update
 * contract (WHERE status mismatch → 0 rows) and the uniq_po_number index.
 * Audit-log INSERTs are captured as raw SQL strings for assertion.
 */
class PoWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public array $pos = [];
    public array $audit = [];
    private int $next_id = 1;

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
            foreach ($this->pos as $row) {
                if (($row['po_number'] ?? '') === ($data['po_number'] ?? '')) {
                    $this->last_error = 'Duplicate entry for key uniq_po_number';
                    return false;
                }
            }
            $id = $this->next_id++;
            $data['po_id'] = $id;
            // Do NOT default edit_count here — the T-1 assertion verifies
            // the service sets it; defaulting it in the stub would mask a
            // missing field in the INSERT data.
            $data += ['reconciled_at' => null];
            $this->pos[$id] = $data;
            $this->insert_id = $id;
            return 1;
        }
        $this->insert_id = 1;
        return 1;
    }

    public function get_row($q, $o = null) {
        if (stripos($q, 'meals_purchase_orders') !== false && preg_match('/po_id = (\d+)/', $q, $m)) {
            return $this->pos[(int) $m[1]] ?? null;
        }
        return null;
    }

    public function get_results($q, $o = null) {
        if (stripos($q, 'meals_purchase_orders') !== false) {
            return array_values($this->pos);
        }
        return [];
    }

    public function update($table, $data, $where, $df = null, $wf = null) {
        if (strpos($table, 'meals_purchase_orders') !== false) {
            $id = (int) ($where['po_id'] ?? 0);
            if (!isset($this->pos[$id])) { return 0; }
            if (isset($where['status']) && ($this->pos[$id]['status'] ?? '') !== $where['status']) {
                return 0; // guarded transition lost the race
            }
            foreach ($data as $k => $v) { $this->pos[$id][$k] = $v; }
            return 1;
        }
        return 0;
    }

    public function query($q) {
        if (stripos($q, 'meals_audit_log') !== false) { $this->audit[] = $q; return 1; }
        return 1;
    }
}

/**
 * Simulates a wpdb where the payload UPDATE always reports 0 rows affected,
 * as if an approve/transition won the race between require_workflow_po (read)
 * and write_payload (write). Used by T-6b.
 */
class RaceWpdb extends PoWpdb {
    public function update($table, $data, $where, $df = null, $wf = null) {
        if (strpos($table, 'meals_purchase_orders') !== false && isset($data['payload'])) {
            return 0; // simulate: an approve won the race between read and write
        }
        return parent::update($table, $data, $where, $df, $wf);
    }
}

// Simulates losing a TRANSITION race: any status-bearing update matches 0 rows.
class TransitionRaceWpdb extends PoWpdb {
    public function update($table, $data, $where, $df = null, $wf = null) {
        if (strpos($table, 'meals_purchase_orders') !== false && isset($data['status'])) {
            return 0;
        }
        return parent::update($table, $data, $where, $df, $wf);
    }
}

// --- WooCommerce stub: SKU→product registry with stock (Task 5) ---
class FakeWCProduct {
    public int $product_id;
    public int $stock;
    public function __construct(int $id, int $stock) { $this->product_id = $id; $this->stock = $stock; }
    public function get_stock_quantity() { return $GLOBALS['wc_stock'][$this->product_id]; }
    public function set_stock_quantity($q) { $this->stock = (int) $q; }
    public function save() { $GLOBALS['wc_stock'][$this->product_id] = $this->stock; }
}
$GLOBALS['wc_sku_map'] = ['CD-001' => 101, 'SD-002' => 102];
$GLOBALS['wc_stock']   = [101 => 50, 102 => 20];
if (!function_exists('wc_get_product_id_by_sku')) {
    function wc_get_product_id_by_sku($sku) { return $GLOBALS['wc_sku_map'][$sku] ?? 0; }
}
if (!function_exists('wc_get_product')) {
    function wc_get_product($id) {
        return isset($GLOBALS['wc_stock'][$id]) ? new FakeWCProduct($id, $GLOBALS['wc_stock'][$id]) : null;
    }
}
if (!function_exists('wc_update_product_stock')) {
    function wc_update_product_stock($product, $qty, $op = 'increase') {
        $id = $product->product_id;
        $GLOBALS['wc_stock'][$id] += ($op === 'increase' ? $qty : -$qty);
        return $GLOBALS['wc_stock'][$id];
    }
}

// --- Harness ---
$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; } else {
        $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true);
    }
}
function chk_true($cond, $label) { chk((bool) $cond, true, $label); }
function fresh(): PoWpdb {
    $w = new PoWpdb();
    $GLOBALS['wpdb'] = $w;
    return $w;
}
function audit_has(PoWpdb $w, string $needle): bool {
    foreach ($w->audit as $sql) { if (strpos($sql, $needle) !== false) { return true; } }
    return false;
}
/** Two forecast-shaped rows, as generate_purchase_order() emits them. */
function forecast_rows(): array {
    return [
        [
            'sku' => 'CD-001', 'product_name' => 'Chicken Dinner',
            'weighted_avg_weekly' => 10.0, 'seasonal_index' => 1.1,
            'adjusted_weekly' => 11.0, 'projected_need' => 99,
            'current_stock' => 40, 'total_available' => 40, 'units_needed' => 59,
            'case_size' => 6, 'cases_to_buy' => 10, 'order_quantity' => 60,
            'seasonal_note' => '', 'weekly_history' => [],
        ],
        [
            'sku' => 'SD-002', 'product_name' => 'Side Salad',
            'weighted_avg_weekly' => 4.0, 'seasonal_index' => 1.0,
            'adjusted_weekly' => 4.0, 'projected_need' => 36,
            'current_stock' => 20, 'total_available' => 20, 'units_needed' => 16,
            'case_size' => 12, 'cases_to_buy' => 2, 'order_quantity' => 24,
            'seasonal_note' => 'Freight fill +1 cases', 'weekly_history' => [],
            'freight_delta_cases' => 1,
        ],
    ];
}

// Helper: a PO driven to 'arrived' with known stock.
function arrived_po(PoWpdb $w): array {
    $GLOBALS['wc_stock'] = [101 => 50, 102 => 20];
    $svc = new MealsDB_Purchase_Orders();
    $id = $svc->create_draft(forecast_rows());
    $svc->approve($id);
    $svc->mark_received($id); // stock now 110 / 44
    return [$svc, $id];
}

// ===========================================================================
// R-1: edit_reconcile_row — persists session, validates.
// ===========================================================================
$w = fresh();
[$svc, $id] = arrived_po($w);
$r = $svc->edit_reconcile_row($id, 'CD-001', 8, 'Two cases damaged in transit');
chk_true(is_array($r), 'R-1: edit returns array');
chk($r['received_cases'], 8, 'R-1: received echoed');
chk($r['ordered_cases'], 10, 'R-1: ordered echoed');
$po = $svc->get_with_payload($id);
chk((int) $po['payload']['received']['CD-001']['received_cases'], 8, 'R-1: session persisted');
chk($po['payload']['received']['CD-001']['note'], 'Two cases damaged in transit', 'R-1: note persisted');
chk((int) $po['edit_count'], 1, 'R-1: edit_count bumped');
chk($GLOBALS['wc_stock'][101], 110, 'R-1: NO stock effect before completion');

chk($svc->edit_reconcile_row($id, 'NOPE', 1, 'x')->get_error_code(), 'unknown_sku', 'R-1: unknown sku rejected');
chk($svc->edit_reconcile_row($id, 'CD-001', -1, 'x')->get_error_code(), 'bad_cases', 'R-1: negative rejected');
chk($svc->edit_reconcile_row($id, 'CD-001', 1, str_repeat('n', 501))->get_error_code(), 'note_too_long', 'R-1: 501-char note rejected');

// Wrong status.
$w2 = fresh();
$svc2 = new MealsDB_Purchase_Orders();
$draft = $svc2->create_draft(forecast_rows());
chk($svc2->edit_reconcile_row($draft, 'CD-001', 1, 'x')->get_error_code(), 'locked', 'R-1: reconcile edit on draft rejected');

// ===========================================================================
// R-2: complete_reconcile — note required for every changed row.
// ===========================================================================
$w = fresh();
[$svc, $id] = arrived_po($w);
$svc->edit_reconcile_row($id, 'CD-001', 8, ''); // changed, NO note yet
$r = $svc->complete_reconcile($id);
chk($r->get_error_code(), 'notes_required', 'R-2: missing note blocks completion');
chk_true(in_array('CD-001', (array) $r->get_error_data()['skus'], true), 'R-2: offending sku listed');
chk($svc->get_with_payload($id)['status'], 'arrived', 'R-2: status unchanged');
chk($GLOBALS['wc_stock'][101], 110, 'R-2: stock unchanged');

// ===========================================================================
// R-3: complete_reconcile — deltas applied, notes audited, status flips.
// ===========================================================================
$w = fresh();
[$svc, $id] = arrived_po($w);
$svc->edit_reconcile_row($id, 'CD-001', 8, 'Two cases damaged in transit'); // -2 cases × 6 = -12 units
// SD-002 untouched → received as ordered, no delta, no note needed.
$r = $svc->complete_reconcile($id);
chk($r, true, 'R-3: completes');
$po = $svc->get_with_payload($id);
chk($po['status'], 'reconciled', 'R-3: status → reconciled');
chk_true(!empty($po['reconciled_at']), 'R-3: reconciled_at set');
chk((int) $po['reconciled_by'], 7, 'R-3: reconciled_by set');
chk($GLOBALS['wc_stock'][101], 98, 'R-3: stock corrected 110 − 12');
chk($GLOBALS['wc_stock'][102], 44, 'R-3: untouched row no delta');
chk_true(audit_has($w, 'po_reconciled'), 'R-3: po_reconciled audited');
chk_true(audit_has($w, 'inventory_discrepancy'), 'R-3: per-SKU discrepancy audited');
chk_true(audit_has($w, 'Two cases damaged in transit'), 'R-3: note lands in discrepancy audit');
// Double completion rejected, no second delta.
chk($svc->complete_reconcile($id)->get_error_code(), 'locked', 'R-3: double completion rejected');
chk($GLOBALS['wc_stock'][101], 98, 'R-3: no double delta');

// ===========================================================================
// R-4: received == ordered explicitly (note optional, no delta).
// ===========================================================================
$w = fresh();
[$svc, $id] = arrived_po($w);
$svc->edit_reconcile_row($id, 'CD-001', 10, ''); // same as ordered, blank note fine
chk($svc->complete_reconcile($id), true, 'R-4: completes without notes');
chk($GLOBALS['wc_stock'][101], 110, 'R-4: no delta applied');

// --- summary ---
echo "\n" . $passed . " passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
