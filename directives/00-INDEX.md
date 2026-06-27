# Midland Packing Documents — Implementation Directives (Claude Code handoff)

> ## ✅ BUILD COMPLETE — all units implemented (2026-06-26)
> Branch `feature/midland-packing-documents` · **PR #438** · 7 commits (one per unit).
>
> | Unit | Status | Commit | Primary output | Test |
> |---|---|---|---|---|
> | 01 schema | ✅ | 796c16f | `class-tables.php`, `class-schema.php` (`meals_slip_batches`) | CREATE-TABLE validated |
> | 04 service | ✅ | 9219832 | `includes/services/class-slip-batch.php` | test-slip-batch.php (31) |
> | 02 renderers | ✅ | aad69c8 | `includes/services/class-slip-pdf-generator.php` (additive) | test-slip-midland-render.php (25) |
> | 03 merge | ✅ | 8ebb5db | `includes/services/class-slip-merge.php` | test-slip-merge-validate.php (13) |
> | 05 ajax | ✅ | aea6080 | `includes/ajax/class-ajax-slip-batch.php` | test-ajax-slip-batch.php (24) |
> | 06 admin+js | ✅ | a24c74f | `class-slip-batch-page.php`, `assets/js/slip-batch.js` | lint + node + cross-ref |
> | 07 wiring | ✅ | 539e157 | `meals-db-main.php`, `uninstall.php`, v1.0.471 | integration verified |
>
> **Automated:** 4 new test files / 93 checks pass; full suite green except 2 pre-existing dompdf/mbstring
> env failures (unrelated). **Live-only verification still required before merge** (Imagick + dompdf +
> mbstring): doc rendering, the doc 3→doc 4 merge, and the divider calibration pass — see PR #438 Test Plan
> and unit 07's checklist.
>
> **Resolved during build (operator, 2026-06-26):** collect amount reuses `Collection_Calculator`; secondary
> phone/contact included only when populated; doc 1 legend sourced from `mealsdb_zone_delivery_schedule`;
> empty take-from-hold initials render `NONE`. **Design decision:** doc 1/2/4 renderers are *additive* (the
> live daily-slip path is untouched) rather than a refactor of the shared render path — see unit 02.
>
> ---


This is a multi-file directive set to build the Midland packing/shipping document feature in the
Meals Database plugin. Each file is a self-contained build unit. Build in numeric order; later units
depend on earlier ones. All file paths are relative to the plugin root (where `meals-db-main.php` lives).

## Background (read first)
The full design rationale, measured coordinates, and data sources live in
`SPEC-midland-packing-documents-COMBINED.md` (the companion spec). These directives are the
implementation instructions; the spec is the reference. Where a directive says "see SPEC", consult it.

The feature: four documents for the packer (Midland) workflow.
- **Doc 1** cover sheet (per zone). NEW — does not exist.
- **Doc 2** packer slip, item list left + blank-but-divider right (per order). REFINE existing generator.
- **Doc 3** packer slip with handwriting, scanned, uploaded back. INPUT (rasterized to a background).
- **Doc 4** driver block, overlaid onto doc 3's right region. Generator exists; reshape + persist + merge.

Two-phase, stateful workflow: generate doc1+doc2 and SAVE doc4 batch → Midland returns doc3 →
upload doc3 → composite saved doc4 onto doc3 → print one finished sheet. One zone at a time.

## Build units
1. **01-schema-slip-batches.md** — new DB table `meals_slip_batches` (persists doc4 payloads + state).
2. **02-doc-rendering.md** — doc 1, doc 2 (with divider), doc 4 (driver-block-only) renderers, calibrated.
3. **03-doc3-background-and-merge.md** — rasterize uploaded doc 3 (Imagick) + composite doc 4 → merged PDF.
4. **04-batch-service.md** — `MealsDB_Slip_Batch` service (create/get/list/cancel/store-doc3/store-merged).
5. **05-ajax.md** — AJAX handlers (generate batch, download doc2/doc4, upload doc3, combine, cancel).
6. **06-admin-page-and-js.md** — the "Packing Slips" history page (table, buttons, confirm popup).
7. **07-bootstrap-and-audit.md** — wire init() calls, event-log events, final integration checklist.

## Hard rules (apply to every unit)
- Mirror EXISTING patterns; do not invent new ones. The invoice-draft layer is the template for
  persistence + history UI + cancel-with-audit. Cite: `includes/services/class-invoice-draft.php`,
  `includes/admin/class-invoice-draft-page.php`, `includes/ajax/class-ajax-invoice-draft.php`,
  `assets/js/invoice-draft.js`.
- Page geometry is Letter landscape everywhere. 300 DPI rasterization = 3300x2550 px.
- Permissions/guard: reuse the exact guard stack from `class-ajax-invoice-draft.php::guard()`
  (nonce → `manage_options` → rate limiter). Driver blocks expose decrypted client PII (name/address),
  so `manage_options` is REQUIRED, same as invoice drafts.
- Audit via `MealsDB_Event_Log::record([...])` (see 07 for the exact array shape).
- After each unit: `php -l` every touched file; run the test suite (`php tests/test-*.php`); do not
  proceed on a red suite.
