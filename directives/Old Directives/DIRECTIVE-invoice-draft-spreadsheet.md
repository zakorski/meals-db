# Directive — Invoice Draft "Spreadsheet" Review View (legible + live-recompute) — REV 2

> **STATUS — SHIPPED (2026-06-30).** All three pipeline grids are merged to `main`:
> - **VAC** — flat grid + live recompute, hand-entered fold — **PR #439**.
> - **SDNB-legacy** — per-line "client block" grid + live recompute — **PR #440**.
> - **SDNB-new-portal** — flat grid, legibility + edit only (portal owns the total; no recompute) — **PR #441**.
>
> All three open questions are resolved (recorded below). The only remaining item is the cross-cutting
> **manual browser verification** in the Verify section — not yet performed on a live site.

> **REV 2 note:** the first draft of this directive assumed (a) one universal pipeline math
> function (`split_into_invoice_lines`), (b) one universal editable-field set
> (`bill_mains`/`bill_sides`/`bill_tax_sides`/`bill_nontax_sides`/`client_contribution`), and
> (c) that the stored `current` row already carries that math's input shape. A line-by-line read of
> `class-invoice-generator.php` contradicts all three. This revision corrects the mechanics. The
> GOAL is unchanged; the WIRING is per-pipeline. Read "The three pipelines are NOT the same" before
> touching code — it is the whole correction.

## Goal
Replace the current draft review grid (an illegible wall of full-width `<input>` cells) with a clean,
spreadsheet-style view Janet can read and edit before finalizing. Editing an INPUT field live-recomputes
the DERIVED money fields by calling the EXISTING pipeline math **on the server** — no formula is duplicated
in JavaScript. Derived values are NEVER directly editable, and are **computed-on-read, never persisted**
into the draft (finalize remains the single derivation point).

---

## What REV 2 corrects (the three wrong assumptions)

1. **`split_into_invoice_lines()` is SDNB-LEGACY ONLY.** It is called in exactly one place —
   `serialize_sdnb_legacy()` (line 972). VAC and SDNB-new-portal never call it. The mock supplied with
   this work is a **VAC** draft, so the original "expose `recompute_lines()` over `split_into_invoice_lines`"
   plan does not cover the pipeline in the mock at all. There is no single source-of-truth math function;
   there are **three** per-pipeline derivations.

2. **The editable field names differ per pipeline, and the `bill_*` set is not persisted for SDNB.**
   - For **SDNB-legacy / new-portal**, the stored `current` row is a raw phase-2 row keyed on
     `allocated_mains` / `allocated_sides` / `allocated_tax_sides` / `allocated_nontax_sides` /
     `resolved_rate` / `contribution_cents`. The `bill_*` names exist ONLY transiently inside
     `serialize_sdnb_legacy()`'s adapter (lines 944–963) and are never saved. A grid editing "bill_mains"
     on an SDNB draft would be editing a column that does not exist.
   - For **VAC**, the persisted editable fields are `bill_mains`, `bill_rate`, `fold_amount`, `fold_hst`
     (set in `build_vac_draft_rows`, lines 781–803) — a different set again.

3. **The mock's derived columns (Subtotal, VAC Portion, Tax, Total) are not fields on the row today.**
   The current grid dumps `array_keys` of the stored row, which holds inputs/allocations, not final line
   totals. Surfacing those columns is new derived-value wiring, not a pure presentation change — and for
   VAC the "VAC Portion = Subtotal − Client Contribution" decomposition in the mock does **not** match the
   current VAC billing model (mains-only + hand fold). See **Open questions**.

Corrected design principle: **derived = compute-on-read**, produced by a single shared per-row compute
function per pipeline that BOTH the serializer (finalize) AND the grid (render + recompute) call. No
derived value is stored back into `current`; nothing is recomputed in JS.

---

## Reference (verified line numbers — study before editing)
- Current grid renderer: `includes/admin/class-invoice-draft-page.php`
  - column discovery + table build: lines 264–297 (`array_keys` union → `<table id="mealsdb-draft-grid">`).
  - `render_cell()` line 367: makes EVERY editable scalar a `<input class="mealsdb-draft-cell">`.
