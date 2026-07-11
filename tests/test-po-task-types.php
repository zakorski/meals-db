<?php
/**
 * Workflow-native PO task types (spec 2026-07-10 task-integration §3):
 *   po_confirm_arrival — arrived yes → mark_received (idempotent); no → defer.
 *   po_reconcile       — per-SKU counts+notes → service reconcile (idempotent).
 *
 * Run with: php tests/test-po-task-types.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// Pre-defining the class wins over the autoloader — capture degraded events.
class MealsDB_Event_Log {
    public static array $records = [];
    public static function record(array $args): void { self::$records[] = $args; }
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

    public array $tasks = [];
    private int $next_task_id = 1;

    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }

    public function insert($table, $data, $formats = null) {
        if (strpos($table, 'meals_tasks') !== false) {
            $id = $this->next_task_id++;
            $data['task_id'] = $id;
            $data += ['status' => 'pending', 'deferral_count' => 0];
            $this->tasks[$id] = $data;
            $this->insert_id = $id;
            return 1;
        }
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
        if (stripos($q, 'meals_tasks') !== false && preg_match('/task_id = (\d+)/', $q, $m)) {
            return $this->tasks[(int) $m[1]] ?? null;
        }
        if (stripos($q, 'meals_purchase_orders') !== false && preg_match('/po_id = (\d+)/', $q, $m)) {
            return $this->pos[(int) $m[1]] ?? null;
        }
        return null;
    }

    public function get_results($q, $o = null) {
        if (stripos($q, 'meals_tasks') !== false) {
            // Honor the filters query_tasks() emits (values inlined by prepare()).
            $status = preg_match("/status IN \(([^)]*)\)/", $q, $m1) ? array_map(function ($s) { return trim($s, " '"); }, explode(',', $m1[1])) : null;
            $type   = preg_match("/task_type IN \(([^)]*)\)/", $q, $m2) ? array_map(function ($s) { return trim($s, " '"); }, explode(',', $m2[1])) : null;
            $rtype  = preg_match("/related_entity_type = '([^']*)'/", $q, $m3) ? $m3[1] : null;
            $rid    = preg_match('/related_entity_id = (\d+)/', $q, $m4) ? (int) $m4[1] : null;
            $out = [];
            foreach ($this->tasks as $t) {
                if ($status !== null && !in_array((string) ($t['status'] ?? ''), $status, true)) { continue; }
                if ($type !== null && !in_array((string) ($t['task_type'] ?? ''), $type, true)) { continue; }
                if ($rtype !== null && (string) ($t['related_entity_type'] ?? '') !== $rtype) { continue; }
                if ($rid !== null && (int) ($t['related_entity_id'] ?? 0) !== $rid) { continue; }
                $out[] = $t;
            }
            return $out;
        }
        if (stripos($q, 'meals_purchase_orders') !== false) {
            return array_values($this->pos);
        }
        return [];
    }

    public function update($table, $data, $where, $df = null, $wf = null) {
        if (strpos($table, 'meals_tasks') !== false) {
            $id = (int) ($where['task_id'] ?? 0);
            if (!isset($this->tasks[$id])) { return 0; }
            foreach ($data as $k => $v) { $this->tasks[$id][$k] = $v; }
            return 1;
        }
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
function degraded_events(string $event): array {
    $out = [];
    foreach (MealsDB_Event_Log::$records as $r) {
        if (($r['event'] ?? '') === $event && ($r['outcome'] ?? '') === 'degraded') { $out[] = $r; }
    }
    return $out;
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

MealsDB_Task_Registry::reset();
MealsDB_Task_Type_PO_Confirm_Arrival::register();
MealsDB_Task_Type_PO_Reconcile::register();

/** Drive a fresh draft to 'placed' with a known expected arrival; return [svc, engine, po_id]. */
function placed_po(PoWpdb $w): array {
    $GLOBALS['wc_stock'] = [101 => 50, 102 => 20];
    $svc = new MealsDB_Purchase_Orders();
    $engine = new MealsDB_Task_Engine($w);
    $id = $svc->create_draft(forecast_rows());
    $svc->approve($id, '2026-07-24');
    return [$svc, $engine, $id];
}

