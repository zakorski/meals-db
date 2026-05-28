# Billing overhaul — Phase 2: generators use allocated meals

Apply `phase2-generators.patch` to the **meals-db** plugin checkout.

This is where the new methodology finally produces government invoices that
match Janet's real submissions. Phase 0 (normalization) and phase 1 (engine
allowance fill) are the foundation; this is the layer that reads engine output
and emits the three CSVs in their required formats.

## Ordering (eighth in the series)

1. import-removal.patch
2. reorg-menus.patch
3. task-dates-delivery.patch
4. frequency-weeks-fix.patch
5. phase0-normalization.patch
6. phase1-allocation-fill.patch
7. phase2-generators.patch  <- this one

`git apply --check` confirms the fit.

## What changes

All three government invoice generators (legacy SDNB, new portal SDNB, VAC
CSV) now read **one canonical data source**: what the allocation engine
allocated to the billing month, plus the contribution line-item sum for the
same scope. No more allowance-cap min() at invoice time, no more BNM, no more
overage columns — the engine's fill (phase 1) already enforced allowance with
single-month spill.

**Per-format behaviour** (verified against Janet's real submissions):

- **SDNB new portal (18-col CSV):** one row per client, SCI Id left BLANK
  (portal assigns on upload — confirmed by user), Service Request Id from
  client's `sdnb_service_request_id`. Emits Units, Rate, Contribution, Tax
  as separate fields — the portal computes the total. Contribution is the
  monthly sum of product-5675 line items on orders the engine allocated to
  this month (blank for ~99% of clients, populated for the assessed few like
  Janet's Terrence LeBlanc / $19.77).

- **Legacy SDNB (99-col CSV):** one or two lines per client (two-line split
  preserved from existing code), upload-ready. **Dept. Cost = Basic −
  Contribution**, **Total Invoice Line Cost = Dept. + Tax** — confirmed
  against Janet's Jan 2025 Moncton submission (Brammah Peter: basic 366.50,
  contrib 10.24, dept 356.26; Doyle Linda: basic 410.48, contrib 236.69, dept
  173.79). The Dept. Cost column write was previously emitting basic cost
  raw — now correctly subtracts contribution.

- **VAC CSV (stage 1 of the PDF pipeline, phase 4 will consume):**
  `new_total = mains_cost + sides_cost + HST`. No contribution subtraction
  — confirmed against old vet-invoice line 521 and Janet's Robert Ralph PDF
  (31 × $9.05 = $280.55 exactly). The duplicate magic-number allowance
  switch (the second of the two copies the spec flagged) is REMOVED; the
  engine's permitted figure is used for the informational "Monthly
  Allowance" / "Allowance Remaining" columns. BNM Mains / Overage columns
  emit 0 — they're billing-irrelevant now.

## Changes (2 files)

```
A  tests/test-phase2-billing.php       (15 checks, Janet-grounded)
M  includes/services/class-invoice-generator.php
```

Inside the generator:
- New `get_phase2_billing_data()` — the canonical fetcher (one row per
  client with allocated_mains/sides/tax_sides/nontax_sides + resolved_rate
  + contribution_cents + basic_cents + tax_cents).
- New `sum_contribution_for_orders()` — sums product-5675 line items
  across a set of wc_order_ids by reading the WC HPOS items + itemmeta
  tables directly.
- `generate_sdnb_new_portal()` rewritten: one row per client, SCI Id
  blank, full new-portal format.
- `generate_sdnb_legacy()` data source switched to phase 2 (still uses
  the existing two-line splitter `split_into_invoice_lines`); Dept. Cost
  row-emission bug fixed.
- `generate_vac_csv()` rewritten: no caps, no overage, no contribution
  subtraction, duplicate magic-number allowance switch removed.

## NOT touched (deferred to later phases)

- The old `get_invoice_data_for_clients()` and `get_allocation_based_billing()`
  fetchers are now unused by any generator but **left in place** as dead code
  — a later cleanup phase can remove them. The phase-1 doc made the same call
  on the engine's old `build_desired_allocation_rows` / `lock_allocation_month`
  helpers.
- The VAC PDF generator (`generate_vac_pdf`) — phase 4 ports the old FPDF
  background-image approach using the new VAC CSV as input.
- Phase 3: the over-allowance spill report (Reports menu).

## Steps

```bash
git checkout -b billing-phase2
git apply --check phase2-generators.patch
git apply phase2-generators.patch
php -l includes/services/class-invoice-generator.php
php tests/test-phase2-billing.php   # Ran 15 checks: 15 passed

clean=0; total=0; fails=""
for t in tests/test-*.php; do
  total=$((total+1)); out=$(php "$t" 2>&1)
  if echo "$out" | grep -qE "\[FAIL\]|[1-9][0-9]* failed|PHP Fatal|Uncaught|Parse error|not found"; then
    fails="$fails $(basename $t)"; else clean=$((clean+1)); fi
done
echo "RESULT: $clean / $total clean${fails:+ -- FAILS:$fails}"
```

Expected: **57 / 57 clean**.

## Staging validation (do this carefully — billing output)

1. Run all three generators against a known historical month
   (e.g. November 2025 for new portal, January 2025 for legacy Moncton + VAC
   CSV) and **byte-diff or column-diff against Janet's actual submissions**.
   - New portal: 140 rows, blank SCI Id, one row per client.
   - Legacy: 99 columns, Dept. Cost = Basic - Contribution (check the
     non-zero-contribution clients).
   - VAC CSV: new_total = mains_cost + sides_cost + HST.
2. Pick a client you know had non-zero contribution (e.g. Brammah Peter or
   Doyle Linda from Jan 2025) and verify the row math by hand.
3. Generate the new-portal invoice in a month where one of the 12 legacy
   contribution-having clients participated; confirm the contribution column
   is populated (was reading from the stored field, now reading the
   line-item sum — should still produce the same value if the order process
   applied it once that month).

## Known carryover

- The Dept. Cost column emits `basic - contribution` per Janet's data, even
  though the **spec §4b earlier said no subtraction**. The data unambiguously
  shows subtraction in legacy SDNB. Spec was wrong; data wins. The new-portal
  path correctly emits separate fields (no plugin-side subtraction); VAC
  correctly doesn't subtract.
- Dead-code cleanup of `get_invoice_data_for_clients` /
  `get_allocation_based_billing` deferred to a later cleanup pass.
