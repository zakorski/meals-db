# Directive MAJ-2 — allocation_errors: retention + dedup

**Status:** ready to implement (code-only, post-launch hygiene — lowest urgency of the three)
**Severity:** MAJOR (slow-burn). The `meals_allocation_errors` table has no pruning and no dedup:
it grows unbounded, and a recurring allocation problem writes a fresh row every nightly rebuild,
burying signal under repetition.
**Verified at:** v1.0.406. Single writer: `MealsDB_Allocation_Rebuilder::log_spillover_error()`
(~line 565). Read in reports (~1490). No prune anywhere (the STR-LOG retention rewrite handles
only `meals_event_log`).

---

## THE CURRENT BEHAVIOR (verified)

`log_spillover_error()` does a bare insert, every time:

```php
$this->wpdb->insert($table, [
    'client_id'      => $client_id,
    'billing_month'  => $billing_month,
    'wc_order_id'    => $wc_order_id,
    'error_type'     => 'multi_month_spillover',
    'mains_unplaced' => $mains_unplaced,
    'sides_unplaced' => $sides_unplaced,
    'message'        => $message,
]);
```

Two problems:
1. **No dedup.** The nightly rebuilder re-processes dirty months; the SAME spillover on the SAME
   order writes a NEW row each run. After a week of an unresolved spillover, there are 7 identical
   rows. The natural identity of the error is `(client_id, billing_month, wc_order_id,
   error_type)` — a repeat should UPDATE (bump a count, refresh last-seen + the latest figures),
   not INSERT.
2. **No retention.** Nothing ever deletes from this table. Unlike the event-log trunk (pruned by
   `MealsDB_Log_Retention` since STR-LOG), `allocation_errors` accumulates forever.

Note: `error_type` is currently always `'multi_month_spillover'` (one writer, one type). The
dedup key includes `error_type` anyway so future error types dedup correctly without a schema
change.

---

## PRE-FLIGHT VERIFICATION

```bash
cd <plugin-root>
# 1. The single writer + its columns.
grep -n "function log_spillover_error\|ALLOCATION_ERRORS" includes/services/class-allocation-rebuilder.php
# 2. The table schema (we add a dedup index + count/last-seen columns).
grep -n "ALLOCATION_ERRORS" includes/class-schema.php
sed -n "$(grep -n 'ALLOCATION_ERRORS =>' includes/class-schema.php | head -1 | cut -d: -f1),+30p" includes/class-schema.php
# 3. Confirm nothing already prunes it (should be nothing).
grep -rn "ALLOCATION_ERRORS\|allocation_errors" includes/class-log-retention.php
# 4. The retention cron we'll hook into (STR-LOG rewrote it).
grep -n "function run\|prune" includes/class-log-retention.php | head
# 5. Any OTHER writer (must be exactly one for the dedup to be complete).
grep -rn "ALLOCATION_ERRORS" includes/ --include=*.php | grep -i "insert\|->insert"
#    STOP if there is more than one insert site — dedup must cover all of them.
```

---

## THE FIX

### Step 1 — Schema: add count + timestamps + a dedup unique key (additive — STR-11 safe)

To `MealsDB_Schema` for `ALLOCATION_ERRORS`, ADD columns (NULL/defaulted so existing rows are
fine):
- `occurrence_count` `INT UNSIGNED NOT NULL DEFAULT 1`
- `first_seen_at` `DATETIME NULL`
- `last_seen_at`  `DATETIME NULL`

…and a UNIQUE index on the dedup key:
- `['name' => 'uniq_dedup', 'type' => 'UNIQUE', 'columns' => ['client_id', 'billing_month', 'wc_order_id', 'error_type']]`

**STR-11 caveat — the additive schema sync only ADDS columns/indexes; it will NOT retro-dedup
existing rows, and a UNIQUE index cannot be added to a table that already contains duplicate
rows.** Two cases:
- **Fresh installs / no existing dupes:** the UNIQUE index applies cleanly. (This is the launch
  case — the operator confirmed no live data.)
- **Existing installs WITH dupes:** adding the UNIQUE index will FAIL on duplicate rows. Provide a
  one-time cleanup that collapses dupes to a single row (max figures, summed/known count) BEFORE
  the index is added. Given the operator confirmed no live data to migrate, this cleanup can be a
  documented manual step rather than automated migration code — but state it explicitly so a
  future populated install isn't broken by the index add. If you choose to automate, gate it
  behind the schema-version bump so it runs once.

### Step 2 — Writer becomes upsert (dedup)

Rewrite `log_spillover_error()` to UPSERT on the dedup key. Two clean approaches; pick per the
codebase's existing idiom:

**Approach A — `INSERT ... ON DUPLICATE KEY UPDATE`** (one statement, atomic, relies on the
UNIQUE index):
```php
$table = MealsDB_DB::get_table_name(MealsDB_Tables::ALLOCATION_ERRORS);
$now   = gmdate('Y-m-d H:i:s');
$sql = $this->wpdb->prepare(
    "INSERT INTO `{$table}`
        (client_id, billing_month, wc_order_id, error_type,
         mains_unplaced, sides_unplaced, message,
         occurrence_count, first_seen_at, last_seen_at)
     VALUES (%d, %s, %d, %s, %d, %d, %s, 1, %s, %s)
     ON DUPLICATE KEY UPDATE
        mains_unplaced   = VALUES(mains_unplaced),
        sides_unplaced   = VALUES(sides_unplaced),
        message          = VALUES(message),
        occurrence_count = occurrence_count + 1,
        last_seen_at     = VALUES(last_seen_at)",
    $client_id, $billing_month, $wc_order_id, 'multi_month_spillover',
    $mains_unplaced, $sides_unplaced, $message, $now, $now
);
$this->wpdb->query($sql);
```
(first_seen_at is set only on INSERT; last_seen_at + figures refresh on repeat; count increments.)

**Approach B** — SELECT-then-insert/update via `$wpdb->insert`/`update` (more `$wpdb`-idiomatic,
two statements, small race window). Prefer A unless the codebase avoids raw `query()` for writes.

Keep the method signature unchanged so the call site in the rebuilder doesn't change.

### Step 3 — Retention: prune aged allocation_errors in the existing cron

`MealsDB_Log_Retention::run()` already prunes the event-log trunk in bounded passes (STR-LOG). Add
one more bounded pass for `allocation_errors`, mirroring the trunk's discipline (LIMIT per pass,
gmdate cutoff). Retention policy: prune rows whose `last_seen_at` is older than a window — these
are RESOLVED-by-absence errors (the spillover stopped recurring). Recommended window: keep ~1 year
(allocation errors are low-volume and investigation-relevant — match the trunk's LONG band, 365
days), then prune. NEVER prune by `first_seen_at` (a long-running recurring error has an old
first_seen but a recent last_seen — it's still active; keep it). Use `last_seen_at < cutoff`.

```php
// in run(), alongside the trunk prune passes:
$deleted_alloc = self::prune_allocation_errors(self::LONG_DAYS);   // last_seen_at based
// add $deleted_alloc to the finish() stats
```
Implement `prune_allocation_errors($days)` as a bounded `DELETE ... WHERE last_seen_at < %s
LIMIT MAX_ROWS_PER_PASS` (same cap as the trunk passes). Rows with NULL last_seen_at (legacy,
pre-upgrade) — coalesce to a safe column for the comparison (e.g. treat NULL as "old" only if you
also backfill last_seen_at on upgrade; simplest: `WHERE COALESCE(last_seen_at, first_seen_at,
'1970-01-01') < %s` so legacy rows without timestamps eventually age out).

`meals_audit_log` and the trunk are untouched — this only adds an allocation_errors pass.

---

## TESTS (`tests/test-allocation-errors-dedup.php` + extend `tests/test-log-retention.php`)

- **T-1 first occurrence inserts:** `log_spillover_error()` for a new
  (client, month, order, type) inserts one row, `occurrence_count = 1`, first/last_seen set.
- **T-2 repeat dedups:** calling it again for the SAME key does NOT add a row; it bumps
  `occurrence_count` to 2, refreshes `last_seen_at` and the unplaced figures + message, leaves
  `first_seen_at` unchanged.
- **T-3 different key inserts:** a different order_id (or month, or type) inserts a separate row.
- **T-4 retention prunes by last_seen:** an allocation_errors row with `last_seen_at` older than
  the window is deleted by `run()`; a row with a RECENT last_seen but OLD first_seen is KEPT.
- **T-5 retention pass is bounded:** the allocation_errors DELETE includes a `LIMIT` (same backlog
  -lock guard as the trunk passes) and targets `meals_allocation_errors`.
- **T-6 retention still does the trunk:** the existing trunk prune passes still run (don't
  regress STR-LOG's retention by adding the new pass).

Run new test + FULL suite. `test-log-retention.php` is the regression-sensitive one (it asserts
the number of DELETE passes — that count goes up by one; update its expectation to match, and
note it).

---

## ACCEPTANCE CRITERIA

1. `allocation_errors` has `occurrence_count`, `first_seen_at`, `last_seen_at`, and a UNIQUE dedup
   index on `(client_id, billing_month, wc_order_id, error_type)` (additive schema).
2. `log_spillover_error()` upserts: repeats bump the count + refresh last_seen/figures instead of
   inserting duplicates; signature unchanged.
3. `MealsDB_Log_Retention::run()` prunes aged allocation_errors by `last_seen_at` in a bounded
   pass, without disturbing the trunk or audit-log retention.
4. The UNIQUE-index-on-existing-dupes hazard is handled (cleanup step documented/automated and
   gated).
5. New test green; full suite green; `test-log-retention` pass-count expectation updated.

---

## OUT OF SCOPE

- Surfacing allocation_errors on the Event Log dashboard or digest — separate nicety; this
  directive is storage hygiene only. (Though now that repeats carry `occurrence_count`, a future
  dashboard view becomes more useful — worth noting, not building.)
- Changing what COUNTS as an allocation error or when spillover is logged — the writer's trigger
  logic is unchanged; only its write strategy (upsert) and the table's lifecycle change.
- The event-log trunk / audit-log retention — untouched.
