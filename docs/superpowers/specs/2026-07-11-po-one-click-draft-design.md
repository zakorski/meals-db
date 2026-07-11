# PO one-click draft generation — design

**Date:** 2026-07-11
**Status:** Approved
**Builds on:** `2026-07-10-po-draft-workflow-design.md` (draft workflow), `2026-07-10-po-task-integration-design.md`

## Problem

The Purchase Order forecast tab currently requires three decisions to get a
draft PO: tick (or not) the "Optimise for whole pallets" checkbox, click
**Generate** to preview, then click **Save as draft PO** to persist and open
the draft. In practice the operator always wants the optimized draft, and the
draft detail page is already the real review surface (editable steppers,
coverage warnings, pallet totals). The preview step is ceremony.

## Decision

Clicking **Generate draft PO** creates a pallet-optimized draft and opens it
in one go. The forecast-tab preview (table, pallet-summary banner, Export CSV)
is removed; CSV export moves to the draft detail page.

## Changes

### 1. Forecast tab → one button

`views/purchase-order.php` + `assets/js/purchase-order.js`

- The Generate button (relabelled **Generate draft PO**) posts
  `mealsdb_po_save_draft` (nonce: `MealsDB_Ajax_Purchase_Orders::NONCE_ACTION`)
  and on success redirects to `admin.php?page=mealsdb&tab=po_admin&po_id=<id>`
  — the same redirect the old Save-as-draft button performed.
- Removed: optimize checkbox + its description, Save-as-draft button, Export
  CSV button, preview table renderer, delta cell, pallet-summary banner, CSV
  state, and all i18n strings that served only those. The JSON island keeps
  `poNonce`, `poAdminUrl`, `ajaxUrl`, and the status strings
  (`generating`/`requestFailed`/`draftSaveFailed`).
- A description line states that generation produces a pallet-optimized draft
  (75-case Apetito pallets, fill-if-≥⅓ / trim otherwise, 7–52 week guard) and
  that review/editing happens on the draft page.
- Keep the existing status-notice plumbing (`#mealsdb-po-status`, shared
  `MealsDBReport` helpers with inline fallbacks). Button disables while saving
  to prevent double-submit (double-click currently risks two drafts).

### 2. Server always optimizes

`includes/ajax/class-ajax-purchase-orders.php::save_draft()`

- Drop the `$_POST['optimize']` read; always run
  `MealsDB_Reports::optimize_po_for_pallets()` on the regenerated rows before
  `create_draft()`. Rows remain server-regenerated (browser payload untrusted).
  Guard spine (nonce → capability → `client_modify` rate bucket) unchanged.
- Remove the now-orphaned `mealsdb_generate_purchase_order` AJAX wrapper
  (registration + handler + its optimize branch) from
  `includes/ajax/class-ajax-reports.php`. Its only caller was the deleted
  preview. The **service** method `MealsDB_Reports::generate_purchase_order()`
  stays — `save_draft` and the buffer/full-catalog tests use it. Other report
  endpoints in that class are untouched.

### 3. Export CSV on the draft detail page

`views/purchase-orders.php` (detail view) + `assets/js/purchase-orders.js`

- An **Export CSV** button appears on workflow-PO detail pages (all modes:
  draft, locked, reconcile). Legacy task-created POs (payload `NULL`) don't
  get it.
- CSV is built client-side at click time from the grid's `data-*` attributes
  (`data-sku`, `data-case-size`, `data-adjusted-weekly`, `data-stock`,
  current `data-cases`) plus the product-name and note cells, so it always
  reflects live stepper edits. Columns: SKU, Product, Adj/Wk, Stock, Case
  size, Cases, Order qty, Coverage (wks), Note.
- Every cell routes through `Report.csvCell` (`report-utils.js`) per the
  CSV-injection rule (CLAUDE.md Pattern 14), with the same inline fallback
  pattern `purchase-order.js` uses today. Download via `Report.exportCsv`.
  Verify `report-utils.js` is enqueued on the `po_admin` tab; enqueue it there
  if it is not.
- Filename: `po-<po_number>-<YYYY-MM-DD>.csv`.

### 4. Copy + tests

- Update the PO list description ("Drafts are created from the Purchase Order
  forecast tab (\"Save as draft PO\")…") to describe the one-click flow.
- No behavioural test changes expected: `test-po-draft-lifecycle.php` and
  `test-po-freight-optimization.php` exercise the service layer, which is
  unchanged. Run the suite to confirm.

## Trade-offs accepted

- **Pallet-summary narrative is lost.** The banner ("filled up to a whole
  pallet, N cases changed") only existed on the preview. Per-row
  `freight_delta_cases` are still snapshotted in the draft payload, and the
  detail footer shows the pallet count — the outcome is visible, the
  optimizer's narrative is not.
- **Base (un-optimized) forecast is no longer viewable or exportable.** The
  operator always gets the optimized order; "was:" hints on the draft page
  cover per-row deviations from generation, not from the pre-pallet baseline.
- **A read-only AJAX endpoint is deleted** rather than left as dead surface.

## Out of scope

- Any change to the forecast model, the pallet optimizer, or the draft
  lifecycle/state machine.
- Rendering `freight_delta_cases` on the draft detail page.
- CSV export for legacy (task-created) POs.
