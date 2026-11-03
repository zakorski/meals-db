# DIRECTIVE K21 — Bidirectional fill-blank sync with A/B conflict resolution

**Targets:** `includes/class-sync.php`, `includes/services/sync/class-sync-compare.php`,
`includes/services/sync/class-sync-query.php`, `includes/services/sync/class-sync-mutate.php`,
`includes/ajax/class-ajax-sync.php`, `includes/services/class-migration-consolidated.php`,
`views/dashboard.php`, schema/tables
**Severity:** HIGH — writes to both data stores
**Depends on:** v1.0.591 (K20)

---

## Revision 2 (2026-11-03) — operator decisions after code review

This revision supersedes the original text wherever they differ. Changes:

1. **Fill-blank is strict** and confirmed. No automated path overwrites a populated value; the only
   automated exception is the `delivery_initials` → `nickname` push (ITEM 5).
2. **Reconcile per WordPress account, not per client** (ITEM 7a). All active clients linked to one
   `wp_user_id` must agree on all 26 fields. `client_type` is the one legitimate exception
   (dual-program people); it is handled by the operator's manual dismissal — **nothing is built for
   it** (ITEM 3).
3. **Breakers:** only K13's mass-blank pre-flight remains. The mass-conflict breaker and the
   mass-write breaker are **removed** (ITEM 7). Confidence comes from the no-overwrite invariant test
   (Test 32), not a runtime breaker. A large first run of fills and conflicts is expected and
   accepted.
4. **One open conflict per `(wp_user_id, field)`**, replaced by the newest observation on every run
   (ITEM 8).
5. **Batching and stale-page protection** for resolution (ITEMs 9, 10).
6. **Factual corrections:** the `phone_primary` "defect" was not a defect; `delivery_day` *does*
   have a usermeta key; Test 16 passes on v1.0.591 (see the relevant sections).
7. **Gaps closed:** the registry must absorb five maps, not two; integer comparator for the
   frequency fields; unparseable dates never fill; derived fields are themselves fill-blank on
   automated paths; the migration's `delivery_area_name` source aligned with the sync; existing
   dual-link bugs in `push_to_woocommerce()` and `find_tracked_client_by_wp_user()` fixed.

Shadow mode is **off** in production (operator, 2026-11-03): every WordPress write in this directive
is live the moment it ships.

---

## Goal

Every linked WordPress account and its active MealsDB client(s) are reconciled field by field. Each
field lands in exactly one of three outcomes:

| State | Action |
|---|---|
| All non-blank values equal (after normalisation) | fill every blank side from that value |
| Every side blank | nothing |
| Any two non-blank values differ | **queue a conflict, write nothing for that field** |

For the common case — one client per account — this is the plain two-sided rule: equal → nothing;
one side blank → copy from the populated side; both populated and different → conflict.

**No automated path ever overwrites a populated value.** Conflicts are resolved by an operator in a
side-by-side view, choosing per field which value to keep — the way Ancestry compares two records
for one person.

---

## Existing bones — build on these, do not replace them

- `MealsDB_Sync::run_nightly_sync()` — WP → MealsDB with K13's `metadata_exists()` gate,
  empty-over-real refusal and mass-blank pre-flight breaker.
- `get_field_to_wp_meta_map()` / `get_wp_authoritative_fields()` — 17 fields today.
- `MealsDB_Sync_Compare::detect_mismatches()` — per-client mismatch records carrying both
  snapshots, plus WordPress-only and MealsDB-only records, with a `staff_wp_ids` exclusion.
  Rendered on `views/dashboard.php`.
- `filter_ignored()` + `meals_ignored_conflicts`.
- `class-ajax-sync.php` resolve endpoint (`sync_field`) — already pushes a chosen value either
  direction.
- `MealsDB_Sync::$syncing` — re-entrancy guard against hook loops.
- `MealsDB_Shadow_Mode::is_enabled()` — suppresses WP write-back; defaults ON when unset (currently
  explicitly OFF in production).
- `MealsDB_Phone::is_same_number()` / `has_number()` (K20).
- `MealsDB_Zone_Day::day_for_zone()` (`includes/services/class-zone-day.php`) — zone → delivery day.
- `MealsDB_Migration_Consolidated::customer_group_to_client_type()` — Directive 7's single
  `customer_group` → `client_type` mapping.

**Correction (Rev 2):** the original text called `compare_fields()` reading `$client['phone_primary']`
a defect. It is not: `MealsDB_Sync_Query::build_client_column_map()` selects
`client_phone_1 AS phone_primary`. The real limitation is that the comparison query
(`get_meals_clients()` / `normalize_client_row()`) returns only six identity columns and
`compare_fields()` checks only four fields. ITEM 1 replaces both; the A/B view needs a query that
returns every registry column.

