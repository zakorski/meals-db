# Billing overhaul — Phase 4: VAC PDF generator

Apply `phase4-vac-pdf.patch` to the **meals-db** plugin checkout.

This is the final phase. The phase-2 VAC CSV becomes Stage 1; this is Stage
2 — stamping each veteran's row onto a pre-printed Blue Cross "Provider
Reimbursement Form / Access to Nutrition" template, merged into a single
Legal-size PDF.

## Ordering (tenth and final in the series)

1. import-removal.patch
2. reorg-menus.patch
3. task-dates-delivery.patch
4. frequency-weeks-fix.patch
5. phase0-normalization.patch
6. phase1-allocation-fill.patch
7. phase2-generators.patch
8. phase3-spillover-report.patch
9. **phase4-vac-pdf.patch**  <- this one

## IMPORTANT: binary file in this patch

The patch includes a binary asset (`assets/images/vac-blue-cross-form.jpg`,
~1.2MB — the blank Blue Cross form scan that becomes the page background).
You MUST apply with `--binary`:

```bash
git apply --binary phase4-vac-pdf.patch
```

A plain `git apply` may skip or corrupt the binary segment. `git apply
--check --binary phase4-vac-pdf.patch` confirms the fit before applying.

## What changes

`MealsDB_Invoice_Generator::generate_vac_pdf()` is replaced in place:

- Was: TCPDF, generating a homemade summary page per veteran. Janet never
  used this output — her real submission is the FPDF-style Blue Cross form.
- Is: dompdf, rendering HTML with absolute-positioned text overlaying the
  pre-printed Blue Cross form image. One page per veteran, merged.

**Approach** — recovered from the old `print.php` (FPDF) generator and
ported to dompdf since the plugin already ships dompdf as a composer
dependency. HTML with `position: absolute` spans at the same Legal-point
coordinates the legacy generator used. The same `background.jpg` from the
old system is bundled with the plugin (verified byte-exact on apply).

**Coordinate translation** — FPDF anchors text at the BASELINE; dompdf
positions an absolute element by its TOP edge. The new method subtracts
`font_pt * 0.85` (approximate Helvetica ascent) from the legacy y-coordinate
so visible glyphs land in the same place. Verified by the test against the
legacy values (e.g. fullname at x=90,y=743,font=12pt → top=732.8pt).

**Contract change** — `generate_vac_pdf` now returns PDF **bytes** instead
of a temp-file path. The AJAX caller in `class-ajax-invoice.php` is updated
to stream the bytes directly. No more temp files, no path-traversal defense
needed, no cleanup.

## Changes (4 files)

```
A  assets/images/vac-blue-cross-form.jpg   (the blank form, 1.2MB, Legal ratio)
A  tests/test-vac-pdf.php                  (23 checks: HTML template + dompdf smoke)
M  includes/services/class-invoice-generator.php
                                            - generate_vac_pdf() rewritten in place
                                            - build_vac_pdf_html() extracted as
                                              testable helper (pure HTML build)
                                            - private h() escape helper added
M  includes/ajax/class-ajax-invoice.php
                                            - download_vac_pdf updated for the
                                              bytes-returning contract
```

## Steps

```bash
git checkout -b billing-phase4
git apply --check --binary phase4-vac-pdf.patch
git apply --binary phase4-vac-pdf.patch

# Verify the binary reproduced
file assets/images/vac-blue-cross-form.jpg   # should report JPEG image, ~1.2 MB
identify assets/images/vac-blue-cross-form.jpg 2>/dev/null   # 2550x4200 if ImageMagick available

php -l includes/services/class-invoice-generator.php
php -l includes/ajax/class-ajax-invoice.php

php tests/test-vac-pdf.php   # Ran 23 checks: 23 passed

clean=0; total=0; fails=""
for t in tests/test-*.php; do
  total=$((total+1)); out=$(php "$t" 2>&1)
  if echo "$out" | grep -qE "\[FAIL\]|[1-9][0-9]* failed|PHP Fatal|Uncaught|Parse error|not found"; then
    fails="$fails $(basename $t)"; else clean=$((clean+1)); fi
done
echo "RESULT: $clean / $total clean${fails:+ -- FAILS:$fails}"
```

Expected: **59 / 59 clean**.

## Staging validation (visual)

The 23-check test confirms the HTML template is correct and dompdf renders
valid PDF bytes. But **the alignment of stamped text against the
pre-printed form is fundamentally visual** — only a side-by-side comparison
against Janet's actual Jan 2025 submission will confirm everything lands
in the right boxes.

1. Generate a VAC PDF for January 2025 (a month you have a real Janet
   submission to compare).
2. Open both side-by-side. For each veteran (start with Robert Ralph since
   the test grounds itself in his row: K# 8373037, 31 meals, $280.55):
   - **Name** in the Client Information block.
   - **Health card K#** to the right of the K label.
   - **Address** and **City** on their respective lines.
   - **Phone** at the far right.
   - **NB** as Province.
   - **Postal Code**.
   - **Date of service** (DD/MM/YY) inside the Claim Information row.
   - **"of N Meals"** the meal count in the central box.
   - **HST amount** in the (includes HST) box.
   - **Total** with `$` prefix at the bottom-right TOTAL.
   - **Second date** next to the Provider signature.
3. If any field is off by a few points (the baseline-to-top translation
   uses 0.85 × font_pt as an approximation), the coordinates can be tweaked
   in `build_vac_pdf_html()`'s `$coords` table.

## What's NOT cleaned up

- The legacy `get_invoice_data_for_clients` and `get_allocation_based_billing`
  fetchers in the generator are still present but unused (carryover from
  phase 2's deliberate scope).
- The engine's `build_desired_allocation_rows` and `lock_allocation_month`
  private helpers are still present but unused (carryover from phase 1).

A separate cleanup phase can remove these whenever you want — they don't
hurt anything sitting there, and pulling them in a later focused patch
keeps the diff readable.

## Billing overhaul complete

With phase 4 applied, the full chain (10 patches) delivers:

- Reports/Data Ops menu reorganization (`reorg-menus`)
- Task system + Weekly Delivery List (`task-dates-delivery`)
- Quick Order frequency-weeks fix (`frequency-weeks-fix`)
- Daily-rate normalization formula (`phase0-normalization`)
- Delivery-month allowance fill with single-month spill (`phase1-allocation-fill`)
- Three government generators bill what the engine allocated, in
  format-correct shape (`phase2-generators`)
- Over-allowance spill report in the Reports menu (`phase3-spillover-report`)
- VAC PDF in the exact form Janet submits (`phase4-vac-pdf`)

Suite: 59/59 across the chain.
