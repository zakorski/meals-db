# K13 v2 — Sync must not blank a column from a meta key that does not exist

**Date:** 2026-09-14
**Directive:** `directives/DIRECTIVE-K13-v2-sync-blanking-fix.md`
**Targets:** `includes/class-sync.php`, `includes/services/sync/class-sync-mutate.php`
**Severity:** HIGH — silent, recurring, total data loss across 992 clients
**Depends on:** v1.0.578 (K12)

---

## Problem

`MealsDB_Sync::get_field_to_wp_meta_map()` (`class-sync.php:114`) maps nine `meals_clients`
columns to usermeta keys prefixed `mealsdb_`, under the comment *"Plugin-managed custom meta (no
standard WC equivalent)."* Seven of those keys have never existed on this install; two exist for
8–9 users (verified on production 2026-09-14).

The nightly sync (`run_nightly_sync`, `class-sync.php:462–558`) reads the mapped key via
`read_wp_field_value()` (`:817`), which returns `''` for a key that does not exist.
`normalize_for_comparison()` then sees `''` differ from the real column value and
`push_to_meals_db()` writes the `''` over the column — every night, every client. Street was
restored by hand on 2026-09-09 and was blank again by 2026-09-14.

The comment is factually wrong: street has a standard WC equivalent —
`billing_address_1` / `shipping_address_1` (2,322 usermeta rows each) — which is where the
migration reads it from and where the real data lives.

### Verified against the code

| Claim | Evidence |
|---|---|
| Map sends 9 columns to `mealsdb_*` keys | `class-sync.php:132–141` |
| `street_name` / `delivery_street_name` are WP-*authoritative* | `class-sync.php:34,38` |
| WP-ward push resolves its destination through the **same map** | `apply_wp_user_update()` → `get_field_to_wp_meta_map()`, `class-sync-mutate.php:994` |
| Nightly sync is **WP→DB only** for these fields | two loops at `class-sync.php:513` and `:775`, both call `push_to_meals_db` |
| `read_wp_field_value()` collapses absent-vs-empty into `''` | `class-sync.php:817` |
| WP-ward push happens **only** via conflict-resolution AJAX + an internal secondary path — **not** nightly, **not** the client form | callers of `push_to_woocommerce`/`update_wp_user`: `class-ajax-sync.php:58`, `class-sync-mutate.php:284`; form save does not route through these |
| Migration fallback keys | `client_phone_2` ← `billing_phone_2`; each `alternate_contact_*` ← its unprefixed key (`class-migration-consolidated.php:440,497–500`) |
| Shadow mode suppresses DB→WP at a single chokepoint | `update_wp_user()`, `class-sync-mutate.php:64` |

---

## Design

Four layers, innermost first. ITEM 2 must ship even if ITEM 1 is partial; ITEM 3 is what makes
ITEM 1 safe to deploy.

### ITEM 2 — Absent key ≠ empty value (the class fix)

Root cause: `read_wp_field_value()` returns `''` both when a meta key is set to empty and when it
does not exist. Fix at the source.

- New private helper `read_wp_field_value_with_presence(WP_User $user, array $descriptor): array`
  returning `[$value, $present]`.
  - `type=core` → `$present = isset(...)` (email/first/last always effectively present).
  - `type=meta` → `$present = metadata_exists('user', $user->ID, $descriptor['key'])`.
- Both nightly loops (`:513`, `:775`) use it. When `$present === false` → `continue`: skip the
  field, write nothing, leave the DB value alone. An absent key means "no opinion."
- `read_wp_field_value()` may be kept as a thin wrapper (returns element 0) or its two callers
  migrated; the presence-aware helper is the single source of truth.

**Event logging — one row per field per run, not per client.** Accumulate the set of skipped
field→key pairs during the run; at run end emit one `sync.meta_key_missing` event per pair:

```
sync.meta_key_missing | degraded | "street_name -> billing_address_1 absent for 992 users; column left unchanged"
```

via `MealsDB_Event_Log::record()` (`category=sync`, `subsystem=sync`, `outcome=degraded`).