---

## Scope

### Two-way, fill-blank only — 26 fields

| MealsDB | WordPress | Comparator |
|---|---|---|
| `first_name` | core `first_name` | text |
| `last_name` | core `last_name` | text |
| `client_email` | core `user_email` | email |
| `client_phone_1` | `billing_phone` | phone |
| `client_phone_2` | `shipping_phone` | phone |
| `street_name` | `billing_address_1` | text |
| `city` | `billing_city` | text |
| `province` | `billing_state` | code |
| `postal_code` | `billing_postcode` | postal |
| `delivery_street_name` | `shipping_address_1` | text |
| `delivery_city` | `shipping_city` | text |
| `delivery_province` | `shipping_state` | code |
| `delivery_postal_code` | `shipping_postcode` | postal |
| `alternate_contact_name` | `alternate_contact_name` | text |
| `alternate_contact_phone_1` | `alternate_contact_phone_1` | phone |
| `alternate_contact_phone_2` | `alternate_contact_phone_2` | phone |
| `alternate_contact_email` | `alternate_contact_email` | email |
| `client_type` | `customer_group` | client-type class (ITEM 3) |
| `delivery_area_name` | `shipping_address_2` (+ `billing_address_2`) | text (ITEM 4) |
| `service_center_charged` | `service_centre_charged` | text |
| `payment_method` | `payment_method` | text |
| `delivery_frequency` | `delivery_frequency` | integer |
| `ordering_frequency` | `ordering_frequency` | integer |
| `service_id` | `service_id` | text |
| `service_commence_date` | `commence_date` | date |
| `expected_termination_date` | `service_termination_date` | date |

### One-way, MealsDB → WordPress — 1 field

`delivery_initials` → `nickname`. See ITEM 5.

### Derived — filled when their source is filled or resolved

- `delivery_area_zone` ← `service_center_charged`
- `delivery_day` ← `delivery_area_name`

See ITEM 6.

### Excluded

- **Billing quantities:** `client_contribution`, `delivery_fee`, `allowance_mains`,
  `allowance_sides`, `requisition_period`, `units`.
- **Manual by design:** `use_legacy_billing`.
- **Encrypted:** `individual_id`, `requisition_id`, `vet_health_card`, `diet_concerns`,
  `customer_comments`. Syncing MealsDB → WP would write decrypted government identifiers into
  `wp_usermeta`.
- **Computed:** `next_order_date`, `next_delivery_date`, `last_delivery_date`.
- **The `delivery_day` usermeta key.** *(Rev 2 correction: the original said `delivery_day` has no
  WordPress twin. The migration reads `$meta['delivery_day']` — `class-migration-consolidated.php`
  ~line 511.)* `delivery_day` stays derived from the zone schedule (ITEM 6); the usermeta key is
  neither read nor written by the sync.
- **Inactive clients** (`active = 0`) — not read, compared or written by any sync path, and not
  counted as a linked client in ITEM 7a.
- **Staff accounts** — every `staff_wp_ids` user and any user with administrator capability. In
  particular `wp_user_id` 1 is the site `admin` account and is linked to test client 1; it must not
  be synced. *(Note: the current nightly and real-time hooks do NOT exclude staff today — only the
  dashboard comparison does. This is a live bug that this directive fixes.)*

Every other MealsDB column remains out of scope.

---

## ITEM 1 — One field registry

Replace the parallel maps with a single registry — the only source of truth for the sync, the
comparison, the resolution endpoint and the conflict view. Each entry declares: MealsDB column, WP
source (core field, meta key, or several), direction (`two_way` / `to_wp_only`), comparator, blank
test, and any derived fields that depend on it.

**Rev 2 — there are at least five field definitions to absorb, not two:**

1. `MealsDB_Sync::get_field_to_wp_meta_map()`
2. `MealsDB_Sync::get_wp_authoritative_fields()`
3. `MealsDB_Sync::get_mealsdb_authoritative_fields()` — also the allowlist in
   `MealsDB_Ajax_Sync::toggle_ignore()`
4. `MealsDB_Sync_Mutate::build_identity_column_map()` — the field → column allowlist both push
   methods use, plus the hardcoded `switch` in `apply_wp_user_update()`
5. `MealsDB_Sync_Compare::compare_fields()` and `MealsDB_Sync_Query::build_client_column_map()` /
   `normalize_client_row()`

Existing public accessors may remain as thin wrappers over the registry for existing callers.
**No field mapping may be defined outside it.** Two maps disagreeing is how K13 happened.

---

