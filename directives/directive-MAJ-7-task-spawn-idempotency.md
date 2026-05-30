# Directive MAJ-7 — Task spawn idempotency (dedup under re-run / overlap)

**Status:** ready to implement (code-only, post-launch hygiene)
**Severity:** MAJOR (narrowed after code read — see below). NOT "no dedup at all"; the recurrence
advance already prevents normal repeat. The real gap is **idempotency under re-run / overlapping
cron**: a second concurrent or re-triggered nightly pass re-spawns the same tasks.
**Verified at:** v1.0.412 — `class-task-rules.php` (`run_cron_pass` ~287, `spawn_from_rule` ~363),
`class-task-engine.php` (`create_task` ~55), `class-task-cron.php` (`nightly_sync` ~31).

---

## WHAT THE CODE ACTUALLY DOES (verified — corrects the original finding)

The original MAJ-7 finding said "task spawn lacks dedup." Reading the tree, that's too strong:

- `run_cron_pass()` selects rules due (`next_run_at <= now`), calls `spawn_from_rule()`, then
  **advances `next_run_at` to the next recurrence and stamps `last_run_at`** (~307–312). So a rule
  does NOT re-fire on the next nightly run — the normal case is already guarded.

So the bug is narrower and specific:

1. **Spawn and advance are not atomic.** Sequence is: SELECT due → spawn (insert tasks) → UPDATE
   `next_run_at`. If two passes run concurrently (cron overlap, a manual re-trigger while the
   scheduled one runs, or a crash between spawn and the UPDATE), the second pass sees the SAME due
   rules (next_run_at not yet advanced) and spawns **duplicate tasks**. `create_task` is a bare
   `INSERT` (~114) with no idempotency key, so nothing catches the duplicate.
2. **No overlap lock on `nightly_sync`.** Verified: no transient/running guard exists, so two
   passes CAN run at once.
3. **Query-spawn rules** (`SPAWN_QUERY`) spawn one task per matching entity (`related_entity_id`
   set per task, ~408+). Re-running before the underlying query result changes duplicates the
   per-entity tasks — same root cause (no idempotency key), wider blast radius (N entities).

The tasks table has indexes on `source_rule_id` and `(related_entity_type, related_entity_id)` but
**no UNIQUE dedup index**. That's the missing piece.

---

## PRE-FLIGHT VERIFICATION

```bash
cd <plugin-root>
# 1. The advance-after-spawn (confirm it's still SELECT due → spawn → UPDATE next_run_at).
grep -n "next_run_at\|last_run_at\|spawn_from_rule\|run_cron_pass" includes/services/class-task-rules.php | head
# 2. create_task is a bare insert (the dedup target).
grep -n "wpdb->insert\|source_rule_id\|related_entity_id\|next_run_date" includes/services/class-task-engine.php | head
# 3. tasks table columns + confirm NO unique dedup index yet.
sed -n "$(grep -n 'TASKS =>' includes/class-schema.php | head -1 | cut -d: -f1),+50p" includes/class-schema.php | grep -n "UNIQUE\|source_rule_id\|related_entity\|next_run_date\|task_type"
# 4. nightly_sync overlap guard (expect NONE).
grep -n "lock\|transient\|is_running\|already running" includes/class-task-cron.php
# STOP if an overlap lock or a dedup key already exists — re-scope to whatever's missing.
```

---

## THE FIX — two layers (idempotency key is the real fix; the lock is defense-in-depth)

### Layer 1 (primary) — an idempotency key on spawned tasks

Give each spawned task a deterministic **spawn identity** so a duplicate spawn is rejected by the
database, not by hoping the timing lines up. The natural identity:

```
(source_rule_id, related_entity_id, next_run_date, task_type)
```

- For `SPAWN_FIXED` rules, `related_entity_id` is NULL — the key is effectively
  `(rule, next_run_date, type)`: one task per rule per scheduled date. Re-running the same due
  date can't double it.
- For `SPAWN_QUERY` rules, `related_entity_id` distinguishes per-entity tasks — one task per
  (rule, entity, date).

**Implementation — a dedicated dedup column, not a multi-NULL unique index.** MySQL UNIQUE
indexes treat multiple NULLs as distinct, so a UNIQUE on `(source_rule_id, related_entity_id,
next_run_date, task_type)` would NOT dedup `SPAWN_FIXED` tasks (NULL entity_id → every row
"distinct"). Avoid that trap: add a computed `spawn_key` column and make IT unique.

Schema additions to `TASKS` (additive — STR-11 safe):
- `spawn_key` `VARCHAR(191) NULL` — NULL for manually-created tasks (which must NOT be deduped;
  an operator creating two ad-hoc tasks is legitimate). Only rule-spawned tasks set it.
- UNIQUE index `uniq_spawn_key` on `(spawn_key)`.

`spawn_key` is built only when the task is rule-spawned:
```
spawn_key = sprintf('%d:%s:%s:%s',
    source_rule_id,
    related_entity_id !== null ? (string) related_entity_id : '-',  // literal '-' for fixed
    next_run_date,
    task_type
);
```
The literal `'-'` placeholder for the NULL entity is what makes `SPAWN_FIXED` dedup correctly
(stable non-NULL key) while keeping the NULL `related_entity_id` column semantics intact.

