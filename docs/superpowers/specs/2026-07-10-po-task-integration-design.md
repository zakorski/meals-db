# PO Workflow ↔ Task System Integration — Design

**Date:** 2026-07-10
**Status:** Approved approach (Approach A), pending spec review
**Prerequisite:** PR #461 (PO draft workflow) — merged.

**Goal:** Tie the task system to the new PO draft workflow so PO work shows up on the task dashboard, with **either side able to act**: completing a task performs the workflow action, and performing the action on the PO page closes the linked task. The three legacy PO task types are removed (never used in production).

**Out of scope:**
- Draft-nag tasks (reminders for unapproved drafts). Not requested; add later if wanted.
- Task-rule (cron) spawning for POs — all spawning here is event-driven.
- Any change to non-PO task types, the task engine core, the task rules system, or the nightly task cron.

---

## Background / current state

- **PO workflow** (`MealsDB_Purchase_Orders`, PR #461): `planned`=Draft → `placed`=Approved → `arrived`=Received → `reconciled`, guarded transitions (`WHERE status='<from>'`, exactly-once side-effects), payload with `generated/current/received` rows. `expected_arrival` is never set (header fields fixed at generation). Inventory side-effects currently delegate to two statics on legacy task-type classes.
- **Task system**: `MealsDB_Task_Engine::create_task()` (fields: task_type, payload, next_run_date, related_entity_type/id, parent_task_id, assignee_role, urgency, spawn_key), statuses pending/in_progress/deferred + terminal completed/skipped/abandoned, `complete_task()` validates form_data against the registered `form_schema` then fires `on_complete` AFTER commit (errors caught, not re-thrown), `defer_task(..., allow_from_terminal: true)` lets a handler reverse a just-committed completion (the legacy arrived=no pattern). `MealsDB_Task_Registry::register()` defines label/role/urgency/form_schema/callbacks; form schema supports `show_when` conditionals including `not_equals_field`, and required-validation is skipped for hidden fields. Task AJAX: nonce `mealsdb_nonce`, baseline capability, `task_modify` bucket (100/hr).
- **Legacy PO task chain** (`place_po` → `confirm_po_arrival` → `physical_count`): drives old-style `payload IS NULL` POs. **Never used in production** (operator) — to be deleted.
- Task detail renders `related_entity_type='po'` as plain text `po #N` (no link). The PO detail page already lists related tasks.

---

## 1. Lifecycle hooks (PO service)

`MealsDB_Purchase_Orders` emits a WordPress action after each successful transition (after the guarded UPDATE **and** the audit-log write, guarded by `function_exists('do_action')` for test contexts):

| Hook | Args | Fired by |
|---|---|---|
| `mealsdb_po_approved` | `int $po_id, ?string $expected_arrival` | `approve()` |
| `mealsdb_po_unapproved` | `int $po_id, string $reason` | `unapprove()` |
| `mealsdb_po_received` | `int $po_id` | `mark_received()` |
| `mealsdb_po_reconciled` | `int $po_id` | `complete_reconcile()` |

No hook for draft-created or cancelled (no task exists at those points). The service remains task-free — it knows nothing about the task engine.

### Service signature changes

- `approve(int $po_id, ?string $expected_arrival = null)` — new optional param, normalized via the existing `normalize_date()` (malformed → null); stored in the existing `expected_arrival` column inside the same guarded transition. Passed through to the hook.
- `unapprove()` — additionally clears `expected_arrival` (symmetry: it was set at approval).
- `mark_received(int $po_id, ?string $arrival_date = null)` — new optional param so a task completed late can record the true arrival date; defaults to today (UTC) as now. Stored in `arrival_date` within the transition.

No schema change — `expected_arrival` and `arrival_date` columns already exist.

---

## 2. The bridge (`includes/services/class-po-task-bridge.php`)

New static class `MealsDB_PO_Task_Bridge`, `init()` called from `meals-db-main.php` (plugins_loaded, after task types register). Listens to the four hooks. **Every handler wraps its body in `catch (\Throwable)` → `MealsDB_Logger::error` + `MealsDB_Event_Log::record(outcome:'degraded', category:'task', subsystem:'po_task_bridge', …)`** — a task-spawn failure must never break the PO action that triggered it (Pattern 7), but it must surface on the Event Log dashboard.

| Hook | Bridge behavior |
|---|---|
| `mealsdb_po_approved` | If an **open** (non-terminal) `po_confirm_arrival` task already exists for this PO → do nothing (dedup by query, not spawn_key — these are event spawns, spawn_key stays NULL). Else `create_task`: type `po_confirm_arrival`, `next_run_date` = `$expected_arrival` ?: today+7 days, `related_entity_type` 'po' / `related_entity_id` $po_id, `assignee_role` 'warehouse', urgency routine, payload prefill `{po_number, supplier, expected_arrival}`. |
| `mealsdb_po_unapproved` | Query open `po_confirm_arrival` + `po_reconcile` tasks for this PO → `skip_task` each with note `PO un-approved: <reason>`. A later re-approval spawns a fresh task. |
| `mealsdb_po_received` | Auto-close any open `po_confirm_arrival` task (see auto-close rule below). Then, if no open `po_reconcile` task exists, `create_task`: type `po_reconcile`, due today+7 days (mirrors the legacy physical_count lag), warehouse, related po, payload prefill `{po_number, rows: [{sku, product_name, ordered_cases}]}` from the PO payload's `current` rows with cases > 0. |
| `mealsdb_po_reconciled` | Auto-close any open `po_reconcile` task. |

**Auto-close rule:** when the PO page performs the action first, the bridge closes the counterpart task via **`skip_task` with note "Done on the PO page (<action>, PO <po_number>)"** — not `complete_task`. Rationale: completing would require synthesizing valid form data against the task's form_schema (fragile, especially for the reconcile repeat-group); skipping is terminal, honest ("this task's work happened elsewhere"), and the audit log — not the task record — is the system of record for what happened. One mechanism for both task types.

---

## 3. Two workflow-native task types

Both registered in `meals-db-main.php` where the three legacy registrations are removed. Both handlers are **idempotent against the PO status** — the guarded transitions make "already done" detectable, so either-side actuation cannot double-apply side-effects.

### 3a. `po_confirm_arrival` (`includes/task-types/class-task-type-po-confirm-arrival.php`)

- **Register:** label "Confirm PO Arrival", assignee_role `warehouse`, urgency routine.
- **Form:** `po_number` (text, readonly) · `supplier` (text, readonly) · `expected_arrival` (date, readonly) · `arrived` (yesno, required) · `arrival_date` (date, show_when arrived=yes, required-when-shown) · `notes` (textarea, optional).
- **on_complete:**
  - `arrived !== 'yes'` → `defer_task(+1 day, allow_from_terminal: true)` — the legacy auto-defer pattern, unchanged.
  - `arrived === 'yes'` → load the PO; by status:
    - `placed` → `mark_received($po_id, $arrival_date)`. On `WP_Error('race')` re-check status (someone else just did it) → treat as done.
    - `arrived` / `reconciled` → already done (the PO page acted first and the auto-skip lost a race, or an operator completed a stale task) → success, no-op; log an info-level event.
    - anything else (draft/cancelled/missing/legacy) → the task is stale → `MealsDB_Logger::error` + Event_Log `degraded` (`event: 'po_task.stale_confirm'`). The task stays completed (the engine already committed); the degraded event is the operator's signal.

### 3b. `po_reconcile` (`includes/task-types/class-task-type-po-reconcile.php`)

- **Register:** label "Reconcile PO", assignee_role `warehouse`, urgency routine.
- **Form:** `po_number` (text, readonly) · `count_received` (yesno, required — "Have you counted the delivery?") · `sku_rows` (repeat_group, show_when count_received=yes) with per-row: `sku` (readonly) · `product_name` (readonly) · `ordered_cases` (number, readonly) · `received_cases` (number, required, min 0) · `note` (text, **required**, show_when received_cases `not_equals_field` ordered_cases — the registry skips required-validation while hidden, enforces it when shown) · plus `overall_notes` (textarea, optional). Rows prefilled from the task payload. **Counts are in CASES**, matching the PO page's +/- UI.
- **on_complete:**
  - `count_received !== 'yes'` → defer +1 day (allow_from_terminal).
  - Else → load the PO; by status:
    - `arrived` → for each row where `received_cases != ordered_cases`: `edit_reconcile_row($po_id, $sku, $received_cases, $note)`; then `complete_reconcile($po_id)`. The service's notes-required validation is the backstop behind the form's; a `notes_required`/`race` error → Event_Log `degraded` (`event: 'po_task.reconcile_failed'`) with the error message — the operator finishes on the PO page.
    - `reconciled` → already done → no-op success.
    - anything else → stale → degraded event, as above.

Completing the reconcile task and reconciling on the PO page produce **identical** audit rows (`po_reconcile_edit`, `po_reconciled`, per-SKU `inventory_discrepancy` with notes) because both go through the same service methods.

---

## 4. Legacy removal + inventory helpers relocation

- **Delete** `includes/task-types/class-task-type-place-po.php`, `class-task-type-confirm-po-arrival.php`, `class-task-type-physical-count.php` and their three `::register()` calls in `meals-db-main.php`. (Operator: never used in production. Any stray dev tasks of those types render read-only with the registry's unknown-type fallback label; they cannot be completed — acceptable.)
- **Move** `apply_inventory_bump(array $items)` and `apply_adjustments(int $po_id, array $adjustments)` into `MealsDB_Purchase_Orders` as public static methods — code, audit actions (`po_inventory_bump`, `inventory_discrepancy`), and behavior byte-identical. Update the two workflow call sites (`mark_received`, `complete_reconcile`) from `MealsDB_Task_Type_*::` to `self::`. This removes the workflow→task-type cross-dependency flagged in the last track's reviews.
- **Tests:** `tests/test-task-workflow-po-chain.php` (tests the deleted chain) is removed and replaced by the new bridge/task-type tests (§7). `test-po-draft-lifecycle.php` / `test-po-reconcile-deltas.php` keep passing unchanged — they exercise the service, whose stock behavior is unmoved.
- Grep-and-fix any other references to the three deleted classes (plan-time verification step).

---

## 5. Expected-arrival capture at approval

- **AJAX:** `mealsdb_po_approve` gains an optional `expected_arrival` param (validated `YYYY-MM-DD`; missing/malformed → null → bridge falls back to +7 days for the task due date).
- **Detail page:** a date input (`#mealsdb-po-expected-arrival`, prefilled today+7) rendered next to the Approve button in draft mode; JS includes its value in the approve POST.
- **List page:** the Approve button prompts (`window.prompt`) prefilled with today+7 in `YYYY-MM-DD`; cancel aborts the approval; cleared/invalid input → approval proceeds with null. Mirrors the un-approve reason prompt UX.
- Stored in the existing `expected_arrival` column; shown by the existing detail row and list. Cleared on un-approve.

---

## 6. UI cross-links

- **Task detail** (`views/task-detail.php`): when `related_entity_type === 'po'`, render the entity as a link to `admin.php?page=mealsdb&tab=po_admin&po_id=N` instead of plain text. Other entity types unchanged.
- **PO detail:** the Related Tasks panel already exists and lists linked tasks with Open buttons — no change needed; workflow POs will now actually have related tasks.

---

## 7. Testing

Standalone scripts, same stub conventions as the PO workflow tests:

- **`tests/test-po-task-bridge.php`** — with an in-memory wpdb capturing `meals_tasks` + `meals_purchase_orders`: approve fires hook → task created with correct type/due date (expected_arrival vs +7 fallback)/related entity/payload; second approve-cycle dedup (open task exists → no duplicate); unapprove → open tasks skipped with reason note; received → confirm task auto-skipped + reconcile task spawned (due +7); reconciled → reconcile task auto-skipped; bridge handler swallows a throwing engine (`degraded` event recorded, no exception escapes).
- **`tests/test-po-task-types.php`** — handler idempotency matrix: confirm on placed → stock bumped once; confirm on already-arrived → no second bump, success; arrived=no → deferred +1 day; reconcile happy path through the service (deltas + audit notes); reconcile on already-reconciled → no-op; stale-status paths emit degraded events.
- **`tests/test-po-draft-lifecycle.php` / `test-po-reconcile-deltas.php`** — updated only where the statics moved (assertions unchanged); must stay green.
- Delete `tests/test-task-workflow-po-chain.php`.

---

## 8. Decisions log

| Decision | Choice | Why |
|---|---|---|
| Authority model | Either side actuates | Operator decision. Safe because every side-effect sits behind a guarded status transition — the second actor sees "already done" |
| Auto-close mechanism | `skip_task` with explanatory note, never synthesized `complete_task` | No fragile form-data synthesis; task record is a to-do list, the audit log is the system of record |
| Arrival date capture | Optional input at approval, default +7 days | Operator decision |
| Legacy chain | All three task types deleted | Operator: never used in production |
| Inventory statics | Moved into `MealsDB_Purchase_Orders` | The deleted classes owned them; removes the workflow→task-type dependency |
| Spawn mechanism | Event hooks + bridge class (Approach A) | Instant task visibility; service stays task-free; each side testable alone |
| Dedup | Query for open task before spawn (spawn_key stays NULL) | spawn_key is the rule-spawn mechanism; re-approval after un-approve must spawn a fresh task, which a deterministic key would block |
| Task failure isolation | Bridge + handlers catch `\Throwable`, log degraded, never propagate | Pattern 7; a task problem must not break checkout-path PO actions |
| Reconcile units | Cases (not units) in the task form | Matches the PO page +/- UI and `edit_reconcile_row()` |
