# PO Workflow ↔ Task System Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tie the task system to the PO draft workflow: lifecycle hooks from the PO service, a bridge that spawns/auto-closes warehouse tasks, two workflow-native task types that can actuate the workflow ("either side actuates"), removal of the never-used legacy PO task chain, and expected-arrival capture at approval.

**Architecture:** `MealsDB_Purchase_Orders` emits `mealsdb_po_approved/unapproved/received/reconciled` actions after each guarded transition (staying task-free). A new `MealsDB_PO_Task_Bridge` listens and spawns `po_confirm_arrival` / `po_reconcile` tasks or auto-skips them when the PO page acted first. The two new task types' `on_complete` handlers call the same service methods as the PO page — guarded transitions make whichever side acts second a graceful no-op. The three legacy PO task types are deleted; their two inventory statics move into the PO service. Spec: `docs/superpowers/specs/2026-07-10-po-task-integration-design.md`.

**Tech Stack:** WordPress plugin, PHP 8.2, `$wpdb`, jQuery admin JS, standalone PHP test scripts (`php tests/<file>.php`, exit 0 = pass).

---

## Codebase facts the engineer must know

- **CLAUDE.md is binding** (read it): `\Throwable` catches, UTC `gmdate()`, audit log via `MealsDB_Logger::log($action, $target_id, $field, $old, $new, $source='mealsdb')`, Event Log via `MealsDB_Event_Log::record([...])` for degraded outcomes, no inline `<script>` > 20 lines.
- **Task engine** (`includes/services/class-task-engine.php`): `new MealsDB_Task_Engine($wpdb = null)`; `create_task(array $args): int` (task_type, payload, next_run_date, related_entity_type/id, parent_task_id, assignee_role, urgency); `query_tasks(array $filters): array` (status/task_type/related_entity_type/related_entity_id filters, arrays allowed); `complete_task(int, array $form_data, int $completed_by): bool` (validates form_data against form_schema, commits, then fires `on_complete` — callback errors are caught by the engine, not re-thrown); `defer_task(int, string $new_date, ?string $reason, bool $allow_from_terminal = false): bool`; `skip_task(int, ?string $reason = null): bool` (refuses terminal tasks, logs transition). Statuses: pending/in_progress/deferred + terminal completed/skipped/abandoned. `MealsDB_Task_Engine::URGENCY_ROUTINE`.
- **Task registry** (`class-task-registry.php`): `MealsDB_Task_Registry::register(string $type_id, array $definition)` with label/description/assignee_role/urgency/form_schema/on_complete. Form schema supports `readonly`, `required`, `min`, `show_when` (`['field'=>'x','equals'=>'v']` or `['field'=>'x','not_equals_field'=>'y']` — required-validation is skipped while hidden), and `repeat_group` with `items_from => 'payload.<key>'` prefill and nested `fields`. `MealsDB_Task_Registry::reset()` exists for tests.
- **PO service** (`includes/services/class-purchase-orders.php`): `approve(int $po_id)` at line ~516, `unapprove(int, string $reason)` ~574, `mark_received(int)` ~638, `complete_reconcile(int)` ~745, `edit_reconcile_row(int, string $sku, int $received_cases, string $note)`; private `transition()`, `require_workflow_po()`, static `normalize_date($value): ?string`. All transitions guarded (`WHERE status='<from>'`), all return `true|WP_Error` with codes forbidden/not_found/legacy/locked/race/…
- **Current inventory statics** (to move): `MealsDB_Task_Type_Confirm_PO_Arrival::apply_inventory_bump(array $items): void` (`class-task-type-confirm-po-arrival.php:115-163`, atomic `wc_update_product_stock` increase, audits `po_inventory_bump`) and `MealsDB_Task_Type_Physical_Count::apply_adjustments(int $po_id, array $adjustments): void` (`class-task-type-physical-count.php:94-161`, server-sources ordered from PO items, audits `inventory_discrepancy`).
- **Registrations to remove** (`meals-db-main.php:121-123`): `MealsDB_Task_Type_Place_PO::register();`, `MealsDB_Task_Type_Confirm_PO_Arrival::register();`, `MealsDB_Task_Type_Physical_Count::register();`.
- **Test stub conventions:** the PO tests use an inline-substituting `prepare()` + regex `get_row`/`get_results`. The engine's `query_tasks` emits `status IN ('a','b')`, `task_type IN (...)`, `related_entity_type = 'po'`, `related_entity_id = N` — the tasks stub must filter on those. Autoloader covers `includes/task-types` (`class-autoloader.php:43`); new class `MealsDB_Task_Type_PO_Confirm_Arrival` → file `class-task-type-po-confirm-arrival.php` (lowercase, underscores→hyphens).
- Known local baseline: 2 PDF tests fail (missing mbstring/imagick) — ignore only those.

## File structure

| File | Action | Responsibility |
|---|---|---|
| `includes/services/class-purchase-orders.php` | Modify | + inventory statics, + hooks, + expected_arrival/arrival_date params |
| `includes/task-types/class-task-type-place-po.php` | Delete | legacy |
| `includes/task-types/class-task-type-confirm-po-arrival.php` | Delete | legacy |
| `includes/task-types/class-task-type-physical-count.php` | Delete | legacy |
| `includes/task-types/class-task-type-po-confirm-arrival.php` | Create | workflow-native confirm-arrival task |
| `includes/task-types/class-task-type-po-reconcile.php` | Create | workflow-native reconcile task |
| `includes/services/class-po-task-bridge.php` | Create | hook listener: spawn / auto-skip |
| `meals-db-main.php` | Modify | swap registrations, bridge init |
| `includes/ajax/class-ajax-purchase-orders.php` | Modify | approve gains expected_arrival param |
| `views/purchase-orders.php` | Modify | expected-arrival input (detail), i18n keys |
| `assets/js/purchase-orders.js` | Modify | approve date capture (input / prompt) |
| `views/task-detail.php` | Modify | PO deep link |
| `tests/test-po-task-types.php` | Create | handler idempotency matrix |
| `tests/test-po-task-bridge.php` | Create | hook→spawn/auto-skip loop |
| `tests/test-po-draft-lifecycle.php` | Modify | T-11 hooks + expected_arrival |
| `tests/test-task-workflow-po-chain.php` | Delete | tests the deleted chain |

---

### Task 0: Branch

- [ ] **Step 0.1:**

```bash
cd /mnt/fastssd/meals-db && git checkout main && git pull && git checkout -b feat/po-task-integration
```

---

### Task 1: Move inventory statics into the PO service

**Files:**
- Modify: `includes/services/class-purchase-orders.php`
- Tests: `tests/test-po-draft-lifecycle.php`, `tests/test-po-reconcile-deltas.php` (must stay green, no edits expected)

The two statics are COPIED into the service (verbatim behavior, log prefix updated); the legacy classes keep their own copies until Task 2 deletes them (one commit of duplication, then gone).

- [ ] **Step 1.1: Add the two statics to `MealsDB_Purchase_Orders`**

Add at the end of the class (after the private helpers), a new section:

