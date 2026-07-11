# PO Draft Workflow + Case Adjustment UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Purchase Order generator an invoice-style draft → approve → receive → reconcile lifecycle on the existing `meals_purchase_orders` table, plus +/- per-case adjustment buttons with 9-week (yellow `!`) and 7-week (red `!`) coverage warnings.

**Architecture:** Reuse the existing `meals_purchase_orders` table and status ENUM (`planned`=Draft, `placed`=Approved, `arrived`=Received, `reconciled`, `cancelled`) — all schema changes are ADDITIVE columns (Schema_Sync cannot ALTER). Lifecycle methods live on the existing `MealsDB_Purchase_Orders` service using guarded UPDATEs (`WHERE status='<expected>'`) for race safety. A new AJAX class mirrors the invoice-draft guard spine (nonce → capability → rate limit → validate → act). Inventory side-effects delegate to the two existing task-type statics (`apply_inventory_bump`, `apply_adjustments`) WITHOUT touching the task system. Spec: `docs/superpowers/specs/2026-07-10-po-draft-workflow-design.md`.

**Tech Stack:** WordPress plugin, PHP 8.2, `$wpdb`, jQuery admin JS with JSON data islands, standalone PHP test scripts (no PHPUnit — run with `php tests/<file>.php`, exit code 0 = pass).

---

## Codebase facts the engineer must know

- **CLAUDE.md is binding.** Read it first. Key rules used here: `\Throwable` not `\Exception`; UTC via `gmdate()`; `$wpdb->prepare()` everywhere; audit log (`MealsDB_Logger::log`) for committed data changes; no inline `<script>` > 20 lines; escape at output.
- Table name resolution: `MealsDB_DB::get_table_name(MealsDB_Tables::PURCHASE_ORDERS)` → prefixed `meals_purchase_orders`.
- JSON encode helper used by this table already: `MealsDB_Task_Engine::encode_json($value)` (`includes/services/class-task-engine.php:569`).
- Audit signature: `MealsDB_Logger::log(string $action, int $target_id, string $field, ?string $old, ?string $new, string $source = 'mealsdb')`.
- Inventory statics (task system NOT otherwise touched):
  - `MealsDB_Task_Type_Confirm_PO_Arrival::apply_inventory_bump(array $items)` — atomic WC stock increase per item (`quantity_ordered` in UNITS), audits `po_inventory_bump` per product.
  - `MealsDB_Task_Type_Physical_Count::apply_adjustments(int $po_id, array $adjustments)` — each `{sku, actual_count (UNITS), reason, reason_notes}`; server-sources ordered qty from the stored PO `items`, rejects unknown SKUs, audits `inventory_discrepancy` per SKU.
- `wpdb->update($table, $data, $where)` accepts multiple WHERE conditions and writes PHP `null` as SQL `NULL`. Returns rows-affected (int) or `false` on error — a guarded transition that loses the race returns `0`.
- Forecast rows (from `MealsDB_Reports::generate_purchase_order()`) contain: `sku, product_name, weighted_avg_weekly, seasonal_index, adjusted_weekly, projected_need, current_stock, total_available, units_needed, case_size, cases_to_buy, order_quantity, seasonal_note, weekly_history` (+ `freight_delta_cases` on pallet-optimized rows).
- Tests are standalone scripts: define WP stubs, an in-memory `wpdb` subclass, tiny `chk()/chk_true()` harness, `exit(empty($failures) ? 0 : 1)`. Model: `tests/test-invoice-draft.php` (stateful wpdb) and `tests/test-task-workflow-po-chain.php` (WC product stubs).
- Local CLI lacks mbstring/imagick: `test-slip-pdf-generator.php` and one other PDF test fail as baseline — ignore those two, nothing else.

## File structure

| File | Action | Responsibility |
|---|---|---|
| `includes/class-schema.php` | Modify | Add 8 columns to the `meals_purchase_orders` definition |
| `includes/class-rate-limiter.php` | Modify | Add `po_draft_edit` bucket (300/hr, mutating) |
| `meals-db-main.php` | Modify | Version bump 1.0.496→1.0.497; register new AJAX class |
| `includes/services/class-purchase-orders.php` | Modify | Workflow methods (create_draft, edits, transitions, coverage) |
| `includes/ajax/class-ajax-purchase-orders.php` | Create | 8 AJAX endpoints, guard spine |
| `views/purchase-orders.php` | Rewrite | List + detail (draft/locked/reconcile modes) + legacy render |
| `views/purchase-order.php` | Modify | "Save as draft PO" button + island keys |
| `assets/js/purchase-order.js` | Modify | Save-as-draft handler |
| `assets/js/purchase-orders.js` | Create | List actions + detail editor (+/- buttons, warnings) |
| `assets/css/purchase-orders.css` | Create | Warning badges, +/- buttons, table styling |
| `includes/class-admin-ui.php` | Modify | Enqueue JS+CSS for `po_admin` tab |
| `tests/test-po-draft-lifecycle.php` | Create | Draft/approve/receive lifecycle + validation |
| `tests/test-po-reconcile-deltas.php` | Create | Reconcile notes + stock deltas |

---

### Task 0: Branch

- [ ] **Step 0.1: Create the feature branch**

```bash
cd /mnt/fastssd/meals-db && git checkout main && git pull && git checkout -b feat/po-draft-workflow
```

---

### Task 1: Schema columns, rate bucket, version bump

**Files:**
- Modify: `includes/class-schema.php` (the `MealsDB_Tables::PURCHASE_ORDERS` block, ~line 474)
- Modify: `includes/class-rate-limiter.php` (~lines 18–52)
- Modify: `meals-db-main.php:6`

- [ ] **Step 1.1: Add the new columns to the canonical schema**

In `includes/class-schema.php`, inside the `MealsDB_Tables::PURCHASE_ORDERS` definition, replace the `columns` array with (only the lines between `'reconciled_at'` and `'created_at'` are new — do not touch existing lines):

```php
'columns' => [
    'po_id'            => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
    'po_number'        => 'VARCHAR(50) NOT NULL',
    'supplier'         => 'VARCHAR(100) NULL',
    'placed_date'      => 'DATE NULL',
    'expected_arrival' => 'DATE NULL',
    'arrival_date'     => 'DATE NULL',
    'status'           => "ENUM('planned','placed','arrived','counted','reconciled','cancelled') NOT NULL DEFAULT 'planned'",
    'items'            => 'JSON NULL',
    'notes'            => 'TEXT NULL',
    'reconciled_at'    => 'DATETIME NULL',
    // --- PO draft workflow (2026-07 spec). ADDITIVE ONLY: Schema_Sync
    // cannot ALTER existing columns, which is exactly why the workflow
    // reuses the existing status ENUM ('planned' displays as "Draft")
    // instead of adding new ENUM values. payload IS NULL marks a legacy
    // task-created PO (read-only in the new UI; its lifecycle stays with
    // the task chain so the two paths can never double-bump stock).
    'payload'          => 'LONGTEXT NULL',
    'edit_count'       => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'created_by'       => 'BIGINT UNSIGNED NULL',
    'approved_by'      => 'BIGINT UNSIGNED NULL',
    'approved_at'      => 'DATETIME NULL',
    'received_by'      => 'BIGINT UNSIGNED NULL',
    'received_at'      => 'DATETIME NULL',
    'reconciled_by'    => 'BIGINT UNSIGNED NULL',
    'created_at'       => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'updated_at'       => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
],
```

- [ ] **Step 1.2: Add the rate bucket**

In `includes/class-rate-limiter.php`, add to `DEFAULT_LIMITS` after the `invoice_draft_edit` entry:

```php
// Per-row +/- case edits on a PO draft / reconcile session. Same
// rationale as invoice_draft_edit: many small writes in one sitting.
'po_draft_edit'          => 300,  // PO draft & reconcile row edits
```

And to `MUTATING_ACTIONS`:

```php
'po_draft_edit'         => true,
```

- [ ] **Step 1.3: Bump the plugin version**

In `meals-db-main.php` line 6, change ` * Version: 1.0.496` to ` * Version: 1.0.497`. (`MEALS_DB_VERSION` is derived from this header; the bump makes `mealsdb_maybe_upgrade_schema` run `MealsDB_Installer::install()` → `Schema_Sync` adds the columns on next admin load.)

- [ ] **Step 1.4: Lint and commit**

```bash
php -l includes/class-schema.php && php -l includes/class-rate-limiter.php && php -l meals-db-main.php
git add includes/class-schema.php includes/class-rate-limiter.php meals-db-main.php
git commit -m "feat(po): additive schema columns + rate bucket for PO draft workflow

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Service — create_draft, get_with_payload, coverage helper

**Files:**
- Modify: `includes/services/class-purchase-orders.php`
- Test: `tests/test-po-draft-lifecycle.php` (create)

- [ ] **Step 2.1: Write the failing test file**

Create `tests/test-po-draft-lifecycle.php`:

```php
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
            $data += ['edit_count' => 0, 'reconciled_at' => null];
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
```

- [ ] **Step 2.2: Run the test to verify it fails**

Run: `php tests/test-po-draft-lifecycle.php`
Expected: PHP fatal `Call to undefined method MealsDB_Purchase_Orders::create_draft()`.

- [ ] **Step 2.3: Implement create_draft, get_with_payload, coverage_weeks, status_label**

In `includes/services/class-purchase-orders.php`, add after the existing constants (line 21):

```php
    /**
     * PO draft workflow (spec 2026-07-10). The existing statuses double as
     * the workflow states — displayed via status_label():
     *   planned=Draft, placed=Approved, arrived=Received, reconciled, cancelled.
     * payload IS NULL ⇒ legacy task-created PO: the new workflow refuses to
     * touch it (its lifecycle belongs to the task chain — prevents a task and
     * a list action double-applying the same inventory bump).
     */
    public const PAYLOAD_SCHEMA = 1;

    /** Mirrors the forecast model's 9-week coverage target (class-reports.php). */
    public const COVERAGE_TARGET_WEEKS = 9.0;

    /** Mirrors the pallet-optimizer's 7-week safety floor (class-reports.php). */
    public const COVERAGE_FLOOR_WEEKS = 7.0;

    public const DEFAULT_SUPPLIER = 'Apetito';

    private const MAX_CASES    = 10000; // fat-finger ceiling on any row
    private const MAX_NOTE_LEN = 500;   // reconcile note length cap