> **APPLIED (2026-11-03) — ITEMS 1–3, registry and comparators only; no sync behaviour changed.**
> `MealsDB_Sync_Registry` (`includes/services/sync/class-sync-registry.php`) holds the 27 fields;
> `MealsDB_Sync_Comparator` (`class-sync-comparator.php`) implements ITEM 2's table and ITEM 3's
> class comparison. Absorbed so far: maps 1, 2 and 4 of the list above
> (`get_field_to_wp_meta_map()` / `get_wp_authoritative_fields()` are now thin accessors returning
> the transitional `k13` subset — byte-identical output; the mutator's column map and its
> `apply_wp_user_update()` switch are registry-driven). **Still to absorb:** map 3
> (`get_mealsdb_authoritative_fields()`, the ignore allowlist) goes with ITEM 8's ignore
> integration; map 5 (`compare_fields()` / the sync-query identity columns) is replaced wholesale
> by ITEMs 8–9 — changing it earlier would orphan existing `meals_ignored_conflicts` rows, which
> are keyed on the old `phone_primary` field name. ITEM 3's mapping extended in
> `MealsDB_Migration_Consolidated` (`extramural`, `private`, `customer_group_class()`,
> `client_type_to_customer_group()`). Side effects: `customer_group_to_client_type('extramural')`
> now returns SDNB, which changes private-intake promotion for that 1 user from Private to SDNB; and
> the mutator now refuses to blank core fields and `billing_phone` (previously unguarded). Tests:
> `tests/test-sync-registry-k21.php`, `tests/test-sync-mutate-registry-k21.php`.

## ITEM 2 — Equality and blankness, per comparator

Comparison is normalised, or formatting differences flood the conflict queue. The production
overlap check (2026-10-30) found 99%+ agreement on every address and contact field **only after**
normalisation.

| Comparator | Equal when | Blank when |
|---|---|---|
| text | `mb_strtolower(trim())` equal, internal whitespace collapsed | trimmed empty |
| email | lowercase/trim equal | trimmed empty |
| phone | `MealsDB_Phone::is_same_number()` | `!MealsDB_Phone::has_number()` |
| postal | uppercase, spaces removed, equal | trimmed empty |
| code | uppercase/trim equal | trimmed empty |
| integer | parsed integers equal | trimmed empty or `0` |
| date | parsed `Y-m-d` equal | trimmed empty, `0`, or `0000-00-00` |
| client-type class | ITEM 3 class equal | blank or unrecognised |

**Phone:** K20's rule — under 7 digits is not a phone. The 157 `shipping_phone = '0'` placeholders
read as blank, so a real MealsDB number fills them instead of each becoming a permanent conflict.

**Integer (Rev 2):** the migration stores `delivery_frequency` / `ordering_frequency` as `(int)`. A
WordPress value that is not a plain integer (e.g. `2 weeks`) is **not blank**: compare it raw so it
surfaces as a conflict, and **never** use it to fill the MealsDB INT column.

**Date:** both systems store ISO `YYYY-MM-DD` (633 `commence_date`, 568 `service_termination_date`
verified). An **unparseable** value is *not* blank — compare it raw, so it surfaces as a conflict
rather than being silently overwritten. **Rev 2: an unparseable value never fills the other side
either.** MealsDB blank vs WP `202409-02-03` is a conflict, not a fill into a DATE column.

General rule for every comparator: a value that fails its comparator's parse is never a fill
source. It is a conflict.

---

## ITEM 3 — `client_type` ↔ `customer_group`

Compare by **class**, case-insensitively, internal whitespace collapsed. Production values
(2026-11-02):

| `customer_group` | Users | Class |
|---|---|---|
| `sdnb` | 562 | SDNB |
| `SDNB` | 2 | SDNB |
| `sdnb rural` | 7 | SDNB |
| `Extra Mural` | 3 | SDNB |
| `extramural` | 1 | SDNB |
| `veterans` | 69 | Veteran |
| `private` | 421 | Private |
| *(blank)* | 11 | — |

**Rev 2 — extend the existing mapping, do not create a second one.**
`MealsDB_Migration_Consolidated::customer_group_to_client_type()` (Directive 7, "the SINGLE
customer_group → client_type mapping") already holds `sdnb`, `sdnb rural`, `extra mural`,
`veterans`. It lacks `extramural` and returns null for `private`. Extend it (or move it to a shared
home both the migration and the sync call) so it covers every row above. That map entry **is** the
"one place" where Extra Mural's provisional SDNB classification lives (operator decision 2026-11-02,
pending confirmation); comment it as provisional. A parallel constant elsewhere would reintroduce
the two-maps problem ITEM 1 forbids.

**The mapping is lossy toward MealsDB**, so:

- `sdnb rural` vs `SDNB` is **equal**, never a conflict.
- Filling a blank `customer_group` from MealsDB writes the canonical value: `sdnb`, `veterans`,
  `private`.
