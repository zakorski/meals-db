# DIRECTIVE K21 — Bidirectional fill-blank sync with A/B conflict resolution

**Targets:** `includes/class-sync.php`, `includes/services/sync/class-sync-compare.php`,
`includes/services/sync/class-sync-mutate.php`, `includes/ajax/class-ajax-sync.php`,
`includes/services/class-migration-consolidated.php`, `views/dashboard.php`, schema/tables
**Severity:** HIGH — writes to both data stores
**Depends on:** v1.0.591 (K20)

---

## Goal

Every linked client/WP-user pair is reconciled field by field. Each field lands in exactly one of
three outcomes:

| State | Action |
|---|---|
| Equal (after normalisation) | nothing |
| One side blank, other populated | **copy from the populated side** |
| Both populated, different | **queue a conflict, write nothing** |

**No automated path ever overwrites a populated value.** Conflicts are resolved by an operator in a
side-by-side view, choosing per field which record to keep — the way Ancestry compares two records
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
- `class-ajax-sync.php` resolve endpoint — already pushes a chosen value either direction.
- `MealsDB_Sync::$syncing` — re-entrancy guard against hook loops.
- `MealsDB_Shadow_Mode::is_enabled()` — suppresses WP write-back; defaults ON when unset.
- `MealsDB_Phone::is_same_number()` / `has_number()` (K20).
- `MealsDB_Zone_Day` (`class-zone-day.php`) — zone → delivery day.

**Defect to fix in passing:** `compare_fields()` reads `$client['phone_primary']`, but the column
is `client_phone_1`. ITEM 1 replaces this comparison.

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
| `delivery_frequency` | `delivery_frequency` | text |
| `ordering_frequency` | `ordering_frequency` | text |
| `service_id` | `service_id` | text |
| `service_commence_date` | `commence_date` | date |
| `expected_termination_date` | `service_termination_date` | date |

### One-way, MealsDB → WordPress — 1 field

`delivery_initials` → `nickname`. See ITEM 5.

### Derived — recomputed when their source changes

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
- **Staff accounts** — every `staff_wp_ids` user and any user with administrator capability. In
  particular `wp_user_id` 1 is the site `admin` account and is linked to test client 1; it must not
  be synced.

Every other MealsDB column remains out of scope.

---

## ITEM 1 — One field registry

Replace the parallel maps with a single registry — the only source of truth for the sync, the
comparison and the conflict view. Each entry declares: MealsDB column, WP source (core field, meta
key, or several), direction (`two_way` / `to_wp_only`), comparator, blank test, and any derived
fields that depend on it.

`get_field_to_wp_meta_map()` and `get_wp_authoritative_fields()` may remain as thin accessors over
the registry for existing callers. **No field mapping may be defined outside it.** Two maps
disagreeing is how K13 happened.

---

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
| date | parsed `Y-m-d` equal | trimmed empty |
| client-type class | ITEM 3 class equal | blank or unrecognised |

**Phone:** K20's rule — under 7 digits is not a phone. The 157 `shipping_phone = '0'` placeholders
read as blank, so a real MealsDB number fills them instead of each becoming a permanent conflict.

**Date:** both systems store ISO `YYYY-MM-DD` (633 `commence_date`, 568 `service_termination_date`
verified). An **unparseable** value is *not* blank — compare it raw, so it surfaces as a conflict
rather than being silently overwritten. Known case: `commence_date = '202409-02-03'`.

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

**Extra Mural is provisionally SDNB** (operator decision 2026-11-02, pending confirmation). Hold the
classification in one named constant so it changes in one place.

**The mapping is lossy toward MealsDB**, so:

- `sdnb rural` vs `SDNB` is **equal**, never a conflict.
- Filling a blank `customer_group` from MealsDB writes the canonical value: `sdnb`, `veterans`,
  `private`.
- **No path may replace a more specific Enzebra value with a less specific one.** A resolution
  choosing MealsDB for this field writes only when the classes differ, and then the canonical value
  for MealsDB's class.

An unrecognised value is treated as blank **and** logged once per run with the value, so a new
Enzebra group surfaces rather than silently defaulting.

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

---

## ITEM 5 — `delivery_initials` → `nickname` (one-way)

**MealsDB's `delivery_initials` is an immutable source of truth.** No sync, reconcile, real-time
hook or resolution may ever write `delivery_initials`.

