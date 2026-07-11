# PO Draft Workflow + Case Adjustment UI — Design

**Date:** 2026-07-10
**Status:** Approved approach (Approach A), pending spec review
**Scope:** Two features for the Purchase Order subsystem:
1. A draft/list/approve lifecycle for generated purchase orders, mirroring the Government Invoice Generator's look, feel, and safety model.
2. +/- case adjustment buttons in the PO edit view (one case per click) with coverage-week warnings: yellow `!` below the 9-week target, red `!` below the 7-week safety floor.

**Out of scope (explicit):**
- The task system (`place_po`, `confirm_po_arrival`, `physical_count` task types and their spawn rules) is NOT touched. Tying tasks to the new system is a separate development track immediately following this one. Where the new code would otherwise interact with task hooks, it does not call them; nothing new spawns tasks.
- No SDNB/VAC pricing or billing changes.
- No un-receive action (see Lifecycle).

---

## Background / current state

- `meals_purchase_orders` already exists (`class-purchase-orders.php` service): `po_id`, `po_number` (UNIQUE NOT NULL), `supplier`, `placed_date`, `expected_arrival`, `arrival_date`, `status ENUM('planned','placed','arrived','counted','reconciled','cancelled')`, `items` JSON (`[{sku, product_name, quantity_ordered}]`, quantities in UNITS), `notes`, `reconciled_at`, timestamps.
- The forecast generator (`MealsDB_Reports::generate_purchase_order()`, Purchase Order tab `?tab=po`, `views/purchase-order.php` + `assets/js/purchase-order.js`) is generate-and-display only. Nothing persists. The optional pallet-optimization pass (`optimize_po_for_pallets()`) returns a sibling `optimized` row set.
- Today persistence happens only via the `place_po` task (operator retypes items as CSV → PO created as `placed`), then `confirm_po_arrival` (status `arrived` + WC stock bump) and `physical_count` (received-vs-ordered deltas + status `reconciled`).
- The read-only PO list lives at `?tab=po_admin` → `views/purchase-orders.php` (`class-admin-ui.php:1054`).
- Forecast model: 9 weeks coverage target (6-week horizon + 3-week buffer, `class-reports.php:149`); pallet optimizer enforces a 7-week floor (`:681`) and 52-week ceiling (`:647`). Coverage for a row = (current_stock + order_quantity) / adjusted_weekly. These are the yellow/red thresholds.
- The invoice-draft system to mirror: `meals_invoice_drafts` with `generated` (immutable) + `current` (editable) payload copies, list view with filters + per-row actions, per-field AJAX edits with audit, finalize/un-finalize (reason required, audited), guarded UPDATEs for race safety. Files: `class-invoice-draft.php`, `class-invoice-draft-page.php`, `class-ajax-invoice-draft.php`, `invoice-draft.css/js`.

---

## 1. Data model (additive only — no ALTERs)

`MealsDB_Schema_Sync` only ADDS tables/columns; it never modifies existing ones (CLAUDE.md Pattern 9). The design therefore adds columns and reuses the existing status ENUM values with friendly display labels. **No ENUM change, no hand-written ALTER.**

### Status mapping

| DB value (`status`) | UI label | Meaning in the new workflow |
|---|---|---|
| `planned` | **Draft** | Editable forecast draft (currently unused by the task flow — `place_po` creates POs as `placed`) |
| `placed` | **Approved** | Locked; no edits without un-approve |
| `arrived` | **Received** | Stock bumped by ordered quantities |
| `reconciled` | **Reconciled** | Received counts confirmed/adjusted; deltas applied |
| `cancelled` | **Cancelled** | Abandoned draft |
| `counted` | (legacy) | Not used by the new workflow; displayed as-is if present |

### New columns on `meals_purchase_orders` (all nullable/defaulted)