- **No path may replace a more specific Enzebra value with a less specific one.** A resolution
  choosing MealsDB for this field writes only when the classes differ, and then the canonical value
  for MealsDB's class.

An unrecognised value is **not blank** (Rev 2 — changed during implementation): treating it as
blank would let a fill overwrite it, which breaks the strict fill-blank rule and Test 32. It is
handled like any unparseable value — never filled over, never a fill source, so it surfaces as a
conflict — **and** it is logged once per run with the value, so a new Enzebra group surfaces rather
than silently defaulting. (`MealsDB_Sync_Comparator::is_unrecognised()` finds them.)

**Dual-program people (Rev 2):** `client_type` is the one field where linked clients legitimately
differ (an SDNB recipient who is also a Veteran). This is rare. **Build nothing special for it.** It
produces a normal conflict; the operator dismisses it with **Leave both** (ITEM 8), and the
dismissal holds until a value changes.

Note: the sync SELECTs filter `client_type IN ('SDNB','Veteran','Private')`, so a blank MealsDB
`client_type` is never read and WP → MealsDB filling of this field cannot occur. In practice this
field fills MealsDB → WP only, plus conflicts. That is acceptable; do not widen the filter.

---

## ITEM 4 — `delivery_area_name` ↔ the address_2 pair

The zone lives in WooCommerce `address_2` — deliberately; apartment and suite numbers are carried
inside `address_1`. `billing_address_2` and `shipping_address_2` agree on 1,060 of 1,061 users. In
the one disagreement (Gloria Cudahy), MealsDB matches `shipping_address_2`.

- **Read from WordPress:** `shipping_address_2` is the comparison source and wins.
- **Write to WordPress:** fill **both** `shipping_address_2` and `billing_address_2` when filling a
  blank, so they stay in step.
- A user whose two `address_2` keys disagree is a WordPress-internal inconsistency: compare MealsDB
  against `shipping_address_2` only, and log the disagreement once per run. Do not raise it as a
  MealsDB conflict.
- **Rev 2 — align the migration.** `class-migration-consolidated.php` reads `delivery_area_name`
  from `billing_address_2` (phase 1 ~line 356; addresses phase ~line 1033). Switch both to
  `shipping_address_2` (falling back to `billing_address_2` only when shipping is blank), or the
  next rebuild contradicts the sync.

---

## ITEM 5 — `delivery_initials` → `nickname` (one-way)

**MealsDB's `delivery_initials` is an immutable source of truth.** No sync, reconcile, real-time
hook or resolution may ever write `delivery_initials`.

Push to WordPress `nickname` only when:

- MealsDB `delivery_initials` is non-empty, **and**
- `mb_strlen(trim(nickname)) !== 3`.

WordPress sets `nickname` to the username on account creation, so it is populated on all 1,031
linked client users — a plain blank test would never write. Anything not exactly 3 characters
(`Betty Ann`, `admin`, `anne.potter`) is treated as blank and overwritten. (Initials are enforced
as `^[A-Z]{3}$` by `MealsDB_Initials` / the validator, so a pushed value always passes the
3-character test on the next run and is never rewritten.)

**An existing 3-character nickname is never touched, even when it differs from MealsDB.** Measured
2026-11-02: 13 would be overwritten; 615 are 3-character codes that differ and are left alone by
design. These do **not** appear in the conflict view.

This is the **only** automated overwrite of a populated value anywhere in the sync.

Where several active clients share one account (ITEM 7a), push only when all of their non-empty
`delivery_initials` agree; otherwise skip and log.

---

## ITEM 6 — Derived fields

Neither has a WordPress twin in this sync. Each is filled **only when its source is filled or
resolved** — through the sync, a real-time hook, or a resolution — and otherwise left alone.

**Rev 2 — derived fields are fill-blank on automated paths too.** When an automated fill sets the
source:

- derived field blank → write the computed value (audit + event, below);
- derived field populated and equal to the computed value → nothing;
- derived field populated and different → **do not write**; log `sync.derived_mismatch` with the
  stored and computed values.

Only a **resolution** (an operator action) recomputes and overwrites a populated derived value.
Without this rule, filling a blank `service_center_charged` would silently overwrite a hand-set
`delivery_area_zone` and move the client to a different invoice and rate.

A computed value of `null` (unresolvable source) never writes. It leaves the stored value unchanged
and logs.

### `delivery_area_zone` ← `service_center_charged`

The migration's lookup is **case-sensitive and silently drops valid values**
(`class-migration-consolidated.php` ~line 352):

```php
$zone_map = ['Moncton' => 'M', 'Sussex' => 'S', 'veterans' => null];
$zone     = $zone_map[$sc_raw] ?? null;
```