```php
    // -----------------------------------------------------------------
    // Inventory side-effects (moved here from the deleted legacy PO task
    // types — spec 2026-07-10 task-integration §4. Behavior and audit
    // actions are unchanged; these are the ONLY stock-write paths for POs.)
    // -----------------------------------------------------------------

    /**
     * Bump WC stock for each item by quantity_ordered (UNITS). Silently
     * skips items with unknown SKUs, logging each miss.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public static function apply_inventory_bump(array $items): void {
        if (!function_exists('wc_get_product_id_by_sku') || !function_exists('wc_get_product') || !function_exists('wc_update_product_stock')) {
            error_log('[MealsDB Purchase Orders] WooCommerce not available; skipping inventory bump.');
            return;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sku = isset($item['sku']) ? (string) $item['sku'] : '';
            $qty = isset($item['quantity_ordered']) ? (int) $item['quantity_ordered'] : 0;
            if ($sku === '' || $qty === 0) {
                continue;
            }

            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                error_log(sprintf('[MealsDB Purchase Orders] Unknown SKU "%s"; skipping bump.', $sku));
                continue;
            }
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $current = (int) $product->get_stock_quantity();
            // Atomic DB-level increment (SQL `stock = stock + qty`) instead of a
            // read-modify-write set/save, which clobbers a concurrent stock
            // change (e.g. an order placed between our read and our write).
            $new_stock = wc_update_product_stock($product, $qty, 'increase');
            if ($new_stock === null) {
                // Product does not manage stock — nothing changed; skip the
                // audit line rather than log a bogus old->new pair.
                continue;
            }
            $new_total = (int) $new_stock;

            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log(
                    'po_inventory_bump',
                    (int) $product_id,
                    'stock_quantity',
                    (string) $current,
                    (string) $new_total,
                    'mealsdb'
                );
            }
        }
    }

    /**
     * Apply per-SKU count deltas to WC stock. Ordered quantities were added
     * at receive time; this only adjusts the ordered-vs-actual delta.
     *
     * Ordered quantities (and the valid SKU set) are sourced from the STORED
     * PO, never the caller — a tampered adjustment row cannot apply an
     * arbitrary delta. Only actual_count is taken from the caller.
     *
     * @param array<int, array<string, mixed>> $adjustments each {sku, actual_count (UNITS), reason, reason_notes}
     */
    public static function apply_adjustments(int $po_id, array $adjustments): void {
        if (!function_exists('wc_get_product_id_by_sku') || !function_exists('wc_get_product')) {
            error_log('[MealsDB Purchase Orders] WooCommerce not available; skipping stock adjustments.');
            return;
        }

        $ordered_by_sku = [];
        $po = (new MealsDB_Purchase_Orders())->get($po_id);
        foreach ((array) ($po['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $isku = isset($item['sku']) ? (string) $item['sku'] : '';
            if ($isku !== '') {
                $ordered_by_sku[$isku] = isset($item['quantity_ordered']) ? (int) $item['quantity_ordered'] : 0;
            }
        }

        foreach ($adjustments as $adj) {
            if (!is_array($adj)) {
                continue;
            }
            $sku = isset($adj['sku']) ? (string) $adj['sku'] : '';
            // Reject any SKU not actually on this PO — never trust a caller-only sku.
            if ($sku === '' || !array_key_exists($sku, $ordered_by_sku)) {
                continue;
            }
            $ordered = $ordered_by_sku[$sku]; // server-sourced
            $actual  = isset($adj['actual_count']) ? (int) $adj['actual_count'] : $ordered;
            $diff    = $actual - $ordered;

            if ($diff === 0) {
                continue;
            }

            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                error_log(sprintf('[MealsDB Purchase Orders] Unknown SKU "%s"; skipping adjustment.', $sku));
                continue;
            }
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $current = (int) $product->get_stock_quantity();
            $new_total = $current + $diff;
            $product->set_stock_quantity($new_total);
            $product->save();

            if (class_exists('MealsDB_Logger')) {
                $reason = isset($adj['reason']) ? (string) $adj['reason'] : '';
                $notes  = isset($adj['reason_notes']) ? (string) $adj['reason_notes'] : '';
                MealsDB_Logger::log(
                    'inventory_discrepancy',
                    (int) $product_id,
                    'stock_quantity',
                    (string) $current,
                    (string) $new_total,
                    sprintf('mealsdb:po=%d:sku=%s:ordered=%d:actual=%d:reason=%s:notes=%s',
                        $po_id, $sku, $ordered, $actual, $reason, $notes)
                );
            }
        }
    }
```

- [ ] **Step 1.2: Switch the two workflow call sites to self::**

In `mark_received()` (~line 657) replace:

```php
        if (class_exists('MealsDB_Task_Type_Confirm_PO_Arrival')) {
            MealsDB_Task_Type_Confirm_PO_Arrival::apply_inventory_bump((array) $po['items']);
        }
```
with:
```php
        self::apply_inventory_bump((array) $po['items']);
```

In `complete_reconcile()` (~line 803) replace:

```php
        if (!empty($adjustments) && class_exists('MealsDB_Task_Type_Physical_Count')) {
            // Best-effort, void, per-SKU (matches the legacy physical_count task
            // path): a mid-loop failure leaves this PO reconciled with the
            // applied SKUs audited as inventory_discrepancy rows — recovery is
            // diffing those rows against the payload and correcting WC stock.
            MealsDB_Task_Type_Physical_Count::apply_adjustments($po_id, $adjustments);
        }
```
with:
```php
        if (!empty($adjustments)) {
            // Best-effort, void, per-SKU: a mid-loop failure leaves this PO
            // reconciled with the applied SKUs audited as inventory_discrepancy
            // rows — recovery is diffing those rows against the payload and
            // correcting WC stock.
            self::apply_adjustments($po_id, $adjustments);
        }
```

Also update the `mark_received()` docblock sentence "The bump itself delegates to the existing task-type static…" to "The bump itself is this class's own `apply_inventory_bump` (one inventory-bump implementation in the plugin)."

- [ ] **Step 1.3: Verify**

```bash
php -l includes/services/class-purchase-orders.php
php tests/test-po-draft-lifecycle.php    # 74+ passed, 0 failed
php tests/test-po-reconcile-deltas.php   # 28 passed, 0 failed
php tests/test-task-workflow-po-chain.php # 25 passed (legacy chain still intact this commit)
```

- [ ] **Step 1.4: Commit**

```bash
git add includes/services/class-purchase-orders.php
git commit -m "refactor(po): move inventory bump/adjustment statics into the PO service

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Delete the legacy PO task chain

**Files:**
- Delete: `includes/task-types/class-task-type-place-po.php`, `class-task-type-confirm-po-arrival.php`, `class-task-type-physical-count.php`
- Delete: `tests/test-task-workflow-po-chain.php`
- Modify: `meals-db-main.php:121-123`

- [ ] **Step 2.1: Remove the three registration lines** from `meals-db-main.php`:

```php
    MealsDB_Task_Type_Place_PO::register();
    MealsDB_Task_Type_Confirm_PO_Arrival::register();
    MealsDB_Task_Type_Physical_Count::register();
```

- [ ] **Step 2.2: Delete the four files**

```bash
git rm includes/task-types/class-task-type-place-po.php \
       includes/task-types/class-task-type-confirm-po-arrival.php \
       includes/task-types/class-task-type-physical-count.php \
       tests/test-task-workflow-po-chain.php
