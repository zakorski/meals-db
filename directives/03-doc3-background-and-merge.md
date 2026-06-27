# 03 — Doc 3 rasterization + doc 4 overlay merge

> **✅ COMPLETE (2026-06-26, PR #438, commit 8ebb5db).** `includes/services/class-slip-merge.php` —
> `validate_doc3` (PDF magic-byte + page_count===order_count; Imagick count with a `/Type /Page` heuristic
> fallback so it runs off the live host), `rasterize_doc3` (Imagick 300-DPI → 3300x2550 JPEGs, orientation
> detected, `class_exists('Imagick')`-guarded → degraded event not fatal), `combine` (VAC background-image
> dompdf overlay using the unit 02 fragment + `DOC4_BLOCK_*` coords, NO divider, scratch cleanup in finally).
> **Rasterize/overlay are live-only.** Tests: `tests/test-slip-merge-validate.php` (13 checks).

## Goal
Turn an uploaded doc 3 PDF into per-page backgrounds and composite the saved doc 4 driver block onto
each page's right region, producing one finished print-ready PDF.

## Reference
- Imagick is available on the live host (confirmed). Use it for PDF→image.
- VAC overlay precedent (background-image + absolutely-positioned content rendered by dompdf):
  `includes/services/class-invoice-generator.php` serialize_vac_pdf_from_csv (~line 1414).
- dompdf render entry: `MealsDB_Slip_PDF_Generator::render_with_dompdf()` (line 824).

## Step 1 — rasterize doc 3 to per-page backgrounds (300 DPI, faithful)
New helper, e.g. `MealsDB_Slip_Merge::rasterize_doc3(string $pdf_path): array` returns ordered list of
background JPEG paths (one per page).
- For each page: render at **300 DPI** → **3300 x 2550 px** Letter landscape, faithful preserve
  (no thresholding). Imagick: `setResolution(300,300)`, read page, `setImageFormat('jpeg')`, write.
- **Orientation: DETECT, do not assume.** The reference sample was Letter PORTRAIT (612x792) with content
  rotated, needing +90°. Production: if a page reads as portrait (h>w), rotate to landscape; handle 180°
  if detectable. Target every page to upright Letter LANDSCAPE 3300x2550 before compositing.
- Write backgrounds to a temp/work dir under `wp_upload_dir()['basedir'].'/mealsdb-slips/tmp/'`.

## Step 2 — composite doc 4 onto each page
For page N (1-based), take the saved doc4_payload[N-1] (from the batch — unit 01/04) and overlay it.
Two viable mechanisms (use the VAC pattern = option A):
- **A (matches VAC, preferred):** build an HTML page whose `@page` is Letter landscape, with the doc 3
  background JPEG as a full-page `background-image` (file:// URL; set dompdf `isRemoteEnabled`/`chroot`
  as render_with_dompdf already does), and the doc 4 driver block absolutely-positioned at the calibrated
  coords (left 7.4in, Collect top 4.62in, width 3.2in — unit 02). Render that page via dompdf → one PDF
  page. NO divider drawn by doc 4 (background already has it).
- (B, only if A has image-scaling issues: composite with Imagick directly — render doc4 block to a
  transparent PNG, `compositeImage` onto the background, assemble PDF. A is preferred for text crispness.)

Collect amount: use the real amount due for that order (see unit 02 note), already captured in the
saved doc4_payload at generation time.

## Step 3 — concatenate pages → one merged PDF
Assemble all composited pages into a single PDF, in page order. With option A, render all pages in one
dompdf document (each order = one `.page` with `page-break-after: always`), or render per-page and
concatenate. Output bytes → saved to `merged_path` (unit 04 stores it).

## Validation hook (used by the AJAX layer, unit 05)
Expose `MealsDB_Slip_Merge::validate_doc3(string $pdf_path, int $expected_order_count): array`
returning `['ok'=>bool, 'page_count'=>int, 'reason'=>string]`. Valid IFF:
- the file is a readable PDF, AND
- page count === expected_order_count (orders are never multi-page → one doc3 page per order).
A mismatch returns ok=false (the Combine button stays disabled — unit 05/06). This is a BLOCK, by design.

## Positional pairing (no content guard — operator decision)
doc4_payload[N-1] pairs with doc3 page N strictly by position. Do NOT attempt to read order numbers off
the scan or reorder. Collation correctness is the team's responsibility (page numbers on the docs are
their safety net). The page-COUNT check above is the only guard.

## Verify
```
php -l includes/services/class-slip-merge.php   # (new file)
php tests/test-*.php
# Functional: upload a known doc 3 (page count = order count), combine, open the merged PDF, confirm
# each driver block lands in the blank right region below the divider, one block per page, no doubling.
```