Push to WordPress `nickname` only when:

- MealsDB `delivery_initials` is non-empty, **and**
- `CHAR_LENGTH(TRIM(nickname)) <> 3`.

WordPress sets `nickname` to the username on account creation, so it is populated on all 1,031
linked client users — a plain blank test would never write. Anything not exactly 3 characters
(`Betty Ann`, `admin`, `anne.potter`) is treated as blank and overwritten.

**An existing 3-character nickname is never touched, even when it differs from MealsDB.** Measured
2026-11-02: 13 would be overwritten; 615 are 3-character codes that differ and are left alone by
design. These do **not** appear in the conflict view.

---

## ITEM 6 — Derived fields

Neither has a WordPress twin. Each is recomputed **only when its source changes** — through the
sync, a real-time hook, or a resolution — and otherwise left alone.

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
A change moves a client to a different invoice and price, so every recompute that **changes** the
value writes an audit entry and a `sync.derived_zone_changed` event with old and new values.

### `delivery_day` ← `delivery_area_name`

Recompute through `MealsDB_Zone_Day` when `delivery_area_name` changes. If a zone moves from Zone 1
to Zone 3 and the day is not recomputed, the client lands on the wrong day's slip. Same audit and
event requirement as above.

---

## ITEM 7 — Nightly reconcile

Extend `run_nightly_sync()` to apply the three-state rule in both directions for every two-way
field, and the one-way rule for ITEM 5:

1. Read both values; apply the comparator's blank test and equality.
2. **Equal** → no-op. If an open conflict exists for this pair and field, close it `auto_resolved`.
3. **MealsDB blank, WP populated** → write WP → MealsDB via `push_to_meals_db()`.
4. **WP blank, MealsDB populated** → write MealsDB → WP via `push_to_woocommerce()`.
5. **Both populated, different** → upsert a conflict (ITEM 8). Write nothing.
6. After any write to a field with dependants, recompute them (ITEM 6).

Preserve every K13 guard:

- `metadata_exists()` still separates absent from empty. WP → MealsDB: an absent key is "no
  opinion". MealsDB → WP: an absent key counts as blank, so the field may be created.
- Empty-over-real refusal applies in **both** directions.
- The mass-blank pre-flight breaker stays.
- **Add a mass-conflict breaker:** if a single field would produce conflicts for more than 20% of
  compared pairs, skip that field for the run and log `sync.mass_conflict_refused` at `error`. A
  rate that high almost certainly means a comparator bug.
- **Add a mass-write breaker per direction:** if a single run would write more than 20% of pairs for
  one field, refuse that field and log. A bulk backfill is a deliberate operator action, not
  something the nightly should discover.

Thresholds are named constants with the reasoning in a comment.

---

## ITEM 8 — Conflict store

New table `meals_sync_conflicts`:

| Column | Notes |
|---|---|
| `conflict_id` | PK |
| `client_id`, `wp_user_id` | |
| `field` | registry key |
| `meals_value`, `wp_value` | raw values as observed |
| `status` | `open` / `resolved` / `dismissed` / `auto_resolved` |
| `first_seen_at`, `last_seen_at` | |
| `resolved_by`, `resolved_at`, `resolution` | `meals` / `wp` / `dismissed` / `auto` |
| `dismissed_pair_hash` | see below |

One open row per `(client_id, field)`, enforced with a dedup column as in K17's `charge_dedup` —
MySQL has no partial unique index. Upsert nightly: refresh `last_seen_at` and both values; never
duplicate.

A **dismissed** conflict stays dismissed only while both values are unchanged. Store a hash of the
value pair on dismissal; if either value changes, reopen.

Integrate with `meals_ignored_conflicts` rather than running two ignore systems — migrate its rules
or have one delegate to the other, and say which in a comment.

---

## ITEM 9 — A/B conflict view

Evolve the dashboard comparison into a record comparison.

- **One card per client with open conflicts** — MealsDB and WordPress side by side, field by field.
- Fields in conflict are highlighted with **Keep MealsDB** / **Keep WordPress**. Fields already equal
  are shown collapsed, so the whole record is visible for context.