```

- [ ] **Step 2.3: Verify zero remaining references**

```bash
grep -rn "Place_PO\|Confirm_PO_Arrival\|Physical_Count\|place_po\|confirm_po_arrival\|physical_count" --include=*.php . | grep -v docs/
```
Expected: NO output from includes/, views/, assets/, tests/, meals-db-main.php. (Hits inside `docs/` are fine — specs/audits are historical.) If anything else surfaces (e.g. a task-rules seed, a view dropdown), STOP and fix that reference as part of this task, reporting what you found.

```bash
php -l meals-db-main.php
php tests/test-po-draft-lifecycle.php && php tests/test-po-reconcile-deltas.php
```

- [ ] **Step 2.4: Commit**

```bash
git add -A
git commit -m "feat(po): remove legacy PO task chain (never used in production)

place_po / confirm_po_arrival / physical_count and their chain test are
deleted; the workflow-native replacements arrive with the task bridge.
Inventory statics were relocated to MealsDB_Purchase_Orders in the
previous commit.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Lifecycle hooks + signature changes on the PO service

**Files:**
- Modify: `includes/services/class-purchase-orders.php`
- Test: `tests/test-po-draft-lifecycle.php` (append T-11)

- [ ] **Step 3.1: Append failing tests**

In `tests/test-po-draft-lifecycle.php`, add to the WP-stub block near the top (after the `sanitize_text_field` stub):

```php
if (!function_exists('do_action')) {
    function do_action(string $hook, ...$args) { $GLOBALS['fired_actions'][] = ['hook' => $hook, 'args' => $args]; }
}
if (!function_exists('add_action')) {
    function add_action(string $hook, $cb, int $prio = 10, int $accepted = 1) { $GLOBALS['wp_actions'][$hook][] = $cb; }
}
$GLOBALS['fired_actions'] = [];
```

Append before the summary block:

```php
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

// malformed expected_arrival → stored as null, hook gets null.
chk($svc->approve($id, 'not-a-date'), true, 'T-11: approve tolerates malformed date');
chk($svc->get_with_payload($id)['expected_arrival'], null, 'T-11: malformed date stored as null');
$f = fired('mealsdb_po_approved');
chk($f[1]['args'], [$id, null], 'T-11: hook gets null for malformed date');

// mark_received with an explicit arrival date.
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
```

- [ ] **Step 3.2: Run to verify failure**

Run: `php tests/test-po-draft-lifecycle.php`
Expected: T-11 failures (approve() takes 1 arg / expected_arrival not stored / zero fired hooks). Earlier tests stay green.

- [ ] **Step 3.3: Implement**

In `includes/services/class-purchase-orders.php`:

a) `approve()` — change the signature and store/emit the date:

```php
    public function approve(int $po_id, ?string $expected_arrival = null) {
```

Normalize right after the `require_workflow_po` block:

```php
        // Task-integration: an optional expected-arrival date (captured in the
        // approve dialog) rides the same guarded transition and becomes the
        // confirm-arrival task's due date. Malformed → null (bridge falls back).
        $expected_arrival = self::normalize_date($expected_arrival);
```

Add to the `transition()` extras array (after `'items' => $encoded_items,`):

```php
            'expected_arrival' => $expected_arrival,
```

After the `MealsDB_Logger::log('po_approved', …)` block, before `return true;`:

```php
        if (function_exists('do_action')) {
            do_action('mealsdb_po_approved', $po_id, $expected_arrival);
        }
```

b) `unapprove()` — add to the transition extras (after `'placed_date' => null,`):

```php
            'expected_arrival' => null,
```

After its audit-log block, before `return true;`:

```php
        if (function_exists('do_action')) {
            do_action('mealsdb_po_unapproved', $po_id, $reason);
        }
```

c) `mark_received()` — change the signature:

```php
    public function mark_received(int $po_id, ?string $arrival_date = null) {
```

Before the `transition()` call:

```php
        // A task completed after the fact may carry the TRUE arrival date;
        // the PO page passes null and gets today (UTC), as before.
        $arrival_date = self::normalize_date($arrival_date) ?? gmdate('Y-m-d');
```

and use `'arrival_date' => $arrival_date,` in the extras (replacing `gmdate('Y-m-d')`). After the audit block, before `return true;`:

```php
        if (function_exists('do_action')) {
            do_action('mealsdb_po_received', $po_id);
        }
```

d) `complete_reconcile()` — after its `MealsDB_Logger::log('po_reconciled', …)` block, before `return true;`:

```php
        if (function_exists('do_action')) {
            do_action('mealsdb_po_reconciled', $po_id);
        }
```

- [ ] **Step 3.4: Run both PO test files — all green**

```bash
php tests/test-po-draft-lifecycle.php && php tests/test-po-reconcile-deltas.php
```

- [ ] **Step 3.5: Commit**

```bash
php -l includes/services/class-purchase-orders.php
git add includes/services/class-purchase-orders.php tests/test-po-draft-lifecycle.php
git commit -m "feat(po): lifecycle hooks + expected-arrival/arrival-date params on the PO service

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Task type `po_confirm_arrival`

**Files:**
- Create: `includes/task-types/class-task-type-po-confirm-arrival.php`
- Test: `tests/test-po-task-types.php` (create)

- [ ] **Step 4.1: Write the failing test file**

Create `tests/test-po-task-types.php`. Bootstrap: copy VERBATIM from `tests/test-po-draft-lifecycle.php` everything from `<?php` through the `forecast_rows()` function (WP stubs including the do_action/add_action stubs from Task 3, WP_Error, PoWpdb + Race stubs, WC stubs, harness, fresh(), audit_has(), forecast_rows()). Update the header docblock:

```php
/**
 * Workflow-native PO task types (spec 2026-07-10 task-integration §3):
 *   po_confirm_arrival — arrived yes → mark_received (idempotent); no → defer.
 *   po_reconcile       — per-SKU counts+notes → service reconcile (idempotent).
 *
 * Run with: php tests/test-po-task-types.php
 */
```

Then EXTEND the copied `PoWpdb` stub for tasks (add these members INSIDE the PoWpdb class — the copy in this file only):

```php
    public array $tasks = [];
    private int $next_task_id = 1;
```

In `PoWpdb::insert()`, add BEFORE the purchase-orders branch:

```php
        if (strpos($table, 'meals_tasks') !== false) {
            $id = $this->next_task_id++;
            $data['task_id'] = $id;
            $data += ['status' => 'pending', 'deferral_count' => 0];
            $this->tasks[$id] = $data;
            $this->insert_id = $id;
            return 1;
        }
```

In `PoWpdb::get_row()`, add BEFORE the purchase-orders branch:

```php
        if (stripos($q, 'meals_tasks') !== false && preg_match('/task_id = (\d+)/', $q, $m)) {
            return $this->tasks[(int) $m[1]] ?? null;
        }
```

In `PoWpdb::update()`, add BEFORE the purchase-orders branch:

```php
        if (strpos($table, 'meals_tasks') !== false) {
            $id = (int) ($where['task_id'] ?? 0);
            if (!isset($this->tasks[$id])) { return 0; }
            foreach ($data as $k => $v) { $this->tasks[$id][$k] = $v; }
            return 1;
        }