- Save JS (KEEP working): `assets/js/invoice-draft.js`
  - line 62: `$('#mealsdb-draft-grid').on('change','.mealsdb-draft-cell', ...)` → POSTs `mealsdb_edit_draft_field`.
  - cells keyed by `data-client-id` + `data-field`. PRESERVE these on editable inputs so the save path is untouched.
- Edit endpoint (fold recompute into this — see Part 3): `includes/ajax/class-ajax-invoice-draft.php`
  - `edit_draft_field()` line 153 — already: guard → load draft → validate per-field → `edit_field()` → audit.
  - `guard()` line 487 (nonce → manage_options → rate limit). `validate_value()`/`classify_field()` lines 518/571.
- Draft storage: `includes/services/class-invoice-draft.php` — `current` (editable) + `generated` (immutable
  baseline), keyed by client_id (lines 63–67). `edit_field()` line 232 persists one field + bumps edit_count.
- Pipeline math (DO NOT reimplement in JS — call server-side):
  `includes/services/class-invoice-generator.php`
  - `split_into_invoice_lines()` line 377 (PRIVATE) — **SDNB-legacy only.** Expects an ALREADY-ADAPTED row
    (`resolved_rate`, nested `client` w/ `delivery_area_zone`, `bill_mains`, `bill_sides`, `bill_tax_sides`,
    `bill_nontax_sides`, `client_contribution`). The adapter that builds that shape from a phase-2 row is
    `serialize_sdnb_legacy()` lines 944–963 — it is NOT shared today; Part 3 lifts it out.
  - `serialize_vac_csv()` line 1296 — VAC derivation. Per-row math at lines 1337–1357:
    `vet_mains_cost = bill_mains × bill_rate`; `vac_total = vet_mains_cost + fold_amount + fold_hst`
    (mains-only; sides NOT billed; `fold_*` are HAND-ENTERED). `resolve_hst_rate()` is WC-sourced (LB-7).
  - `serialize_sdnb_new_portal()` line 1188 — passthrough: emits `allocated_mains`, `resolved_rate`,
    `contribution_cents`, `tax_cents`; the PORTAL computes the total. No `split_into_invoice_lines`, no Total column.
  - Row builders: `build_vac_draft_rows()` 741, `build_sdnb_legacy_draft_rows()` 838,
    `build_sdnb_new_portal_draft_rows()` 852 (the last two are bare `get_phase2_billing_data()` output).

---

## The three pipelines are NOT the same

### VAC (mock pipeline)
- **Stored `current` row** (build_vac_draft_rows): identity (`last_name`, `first_name`, `vet_health_card`
  decrypted, `street_name`, `city`, `postal_code`, `client_phone_1`, `requisition_period`), allocations
  (`allocated_mains`, `allocated_tax_sides`, `allocated_nontax_sides`, `resolved_rate`), the editable
  corrected-model fields (`bill_mains`, `bill_rate`, `fold_amount`, `fold_hst`), and `info_*` reference fields.
- **Math:** `serialize_vac_csv` lines 1349–1350. `fold_amount`/`fold_hst` are Janet's hand-work, seeded 0,
  deliberately NOT auto-computed (build_vac_draft_rows 752–758).
- **Editable (INPUT):** `bill_mains` (int), `bill_rate` ($), `fold_amount` ($, manual), `fold_hst` ($, manual),
  plus optional identity text.
- **Derived (READ-ONLY):** `vet_mains_cost` (= bill_mains × bill_rate), `vac_total`
  (= vet_mains_cost + fold_amount + fold_hst). Informational read-only: `info_*`, `remaining_sides`,
  `allowance_remaining`.

### SDNB-legacy
- **Stored `current` row:** raw phase-2 row — `allocated_mains`, `allocated_sides`, `allocated_tax_sides`,
  `allocated_nontax_sides`, `resolved_rate`, `contribution_cents`, identity (`service_id`, `requisition_id`,
  `individual_id`, `delivery_area_zone`, names, address). **No `bill_*` fields.**
- **Math:** `serialize_sdnb_legacy` adapts (allocated_* → bill_*, contribution_cents/100 → client_contribution,
  wraps row as `client`) then calls `split_into_invoice_lines`, which derives `units`, `rate`, `basic_cost_cents`,
  `tax_cents`, and the optional line-2 fields.
