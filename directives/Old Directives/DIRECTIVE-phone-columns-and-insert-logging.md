# DIRECTIVE — Widen phone columns + log the silent insert failure in phase 1

**Baseline:** v1.0.566.
**Source:** staging migration diagnosis, 2026-08-25/26.
**Severity:** HIGH — phase 1 "Create Meals Clients" currently creates **zero** clients, and reports the
failures as a bare error count with no explanation anywhere.

**Note:** ITEM 2 has already been applied by hand to Zak's local staging copy in order to diagnose this. It
needs committing to the repo properly; the local edit is not in version control.

---

## The bug, and how it was found

Phase 1 reports `created: 0, errors: N` on every live run and has done since at least 2026-06-25. Nothing
was created, and **no log anywhere named a reason** — not `mealsdb_migration_log`, not the event log, not
the PHP error log.

The cause is the `else` branch after the client `INSERT`
(`includes/services/class-migration-consolidated.php`, ~line 574):

```php
if ($insert_result !== false) {
    $stats['created']++;
} else {
    $stats['errors']++;          // <- no logging at all
}
```

`$wpdb->insert()` returns `false` and `$wpdb->last_error` holds the reason, which is then discarded. With a
one-line diagnostic added, the very next run produced:

```
[Consolidated] Insert failed for user 2610: WordPress database error:
Processing the value for the following field failed: client_phone_1.
The supplied value may be too long or contains invalid data.
```

**`client_phone_1` is `VARCHAR(20)`.** The source `billing_phone` usermeta contains free-text entries, not
bare phone numbers:

| user | len | value |
|---|---|---|
| 2638 | 21 | `506-577-0888 531-6747` |
| 3364 | 24 | `506-852-3540 or 372-4476` |
| 2600 | 25 | `506-858-5187 506-871-9388` |
| 2680 | 31 | `229-1777 (cell) or 506-215-0574` |
| 2603 | 38 | `506-204-7505 or 233-4452 (wife Evelyn)` |
| 2610 | 45 | `506-204-7747 or 1-506-345-0237 (sister Diane)` |

**104 usermeta rows** across `billing_phone`, `billing_phone_2` and `mealsdb_client_phone_2` exceed 20
characters. That is real operational information — a second number, whose phone it is, who answers it — and
it should be preserved, not truncated.

**Cost of the silence:** an entire debugging session. Six wrong hypotheses were pursued (ENUM/`uq_wp_user`
duplicate, encryption key, candidate-selection offset, `customer_group` case/whitespace, deleted WP users,
insert format-array arity) before the one-line log named the real cause immediately.

---

# ITEM 1 — Widen the four phone columns

In `includes/class-schema.php`, lines **29, 30, 32, 33**:

```php
'client_phone_1'            => 'VARCHAR(100) NULL',
'client_phone_2'            => 'VARCHAR(100) NULL',
'alternate_contact_phone_1' => 'VARCHAR(100) NULL',
'alternate_contact_phone_2' => 'VARCHAR(100) NULL',
```

All four together. The same free-text pattern lives in the alternate-contact meta; widening only the two
that happen to fail today guarantees a repeat.

**100 chars** covers the longest current value (45) with headroom for the annotation style in use.

### This is a SAFE drift and will auto-apply

`MealsDB_Schema_Alter_Planner` (~line 179) classifies *"widen VARCHAR/CHAR/TEXT"* as **SAFE**, so it
auto-applies via online DDL on the version bump — no operator confirmation needed.

**Contrast with the v558 blocker:** that change bundled an ENUM *removal* with an addition, which
reclassified the whole column change as RISKY and silently withheld it. This one is a pure widening. **Do
not bundle anything else into these lines** — no NULL/NOT NULL change, no default change, no rename.

Bump the plugin version so `mealsdb_maybe_upgrade_schema()` fires.

## Verify
- `SHOW COLUMNS FROM 2xnIt_meals_clients LIKE 'client_phone%';` → both `varchar(100)`. Same for the two
  `alternate_contact_phone_*` columns. 📷
- The schema drift tool reports clean afterwards.
- Existing rows are unaffected (widening is non-destructive).

---

# ITEM 2 — Log the insert failure (commit the hand-applied patch)

Same file, the `else` branch after the client insert:

```php
if ($insert_result !== false) {
    $stats['created']++;
} else {
    $stats['errors']++;
    self::log(sprintf('Insert failed for user %d: %s', $uid, $wpdb->last_error ?: 'unknown'));
}
```

`self::log()` routes to `MealsDB_Migration::append_log()`, which is readable from the
`mealsdb_migration_log` option — the same place every other phase result already lands.

**This matches the file's own convention.** Phase 2 (`create_rates`) already logs its insert failure with
`$wpdb->last_error ?: 'unknown'`. Phase 1 was the outlier.

### Audit the other silent counters

While in this file, check every `$stats['errors']++` in **all** phases and confirm each is accompanied by
either a `self::log()` or an event-log record. In `run_phase_create_clients` there are four error branches:
missing WP user, unrecognised `customer_group`, encryption failure, and the insert. Only the insert was
silent — but the missing-WP-user branch is also worth a line, since it is equally invisible today.

An error counter that increments without saying why is the defect class this directive exists to close.

## Must NOT change
- The counter semantics (`created` / `skipped` / `errors`) — the UI and the log parse them.
- The dry-run short-circuit at `if ($dry_run) { $stats['created']++; continue; }`. **Note for readers:** a
  dry run increments `created` for every candidate it *reaches* without attempting an insert, so dry-run
  `created` and live-run `created` are not comparable. That mismatch caused real confusion during
  diagnosis; a clarifying comment there would be worth more than it costs.
- The idempotency `skipped` check.
- The candidate query and its `LIMIT`/`OFFSET` pagination (verified working: offsets advanced 100 → 200 →
  300 → 400 across 610 candidates).

## Verify
- Run phase 1 live with a deliberately over-long value still present → the log shows
  `[Consolidated] Insert failed for user NNN: <reason>` naming the user and the database message. 📷
- After ITEM 1 lands, re-run → `created` is non-zero and no insert-failure lines appear.

---

# Post-deploy sequence (operator)

1. Deploy; confirm the four columns are `varchar(100)`.
2. **Reload the migration page** (the pagination offset lives in page JS; a stale page resumes mid-range).
3. Run the consolidated migration **live**. Expect roughly **103 clients created** — 610 candidates matching
   the government `customer_group` list, minus the 507 that already have client rows.
4. Confirm: `SELECT COUNT(*) FROM 2xnIt_meals_clients;` rises from 874 by ~103, and
   `customers_without_client_record` falls from 96 toward ~11 (the 10 private plus one blank-group user).
5. The new clients need delivery days, delivery dates and allocations — re-run phases 5 and 8, then the
   phase 9 / phase 10 delivery-date backfill.
6. Re-run the ground-truth scorecard and the allocation coverage count.

---

# Related, not in this build

- **Data quality:** 104 usermeta phone rows contain free text rather than numbers. Widening preserves them;
  splitting primary/secondary/annotation into their proper fields is a separate data exercise for Janet.
- The 10 private-group and 1 blank-group customers still without client records — phase 6 and a deleted-user
  decision respectively.