```

In `PoWpdb::get_results()`, REPLACE the body with:

```php
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
```

After the bootstrap, register the new task type and add helpers:

```php
MealsDB_Task_Registry::reset();
MealsDB_Task_Type_PO_Confirm_Arrival::register();

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
```

End the file with the standard summary/exit block (same as the other PO tests).

- [ ] **Step 4.2: Run to verify failure**

Run: `php tests/test-po-task-types.php`
Expected: fatal — class `MealsDB_Task_Type_PO_Confirm_Arrival` not found.

- [ ] **Step 4.3: Create the task type**

Create `includes/task-types/class-task-type-po-confirm-arrival.php`:

```php
<?php
/**
 * Task type: po_confirm_arrival — "Did PO #X arrive?" for WORKFLOW POs
 * (spec 2026-07-10 task-integration §3a). Spawned by MealsDB_PO_Task_Bridge
 * when a PO is approved; due on the expected-arrival date.
 *
 * Either side actuates: answering yes calls the same guarded
 * MealsDB_Purchase_Orders::mark_received() the PO page uses, so whichever
 * side acts second is a graceful no-op — the double-bump protection lives
 * in the service's status-guarded transition, not here.
 *
 * A "no" answer auto-defers the task by 1 day (legacy pattern preserved).
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Type_PO_Confirm_Arrival {

    public const TYPE_ID = 'po_confirm_arrival';

    public static function register(): void {
        if (!class_exists('MealsDB_Task_Registry')) {
            return;
        }

        MealsDB_Task_Registry::register(self::TYPE_ID, [
            'label'         => __('Confirm PO Arrival', 'meals-db'),
            'description'   => __('Confirm that an approved purchase order has arrived (adds it to inventory).', 'meals-db'),
            'assignee_role' => 'warehouse',
            'urgency'       => MealsDB_Task_Engine::URGENCY_ROUTINE,
            'form_schema'   => [
                ['name' => 'po_number',        'type' => 'text', 'label' => __('PO Number', 'meals-db'), 'readonly' => true],
                ['name' => 'supplier',         'type' => 'text', 'label' => __('Supplier', 'meals-db'), 'readonly' => true],
                ['name' => 'expected_arrival', 'type' => 'date', 'label' => __('Expected', 'meals-db'), 'readonly' => true],
                ['name' => 'arrived',          'type' => 'yesno', 'label' => __('Did it arrive?', 'meals-db'), 'required' => true],
                ['name' => 'arrival_date',     'type' => 'date', 'label' => __('Actual arrival date', 'meals-db'),
                 'show_when' => ['field' => 'arrived', 'equals' => 'yes']],
                ['name' => 'notes',            'type' => 'textarea', 'label' => __('Notes', 'meals-db')],
            ],
            'on_complete'   => [self::class, 'handle_complete'],
        ]);
    }

    public static function handle_complete(array $task, array $form_data, int $completed_by): void {
        $arrived = isset($form_data['arrived']) ? (string) $form_data['arrived'] : 'no';
        $po_id   = isset($task['related_entity_id']) ? (int) $task['related_entity_id'] : 0;

        if ($arrived !== 'yes') {
            // Permissive UX (legacy pattern): finishing the form with
            // arrived=no auto-defers by 1 day instead of losing the task
            // in 'completed' limbo.
            $engine = new MealsDB_Task_Engine();
            $engine->defer_task(
                (int) $task['task_id'],
                gmdate('Y-m-d', strtotime('+1 day')),
                'Auto-deferred: PO did not arrive on expected date.',
                true // allow_from_terminal: reverse the just-committed completion
            );
            return;
        }

        if ($po_id <= 0) {
            self::degrade($task, 'po_task.confirm_no_entity', 'Task has no related PO.');
            return;
        }

        $service      = new MealsDB_Purchase_Orders();
        $arrival_date = isset($form_data['arrival_date']) ? (string) $form_data['arrival_date'] : null;

        $result = $service->mark_received($po_id, $arrival_date);
        if ($result === true) {
            return;
        }

        // The transition was refused. If the PO is already past 'placed', the
        // PO page (or another task) acted first — that is SUCCESS for this
        // task's purpose, not an error. Anything else is a stale/broken task.
        $po     = $service->get($po_id);
        $status = is_array($po) ? (string) ($po['status'] ?? '') : '';
        if (in_array($status, [MealsDB_Purchase_Orders::STATUS_ARRIVED, MealsDB_Purchase_Orders::STATUS_RECONCILED], true)) {
            return; // already received — nothing to do, no degraded noise
        }

        $message = is_wp_error($result) ? $result->get_error_message() : 'mark_received refused';
        self::degrade($task, 'po_task.stale_confirm', sprintf('PO %d status "%s": %s', $po_id, $status, $message));
    }

    /**
     * The engine has already committed this task as completed (callbacks fire
     * post-commit) — surfacing the problem on the Event Log dashboard is the
     * operator's signal to finish the work on the PO page.
     */
    private static function degrade(array $task, string $event, string $message): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Task po_confirm_arrival] ' . $message);
        }
        if (class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'task',
                'subsystem' => 'po_task_types',
                'event'     => $event,
                'outcome'   => 'degraded',
                'message'   => $message,
                'context'   => ['task_id' => (int) ($task['task_id'] ?? 0)],
            ]);
        }
    }
}
```

- [ ] **Step 4.4: Run tests, verify pass**

Run: `php tests/test-po-task-types.php` — all pass, exit 0.

- [ ] **Step 4.5: Commit**

```bash
php -l includes/task-types/class-task-type-po-confirm-arrival.php
git add includes/task-types/class-task-type-po-confirm-arrival.php tests/test-po-task-types.php
git commit -m "feat(po): workflow-native po_confirm_arrival task type

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Task type `po_reconcile`

**Files:**
- Create: `includes/task-types/class-task-type-po-reconcile.php`
- Test: `tests/test-po-task-types.php` (append)

- [ ] **Step 5.1: Append failing tests**

In `tests/test-po-task-types.php`, register the second type next to the first (`MealsDB_Task_Type_PO_Reconcile::register();`) and append before the summary block:

```php
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
```

- [ ] **Step 5.2: Run to verify failure** — fatal, class not found.

- [ ] **Step 5.3: Create the task type**

Create `includes/task-types/class-task-type-po-reconcile.php`:

```php
<?php
/**
 * Task type: po_reconcile — record what actually arrived for a WORKFLOW PO
 * (spec 2026-07-10 task-integration §3b). Spawned by MealsDB_PO_Task_Bridge
 * when a PO is marked received; due +7 days later.
 *
 * Counts are in CASES (matching the PO page's +/- reconcile UI). The handler
 * routes every row through MealsDB_Purchase_Orders::edit_reconcile_row() and
 * then complete_reconcile() — the SAME validated, audited path the PO page
 * uses, so ordered quantities are server-sourced and the note-per-adjusted-row
 * rule is enforced by the service regardless of what the form claimed.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Type_PO_Reconcile {

    public const TYPE_ID = 'po_reconcile';

    public static function register(): void {
        if (!class_exists('MealsDB_Task_Registry')) {
            return;
        }

        MealsDB_Task_Registry::register(self::TYPE_ID, [
            'label'         => __('Reconcile PO', 'meals-db'),
            'description'   => __('Record the actually-received case counts for a purchase order; stock is corrected for any differences.', 'meals-db'),
            'assignee_role' => 'warehouse',
            'urgency'       => MealsDB_Task_Engine::URGENCY_ROUTINE,
            'form_schema'   => [
                ['name' => 'po_number',      'type' => 'text', 'label' => __('PO Number', 'meals-db'), 'readonly' => true],
                ['name' => 'count_received', 'type' => 'yesno', 'required' => true,
                 'label' => __('Have you counted the delivery?', 'meals-db')],
                ['name'       => 'sku_rows',
                 'type'       => 'repeat_group',
                 'label'      => __('Received counts (cases)', 'meals-db'),
                 'items_from' => 'payload.rows',
                 'show_when'  => ['field' => 'count_received', 'equals' => 'yes'],
                 'fields'     => [
                     ['name' => 'sku',            'type' => 'text',   'label' => __('SKU', 'meals-db'), 'readonly' => true],
                     ['name' => 'product_name',   'type' => 'text',   'label' => __('Product', 'meals-db'), 'readonly' => true],
                     ['name' => 'ordered_cases',  'type' => 'number', 'label' => __('Ordered (cases)', 'meals-db'), 'readonly' => true],
                     ['name' => 'received_cases', 'type' => 'number', 'label' => __('Received (cases)', 'meals-db'), 'required' => true, 'min' => 0],
                     ['name' => 'note',           'type' => 'text',   'label' => __('Why does it differ?', 'meals-db'), 'required' => true,
                      'show_when' => ['field' => 'received_cases', 'not_equals_field' => 'ordered_cases']],
                 ]],
                ['name' => 'overall_notes',  'type' => 'textarea', 'label' => __('Overall notes', 'meals-db')],
            ],
            'on_complete'   => [self::class, 'handle_complete'],
        ]);
    }

    public static function handle_complete(array $task, array $form_data, int $completed_by): void {
        $counted = isset($form_data['count_received']) ? (string) $form_data['count_received'] : 'no';
        $po_id   = isset($task['related_entity_id']) ? (int) $task['related_entity_id'] : 0;

        if ($counted !== 'yes') {
            $engine = new MealsDB_Task_Engine();
            $engine->defer_task(
                (int) $task['task_id'],
                gmdate('Y-m-d', strtotime('+1 day')),
                'Auto-deferred: delivery not counted yet.',
                true
            );
            return;
        }

        if ($po_id <= 0) {
            self::degrade($task, 'po_task.reconcile_no_entity', 'Task has no related PO.');
            return;
        }

        $service = new MealsDB_Purchase_Orders();
        $po      = $service->get($po_id);
        $status  = is_array($po) ? (string) ($po['status'] ?? '') : '';
        if ($status === MealsDB_Purchase_Orders::STATUS_RECONCILED) {
            return; // PO page acted first — done, no degraded noise
        }

        // Persist every submitted row into the reconcile session. The service
        // re-derives ordered counts and deltas from ITS payload — the form's
        // readonly ordered_cases is display-only and never trusted.
        $rows = isset($form_data['sku_rows']) && is_array($form_data['sku_rows']) ? $form_data['sku_rows'] : [];
        $row_errors = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku      = isset($row['sku']) ? (string) $row['sku'] : '';
            $received = isset($row['received_cases']) ? (int) $row['received_cases'] : 0;
            $note     = isset($row['note']) ? (string) $row['note'] : '';
            if ($sku === '') {
                continue;
            }
            $result = $service->edit_reconcile_row($po_id, $sku, $received, $note);
            if (is_wp_error($result)) {
                $row_errors[] = $sku . ': ' . $result->get_error_message();
            }
        }

        $result = $service->complete_reconcile($po_id);
        if ($result === true) {
            if (!empty($row_errors)) {
                // Completed, but some rows were refused (e.g. tampered SKU) —
                // surface them; the applied deltas are already audited.
                self::degrade($task, 'po_task.reconcile_partial', implode(' | ', $row_errors));
            }
            return;
        }

        // Refused. Already reconciled (race with the PO page) is success.
        $po     = $service->get($po_id);
        $status = is_array($po) ? (string) ($po['status'] ?? '') : '';
        if ($status === MealsDB_Purchase_Orders::STATUS_RECONCILED) {
            return;
        }

        $message = is_wp_error($result) ? $result->get_error_message() : 'complete_reconcile refused';
        if (!empty($row_errors)) {
            $message .= ' | rows: ' . implode(' | ', $row_errors);
        }
        self::degrade($task, 'po_task.reconcile_failed', sprintf('PO %d status "%s": %s', $po_id, $status, $message));
    }

    /** Post-commit failure surface — see po_confirm_arrival for rationale. */
    private static function degrade(array $task, string $event, string $message): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Task po_reconcile] ' . $message);
        }
        if (class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'task',
                'subsystem' => 'po_task_types',
                'event'     => $event,
                'outcome'   => 'degraded',
                'message'   => $message,
                'context'   => ['task_id' => (int) ($task['task_id'] ?? 0)],
            ]);
        }
    }
}
```

**Note for R-T2 (stale task):** `edit_reconcile_row` on an already-reconciled PO returns a `locked` WP_Error per row, then `complete_reconcile` also refuses; the final status check sees `reconciled` → return without degrading. Verify the test passes on exactly this path (no stock change, no exception).

- [ ] **Step 5.4: Run tests, verify pass**

```bash
php tests/test-po-task-types.php && php tests/test-po-draft-lifecycle.php && php tests/test-po-reconcile-deltas.php
```

- [ ] **Step 5.5: Commit**

```bash
php -l includes/task-types/class-task-type-po-reconcile.php
git add includes/task-types/class-task-type-po-reconcile.php tests/test-po-task-types.php
git commit -m "feat(po): workflow-native po_reconcile task type

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: The bridge + wiring

**Files:**
- Create: `includes/services/class-po-task-bridge.php`
- Modify: `meals-db-main.php` (register the two new types + bridge init, where the legacy registrations were removed)
- Test: `tests/test-po-task-bridge.php` (create)

- [ ] **Step 6.1: Write the failing test file**

Create `tests/test-po-task-bridge.php`. Bootstrap: copy VERBATIM from `tests/test-po-task-types.php` (everything through `forecast_rows()`, including the extended PoWpdb with tasks support and the do_action/add_action stubs — but REPLACE the do_action stub with one that actually dispatches, so the service→bridge loop runs end-to-end):

```php
if (!function_exists('do_action')) {
    function do_action(string $hook, ...$args) {
        $GLOBALS['fired_actions'][] = ['hook' => $hook, 'args' => $args];
        foreach ($GLOBALS['wp_actions'][$hook] ?? [] as $cb) { call_user_func_array($cb, $args); }
    }
}
if (!function_exists('add_action')) {
    function add_action(string $hook, $cb, int $prio = 10, int $accepted = 1) { $GLOBALS['wp_actions'][$hook][] = $cb; }
}
$GLOBALS['fired_actions'] = [];
$GLOBALS['wp_actions']   = [];
```

Header docblock: bridge tests, run with `php tests/test-po-task-bridge.php`. After the bootstrap:

```php
MealsDB_Task_Registry::reset();
MealsDB_Task_Type_PO_Confirm_Arrival::register();
MealsDB_Task_Type_PO_Reconcile::register();
MealsDB_PO_Task_Bridge::init();