- **Editable (INPUT):** `allocated_mains`, `allocated_sides`, `allocated_tax_sides`, `allocated_nontax_sides`,
  `contribution_cents` (and `resolved_rate` — see Open questions).
- **Derived (READ-ONLY):** `units`, `rate`, `basic_cost_cents`, `tax_cents`, line-2 fields, line total.

### SDNB-new-portal
- **Stored `current` row:** raw phase-2 row (same as legacy) + `sdnb_service_request_id`, `tax_cents`.
- **Math:** none of ours — `serialize_sdnb_new_portal` is a passthrough; the portal totals on upload.
- **Editable (INPUT):** `allocated_mains`, `resolved_rate`, `contribution_cents`, `tax_cents`.
- **Derived (READ-ONLY):** `units` (= allocated_mains). There is **no total to recompute** — for this pipeline
  the change is legibility + edit only, with no live-recompute math. Do not invent a total the portal owns.

---

## Part 1 — Curated columns (kill the array_keys dump), PER PIPELINE
Replace the `array_keys` union with an explicit, ordered, **per-pipeline** column list — each entry:
`label` + `field` key + `type` (`identity|input-int|input-money|derived-money|derived-int|info`). Hide
internal/bookkeeping fields. Provide a **"Show all fields"** toggle that falls back to the current raw
`array_keys` behavior for debugging. Any field not in the curated list is hidden unless "Show all" is on.

Define three column maps (the code already branches per pipeline via the stored row shape / `draft['pipeline']`;
mirror that). Use the REAL field names from "The three pipelines are NOT the same" above — do **not** emit a
`bill_mains` column for an SDNB draft (it isn't on the row), and do **not** emit a Total column for new-portal.

**RESOLVED (operator, 2026-06-29):** the VAC column map mirrors the CODE model, NOT the mock. The mock is a
LAYOUT/STYLING reference only. Its `Client Contrib` / `VAC Portion` columns are dropped — `contribution_cents`
is pulled into the VAC row by `get_phase2_billing_data` but NEVER applied to the VAC total (dead code carried
from the old system), so surfacing it as a billed figure would be a lie. The curated VAC columns are:
Client, Address, Meals (`bill_mains`, input-int), Rate (`bill_rate`, input-money), Fold Amount (`fold_amount`,
input-money), Fold HST (`fold_hst`, input-money), Vet Mains Cost (`vet_mains_cost`, derived-money), VAC Total
(`vac_total`, derived-money). No `Client Contrib`, no `VAC Portion`, no standalone `Tax` column (HST lives
inside Fold HST). The dead `contribution_cents` is reachable ONLY under the "Show all fields" debug toggle.

## Part 2 — Spreadsheet styling (legibility)
Enqueue a stylesheet for `#mealsdb-draft-grid`, guarded to this page only (mirror the existing
`enqueue_scripts` hook-suffix guard at `class-invoice-draft-page.php:58`):
- bordered cells (separated borders), `tabular-nums`, zebra striping (table already has `striped`).
- sticky header row (`position:sticky; top:0`) and frozen first column (client name: `position:sticky; left:0`)
  so headers/client stay visible scrolling a wide/long invoice.
- money columns right-aligned with `$` formatting; integer columns right-aligned.
- INPUT cells read as text until focused: render the `<input>` but style it borderless/transparent until
  `:hover`/`:focus` (border highlight like a selected spreadsheet cell), so it reads like a cell, not a box.
- DERIVED cells visually distinct (subtle grey background, class `mealsdb-derived`) — "computed, not editable".
- Keep the existing "was: <generated value>" hint (small grey, `render_cell` lines 382–385) when current ≠ generated.

## Part 3 — Live recompute (server-side, shared per-pipeline compute)

**3a — Refactor first (the "single source of truth" must be literally shared):**
- **SDNB-legacy:** lift the allocated_*→bill_* adapter out of `serialize_sdnb_legacy()` (lines 944–963) into a
  private helper, then expose a thin public wrapper that takes a `current` row and returns the derived line(s):
  `public static function recompute_sdnb_legacy_row(array $row): array` → adapt → `split_into_invoice_lines()`.
  `serialize_sdnb_legacy` then calls the SAME helper (no duplicated adapter, no duplicated math).
- **VAC:** extract the per-row derived math from `serialize_vac_csv()` (lines 1337–1357) into
  `public static function compute_vac_row_derived(array $row): array` returning
  `['vet_mains_cost_cents'=>…, 'vac_total_cents'=>…, 'remaining_sides'=>…, 'allowance_remaining_cents'=>…]`.
  `serialize_vac_csv` then calls it instead of inlining the formula. The recompute endpoint calls the same fn.
  `fold_amount`/`fold_hst` remain INPUTS — the fn reads them, it does not derive them.
- **SDNB-new-portal:** no money derivation (the portal owns the total). At most expose `units = allocated_mains`.
  Skip the recompute round-trip for this pipeline.

**3b — Fold recompute into the EXISTING edit endpoint (no second endpoint, no new nonce):**
Extend `edit_draft_field()` (class-ajax-invoice-draft.php:153). After `edit_field()` succeeds and the audit
row is written, load the updated `current` row for that client_id, dispatch on `draft['pipeline']` to the
matching compute fn from 3a, and add the derived values to the JSON response:
```php
wp_send_json_success([
    'field'   => $field,
    'value'   => $validated,
    'changed' => ($old !== $validated),
    'derived' => $derived, // [field => formatted display value], pipeline-specific; [] for new-portal
]);
```
Keep `guard()` (nonce → manage_options → rate limit) and `validate_value()` exactly as-is. **Do NOT persist
the derived values back into `current`** — they are display-only; finalize re-derives from the inputs via the
same compute fn, so a persisted copy could only drift.

**3c — Grid render uses the same compute fn:** the curated grid (Part 1) renders DERIVED cells by calling the
per-pipeline compute fn at render time (both the editable and the finalized read-only view), so what Janet sees
matches what finalize will emit. Derived cells get class `mealsdb-derived` and carry NO editable
`data-field` input.

**3d — JS (`invoice-draft.js`):** in the existing `.mealsdb-draft-cell` `change` handler (line 62), after the
save resolves, write `resp.data.derived` into that row's read-only derived cells (by a `data-derived-field`
attribute). Never compute money in JS — only display what the endpoint returns. Show a brief
saving/recomputing state on the row.

