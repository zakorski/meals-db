<?php
/**
 * PO draft workflow lifecycle tests (spec 2026-07-10):
 *   create_draft → payload shape, po_number, collision retry
 *   edit_draft_cases → validation, audit, race guard        (Task 3)
 *   approve / unapprove / cancel_draft → guarded transitions (Task 4)
 *   mark_received → stock bump exactly once                  (Task 5)
 *   coverage_weeks → 9/7 boundaries
 *
 * Run with: php tests/test-po-draft-lifecycle.php
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

// ===========================================================================
// T-1: create_draft → payload shape, defaults, audit.
// ===========================================================================
$w = fresh();
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
chk_true($id > 0, 'T-1: create_draft returns id > 0');
$po = $svc->get_with_payload($id);
chk_true(is_array($po), 'T-1: get_with_payload returns array');
chk($po['status'], 'planned', 'T-1: status is planned (Draft)');
chk($po['supplier'], 'Apetito', 'T-1: default supplier');
chk_true(strpos((string) $po['po_number'], 'PO-') === 0, 'T-1: po_number auto-generated');
chk((int) $po['edit_count'], 0, 'T-1: edit_count starts 0');
chk_true(is_array($po['payload']), 'T-1: payload decodes');
chk((int) $po['payload']['schema'], 1, 'T-1: payload schema 1');
chk(count($po['payload']['generated']), 2, 'T-1: 2 generated rows');
chk($po['payload']['generated'], $po['payload']['current'], 'T-1: generated == current at creation');
chk((int) $po['payload']['current'][0]['cases'], 10, 'T-1: cases from cases_to_buy');
chk((int) $po['payload']['current'][0]['order_quantity'], 60, 'T-1: order_quantity = cases*case_size');
chk((float) $po['payload']['current'][0]['adjusted_weekly'], 11.0, 'T-1: adjusted_weekly snapshot');
chk((int) $po['payload']['current'][1]['freight_delta_cases'], 1, 'T-1: freight delta carried');
chk($po['items'], [], 'T-1: items empty until approval');
chk_true(audit_has($w, 'po_draft_created'), 'T-1: po_draft_created audited');

// ===========================================================================
// T-2: create_draft rejects empty/unusable input.
// ===========================================================================
$w = fresh();
$svc = new MealsDB_Purchase_Orders();
chk($svc->create_draft([]), 0, 'T-2: empty rows → 0');
chk($svc->create_draft([['product_name' => 'No SKU', 'cases_to_buy' => 5]]), 0, 'T-2: blank-sku rows only → 0');

// ===========================================================================
// T-3: po_number collision retries once with suffix.
// ===========================================================================
$w = fresh();
$svc = new MealsDB_Purchase_Orders();
$a = $svc->create_draft(forecast_rows());
$b = $svc->create_draft(forecast_rows()); // same second → same base po_number
chk_true($b > 0, 'T-3: second same-second draft still created');
$pb = $svc->get_with_payload($b);
chk_true(substr((string) $pb['po_number'], -2) === '-2', 'T-3: retry appended -2 suffix');

// ===========================================================================
// T-4: coverage_weeks boundaries.
// ===========================================================================
$row = ['adjusted_weekly' => 10.0, 'current_stock' => 20, 'case_size' => 5, 'cases' => 14];
// (20 + 14*5) / 10 = 9.0
chk(MealsDB_Purchase_Orders::coverage_weeks($row), 9.0, 'T-4: coverage at exactly 9.0');
chk(MealsDB_Purchase_Orders::coverage_weeks($row, 13), 8.5, 'T-4: override cases → 8.5 (below target)');
chk(MealsDB_Purchase_Orders::coverage_weeks($row, 10), 7.0, 'T-4: 7.0 exactly (floor boundary)');
chk(MealsDB_Purchase_Orders::coverage_weeks(['adjusted_weekly' => 0, 'current_stock' => 5, 'case_size' => 1, 'cases' => 1]), null, 'T-4: zero demand → null (no warning possible)');

// --- summary ---
echo "\n" . $passed . " passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