- Client-level shortcuts: **Use all MealsDB** / **Use all WordPress**.
- **Leave both** per field — dismisses (ITEM 8).
- Filter by field, so all `client_type` conflicts can be reviewed together.
- Show **raw** values, not normalised ones.
- Every write goes through `window.MealsDBConfirm` with focus on Cancel (K10 ITEM 4).
- `delivery_initials` / `nickname` never appears (ITEM 5).
- Derived fields are shown read-only, noting that they follow their source.

WordPress-only and MealsDB-only records keep their existing treatment.

---

## ITEM 10 — Resolution writes

Choosing a value writes it to the other side through the existing resolve endpoint, records the
resolution, and closes the conflict. Both sides then match, so it cannot recur.

Resolution is the **only** path permitted to overwrite a populated value. It must:

- respect ITEM 3's lossy-direction rule,
- never write `delivery_initials`,
- recompute derived fields where the resolved field is a source (ITEM 6),
- set `MealsDB_Sync::$syncing` around the write so `profile_update` /
  `woocommerce_customer_save_address` cannot re-enter the sync,
- write an audit entry per field: old value, new value, direction, user.

---

## ITEM 11 — WordPress-side write safety

`client_email` writes `user_email` via `wp_update_user()`:

- **WordPress emails the old address on `user_email` change.** Suppress it for sync and resolution
  writes (`send_email_change_email` returning false, scoped to the write). Many addresses are
  `noemail###@` placeholders; others are real clients who must not receive unexplained system mail.
- **`user_email` is unique.** A collision returns `WP_Error`. Record a failed write with the reason,
  leave the conflict open, continue. Never abort the run.

---

## ITEM 12 — Shadow mode

`MealsDB_Shadow_Mode::is_enabled()` suppresses all WP writes. When on:

- still detect and count every WP write that *would* occur,
- log one `sync.shadow_suppressed` event per run with counts per field,
- show a persistent notice in the conflict view and disable **Keep MealsDB** / **Use all MealsDB**
  rather than letting them appear to succeed.

Do not change shadow mode's default or setting.

---

## ITEM 13 — Real-time hooks follow the same rules

`on_wp_user_updated`, `on_wc_address_saved` and `on_wc_customer_created` currently push WP →
MealsDB. Route them through the same registry and three-state logic: fill a blank, no-op when equal,
queue a conflict when both populated and different, recompute dependants. A WordPress profile edit
must not overwrite a populated MealsDB value any more than the nightly may — and must never write
`delivery_initials`.

---

## Out of scope

- Every excluded field listed above.
- Creating MealsDB clients from WordPress-only users, or the reverse.
- Changing shadow mode's default or setting.
- Bulk-resolving existing differences.
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
17. `service_center_charged = 'moncton'` → `delivery_area_zone = 'M'`. **Fails against v1.0.591.**
18. A zone recompute that changes the value writes an audit entry and `sync.derived_zone_changed`.
19. `delivery_area_name` change recomputes `delivery_day`.
20. Unparseable `commence_date` → conflict, not overwritten.
21. Dismissed conflict stays dismissed while values unchanged; reopens on change.
22. Mass-conflict breaker: >20% conflicts on one field → field skipped, logged.
23. Mass-write breaker: >20% writes on one field → field refused, logged.
24. `user_email` collision → failed write recorded, run continues, conflict stays open.
25. No email-change notification on sync or resolution writes.
26. Shadow mode on → no WP writes, `sync.shadow_suppressed` logged, MealsDB actions disabled.
27. Real-time `profile_update` with both populated and different → conflict, no overwrite.
28. Resolution write does not re-enter the sync.
29. Encrypted and excluded fields are never read or written by any sync path.
30. Staff accounts, including `wp_user_id` 1, are never synced.
31. Unrecognised `customer_group` → treated as blank, logged with the value.

No test may hit the network or require a live cron.

---

## Acceptance criteria

- No automated path overwrites a populated value in either direction.
- `delivery_initials` is never written by any sync path.
- A single registry defines every synced field; no mapping exists outside it.
- Comparison is normalised per comparator; phone blankness uses K20's rule.
- `client_type` compares by class and never downgrades a more specific Enzebra value.
- Derived fields recompute only when their source changes, and every change is audited.
- The zone lookup is case-insensitive in both the migration and the sync.
- Conflicts persist, dedupe, auto-close on convergence, and honour dismissal until values change.
- Shadow mode is reported, never silently obeyed.
- Tests 3, 4, 16 and 17 each fail against v1.0.591.