```

Add these methods at the end of the class (before the closing brace):

```php
    // -----------------------------------------------------------------
    // Draft workflow (spec 2026-07-10) — creation + reads
    // -----------------------------------------------------------------

    /**
     * Persist a generated forecast as a Draft PO. $rows is the output of
     * MealsDB_Reports::generate_purchase_order() (optionally pallet-optimized);
     * each row is snapshotted with its demand/stock context so the coverage
     * warnings stay deterministic for the life of the draft.
     *
     * Returns po_id, or 0 on failure.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $meta supplier / notes overrides
     */
    public function create_draft(array $rows, array $meta = []): int {
        // Defense-in-depth: service-layer capability re-check (Pattern 1).
        if (class_exists('MealsDB_Permissions') && !MealsDB_Permissions::can_access_plugin()) {
            return 0;
        }

        $payload_rows = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $sku = trim((string) ($r['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $case_size = max(1, (int) ($r['case_size'] ?? 1));
            $cases     = max(0, (int) ($r['cases_to_buy'] ?? 0));
            $payload_rows[] = [
                'sku'                 => $sku,
                'product_name'        => (string) ($r['product_name'] ?? ''),
                'case_size'           => $case_size,
                'cases'               => $cases,
                'order_quantity'      => $cases * $case_size,
                'adjusted_weekly'     => round((float) ($r['adjusted_weekly'] ?? 0), 2),
                'current_stock'       => (int) ($r['current_stock'] ?? 0),
                'seasonal_index'      => round((float) ($r['seasonal_index'] ?? 1), 2),
                'freight_delta_cases' => (int) ($r['freight_delta_cases'] ?? 0),
                'seasonal_note'       => (string) ($r['seasonal_note'] ?? ''),
            ];
        }
        if (empty($payload_rows)) {
            error_log('[MealsDB Purchase Orders] create_draft: no usable rows.');
            return 0;
        }

        $payload = [
            'schema'    => self::PAYLOAD_SCHEMA,
            'generated' => $payload_rows,
            'current'   => $payload_rows,
            'received'  => [], // sku => {received_cases, note}, reconcile session
        ];

        $row = [
            'po_number'  => 'PO-' . gmdate('Ymd-His'),
            'supplier'   => isset($meta['supplier']) ? (string) $meta['supplier'] : self::DEFAULT_SUPPLIER,
            'status'     => self::STATUS_PLANNED,
            // items stays empty until approval — it is the "what was actually
            // ordered" contract consumed by apply_inventory_bump/_adjustments.
            'items'      => MealsDB_Task_Engine::encode_json([]),
            'notes'      => isset($meta['notes']) ? (string) $meta['notes'] : null,
            'payload'    => MealsDB_Task_Engine::encode_json($payload),
            'edit_count' => 0,
            'created_by' => get_current_user_id() ?: null,
        ];

        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::PURCHASE_ORDERS);
        $result = $this->wpdb->insert($table, $row);
        if ($result === false) {
            // uniq_po_number backstop: two saves in the same second collide.
            // One suffixed retry covers the realistic case (one operator).
            $row['po_number'] .= '-2';
            $result = $this->wpdb->insert($table, $row);
            if ($result === false) {
                error_log('[MealsDB Purchase Orders] create_draft insert failed: ' . $this->wpdb->last_error);
                return 0;
            }
        }

        $po_id = (int) $this->wpdb->insert_id;
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('po_draft_created', $po_id, 'status', null, self::STATUS_PLANNED);
        }
        return $po_id;
    }

    /**
     * get() plus decoded workflow payload. payload === null ⇒ legacy
     * task-created PO (or corrupt JSON, treated the same: read-only).
     *
     * @return array<string, mixed>|null
     */
    public function get_with_payload(int $po_id): ?array {
        $po = $this->get($po_id);
        if ($po === null) {
            return null;
        }
        if (isset($po['payload']) && is_string($po['payload']) && $po['payload'] !== '') {
            $decoded = json_decode($po['payload'], true);
            $po['payload'] = (is_array($decoded) && isset($decoded['current']) && is_array($decoded['current']))
                ? $decoded : null;
        } else {
            $po['payload'] = null;
        }
        return $po;
    }

    /**
     * Weeks of coverage for a payload row: (stock snapshot + cases×case_size)
     * ÷ adjusted weekly demand. Null when demand is zero (coverage undefined —
     * the UI shows no warning). $cases overrides the row's stored count so the
     * same math serves draft edits and reconcile previews.
     */
    public static function coverage_weeks(array $row, ?int $cases = null): ?float {
        $weekly = (float) ($row['adjusted_weekly'] ?? 0);
        if ($weekly <= 0) {
            return null;
        }
        $cases = $cases ?? (int) ($row['cases'] ?? 0);
        $units = (int) ($row['current_stock'] ?? 0) + $cases * max(1, (int) ($row['case_size'] ?? 1));
        return round($units / $weekly, 1);
    }

    /** Operator-facing label for a status (planned displays as Draft, etc). */
    public static function status_label(string $status): string {
        switch ($status) {
            case self::STATUS_PLANNED:    return __('Draft', 'meals-db');
            case self::STATUS_PLACED:     return __('Approved', 'meals-db');
            case self::STATUS_ARRIVED:    return __('Received', 'meals-db');
            case self::STATUS_RECONCILED: return __('Reconciled', 'meals-db');
            case self::STATUS_CANCELLED:  return __('Cancelled', 'meals-db');
            default:                      return $status; // legacy 'counted'
        }
    }
```

- [ ] **Step 2.4: Run the test to verify T-1 through T-4 pass**

Run: `php tests/test-po-draft-lifecycle.php`
Expected: `~20 passed, 0 failed`, exit 0.

- [ ] **Step 2.5: Commit**

```bash
php -l includes/services/class-purchase-orders.php
git add includes/services/class-purchase-orders.php tests/test-po-draft-lifecycle.php
git commit -m "feat(po): draft creation, payload reads, coverage helper on PO service

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Service — edit_draft_cases

**Files:**
- Modify: `includes/services/class-purchase-orders.php`
- Test: `tests/test-po-draft-lifecycle.php` (append)

- [ ] **Step 3.1: Append failing tests**

Append to `tests/test-po-draft-lifecycle.php` (before the `--- summary ---` block):

```php
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
```

- [ ] **Step 3.2: Run to verify the new tests fail**

Run: `php tests/test-po-draft-lifecycle.php`
Expected: fatal `Call to undefined method ...::edit_draft_cases()`.

- [ ] **Step 3.3: Implement edit_draft_cases + private helpers**

Add to `includes/services/class-purchase-orders.php`:

```php
    // -----------------------------------------------------------------
    // Draft workflow — case edits (+/- buttons)
    // -----------------------------------------------------------------

    /**
     * Set the ordered case count for one row of a Draft PO. Validates status,
     * SKU membership, and range; bumps edit_count and audits only on an actual
     * change. Coverage warnings are the caller's concern — this never blocks
     * on the 9/7-week thresholds (spec: warnings, not clamps).
     *
     * @return array{changed: bool, cases: int, order_quantity: int, coverage_weeks: float|null}|WP_Error
     */
    public function edit_draft_cases(int $po_id, string $sku, int $cases) {
        if (class_exists('MealsDB_Permissions') && !MealsDB_Permissions::can_access_plugin()) {
            return new WP_Error('forbidden', __('Insufficient permissions.', 'meals-db'));
        }
        if ($cases < 0 || $cases > self::MAX_CASES) {
            return new WP_Error('bad_cases', __('Case count is out of the allowed range.', 'meals-db'));
        }

        $po = $this->require_workflow_po($po_id, self::STATUS_PLANNED,
            __('Only draft purchase orders can be edited.', 'meals-db'));
        if (is_wp_error($po)) {
            return $po;
        }

        $idx = self::find_row_index($po['payload']['current'], $sku);
        if ($idx === null) {
            return new WP_Error('unknown_sku', __('Unknown SKU for this purchase order.', 'meals-db'));
        }

        $row = $po['payload']['current'][$idx];
        $old = (int) ($row['cases'] ?? 0);
        if ($old === $cases) {
            return [
                'changed'        => false,
                'cases'          => $old,
                'order_quantity' => (int) ($row['order_quantity'] ?? 0),
                'coverage_weeks' => self::coverage_weeks($row),
            ];
        }

        $po['payload']['current'][$idx]['cases']          = $cases;
        $po['payload']['current'][$idx]['order_quantity'] = $cases * max(1, (int) ($row['case_size'] ?? 1));

        if (!$this->write_payload($po_id, $po['payload'], self::STATUS_PLANNED, (int) $po['edit_count'] + 1)) {
            return new WP_Error('save_failed',
                __('Could not save the change (the draft may have just been approved) — reload.', 'meals-db'));
        }
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('po_draft_edit', $po_id, $sku, (string) $old, (string) $cases);
        }

        $updated = $po['payload']['current'][$idx];
        return [
            'changed'        => true,
            'cases'          => $cases,
            'order_quantity' => (int) $updated['order_quantity'],
            'coverage_weeks' => self::coverage_weeks($updated),
        ];
    }

    /**
     * Load a PO and require it to be a workflow PO (payload present) in the
     * expected status. Returns the hydrated PO array or a WP_Error.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function require_workflow_po(int $po_id, string $expected_status, string $locked_message) {
        $po = $this->get_with_payload($po_id);
        if ($po === null) {
            return new WP_Error('not_found', __('Purchase order not found.', 'meals-db'));
        }
        if (!is_array($po['payload'])) {
            return new WP_Error('legacy',
                __('This purchase order was created by the task workflow and cannot be modified here.', 'meals-db'));
        }
        if ((string) ($po['status'] ?? '') !== $expected_status) {
            return new WP_Error('locked', $locked_message);
        }
        return $po;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function find_row_index(array $rows, string $sku): ?int {
        foreach ($rows as $i => $row) {
            if ((string) ($row['sku'] ?? '') === $sku) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Persist the payload under the same status guard the transitions use, so
     * an edit racing an approve loses cleanly (0 rows) instead of mutating a
     * locked PO. edit_count is written as a value (not col+1): a lost
     * increment between two same-second edits is acceptable for an
     * informational counter.
     */
    private function write_payload(int $po_id, array $payload, string $expected_status, int $edit_count): bool {
        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::PURCHASE_ORDERS);
        $result = $this->wpdb->update(
            $table,
            [
                'payload'    => MealsDB_Task_Engine::encode_json($payload),
                'edit_count' => $edit_count,
            ],
            ['po_id' => $po_id, 'status' => $expected_status]
        );
        return $result === 1;
    }
```

- [ ] **Step 3.4: Run tests, verify pass**

Run: `php tests/test-po-draft-lifecycle.php`
Expected: all pass, exit 0.

- [ ] **Step 3.5: Commit**