/** Manually create a confirm-arrival task linked to a PO (bridge is Task 6). */
function confirm_task(MealsDB_Task_Engine $engine, int $po_id): int {
    return $engine->create_task([
        'task_type'           => MealsDB_Task_Type_PO_Confirm_Arrival::TYPE_ID,
        'payload'             => ['po_number' => 'PO-X', 'supplier' => 'Apetito', 'expected_arrival' => '2026-07-24'],
        'next_run_date'       => '2026-07-24',
        'related_entity_type' => 'po',
        'related_entity_id'   => $po_id,
        'assignee_role'       => 'warehouse',
    ]);
}

// ===========================================================================
// C-1: arrived=yes on a placed PO → mark_received via the service, once.
// ===========================================================================
$w = fresh();
[$svc, $engine, $po_id] = placed_po($w);
$tid = confirm_task($engine, $po_id);
chk_true($tid > 0, 'C-1: task created');
chk($engine->complete_task($tid, ['arrived' => 'yes', 'arrival_date' => '2026-07-22'], 7), true, 'C-1: completes');
$po = $svc->get_with_payload($po_id);
chk($po['status'], 'arrived', 'C-1: PO received via task');
chk($po['arrival_date'], '2026-07-22', 'C-1: form arrival date honored');
chk($GLOBALS['wc_stock'][101], 110, 'C-1: stock bumped');
chk($w->tasks[$tid]['status'], 'completed', 'C-1: task completed');

// ===========================================================================
// C-2: arrived=yes on an ALREADY-received PO → graceful no-op, no double bump.
// ===========================================================================
$w = fresh();
[$svc, $engine, $po_id] = placed_po($w);
$svc->mark_received($po_id); // PO page acted first
chk($GLOBALS['wc_stock'][101], 110, 'C-2: first bump applied');
$tid = confirm_task($engine, $po_id);
chk($engine->complete_task($tid, ['arrived' => 'yes'], 7), true, 'C-2: stale task still completes');
chk($GLOBALS['wc_stock'][101], 110, 'C-2: NO double bump');
chk($svc->get_with_payload($po_id)['status'], 'arrived', 'C-2: status unchanged');

// ===========================================================================
// C-3: arrived=no → auto-defer +1 day (completion reversed).
// ===========================================================================
$w = fresh();
[$svc, $engine, $po_id] = placed_po($w);
$tid = confirm_task($engine, $po_id);
$engine->complete_task($tid, ['arrived' => 'no'], 7);
chk($w->tasks[$tid]['status'], 'deferred', 'C-3: task deferred, not completed');
chk($w->tasks[$tid]['next_run_date'], gmdate('Y-m-d', strtotime('+1 day')), 'C-3: due tomorrow');
chk($svc->get_with_payload($po_id)['status'], 'placed', 'C-3: PO untouched');
chk($GLOBALS['wc_stock'][101], 50, 'C-3: no stock effect');

/** Drive a PO to 'arrived' and create a reconcile task; return [svc, engine, po_id, tid]. */
function reconcile_setup(PoWpdb $w): array {
    [$svc, $engine, $po_id] = placed_po($w);
    $svc->mark_received($po_id); // stock now 110 / 44
    $tid = $engine->create_task([
        'task_type'           => MealsDB_Task_Type_PO_Reconcile::TYPE_ID,
        'payload'             => ['po_number' => 'PO-X', 'rows' => [
            ['sku' => 'CD-001', 'product_name' => 'Chicken Dinner', 'ordered_cases' => 10],
            ['sku' => 'SD-002', 'product_name' => 'Side Salad',     'ordered_cases' => 2],
        ]],
        'next_run_date'       => gmdate('Y-m-d'),
        'related_entity_type' => 'po',
        'related_entity_id'   => $po_id,
        'assignee_role'       => 'warehouse',
    ]);
    return [$svc, $engine, $po_id, $tid];
}