**`create_task` change:** accept an optional `spawn_key`. When present, use
`INSERT ... ON DUPLICATE KEY UPDATE task_id = task_id` (a no-op touch) OR detect the duplicate-key
error and treat it as "already spawned, skip." Return 0 (or the existing task_id) on a dedup hit
WITHOUT logging an error — a deduped re-spawn is success, not failure. Do NOT log a duplicate as a
`create_task insert failed` error (it isn't one). Manually-created tasks (no `spawn_key`) keep the
current bare-insert behavior unchanged.

**`spawn_from_rule` change:** compute and pass `spawn_key` for both the FIXED and QUERY branches.
This is the only place spawn identity is known.

### Layer 2 (defense-in-depth) — overlap lock on the nightly pass

Even with Layer 1, two concurrent passes doing redundant work is wasteful and can interleave oddly.
Wrap `nightly_sync` (or `run_cron_pass`) in a short-lived lock so only one runs at a time:

```php
// in nightly_sync, before the pass:
if (get_transient('mealsdb_task_spawn_running')) {
    // Another pass holds the lock — skip, log a degraded trunk event so an
    // unexpected overlap is visible (STR-LOG), and return.
    MealsDB_Event_Log::record([
        'severity' => 'warning', 'category' => 'job', 'subsystem' => 'task_cron',
        'event' => 'nightly_sync.overlap_skipped', 'outcome' => MealsDB_Event_Log::OUTCOME_DEGRADED,
        'message' => 'Task spawn pass skipped: another pass is already running.',
    ]);
    return;
}
set_transient('mealsdb_task_spawn_running', 1, 15 * MINUTE_IN_SECONDS);
try {
    // ... existing pass ...
} finally {
    delete_transient('mealsdb_task_spawn_running');
}
```

The transient TTL (15 min) is a safety valve so a crashed pass can't wedge the lock forever. The
lock is best-effort (transients aren't a hard mutex), which is exactly why Layer 1 is the PRIMARY
fix — the DB unique key is the real guarantee; the lock just reduces redundant work and makes
overlap visible.

### Atomicity note (do not over-engineer)
With Layer 1, the spawn→advance non-atomicity is no longer a *correctness* problem (a duplicate
spawn is rejected by the unique key). So do NOT wrap spawn+advance in a heavy transaction — the
idempotency key is the cheaper, more robust fix and survives crashes/overlaps that a transaction
boundary wouldn't (e.g. two separate processes).

---

## TESTS (`tests/test-task-spawn-dedup.php`)

- **T-1 fixed re-spawn deduped:** spawn a `SPAWN_FIXED` rule for a given `next_run_date`, then call
  `spawn_from_rule` AGAIN with the same date → second call creates NO new task (the unique
  `spawn_key` rejects it); task count stays 1.
- **T-2 next date NOT deduped:** same rule, a DIFFERENT `next_run_date` → a new task IS created
  (different spawn_key). (Proves recurrence still works — we dedup repeats, not legitimate next
  occurrences.)
- **T-3 query re-spawn per-entity deduped:** a `SPAWN_QUERY` rule matching entities A,B → run
  twice for the same date → exactly 2 tasks (A,B), not 4.
- **T-4 query new entity spawns:** second run where the query now also matches entity C → C gets a
  task; A,B are not re-created.
- **T-5 manual tasks NOT deduped:** two `create_task` calls with NO spawn_key (operator-created)
  both succeed (manual tasks are intentionally un-deduped).
- **T-6 dedup hit is not an error:** a deduped re-spawn does NOT emit a `create_task insert failed`
  error_log line and does not return a failure that the cron counts as an error.
- **T-7 overlap lock:** with the running transient set, `nightly_sync` skips, emits the
  `overlap_skipped` degraded trunk event, and creates no tasks; lock is released in `finally`.
- **T-8 spawn_key shape:** FIXED → `rule:-:date:type`; QUERY → `rule:entityid:date:type`
  (the `-` placeholder for fixed is what makes the unique index dedup NULL-entity rows).

Run new test + FULL suite (expect 70 + this). Regression-sensitive: the existing task tests
(`test-task-*`) — confirm normal single-spawn still works and manual task creation is unaffected.
Watch the `idx`/schema test if one asserts the tasks table index set (the new unique index +
column change the expected schema).

---

## ACCEPTANCE CRITERIA

1. `TASKS` has a nullable `spawn_key` + UNIQUE `uniq_spawn_key`; manual tasks leave it NULL and are
   never deduped.
2. `spawn_from_rule` computes `spawn_key` for FIXED (`rule:-:date:type`) and QUERY
   (`rule:entityid:date:type`) and passes it to `create_task`.
3. `create_task` treats a duplicate `spawn_key` as an idempotent no-op (skip, not an error);
   bare-insert behavior preserved when no `spawn_key`.
4. Re-running / overlapping the nightly pass does not create duplicate tasks (T-1/T-3); legitimate
   next-occurrence and new-entity spawns still work (T-2/T-4).
5. `nightly_sync` holds a best-effort transient lock; an overlap is skipped + logged degraded.
6. New test green; full suite green; task-table schema-test expectation updated for the new
   column/index.

---

## OUT OF SCOPE

- The multi-NULL-unique-index trap is AVOIDED by design (the `spawn_key` column with a `-`
  placeholder) — do not instead try a composite unique index over the nullable columns; it won't
  dedup fixed-spawn rows.
- Rule-edit propagation to already-spawned tasks (`update_rule(..., $propagate)`) — separate
  existing feature, not touched here.
- Hardening WP-Cron scheduling itself (overlap is possible because WP-Cron is request-driven) —
  out of scope; the transient lock + idempotency key handle the consequence, which is what matters.
- MAJ-6 (slips by creation date) — unrelated; separate directive, blocked on the operator
  delivery-date question.
