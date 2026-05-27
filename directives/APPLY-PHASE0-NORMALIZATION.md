# Billing overhaul — Phase 0: requisition normalization

Apply `phase0-normalization.patch` to the **meals-db** plugin checkout.

This is the first phase of the government-billing overhaul (see
billing-methodology-spec.md). Each phase is its own patch; this one is
self-contained and billing-foundational.

## Ordering (sixth in the series)

1. import-removal.patch
2. reorg-menus.patch
3. task-dates-delivery.patch
4. frequency-weeks-fix.patch
5. phase0-normalization.patch  <- this one

`git apply --check` confirms the fit. Against repo version 1.0.360.

## Why

Old SDNB/VAC requisitions were never standardized — clients got "X meals of
type Y per period Z" (1/day, 7/week, 30/month, 2/week, ...). The allowance
calc in `MealsDB_Allocation_Engine::calculate_permitted_for_month` coped with
this via hardcoded `== 7` / `== 14` / `== 31` branches — a guess that only
handled a few specific values and silently mishandled the rest.

This replaces that switch with one rate-based formula that normalizes ANY
X-per-Y requisition into a monthly permitted count:

```
permitted_per_month = floor( (allowance / days_in_period) * effective_days )
```

- `days_in_period`: 1 (day), 7 (week), or the month's calendar days (month).
- `effective_days`: the existing prorated day count (mid-month commence /
  termination already handled) — partial months scale automatically.
- floor() applied only to the final figure; **mains and sides independent**.

Examples: 1/day -> 31 (Jan); 2/day -> 62; 7/week -> 31; 2/week -> floor(8.86)
= 8; 30/month -> 30. The 2/day = 62 case matches the real VAC 62-meal claim.

## Changes (2 files)

```
M  includes/services/class-allocation-engine.php  (formula replaces the switch;
                                                    new private days_in_period())
A  tests/test-permitted-normalization.php          (15 checks)
```

## NOT touched (deliberate)

`class-invoice-generator.php` has a DUPLICATE copy of the magic-number logic
(get_allowance_data_for_clients, ~lines 325-341 — the legacy allocation-blind
billing path). It is intentionally left alone: phase 2 removes that whole path
(generators will read the engine's allocated figures), so fixing it now is
wasted work on code being deleted.

## Steps

```bash
git checkout -b billing-phase0
git apply --check phase0-normalization.patch
git apply phase0-normalization.patch
php -l includes/services/class-allocation-engine.php
php tests/test-permitted-normalization.php   # Ran 15 checks: 15 passed

clean=0; total=0; fails=""
for t in tests/test-*.php; do
  total=$((total+1)); out=$(php "$t" 2>&1)
  if echo "$out" | grep -qE "\[FAIL\]|[1-9][0-9]* failed|PHP Fatal|Uncaught|Parse error|not found"; then
    fails="$fails $(basename $t)"; else clean=$((clean+1)); fi
done
echo "RESULT: $clean / $total clean${fails:+ -- FAILS:$fails}"
```

Expected: **55 / 55 clean**.

## Note on existing data

This corrects the allowance LOGIC. Any stored monthly summaries computed under
the old magic-number logic will differ once months are recalculated. A full
recalculation happens naturally in phase 1 (engine rework); no separate
backfill needed for phase 0 alone, but be aware permitted_* values will change
for clients whose requisition wasn't one of the old hardcoded cases.

Report back: lint, `RESULT: X / Y`.
