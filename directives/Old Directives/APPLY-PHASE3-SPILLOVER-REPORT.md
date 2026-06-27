# Billing overhaul — Phase 3: over-allowance spillover report

Apply `phase3-spillover-report.patch` to the **meals-db** plugin checkout.

This is the operational visibility layer that replaces the old overage
billing concept. Under the new model the engine fills monthly allowance with
spill (phase 1), so legitimate over-allowance events still happen — they're
just allocated to the next month rather than distorting the invoice. This
report surfaces them.

## Ordering (ninth in the series)

1. import-removal.patch
2. reorg-menus.patch
3. task-dates-delivery.patch
4. frequency-weeks-fix.patch
5. phase0-normalization.patch
6. phase1-allocation-fill.patch
7. phase2-generators.patch
8. phase3-spillover-report.patch  <- this one

`git apply --check` confirms the fit.

## What changes

A new **Over-Allowance Spill** subtab is added to the Reports menu (alongside
Fee Reconciliation, Private Sales, Order Errors). The report takes a
month/year selector and lists every delivery for that month whose meals
exceeded the client's monthly allowance and spilled into the next month.

It shows two flavors of spill in one table:

- **Normal single-month spills** — orders with `delivery_allocations` rows in
  both the delivery month AND the next month, written there by the engine's
  fill (phase 1). Not an error, just visibility.

- **Multi-month-spillover errors** — rows logged by the rebuilder to
  `mealsdb_allocation_errors` when the spill couldn't fit in the next month
  either. Flagged in the UI (highlighted row + 'Multi-Month Error' label +
  the original error message). These need operator attention.

CSV export of the same data.

## Changes (6 files)

```
A  views/spillover-report.php             (the report UI)
A  assets/js/spillover-report.js          (month picker → AJAX → table → CSV export)
A  tests/test-spillover-report.php        (20 checks)
M  includes/class-admin-ui.php            (subtab + JS bundle enqueue)
M  includes/services/class-reports.php    (spillover_report() + export_spillover_csv())
M  includes/ajax/class-ajax-reports.php   (mealsdb_spillover_report AJAX handler)
```

## Steps

```bash
git checkout -b billing-phase3
git apply --check phase3-spillover-report.patch
git apply phase3-spillover-report.patch
php -l includes/services/class-reports.php
php -l includes/ajax/class-ajax-reports.php
php -l views/spillover-report.php
node -e "new Function(require('fs').readFileSync('assets/js/spillover-report.js','utf8'))" && echo "JS valid"

php tests/test-spillover-report.php   # Ran 20 checks: 20 passed

clean=0; total=0; fails=""
for t in tests/test-*.php; do
  total=$((total+1)); out=$(php "$t" 2>&1)
  if echo "$out" | grep -qE "\[FAIL\]|[1-9][0-9]* failed|PHP Fatal|Uncaught|Parse error|not found"; then
    fails="$fails $(basename $t)"; else clean=$((clean+1)); fi
done
echo "RESULT: $clean / $total clean${fails:+ -- FAILS:$fails}"
```

Expected: **58 / 58 clean**.

## Staging validation

1. Navigate to MealsDB → Reports → Over-Allowance Spill. The picker defaults
   to the current month.
2. Pick a known-quiet month — table should show no spillovers.
3. Force a spill on staging: take a client with a small monthly allowance
   (say, 5 mains/month) and place a delivery for 10 mains. After invoice
   generation (or manual Recalculate Allocations from Data Ops) the report
   for that month should show one row: mains_in_month=5, mains_spilled=5,
   status "Spilled to next month."
4. Force a multi-month error: do the same on a 2 mains/month client with a
   delivery of 10. Both months only absorb 2 each (4 placed), 6 unplaced.
   The report should show one row flagged "Multi-Month Error" with the
   logged message.
5. Click Export CSV — file downloads with the expected columns.

Report back: lint, `RESULT: X / Y`, staging results.

## What's next

Phase 4 — VAC PDF generator. Ports the old FPDF background-image approach
(coordinates already mapped in spec Appendix D) to produce the merged
per-veteran PDF Janet actually submits. Stage 1 (the VAC CSV from phase 2)
feeds it. Last phase of the billing overhaul.