| Column | Type | Purpose |
|---|---|---|
| `payload` | LONGTEXT NULL | Plain JSON (no PII → no encryption, deliberate divergence from invoice drafts): `{schema: 1, generated: [...], current: [...], received: [...]}` |
| `edit_count` | INT UNSIGNED NOT NULL DEFAULT 0 | Bumped per actual case change (draft or reconcile edit) |
| `created_by` | BIGINT UNSIGNED NULL | WP user ID |
| `approved_by` | BIGINT UNSIGNED NULL | |
| `approved_at` | DATETIME NULL | UTC (`gmdate`) |
| `received_by` | BIGINT UNSIGNED NULL | |
| `received_at` | DATETIME NULL | UTC |
| `reconciled_by` | BIGINT UNSIGNED NULL | (`reconciled_at` already exists) |

### Payload row shape (`generated[]` and `current[]` are arrays of row objects; `sku` is the identifying field)

Each row snapshots the full forecast context at generation time so warnings are deterministic and the draft is self-contained:

```json
{
  "sku": "CD-001",
  "product_name": "Chicken Dinner",
  "case_size": 6,
  "cases": 8,
  "order_quantity": 48,
  "adjusted_weekly": 11.25,
  "current_stock": 40,
  "seasonal_index": 1.1,
  "freight_delta_cases": 0,
  "seasonal_note": ""
}
```

- `generated` = immutable snapshot of what the forecast produced (base or pallet-optimized, whichever the operator saved).
- `current` = editable working copy; only `cases` (and derived `order_quantity = cases × case_size`) ever differ from `generated`.
- `received` = reconcile-in-progress rows: `[{sku, received_cases, note}]`. Persisted per edit so a half-done reconcile survives navigation; **no stock effect until Complete reconciliation**.
- `current_stock` is the WC stock snapshot at generation; warnings use the snapshot, not live stock (deterministic, no drift mid-edit-session).

### Legacy PO detection

`payload IS NULL` ⇒ task-created legacy PO. These render in the list with their status label and a Review (read-only) link ONLY — no new action buttons. Their lifecycle stays with the task chain, which prevents double inventory bumps (a task completing `confirm_po_arrival` AND an operator clicking "Mark received" on the same PO).

### PO number & header fields

Auto-generated at draft save: `PO-{Ymd}-{His}` (UTC), with the existing UNIQUE constraint as collision backstop (on duplicate-key failure, retry once with a `-2` suffix; then surface the error). Supplier defaults to `'Apetito'`. `expected_arrival` left NULL (header fields are not editable — "cases only" decision). `placed_date` is set at approval time, not draft time.

---

## 2. Lifecycle & side-effects

All transitions are **guarded UPDATEs** (`WHERE status = '<expected>'` in the SQL) so concurrent requests race safely — the second request affects 0 rows and returns an error, mirroring invoice finalize. All timestamps UTC. All transitions audit-logged via `MealsDB_Logger::log()` (committed data changes → audit log, per STR-LOG boundary).