`moncton` does not match `Moncton`, so the zone comes out NULL. This produced 19
`derive_zone.unmatched` events after the 2026-09-07 rebuild and blanked 15 SDNB legacy clients'
zones, which had to be restored by hand. It will recur on the next rebuild.

Extract one shared helper used by both the migration and the sync, matching case-insensitively with
whitespace trimmed: `moncton` → `M`, `sussex` → `S`, `veterans` → `null`.

`delivery_area_zone` scopes the M and S legacy SDNB drafts **and** selects rural-versus-urban rate.
A change moves a client to a different invoice and price, so every write that **changes** the value
writes an audit entry and a `sync.derived_zone_changed` event with old and new values.

**This helper and the migration fix ship first, independently of the rest of K21** — the bug recurs
on the next rebuild regardless of the sync work.

> **APPLIED (2026-11-03):** `MealsDB_Operational_Constants::SERVICE_CENTRE_ZONE_MAP` +
> `zone_for_service_centre()` / `is_known_service_centre()`; phase 1 of
> `class-migration-consolidated.php` uses it. Test: `tests/test-service-centre-zone-k21.php`
> (covers Test 17). Fixed in passing: phase 1 read an undefined `$group` (left behind by
> Directive 7), so `sdnb rural` users never got `service_name_zone = 'rural'` and Extra Mural
> users never got their migration note. The sync side of ITEM 6 is still to do.

### `delivery_day` ← `delivery_area_name`

Compute through `MealsDB_Zone_Day::day_for_zone()`. That lookup is an exact match on the trimmed
area name and returns `null` for an unknown area; per the rule above, `null` leaves `delivery_day`
unchanged and logs. If a zone moves from Zone 1 to Zone 3 and the day is not recomputed, the client
lands on the wrong day's slip. Same audit and event requirement as above.

`delivery_day` itself feeds `next_order_date` / `next_delivery_date`. Recomputing those is **out of
scope**; `MealsDB_Derived_Value_Check` / the nightly derived-value audit already flags that drift.

---

## ITEM 7 — Nightly reconcile

Extend `run_nightly_sync()` to apply the rule in both directions for every two-way field, and the
one-way rule for ITEM 5:

1. Read every value (WP + each active linked client); apply the comparator's blank test and
   equality.
2. **All non-blank values equal** → fill every blank side from that value: MealsDB blanks via
   `push_to_meals_db()`, WP blank via `push_to_woocommerce()`. If an open conflict exists for this
   account and field, close it `auto_resolved`.
3. **Every side blank** → no-op.
4. **Any two non-blank values differ** → upsert a conflict (ITEM 8). Write nothing for this field
   on this account.
5. After any write to a field with dependants, apply ITEM 6.

Preserve every K13 guard:

- `metadata_exists()` still separates absent from empty. WP → MealsDB: an absent key is "no
  opinion". MealsDB → WP: an absent key counts as blank, so the field may be created.
- Empty-over-real refusal applies in **both** directions.
- **The mass-blank pre-flight breaker stays. It is the only breaker.**

**Rev 2 — no mass-conflict breaker and no mass-write breaker.** Conflicts write nothing, and fills
only ever write into blanks, so a large first run is the intended clean-up, not an incident. The
safety guarantee is the no-overwrite invariant (Test 32), which must pass before this ships. Do not
add either breaker back.

**Fill writes one side only (Rev 2).** Today `push_to_meals_db()` writes MealsDB and then mirrors
the value back into WordPress, and `push_to_woocommerce()` writes WordPress and then mirrors into
**every** client linked to that account with no blank check. For K21:

- a fill writes only the blank side;
- the "reconcile all linked clients" write in `push_to_woocommerce()` is removed — it overwrites a
  populated sibling and breaks this directive's core rule;
- the redundant WP mirror after a WP → MealsDB fill is removed (it is a no-op at best and an
  audit-log failure row at worst).

### ITEM 7a — One account, several clients

A WP account may be linked to more than one active client (MAJ-1: rare but legitimate). All of
them must agree on all 26 fields, `client_type` excepted (ITEM 3). Reconcile **per account**:

- Before the loop, group active tracked clients by `wp_user_id`.
- For each field, the values are WP plus every client in the group. The ITEM 7 rule applies to
  the whole set: if every non-blank value agrees, fill every blank (WP and clients alike); if any
  two non-blank values differ, raise one conflict for the account and write nothing.
- A conflict can be between two clients with WordPress agreeing with one of them, or blank. That is
  still one conflict per `(wp_user_id, field)`.

**Fix in passing:** `find_tracked_client_by_wp_user()` uses `LIMIT 1` with no `ORDER BY`, so the
real-time hooks act on an arbitrary client when an account has several. Replace it with a lookup
that returns every active linked client, and route the hooks through the per-account logic
(ITEM 13).

