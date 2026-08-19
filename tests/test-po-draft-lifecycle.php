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
if (!function_exists('do_action')) {
    function do_action(string $hook, ...$args) { $GLOBALS['fired_actions'][] = ['hook' => $hook, 'args' => $args]; }
}
if (!function_exists('add_action')) {
    function add_action(string $hook, $cb, int $prio = 10, int $accepted = 1) { $GLOBALS['wp_actions'][$hook][] = $cb; }
}
$GLOBALS['fired_actions'] = [];
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
    public function get_var($q) {
        if (stripos($q, 'meals_purchase_orders') !== false && preg_match('/po_id = (\d+)/', $q, $m)) {
            return $this->pos[(int) $m[1]]['status'] ?? null;
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

// ===========================================================================
// T-5: edit_draft_cases — happy path, audit, edit_count.
// ===========================================================================
$w = fresh();
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
$r = $svc->edit_draft_cases($id, 'CD-001', 12);
chk_true(is_array($r), 'T-5: edit returns array');
chk($r['changed'], true, 'T-5: changed = true');
chk($r['cases'], 12, 'T-5: new cases echoed');
chk($r['order_quantity'], 72, 'T-5: order_quantity = 12*6');
// (40 + 12*6) / 11 = 10.2
chk($r['coverage_weeks'], 10.2, 'T-5: coverage recomputed');
$po = $svc->get_with_payload($id);
chk((int) $po['payload']['current'][0]['cases'], 12, 'T-5: payload persisted');
chk((int) $po['payload']['generated'][0]['cases'], 10, 'T-5: generated baseline untouched');
chk((int) $po['edit_count'], 1, 'T-5: edit_count bumped');
chk_true(audit_has($w, 'po_draft_edit'), 'T-5: po_draft_edit audited');

// No-op: same value → changed=false, no extra audit / count bump.
$audit_before = count($w->audit);
$r = $svc->edit_draft_cases($id, 'CD-001', 12);
chk($r['changed'], false, 'T-5: no-op reports changed=false');
chk(count($w->audit), $audit_before, 'T-5: no-op writes no audit row');
$po = $svc->get_with_payload($id);
chk((int) $po['edit_count'], 1, 'T-5: no-op does not bump edit_count');

// Clamp-at-zero is the JS's job; the service just accepts 0.
$r = $svc->edit_draft_cases($id, 'CD-001', 0);
chk($r['changed'], true, 'T-5: zeroing a row is allowed');

// ===========================================================================
// T-6: edit_draft_cases — validation and status guards.
// ===========================================================================
chk($svc->edit_draft_cases($id, 'NOPE-9', 1)->get_error_code(), 'unknown_sku', 'T-6: unknown sku rejected');
chk($svc->edit_draft_cases($id, 'CD-001', -1)->get_error_code(), 'bad_cases', 'T-6: negative rejected');
chk($svc->edit_draft_cases($id, 'CD-001', 10001)->get_error_code(), 'bad_cases', 'T-6: >10000 rejected');
chk($svc->edit_draft_cases(9999, 'CD-001', 1)->get_error_code(), 'not_found', 'T-6: missing PO rejected');

// Legacy PO (payload NULL) is untouchable.
$legacy_id = $svc->create(['po_number' => 'LEG-1', 'status' => 'planned',
    'items' => [['sku' => 'CD-001', 'product_name' => 'X', 'quantity_ordered' => 6]]]);
chk($svc->edit_draft_cases($legacy_id, 'CD-001', 1)->get_error_code(), 'legacy', 'T-6: legacy PO rejected');

// Locked once not planned.
$w->pos[$id]['status'] = 'placed';
chk($svc->edit_draft_cases($id, 'CD-001', 3)->get_error_code(), 'locked', 'T-6: non-draft status rejected');
$w->pos[$id]['status'] = 'planned';

// T-6b: the edit-vs-transition race — require_workflow_po saw 'planned' but
// the guarded UPDATE finds the status already changed → save_failed, and the
// payload is NOT mutated.
$w = new RaceWpdb();
$GLOBALS['wpdb'] = $w;
$svc = new MealsDB_Purchase_Orders();
$rid = $svc->create_draft(forecast_rows());
$res = $svc->edit_draft_cases($rid, 'CD-001', 5);
chk($res->get_error_code(), 'save_failed', 'T-6b: lost race surfaces save_failed');
$after = $svc->get_with_payload($rid);
chk((int) $after['payload']['current'][0]['cases'], 10, 'T-6b: payload not mutated on lost race');
chk((int) $after['edit_count'], 0, 'T-6b: edit_count not bumped on lost race');

// ===========================================================================
// T-7: approve — items written in UNITS, zero rows omitted, guarded.
// ===========================================================================
$w = fresh();
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
$svc->edit_draft_cases($id, 'SD-002', 0); // operator zeroes a row = "don't order"
$r = $svc->approve($id);
chk($r, true, 'T-7: approve succeeds');
$po = $svc->get_with_payload($id);
chk($po['status'], 'placed', 'T-7: status → placed (Approved)');
chk_true(!empty($po['approved_at']), 'T-7: approved_at set');
chk((int) $po['approved_by'], 7, 'T-7: approved_by = current user');
chk_true(!empty($po['placed_date']), 'T-7: placed_date set');
chk(count($po['items']), 1, 'T-7: zero-case row omitted from items');
chk($po['items'][0]['sku'], 'CD-001', 'T-7: item sku');
chk((int) $po['items'][0]['quantity_ordered'], 60, 'T-7: quantity_ordered in UNITS (10 cases × 6)');
chk_true(audit_has($w, 'po_approved'), 'T-7: po_approved audited');

// Double-approve loses the guard.
chk($svc->approve($id)->get_error_code(), 'locked', 'T-7: second approve rejected');

// All-zero draft cannot be approved.
$id2 = $svc->create_draft(forecast_rows());
$svc->edit_draft_cases($id2, 'CD-001', 0);
$svc->edit_draft_cases($id2, 'SD-002', 0);
chk($svc->approve($id2)->get_error_code(), 'empty', 'T-7: all-zero draft rejected');

// ===========================================================================
// T-8: unapprove — reason required, only from placed, clears approval marks.
// ===========================================================================
chk($svc->unapprove($id, '')->get_error_code(), 'reason_required', 'T-8: empty reason rejected');
chk($svc->unapprove($id, '   ')->get_error_code(), 'reason_required', 'T-8: whitespace reason rejected');
$r = $svc->unapprove($id, 'Apetito changed the delivery window');
chk($r, true, 'T-8: unapprove succeeds');
$po = $svc->get_with_payload($id);
chk($po['status'], 'planned', 'T-8: back to planned (Draft)');
chk($po['approved_by'], null, 'T-8: approved_by cleared');
chk($po['approved_at'], null, 'T-8: approved_at cleared');
chk($po['placed_date'], null, 'T-8: placed_date cleared');
chk_true(audit_has($w, 'po_unapproved'), 'T-8: po_unapproved audited');
chk_true(audit_has($w, 'Apetito changed the delivery window'), 'T-8: reason lands in audit row');
chk($svc->unapprove($id, 'again')->get_error_code(), 'locked', 'T-8: unapprove from draft rejected');

// ===========================================================================
// T-9: cancel_draft — only from planned.
// ===========================================================================
$r = $svc->cancel_draft($id);
chk($r, true, 'T-9: cancel from draft succeeds');
chk($svc->get_with_payload($id)['status'], 'cancelled', 'T-9: status → cancelled');
chk_true(audit_has($w, 'po_draft_cancelled'), 'T-9: audited');
chk($svc->cancel_draft($id)->get_error_code(), 'locked', 'T-9: cancel twice rejected');

// ===========================================================================
// T-9b: the transition race branch — require_workflow_po saw the expected
// status but the guarded UPDATE matched 0 rows → 'race', no audit row.
// ===========================================================================
$w = new TransitionRaceWpdb();
$GLOBALS['wpdb'] = $w;
$svc = new MealsDB_Purchase_Orders();
$rid = $svc->create_draft(forecast_rows());
$audit_before = count($w->audit);
chk($svc->approve($rid)->get_error_code(), 'race', 'T-9b: approve lost race → race');
chk($svc->cancel_draft($rid)->get_error_code(), 'race', 'T-9b: cancel lost race → race');
chk(count($w->audit), $audit_before, 'T-9b: no audit rows on lost races');

// ===========================================================================
// T-10: mark_received — placed→arrived, stock bumped exactly once.
// ===========================================================================
$w = fresh();
$GLOBALS['wc_stock'] = [101 => 50, 102 => 20];
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
chk($svc->mark_accepted($id)->get_error_code(), 'locked', 'T-10: accept before approve rejected');
$svc->approve($id);
chk($svc->mark_received($id)->get_error_code(), 'locked', 'T-10: receive before accept rejected');
// Accept commits stock exactly once.
$r = $svc->mark_accepted($id);
chk($r, true, 'T-10: mark_accepted succeeds');
$po = $svc->get_with_payload($id);
chk($po['status'], 'accepted', 'T-10: status → accepted');
chk_true(!empty($po['accepted_at']), 'T-10: accepted_at set');
// CD-001: 10 cases × 6 = 60 units onto 50; SD-002: 2 cases × 12 = 24 onto 20.
chk($GLOBALS['wc_stock'][101], 110, 'T-10: CD-001 stock committed at accept');
chk($GLOBALS['wc_stock'][102], 44, 'T-10: SD-002 stock committed at accept');
chk_true(audit_has($w, 'po_accepted'), 'T-10: po_accepted audited');
// Received is now a pure marker: status advances, stock unchanged.
$r = $svc->mark_received($id);
chk($r, true, 'T-10: mark_received succeeds from accepted');
$po = $svc->get_with_payload($id);
chk($po['status'], 'arrived', 'T-10: status → arrived (Received)');
chk_true(!empty($po['received_at']), 'T-10: received_at set');
chk_true(!empty($po['arrival_date']), 'T-10: arrival_date set');
chk($GLOBALS['wc_stock'][101], 110, 'T-10: received does not re-bump');
chk_true(audit_has($w, 'po_received'), 'T-10: po_received audited');
// Second receive click: guard loses.
chk($svc->mark_received($id)->get_error_code(), 'locked', 'T-10: double receive rejected');
chk($GLOBALS['wc_stock'][101], 110, 'T-10: no double bump');

// ===========================================================================
// T-11: lifecycle hooks + expected_arrival / arrival_date params.
// ===========================================================================
$w = fresh();
$GLOBALS['fired_actions'] = [];
$GLOBALS['wc_stock'] = [101 => 50, 102 => 20];
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());

function fired(string $hook): array {
    $out = [];
    foreach ($GLOBALS['fired_actions'] as $f) { if ($f['hook'] === $hook) { $out[] = $f; } }
    return $out;
}

// approve with an expected arrival: stored + passed to the hook.
chk($svc->approve($id, '2026-07-24'), true, 'T-11: approve accepts expected_arrival');
$po = $svc->get_with_payload($id);
chk($po['expected_arrival'], '2026-07-24', 'T-11: expected_arrival stored');
$f = fired('mealsdb_po_approved');
chk(count($f), 1, 'T-11: mealsdb_po_approved fired once');
chk($f[0]['args'], [$id, '2026-07-24'], 'T-11: hook args = po_id + expected_arrival');

// unapprove: clears expected_arrival, fires hook with reason.
$svc->unapprove($id, 'window moved');
$po = $svc->get_with_payload($id);
chk($po['expected_arrival'], null, 'T-11: expected_arrival cleared on unapprove');
$f = fired('mealsdb_po_unapproved');
chk($f[0]['args'], [$id, 'window moved'], 'T-11: unapproved hook args');

// malformed (or absent) expected_arrival → falls back to the derived cadence
// arrival (T+13 from today's order date), NOT null — directive item 3.
$derived_arrival = MealsDB_Purchase_Orders::po_schedule_from_order_date(gmdate('Y-m-d'))['expected_arrival'];
chk($svc->approve($id, 'not-a-date'), true, 'T-11: approve tolerates malformed date');
chk($svc->get_with_payload($id)['expected_arrival'], $derived_arrival, 'T-11: malformed date → derived T+13 arrival');
$f = fired('mealsdb_po_approved');
chk($f[1]['args'], [$id, $derived_arrival], 'T-11: hook gets derived arrival for malformed date');

// mark_accepted commits stock, then mark_received with an explicit arrival date.
chk($svc->mark_accepted($id), true, 'T-11: mark_accepted before receive');
chk(count(fired('mealsdb_po_accepted')), 1, 'T-11: mealsdb_po_accepted fired');
chk($svc->mark_received($id, '2026-07-22'), true, 'T-11: mark_received accepts arrival_date');
$po = $svc->get_with_payload($id);
chk($po['arrival_date'], '2026-07-22', 'T-11: explicit arrival_date stored');
chk(count(fired('mealsdb_po_received')), 1, 'T-11: mealsdb_po_received fired');
chk(fired('mealsdb_po_received')[0]['args'], [$id], 'T-11: received hook args');

// complete_reconcile fires its hook.
$svc->edit_reconcile_row($id, 'CD-001', 10, ''); // unchanged count, no note needed
chk($svc->complete_reconcile($id), true, 'T-11: reconcile completes');
chk(count(fired('mealsdb_po_reconciled')), 1, 'T-11: mealsdb_po_reconciled fired');

// Hooks do NOT fire on refused transitions.
$before = count($GLOBALS['fired_actions']);
$svc->approve($id); // locked — already reconciled
chk(count($GLOBALS['fired_actions']), $before, 'T-11: no hook on refused transition');

// --- summary ---
echo "\n" . $passed . " passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