## Part 4 — Finalize unchanged
Finalize / un-finalize, download links, audit — all untouched. The finalized read-only view uses the SAME
curated per-pipeline layout (Part 1) with no inputs, rendering derived cells via the 3a compute fns.

---

## Open questions — ALL RESOLVED (operator, 2026-06-29)
1. **VAC fold stays hand-entered? → YES.** Janet enters `fold_amount`/`fold_hst` per veteran. They remain
   editable INPUT (input-money) fields seeded to 0 (build_vac_draft_rows 796–803); NOT derived, NOT auto-seeded.
   The comments at serialize_vac_csv 752–758 were correct — no seed change.
2. **VAC mock divergence → mirror the CODE model.** The contribution is dead code (pulled by
   `get_phase2_billing_data`, never applied to the VAC total). The mock is a styling reference only; its
   `Client Contrib` / `VAC Portion` columns are NOT built. See the RESOLVED block under Part 1 for the final
   VAC column list. This keeps the directive a pure presentation + server-recompute change — NO billing-logic
   change.
3. **Is `bill_rate` editable on the VAC grid? → YES, editable (input-money).** Janet can override the per-main
   rate per veteran. Keep the current behavior (seeds from `resolved_rate`, but overridable on the grid).
   (SDNB-legacy `resolved_rate` editability is a separate per-pipeline call if/when that grid is built.)

---

## Hard rules
- DERIVED money values are NEVER editable, NEVER computed in JavaScript, and NEVER persisted into `current`.
  Single source of truth = the per-pipeline PHP compute fn (3a), shared by the serializer AND the grid.
- PRESERVE `data-client-id` / `data-field` on editable inputs so the existing save path keeps working.
- Use the REAL stored field names per pipeline (above). No `bill_*` columns on SDNB drafts; no Total on new-portal.
- Asset enqueue guarded to this admin page only (mirror the existing guard at line 58).
- `php -l` touched files; run `php tests/test-*.php` (note: 2 VAC PDF tests fail locally for lack of
  mbstring/imagick — that is the known baseline, not a regression). Verify on a test site that editing
  `bill_mains` (VAC) / `allocated_mains` (SDNB-legacy) updates the totals VIA THE SERVER and that derived cells
  cannot be edited.