| Transition | Trigger | Guard | Side-effects | Audit action |
|---|---|---|---|---|
| (none) → `planned` | "Save as draft" on forecast tab | — | INSERT with payload (generated = current), auto po_number | `po_draft_created` |
| `planned` case edit | +/- in detail view (debounced AJAX per row) | status = planned; SKU must exist in payload; cases int 0–10000 | Update `current[sku].cases/order_quantity`; bump `edit_count` if changed | `po_draft_edit` (old→new, only on actual change) |
| `planned` → `placed` (Approve) | List or detail button + confirm | status = planned | Set `approved_by/at`, `placed_date` (today UTC); write final `items` JSON from `current` (`quantity_ordered` in UNITS = cases × case_size) for compatibility with all existing readers of `items` | `po_approved` |
| `placed` → `planned` (Un-approve) | Button + typed reason (required, non-empty) | status = placed (i.e. NOT yet received) | Clear `approved_by/at`; `items` left stale (overwritten at next approve) | `po_unapproved` + reason |
| `placed` → `arrived` (Mark received) | Button + confirm | status = placed | Set `received_by/at`, `arrival_date`; then `MealsDB_Task_Type_Confirm_PO_Arrival::apply_inventory_bump($items)` — existing static, atomic per-product stock increments, per-product `po_inventory_bump` audit rows. Reusing the static keeps ONE inventory-bump implementation without touching the task system. | `po_received` (+ per-product rows from the static) |
| `arrived` reconcile edit | +/- on received cases in reconcile mode | status = arrived; SKU in payload; received_cases int 0–10000 | Update `payload.received[sku]` (+ note); bump `edit_count`. NO stock effect. | `po_reconcile_edit` |
| `arrived` → `reconciled` (Complete reconciliation) | Button + confirm | status = arrived; **every row where received_cases ≠ ordered cases has a non-empty note (server-validated)** | Build `[{sku, actual_count (units = received_cases × case_size), reason: 'po_reconcile', reason_notes: note}]` and call `MealsDB_Task_Type_Physical_Count::apply_adjustments($po_id, $adjustments)` — existing static: server-sources ordered quantities from the stored PO, rejects SKUs not on the PO, applies deltas, per-SKU `inventory_discrepancy` audit rows including notes. Set `reconciled_by`, `reconciled_at`. | `po_reconciled` (+ per-SKU rows from the static) |
| `planned` → `cancelled` (Cancel draft) | Button + confirm | status = planned | — | `po_draft_cancelled` |

**Deliberately one-way past `arrived`:** there is no un-receive. Receiving bumps stock; correcting what actually arrived is exactly what reconcile is for. Un-approve is only available in the `placed` window.

**Task system interaction:** none. Approval does NOT spawn `confirm_po_arrival`; nothing in the new code calls the task engine. The two static helpers reused (`apply_inventory_bump`, `apply_adjustments`) are pure inventory functions that happen to live on task-type classes; calling them does not create, complete, or modify tasks. Legacy task-driven POs continue working unchanged.

---

## 3. UI

### 3.1 List view (`?page=…&tab=po_admin`, `views/purchase-orders.php` rebuilt)

Invoice-list style: `table.widefat.striped`, plain-text status labels, per-row action links, GET filter for status.

Columns: PO # | Supplier | Status | Cases (total, + pallets where relevant) | Rows | Edits | Created by / at (UTC) | Approved at | Received at | Actions.

Actions by status (new-workflow POs only):
- **Draft:** Review · Approve · Cancel
- **Approved:** Review · Mark received · Un-approve
- **Received:** Review · Reconcile
- **Reconciled / Cancelled:** Review (read-only)
- **Legacy (payload IS NULL):** Review (read-only) only, any status.

The detail portion of the current `views/purchase-orders.php` (read-only items table for task POs) is preserved as the legacy/read-only render path.

### 3.2 Detail view — draft mode (status `planned`)

