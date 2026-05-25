# Task: Remove CSV / database-dump import tools

Apply `import-removal.patch` to the **meals-db** plugin checkout.

## Baseline

Built against your **current repo (version 1.0.360)** — the one with the
consolidation, quickorder-fees, and shadow-mode work already applied. It is
NOT against the old 1.0.353 baseline. `git apply --check` will confirm the fit.

```bash
grep -m1 "Version:" meals-db-main.php     # expect 1.0.360
git apply --check import-removal.patch     # must be clean
```

## What this removes and why

Under the old paradigm the plugin could ingest an uploaded SQL dump (or a
direct legacy-DB connection) and migrate its users/products/orders in. That
paradigm is gone — the plugin is installed directly on the live site, so
there is no foreign dump to import. This removes every tool that ingests
user-uploaded CSV or database-dump data.

**Removed:**
- `class-migration.php`: the dump-import methods (`detect_prefix`,
  `copy_table_from_db`, `test_source_db`, `get_source_tables`, `load_source`,
  `load_from_db`, `upload_file`, `migrate_users/products/orders`, `cleanup`)
  and their import-only constants (`LOAD_CHUNK_BYTES`, `LOAD_FGETS_BYTES`,
  `MAX_STATEMENT_BYTES`, `$needed_suffixes`, `$type_map`). The file is now a
  thin set of phase entry points + log/progress helpers.
- `class-ajax-migration.php`: the import AJAX handlers and registrations
  (`detect`, `test_db`, `upload`, `load`, `load_from_db`, `cleanup`), the
  credential-stashing helpers, the path/upload-dir helpers, and import phases
  1-3 from `run_phase`. `run_phase` now serves only phases 4-5
  (create_clients / create_rates), which do NOT take a source prefix.
- `class-migration-page.php`: the entire "Step 1: Data Source" UI (DB
  connection, SQL upload, server file path, prefix detection) and the import
  phases (0-3) from the progress display, plus the "Cleanup Source Tables"
  button.
- `assets/js/admin-migration.js`: the import-side client code (tab switching,
  test-db, upload, detect, the phase 0-5 start-migration loop, cleanup
  handler) and the orphaned `ajaxUpload` helper / import state fields.
- `tests/test-migration-constants.php`: deleted — it only validated the
  dump-streaming size constants, which no longer exist.

**Kept (deliberately — these do NOT ingest uploads):**
- `create_clients` / `create_rates` (phases 4-5) — they read the LIVE WP/WC
  tables and delegate to `MealsDB_Migration_Consolidated`. Still reachable via
  `run_phase`, and the migration page still shows their progress.
- The entire Consolidated Migration tool and its card (the real live-install
  migration path).
- All CSV *export* (reports, invoices) and the invoice CSV round-trip parsing
  — these are output / self-generated, not imports.
- The reset / log-viewer controls on the migration page.

## Steps

```bash
git checkout -b remove-csv-dump-import
git apply import-removal.patch
git add -A && git status --short
```

Expected (5 entries): 4 modified (`assets/js/admin-migration.js`,
`includes/admin/class-migration-page.php`,
`includes/ajax/class-ajax-migration.php`,
`includes/services/class-migration.php`), 1 deleted
(`tests/test-migration-constants.php`).

```bash
# Lint
for f in includes/services/class-migration.php \
         includes/ajax/class-ajax-migration.php \
         includes/admin/class-migration-page.php; do
  php -l "$f" || echo "LINT FAILED: $f"
done

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

## Staging check

Open Meals DB → Migration. Confirm:
- The data-source / upload / DB-connection UI is gone.
- The Consolidated Migration card is intact and runnable (dry run).
- No JS console errors on the page.

Report back: `git status`, lint, `RESULT: X / Y`.
