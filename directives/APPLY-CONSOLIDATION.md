# Task: Apply the consolidated migration tool to the MealsDB plugin

You are working in the **meals-db** WordPress plugin checkout. Apply the
accompanying patch, which consolidates eight scattered WP/WooCommerce →
`meals_*` data-movement tools into a single engine, fixes three bugs, and
deletes the five now-absorbed classes. Work on a branch and do not merge
until the verification steps below pass and a human has reviewed the diff.

The patch file is `consolidation.patch`, alongside this document.

---

## 0. Precondition — confirm the baseline

The patch was generated against plugin **version 1.0.353** (see the
`Version:` header in `meals-db-main.php`). It uses exact-context hunks, so
it will only apply cleanly to that source.

```bash
grep -m1 "Version:" meals-db-main.php     # expect: * Version: 1.0.353
git status --porcelain                     # expect: clean working tree
```

- If the version is **1.0.353** and the tree is clean → proceed to step 1.
- If the version **differs**, or `git apply --check` (step 1) reports
  failures → **stop and do not force it.** The surrounding code has drifted.
  In that case, re-derive the changes against the current code instead of
  applying the patch verbatim: read `consolidation.patch` to understand each
  change, then reproduce them by hand. The patch is the source of truth for
  *intent*; the exact line numbers are not.

---

## 1. Create a branch and dry-run the patch

```bash
git checkout -b consolidate-migration
git apply --check consolidation.patch
```

`--check` must report nothing (clean). If it errors, see the drift note in
step 0.

---

## 2. Apply

```bash
git apply consolidation.patch
git add -A
git status --short
```

Expected `git status --short` (17 files):

```
A  includes/services/class-migration-consolidated.php
A  tests/test-consolidated-allowances-no-clobber.php
D  includes/services/class-backfill-addresses.php
D  includes/services/class-backfill-allocations-engine.php
D  includes/services/class-backfill-allowances.php
D  includes/services/class-backfill-next-dates.php
D  includes/services/class-backfill-private-clients.php
M  assets/js/admin-migration.js
M  includes/admin/class-migration-page.php
M  includes/ajax/class-ajax-migration.php
M  includes/ajax/class-ajax-settings.php
M  includes/class-private-intake.php
M  includes/services/class-migration.php
M  tests/test-backfill-private-clients-criteria.php
M  tests/test-backfill-private-clients-dry-run.php
M  tests/test-backfill-private-clients-enrich.php
M  tests/test-task-workflow-next-order-date-anchoring.php
```

If your tooling prefers it, `git apply --index consolidation.patch` stages
in one step. The five `D` entries are real file deletions — confirm they're
gone, not just untracked.

---

## 3. Lint every touched PHP file

```bash
for f in \
  includes/services/class-migration-consolidated.php \
  includes/services/class-migration.php \
  includes/ajax/class-ajax-migration.php \
  includes/ajax/class-ajax-settings.php \
  includes/admin/class-migration-page.php \
  includes/class-private-intake.php \
  tests/test-consolidated-allowances-no-clobber.php; do
  php -l "$f" || echo "LINT FAILED: $f"
done
```

All must report "No syntax errors detected".

---

## 4. Run the test suite

The plugin's tests are self-contained PHP scripts under `tests/`. They need
these PHP extensions present: `mysqli`, `mbstring`, `gd`, `dom`/`xml`.
Install them if `php -m` doesn't list them.

```bash
clean=0; total=0; fails=""
for t in tests/test-*.php; do
  total=$((total+1)); out=$(php "$t" 2>&1)
  if echo "$out" | grep -qE "\[FAIL\]|[1-9][0-9]* failed|PHP Fatal|Uncaught|Parse error|not found"; then
    fails="$fails $(basename $t)"
  else clean=$((clean+1)); fi
done
echo "RESULT: $clean / $total clean${fails:+ — FAILS:$fails}"
```

**Expected: `51 / 51 clean`.**

Run the new regression test explicitly and confirm it actually executes
(it should print a "Ran 7 checks" summary, not silently exit):

```bash
php tests/test-consolidated-allowances-no-clobber.php
# expect: Ran 7 checks: 7 passed, 0 failed
```

> Note: `tests/test-client-form.php` is a pre-existing stale test unrelated
> to this change. It silently no-ops (it requires the autoloader without
> defining `ABSPATH`, so it exits before running and returns 0). That is a
> known prior issue, not something this patch introduces or fixes. Leave it
> alone unless separately asked.

---

## 5. What this change does (for your review)

A single class — `MealsDB_Migration_Consolidated` — now owns every
WP/WC → `meals_*` data-movement step, exposed as seven chunked phases that
run in dependency order and return a uniform
`{ stats, offset, total, complete }` contract:

1. create clients   2. create rates   3. allowances   4. addresses
5. next-dates   6. promote private clients   7. allocations

Driven by a new AJAX action `mealsdb_consolidated_phase` and a new card on
the Migration admin page (`assets/js/admin-migration.js` +
`includes/admin/class-migration-page.php`). Dry-run is the default; a real
run requires unchecking the box and confirming.

**Bug fixes folded in (intentional behavior changes):**

- **Allowances clobber (was data-loss).** The phase now builds a dynamic
  `SET` list and writes only the columns with a real legacy value, so a
  partial-null usermeta row no longer zeroes `allowance_sides` or blanks
  `requisition_period`. Covered by the new regression test.
- **Allocations rollback.** Catches `\Throwable` (not just `\Exception`) so
  a `TypeError` mid-loop still triggers the per-month rollback.
- **`create_rates` pagination.** Uses a fixed-offset cursor against its
  self-clearing predicate instead of walking `OFFSET` over a shrinking set.
  **Side effect to eyeball:** clients with no usable `basic_cost` now get a
  `$0.00` "Standard" rate instead of being skipped (so the cursor
  terminates and every client ends with a default rate).

**Scope decisions baked in:**

- `class-migration.php` keeps `create_clients`/`create_rates` as Phase 4/5
  entry points but **delegates** their bodies to the consolidated engine —
  the Enzebra import flow is otherwise untouched.
- `MealsDB_Private_Intake` is **kept** (it's the live
  `woocommerce_order_status_changed` hook); the private-client phase calls
  into its `maybe_promote()` / `build_field_payload()` rather than
  duplicating them.
- `class-backfill-private-clients.php` was **fully absorbed** (preview,
  promote, enrich, deactivation sweep, helpers all moved into the new class)
  and deleted; its three tests and the settings-page AJAX handlers were
  repointed to the consolidated class.

---

## 6. Before this touches production

This patch is linted and unit-tested against fakes, but it has **not** been
run inside a live WordPress runtime. After the suite is green on this
branch:

1. Deploy the branch to **staging**.
2. Open the Migration admin page and run the **Consolidated Migration** card
   with **Dry run** checked.
3. Verify the per-phase stats look sane — pay attention to the
   create-rates count given the `$0.00` behavior change noted above.
4. Only then consider a real run, and only after a human reviews the diff.

Report back the `git status`, the lint results, and the `RESULT: X / Y`
line from the test suite.
