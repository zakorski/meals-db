# Task: Apply the consolidated migration tool (rate-limit fix included)

You are working in the **meals-db** WordPress plugin checkout. Apply the
accompanying patch, which consolidates eight WP/WooCommerce -> `meals_*`
data-movement tools into a single engine, fixes three data bugs, deletes the
five absorbed classes, **and fixes a rate-limit collision** that aborted the
chunked run with a 429 partway through.

The patch file is `consolidation.patch`, alongside this document.

> **If you already applied an earlier version of this patch:** this is a
> complete, standalone replacement built from the same pristine v1.0.353
> baseline -- it is NOT a delta to stack on top. Reset the previous branch
> (`git checkout main && git branch -D consolidate-migration`) or apply this
> to a fresh branch off the pristine source. Do not apply both.

---

## What changed since the previous version

Only one thing: the rate-limit gate on the chunked AJAX entry point
`run_consolidated_phase` in `includes/ajax/class-ajax-migration.php`.

**The bug:** the consolidated pipeline is chunked at 100 rows per AJAX call,
so a single phase makes many back-to-back calls (5,000 clients ~= 50+ calls).
The `migration_destructive` rate bucket is **5 per hour**, and the old code
checked it on *every* chunk -- so the run died with `Network error (429)` on
the 6th call, after creating 500 rows. (Nothing was actually wrong with the
migration; it was a dry run, and `created: 500` was just the running count
when the limiter cut it off.)

**The fix (options 1 + 3 from review):**
- **Dry runs are never rate-limited** (they write nothing).
- **Real runs are rate-limited only on the first chunk of a phase**
  (`offset === 0`), not on every chunk.

This keeps the guardrail -- you still cannot start more than 5 fresh
*writing* phases per hour -- without throttling the pagination of a single
run.

The three legacy single-shot backfill buttons
(`backfill_allowances` / `backfill_addresses` / `backfill_allocation_engine`)
are intentionally left as-is: they loop internally and consume one token per
click, so 5/hour is fine for them.

---

## 0. Precondition -- confirm the baseline

The patch was generated against plugin **version 1.0.353**
(`Version:` header in `meals-db-main.php`), with exact-context hunks.

```bash
grep -m1 "Version:" meals-db-main.php     # expect: * Version: 1.0.353
git status --porcelain                     # expect: clean working tree
```

If the version differs or `git apply --check` (step 1) fails, **stop -- do
not force it.** The code has drifted; read `consolidation.patch` and
reproduce the changes by hand instead. The patch is the source of truth for
intent, not for exact line numbers.

---

## 1. Branch + dry-run the patch

```bash
git checkout -b consolidate-migration
git apply --check consolidation.patch     # must report nothing
```

## 2. Apply

```bash
git apply consolidation.patch
git add -A
git status --short
```

Expected (17 files): 2 added (`class-migration-consolidated.php`,
`test-consolidated-allowances-no-clobber.php`), 5 deleted (the
`class-backfill-*.php` set), 10 modified. Confirm the 5 deletions actually
removed the files.

## 3. Lint touched PHP

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

## 4. Run the suite

Tests are self-contained PHP scripts under `tests/`. They need the `mysqli`,
`mbstring`, `gd`, and `dom`/`xml` extensions present (install if `php -m`
doesn't list them).

```bash
clean=0; total=0; fails=""
for t in tests/test-*.php; do
  total=$((total+1)); out=$(php "$t" 2>&1)
  if echo "$out" | grep -qE "\[FAIL\]|[1-9][0-9]* failed|PHP Fatal|Uncaught|Parse error|not found"; then
    fails="$fails $(basename $t)"
  else clean=$((clean+1)); fi
done
echo "RESULT: $clean / $total clean${fails:+ -- FAILS:$fails}"
```

**Expected: `51 / 51 clean`.**

> `tests/test-client-form.php` is a pre-existing stale test that silently
> no-ops; it is unrelated to this change. Leave it alone.

---

## 5. Verifying the rate-limit fix specifically

After applying, confirm the gate condition is the fixed one:

```bash
grep -n "Rate-limit the START\|! \$dry_run && \$offset === 0" \
  includes/ajax/class-ajax-migration.php
```

You should see the comment block and the condition
`if ( ! $dry_run && $offset === 0 ...`.

---

## 6. IMPORTANT -- clearing the already-tripped limiter on the test site

On the environment where the 429 happened, that user's
`migration_destructive` bucket is already at 5/5 for the current hour. With
this fix:

- A **dry run** will work **immediately** (dry runs are no longer gated) --
  so re-running the dry run to confirm the fix needs no waiting.
- A **real run** started within the same hour would still be gated at the
  first chunk until the hourly window rolls off.

If you need to start a real run before the hour elapses, clear the limiter
state. It is stored as transients; the cleanest reset is via WP-CLI on that
site:

```bash
wp transient delete --all     # clears rate-limiter transients (and others)
```

If `wp` is not available, the limiter also resets on its own once 60 minutes
pass from the first of the 5 calls. Do NOT lower the `migration_destructive`
limit in `includes/class-rate-limiter.php` to work around this -- the fix
already handles the chunked case; the 5/hour cap is still correct for
*starting* destructive operations.

---

## 7. Before production

Linted and unit-tested against fakes; **not** yet run in a live WordPress
runtime. After the suite is green:

1. Deploy the branch to **staging**.
2. Migration admin page -> **Consolidated Migration** card -> keep **Dry
   run** checked -> **Run Consolidated Migration**. With the fix, this should
   now walk all of "Create Meals Clients" without a 429.
3. Sanity-check the per-phase stats. Two things to eyeball:
   - **Create Meals Clients total:** the failed run reported ~500+ created
     and was still going -- the full candidate count looked like several
     thousand. The operational baseline is ~890 active clients, so a much
     larger number may mean the `customer_group` query is sweeping in
     inactive/historical users. Confirm that is expected before a real run.
   - **Create Client Rates:** clients with no usable `basic_cost` now get a
     `$0.00` "Standard" rate instead of being skipped (intended; lets the
     pagination terminate).
4. Only then consider a real run, after a human reviews the diff.

Report back the `git status`, lint results, and the `RESULT: X / Y` line.
