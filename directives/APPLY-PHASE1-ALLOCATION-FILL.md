# Billing overhaul — Phase 1: delivery-month allowance fill engine

Apply `phase1-allocation-fill.patch` to the **meals-db** plugin checkout.

This is the billing-critical engine rework. Phase 2 (generators) and phase 3
(spill report) sit on top of it.

## Ordering (seventh in the series)

1. import-removal.patch
2. reorg-menus.patch
3. task-dates-delivery.patch
4. frequency-weeks-fix.patch
5. phase0-normalization.patch
6. phase1-allocation-fill.patch  <- this one

`git apply --check` confirms the fit. Against repo version 1.0.360 + the
prior 5 patches.

## What changes

**The model.** Replaces the old per-order, date-prorated allocation with a
**stateful per-client-month delivery-month allowance fill**:

- Each order is treated as a delivery on one date; meals are allocated to the
  **month of that delivery date**.
- The client's per-month allowance (from §3.1's daily-rate formula, phase 0)
  is a **hard cap**. Process the month's deliveries in delivery-date order,
  filling toward remaining headroom.
- If a delivery doesn't fit, the overflow **spills to the next month only**.
- If it doesn't fit there either, a multi-month-spillover error is logged
  to a new `mealsdb_allocation_errors` table (no silent loss, no cascading
  spill).
- **Mains and sides are independent caps.**
- A client-month is **recomputed from scratch** from all that client's orders
  whenever it is rebuilt — never incrementally adjusted. Deterministic
  regardless of order-processing sequence.

**The triggers.** Order hooks now mark `(client_id, billing_month)` dirty in a
new `mealsdb_client_month_dirty` table. The actual rebuild runs when:
- An invoice is generated — scoped to that invoice's client filter for that
  month (e.g. SDNB Moncton January rebuilds only Moncton/legacy/SDNB clients
  dirty for January). This is "scope A" from the spec.
- An operator clicks the new **Recalculate Allocations** button in Data Ops
  — rebuilds everything dirty.

## Changes (9 files)

**New**
- `includes/services/class-allocation-rebuilder.php` — the rebuilder.
  Public: `mark_dirty`, `rebuild_client_month`, `rebuild_for_invoice`,
  `rebuild_all_dirty`. Private: the `fill_months` algorithm.
- `tests/test-allocation-rebuilder.php` — 30 checks. Single-month fit, single
  spill, multi-month error capping, mains/sides independence, in-month
  delivery-date ordering, DELETE-before-fill (recompute-from-orders).

**Schema**
- `includes/class-tables.php` — two new table constants
  (`CLIENT_MONTH_DIRTY`, `ALLOCATION_ERRORS`) added to the whitelist.
- `includes/class-schema.php` — schema definitions for both new tables
  (with indexes).

**Engine**
- `includes/services/class-allocation-engine.php` — `allocate_order` and
  `deallocate_order` rewritten as mark-dirty operations (same public
  signatures). The old private helpers (`build_desired_allocation_rows`,
  `lock_allocation_month`) are dead code now and left in place; a later
  cleanup phase can remove them.

**Generators**
- `includes/services/class-invoice-generator.php` — all three generators
  (`generate_sdnb_legacy`, `generate_sdnb_new_portal`, `generate_vac_csv`)
  call a new `rebuild_dirty_for_invoice` helper immediately after their
  `query_clients` call, scoped to that filter + month.

**Data Ops UI**
- `includes/ajax/class-ajax-settings.php` — new
  `wp_ajax_mealsdb_recalculate_allocations` action and handler.
- `views/data-ops.php` — new "Recalculate Allocations" button.
- `assets/js/settings.js` — click handler that hits the AJAX action.

## Steps

```bash
git checkout -b billing-phase1
git apply --check phase1-allocation-fill.patch
git apply phase1-allocation-fill.patch

# Lint
for f in \
  includes/class-tables.php \
  includes/class-schema.php \
  includes/services/class-allocation-engine.php \
  includes/services/class-allocation-rebuilder.php \
  includes/services/class-invoice-generator.php \
  includes/ajax/class-ajax-settings.php \
  views/data-ops.php \
  tests/test-allocation-rebuilder.php; do
  php -l "$f" | tail -1
done
node -e "new Function(require('fs').readFileSync('assets/js/settings.js','utf8'))" && echo "JS valid"

# Run the new test in isolation
php tests/test-allocation-rebuilder.php   # Ran 30 checks: 30 passed

# Full suite
clean=0; total=0; fails=""
for t in tests/test-*.php; do
  total=$((total+1)); out=$(php "$t" 2>&1)
  if echo "$out" | grep -qE "\[FAIL\]|[1-9][0-9]* failed|PHP Fatal|Uncaught|Parse error|not found"; then
    fails="$fails $(basename $t)"; else clean=$((clean+1)); fi
done
echo "RESULT: $clean / $total clean${fails:+ -- FAILS:$fails}"
```

Expected: **56 / 56 clean**.

## DB migration

The two new tables are created by the existing schema sync. After applying:
go to Data Ops -> Update DB Schema (or the equivalent admin action) so the
new tables are created in the live database. Without this, `mark_dirty` and
the rebuilder will fail with "table not found" on first use.

## What stays stable

- The `delivery_allocations` and `meals_client_allocations` (summary) tables
  are unchanged — the rebuilder writes the same rows, the summary still SUMs
  them via the existing `recalculate_month_totals`.
- The engine's public surface (`allocate_order`, `deallocate_order`,
  `recalculate_month_totals`, etc.) is unchanged — only their internals.
- Phase 0's `calculate_permitted_for_month` is the cap the fill uses.

## Staging validation

After the schema is synced:

1. Place a single small order for a government client. Check
   `meals_client_month_dirty` — there should be one row.
2. Generate an invoice that includes that client and month. Check that:
   - The dirty row is cleared.
   - A `delivery_allocations` row exists for the order in the expected month.
   - The invoice's units for that client match the row.
3. Place an order whose mains exceed the client's monthly allowance. After
   the rebuild: the delivery month gets `mains_count = allowance`, the next
   month gets the overflow.
4. Configure a deliberately small allowance (e.g. 2) and place a delivery of
   10 mains. After rebuild: 2 in delivery month, 2 in next month, an
   `mealsdb_allocation_errors` row with `mains_unplaced = 6` and
   `error_type = multi_month_spillover`.
5. Click **Recalculate Allocations** in Data Ops with no dirty entries —
   reports "Rebuilt 0 client-months."

Report back: lint, `RESULT: X / Y`, staging validation results.

## Known carryover (not in scope here)

- The duplicate magic-number allowance logic in `class-invoice-generator.php`
  (get_allowance_data_for_clients, ~lines 325-341) is still present. Phase 2
  removes that whole path. Flagged in the spec.
- The old private helpers in the engine (`build_desired_allocation_rows`,
  `lock_allocation_month`) are dead code now — left for a cleanup phase to
  keep this patch focused.