```bash
php -l includes/services/class-purchase-orders.php
git add includes/services/class-purchase-orders.php tests/test-po-draft-lifecycle.php
git commit -m "feat(po): per-row case edits on draft POs with race-guarded payload writes

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Service — approve / unapprove / cancel_draft

**Files:**
- Modify: `includes/services/class-purchase-orders.php`
- Test: `tests/test-po-draft-lifecycle.php` (append)

- [ ] **Step 4.1: Append failing tests**

Append before the summary block:

```php
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
```

- [ ] **Step 4.2: Run to verify failure**

Run: `php tests/test-po-draft-lifecycle.php`
Expected: fatal `Call to undefined method ...::approve()`.

- [ ] **Step 4.3: Implement approve, unapprove, cancel_draft, transition helper**

Add to the service class:

```php
    // -----------------------------------------------------------------
    // Draft workflow — lifecycle transitions
    // -----------------------------------------------------------------

    /**
     * Draft → Approved. Locks the PO (invoice-finalize semantics) and writes
     * the definitive `items` JSON (UNITS = cases × case_size) from the current
     * payload — the contract every existing items consumer reads, including
     * apply_inventory_bump and apply_adjustments. Zero-case rows are deliberate
     * "don't order" decisions and are omitted.
     *
     * @return true|WP_Error
     */
    public function approve(int $po_id) {
        if (class_exists('MealsDB_Permissions') && !MealsDB_Permissions::can_access_plugin()) {
            return new WP_Error('forbidden', __('Insufficient permissions.', 'meals-db'));
        }
        $po = $this->require_workflow_po($po_id, self::STATUS_PLANNED,
            __('Only draft purchase orders can be approved.', 'meals-db'));
        if (is_wp_error($po)) {
            return $po;
        }

        $items = [];
        foreach ($po['payload']['current'] as $row) {
            $cases = (int) ($row['cases'] ?? 0);
            if ($cases <= 0) {
                continue;
            }
            $items[] = [
                'sku'              => (string) $row['sku'],
                'product_name'     => (string) ($row['product_name'] ?? ''),
                'quantity_ordered' => $cases * max(1, (int) ($row['case_size'] ?? 1)),
            ];
        }
        if (empty($items)) {
            return new WP_Error('empty', __('Every row is zero cases — nothing to approve.', 'meals-db'));
        }

        $ok = $this->transition($po_id, self::STATUS_PLANNED, self::STATUS_PLACED, [
            'approved_by' => get_current_user_id() ?: null,
            'approved_at' => gmdate('Y-m-d H:i:s'),
            'placed_date' => gmdate('Y-m-d'),
            'items'       => MealsDB_Task_Engine::encode_json($items),
        ]);
        if (!$ok) {
            return new WP_Error('race', __('Could not approve (a concurrent change happened) — reload.', 'meals-db'));
        }
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('po_approved', $po_id, 'status', self::STATUS_PLANNED, self::STATUS_PLACED);
        }
        return true;
    }

    /**
     * Approved → Draft, reason required and audited (mirrors invoice
     * un-finalize). Only available BEFORE receiving — once stock is bumped
     * the state machine is one-way; corrections belong to reconcile.
     *
     * @return true|WP_Error
     */
    public function unapprove(int $po_id, string $reason) {
        if (class_exists('MealsDB_Permissions') && !MealsDB_Permissions::can_access_plugin()) {
            return new WP_Error('forbidden', __('Insufficient permissions.', 'meals-db'));
        }
        $reason = trim($reason);
        if ($reason === '') {
            return new WP_Error('reason_required', __('A reason is required to un-approve (it is audited).', 'meals-db'));
        }
        $po = $this->require_workflow_po($po_id, self::STATUS_PLACED,
            __('Only approved (not yet received) purchase orders can be un-approved.', 'meals-db'));
        if (is_wp_error($po)) {
            return $po;
        }

        // items is left as-written; the next approve overwrites it.
        $ok = $this->transition($po_id, self::STATUS_PLACED, self::STATUS_PLANNED, [
            'approved_by' => null,
            'approved_at' => null,
            'placed_date' => null,
        ]);
        if (!$ok) {
            return new WP_Error('race', __('Could not un-approve (a concurrent change happened) — reload.', 'meals-db'));
        }
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('po_unapproved', $po_id, 'reason', null,
                substr($reason, 0, self::MAX_NOTE_LEN));
        }
        return true;
    }

    /**
     * Draft → Cancelled. Keeps abandoned drafts out of the working list
     * without deleting the record (the audit trail stays coherent).
     *
     * @return true|WP_Error
     */
    public function cancel_draft(int $po_id) {
        if (class_exists('MealsDB_Permissions') && !MealsDB_Permissions::can_access_plugin()) {
            return new WP_Error('forbidden', __('Insufficient permissions.', 'meals-db'));
        }
        $po = $this->require_workflow_po($po_id, self::STATUS_PLANNED,
            __('Only draft purchase orders can be cancelled.', 'meals-db'));
        if (is_wp_error($po)) {
            return $po;
        }
        $ok = $this->transition($po_id, self::STATUS_PLANNED, self::STATUS_CANCELLED);
        if (!$ok) {
            return new WP_Error('race', __('Could not cancel (a concurrent change happened) — reload.', 'meals-db'));
        }
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('po_draft_cancelled', $po_id, 'status', self::STATUS_PLANNED, self::STATUS_CANCELLED);
        }
        return true;
    }

    /**
     * Guarded status transition: the WHERE clause carries the expected FROM
     * status, so of two concurrent requests exactly one matches a row. The
     * loser sees 0 affected rows and must NOT apply side-effects.
     *
     * @param array<string, mixed> $extra additional columns to set
     */
    private function transition(int $po_id, string $from, string $to, array $extra = []): bool {
        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::PURCHASE_ORDERS);
        $result = $this->wpdb->update(
            $table,
            array_merge(['status' => $to], $extra),
            ['po_id' => $po_id, 'status' => $from]
        );
        return $result === 1;
    }
```

- [ ] **Step 4.4: Run tests, verify pass**

Run: `php tests/test-po-draft-lifecycle.php`
Expected: all pass, exit 0.

- [ ] **Step 4.5: Commit**

```bash
php -l includes/services/class-purchase-orders.php
git add includes/services/class-purchase-orders.php tests/test-po-draft-lifecycle.php
git commit -m "feat(po): approve/unapprove/cancel transitions with guarded updates

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Service — mark_received (stock bump)

**Files:**
- Modify: `includes/services/class-purchase-orders.php`
- Test: `tests/test-po-draft-lifecycle.php` (append)

- [ ] **Step 5.1: Append failing tests**

```php
// ===========================================================================
// T-10: mark_received — placed→arrived, stock bumped exactly once.
// ===========================================================================
$w = fresh();
$GLOBALS['wc_stock'] = [101 => 50, 102 => 20];
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
chk($svc->mark_received($id)->get_error_code(), 'locked', 'T-10: receive before approve rejected');
$svc->approve($id);
$r = $svc->mark_received($id);
chk($r, true, 'T-10: mark_received succeeds');
$po = $svc->get_with_payload($id);
chk($po['status'], 'arrived', 'T-10: status → arrived (Received)');
chk_true(!empty($po['received_at']), 'T-10: received_at set');
chk_true(!empty($po['arrival_date']), 'T-10: arrival_date set');
// CD-001: 10 cases × 6 = 60 units onto 50; SD-002: 2 cases × 12 = 24 onto 20.
chk($GLOBALS['wc_stock'][101], 110, 'T-10: CD-001 stock bumped by ordered units');
chk($GLOBALS['wc_stock'][102], 44, 'T-10: SD-002 stock bumped by ordered units');
chk_true(audit_has($w, 'po_received'), 'T-10: po_received audited');
// Second click: guard loses, NO second bump.
chk($svc->mark_received($id)->get_error_code(), 'locked', 'T-10: double receive rejected');
chk($GLOBALS['wc_stock'][101], 110, 'T-10: no double bump');
```

- [ ] **Step 5.2: Run to verify failure**

Run: `php tests/test-po-draft-lifecycle.php` — Expected: fatal, `mark_received` undefined.

- [ ] **Step 5.3: Implement mark_received**

```php
    /**
     * Approved → Received. The guarded transition runs FIRST so a double-click
     * can't apply the inventory bump twice (the loser's UPDATE matches 0
     * rows and returns before any stock write). The bump itself delegates to
     * the existing task-type static — one inventory-bump implementation in the
     * plugin, and calling it does not create or touch any task.
     *
     * @return true|WP_Error
     */
    public function mark_received(int $po_id) {
        if (class_exists('MealsDB_Permissions') && !MealsDB_Permissions::can_access_plugin()) {
            return new WP_Error('forbidden', __('Insufficient permissions.', 'meals-db'));
        }
        $po = $this->require_workflow_po($po_id, self::STATUS_PLACED,
            __('Only approved purchase orders can be marked received.', 'meals-db'));
        if (is_wp_error($po)) {
            return $po;
        }

        $ok = $this->transition($po_id, self::STATUS_PLACED, self::STATUS_ARRIVED, [
            'received_by'  => get_current_user_id() ?: null,
            'received_at'  => gmdate('Y-m-d H:i:s'),
            'arrival_date' => gmdate('Y-m-d'),
        ]);
        if (!$ok) {
            return new WP_Error('race', __('Could not mark received (a concurrent change happened) — reload.', 'meals-db'));
        }

        if (class_exists('MealsDB_Task_Type_Confirm_PO_Arrival')) {
            MealsDB_Task_Type_Confirm_PO_Arrival::apply_inventory_bump((array) $po['items']);
        }
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('po_received', $po_id, 'status', self::STATUS_PLACED, self::STATUS_ARRIVED);
        }
        return true;
    }
```

- [ ] **Step 5.4: Run tests, verify pass** — `php tests/test-po-draft-lifecycle.php`, all pass.

- [ ] **Step 5.5: Commit**