function open_of(PoWpdb $w, string $type): array {
    $out = [];
    foreach ($w->tasks as $t) {
        if (($t['task_type'] ?? '') === $type && in_array($t['status'] ?? '', ['pending', 'in_progress', 'deferred'], true)) { $out[] = $t; }
    }
    return $out;
}

// ===========================================================================
// B-1: approve spawns a confirm task due on the expected arrival.
// ===========================================================================
$w = fresh();
$GLOBALS['wc_stock'] = [101 => 50, 102 => 20];
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
$svc->approve($id, '2026-07-24');
$open = open_of($w, 'po_confirm_arrival');
chk(count($open), 1, 'B-1: one confirm task spawned');
chk($open[0]['next_run_date'], '2026-07-24', 'B-1: due on expected arrival');
chk((int) $open[0]['related_entity_id'], $id, 'B-1: linked to the PO');
chk($open[0]['assignee_role'], 'warehouse', 'B-1: warehouse role');

// ===========================================================================
// B-2: approve with NO date → due +7 days; dedup on double-fire.
// ===========================================================================
$w = fresh();
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
$svc->approve($id);
$open = open_of($w, 'po_confirm_arrival');
chk($open[0]['next_run_date'], gmdate('Y-m-d', strtotime('+7 days')), 'B-2: +7 fallback due date');
// Re-fire the hook directly (simulates a duplicate event): no second task.
do_action('mealsdb_po_approved', $id, null);
chk(count(open_of($w, 'po_confirm_arrival')), 1, 'B-2: dedup — still one open task');

// ===========================================================================
// B-3: unapprove skips the open task with the reason; re-approve spawns fresh.
// ===========================================================================
$svc->unapprove($id, 'supplier changed the window');
chk(count(open_of($w, 'po_confirm_arrival')), 0, 'B-3: open task skipped');
$skipped = array_values(array_filter($w->tasks, function ($t) { return ($t['status'] ?? '') === 'skipped'; }));
chk(count($skipped), 1, 'B-3: exactly one skipped');
$svc->approve($id, '2026-07-30');
$open = open_of($w, 'po_confirm_arrival');
chk(count($open), 1, 'B-3: re-approve spawns a fresh task');
chk($open[0]['next_run_date'], '2026-07-30', 'B-3: fresh task uses the new date');

// ===========================================================================
// B-4: receive on the PO page → confirm task auto-closed, reconcile spawned +7.
// ===========================================================================
$GLOBALS['wc_stock'] = [101 => 50, 102 => 20];
$svc->mark_received($id);
chk(count(open_of($w, 'po_confirm_arrival')), 0, 'B-4: confirm task auto-closed');
$rec = open_of($w, 'po_reconcile');
chk(count($rec), 1, 'B-4: reconcile task spawned');
chk($rec[0]['next_run_date'], gmdate('Y-m-d', strtotime('+7 days')), 'B-4: due +7 days');
chk((int) $rec[0]['related_entity_id'], $id, 'B-4: linked');

// ===========================================================================
// B-5: reconcile on the PO page → reconcile task auto-closed.
// ===========================================================================
$svc->edit_reconcile_row($id, 'CD-001', 8, 'Two cases damaged in transit');
$svc->complete_reconcile($id);
chk(count(open_of($w, 'po_reconcile')), 0, 'B-5: reconcile task auto-closed');

