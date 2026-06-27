# Directive GUI-SAVE-INDEX — All client saves blocked: "deterministic index columns are unavailable"

**Status:** ready to implement. ROOT CAUSE confirmed from staging debug.log + the live table
structure. This is the ACTUAL cause of the "Database error occurred." on create AND edit — it sits
*underneath* the (correctly-fixed) WP-user and phone/province issues.
**Severity:** CRITICAL (functional) — **every** client save is blocked (create and edit, all client
types) whenever the deterministic-index guard can't establish its UNIQUE indexes. Surfaced by
Phase-1R2 (F-R2.1 create FAIL, F-R2.5 edit FAIL) and pinned via debug.log.
**Verified at:** v1.0.426, `includes/class-client-form.php`.

---

## WHAT THE EVIDENCE SHOWS (not hypothesis — confirmed)

debug.log at the failing timestamps (19:22 create, 19:28 create, 19:30 edit) all read:
`[MealsDB] Save aborted: deterministic index columns are unavailable.` /
`[MealsDB] Update aborted: deterministic index columns are unavailable.`
(The `client_phone_1`/`province` errors in the same log are from **03:58 — a pre-#405 run**, stale.)

Live `2xnIt_meals_clients` structure (Adminer): the four index COLUMNS all exist
(`individual_id_index`, `requisition_id_index`, `vet_health_card_index`, `delivery_initials_index`,
all `char(64) NULL`). But the INDEXES list shows UNIQUE only on `vet_health_card_index` and
`delivery_initials_index` — **`individual_id_index` and `requisition_id_index` have NO unique
index.**

`ensure_index_columns_exist()` (called on every save/update at lines ~835 / ~1052) requires a
`unique_<col>` UNIQUE index on ALL FOUR columns. For the two that lack it, it runs
`CREATE UNIQUE INDEX unique_individual_id_index ON ... (individual_id_index)` — and that **fails**,
because the column contains **duplicate non-NULL values** (MySQL errno 1062: cannot build a UNIQUE
index over duplicate data). The backfill correctly leaves empty-source rows NULL (NULLs don't
collide), so the collision is from GENUINE duplicate `individual_id` / `requisition_id` values among
migrated clients — e.g. the MAJ-1 dual-program person (one human as two client rows sharing an
identifier) or a migration duplicate. `CREATE UNIQUE INDEX` fails → `$allEnsured = false` → the
guard returns false → **the save aborts before the INSERT/UPDATE runs**, for every client.

This is why: it hits create AND edit (same guard on both paths); it's table-wide (not specific to
WP user / phone / type); and it persisted across many sessions (04:47, 17:03, 18:00, 19:22…) — a
stable data/index condition, not an intermittent fault.

---

## PRE-FLIGHT VERIFICATION (confirm the duplicate before changing anything)

```sql
-- The collisions that block the UNIQUE index. Expect >0 rows for at least one.
SELECT individual_id_index, COUNT(*) c FROM 2xnIt_meals_clients
  WHERE individual_id_index IS NOT NULL AND individual_id_index <> ''
  GROUP BY individual_id_index HAVING c > 1;
SELECT requisition_id_index, COUNT(*) c FROM 2xnIt_meals_clients
  WHERE requisition_id_index IS NOT NULL AND requisition_id_index <> ''
  GROUP BY requisition_id_index HAVING c > 1;
```
And inspect the colliding clients (are they the same person across programs, or true duplicates?):
```sql
SELECT client_id, client_type, individual_id_index FROM 2xnIt_meals_clients
  WHERE individual_id_index IN (
    SELECT individual_id_index FROM 2xnIt_meals_clients
    WHERE individual_id_index IS NOT NULL AND individual_id_index <> ''
    GROUP BY individual_id_index HAVING COUNT(*) > 1);
```
**This is also an OPERATOR question:** are duplicate `individual_id`/`requisition_id` values
LEGITIMATE (a person enrolled in two programs → MAJ-1) or always erroneous? The answer decides the
fix below.

---

## THE FIX — two parts, and a design decision

### Part A (required, regardless): the save path must NOT fail-closed on an index-management problem
A missing/unbuildable UNIQUE *constraint* should never block all data entry. The deterministic index
exists for DEDUP DETECTION (warn on a possible duplicate, MAJ-1 spirit), not as a hard gate on every
write. Change `ensure_index_columns_exist()` so that **inability to establish the UNIQUE index does
not abort the save**:

- The index COLUMNS must exist (they're written on insert) — keep that check; if a *column* is
  genuinely missing and can't be added, that's a real abort.
- But if the unique INDEX can't be created (duplicate data, errno 1062) or dropped/rebuilt, **log a
  warning (degraded STR-LOG event) and continue the save** — write the row, write the index VALUE
  into its column (the hash sidecar still populates for dedup queries), just without the DB-level
  UNIQUE constraint. The dedup *check* (find-by-index) works on the column with or without a unique
  index; only the hard DB constraint is absent.
- Net: `ensure_index_columns_exist()` returns true (save proceeds) as long as the columns exist;
  index-constraint problems become warnings, not save-killers.

This alone unblocks create and edit on staging immediately and is the correct robustness posture.

### Part B (the constraint strategy) — RESOLVED by operator: ALLOW AND WARN, no hard unique constraint

**Operator decision:** one person in two programs is technically valid (though more often a
mistake); the system must **allow and warn, never fail.** This mirrors the existing MAJ-1 posture
(the WP-user "already linked to client #N" case warns but does not block).

Therefore:
- **`individual_id_index` and `requisition_id_index` must NOT carry a hard DB UNIQUE constraint.**
  Remove them from the UNIQUE-index requirement in `ensure_index_columns_exist()` /
  `$deterministic_index_map`'s unique-enforcement. Keep the **hash column** itself (it's still used
  for fast dedup *lookup*).