Table columns: SKU | Product | Adj/Wk | Stock (snapshot) | Case size | **Cases** | Order qty | Coverage | Forecast note (the row's `seasonal_note` — distinct from reconcile notes).

- **Cases cell:** `[−] [ n ] [+]` — buttons adjust one case per click, clamped at 0. The numeric display is not a free-text input (buttons only, per "cases only" decision). Clicks are debounced ~600 ms per row, then one AJAX save posts the row's final value. On save success: `edit_count` refresh, `was: N` hint shown when `current ≠ generated` (invoice `.mealsdb-draft-was` pattern), brief `.mealsdb-recomputed` flash. On error: revert to last-saved value, show notice.
- **Coverage cell:** `(current_stock + cases × case_size) / adjusted_weekly`, recomputed in JS on every click for instant feedback (not money — server-side-only math rule doesn't apply; thresholds are also mirrored server-side for the initial render, single constant pair shared via the data island).
  - coverage < 9.0 → yellow `!` (`.mealsdb-po-warn`), tooltip: "Below 9-week coverage target (X.X wks)"
  - coverage < 7.0 → red `!` (`.mealsdb-po-crit`), tooltip: "Below 7-week safety floor (X.X wks)"
  - `adjusted_weekly <= 0` → no warning (coverage undefined; row shows "—")
  - Warnings never block saving.
- Footer: TOTAL cases row (live), pallet count vs `APETITO_CASES_PER_PALLET`.

### 3.3 Detail view — approved / received (locked)

Same table, read-only: no +/- buttons, values only, "was:" hints preserved. Banner: "This purchase order is approved and is shown read-only." (received: analogous). Un-approve button (approved only) with required-reason prompt, identical UX to invoice un-finalize.

### 3.4 Detail view — reconcile mode (status `arrived`, via Reconcile action)

Same table plus: **Received** column with `[−] [ n ] [+]` (initialized from ordered cases, or from a persisted `payload.received` session), and a **Note** input that appears on (and is required for) any row where received ≠ ordered. Ordered column stays visible for comparison. Coverage warnings recompute against received cases (same thresholds — reducing received below the floor should be visually loud). Sticky footer button: **Complete reconciliation** → confirm dialog → single AJAX call. Server re-validates notes; a missing note returns per-row errors and nothing is applied.

Example flow (from the operator): hit `−` twice on a SKU, type "Two cases damaged in transit", click Complete reconciliation → stock drops by 2 × case_size for that SKU, `inventory_discrepancy` audit row carries the note.

### 3.5 Forecast tab (`?tab=po`) addition

After a successful generate, a **"Save as draft"** button appears next to Export CSV. It saves whichever variant is currently displayed (base, or pallet-optimized when the optimize toggle is on and the optimized table is shown). On success: link/redirect to the new draft's detail view. Generation/preview/optimize behavior otherwise unchanged.

### 3.6 Assets

- `assets/js/purchase-orders.js` (new) — list actions + detail editor. `assets/js/purchase-order.js` (existing) gains only the Save-as-draft call.
- `assets/css/purchase-orders.css` (new) — mirrors `invoice-draft.css` patterns (sticky header, `max-height: 75vh` scroll container, derived-cell shading, `.mealsdb-recomputed` flash) plus:
  - `.mealsdb-po-warn` — yellow `!` badge (WP notice-yellow palette)
  - `.mealsdb-po-crit` — red `!` badge (WP notice-red palette)
  - +/- button styling (`button.button.mealsdb-po-step`, compact)
- No inline `<script>` blocks > 20 lines; config via JSON data island + `wp_add_inline_script`, matching the existing PO tab.

---

## 4. Endpoints & guards

New `includes/ajax/class-ajax-purchase-orders.php`, mirroring `class-ajax-invoice-draft.php` structure: shared `guard()` (nonce → capability → rate limit), `\Throwable` outer catch → `wp_send_json_error` (swallow, log, sentinel — Pattern 7), all responses `{success, data:{message…}}` with proper HTTP codes (400/403/429/500).

- **Nonce:** one new context `mealsdb_po_nonce` for the whole workflow (destructive category warrants its own context per Pattern 13; one context for the family, like `mealsdb_invoice_draft_nonce`).
- **Capability:** `MealsDB_Permissions::can_access_plugin()` (baseline `manage_woocommerce`) for ALL actions — deliberate divergence from invoices' `manage_options`: purchasing is operational, carries no PII and no client billing. (Flagged and approved during design.)
- **Defense-in-depth:** capability re-checked in the service layer (`MealsDB_Purchase_Orders` lifecycle methods), view file tops keep `MealsDB_Permissions::enforce()`.

| AJAX action | Purpose | Rate bucket |
|---|---|---|
| `mealsdb_po_save_draft` | Forecast tab → INSERT draft | `client_modify` (50/hr) |
| `mealsdb_po_edit_cases` | Draft-mode row save | **new** `po_draft_edit` (300/hr, fail-closed) |
| `mealsdb_po_reconcile_edit` | Reconcile-mode row save | `po_draft_edit` |
| `mealsdb_po_approve` | Draft → Approved | `settings_modify` (20/hr) |
| `mealsdb_po_unapprove` | Approved → Draft (reason required) | `settings_modify` |
| `mealsdb_po_mark_received` | Approved → Received + stock bump | `settings_modify` |
| `mealsdb_po_complete_reconcile` | Received → Reconciled + deltas | `settings_modify` |
| `mealsdb_po_cancel` | Draft → Cancelled | `settings_modify` |

Server-side validation on edits: `po_id` must exist and hold the expected status; SKU must exist in the stored payload (never trust a form-only SKU — same rule `apply_adjustments` already enforces); cases integer, 0–10,000; note ≤ 500 chars, `sanitize_text_field`.

### Service layer additions (`MealsDB_Purchase_Orders`)

`create_draft(array $rows, array $meta): int|WP_Error` · `get_with_payload(int $po_id): ?array` · `edit_draft_cases(int $po_id, string $sku, int $cases): array|WP_Error` · `approve(int $po_id): bool|WP_Error` · `unapprove(int $po_id, string $reason)` · `mark_received(int $po_id)` · `edit_reconcile_row(int $po_id, string $sku, int $received_cases, string $note)` · `complete_reconcile(int $po_id)` · `cancel_draft(int $po_id)`. Existing `create()/get()/update()/query()` untouched (task path still uses them).

---

## 5. Schema rollout

1. Add the new columns to the canonical `MealsDB_Schema` definition for `meals_purchase_orders` (additions only — Schema_Sync handles these).
2. Bump `MEALS_DB_VERSION`.
3. Per recon-01 caution: after upgrade, verify columns exist before the new UI writes payloads (the service returns a clear error rather than silently failing if `payload` is missing).

---

## 6. Testing

Following existing test-suite patterns (`tests/test-task-workflow-po-chain.php` WC stubs, `test-po-freight-optimization.php` style):

- **`tests/test-po-draft-lifecycle.php`** — create_draft payload shape (generated == current); guarded-transition matrix (each action from each wrong status fails; races: two approves → one wins); edit validation (unknown SKU rejected, negative/huge cases rejected, clamp at 0); un-approve requires reason; cancel only from draft; legacy PO (payload NULL) rejected by all new actions; `items` written in units at approve; audit rows emitted per transition.
- **`tests/test-po-reconcile-deltas.php`** — note-required enforcement (missing note → error, no stock change); delta math through `apply_adjustments` (received < ordered → negative delta; = → no-op; unknown SKU ignored); `payload.received` session persistence; complete flips status + timestamps.
- **Coverage thresholds** — small pure-function test for the server-side mirror of the coverage/threshold computation (9.0 / 7.0 boundaries, `adjusted_weekly = 0` → no warning).

Live PDF/mbstring-dependent paths: none involved (no PDF in this feature).

---

## 7. Decisions log

| Decision | Choice | Why |
|---|---|---|
| Integration with existing PO system | Extend `meals_purchase_orders` (Approach A) | One table, one lifecycle; `planned` status was unused → free "draft" slot; avoids first-ever ALTER migration |
| Task system | Untouched; separate follow-up track | Operator decision. Reused statics are pure inventory helpers, not task-engine calls |
| Draft editability | Cases only (+/- buttons); header fields fixed at generation | Operator decision |
| Approve semantics | Invoice-style lock + un-approve with required audited reason | Operator decision ("identical to the government invoices") |
| Mark received | Bumps stock by ordered quantities | Operator decision |
| Reconcile | +/- on received counts, note required per changed row, deltas applied once at completion | Operator decision (e.g. "Two cases damaged in transit") |
| Thresholds | Yellow < 9 wks (forecast coverage target), red < 7 wks (pallet-optimizer floor), computed from generation-time snapshot | Matches existing model constants; deterministic |
| Payload encryption | None (plain JSON) | No PII in PO rows (SKU/qty only); deliberate divergence from invoice drafts |
| Capability | Baseline `manage_woocommerce`, not `manage_options` | Purchasing is operational; no PII/billing. Flagged and approved |
| Cancel draft | Included | Approved as scope addition; prevents unbounded draft accumulation |
| Un-receive | Not provided | Stock corrections belong to reconcile; keeps state machine one-way past `arrived` |