// ===========================================================================
// R-T1: happy path — short 2 cases with a note → deltas + reconciled.
// ===========================================================================
$w = fresh();
[$svc, $engine, $po_id, $tid] = reconcile_setup($w);
$ok = $engine->complete_task($tid, [
    'count_received' => 'yes',
    'sku_rows' => [
        ['sku' => 'CD-001', 'ordered_cases' => 10, 'received_cases' => 8, 'note' => 'Two cases damaged in transit'],
        ['sku' => 'SD-002', 'ordered_cases' => 2,  'received_cases' => 2, 'note' => ''],
    ],
], 7);
chk($ok, true, 'R-T1: completes');
$po = $svc->get_with_payload($po_id);
chk($po['status'], 'reconciled', 'R-T1: PO reconciled via task');
chk($GLOBALS['wc_stock'][101], 98, 'R-T1: delta applied (110 − 2×6)');
chk($GLOBALS['wc_stock'][102], 44, 'R-T1: unchanged row no delta');
chk_true(audit_has($w, 'Two cases damaged in transit'), 'R-T1: note in discrepancy audit');
chk($w->tasks[$tid]['status'], 'completed', 'R-T1: task completed');

// ===========================================================================
// R-T2: already reconciled on the PO page → graceful no-op, no double delta.
// ===========================================================================
$w = fresh();
[$svc, $engine, $po_id, $tid] = reconcile_setup($w);
$svc->edit_reconcile_row($po_id, 'CD-001', 8, 'Two cases damaged in transit');
$svc->complete_reconcile($po_id); // PO page acted first → stock 98
chk($GLOBALS['wc_stock'][101], 98, 'R-T2: page-side delta applied');
$ok = $engine->complete_task($tid, [
    'count_received' => 'yes',
    'sku_rows' => [
        ['sku' => 'CD-001', 'ordered_cases' => 10, 'received_cases' => 8, 'note' => 'dup'],
        ['sku' => 'SD-002', 'ordered_cases' => 2,  'received_cases' => 2, 'note' => ''],
    ],
], 7);
chk($ok, true, 'R-T2: stale task still completes');
chk($GLOBALS['wc_stock'][101], 98, 'R-T2: NO double delta');

// ===========================================================================
// R-T3: count_received=no → auto-defer, no effects.
// ===========================================================================
$w = fresh();
[$svc, $engine, $po_id, $tid] = reconcile_setup($w);
$engine->complete_task($tid, ['count_received' => 'no'], 7);
chk($w->tasks[$tid]['status'], 'deferred', 'R-T3: deferred');
chk($svc->get_with_payload($po_id)['status'], 'arrived', 'R-T3: PO untouched');
chk($GLOBALS['wc_stock'][101], 110, 'R-T3: no delta');

// ===========================================================================
// D-1: degraded paths — no related PO / stale status emit Event_Log rows.
// ===========================================================================
$w = fresh();
MealsDB_Event_Log::$records = [];
$engine = new MealsDB_Task_Engine($w);

// Confirm task with NO related PO → confirm_no_entity.
$tid = $engine->create_task([
    'task_type' => MealsDB_Task_Type_PO_Confirm_Arrival::TYPE_ID,
    'payload'   => ['po_number' => 'PO-X'],
]);
$engine->complete_task($tid, ['arrived' => 'yes'], 7);
chk(count(degraded_events('po_task.confirm_no_entity')), 1, 'D-1: confirm_no_entity degraded event');

// Confirm task pointing at a DRAFT PO (stale) → stale_confirm.
$svc = new MealsDB_Purchase_Orders();
$draft_id = $svc->create_draft(forecast_rows());
$tid = confirm_task($engine, $draft_id); // PO still planned
$engine->complete_task($tid, ['arrived' => 'yes'], 7);
chk(count(degraded_events('po_task.stale_confirm')), 1, 'D-1: stale_confirm degraded event');
chk($svc->get_with_payload($draft_id)['status'], 'planned', 'D-1: draft PO untouched');

// Reconcile task with no related PO → reconcile_no_entity.
MealsDB_Event_Log::$records = [];
$tid = $engine->create_task([
    'task_type' => MealsDB_Task_Type_PO_Reconcile::TYPE_ID,
    'payload'   => ['po_number' => 'PO-X', 'rows' => []],
]);
$engine->complete_task($tid, ['count_received' => 'yes'], 7);
chk(count(degraded_events('po_task.reconcile_no_entity')), 1, 'D-1: reconcile_no_entity degraded event');

// --- summary ---
echo "\n" . $passed . " passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
