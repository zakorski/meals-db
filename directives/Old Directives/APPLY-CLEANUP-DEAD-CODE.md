# Billing overhaul — Cleanup: remove dead overage paths and phase-1/2 remnants

Apply `cleanup-dead-code.patch` to the **meals-db** plugin checkout.

This is a follow-up to phases 0-4 that removes the dead code each phase
deliberately left behind for incremental safety. It also bundles two small
hardening fixes from PR #379 (strict month regex + defence-in-depth auth
gate on `spillover_report`) since they touch overlapping files.

## Ordering (eleventh in the series)

1. import-removal.patch
2. reorg-menus.patch
3. task-dates-delivery.patch
4. frequency-weeks-fix.patch
5. phase0-normalization.patch
6. phase1-allocation-fill.patch
7. phase2-generators.patch
8. phase3-spillover-report.patch
9. phase4-vac-pdf.patch
10. cleanup-dead-code.patch  <- this one

## What's removed

### From the allocation engine (phase-1 remnants)
- `MealsDB_Allocation_Engine::build_desired_allocation_rows()` — old
  per-order, date-prorated row builder.
- `MealsDB_Allocation_Engine::lock_allocation_month()` — old per-order
  row-locking helper.
- `MealsDB_Allocation_Engine::fingerprint_allocation_rows()` — helper used
  only by the above.
- `tests/test-allocation-engine-lock.php`,
  `tests/test-allocation-engine-fingerprint.php` — tests for the removed
  helpers.
- The "remnants of that path" doc comment in `allocate_order` is trimmed.

### From the invoice generator (phase-2 remnants)
- `get_invoice_data_for_clients()` — per-order data fetcher, replaced by
  `get_phase2_billing_data()`.
- `get_allowance_data_for_clients()` — magic-number allowance switch the
  phase-0 doc explicitly flagged for removal.
- `get_allocation_based_billing()` — min/cap allowance data path, replaced
  by the engine's per-month summary.

### The whole overage-preview flow (no longer meaningful under phase 1)
Under phase 1, overage doesn't exist at invoice time — the engine has
allocated everything, and any "couldn't fit" event is in the spillover
errors table that phase 3 already reports on. So the preview+create flow
is functionally dead. Removed:

- Service: `get_overage_product_ids()` (the invoice-generator one — the
  slip generator's *private* same-named helper is **kept**),
  `get_sdnb_overages()`, `get_vac_overages()`.
- AJAX: `mealsdb_preview_overages`, `mealsdb_create_overage_orders` actions
  and handlers (~270 lines).
- View: the entire "Import Overages" card in `views/admin-invoice.php`
  (form inputs, preview table, both buttons).
- JS: all overage handlers in `assets/js/invoice.js` (preview, create,
  show/hide logic, message helper).

### Hardening (from PR #379)
- `MealsDB_Reports::spillover_report()` adds defence-in-depth via
  `is_authorized_to_read_reports()` so direct service callers (WP-CLI,
  REST, cron) can't bypass the capability gate that lives at the AJAX
  layer.
- Both the service and the AJAX handler tighten the month regex from
  `\d{4}-\d{2}` to `\d{4}-(0[1-9]|1[0-2])` — the lax form accepted
  `2025-13` (DateTime throws → 500) and `2025-00` (DateTime silently
  normalises to the previous December, returning data for the wrong
  month). The test adds explicit rejection assertions for both.

## What's kept (deliberately)

- `MealsDB_Slip_PDF_Generator::get_overage_product_ids()` — different
  concern (the physical overage SKU on delivery slips, not invoice
  overage). Still used by the slip generator.
- `split_into_invoice_lines()` in the invoice generator — used by the
  legacy SDNB two-line splitter path.
- `MealsDB_Invoice_Generator::get_fee_product_ids()` — used by the new
  phase-2 contribution-sum query.

## Numbers

- Net lines removed: about 1100 (engine ~200, invoice-generator ~525,
  AJAX ~270, view + JS ~150) minus a few new lines of hardening comments
  and tighter docblocks.
- Files: 8 modified, 2 deleted.

## Changes (10 files)

```
M  assets/js/invoice.js                            (overage handlers removed)
M  includes/ajax/class-ajax-invoice.php            (preview/create handlers removed)
M  includes/ajax/class-ajax-reports.php            (strict month regex)
M  includes/services/class-allocation-engine.php   (three private helpers removed)
M  includes/services/class-invoice-generator.php   (six methods removed,
                                                    docblocks updated)
M  includes/services/class-reports.php             (auth gate + strict month regex)
M  tests/test-spillover-report.php                 (auth-stub helpers + 13/00 rejection)
M  views/admin-invoice.php                         (Import Overages card removed)
D  tests/test-allocation-engine-fingerprint.php
D  tests/test-allocation-engine-lock.php
```

## Steps

```bash
git checkout -b billing-cleanup
git apply --check cleanup-dead-code.patch
git apply cleanup-dead-code.patch

# Lint everything touched
for f in \
  includes/services/class-allocation-engine.php \
  includes/services/class-invoice-generator.php \
  includes/services/class-reports.php \
  includes/ajax/class-ajax-invoice.php \
  includes/ajax/class-ajax-reports.php \
  views/admin-invoice.php; do
  php -l "$f" | tail -1
done
node -e "new Function(require('fs').readFileSync('assets/js/invoice.js','utf8'))" && echo "JS valid"

# Full suite (now 57 — the 2 removed tests are gone)
clean=0; total=0; fails=""
for t in tests/test-*.php; do
  total=$((total+1)); out=$(php "$t" 2>&1)
  if echo "$out" | grep -qE "\[FAIL\]|[1-9][0-9]* failed|PHP Fatal|Uncaught|Parse error|not found"; then
    fails="$fails $(basename $t)"; else clean=$((clean+1)); fi
done
echo "RESULT: $clean / $total clean${fails:+ -- FAILS:$fails}"
```

Expected: **57 / 57 clean**.

## Staging validation

1. Generate all three invoices for a known month — output should be
   identical to before the cleanup. (Verified mechanically: no production
   path called the removed methods.)
2. Confirm the invoice admin page no longer shows "Import Overages" or its
   buttons. The "Generate Invoices" controls above are unaffected.
3. Confirm the Reports → Over-Allowance Spill report still works with
   month values like `2025-01`, `2025-12`. Now also confirm it
   rejects `2025-13` and `2025-00` cleanly (an error message, not a 500).

## Billing overhaul: now closed

With cleanup applied, the 11-patch chain delivers:

- Reports/Data Ops reorg
- Task system + delivery dates
- Quick Order frequency-weeks fix
- Daily-rate normalization formula
- Delivery-month allowance fill with single-month spill
- Three government generators producing real-format upload-ready output
- Over-allowance spill report
- VAC PDF in Janet's exact submission shape
- Dead-code cleanup of pre-overhaul paths

Suite: 57/57 across the chain.
