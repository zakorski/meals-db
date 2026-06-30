# Directive — Packing Slips: remove doc-3 merge, combine Doc 1 into Doc 2 as its cover

## Summary
Two coordinated changes to the Packing Slips feature:
- **A. Remove the doc-3 upload + rasterize + combine (merge) machinery entirely** (clean deletion).
  The team will keep doing the overlay manually, as they do today.
- **B. Combine Doc 1 and Doc 2 into ONE download** — Doc 1 (cover) becomes page 1 of the Doc 2 packer
  PDF — behind a single button labeled **"Packing Slips"**.

KEEP: batch generation + persistence, the `meals_slip_batches` table, the persisted **Doc 4** (it must
stay paired identically, page-for-page, to the Doc 2 it was generated with, for the team's manual
overlay), the Doc 4 download, Cancel, and the audit logging.

Resulting row actions: **Packing Slips** (combined Doc1+Doc2) | **Doc 4 (driver)** | **Cancel**.

## Reference (v1.0.479)
- Admin row: `includes/admin/class-slip-batch-page.php` (~lines 181–205): the Doc1/Doc2/Doc4 download
  links, the Upload-Doc3 span, Combine button, Download-merged link, Cancel.
- AJAX: `includes/ajax/class-ajax-slip-batch.php`:
  - init() registrations ~lines 51–61.
  - to REMOVE: `upload_doc3` (~122), `combine` (~202), `download_merged`, their actions (~52,53,61).
  - to MERGE: `download_doc1` (~292) + `download_doc2` (~311) → one `download_packing_slips`.
  - `download_url()` (~422) builds GET links per `which`.
- Generation (on-demand, NOT stored): `includes/services/class-slip-pdf-generator.php`
  - `generate_doc1_cover_sheet(zone, date, batch)` (~963) → cover; stamps "Page 1 of (1+order_count)".
  - `generate_doc2_packer_by_zones(...)` (~near 1040) → packer slips; stamps "Page (n+1) of (1+count)".
  - Page numbering ALREADY treats them as one doc (cover=page 1). Concatenating cover-first yields
    correct continuous numbering — NO renumbering needed.
- Merge service to DELETE: `includes/services/class-slip-merge.php`.
- Merge tests to DELETE: `tests/test-slip-merge-rasterize-failed.php`, `tests/test-slip-merge-validate.php`.
- Persistence: `includes/services/class-slip-batch.php` — create()/list()/get() reference
  `doc3_path, doc3_page_count, merged_path`; `store_doc3()`, `store_merged()`, `has_doc3`, `has_merged`.
- Schema: `includes/class-schema.php` (~777–780): columns `doc3_path`, `doc3_page_count`, `merged_path`.
- JS: `assets/js/slip-batch.js` — upload handler (~84–130), combine handler (~140+), `i18n.uploading`.
- Bootstrap: `meals-db-main.php` — `MealsDB_Slip_Merge` is not separately init()'d (static service), but
  confirm no require/autoload entry needs removing.

## PART A — Remove the merge machinery (clean deletion)

### A1. AJAX class
- Delete the `upload_doc3()` and `combine()` methods and the `download_merged()` method.
- Remove their `add_action` lines (`mealsdb_slip_upload_doc3`, `mealsdb_slip_combine`,
  `mealsdb_slip_download_merged`).