// ===========================================================================
// B-6: a throwing engine never breaks the PO action.
// ===========================================================================
class ExplodingTasksWpdb extends PoWpdb {
    public function insert($table, $data, $formats = null) {
        if (strpos($table, 'meals_tasks') !== false) { throw new RuntimeException('boom'); }
        return parent::insert($table, $data, $formats);
    }
}
$w = new ExplodingTasksWpdb();
$GLOBALS['wpdb'] = $w;
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
chk($svc->approve($id, '2026-07-24'), true, 'B-6: approve still succeeds when task spawn explodes');
chk($svc->get_with_payload($id)['status'], 'placed', 'B-6: PO approved despite bridge failure');
```

End with the standard summary/exit block.

- [ ] **Step 6.2: Run to verify failure** — fatal, `MealsDB_PO_Task_Bridge` not found.

- [ ] **Step 6.3: Create the bridge**

Create `includes/services/class-po-task-bridge.php`:

```php
<?php
/**
 * Bridge: PO draft workflow → task system (spec 2026-07-10 task-integration §2).
 *
 * Listens to the mealsdb_po_* lifecycle hooks and keeps the task dashboard in
 * sync: approve spawns a confirm-arrival task, receive closes it and spawns a
 * reconcile task, un-approve/reconcile close whatever is open. The PO service
 * stays task-free; this class is the ONLY place the two systems meet.
 *
 * Auto-close uses skip_task with an explanatory note — never a synthesized
 * complete_task — because the audit log, not the task record, is the system
 * of record for what happened (spec §2 "auto-close rule").
 *
 * Every handler swallows \Throwable (Pattern 7): a task problem must never
 * break the PO action that triggered it. Failures surface as degraded events
 * on the Event Log dashboard.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_PO_Task_Bridge {

    /** Non-terminal task statuses — "still on the dashboard". */
    private const OPEN_STATUSES = ['pending', 'in_progress', 'deferred'];

    /** Fallback confirm-task lag when approval carried no expected arrival. */
    private const DEFAULT_ARRIVAL_LAG_DAYS = 7;

    /** Reconcile-task lag after receiving (mirrors the legacy +7 count lag). */
    private const RECONCILE_LAG_DAYS = 7;

    public static function init(): void {
        add_action('mealsdb_po_approved',   [__CLASS__, 'on_approved'], 10, 2);
        add_action('mealsdb_po_unapproved', [__CLASS__, 'on_unapproved'], 10, 2);
        add_action('mealsdb_po_received',   [__CLASS__, 'on_received'], 10, 1);
        add_action('mealsdb_po_reconciled', [__CLASS__, 'on_reconciled'], 10, 1);
    }

    /** Approve → spawn the confirm-arrival task (deduped by open-task query). */
    public static function on_approved($po_id, $expected_arrival = null): void {
        try {
            $po_id  = (int) $po_id;
            $engine = new MealsDB_Task_Engine();
            if (!empty(self::open_tasks($engine, $po_id, MealsDB_Task_Type_PO_Confirm_Arrival::TYPE_ID))) {
                return; // already queued
            }
            $po = (new MealsDB_Purchase_Orders())->get($po_id);
            if ($po === null) {
                return;
            }
            $due = (is_string($expected_arrival) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected_arrival))
                ? $expected_arrival
                : gmdate('Y-m-d', strtotime('+' . self::DEFAULT_ARRIVAL_LAG_DAYS . ' days'));

            $engine->create_task([
                'task_type'           => MealsDB_Task_Type_PO_Confirm_Arrival::TYPE_ID,
                'payload'             => [
                    'po_number'        => (string) ($po['po_number'] ?? ''),
                    'supplier'         => (string) ($po['supplier'] ?? ''),
                    'expected_arrival' => $due,
                ],
                'next_run_date'       => $due,
                'related_entity_type' => 'po',
                'related_entity_id'   => $po_id,
                'assignee_role'       => 'warehouse',
                'urgency'             => MealsDB_Task_Engine::URGENCY_ROUTINE,
            ]);
        } catch (\Throwable $e) {
            self::degrade('po_bridge.approved_failed', (int) $po_id, $e);
        }
    }

    /** Un-approve → close whatever is open; a later re-approve spawns fresh. */
    public static function on_unapproved($po_id, $reason = ''): void {
        try {
            $po_id  = (int) $po_id;
            $engine = new MealsDB_Task_Engine();
            $note   = 'PO un-approved: ' . (string) $reason;
            foreach ([MealsDB_Task_Type_PO_Confirm_Arrival::TYPE_ID, MealsDB_Task_Type_PO_Reconcile::TYPE_ID] as $type) {
                foreach (self::open_tasks($engine, $po_id, $type) as $task) {
                    $engine->skip_task((int) $task['task_id'], $note);
                }
            }
        } catch (\Throwable $e) {
            self::degrade('po_bridge.unapproved_failed', (int) $po_id, $e);
        }
    }

    /** Receive → close the confirm task, spawn the reconcile task. */
    public static function on_received($po_id): void {
        try {
            $po_id  = (int) $po_id;
            $engine = new MealsDB_Task_Engine();
            $po     = (new MealsDB_Purchase_Orders())->get_with_payload($po_id);
            if ($po === null) {
                return;
            }
            $note = sprintf('Done on the PO page (received, PO %s).', (string) ($po['po_number'] ?? $po_id));
            foreach (self::open_tasks($engine, $po_id, MealsDB_Task_Type_PO_Confirm_Arrival::TYPE_ID) as $task) {
                $engine->skip_task((int) $task['task_id'], $note);
            }

            if (!empty(self::open_tasks($engine, $po_id, MealsDB_Task_Type_PO_Reconcile::TYPE_ID))) {
                return; // reconcile already queued
            }
            // Rows in CASES for the task form, from the workflow payload
            // (ordered rows only — zero-case rows were never ordered).
            $rows = [];
            if (is_array($po['payload'])) {
                foreach ($po['payload']['current'] as $row) {
                    $cases = (int) ($row['cases'] ?? 0);
                    if ($cases <= 0) {
                        continue;
                    }
                    $rows[] = [
                        'sku'           => (string) $row['sku'],
                        'product_name'  => (string) ($row['product_name'] ?? ''),
                        'ordered_cases' => $cases,
                    ];
                }
            }
            $engine->create_task([
                'task_type'           => MealsDB_Task_Type_PO_Reconcile::TYPE_ID,
                'payload'             => [
                    'po_number' => (string) ($po['po_number'] ?? ''),
                    'rows'      => $rows,
                ],
                'next_run_date'       => gmdate('Y-m-d', strtotime('+' . self::RECONCILE_LAG_DAYS . ' days')),
                'related_entity_type' => 'po',
                'related_entity_id'   => $po_id,
                'assignee_role'       => 'warehouse',
                'urgency'             => MealsDB_Task_Engine::URGENCY_ROUTINE,
            ]);
        } catch (\Throwable $e) {
            self::degrade('po_bridge.received_failed', (int) $po_id, $e);
        }
    }

    /** Reconciled → close the reconcile task. */
    public static function on_reconciled($po_id): void {
        try {
            $po_id  = (int) $po_id;
            $engine = new MealsDB_Task_Engine();
            $po     = (new MealsDB_Purchase_Orders())->get($po_id);
            $note   = sprintf('Done on the PO page (reconciled, PO %s).', (string) ($po['po_number'] ?? $po_id));
            foreach (self::open_tasks($engine, $po_id, MealsDB_Task_Type_PO_Reconcile::TYPE_ID) as $task) {
                $engine->skip_task((int) $task['task_id'], $note);
            }
        } catch (\Throwable $e) {
            self::degrade('po_bridge.reconciled_failed', (int) $po_id, $e);
        }
    }

    /** @return array<int, array<string, mixed>> open tasks of $type linked to the PO */
    private static function open_tasks(MealsDB_Task_Engine $engine, int $po_id, string $type): array {
        return $engine->query_tasks([
            'task_type'           => $type,
            'related_entity_type' => 'po',
            'related_entity_id'   => $po_id,
            'status'              => self::OPEN_STATUSES,
        ]);
    }

    private static function degrade(string $event, int $po_id, \Throwable $e): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB PO Task Bridge] ' . $event . ': ' . $e->getMessage());
        }
        if (class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'error',
                'category'  => 'task',
                'subsystem' => 'po_task_bridge',
                'event'     => $event,
                'outcome'   => 'degraded',
                'message'   => $e->getMessage(),
                'context'   => ['po_id' => $po_id],
            ]);
        }
    }
}
```

- [ ] **Step 6.4: Wire up in `meals-db-main.php`**

Where the three legacy registrations were removed (after `MealsDB_Task_Type_Call_Client::register();`), add:

```php
    MealsDB_Task_Type_PO_Confirm_Arrival::register();
    MealsDB_Task_Type_PO_Reconcile::register();
```

And after `MealsDB_Task_Cron::init();`:

```php
    MealsDB_PO_Task_Bridge::init();
```

- [ ] **Step 6.5: Run all four PO test files, verify pass**

```bash
php tests/test-po-task-bridge.php && php tests/test-po-task-types.php \
  && php tests/test-po-draft-lifecycle.php && php tests/test-po-reconcile-deltas.php
```

- [ ] **Step 6.6: Commit**

```bash
php -l includes/services/class-po-task-bridge.php && php -l meals-db-main.php
git add includes/services/class-po-task-bridge.php meals-db-main.php tests/test-po-task-bridge.php
git commit -m "feat(po): task bridge — spawn/auto-close warehouse tasks from PO lifecycle hooks

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: Expected-arrival capture at approval (AJAX + UI)

**Files:**
- Modify: `includes/ajax/class-ajax-purchase-orders.php` (transition_endpoint)
- Modify: `views/purchase-orders.php` (detail actions + i18n)
- Modify: `assets/js/purchase-orders.js` (approve click path)

- [ ] **Step 7.1: AJAX — pass the date through**

In `transition_endpoint()`, replace the `if ($method === 'unapprove') { … } else { … }` dispatch with:

```php
            if ($method === 'unapprove') {
                $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));
                $result = $service->unapprove($po_id, $reason);
            } elseif ($method === 'approve') {
                // Optional expected-arrival date for the confirm-arrival task's
                // due date; the service normalizes (malformed → null → the
                // bridge falls back to +7 days).
                $expected = sanitize_text_field(wp_unslash($_POST['expected_arrival'] ?? ''));
                $result   = $service->approve($po_id, $expected !== '' ? $expected : null);
            } else {
                $result = $service->{$method}($po_id);
            }
```