---

## ITEM 8 — Conflict store

New table `meals_sync_conflicts`:

| Column | Notes |
|---|---|
| `conflict_id` | PK |
| `wp_user_id` | the account; **the conflict key** |
| `field` | registry key |
| `wp_value` | raw WordPress value as observed |
| `meals_values` | JSON map `client_id` → raw value, for every active linked client |
| `values_hash` | hash of `wp_value` + sorted `meals_values` (stale-page guard, ITEM 10) |
| `status` | `open` / `resolved` / `dismissed` / `auto_resolved` / `superseded` |
| `first_seen_at`, `last_seen_at` | UTC |
| `resolved_by`, `resolved_at`, `resolution` | `wp` / `client:<id>` / `dismissed` / `auto` / `relinked` |
| `dismissed_pair_hash` | `values_hash` at dismissal |
| `open_dedup` | `"<wp_user_id>|<field>"` while open or dismissed, `NULL` otherwise; UNIQUE |

**One open row per `(wp_user_id, field)`** (Rev 2: keyed by account, not client), enforced with
the `open_dedup` column as in K17's `charge_dedup` — MySQL has no partial unique index.

**Newest observation replaces the old one.** Each run upserts: overwrite `wp_value`,
`meals_values`, `values_hash` and `last_seen_at`; keep `first_seen_at`. There is never more than
one unresolved entry for the same account and field — a misspelt surname seen on three nights, or
across two linked clients, is one row showing the latest values.

A **dismissed** conflict stays dismissed only while its values are unchanged. If `values_hash` on
a later run differs from `dismissed_pair_hash`, reopen it.

**Relinking (Rev 2).** When a client's `wp_user_id` changes, or `on_wp_user_deleted` unlinks it,
mark that account's open and dismissed conflicts `superseded` (resolution `relinked`). The next run
recomputes from the new link.

Integrate with `meals_ignored_conflicts` rather than running two ignore systems — migrate its rules
or have one delegate to the other, and say which in a comment.

Add the table to `MealsDB_Schema` / `MealsDB_Tables`, bump the schema version, and drop it in
`uninstall.php`.

> **APPLIED (2026-11-03) — conflict store + DETECTION ONLY.** `meals_sync_conflicts` (schema +
> `MealsDB_Tables::SYNC_CONFLICTS`; uninstall drops it via `MealsDB_Tables::all()`; the version
> bump is done by CI on merge). `MealsDB_Sync_Conflicts` stores rows; `MealsDB_Sync_Reconciler`
> classifies every registry field per account (`equal` / `blank` / `fill` / `conflict` / `keep` /
> `skip`) and records conflicts. It runs at the start of `run_nightly_sync()`, **before** the
> legacy WP-wins loop, and writes nothing to client data in either system — only conflict rows and
> one `sync.reconcile_detected` event with per-field counts (plus `sync.value_unrecognised` and
> `sync.wp_internal_inconsistency` when they occur). The legacy loop is unchanged, so a conflict on
> one of the 17 k13 fields is overwritten by it and closes `auto_resolved` on the next run — which
> is accurate: the legacy nightly resolved it WordPress-wins. That stops when ITEM 7 lands.
> Decisions made in implementation: `meals_ignored_conflicts` is **delegated to, read-only** (a
> matching legacy rule records the conflict as dismissed; client_phone_1 also matches the old
> `phone_primary` name); a change in the linked client set **supersedes** the row (also on the
> nightly sweep for accounts no longer tracked, and immediately in `on_wp_user_deleted`) rather than
> hooking every `wp_user_id` writer; detection **fails closed** if the staff list or the conflict
> store can't be read. Not yet done: closed-row retention/pruning (closed rows accumulate). Test:
> `tests/test-sync-conflicts-k21.php`.

---

## ITEM 9 — A/B conflict view

Evolve the dashboard comparison into a record comparison.

- **One card per WP account with open conflicts** — WordPress and each active linked client side
  by side, field by field.
- Fields in conflict are highlighted with a **Keep** button per column (Keep WordPress / Keep
  client N). Fields already equal are shown collapsed, so the whole record is visible for context.
- Account-level shortcuts: **Use all WordPress** / **Use all MealsDB** (the latter offered only
  when the account has one active client).
- **Leave both** per field — dismisses (ITEM 8). This is how dual-program `client_type` is handled.
- Filter by field, so all `client_type` conflicts can be reviewed together. Paginate.
- Show **raw** values, not normalised ones.
- Every write goes through `window.MealsDBConfirm` with focus on Cancel (K10 ITEM 4).
- `delivery_initials` / `nickname` never appears (ITEM 5).
- Derived fields are shown read-only, noting that they follow their source.
- Each card carries every conflict's `conflict_id` and `values_hash` as rendered (ITEM 10).