```bash
php -l includes/services/class-purchase-orders.php
git add includes/services/class-purchase-orders.php tests/test-po-draft-lifecycle.php
git commit -m "feat(po): mark-received transition with exactly-once inventory bump

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Service — reconcile (edit_reconcile_row + complete_reconcile)

**Files:**
- Modify: `includes/services/class-purchase-orders.php`
- Test: `tests/test-po-reconcile-deltas.php` (create)

- [ ] **Step 6.1: Write the failing test file**

Create `tests/test-po-reconcile-deltas.php`. Reuse the EXACT same bootstrap as `tests/test-po-draft-lifecycle.php` (everything from the opening `<?php` down to and including the `forecast_rows()` function — copy it verbatim; these are standalone scripts, duplication is the suite's convention). Then:

```php
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
```

- [ ] **Step 6.2: Run to verify failure**

Run: `php tests/test-po-reconcile-deltas.php` — Expected: fatal, `edit_reconcile_row` undefined.

- [ ] **Step 6.3: Implement the two reconcile methods**

```php
    // -----------------------------------------------------------------
    // Draft workflow — reconciliation
    // -----------------------------------------------------------------

    /**
     * Record one row of a reconcile-in-progress session: the actually-received
     * case count (+/- buttons) and its note. Persisted in payload.received so
     * a half-done session survives navigation. NO stock effect here — deltas
     * are applied exactly once, by complete_reconcile().
     *
     * The note-required rule is enforced at COMPLETION (a row is often
     * adjusted before its note is typed); this method only caps length.
     *
     * @return array{received_cases: int, ordered_cases: int, coverage_weeks: float|null}|WP_Error
     */
    public function edit_reconcile_row(int $po_id, string $sku, int $received_cases, string $note) {
        if (class_exists('MealsDB_Permissions') && !MealsDB_Permissions::can_access_plugin()) {
            return new WP_Error('forbidden', __('Insufficient permissions.', 'meals-db'));
        }
        if ($received_cases < 0 || $received_cases > self::MAX_CASES) {
            return new WP_Error('bad_cases', __('Case count is out of the allowed range.', 'meals-db'));
        }
        $note = function_exists('sanitize_text_field') ? sanitize_text_field($note) : trim($note);
        if (strlen($note) > self::MAX_NOTE_LEN) {
            return new WP_Error('note_too_long', __('Note is too long (500 characters max).', 'meals-db'));
        }

        $po = $this->require_workflow_po($po_id, self::STATUS_ARRIVED,
            __('Only received purchase orders can be reconciled.', 'meals-db'));
        if (is_wp_error($po)) {
            return $po;
        }

        // Only ORDERED rows (cases > 0 at approval) are reconcilable.
        $ordered = null;
        foreach ($po['payload']['current'] as $row) {
            if ((string) ($row['sku'] ?? '') === $sku && (int) ($row['cases'] ?? 0) > 0) {
                $ordered = $row;
                break;
            }
        }
        if ($ordered === null) {
            return new WP_Error('unknown_sku', __('That SKU is not on this purchase order.', 'meals-db'));
        }

        $received = is_array($po['payload']['received'] ?? null) ? $po['payload']['received'] : [];
        $old = isset($received[$sku]['received_cases'])
            ? (int) $received[$sku]['received_cases']
            : (int) $ordered['cases'];
        $received[$sku] = ['received_cases' => $received_cases, 'note' => $note];
        $po['payload']['received'] = $received;

        if (!$this->write_payload($po_id, $po['payload'], self::STATUS_ARRIVED, (int) $po['edit_count'] + 1)) {
            return new WP_Error('save_failed',
                __('Could not save the change (the reconciliation may have just completed) — reload.', 'meals-db'));
        }
        if (class_exists('MealsDB_Logger') && $old !== $received_cases) {
            MealsDB_Logger::log('po_reconcile_edit', $po_id, $sku, (string) $old, (string) $received_cases);
        }
        return [
            'received_cases' => $received_cases,
            'ordered_cases'  => (int) $ordered['cases'],
            'coverage_weeks' => self::coverage_weeks($ordered, $received_cases),
        ];
    }

    /**
     * Received → Reconciled. Validates that every adjusted row carries a note
     * ("hit − twice, comment 'Two cases damaged in transit'"), then flips the
     * status under guard and applies the stock deltas via the existing
     * physical-count static (server-sourced ordered quantities; per-SKU
     * inventory_discrepancy audit rows that carry the note). Transition-first
     * means a concurrent double-complete applies the deltas exactly once.
     *
     * Untouched rows and rows set back to the ordered count are received-as-
     * ordered: no delta, no note required.
     *
     * @return true|WP_Error
     */
    public function complete_reconcile(int $po_id) {
        if (class_exists('MealsDB_Permissions') && !MealsDB_Permissions::can_access_plugin()) {
            return new WP_Error('forbidden', __('Insufficient permissions.', 'meals-db'));
        }
        $po = $this->require_workflow_po($po_id, self::STATUS_ARRIVED,
            __('Only received purchase orders can be reconciled.', 'meals-db'));
        if (is_wp_error($po)) {
            return $po;
        }

        $received = is_array($po['payload']['received'] ?? null) ? $po['payload']['received'] : [];
        $missing = [];
        $adjustments = [];
        foreach ($po['payload']['current'] as $row) {
            $cases = (int) ($row['cases'] ?? 0);
            if ($cases <= 0) {
                continue;
            }
            $sku = (string) $row['sku'];
            if (!isset($received[$sku])) {
                continue; // untouched = received as ordered
            }
            $rc = (int) $received[$sku]['received_cases'];
            if ($rc === $cases) {
                continue; // explicitly confirmed as ordered
            }
            $note = trim((string) ($received[$sku]['note'] ?? ''));
            if ($note === '') {
                $missing[] = $sku;
                continue;
            }
            $adjustments[] = [
                'sku'          => $sku,
                'actual_count' => $rc * max(1, (int) ($row['case_size'] ?? 1)),
                'reason'       => 'po_reconcile',
                'reason_notes' => $note,
            ];
        }
        if (!empty($missing)) {
            return new WP_Error(
                'notes_required',
                sprintf(
                    /* translators: %s: comma-separated SKU list */
                    __('A note is required for every adjusted row. Missing: %s', 'meals-db'),
                    implode(', ', $missing)
                ),
                ['skus' => $missing]
            );
        }

        $ok = $this->transition($po_id, self::STATUS_ARRIVED, self::STATUS_RECONCILED, [
            'reconciled_by' => get_current_user_id() ?: null,
            'reconciled_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            return new WP_Error('race', __('Could not complete (a concurrent change happened) — reload.', 'meals-db'));
        }

        if (!empty($adjustments) && class_exists('MealsDB_Task_Type_Physical_Count')) {
            MealsDB_Task_Type_Physical_Count::apply_adjustments($po_id, $adjustments);
        }
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('po_reconciled', $po_id, 'status', self::STATUS_ARRIVED, self::STATUS_RECONCILED);
        }
        return true;
    }
```

- [ ] **Step 6.4: Run BOTH test files, verify pass**

```bash
php tests/test-po-draft-lifecycle.php && php tests/test-po-reconcile-deltas.php
```
Expected: both exit 0.

- [ ] **Step 6.5: Commit**

```bash
php -l includes/services/class-purchase-orders.php
git add includes/services/class-purchase-orders.php tests/test-po-reconcile-deltas.php
git commit -m "feat(po): reconcile session with required notes and exactly-once stock deltas

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: AJAX endpoint class

**Files:**
- Create: `includes/ajax/class-ajax-purchase-orders.php`
- Modify: `meals-db-main.php` (register init after `MealsDB_Ajax_Reports::init();`)

No standalone test (the logic lives in the tested service; the guard spine is a byte-for-byte sibling of the audited `class-ajax-invoice-draft.php`). Verification is lint + a grep that every endpoint carries the guard.

- [ ] **Step 7.1: Create the AJAX class**

```php
<?php
/**
 * AJAX handlers for the Purchase Order draft workflow (spec 2026-07-10).
 *
 * Eight endpoints, each carrying the plugin guard spine in order:
 *   1. nonce       (check_ajax_referer, fail-closed; one context for the
 *                   family, like the invoice-draft endpoints)
 *   2. capability  (BASELINE plugin capability, NOT manage_options: PO rows
 *                   are SKUs and case counts — no client PII, no billing —
 *                   matching the rest of the purchasing area. Deliberate,
 *                   operator-approved divergence from invoice drafts.)
 *   3. rate limit  (mutating buckets, fail-closed)
 *   4. validate    (server-side; SKUs are checked against the stored payload
 *                   in the service — never trusted from the form)
 *   5. act + JSON  (outer catch(\Throwable) — never a bare 500)
 *
 * Committed changes (draft created/edited/approved/received/reconciled) are
 * audited in the SERVICE layer — do not double-log here (STR-LOG boundary).
 *
 * The task system is deliberately untouched: approving a PO spawns no task,
 * and the two inventory statics the service reuses do not create tasks.
 *
 * Author: Fishhorn Design
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Ajax_Purchase_Orders {

    /** One nonce context covers the whole PO-workflow family. */
    public const NONCE_ACTION = 'mealsdb_po_nonce';

    public static function init(): void {
        add_action('wp_ajax_mealsdb_po_save_draft',         [__CLASS__, 'save_draft']);
        add_action('wp_ajax_mealsdb_po_edit_cases',         [__CLASS__, 'edit_cases']);
        add_action('wp_ajax_mealsdb_po_reconcile_edit',     [__CLASS__, 'reconcile_edit']);
        add_action('wp_ajax_mealsdb_po_approve',            [__CLASS__, 'approve']);
        add_action('wp_ajax_mealsdb_po_unapprove',          [__CLASS__, 'unapprove']);
        add_action('wp_ajax_mealsdb_po_mark_received',      [__CLASS__, 'mark_received']);
        add_action('wp_ajax_mealsdb_po_complete_reconcile', [__CLASS__, 'complete_reconcile']);
        add_action('wp_ajax_mealsdb_po_cancel',             [__CLASS__, 'cancel']);
    }

    /**
     * Forecast tab "Save as draft PO". The rows are REGENERATED server-side
     * rather than accepted from the browser: the on-screen table is display
     * data, not a trusted payload. The operator saves "what the model says
     * right now" — identical to the preview unless stock/orders moved in the
     * seconds between Generate and Save.
     */
    public static function save_draft(): void {
        if (!self::guard('client_modify')) {
            return;
        }
        try {
            $reports = new MealsDB_Reports($GLOBALS['wpdb']);
            $rows    = $reports->generate_purchase_order();
            if (!empty($_POST['optimize'])) {
                $optimized = MealsDB_Reports::optimize_po_for_pallets($rows);
                $rows      = $optimized['rows'];
            }
            $service = new MealsDB_Purchase_Orders();
            $po_id   = $service->create_draft($rows);
            if ($po_id <= 0) {
                wp_send_json_error(['message' => __('Could not save the draft purchase order.', 'meals-db')]);
                return;
            }
            wp_send_json_success(['po_id' => $po_id]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB PO AJAX] save_draft failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save the draft. Please try again.', 'meals-db')]);
        }
    }

    /** Draft-mode +/- row save. */
    public static function edit_cases(): void {
        if (!self::guard('po_draft_edit')) {
            return;
        }
        try {
            $po_id = isset($_POST['po_id']) ? absint($_POST['po_id']) : 0;
            $sku   = sanitize_text_field(wp_unslash($_POST['sku'] ?? ''));
            $cases = self::read_int_param('cases');
            if ($po_id <= 0 || $sku === '' || $cases === null) {
                wp_send_json_error(['message' => __('Missing or malformed parameters.', 'meals-db')]);
                return;
            }
            $service = new MealsDB_Purchase_Orders();
            self::respond($service->edit_draft_cases($po_id, $sku, $cases));
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB PO AJAX] edit_cases failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save the change. Please try again.', 'meals-db')]);
        }
    }

    /** Reconcile-mode +/- and note row save. */
    public static function reconcile_edit(): void {
        if (!self::guard('po_draft_edit')) {
            return;
        }
        try {
            $po_id = isset($_POST['po_id']) ? absint($_POST['po_id']) : 0;
            $sku   = sanitize_text_field(wp_unslash($_POST['sku'] ?? ''));
            $cases = self::read_int_param('received_cases');
            // Raw here; the service sanitizes + length-caps.
            $note  = (string) wp_unslash($_POST['note'] ?? '');
            if ($po_id <= 0 || $sku === '' || $cases === null) {
                wp_send_json_error(['message' => __('Missing or malformed parameters.', 'meals-db')]);
                return;
            }
            $service = new MealsDB_Purchase_Orders();
            self::respond($service->edit_reconcile_row($po_id, $sku, $cases, $note));
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB PO AJAX] reconcile_edit failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save the change. Please try again.', 'meals-db')]);
        }
    }

    public static function approve(): void            { self::transition_endpoint('approve'); }
    public static function unapprove(): void          { self::transition_endpoint('unapprove'); }
    public static function mark_received(): void      { self::transition_endpoint('mark_received'); }
    public static function complete_reconcile(): void { self::transition_endpoint('complete_reconcile'); }
    public static function cancel(): void             { self::transition_endpoint('cancel_draft'); }

    // -----------------------------------------------------------------
    // Shared plumbing
    // -----------------------------------------------------------------

    /**
     * All five lifecycle transitions share one shape: settings_modify bucket
     * (destructive-ish, 20/hr), a po_id, and for unapprove a required reason.
     */
    private static function transition_endpoint(string $method): void {
        if (!self::guard('settings_modify')) {
            return;
        }
        try {
            $po_id = isset($_POST['po_id']) ? absint($_POST['po_id']) : 0;
            if ($po_id <= 0) {
                wp_send_json_error(['message' => __('Missing purchase order id.', 'meals-db')]);
                return;
            }
            $service = new MealsDB_Purchase_Orders();
            if ($method === 'unapprove') {
                $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));
                $result = $service->unapprove($po_id, $reason);
            } else {
                $result = $service->{$method}($po_id);
            }
            self::respond($result);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB PO AJAX] ' . $method . ' failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('The action failed. Please try again.', 'meals-db')]);
        }
    }

    /** Map a service result (true | array | WP_Error) onto the JSON contract. */
    private static function respond($result): void {
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
                'data'    => $result->get_error_data(),
            ]);
            return;
        }
        wp_send_json_success(is_array($result) ? $result : ['done' => true]);
    }

    /**
     * Read a whole-number POST param. Returns null on anything non-numeric or
     * fractional ('1e3'-style scientific input is normalized via round(), the
     * same rule the invoice-draft editor applies to counts).
     */
    private static function read_int_param(string $key): ?int {
        $raw = wp_unslash($_POST[$key] ?? '');
        if (!is_scalar($raw) || !is_numeric($raw) || (float) $raw != floor((float) $raw)) {
            return null;
        }
        return (int) round((float) $raw);
    }

    /**
     * Guard spine: nonce → capability → rate limit, each failing CLOSED with
     * a JSON error. Returns true only if all three pass.
     */
    private static function guard(string $rate_bucket): bool {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token.', 'meals-db')]);
            return false;
        }
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'meals-db')], 403);
            return false;
        }
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit($rate_bucket)) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
            return false;
        }
        return true;
    }
}
```