- [ ] **Step 7.2: View — date input on the detail page + i18n key**

In `views/purchase-orders.php`, in the detail actions block, replace the draft-status branch:

```php
                <?php if ($status === MealsDB_Purchase_Orders::STATUS_PLANNED): ?>
                    <button type="button" class="button button-primary mealsdb-po-action" data-po-action="approve" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Approve', 'meals-db'); ?></button>
                    <button type="button" class="button mealsdb-po-action" data-po-action="cancel" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Cancel draft', 'meals-db'); ?></button>
```

with:

```php
                <?php if ($status === MealsDB_Purchase_Orders::STATUS_PLANNED): ?>
                    <label for="mealsdb-po-expected-arrival"><?php esc_html_e('Expected arrival:', 'meals-db'); ?></label>
                    <input type="date" id="mealsdb-po-expected-arrival"
                        value="<?php echo esc_attr(gmdate('Y-m-d', strtotime('+7 days'))); ?>" />
                    <button type="button" class="button button-primary mealsdb-po-action" data-po-action="approve" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Approve', 'meals-db'); ?></button>
                    <button type="button" class="button mealsdb-po-action" data-po-action="cancel" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Cancel draft', 'meals-db'); ?></button>
```

In the island's `i18n` array add:

```php
            'promptExpectedArrival' => __('Expected arrival date (YYYY-MM-DD) — OK approves:', 'meals-db'),
```

- [ ] **Step 7.3: JS — capture the date on approve**

In `assets/js/purchase-orders.js`, inside the `.mealsdb-po-action` click handler, replace:

```js
        if (kind === 'unapprove') {
```

and its preceding `var data = …` line so the block reads:

```js
        var data = { nonce: cfg.nonce, po_id: parseInt($btn.data('po-id'), 10), action: map.action };
        if (kind === 'approve') {
            var $arrival = $('#mealsdb-po-expected-arrival');
            if ($arrival.length) {
                // Detail page: date input + the normal confirm dialog.
                if (!window.confirm(map.confirm)) { return; }
                data.expected_arrival = String($arrival.val() || '');
            } else {
                // List page: one prefilled prompt doubles as the confirm —
                // Cancel aborts the approval entirely.
                var dflt = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
                var picked = window.prompt(t('promptExpectedArrival', 'Expected arrival date (YYYY-MM-DD) — OK approves:'), dflt);
                if (picked === null) { return; }
                data.expected_arrival = picked;
            }
        } else if (kind === 'unapprove') {
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
```

(This replaces the existing `if (kind === 'unapprove') {...} else if (!window.confirm(map.confirm)) {...}` chain — approve no longer falls through to the generic confirm branch.)

- [ ] **Step 7.4: Verify + commit**

```bash
php -l includes/ajax/class-ajax-purchase-orders.php && php -l views/purchase-orders.php
node --check assets/js/purchase-orders.js
git add includes/ajax/class-ajax-purchase-orders.php views/purchase-orders.php assets/js/purchase-orders.js
git commit -m "feat(po): capture expected-arrival date at approval

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: Task-detail deep link to the PO page

**Files:**
- Modify: `views/task-detail.php` (~line 46-49)

- [ ] **Step 8.1: Replace the related-entity row**

Replace:

```php
            <?php if (!empty($task['related_entity_type'])): ?>
                <tr><th><?php esc_html_e('Related', 'meals-db'); ?></th>
                    <td><?php echo esc_html(sprintf('%s #%d', $task['related_entity_type'], (int) $task['related_entity_id'])); ?></td></tr>
            <?php endif; ?>
```

with:

```php
            <?php if (!empty($task['related_entity_type'])): ?>
                <tr><th><?php esc_html_e('Related', 'meals-db'); ?></th>
                    <td><?php
                    $mealsdb_rel_label = sprintf('%s #%d', $task['related_entity_type'], (int) $task['related_entity_id']);
                    if ($task['related_entity_type'] === 'po' && (int) $task['related_entity_id'] > 0) {
                        // Deep link to the PO workflow page (task-integration §6).
                        $mealsdb_po_url = admin_url('admin.php?page=mealsdb&tab=po_admin&po_id=' . (int) $task['related_entity_id']);
                        echo '<a href="' . esc_url($mealsdb_po_url) . '">' . esc_html($mealsdb_rel_label) . '</a>';
                    } else {
                        echo esc_html($mealsdb_rel_label);
                    }
                    ?></td></tr>
            <?php endif; ?>
```

- [ ] **Step 8.2: Verify + commit**

```bash
php -l views/task-detail.php
git add views/task-detail.php
git commit -m "feat(tasks): link po-related tasks to the PO workflow page

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 9: Full verification

- [ ] **Step 9.1: Full suite**

```bash
fails=0; for f in tests/test-*.php; do php "$f" >/dev/null 2>&1 || { echo "FAIL: $f"; fails=$((fails+1)); }; done; echo "$fails failing files"
```
Expected: only the 2 known-baseline PDF failures.

- [ ] **Step 9.2: Lint every touched PHP file; reference sweep**

```bash
for f in includes/services/class-purchase-orders.php includes/services/class-po-task-bridge.php \
         includes/task-types/class-task-type-po-confirm-arrival.php includes/task-types/class-task-type-po-reconcile.php \
         includes/ajax/class-ajax-purchase-orders.php views/purchase-orders.php views/task-detail.php meals-db-main.php; do php -l "$f" || break; done
grep -rn "Place_PO\|Confirm_PO_Arrival\|Physical_Count" --include=*.php . | grep -v docs/ | grep -v "PO_Confirm_Arrival"
```
Expected: 8 clean lints; the grep prints NOTHING (the new class name contains `PO_Confirm_Arrival` — excluded by the last grep -v; anything else surfacing is a missed reference to fix).

- [ ] **Step 9.3: Final whole-implementation review, then finishing-a-development-branch**

Use superpowers:verification-before-completion, then superpowers:finishing-a-development-branch (repo convention: PR to `main`).

---

## Self-review notes (already applied)

- **Spec coverage:** §1 hooks/signatures → Task 3; §2 bridge → Task 6; §3a/§3b task types → Tasks 4–5; §4 legacy removal + statics → Tasks 1–2; §5 expected-arrival capture → Task 7; §6 cross-links → Task 8; §7 testing → Tasks 3–6 + 9.
- **Type consistency:** `MealsDB_Task_Type_PO_Confirm_Arrival::TYPE_ID = 'po_confirm_arrival'`, `MealsDB_Task_Type_PO_Reconcile::TYPE_ID = 'po_reconcile'` used identically in bridge, tests, and registrations. `approve(int, ?string)`, `mark_received(int, ?string)` signatures match all call sites (AJAX passes null-able strings; task handler passes form value or null).
- **Ordering note:** Task 1 duplicates the two statics for exactly one commit (legacy classes retain theirs until Task 2 deletes them) — deliberate, keeps every commit green.
- **B-6 note:** the bridge catches the engine's `\Throwable` — but `create_task` may itself swallow DB errors and return 0; the test throws from the stub's `insert()` to exercise the catch path deterministically.