WordPress-only and MealsDB-only records keep their existing treatment.

---

## ITEM 10 — Resolution writes

Choosing a value writes it to every other side in the account, records the resolution, and closes
the conflict. All sides then match, so it cannot recur.

Resolution is the **only** path permitted to overwrite a populated value. It must:

- respect ITEM 3's lossy-direction rule,
- never write `delivery_initials`,
- recompute derived fields where the resolved field is a source (ITEM 6 — resolution may overwrite
  a populated derived value),
- set `MealsDB_Sync::$syncing` around the write so `profile_update` /
  `woocommerce_customer_save_address` cannot re-enter the sync,
- write an audit entry per field: old value, new value, direction, user.

**Batching (Rev 2).** Resolution is **per account in one request**: the request carries a list of
`{conflict_id, values_hash, keep}` choices and applies them all. One request costs one
`sync_operations` rate-limit token (100/hour, shared with ignore toggles), so working through a
large queue does not 429. Keep the existing nonce, capability and rate-limit layers.

**Stale-page protection (Rev 2).** The endpoint never accepts a value from the browser. It takes
`conflict_id` + the chosen side (`wp` or `client:<id>`) + the `values_hash` the page rendered. On
the server it re-reads the current values from both stores, recomputes the hash, and **refuses that
conflict** ("changed since you loaded this page — reload") if the hash differs. It then writes the
value it just read from the chosen side. Other conflicts in the same batch still apply; the response
reports each one.

---

## ITEM 11 — WordPress-side write safety

`client_email` writes `user_email` via `wp_update_user()`:

- **WordPress emails the old address on `user_email` change.** Suppress it for sync and resolution
  writes (`send_email_change_email` returning false, scoped to the write). Many addresses are
  `noemail###@` placeholders; others are real clients who must not receive unexplained system mail.
- **`user_email` is unique.** A collision returns `WP_Error`. Record a failed write with the reason,
  leave the conflict open, continue. Never abort the run.
- **`user_email` is also a login identifier** (WooCommerce accepts email login) and the
  password-reset destination. The resolution confirm dialog for `client_email` → WordPress must say
  so.

---

## ITEM 12 — Shadow mode

`MealsDB_Shadow_Mode::is_enabled()` suppresses all WP writes. It is currently **off** in production,
so this item is a safeguard rather than live behaviour. When on:

- still detect and count every WP write that *would* occur,
- log one `sync.shadow_suppressed` event per run with counts per field,
- show a persistent notice in the conflict view and disable every resolution choice that writes to
  WordPress rather than letting it appear to succeed.

Do not change shadow mode's default or setting.

---

## ITEM 13 — Real-time hooks follow the same rules

`on_wp_user_updated`, `on_wc_address_saved` and `on_wc_customer_created` currently push WP →
MealsDB. Route them through the same registry and per-account logic (ITEM 7a): fill a blank, no-op
when equal, queue a conflict when non-blank values differ, apply ITEM 6. A WordPress profile edit
must not overwrite a populated MealsDB value any more than the nightly may — and must never write
`delivery_initials`. The hooks act on **every** active client linked to the account, not one
arbitrary row, and skip staff accounts.

---

## Out of scope

- Every excluded field listed above.
- Creating MealsDB clients from WordPress-only users, or the reverse.
- Changing shadow mode's default or setting.
- Bulk-resolving existing differences (beyond per-account batching in ITEM 10).
- Any special handling of dual-program `client_type` beyond dismissal.
- Recomputing `next_order_date` / `next_delivery_date` when `delivery_day` changes.
- Parsing multi-number phone strings into alternate-contact fields.
- Splitting apartment numbers out of `address_1`.

---

## Tests

1. Equal after normalisation (`(506) 555-0100` vs `5065550100`) → no write, no conflict.
2. MealsDB blank, WP populated → MealsDB filled.
3. WP blank, MealsDB populated → WP filled. **Fails against v1.0.591.**
4. Both populated, different → one open conflict, nothing written. **Fails against v1.0.591.**
5. Second nightly run does not duplicate the conflict; `last_seen_at` updates.
6. Values converge independently → conflict closes `auto_resolved`.
7. `shipping_phone = '0'` with a real `client_phone_2` → WP filled, no conflict.
8. `sdnb rural`, `Extra Mural`, `extramural` vs `SDNB` → equal.
9. Blank `customer_group`, MealsDB `Veteran` → WP gets `veterans`.
10. `private` vs `SDNB` → conflict.
11. Resolving `client_type` to MealsDB never writes `sdnb` over `sdnb rural`.
12. `delivery_area_name` read from `shipping_address_2`; filling writes **both** address_2 keys.
13. WP `billing_address_2` ≠ `shipping_address_2` → compared on shipping only, logged, no conflict.
14. `nickname = 'Betty Ann'`, `delivery_initials = 'BAE'` → nickname becomes `BAE`.
15. `nickname = 'MTR'`, `delivery_initials = 'RKU'` → **no write, no conflict.**
16. **No path ever writes `delivery_initials`** — sync, hooks, resolution. Assert across all.
    (Regression guard: this already holds on v1.0.591 and must keep holding.)