- Remove any `'merged'` branch in `download_url()` / the download dispatch.
- Remove the now-unused doc-3 size-cap constant and any merge-only helpers (e.g. `stream`/audit helpers
  used solely by upload/combine — verify they aren't shared before deleting).
- Audit events `slip_batch_doc3_uploaded` and `slip_batch_combined` will no longer fire — that's expected.

### A2. Merge service + tests
- Delete `includes/services/class-slip-merge.php`.
- Delete `tests/test-slip-merge-rasterize-failed.php` and `tests/test-slip-merge-validate.php`.
- Grep for any remaining references: `grep -rn "MealsDB_Slip_Merge\|class-slip-merge" includes/ tests/ meals-db-main.php`
  and remove them (require/use lines, etc.).

### A3. Batch service (`class-slip-batch.php`)
- Remove `store_doc3()` and `store_merged()` methods.
- Remove `doc3_path, doc3_page_count, merged_path` from the INSERT (create) and SELECT (get/list) column
  lists, and drop the `has_doc3` / `has_merged` derived flags from list()/get() output.
- Remove any on-disk file handling for doc3/merged (the `mealsdb-slips/doc3/` and `/merged/` dirs). In
  `cancel()`, remove the unlink of doc3/merged files (the row delete remains). Keep cancel otherwise.

### A4. Schema (`class-schema.php`)
- Remove the `doc3_path`, `doc3_page_count`, `merged_path` column definitions from the
  `meals_slip_batches` table (~777–780). Keep the rest of the table (batch identity, doc4 payload, status,
  timestamps).
- The `status` column: the `doc3_uploaded` / `combined` states are now unreachable. Simplify to just
  `generated` (and `cancelled` if used), or leave the column with only `generated` ever set — either is
  fine; do NOT leave UI implying the dead states.
- NOTE (migration): on an existing install the dropped columns will remain in the DB until a schema
  re-sync runs. If the installer does additive-only sync, that's acceptable (the columns sit unused);
  if there's a column-drop path, let it remove them. Don't write a destructive migration just for this —
  unused columns are harmless. State which behavior applies.

### A5. JS (`slip-batch.js`)
- Delete the Upload Doc 3 handler and the Combine handler entirely.
- Remove `i18n.uploading` usage and the `.always()` uploading-clear block (from the earlier fix) since
  upload is gone.
- Keep generate, list-refresh, and cancel handlers.

## PART B — Combine Doc 1 + Doc 2 into one "Packing Slips" download

### B1. New combined generator path
Add a method that returns ONE PDF = cover (page 1) followed by the packer slips. Cleanest approach,
since both are produced by dompdf on-demand from the batch:
- Preferred: render BOTH into a SINGLE dompdf document — cover markup first, then each order's slip with
  `page-break-before: always` — so it's one render and pagination is inherently continuous. Add e.g.
  `generate_packing_slips_combined(string $zone, string $date, array $batch): string` on the slip
  generator that emits cover HTML + the existing doc-2 slip HTML in one document.
- Acceptable alternative if a single render is impractical: render doc1 and doc2 separately (existing
  methods) and concatenate the two PDFs. If concatenating, a PDF lib is needed — but PREFER the single
  dompdf document to avoid adding any dependency (dompdf is already vendored; no Imagick/FPDI).
- Page numbering: unchanged — doc 1 already stamps "Page 1 of (1+N)" and doc 2 "Page (n+1) of (1+N)", so
  cover-first concatenation is already correct. Verify in the combined output.

### B2. AJAX
- Replace `download_doc1` + `download_doc2` with ONE handler `download_packing_slips` (action
  `mealsdb_slip_download_packing_slips`) that streams the combined PDF (filename e.g.
  `packing-slips-<zone>-<date>.pdf`). Keep `manage_options` guard + nonce as the other downloads have.
- Remove the separate `download_doc1` / `download_doc2` actions/handlers (folded into the above). Keep
  `download_doc4` as-is.
- Update `download_url()` `which` values: replace `doc1`/`doc2` with `packing_slips`; keep `doc4`; drop
  `merged`.

### B3. Admin row (`class-slip-batch-page.php`)
- Replace the three buttons (Doc 1 / Doc 2 / Upload Doc 3 / Combine / Download merged) with:
  - `<a class="button" href="<packing_slips url>">Packing Slips</a>`
  - `<a class="button" href="<doc4 url>">Doc 4 (driver)</a>`
  - the existing Cancel button + row message span.
- Delete the upload `<span>`/file input, the Combine button, and the merged link markup.
- Update the help/intro text (~line 100) to drop "so the scan can be combined later" — describe it as
  generating + saving the packing slips and driver sheets for manual handling.

## What must NOT change
- Batch generation, the persisted **Doc 4** payload and its positional pairing to Doc 2 (critical — the
  team matches them by hand), Doc 4 download, Cancel + its audit log, the history table/list, the
  `manage_options` guard. Do not alter the doc-2 slip layout, the divider, or doc-4 content.

## Verify
```
php -l (every changed PHP file)
grep -rn "MealsDB_Slip_Merge\|download_merged\|upload_doc3\|\\bcombine\\b" includes/ assets/ tests/   # only unrelated hits remain
php tests/test-*.php   # green; the two deleted merge tests are gone, not failing
```
- Packing Slips page: each batch row shows exactly **Packing Slips | Doc 4 (driver) | Cancel** — no
  Upload, no Combine, no merged link.
- Click **Packing Slips** → one PDF: page 1 is the cover (Zone/date/TAKE FROM HOLD/legend/N Orders/
  "Page 1 of Y"), pages 2..Y are the packer slips in order, continuous "Page X of Y" numbering, each
  slip's right region blank with the divider. 📷
- Click **Doc 4 (driver)** → the driver blocks, still paired one-per-order to the same batch.
- Generate + Cancel still work; cancel still audits.
- No PHP/JS errors; no references to the removed merge code remain.