### ITEM 1 — Correct the 9 mappings

In `get_field_to_wp_meta_map()`, each remap carrying the directive's "why" comment:

| Column | Old key (0–9 rows) | New key |
|---|---|---|
| `street_name` | `mealsdb_street_name` | `billing_address_1` |
| `delivery_street_name` | `mealsdb_delivery_street_name` | `shipping_address_1` |
| `client_phone_2` | `mealsdb_client_phone_2` | `billing_phone_2` |
| `alternate_contact_name` | `mealsdb_alternate_contact_name` | `alternate_contact_name` |
| `alternate_contact_phone_1` | `mealsdb_alternate_contact_phone_1` | `alternate_contact_phone_1` |
| `alternate_contact_phone_2` | `mealsdb_alternate_contact_phone_2` | `alternate_contact_phone_2` |
| `alternate_contact_email` | `mealsdb_alternate_contact_email` | `alternate_contact_email` |

The five non-address remaps mirror exactly what `class-migration-consolidated.php` reads — the
one code path that populated these columns — so the sync becomes internally consistent with it.
With ITEM 2 in place, a still-absent fallback key can no longer blank a column; the only residual
consideration is data-correctness, which is bounded and accepted (operator decision, 2026-09-14).

`next_order_date` / `next_delivery_date` **stay** on their `mealsdb_*` keys: they are computed by
the next-dates migration phase, not sourced from the WP user. ITEM 2 neutralizes them. Whether
they belong in this map at all is a follow-up, not this directive.

### ITEM 3 — Never write empty over non-empty, both directions

**This is what makes ITEM 1 safe.** After ITEM 1, `street_name` writes to `billing_address_1` —
the only surviving copy of every address. Because `street_name` is WP-authoritative and the
WP-ward push resolves through the same map, an unguarded push of the (currently blank)
`street_name` would write `''` over the real `billing_address_1`.

- **WP→DB** (nightly loops, `push_to_meals_db` path): if incoming `$wp_value` is empty/whitespace
  **and** the existing column value is non-empty → skip the field, log
  `sync.empty_overwrite_refused` (`warning`, field + client id). ITEM 2 covers absent keys; this
  covers a key that exists but has been emptied while the DB holds a real value.
- **DB→WP** (`push_to_woocommerce` → `apply_wp_user_update`, `class-sync-mutate.php`): if incoming
  value is empty and the existing WP meta is non-empty → refuse, log
  `sync.empty_overwrite_refused` (`warning`, field + user id). Protects `billing_address_1` from
  the conflict-resolution AJAX (`class-ajax-sync.php:58`) and the internal secondary path
  (`class-sync-mutate.php:284`).

**Scope: sync mutators only.** Verified that the client form save does not route through these
methods, so a field a operator deliberately clears via the client form is still honoured. A
comment records this scoping and the reason.

**Shadow mode:** the DB→WP hazard is latent today because `update_wp_user` (`:64`) suppresses all
write-back when shadow mode is enabled. The guard ships regardless — shadow mode is a toggle that
can be turned off. A comment records the current state.

### ITEM 4 — Mass-blank circuit breaker

Defence against the *next* mapping mistake, bad migration, or partial restore — all of which
share one signature: the same field goes empty on a large fraction of clients in a single run.

**Relationship to ITEM 3 (deliberate overlap):** ITEM 3 refuses each empty-over-non-empty write
individually and quietly (`warning`), and the run still reports success. That normalizes a
systemic event into 992 lines of background noise. ITEM 4 is the aggregate judgment: if a field
crosses the threshold, the run is not trusted at all — abort it entirely, write nothing (not even
the writes that looked fine), and raise one `error`. ITEM 3 makes each blanking harmless; ITEM 4
makes a *mass* blanking loud and total.

**Implementation — whole-run pre-flight pass** (required because the nightly sync is a streaming
loop with no stage-then-commit phase; you cannot honour "write nothing" after discovering the
problem mid-walk):