17. `service_center_charged = 'moncton'` → `delivery_area_zone = 'M'`. **Fails against v1.0.591.**
18. A zone write that changes the value writes an audit entry and `sync.derived_zone_changed`.
19. `delivery_area_name` filled (derived blank) → `delivery_day` filled.
20. Unparseable `commence_date` vs a populated MealsDB date → conflict, not overwritten.
21. Dismissed conflict stays dismissed while values unchanged; reopens on change.
22. *(Removed in Rev 2 — mass-conflict breaker no longer exists.)*
23. *(Removed in Rev 2 — mass-write breaker no longer exists.)*
24. `user_email` collision → failed write recorded, run continues, conflict stays open.
25. No email-change notification on sync or resolution writes.
26. Shadow mode on → no WP writes, `sync.shadow_suppressed` logged, WP-writing resolution choices
    disabled.
27. Real-time `profile_update` with both populated and different → conflict, no overwrite.
28. Resolution write does not re-enter the sync.
29. Encrypted and excluded fields are never read or written by any sync path.
30. Staff accounts, including `wp_user_id` 1, are never synced — nightly and hooks.
31. Unrecognised `customer_group` → not blank (conflict, never overwritten), logged with the value.

Added in Rev 2:

32. **No-overwrite invariant.** Snapshot every registry column on both sides for a fixture set
    covering every comparator, then run the nightly, every real-time hook and every fill path.
    Assert that no value which was non-blank before is different after, on either side — the sole
    permitted exception being `nickname` per ITEM 5. **This test is the release gate that replaces
    the runtime breakers.**
33. Account with two active clients, all non-blank values agree, one client blank → that client
    and WP (if blank) filled.
34. Account with two active clients whose non-blank values differ → exactly one open conflict for
    `(wp_user_id, field)`; nothing written to either client or WP.
35. `push_to_woocommerce()` never writes a populated sibling client.
36. Real-time hooks act on every active linked client, not one arbitrary row.
37. Inactive clients are never read, compared or written, and are not counted as linked clients.
38. Conflict upsert replaces values: three runs with changing values, and two linked clients,
    leave one row per `(wp_user_id, field)` holding the newest values.
39. Relinking a client or deleting its WP user marks that account's conflicts `superseded`.
40. Automated fill of `service_center_charged` with a populated, different `delivery_area_zone`
    → zone not written, `sync.derived_mismatch` logged. The same choice via resolution → zone
    rewritten and audited.
41. `day_for_zone()` returns null → `delivery_day` unchanged, logged.
42. Unparseable `commence_date`, MealsDB blank → conflict; the DATE column is not filled.
43. Non-integer `delivery_frequency` in WP → conflict; the INT column is not filled.
44. Batch resolution applies several conflicts in one request and consumes one rate-limit token.
45. Stale page: a value changes between render and submit → that conflict is refused, the others in
    the batch apply, nothing is written for the refused one.
46. The resolution endpoint ignores any value supplied by the browser and writes the server-read
    value of the chosen side.

No test may hit the network or require a live cron.

---

## Acceptance criteria

- No automated path overwrites a populated **synced or derived** value in either direction. The
  only automated overwrite is `nickname` per ITEM 5. Test 32 passes.
- `delivery_initials` is never written by any sync path.
- A single registry defines every synced field; no mapping exists outside it (all five listed in
  ITEM 1 absorbed).
- Comparison is normalised per comparator; phone blankness uses K20's rule; unparseable values
  never fill.
- `client_type` compares by class through the extended Directive 7 mapping and never downgrades a
  more specific Enzebra value.
- Accounts with several active clients are reconciled as one set; no sibling is ever overwritten by
  an automated path.
- Derived fields are filled only when blank on automated paths; every change is audited.
- The zone lookup is case-insensitive in both the migration and the sync, and ships first.
- Conflicts persist, keep one row per `(wp_user_id, field)` with the newest values, auto-close on
  convergence, honour dismissal until values change, and are superseded on relink.
- Resolution is batched per account and refuses stale submissions.
- Only K13's mass-blank breaker remains.
- Shadow mode is reported, never silently obeyed.
- Tests 3, 4 and 17 each fail against v1.0.591.
