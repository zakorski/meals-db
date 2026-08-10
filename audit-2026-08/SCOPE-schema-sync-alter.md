# Scope — ALTER (column-MODIFY) support for Schema_Sync (audit H7)

**Status:** scoping only, not implemented. **Author:** audit remediation, 2026-08-10.

## 1. Problem / current state

`MealsDB_Schema_Sync::run_full_sync()` is **additive-only**: it CREATEs missing
tables and ADDs missing columns, and it **detects** column drift
(`column_matches_definition` compares type, width, ENUM list, nullability,
default, auto_increment) but **never issues `ALTER … MODIFY`**. Post-LB-6 the
mismatch report is surfaced (`surface_sync_report`) but not acted on.

This is a **deliberate** decision (see the docblock at `class-schema-sync.php`
~L191): *"silently rewriting a column type on a live billing DB is far riskier
than the drift it would fix."* Consequence (H7): bumping `MEALS_DB_VERSION`
never migrates a changed column type/width/ENUM on an existing install — every
such change needs a hand-written `ALTER` migration in `install-schema.php`
(e.g. `widen_vet_health_card_column`).

**What already exists and is reusable:**
- Per-column `expected` (the canonical definition, constraints stripped by
  `sanitize_column_definition`) and `actual` (the raw `INFORMATION_SCHEMA`
  row) are already computed and collected in `$results['column_mismatches']`.
- Generating the DDL is therefore trivial:
  `ALTER TABLE \`t\` MODIFY COLUMN \`col\` <clean_definition>`.
- Capability gate (`manage_options`), `settings_modify` rate limit, and the
  `surface_sync_report` audit surface are all in place.

**The feature is 20% "emit the ALTER" and 80% "do it safely."**

## 2. Goal

Let a schema change to an *existing* column type/width/ENUM/nullable/default be
applied to deployed installs **without** a bespoke hand-written migration —
while never risking silent data loss or an unbounded table lock on the live
billing DB.

Non-goals (this feature): dropping columns/tables, renaming columns, changing
the PRIMARY KEY, adding/removing composite indexes (tracked separately — see
the B02 MEDIUM about upgrade-time indexes). Column MODIFY only.

## 3. The core tension — why this isn't just "wire it in"

`ALTER … MODIFY` on a populated table can:
- **Lose data** — narrowing (`VARCHAR(255)→(40)`, `DECIMAL(12,2)→(10,2)`)
  truncates; removing an ENUM value blanks rows holding it.
- **Fail hard** — adding `NOT NULL` when NULLs exist; a type change with
  unconvertible values (`VARCHAR→INT` on non-numeric data).
- **Lock the table** — some MODIFYs require `ALGORITHM=COPY` (full table
  rebuild, table locked for the duration). ENUM-value changes and many type
  changes are COPY; width widening is often INPLACE.
- **Leave partial state** — MySQL DDL is not transactional; a failed multi-part
  ALTER can leave the table half-migrated (and must NOT mark the version
  current — cf. recon-01).

So a blanket "MODIFY every mismatch" would be *more* dangerous than today's
drift. The design must **classify** each change and treat safe and risky
changes differently.

## 4. Proposed approach

Add a **classifier + planner + guarded executor**, keeping ADD/CREATE exactly
as-is.

### 4.1 Classify each `column_mismatch` into a risk tier
Derive from (expected vs actual) type/width/ENUM/nullable/default:

| Change | Tier | Notes |
|---|---|---|
| Widen `VARCHAR`/`CHAR`/`DECIMAL`/`TEXT` size up | **SAFE** | no data loss; usually INPLACE |
| `INT`→`BIGINT` (and signed widening) | **SAFE** | value-preserving |
| Add ENUM value(s) (superset) | **SAFE** | existing rows valid |
| `NOT NULL`→`NULL` (relax) | **SAFE** | |
| Add/keep a compatible `DEFAULT` | **SAFE** | metadata-only |
| Widen numeric precision/scale up | **SAFE** | |
| Narrow any size / precision down | **RISKY** | truncation → needs row check |
| Remove/rename ENUM value | **RISKY** | rows with old value blanked |
| `NULL`→`NOT NULL` | **RISKY-CONDITIONAL** | safe iff `COUNT(* WHERE col IS NULL)=0` |
| Type family change (`VARCHAR`↔`INT`, `DATE`↔`DATETIME`, …) | **RISKY** | conversion may fail/lose data |
| `DECIMAL`↔`FLOAT` on money | **RISKY** | never auto (billing) |

Classification is a **pure function** over the already-normalized parts, so it
is fully unit-testable with no DB.

### 4.2 Plan the ALTER
For each SAFE (and each operator-approved RISKY) mismatch, build
`MODIFY COLUMN` DDL from the canonical definition. Prefer online DDL:
append `, ALGORITHM=INPLACE, LOCK=NONE` and fall back to a plain MODIFY if the
server rejects it (older MySQL / a change that can't be INPLACE). MariaDB note:
this whole engine already assumes MySQL 8 (see synthesis T4); confirm the
online-DDL syntax degrades gracefully.

### 4.3 Pre-flight safety checks (RISKY only)
Before executing a RISKY-CONDITIONAL or operator-approved RISKY change, run a
read-only probe and BLOCK if it would lose data:
- `NULL`→`NOT NULL`: `SELECT COUNT(*) … WHERE col IS NULL` must be 0.
- Narrowing: `SELECT COUNT(*) … WHERE LENGTH(col) > <new_len>` (or value out of
  new numeric range) must be 0.
- ENUM value removal: `SELECT COUNT(*) … WHERE col IN (<removed values>)` must
  be 0.
A failed probe → surface as a blocked mismatch with the offending row count;
never auto-apply.

### 4.4 Execute, guarded
- Per-column `try/catch`; on failure record in `$results['errors']` and
  **do not** let the caller mark the schema version current (install() already
  keys off errors post-recon-01 — verify MODIFY failures feed that path).
- Audit-log each applied ALTER (`MealsDB_Logger::log`, schema-change action) and
  emit an Event Log entry — this is a committed change to the data model.
- Recommend/pointer to a DB backup before RISKY applies (operator-facing note).

## 5. Gating / UX (the key operator decision — see §9)

Two viable models; my recommendation is **B**:

- **A — auto-apply SAFE only on version bump.** The `admin_init` upgrade path
  applies SAFE widening automatically; RISKY changes are surfaced (as today) and
  require a bespoke migration or the explicit tool. Lowest friction; the live DB
  only ever sees value-preserving DDL automatically.
- **B — explicit "Apply schema changes" tool (recommended).** A Data-Ops /
  settings screen that (1) shows a **preview**: the exact ALTER SQL, risk tier,
  online-vs-copy, and pre-flight row counts per column; (2) applies SAFE changes
  on click; (3) requires a typed confirmation (`ALTER`) + passing pre-flight for
  RISKY changes. The nightly/admin_init path stays additive-only + surfaces
  drift (unchanged), so nothing type-changes without a human looking at the
  preview. Safest for a billing DB; matches the plugin's force-rebuild pattern
  (typed confirm + rate limit + audit).

A hybrid is possible: auto-apply SAFE on bump (A) **and** offer the explicit
tool for RISKY (B).

## 6. Idempotency & failure semantics
- After a successful MODIFY the mismatch resolves; the next run is a no-op
  (the existing comparator already folds BOOLEAN/int-width so it won't loop).
- A blocked RISKY change persists as a surfaced mismatch every run until the
  operator resolves it — intentional nag (mirrors the finalized-month pattern).
- Any DDL error must propagate to the "schema not current" decision so a partial
  migration is retried / visible, never recorded as done.

## 7. Testing
- **Unit (no DB):** the classifier — feed (expected def, actual row) pairs,
  assert risk tier + generated MODIFY SQL for: widen varchar, add-enum-value,
  int→bigint, relax-null (SAFE); narrow, remove-enum, null→notnull, type-change
  (RISKY/conditional). This is the bulk of the value and is pure.
- **Integration (test DB, MySQL 8 in CI):** apply a SAFE widen and assert the
  column changed + data intact; assert a narrowing with an over-long row is
  BLOCKED (pre-flight) and the column is untouched; assert null→notnull with a
  NULL row is blocked; assert a failed ALTER leaves version-not-current.
- Reuse the harness style of `test-migration-phase-authz` / the fake-wpdb tests
  for the planner; the integration tests need a real MySQL (skip-guard like the
  H13 fee test where WC/DB is absent).

## 8. Rollout / docs
- Ship behind the tool (model B) first; observe on staging before enabling any
  auto-apply.
- Update CLAUDE.md Pattern 9: replace "Schema_Sync NEVER MODIFYs" with the new
  capability + the SAFE/RISKY split; keep the "write a bespoke ALTER for a
  RISKY change we won't auto-apply" guidance for the blocked tier.
- Deprecate/retire ad-hoc `install-schema.php` widen_* migrations once the tool
  covers them (leave existing ones; they're idempotent).

## 9. Operator decisions — DECIDED 2026-08-10

1. **Gating model = HYBRID (A for SAFE + B for RISKY).** SAFE changes auto-apply
   on the `admin_init` version-bump path. RISKY changes go through the explicit
   preview tool (see 2).
2. **RISKY changes = allow apply with preview + typed confirmation.** The tool
   shows the ALTER SQL + risk + pre-flight row counts; the operator types the
   confirmation (`ALTER`) and the change applies only if the pre-flight passes.
   (Not block-only.)
3. **Locking = table lock acceptable in MAINTENANCE MODE.** COPY-algorithm
   ALTERs are allowed; the executor must engage WP maintenance mode
   (`wp_maintenance` / a maintenance flag) around a locking apply and clear it
   after. Prefer INPLACE/LOCK=NONE when the change supports it; fall back to a
   maintenance-mode COPY otherwise.
4. **Money DECIMAL columns = ALWAYS MANUAL.** Any `DECIMAL`/`NUMERIC` change is
   tiered RISKY and never auto-applies, even a widening — it only goes through
   the preview+confirm tool.
5. **DB engine = MySQL 8.0.46 (Community, utf8mb4).** Confirmed. Online DDL
   (`ALGORITHM=INPLACE, LOCK=NONE`) is available; no MariaDB path needed here.
   (The codebase-wide `INSERT ... AS new` T4 concern is also resolved by this —
   production is MySQL 8, not MariaDB.)

### Build order (locked)
1. **Pure classifier** (this slice) — `MealsDB_Schema_Alter_Planner::classify()`:
   given (expected canonical def, actual INFORMATION_SCHEMA row) → risk tier +
   reason. No DB, fully unit-tested. Turns the surfaced drift report into a
   risk-tagged, actionable plan; ships independently.
2. Pre-flight probes + guarded executor (INPLACE-or-maintenance-mode COPY, audit,
   error→version-not-current).
3. Auto-apply SAFE on the version-bump path (A).
4. Preview/apply tool for RISKY (B) — preview SQL + row counts + typed confirm.
5. Docs (CLAUDE.md Pattern 9) + integration tests on MySQL 8 in CI.

## 10. Rough effort
- Classifier + planner (pure) + unit tests: **S–M** (~½–1 day).
- Guarded executor + pre-flight probes + audit/error wiring: **M**.
- Preview/apply UI (model B) + typed-confirm + rate limit: **M**.
- Integration tests against MySQL in CI: **M** (harness setup is the cost).
- Docs + CLAUDE.md: **S**.
Total ~**M–L**; the pure classifier is the high-value, low-risk first slice and
could ship independently (it turns the surfaced drift report into an actionable,
risk-tagged plan even before any auto-apply).