- Enforce "don't accidentally duplicate" as an **application-level WARNING at data entry**, not a
  database constraint: on create/edit, if the entered `individual_id` / `requisition_id` matches an
  existing client's hash, surface a warning naming the other client (e.g. "⚠ another client already
  has this Individual ID — client #N. Continue only if this is a deliberate dual-program
  enrollment.") and **let the operator proceed.** Same warn-not-block idiom as the WP-user
  already-linked check, so it's consistent UX the operator already understands.
- This is the correct tradeoff per the operator: the legitimate dual-program case is protected
  (rarer but real); the mistake case is caught by the warning, with the operator as the final
  check. (A future data-quality report could additionally surface duplicates — additive, not needed
  now.)
- `vet_health_card_index` and `delivery_initials_index` already HAVE working unique indexes on
  staging and aren't implicated — leave their handling as-is UNLESS the same allow-and-warn logic is
  desired there too (confirm separately; not required by this directive).

**Net for Part A + B together:** with the two ID columns no longer requiring a unique constraint,
`ensure_index_columns_exist()` has nothing to fail to build for them; Part A's robustness change
ensures that even a future constraint problem degrades to a warning rather than blocking saves.
Both together unblock create and edit on staging.

### Part C (carry-over): field attribution + event logging the re-test flagged
- The save still surfaced the generic "Database error occurred." with no field attribution for THIS
  failure, because it's not a `$wpdb` column error `parse_failed_column` understands — it's an
  internal guard. Make the guard's failure (when it legitimately aborts, post-Part-A) set
  `last_save_error` to a clear message ("Could not save: a database index could not be prepared —
  contact support"), not the generic string.
- The re-test (EV-R1) found NO Event Log entry for these failures. Emit a `degraded`/error STR-LOG
  event when the index guard can't establish a constraint (Part A's warning path) AND when any save
  genuinely aborts, so these are visible in the Event Log, not just debug.log.

---

## TESTS

- **T-1 (Part A) save proceeds despite unbuildable unique index:** simulate a column whose unique
  index can't be created (duplicate values) → `save()` and `update()` SUCCEED (row written, index
  column populated), a degraded event is logged, NO abort. (This is the staging repro — must pass.)
- **T-2 dedup still works without the DB constraint:** find-by-index lookup still detects a matching
  hash even when no UNIQUE index exists on the column.
- **T-3 (Part B — duplicates allowed + warned) two clients may share an individual_id_index:** both
  persist; a WARNING naming the other client is surfaced on the second, and the operator can
  proceed — neither a block nor a silent accept.
- **T-4 columns-missing still aborts:** if an index COLUMN truly can't exist, save still aborts with
  a clear (non-generic) message — that's a real failure, distinct from the constraint case.
- **T-5 event logged:** the index-degraded path and any genuine abort emit an Event Log entry.
- Full suite green. **Note:** the unit tests use a stub `wpdb` that does NOT enforce MySQL
  constraints — that's WHY 79/79 passed while staging failed. Add at least one test that exercises
  the guard's false/true decision logic directly (mock the index-check returning "can't create").

---

## ACCEPTANCE CRITERIA

1. A client create AND edit SUCCEED on staging (F-R2.1 / F-R2.5 pass) — the index guard no longer
   blocks saves when it can't build a unique constraint.
2. The deterministic hash columns still populate (dedup detection intact).
3. `individual_id_index` / `requisition_id_index` carry NO hard DB unique constraint; duplicate
   identifiers are ALLOWED with a data-entry WARNING naming the other client (dual-program case
   protected; mistake case caught by the warning) — the resolved operator decision.
4. A genuine save failure shows a clear, attributed message — never the bare "Database error
   occurred." — and emits an Event Log entry.
5. New guard-logic test added (not reliant on the constraint-blind stub wpdb); full suite green.
6. Phase-1R2 re-run: F-R2.1 and F-R2.5 PASS.

---

## NOTES — why the prior fixes were not wrong

- **PR #405 (WP-user anchor) was correct and is verified working** (Validate/Pull Data, server-side
  existence check, required gate all pass in the re-test). It fixed a real cause. This index-guard
  block is a SEPARATE, deeper cause that #405's scope never touched — the save was failing *after*
  the WP-user gate, at the index step.
- The original phone/province fix was also correct (those errors are gone from current-run logs;
  the 03:58 entries are stale).
- The lesson: the PHPUnit suite passes because its stub `wpdb` doesn't enforce real schema/index
  constraints, so a guard that fails only against real MySQL is invisible to it. Real-DB behavior
  (staging) is the only place this surfaces — which is exactly what the GUI re-test is for. Worth a
  standing note: index/constraint logic needs a test that doesn't rely on the constraint-blind stub.
