# Task: Add Reports and Data Ops submenus; reorganize tools

Apply `reorg-menus.patch` to the **meals-db** plugin checkout.

## Ordering

This is built on top of the import-removal change. Apply in this order:
1. `import-removal.patch`  (APPLY-IMPORT-REMOVAL.md)
2. `reorg-menus.patch`     ← this one

`git apply --check reorg-menus.patch` will fail if import-removal isn't
applied first. Both are against repo version 1.0.360.

## What this does

Adds two new left-ribbon submenus under MealsDB — **Reports** and **Data
Ops** — and relocates existing tools into them. No tool's behaviour changes;
only the page hosting each button moves. All AJAX actions, handlers, and JS
wiring are unchanged.

**Reports** (read-only; sub-tabs via ?sub=):
- Fee Reconciliation (Contribution Checker, Delivery Fee Checker)
- Private Sales (Private Customer Sales Report)
- Order Errors (Order Error Report)

**Data Ops** (all data-mutating operations, relocated from the old Settings
and Updates cards):
- Backfill Delivery Day, Backfill Next Dates, Private Customer Backfill
  (+preview), Deactivation Sweep (+preview), Enrich Skeletons, Sync Product
  Display Data  (from Settings)
- Update Database Schema, Fetch Products, Force Rebuild, Complete DB Sync,
  Backfill Allowance Data, Backfill Addresses & Rates, Allocation Engine
  Backfill  (from Updates)

**Removed entirely:** Delete Non-Admin Users — UI block, the POST handler in
`class-admin-ui.php`, the orphaned `render_user_delete_summary()` method, and
the `class-user-delete.php` file (its only other method was a private helper
used solely by the deleted feature). Its test is removed too.

**Tab bar:** the `fees`, `privates`, `errors`, and `updates` tabs are removed
(now empty / relocated). The `settings` tab stays but keeps only the
non-relocated items: encryption key, shadow mode, overage product IDs, zone
delivery schedule, and Save Settings.

**Consolidated engine:** Backfill Delivery Day is added as **phase 8**, run
as the final step of the consolidated migration so clients created by phases
1-7 immediately get a delivery_day filled from the zone schedule. It is a
blank-fill (only updates clients whose delivery_day is empty), idempotent,
single-pass. The standalone Data Ops button remains and uses the same logic.

## File changes (9)

```
A  tests/test-consolidated-delivery-day.php
D  includes/class-user-delete.php
D  tests/test-user-delete-anonymise.php
M  assets/js/admin-migration.js          (phase 8 in the consolidated loop)
M  includes/admin/class-migration-page.php (phase 8 in the progress list)
M  includes/class-admin-ui.php            (new pages, enqueue, tab removal)
M  includes/services/class-migration-consolidated.php (run_phase_delivery_day)
M  views/settings.php                      (data-op sections removed)
R  views/updates.php -> views/data-ops.php (relocated, minus delete-users)
```

## Steps

```bash
git checkout -b reports-dataops-reorg
git apply --check reorg-menus.patch        # must be clean
git apply reorg-menus.patch
git add -A && git status --short

# Lint
for f in includes/class-admin-ui.php includes/admin/class-migration-page.php \
         includes/services/class-migration-consolidated.php \
         views/settings.php views/data-ops.php; do
  php -l "$f" || echo "LINT FAILED: $f"
done

# New phase test
php tests/test-consolidated-delivery-day.php   # Ran 8 checks: 8 passed

# Full suite
clean=0; total=0; fails=""
for t in tests/test-*.php; do
  total=$((total+1)); out=$(php "$t" 2>&1)
  if echo "$out" | grep -qE "\[FAIL\]|[1-9][0-9]* failed|PHP Fatal|Uncaught|Parse error|not found"; then
    fails="$fails $(basename $t)"
  else clean=$((clean+1)); fi
done
echo "RESULT: $clean / $total clean${fails:+ -- FAILS:$fails}"
```

Expected: **52 / 52 clean**.

## Staging validation (the load-bearing checks)

The risk in a UI move is a button losing its JS. Verify each relocated button
actually fires:

1. MealsDB menu shows **Reports** and **Data Ops** submenus.
2. **Reports** page: the three sub-tabs (Fee Reconciliation / Private Sales /
   Order Errors) each load and run their report.
3. **Data Ops** page: confirm each control works —
   - Update Database Schema, Force Rebuild (REBUILD confirm), Fetch Products
   - Complete DB Sync (dry run)
   - Backfill Allowance / Addresses / Allocation Engine (dry run)
   - Private Backfill / Deactivation (preview), Enrich (dry run)
   - Sync Product Display Data
   - Backfill Delivery Day
   Watch the browser console for errors and confirm AJAX calls return.
4. **Settings** tab: encryption key, shadow mode, overage IDs, zone schedule,
   Save Settings — all present and saving works.
5. Confirm the old Fee Reconciliation / Private Sales / Order Errors /
   Updates tabs are gone from the main tab bar, and Delete Non-Admin Users
   no longer exists anywhere.
6. Migration page → Consolidated Migration: confirm it now shows 8 phases and
   a dry run walks through Backfill Delivery Day as the final phase.

Report back: `git status`, lint, `RESULT: X / Y`, and the staging results.