1. **Pass 1 (count-only, no writes):** walk the active population; for each mapped field tally how
   many clients have a *currently non-empty* column that the WP side would set empty (the ITEM 3
   refusal candidates). Absent keys (ITEM 2) do not count.
2. **Gate:** if any field's tally exceeds `SYNC_MASS_BLANK_THRESHOLD_PCT` (`20`) of the active
   population → abort **before any write**, log `sync.mass_blank_refused` (`error`, field +
   count), finish the job `degraded`. Threshold is a named constant with the reasoning in a
   comment.
3. **Pass 2 (real sync):** runs only if the gate passed; ITEM 3 still guards each write as the
   second line of defence.

**Denominator:** the count of active clients in the same
`client_type IN ('SDNB','Veteran','Private')` set the sync walks (`:465`), so 20% measures against
the real population.

**Whole-run, not per-field:** one field over threshold kills the entire run, because a broken
mapping casts doubt on every write that run. (Operator decision, 2026-09-14.)

Cost: two reads of ~992 rows nightly, off the hot path (02:00). Negligible.

---

## Testing

PHPUnit against the existing suite plus the K12 additions. No test may require network access or a
live cron; the mutator logic is driven through the real methods with a stubbed `$wpdb`, matching
the existing sync-test fixtures.

1. **Absent key does not blank.** `street_name = '123 Main St'`, no `mealsdb_street_name` row →
   column unchanged after sync. **Fails against v1.0.578.**
2. **Present-empty key is refused by ITEM 3, not skipped by ITEM 2.** Key exists with `''`, column
   holds a real value → column unchanged and `sync.empty_overwrite_refused` logged (NOT
   `sync.meta_key_missing`). This proves `metadata_exists()` genuinely distinguishes present-empty
   from absent: a lazy ITEM 2 that skipped *all* empty values would take the absent branch and log
   the wrong event. *(Post-v2 the sync legitimately can never empty a non-empty column in either
   direction — that is ITEM 3 by design; deliberate clears go through the client form, which is
   out of ITEM 3's scope.)*
3. **Present key with a value writes it.**
4. **Remapped read.** `billing_address_1 = '5 Elm St'`, no `mealsdb_street_name` → `street_name`
   becomes `'5 Elm St'`.
5. **ITEM 3, WP-ward:** `street_name = ''` in meals_clients, `billing_address_1 = '5 Elm St'` →
   push refuses, `billing_address_1` unchanged, `sync.empty_overwrite_refused` logged. **Fails
   against a build with ITEM 1 but not ITEM 3** (and against v1.0.578, where the event does not
   exist and the push targets `mealsdb_street_name`).
6. **ITEM 3 allows a real value** to overwrite a different real value.
7. **Mass-blank refusal.** 100 clients with populated `street_name`, keys absent → run aborts,
   nothing written, `sync.mass_blank_refused` logged. **Fails against v1.0.578.**
8. **Under threshold proceeds.** 1 client of 100 → normal write.
9. **One `sync.meta_key_missing` event per field per run**, not per client.

---

## Acceptance criteria

- No mapping points at a `mealsdb_*` key with zero usermeta rows unless ITEM 2 neutralizes it and
  a test proves it (the two next-date fields).
- `metadata_exists()` gates every meta read-to-write in the sync path.
- No sync path can write an empty value over a non-empty one in either direction.
- A sync run against a database with all custom keys absent writes **nothing** to those columns.
- Running the sync twice in succession on restored data leaves the data unchanged — so
  re-enabling the nightly cron is safe by construction (no separate "disable the cron" operational
  step is required).
- Tests 1, 5 and 7 each fail against v1.0.578.

---

## Out of scope

- Restoring the already-blanked data (separate SQL, run after this ships).
- The `Pull Data` button and `class-wp-user-mapper.php`.
- Whether `next_order_date` / `next_delivery_date` belong in the map.
- Shadow-mode behaviour itself — ITEM 3 only records its current state.
- The "disable the nightly cron before restore" operational step — dropped by operator decision:
  the fix makes re-running the sync idempotent-safe, which the acceptance criteria enforce.