## Verify
- Load a VAC draft, an SDNB-legacy draft, and an SDNB-new-portal draft: columns are curated, labeled, readable;
  numbers aligned; header + client column freeze on scroll; each shows its OWN field set.
- VAC: edit `bill_mains` → `vet_mains_cost` and `vac_total` update to the serializer-correct values; `fold_amount`
  remains an editable input (not derived); the change persists; "was:" hint appears; finalize still produces the
  byte-identical artifact for the same edits.
- SDNB-legacy: edit `allocated_mains` → `units` / `basic_cost_cents` / `tax_cents` / total recompute via the
  shared adapter + `split_into_invoice_lines`; finalize output matches the grid.
- SDNB-new-portal: editable fields save; no phantom Total column; no recompute errors.
- Confirm a derived cell (e.g. VAC `vac_total`) has no input and cannot be changed, on both the editable and the
  finalized read-only view.
- "Show all fields" reveals the raw `array_keys` columns (debug parity with old behavior).

---

# SDNB-legacy grid — SCOPE (2026-06-29)

> Status: SHIPPED. VAC grid (PR #439, `compute_vac_row_derived` + curated map + CSS + recompute endpoint);
> SDNB-legacy grid (PR #440, this section); SDNB-new-portal grid (PR #441, flat map, no recompute).
> This section scopes the SDNB-legacy grid. Two decisions (D1, D2 below) gate the build — resolve with the
> operator/dev BEFORE writing the column map. The VAC build is the template; the wiring (column_map →
> derived_display → edit endpoint `derived` → JS refresh) is identical. The MATH and ROW SHAPE differ.

## What's verified in code (not assumed)
- **Stored `current` row** = bare phase-2 row from `build_sdnb_legacy_draft_rows` (`get_phase2_billing_data`):
  `allocated_mains`, `allocated_sides`, `allocated_tax_sides`, `allocated_nontax_sides`, `resolved_rate`,
  `contribution_cents` (INT cents), identity (`service_id`, `requisition_id`, `individual_id`,
  `individual_id_index`, `delivery_area_zone`, names, address), `wp_user_id`. **No `bill_*` fields.**
- **PII is DECRYPTED on the row** — `collect_sdnb_legacy_client_rows` `safe_decrypt`s `requisition_id` +
  `individual_id` (class-invoice-generator.php ~679). So the grid can display them; "Show all" shows plaintext,
  exactly as the array_keys grid does today (page is `manage_options`-gated — no new exposure).
- **The adapter** (`serialize_sdnb_legacy` lines 944–963) maps `allocated_* → bill_*`,
  `contribution_cents/100 → client_contribution` (float), wraps the row as `client`, then calls
  `split_into_invoice_lines`.
- **`split_into_invoice_lines` (line 377) emits 1 OR 2 lines.** Per line: `units`, `rate`, `basic_cost_cents`,
  `client_contribution_cents` (line-1 only; always 0 on line 2), `tax_cents`. Line total =
  `basic + tax − contribution`. Rurality (→ side rate + line-2 secondary main rate) comes from
  `delivery_area_zone`, NOT the rate value (LB-7). HST = taxable_sides × side_rate × WC-sourced rate.

## Decisions — RESOLVED (operator/dev, 2026-06-29)
- **D1 → PER-LINE BREAKDOWN.** The grid shows each client's 1–2 invoice lines, each with its own
  rate/units/basic/HST/total. This is the literal SDNB invoice shape — and the bigger build (per-line derived
  payload + dynamic sub-row management; see "Per-line design" below).
- **D2 → RATE EDITABLE.** `resolved_rate` is an editable `input-money` on **line 1 only** (line-2 rate is
  always constant-derived and never an input).
- **D3 → CONTRIBUTION EDITABLE, as DOLLARS.** The grid edits the contribution as a dollar amount; the layer
  converts to/from `contribution_cents` (stored cents) on read/save. It is client-level (applies to line 1;
  line 2 is always 0).

## Per-line design (the crux of D1)
The editable inputs are **client-level** (one `allocated_mains`/`tax_sides`/`nontax_sides`/`rate`/`contribution`
per client) but the derived output is **per-line**, and editing an input can CHANGE THE LINE COUNT (e.g. adding
mains past the sides count spawns a second line, or removing them collapses it). So:
- **Layout — "client block" = an editable header row + 1–2 read-only line rows.**
  - *Header row (editable):* Client (frozen) · Service ID · Zone · Mains · Tax Sides · Non-Tax Sides ·
    Contribution($) — the client-level inputs, shown once.
  - *Line rows (read-only, indented under the client):* Line # · Units · Rate · Basic · HST · Line Total. **D2
    exception:** line-1's Rate cell is the editable `resolved_rate` input; line-2's Rate is read-only derived.
  - Group line rows to their client with a `data-client-id` on every row + a CSS block separator (no rowspan —
    rowspan fights the dynamic re-render).
- **Recompute payload is per-line.** `edit_draft_field` returns `derived` as an ORDERED LIST of line objects
  for the edited client, e.g. `derived.lines = [{units,rate,basic,hst,total}, …]`, NOT a flat field→value map.
  This is a SHAPE CHANGE from the VAC `derived` map — the JS branch must handle both (VAC: field map; SDNB:
  line list). Keep the VAC contract intact; add the list form for SDNB.
- **JS must re-render the client's line rows** (remove existing `.mealsdb-line-row[data-client-id=X]`, re-emit
  from `derived.lines`) because the line COUNT can change on edit. The VAC in-place cell update does not cover
  this; SDNB needs a small row-template render in JS (display-only — still NO money math in JS).
- `allocated_sides` (the line-1 gate) is **derived from** `tax_sides + nontax_sides`, NOT an independent input
  (avoids the desync wrinkle); the adapter computes `bill_sides = tax + nontax`.

## Refactor (Part 3a, mirrors the VAC extraction)
Lift the adapter out of `serialize_sdnb_legacy` into:
- `private static function adapt_sdnb_legacy_row(array $phase2_row): ?array` — the 944–963 mapping (returns
  null for a zero-mains row, matching the current `continue`); set `bill_sides = tax + nontax`.
- `public static function recompute_sdnb_legacy_lines(array $row): array` → `adapt_sdnb_legacy_row` →
  `split_into_invoice_lines` → return the ORDERED 1–2 line objects (formatted-money-ready).
`serialize_sdnb_legacy` then calls the SAME adapter (no duplicated mapping). Characterization test
`test-invoice-serialize.php` T-A1 must stay byte-identical.

## Column model (per-line)
Header (editable, per client): Client (identity-name, frozen) · Service ID (identity) ·
Zone (identity, `delivery_area_zone`) · Mains (`allocated_mains`, input-int) ·
Tax Sides (`allocated_tax_sides`, input-int) · Non-Tax Sides (`allocated_nontax_sides`, input-int) ·
Contribution (`contribution_cents` edited as `$`, input-money).
Line rows (derived per line): Line # · Units (derived-int) · Rate (line-1 = `resolved_rate` input-money;
line-2 derived) · Basic (derived-money) · HST (derived-money) · Line Total (derived-money).
No `bill_*` columns (not on the row). Reconcile labels with the operator.

## Test plan (mirror VAC, extended for per-line)
- `test-sdnb-legacy-compute.php` — `recompute_sdnb_legacy_lines` against known `split_into_invoice_lines`
  outputs: a 1-line case, a 2-line case (line-2 secondary main rate + line-1-only contribution), the
  `bill_sides = tax+nontax` derivation, and that editing mains flips the line count.
- Extend `test-invoice-draft-columns.php` — SDNB-legacy header map (editable types; no `bill_*`); line-row
  model (Rate editable on line 1 only).
- `test-ajax-invoice-draft.php` T-13 — SDNB-legacy edit returns `derived.lines` as an ordered list; editing
  mains changes the list length.
- `test-invoice-serialize.php` T-A1 unchanged (adapter refactor is behavior-preserving).
- `$/cents` round-trip for the contribution edit (dollars in → cents stored → dollars displayed).
</content>
</invoke>