- [ ] **Step 7.2: Register it**

In `meals-db-main.php`, after `MealsDB_Ajax_Reports::init();` add:

```php
    MealsDB_Ajax_Purchase_Orders::init();
```

- [ ] **Step 7.3: Verify**

```bash
php -l includes/ajax/class-ajax-purchase-orders.php && php -l meals-db-main.php
grep -c "self::guard(" includes/ajax/class-ajax-purchase-orders.php
```
Expected: lint clean; grep prints `5` or more (guard referenced in every endpoint path: save_draft, edit_cases, reconcile_edit, transition_endpoint + definition).

- [ ] **Step 7.4: Commit**

```bash
git add includes/ajax/class-ajax-purchase-orders.php meals-db-main.php
git commit -m "feat(po): AJAX endpoints for PO draft workflow with full guard spine

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: List + detail view rewrite

**Files:**
- Rewrite: `views/purchase-orders.php`

The current file's legacy detail render (form-table + items + related-tasks) is KEPT as the read-only path for `payload IS NULL` POs and for reconciled/cancelled workflow POs' related-task listing. Replace the whole file with:

- [ ] **Step 8.1: Write the new view**

```php
<?php
/**
 * Purchase Orders tab — draft workflow list + detail (spec 2026-07-10).
 *
 * Lifecycle (status ENUM value → operator label):
 *   planned=Draft → placed=Approved → arrived=Received → reconciled,
 *   with cancelled available from Draft. Legacy task-created POs
 *   (payload IS NULL) render read-only; their lifecycle stays with the
 *   task chain (place_po → confirm_po_arrival → physical_count).
 *
 * Interactivity lives in assets/js/purchase-orders.js, fed by the JSON
 * island #mealsdb-po-admin-data (no inline script blocks).
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

$status_filter = isset($_GET['po_status']) ? sanitize_key(wp_unslash((string) $_GET['po_status'])) : '';
$po_id  = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
$action = isset($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';

$service  = new MealsDB_Purchase_Orders();
$base_url = admin_url('admin.php?page=mealsdb&tab=po_admin');

/** Render the shared JSON island + wrap-up for JS. */
$mealsdb_po_render_island = static function (array $extra = []) use ($base_url): void {
    $island = array_merge([
        'nonce'       => wp_create_nonce(MealsDB_Ajax_Purchase_Orders::NONCE_ACTION),
        'ajaxUrl'     => admin_url('admin-ajax.php'),
        'baseUrl'     => $base_url,
        'targetWeeks' => MealsDB_Purchase_Orders::COVERAGE_TARGET_WEEKS,
        'floorWeeks'  => MealsDB_Purchase_Orders::COVERAGE_FLOOR_WEEKS,
        'i18n'        => [
            'confirmApprove'   => __('Approve this purchase order? Approved orders are locked (un-approve requires an audited reason).', 'meals-db'),
            'confirmReceive'   => __('Mark this purchase order as received? Ordered quantities will be ADDED to inventory.', 'meals-db'),
            'confirmCancel'    => __('Cancel this draft purchase order?', 'meals-db'),
            'confirmComplete'  => __('Complete reconciliation? Stock will be corrected for every adjusted row and the purchase order will be locked.', 'meals-db'),
            'promptUnapprove'  => __('Enter a reason for un-approving (required — it is audited):', 'meals-db'),
            'reasonRequired'   => __('A reason is required.', 'meals-db'),
            'noteRequired'     => __('A note is required for adjusted rows.', 'meals-db'),
            'requestFailed'    => __('Request failed.', 'meals-db'),
            'saving'           => __('Saving…', 'meals-db'),
            'was'              => __('was: %s', 'meals-db'),
            'belowTarget'      => __('Below 9-week coverage target (%s wks)', 'meals-db'),
            'belowFloor'       => __('Below 7-week safety floor (%s wks)', 'meals-db'),
        ],
    ], $extra);
    echo '<script type="application/json" id="mealsdb-po-admin-data">'
        . wp_json_encode($island, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '</script>';
};

/** Coverage cell HTML: number + warning badge. Shared by draft + reconcile renders. */
$mealsdb_po_coverage_cell = static function (?float $weeks): string {
    if ($weeks === null) {
        return '<td class="mealsdb-po-coverage" data-coverage="">&mdash;</td>';
    }
    $badge = '';
    if ($weeks < MealsDB_Purchase_Orders::COVERAGE_FLOOR_WEEKS) {
        $badge = '<span class="mealsdb-po-flag mealsdb-po-crit" title="'
            . esc_attr(sprintf(__('Below 7-week safety floor (%s wks)', 'meals-db'), number_format_i18n($weeks, 1)))
            . '">!</span>';
    } elseif ($weeks < MealsDB_Purchase_Orders::COVERAGE_TARGET_WEEKS) {
        $badge = '<span class="mealsdb-po-flag mealsdb-po-warn" title="'
            . esc_attr(sprintf(__('Below 9-week coverage target (%s wks)', 'meals-db'), number_format_i18n($weeks, 1)))
            . '">!</span>';
    }
    return '<td class="mealsdb-po-coverage" data-coverage="' . esc_attr((string) $weeks) . '">'
        . esc_html(number_format_i18n($weeks, 1)) . ' ' . $badge . '</td>';
};

// ===========================================================================
// Detail view
// ===========================================================================
if ($po_id > 0) {
    $po = $service->get_with_payload($po_id);
    if ($po === null) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Purchase order not found.', 'meals-db') . '</p></div>';
        echo '<p><a class="button" href="' . esc_url($base_url) . '">&larr; ' . esc_html__('Back to list', 'meals-db') . '</a></p>';
        return;
    }

    $is_workflow = is_array($po['payload']);
    $status      = (string) $po['status'];
    $mode        = 'locked';
    if ($is_workflow && $status === MealsDB_Purchase_Orders::STATUS_PLANNED) {
        $mode = 'draft';
    } elseif ($is_workflow && $status === MealsDB_Purchase_Orders::STATUS_ARRIVED && $action === 'reconcile') {
        $mode = 'reconcile';
    }

    $engine        = class_exists('MealsDB_Task_Engine') ? new MealsDB_Task_Engine() : null;
    $related_tasks = $engine ? $engine->query_tasks([
        'related_entity_type' => 'po',
        'related_entity_id'   => $po_id,
        'status'              => ['pending', 'in_progress', 'deferred', 'completed', 'skipped', 'abandoned'],
    ]) : [];
    ?>
    <div id="mealsdb-po-detail" class="mealsdb-po-detail" data-mode="<?php echo esc_attr($mode); ?>" data-po-id="<?php echo (int) $po_id; ?>">
        <p><a class="button" href="<?php echo esc_url($base_url); ?>">&larr; <?php esc_html_e('Back to list', 'meals-db'); ?></a></p>
        <h2>
            <?php echo esc_html(sprintf(__('Purchase Order %s', 'meals-db'), $po['po_number'])); ?>
            <span class="mealsdb-po-status mealsdb-po-status-<?php echo esc_attr($status); ?>">
                <?php echo esc_html(MealsDB_Purchase_Orders::status_label($status)); ?>
            </span>
        </h2>

        <?php if ($is_workflow && $mode === 'locked' && $status === MealsDB_Purchase_Orders::STATUS_PLACED): ?>
            <div class="notice notice-info"><p><?php esc_html_e('This purchase order is approved and is shown read-only. Un-approve it to make changes.', 'meals-db'); ?></p></div>
        <?php elseif ($is_workflow && $mode === 'locked' && $status === MealsDB_Purchase_Orders::STATUS_ARRIVED): ?>
            <div class="notice notice-info"><p><?php esc_html_e('This purchase order has been received. Open Reconcile to record what actually arrived.', 'meals-db'); ?></p></div>
        <?php elseif ($mode === 'reconcile'): ?>
            <div class="notice notice-warning"><p><?php esc_html_e('Reconcile mode: adjust the received case counts with the +/− buttons. Any adjusted row requires a note (e.g. "Two cases damaged in transit"). Stock is corrected only when you complete the reconciliation.', 'meals-db'); ?></p></div>
        <?php elseif (!$is_workflow): ?>
            <div class="notice notice-info"><p><?php esc_html_e('This purchase order was created by the task workflow and is shown read-only here.', 'meals-db'); ?></p></div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr><th><?php esc_html_e('Supplier', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['supplier'] ?? '')); ?></td></tr>
                <tr><th><?php esc_html_e('Placed Date', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['placed_date'] ?? '—')); ?></td></tr>
                <tr><th><?php esc_html_e('Arrival', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['arrival_date'] ?? '—')); ?></td></tr>
                <tr><th><?php esc_html_e('Reconciled', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['reconciled_at'] ?? '—')); ?></td></tr>
                <?php if ($is_workflow): ?>
                <tr><th><?php esc_html_e('Edits', 'meals-db'); ?></th>
                    <td id="mealsdb-po-edit-count"><?php echo (int) ($po['edit_count'] ?? 0); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($is_workflow): ?>
            <p class="mealsdb-po-detail-actions">
                <?php if ($status === MealsDB_Purchase_Orders::STATUS_PLANNED): ?>
                    <button type="button" class="button button-primary mealsdb-po-action" data-po-action="approve" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Approve', 'meals-db'); ?></button>
                    <button type="button" class="button mealsdb-po-action" data-po-action="cancel" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Cancel draft', 'meals-db'); ?></button>
                <?php elseif ($status === MealsDB_Purchase_Orders::STATUS_PLACED): ?>
                    <button type="button" class="button button-primary mealsdb-po-action" data-po-action="receive" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Mark received', 'meals-db'); ?></button>
                    <button type="button" class="button mealsdb-po-action" data-po-action="unapprove" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Un-approve', 'meals-db'); ?></button>
                <?php elseif ($status === MealsDB_Purchase_Orders::STATUS_ARRIVED && $mode !== 'reconcile'): ?>
                    <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['po_id' => $po_id, 'action' => 'reconcile'], $base_url)); ?>"><?php esc_html_e('Reconcile', 'meals-db'); ?></a>
                <?php elseif ($mode === 'reconcile'): ?>
                    <button type="button" class="button button-primary" id="mealsdb-po-complete-reconcile" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Complete reconciliation', 'meals-db'); ?></button>
                <?php endif; ?>
                <span id="mealsdb-po-action-msg" role="status"></span>
            </p>
        <?php endif; ?>

        <h3><?php esc_html_e('Items', 'meals-db'); ?></h3>
        <?php if (!$is_workflow): ?>
            <?php if (empty($po['items'])): ?>
                <p><em><?php esc_html_e('No items on this PO.', 'meals-db'); ?></em></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th><?php esc_html_e('SKU', 'meals-db'); ?></th>
                        <th><?php esc_html_e('Product', 'meals-db'); ?></th>
                        <th><?php esc_html_e('Qty Ordered', 'meals-db'); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($po['items'] as $item): ?>
                            <tr>
                                <td><?php echo esc_html((string) ($item['sku'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($item['product_name'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($item['quantity_ordered'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php else: ?>
            <?php
            $rows     = $po['payload']['current'];
            $received = is_array($po['payload']['received'] ?? null) ? $po['payload']['received'] : [];
            $generated_by_sku = [];
            foreach ($po['payload']['generated'] as $g) {
                $generated_by_sku[(string) $g['sku']] = (int) $g['cases'];
            }
            $total_cases = 0;
            $total_units = 0;
            ?>
            <table class="widefat striped mealsdb-po-grid" id="mealsdb-po-grid">
                <thead><tr>
                    <th><?php esc_html_e('SKU', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Product', 'meals-db'); ?></th>
                    <th class="num"><?php esc_html_e('Adj/Wk', 'meals-db'); ?></th>
                    <th class="num"><?php esc_html_e('Stock', 'meals-db'); ?></th>
                    <th class="num"><?php esc_html_e('Case size', 'meals-db'); ?></th>
                    <th class="num"><?php echo $mode === 'reconcile' ? esc_html__('Ordered', 'meals-db') : esc_html__('Cases', 'meals-db'); ?></th>
                    <?php if ($mode === 'reconcile'): ?>
                        <th class="num"><?php esc_html_e('Received', 'meals-db'); ?></th>
                        <th><?php esc_html_e('Note (required if adjusted)', 'meals-db'); ?></th>
                    <?php endif; ?>
                    <th class="num"><?php esc_html_e('Order qty', 'meals-db'); ?></th>
                    <th class="num"><?php esc_html_e('Coverage (wks)', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Forecast note', 'meals-db'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $sku        = (string) $row['sku'];
                    $cases      = (int) $row['cases'];
                    if ($mode === 'reconcile' && $cases <= 0) {
                        continue; // zero-case rows were never ordered
                    }
                    $rc         = isset($received[$sku]['received_cases']) ? (int) $received[$sku]['received_cases'] : $cases;
                    $note       = isset($received[$sku]['note']) ? (string) $received[$sku]['note'] : '';
                    $shown      = $mode === 'reconcile' ? $rc : $cases;
                    $total_cases += $shown;
                    $total_units += $shown * (int) $row['case_size'];
                    $gen        = $generated_by_sku[$sku] ?? $cases;
                    ?>
                    <tr data-sku="<?php echo esc_attr($sku); ?>"
                        data-case-size="<?php echo (int) $row['case_size']; ?>"
                        data-adjusted-weekly="<?php echo esc_attr((string) $row['adjusted_weekly']); ?>"
                        data-stock="<?php echo (int) $row['current_stock']; ?>"
                        data-generated-cases="<?php echo (int) $gen; ?>"
                        data-ordered-cases="<?php echo (int) $cases; ?>">
                        <td><?php echo esc_html($sku); ?></td>
                        <td><?php echo esc_html((string) $row['product_name']); ?></td>
                        <td class="num"><?php echo esc_html(number_format_i18n((float) $row['adjusted_weekly'], 2)); ?></td>
                        <td class="num"><?php echo (int) $row['current_stock']; ?></td>
                        <td class="num"><?php echo (int) $row['case_size']; ?></td>
                        <td class="num mealsdb-po-ordered">
                            <?php if ($mode === 'draft'): ?>
                                <span class="mealsdb-po-stepper">
                                    <button type="button" class="button mealsdb-po-step" data-step="-1" aria-label="<?php esc_attr_e('One case fewer', 'meals-db'); ?>">&minus;</button>
                                    <span class="mealsdb-po-cases" data-cases="<?php echo (int) $cases; ?>"><?php echo (int) $cases; ?></span>
                                    <button type="button" class="button mealsdb-po-step" data-step="1" aria-label="<?php esc_attr_e('One case more', 'meals-db'); ?>">+</button>
                                </span>
                                <?php if ($cases !== $gen): ?>
                                    <div class="mealsdb-po-was"><?php echo esc_html(sprintf(__('was: %s', 'meals-db'), $gen)); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php echo (int) $cases; ?>
                            <?php endif; ?>
                        </td>
                        <?php if ($mode === 'reconcile'): ?>
                            <td class="num">
                                <span class="mealsdb-po-stepper">
                                    <button type="button" class="button mealsdb-po-step" data-step="-1" aria-label="<?php esc_attr_e('One case fewer', 'meals-db'); ?>">&minus;</button>
                                    <span class="mealsdb-po-cases" data-cases="<?php echo (int) $rc; ?>"><?php echo (int) $rc; ?></span>
                                    <button type="button" class="button mealsdb-po-step" data-step="1" aria-label="<?php esc_attr_e('One case more', 'meals-db'); ?>">+</button>
                                </span>
                            </td>
                            <td>
                                <input type="text" class="mealsdb-po-note regular-text" maxlength="500"
                                    value="<?php echo esc_attr($note); ?>"
                                    placeholder="<?php esc_attr_e('Why does this differ?', 'meals-db'); ?>"
                                    <?php echo $rc === $cases ? 'style="display:none;"' : ''; ?> />
                            </td>
                        <?php endif; ?>
                        <td class="num mealsdb-po-orderqty"><?php echo (int) ($shown * (int) $row['case_size']); ?></td>
                        <?php echo $mealsdb_po_coverage_cell(MealsDB_Purchase_Orders::coverage_weeks($row, $shown)); // phpcs:ignore WordPress.Security.EscapeOutput -- helper escapes internally ?>
                        <td><?php echo esc_html((string) $row['seasonal_note']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <th colspan="5"><?php esc_html_e('TOTAL', 'meals-db'); ?></th>
                    <th class="num" id="mealsdb-po-total-cases"><?php echo (int) $total_cases; ?></th>
                    <?php if ($mode === 'reconcile'): ?><th></th><th></th><?php endif; ?>
                    <th class="num" id="mealsdb-po-total-units"><?php echo (int) $total_units; ?></th>
                    <th></th><th></th>
                </tr></tfoot>
            </table>
        <?php endif; ?>

        <?php if (!empty($related_tasks)): ?>
            <h3><?php esc_html_e('Related Tasks', 'meals-db'); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th><?php esc_html_e('Type', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Status', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Due', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Open', 'meals-db'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($related_tasks as $task): ?>
                        <?php
                        $def = MealsDB_Task_Registry::get($task['task_type']);
                        $label = $def['label'] ?? $task['task_type'];
                        $detail_url = admin_url('admin.php?page=mealsdb&tab=tasks&action=detail&task_id=' . (int) $task['task_id']);
                        ?>
                        <tr>
                            <td><?php echo esc_html($label); ?></td>
                            <td><code><?php echo esc_html($task['status']); ?></code></td>
                            <td><?php echo esc_html($task['next_run_date']); ?></td>
                            <td><a class="button button-small" href="<?php echo esc_url($detail_url); ?>"><?php esc_html_e('Open', 'meals-db'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($po['notes'])): ?>
            <h3><?php esc_html_e('Notes', 'meals-db'); ?></h3>
            <pre style="background:#f7f7f7;padding:12px;border:1px solid #ddd;"><?php echo esc_html((string) $po['notes']); ?></pre>
        <?php endif; ?>
    </div>
    <?php
    $mealsdb_po_render_island(['poId' => $po_id, 'mode' => $mode]);
    return;
}

// ===========================================================================
// List view
// ===========================================================================
$filters = [];
if ($status_filter !== '') {
    $filters['status'] = [$status_filter];
}
$rows = $service->query($filters);
?>
<div id="mealsdb-po-list" class="mealsdb-po-list">
    <h2><?php esc_html_e('Purchase Orders', 'meals-db'); ?></h2>
    <p class="description"><?php esc_html_e('Drafts are created from the Purchase Order forecast tab ("Save as draft PO"). Approve locks a draft; Mark received adds it to inventory; Reconcile records what actually arrived.', 'meals-db'); ?></p>

    <div style="margin-bottom:12px;">
        <label><strong><?php esc_html_e('Status:', 'meals-db'); ?></strong></label>
        <select onchange="window.location.href=this.value">
            <option value="<?php echo esc_url($base_url); ?>"><?php esc_html_e('All', 'meals-db'); ?></option>
            <?php foreach (MealsDB_Purchase_Orders::ALLOWED_STATUSES as $s): ?>
                <option value="<?php echo esc_url(add_query_arg(['po_status' => $s], $base_url)); ?>"
                    <?php selected($status_filter, $s); ?>>
                    <?php echo esc_html(MealsDB_Purchase_Orders::status_label($s)); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span id="mealsdb-po-action-msg" role="status"></span>
    </div>

    <?php if (empty($rows)): ?>
        <p><em><?php esc_html_e('No purchase orders yet.', 'meals-db'); ?></em></p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?php esc_html_e('PO #', 'meals-db'); ?></th>
                <th><?php esc_html_e('Supplier', 'meals-db'); ?></th>
                <th><?php esc_html_e('Status', 'meals-db'); ?></th>
                <th class="num"><?php esc_html_e('Cases', 'meals-db'); ?></th>
                <th class="num"><?php esc_html_e('Edits', 'meals-db'); ?></th>
                <th><?php esc_html_e('Created', 'meals-db'); ?></th>
                <th><?php esc_html_e('Approved', 'meals-db'); ?></th>
                <th><?php esc_html_e('Received', 'meals-db'); ?></th>
                <th><?php esc_html_e('Actions', 'meals-db'); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $po): ?>
                    <?php
                    $rid        = (int) $po['po_id'];
                    $detail_url = add_query_arg(['po_id' => $rid], $base_url);
                    $payload    = null;
                    if (isset($po['payload']) && is_string($po['payload']) && $po['payload'] !== '') {
                        $decoded = json_decode($po['payload'], true);
                        $payload = (is_array($decoded) && isset($decoded['current'])) ? $decoded : null;
                    }
                    $is_wf = is_array($payload);
                    $cases = 0;
                    if ($is_wf) {
                        foreach ($payload['current'] as $r) { $cases += (int) ($r['cases'] ?? 0); }
                    }
                    // Legacy items store UNITS, not cases — the column shows — for them.
                    $st = (string) $po['status'];
                    ?>
                    <tr>
                        <td><strong><a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html((string) $po['po_number']); ?></a></strong></td>
                        <td><?php echo esc_html((string) ($po['supplier'] ?? '')); ?></td>
                        <td><span class="mealsdb-po-status mealsdb-po-status-<?php echo esc_attr($st); ?>"><?php echo esc_html(MealsDB_Purchase_Orders::status_label($st)); ?></span><?php if (!$is_wf): ?> <em class="mealsdb-po-legacy"><?php esc_html_e('(task)', 'meals-db'); ?></em><?php endif; ?></td>
                        <td class="num"><?php echo $is_wf ? (int) $cases : '&mdash;'; ?></td>
                        <td class="num"><?php echo $is_wf ? (int) ($po['edit_count'] ?? 0) : '&mdash;'; ?></td>
                        <td><?php echo esc_html((string) ($po['created_at'] ?? '—')); ?></td>
                        <td><?php echo esc_html((string) ($po['approved_at'] ?? ($po['placed_date'] ?? '—'))); ?></td>
                        <td><?php echo esc_html((string) ($po['received_at'] ?? ($po['arrival_date'] ?? '—'))); ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url($detail_url); ?>"><?php esc_html_e('Review', 'meals-db'); ?></a>
                            <?php if ($is_wf && $st === MealsDB_Purchase_Orders::STATUS_PLANNED): ?>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="approve" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Approve', 'meals-db'); ?></button>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="cancel" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Cancel', 'meals-db'); ?></button>
                            <?php elseif ($is_wf && $st === MealsDB_Purchase_Orders::STATUS_PLACED): ?>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="receive" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Mark received', 'meals-db'); ?></button>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="unapprove" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Un-approve', 'meals-db'); ?></button>
                            <?php elseif ($is_wf && $st === MealsDB_Purchase_Orders::STATUS_ARRIVED): ?>
                                <a class="button button-small" href="<?php echo esc_url(add_query_arg(['po_id' => $rid, 'action' => 'reconcile'], $base_url)); ?>"><?php esc_html_e('Reconcile', 'meals-db'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php
$mealsdb_po_render_island(['mode' => 'list']);
```

- [ ] **Step 8.2: Lint + commit**

```bash
php -l views/purchase-orders.php
git add views/purchase-orders.php
git commit -m "feat(po): invoice-style PO list and detail views with draft/locked/reconcile modes

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 9: JS + CSS + enqueue wiring

**Files:**
- Create: `assets/js/purchase-orders.js`
- Create: `assets/css/purchase-orders.css`
- Modify: `includes/class-admin-ui.php` (`enqueue_tab_view_scripts`, ~line 117)

- [ ] **Step 9.1: Create `assets/js/purchase-orders.js`**

```js
/**
 * Purchase Orders tab — draft workflow list + detail interactivity.
 *
 * Reads config from the JSON island #mealsdb-po-admin-data. Three concerns:
 *   1. List/detail lifecycle buttons (approve / un-approve / receive /
 *      cancel / complete-reconcile) with confirms and the un-approve
 *      reason prompt (mirrors invoice un-finalize UX).
 *   2. Draft mode: +/- case steppers, debounced per-row saves, "was:" hints,
 *      live totals.
 *   3. Coverage warnings, recomputed on every click from the row's
 *      generation-time snapshot (data-adjusted-weekly / data-stock /
 *      data-case-size): yellow ! below the 9-week target, red ! below the
 *      7-week floor. Warnings never block saving — they inform.
 */
(function ($) {
    'use strict';

    var _el = document.getElementById('mealsdb-po-admin-data');
    if (!_el) { return; }
    var cfg  = JSON.parse(_el.textContent || '{}');
    var i18n = cfg.i18n || {};

    function t(key, fallback) {
        return (i18n[key] != null) ? i18n[key] : fallback;
    }
    function esc(str) {
        var d = document.createElement('div');
        d.textContent = str == null ? '' : String(str);
        return d.innerHTML;
    }
    function msg(text, isError) {
        $('#mealsdb-po-action-msg')
            .text(text || '')
            .css('color', isError ? '#b32d2e' : '#2271b1');
    }

    // ------------------------------------------------------------------
    // Coverage math — same formula as MealsDB_Purchase_Orders::coverage_weeks.
    // Not money, so client-side math is fine; thresholds come from the island.
    // ------------------------------------------------------------------
    function coverage($row, cases) {
        var weekly = parseFloat($row.data('adjusted-weekly')) || 0;
        if (weekly <= 0) { return null; }
        var units = (parseInt($row.data('stock'), 10) || 0)
                  + cases * (parseInt($row.data('case-size'), 10) || 1);
        return Math.round((units / weekly) * 10) / 10;
    }

    function renderCoverage($row, cases) {
        var $cell = $row.find('.mealsdb-po-coverage');
        var wks = coverage($row, cases);
        if (wks === null) {
            $cell.html('&mdash;').attr('data-coverage', '');
            return;
        }
        var badge = '';
        if (wks < (cfg.floorWeeks || 7)) {
            badge = '<span class="mealsdb-po-flag mealsdb-po-crit" title="'
                + esc(t('belowFloor', 'Below 7-week safety floor (%s wks)').replace('%s', wks.toFixed(1)))
                + '">!</span>';
        } else if (wks < (cfg.targetWeeks || 9)) {
            badge = '<span class="mealsdb-po-flag mealsdb-po-warn" title="'
                + esc(t('belowTarget', 'Below 9-week coverage target (%s wks)').replace('%s', wks.toFixed(1)))
                + '">!</span>';
        }
        $cell.attr('data-coverage', wks).html(esc(wks.toFixed(1)) + ' ' + badge);
    }

    function refreshTotals() {
        var cases = 0, units = 0;
        $('#mealsdb-po-grid tbody tr').each(function () {
            var $row = $(this);
            var c = parseInt($row.find('.mealsdb-po-cases').attr('data-cases'), 10);
            if (isNaN(c)) { c = parseInt($row.data('ordered-cases'), 10) || 0; }
            cases += c;
            units += c * (parseInt($row.data('case-size'), 10) || 1);
        });
        $('#mealsdb-po-total-cases').text(cases);
        $('#mealsdb-po-total-units').text(units);
    }

    // ------------------------------------------------------------------
    // Steppers (draft + reconcile modes share the click/debounce plumbing;
    // the posted action differs by mode).
    // ------------------------------------------------------------------
    var mode = cfg.mode || 'list';
    var saveTimers = {}; // sku -> timeout id

    function currentCases($row) {
        return parseInt($row.find('.mealsdb-po-cases').attr('data-cases'), 10) || 0;
    }
    function setCases($row, cases) {
        $row.find('.mealsdb-po-cases').attr('data-cases', cases).text(cases);
        $row.find('.mealsdb-po-orderqty').text(cases * (parseInt($row.data('case-size'), 10) || 1));
        renderCoverage($row, cases);
        refreshTotals();
        if (mode === 'draft') {
            var gen = parseInt($row.data('generated-cases'), 10) || 0;
            var $was = $row.find('.mealsdb-po-was');
            if (cases !== gen) {
                if (!$was.length) {
                    $was = $('<div class="mealsdb-po-was"></div>').appendTo($row.find('.mealsdb-po-ordered'));
                }
                $was.text(t('was', 'was: %s').replace('%s', gen));
            } else {
                $was.remove();
            }
        }
        if (mode === 'reconcile') {
            var ordered = parseInt($row.data('ordered-cases'), 10) || 0;
            $row.find('.mealsdb-po-note').toggle(cases !== ordered);
        }
    }

    function queueSave($row) {
        var sku = String($row.data('sku'));
        if (saveTimers[sku]) { window.clearTimeout(saveTimers[sku]); }
        saveTimers[sku] = window.setTimeout(function () {
            delete saveTimers[sku];
            saveRow($row);
        }, 600);
    }

    function saveRow($row) {
        var sku   = String($row.data('sku'));
        var cases = currentCases($row);
        var data  = { nonce: cfg.nonce, po_id: cfg.poId, sku: sku };
        if (mode === 'draft') {
            data.action = 'mealsdb_po_edit_cases';
            data.cases  = cases;
        } else {
            data.action         = 'mealsdb_po_reconcile_edit';
            data.received_cases = cases;
            data.note           = String($row.find('.mealsdb-po-note').val() || '');
        }
        $row.addClass('mealsdb-po-saving');
        $.post(cfg.ajaxUrl, data, function (res) {
            $row.removeClass('mealsdb-po-saving');
            if (!res || !res.success) {
                msg((res && res.data && res.data.message) || t('requestFailed', 'Request failed.'), true);
                return;
            }
            msg('');
            if (res.data && res.data.changed) {
                var $count = $('#mealsdb-po-edit-count');
                if ($count.length) { $count.text((parseInt($count.text(), 10) || 0) + 1); }
            }
            $row.find('.mealsdb-po-coverage').addClass('mealsdb-po-recomputed');
            window.setTimeout(function () {
                $row.find('.mealsdb-po-coverage').removeClass('mealsdb-po-recomputed');
            }, 600);
        }).fail(function () {
            $row.removeClass('mealsdb-po-saving');
            msg(t('requestFailed', 'Request failed.'), true);
        });
    }

    $(document).on('click', '.mealsdb-po-step', function () {
        var $row  = $(this).closest('tr');
        var cases = Math.max(0, currentCases($row) + parseInt($(this).data('step'), 10));
        setCases($row, cases);
        queueSave($row);
    });

    // A typed reconcile note also needs persisting (the count may be
    // unchanged-but-annotated mid-session; the service stores both).
    $(document).on('change', '.mealsdb-po-note', function () {
        queueSave($(this).closest('tr'));
    });

    // ------------------------------------------------------------------
    // Lifecycle actions
    // ------------------------------------------------------------------
    var ACTION_MAP = {
        approve:   { action: 'mealsdb_po_approve',       confirm: t('confirmApprove', 'Approve this purchase order?') },
        receive:   { action: 'mealsdb_po_mark_received', confirm: t('confirmReceive', 'Mark received? Quantities will be added to inventory.') },
        cancel:    { action: 'mealsdb_po_cancel',        confirm: t('confirmCancel', 'Cancel this draft purchase order?') },
        unapprove: { action: 'mealsdb_po_unapprove',     confirm: null }
    };

    $(document).on('click', '.mealsdb-po-action', function () {
        var $btn = $(this);
        var kind = String($btn.data('po-action'));
        var map  = ACTION_MAP[kind];
        if (!map) { return; }

        var data = { nonce: cfg.nonce, po_id: parseInt($btn.data('po-id'), 10), action: map.action };
        if (kind === 'unapprove') {
            var reason = window.prompt(t('promptUnapprove', 'Enter a reason for un-approving (required):'));
            if (reason === null) { return; }
            if (!reason.replace(/\s/g, '').length) {
                msg(t('reasonRequired', 'A reason is required.'), true);
                return;
            }
            data.reason = reason;
        } else if (!window.confirm(map.confirm)) {
            return;
        }

        $btn.prop('disabled', true);
        msg(t('saving', 'Saving…'), false);
        $.post(cfg.ajaxUrl, data, function (res) {
            if (res && res.success) {
                window.location.reload();
                return;
            }
            $btn.prop('disabled', false);
            msg((res && res.data && res.data.message) || t('requestFailed', 'Request failed.'), true);
        }).fail(function () {
            $btn.prop('disabled', false);
            msg(t('requestFailed', 'Request failed.'), true);
        });
    });

    $(document).on('click', '#mealsdb-po-complete-reconcile', function () {
        var $btn = $(this);
        // Client-side pre-check: every adjusted row needs a note. The server
        // re-validates authoritatively; this just saves a round-trip.
        var missing = false;
        $('#mealsdb-po-grid tbody tr').each(function () {
            var $row = $(this);
            var ordered = parseInt($row.data('ordered-cases'), 10) || 0;
            if (currentCases($row) !== ordered && !String($row.find('.mealsdb-po-note').val() || '').replace(/\s/g, '').length) {
                $row.addClass('mealsdb-po-note-missing');
                missing = true;
            } else {
                $row.removeClass('mealsdb-po-note-missing');
            }
        });
        if (missing) {
            msg(t('noteRequired', 'A note is required for adjusted rows.'), true);
            return;
        }
        if (!window.confirm(t('confirmComplete', 'Complete reconciliation?'))) { return; }

        $btn.prop('disabled', true);
        msg(t('saving', 'Saving…'), false);
        $.post(cfg.ajaxUrl, {
            nonce: cfg.nonce, po_id: cfg.poId, action: 'mealsdb_po_complete_reconcile'
        }, function (res) {
            if (res && res.success) {
                window.location.href = cfg.baseUrl + '&po_id=' + cfg.poId;
                return;
            }
            $btn.prop('disabled', false);
            // Highlight server-reported offenders (authoritative).
            if (res && res.data && res.data.data && res.data.data.skus) {
                $.each(res.data.data.skus, function (_, sku) {
                    $('#mealsdb-po-grid tbody tr').filter(function () {
                        return String($(this).data('sku')) === String(sku);
                    }).addClass('mealsdb-po-note-missing');
                });
            }
            msg((res && res.data && res.data.message) || t('requestFailed', 'Request failed.'), true);
        }).fail(function () {
            $btn.prop('disabled', false);
            msg(t('requestFailed', 'Request failed.'), true);
        });
    });
})(jQuery);
```

- [ ] **Step 9.2: Create `assets/css/purchase-orders.css`**

```css
/**
 * Purchase Orders tab — draft workflow styling.
 * Follows the invoice-draft.css conventions (WP admin palette, sticky
 * header, recompute flash) plus the coverage warning badges.
 */

/* Grid */
.mealsdb-po-grid th.num,
.mealsdb-po-grid td.num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.mealsdb-po-grid thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f6f7f7;
}

/* Steppers */
.mealsdb-po-stepper {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
}
.mealsdb-po-stepper .mealsdb-po-step {
    min-width: 26px;
    padding: 0 6px;
    line-height: 1.6;
    text-align: center;
}
.mealsdb-po-cases {
    display: inline-block;
    min-width: 32px;
    text-align: right;
    font-weight: 600;
}

/* "was: N" hint under an edited count (invoice-draft convention) */
.mealsdb-po-was {
    font-size: 11px;
    color: #777;
    text-align: right;
}

/* Coverage warning badges: yellow ! below the 9-week target, red ! below
   the 7-week safety floor. Informational — never blocks saving. */
.mealsdb-po-flag {
    display: inline-block;
    min-width: 16px;
    text-align: center;
    border-radius: 50%;
    font-weight: 700;
    cursor: help;
}
.mealsdb-po-warn {
    background: #fcf9e8;
    color: #996800;
    border: 1px solid #dba617;
}
.mealsdb-po-crit {
    background: #fcf0f1;
    color: #b32d2e;
    border: 1px solid #b32d2e;
}

/* Row states */
tr.mealsdb-po-saving {
    opacity: 0.6;
}
.mealsdb-po-coverage.mealsdb-po-recomputed {
    background: #fcf9e8;
    transition: background 0.6s ease;
}
tr.mealsdb-po-note-missing .mealsdb-po-note {
    border-color: #b32d2e;
    box-shadow: 0 0 0 1px #b32d2e;
}

/* Status chip */
.mealsdb-po-status {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 10px;
    font-size: 12px;
    background: #f0f0f1;
    color: #3c434a;
}
.mealsdb-po-status-planned    { background: #f0f6fc; color: #2271b1; }
.mealsdb-po-status-placed     { background: #edfaef; color: #007017; }
.mealsdb-po-status-arrived    { background: #fcf9e8; color: #996800; }
.mealsdb-po-status-reconciled { background: #f0f0f1; color: #3c434a; }
.mealsdb-po-status-cancelled  { background: #fcf0f1; color: #b32d2e; }
.mealsdb-po-legacy            { color: #777; font-size: 11px; }
```

- [ ] **Step 9.3: Enqueue on the `po_admin` tab**

In `includes/class-admin-ui.php`, inside `enqueue_tab_view_scripts()`'s `switch ($tab)`, add after the `case 'po':` block:

```php
            case 'po_admin':
                $enqueue('purchase-orders');
                $po_css = MEALS_DB_PLUGIN_DIR . 'assets/css/purchase-orders.css';
                if (file_exists($po_css)) {
                    wp_enqueue_style(
                        'mealsdb-purchase-orders',
                        MEALS_DB_PLUGIN_URL . 'assets/css/purchase-orders.css',
                        [],
                        filemtime($po_css)
                    );
                }
                break;
```

- [ ] **Step 9.4: Verify + commit**

```bash
php -l includes/class-admin-ui.php
node --check assets/js/purchase-orders.js 2>/dev/null || php -r 'exit(0);' # node optional; JS reviewed manually if absent
git add assets/js/purchase-orders.js assets/css/purchase-orders.css includes/class-admin-ui.php
git commit -m "feat(po): +/- case steppers, coverage warnings, lifecycle actions (JS/CSS)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 10: Forecast tab "Save as draft PO"

**Files:**
- Modify: `views/purchase-order.php`
- Modify: `assets/js/purchase-order.js`

- [ ] **Step 10.1: Add the button and island keys to the view**

In `views/purchase-order.php`, after the Export CSV button (line 27–29) add:

```php
            <button type="button" class="button" id="mealsdb-po-save-draft" style="display:none;">
                <?php echo esc_html__('Save as draft PO', 'meals-db'); ?>
            </button>
```

In the `$mealsdb_po_island` array, after `'ajaxUrl' => ...` add:

```php
    // PO draft workflow: its own nonce context (destructive family) and the
    // list page to land on after a successful save.
    'poNonce'    => wp_create_nonce(MealsDB_Ajax_Purchase_Orders::NONCE_ACTION),
    'poAdminUrl' => admin_url('admin.php?page=mealsdb&tab=po_admin'),
```

And to the `i18n` sub-array add:

```php
        'savingDraft'     => __('Saving draft…', 'meals-db'),
        'draftSaveFailed' => __('Could not save the draft purchase order.', 'meals-db'),
```

- [ ] **Step 10.2: Wire the button in `assets/js/purchase-order.js`**

In the generate success handler, after `$('#mealsdb-po-export').show();` inside the `if (csvData)` block, add a sibling line showing the save button — change the block to:

```js
            if (csvData) {
                $('#mealsdb-po-export').show();
                $('#mealsdb-po-save-draft').show();
            }
```

At the start of the `#mealsdb-po-generate` click handler, next to `$('#mealsdb-po-export').hide();` add:

```js
        $('#mealsdb-po-save-draft').hide();
```

At the end of the file, before the closing `})(jQuery);`, add:

```js
    // Persist the on-screen forecast as a Draft PO. The server REGENERATES
    // the rows (the browser copy is untrusted display data) and saves the
    // same variant that is showing — base, or pallet-optimised when the
    // optimised table is on screen.
    $('#mealsdb-po-save-draft').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        showStatus(t('savingDraft', 'Saving draft…'), 'info');
        $.post(ajaxUrl, {
            action: 'mealsdb_po_save_draft',
            nonce: data.poNonce || '',
            optimize: showingOptimized ? 1 : 0
        }, function (res) {
            if (res && res.success && res.data && res.data.po_id) {
                window.location.href = (data.poAdminUrl || '') + '&po_id=' + parseInt(res.data.po_id, 10);
                return;
            }
            $btn.prop('disabled', false);
            showStatus((res && res.data && res.data.message) || t('draftSaveFailed', 'Could not save the draft purchase order.'), 'error');
        }).fail(function () {
            $btn.prop('disabled', false);
            showStatus(t('requestFailed', 'Request failed.'), 'error');
        });
    });
```

- [ ] **Step 10.3: Lint + commit**

```bash
php -l views/purchase-order.php
git add views/purchase-order.php assets/js/purchase-order.js
git commit -m "feat(po): save-as-draft button on the forecast tab

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 11: Full verification pass

- [ ] **Step 11.1: Run the entire test suite**

```bash
cd /mnt/fastssd/meals-db
fails=0; for f in tests/test-*.php; do php "$f" >/dev/null 2>&1 || { echo "FAIL: $f"; fails=$((fails+1)); }; done; echo "$fails failing files"
```
Expected: only the 2 known-baseline PDF failures (mbstring/imagick missing locally — see memory note). Any OTHER failure must be fixed before proceeding.

- [ ] **Step 11.2: Lint every touched PHP file**

```bash
for f in includes/class-schema.php includes/class-rate-limiter.php meals-db-main.php \
         includes/services/class-purchase-orders.php includes/ajax/class-ajax-purchase-orders.php \
         views/purchase-orders.php views/purchase-order.php includes/class-admin-ui.php; do php -l "$f" || break; done
```
Expected: `No syntax errors detected` × 8.

- [ ] **Step 11.3: Guard-coverage grep (defense-in-depth spot check)**

```bash
grep -n "can_access_plugin\|check_ajax_referer\|check_rate_limit" includes/ajax/class-ajax-purchase-orders.php | head
grep -c "can_access_plugin" includes/services/class-purchase-orders.php
```
Expected: guard() shows all three layers; service shows ≥ 7 capability re-checks (one per workflow mutator).

- [ ] **Step 11.4: Final commit if anything moved, then hand off**

Use superpowers:verification-before-completion, then superpowers:finishing-a-development-branch (repo convention: PR to `main`, e.g. `gh pr create`).

---

## Self-review notes (already applied)

- **Spec coverage:** schema §1 → Task 1; lifecycle §2 → Tasks 2–6; list/detail UI §3.1–3.4 → Task 8; forecast-tab save §3.5 → Task 10; assets §3.6 → Task 9; endpoints/guards §4 → Task 7; rollout §5 → Task 1; testing §6 → Tasks 2–6 + 11. Cancel-draft included (Task 4). Legacy read-only gating: Task 3 (T-6), Task 8 (view).
- **Type consistency:** service returns `int` (create_draft), `array|WP_Error` (row edits), `true|WP_Error` (transitions); AJAX `respond()` handles all three. Payload row keys used identically in service, view (`data-*`), and JS.
- **Known deviation from spec text:** `payload.received` is stored as a sku-keyed map (not a list) for O(1) lookup; the spec's `[{sku, received_cases, note}]` shape is otherwise honored field-for-field.
