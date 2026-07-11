# Zone schedule as sole source of truth for client delivery days — design

**Date:** 2026-07-11
**Status:** Approved
**Related:** the delivery-date system analysis in this feature's conversation; `MealsDB_Date_Calculator` header docs; directive ITEM1-DERIVED (drift checker)

## Problem

A client's `delivery_day` is written by multiple paths that disagree on
vocabulary: the client form validates and stores `'WED AM'`-style values,
while the zone backfills, the slip generator's SQL (`LOWER(delivery_day) =
'wednesday'`), the occurrence mapper, and `MealsDB_Date_Calculator` all use
full English day names. A client whose day was set through the form gets no
delivery-day snap on next dates, **silently falls off every packer/driver
slip**, and their orders map to no delivery occurrence. Additionally, the
client→zone link (`delivery_area_name`) is a free-text field that must
exactly match a zone-schedule key for any zone-derived logic (backfill, drift
check) to apply — a typo or zone rename silently orphans clients.

## Decision

The zone delivery schedule (`mealsdb_zone_delivery_schedule`, edited on the
Settings page) becomes the **sole source of truth** for client delivery days.
Clients point at a zone via a constrained dropdown; `delivery_day` becomes a
zone-derived, read-only, synced cache column (lowercase full day names).
Next delivery remains `last_delivery_date + delivery_frequency weeks, snapped
to the zone's day` via the existing calculator — unchanged.

## Changes

### A. Zone schedule editor (Settings)

`views/settings.php` + `includes/ajax/class-ajax-settings.php`

- The existing per-zone day `<select>` is trimmed to **Monday–Friday** (UI
  list and the server-side `$valid_days` whitelist both).
- On settings save, if a zone's day changed, **propagate immediately**: one
  `UPDATE` per changed zone setting `delivery_day = <lowercase day>` for all
  active clients with that `delivery_area_name`. Audit-log the propagation
  (zone, old day, new day, rows affected) via `MealsDB_Logger::log()`.
- If the submitted schedule drops a zone that active clients still reference,
  save proceeds but records a **degraded** `MealsDB_Event_Log` event naming
  the zone and client count (they become orphans; see §D).

### B. Client form

`includes/class-admin-ui.php` (form renderer), `includes/class-client-form.php`
(validation/save)

- **Delivery Area Name** becomes a `<select>` populated from
  `array_keys(mealsdb_zone_delivery_schedule)`. Required exactly as today
  (same client types). Existing stored values not in the schedule render as a
  selected-but-flagged option (`⚠ not in schedule`) so editing an orphaned
  client forces a real choice without corrupting an untouched record.
- **Delivery Day** becomes a read-only display (not an input): shows the
  zone-derived day plus the zone's route label (e.g. "Wednesday — Wednesday
  morning - Moncton Downtown"), updated client-side when the zone selection
  changes (small addition to the form JS; zone→day+label map delivered via a
  JSON island, the plugin's established pattern — no AJAX round-trip).
- On save, the server **ignores any posted `delivery_day`** and derives it
  from the selected zone via the shared service (§C). Stored format:
  **lowercase full day name** (`'wednesday'`), which the slip SQL and
  calculator already expect.
- Deleted: the `'WED AM'` allowed-options list, the
  `mealsdb_allowed_delivery_days` filter, and the `'delivery_day' => 'upper'`
  entry in the form's case-format map. The validation rule for
  `delivery_day` is removed from `validate()` (the field is no longer
  user-supplied); the zone select is validated against the schedule keys.

### C. One derivation service + drift enforcement

- New `includes/services/class-zone-day.php` — `MealsDB_Zone_Day::day_for_zone(
  ?string $area_name): ?string`. Reads the schedule option, returns the
  lowercase full day name, or null when the schedule is missing, the area is
  blank, the area isn't a schedule key, or the zone has no day. Pure lookup;
  no writes.
- All four delivery-day writers/deriving readers route through it:
  1. Client form save (§B),
  2. Schedule-save propagation (§A),
  3. The resync backfill (§D),
  4. `MealsDB_Derived_Value_Check::expected_delivery_day()` (replacing its
     inline schedule lookup — behavior identical, one implementation).
- The Settings **auto-correct** toggle for `delivery_day` defaults to **ON**
  for new installs, and the "(not recommended — zone overrides are
  legitimate)" caption is replaced with wording that overrides are no longer
  supported and drift is rewritten nightly (audit-logged, as today). Existing
  installs keep their stored toggle value; the resync flow (§D) prompts the
  operator to enable it.

### D. One-time resync + orphan report

`includes/ajax/class-ajax-settings.php` (new endpoint) + `views/settings.php`
(button) — modeled on the existing `mealsdb_backfill_delivery_days` endpoint,
which it **replaces** (that endpoint only filled blanks; this one overwrites).

- Button: "Resync delivery days from zones". Guard spine: nonce
  (`mealsdb_settings_nonce`), `manage_options`, `migration_destructive`
  bucket. No typed confirmation (the data is re-derivable).
- Action: for every active client, set `delivery_day` from
  `MealsDB_Zone_Day::day_for_zone(delivery_area_name)`. Response reports:
  clients updated, clients already correct, and **orphans** — active clients
  whose zone resolves to null — listed with client id + name + the
  unresolvable `delivery_area_name` value.
- Orphans are also recorded as one degraded `MealsDB_Event_Log` event
  (category `sync`, event `delivery_day.orphaned_clients`, context carries
  the count and ids) so they surface on the Event Log dashboard and daily
  digest until fixed by choosing a real zone on each client's form.

## What deliberately does NOT change

- `MealsDB_Date_Calculator`, `MealsDB_Client_Dates` (mark_delivered /
  advance_on_order), the `client_delivery` task, and the slip occurrence
  mapper — all keep reading the `delivery_day` column exactly as today.
- The Quick Order `next_delivery_date` operator override stays (one-off
  schedule exceptions); the nightly drift check keeps such overrides visible.
- `delivery_area_zone` and `service_zone` fields (different concerns: rate
  rurality and service area) are untouched.
- The B1 occurrence-mapping limitation (off-week orders for non-weekly
  clients) is out of scope.

## Edge cases

- **Client in a zone with no schedule entry:** impossible for new saves (the
  dropdown only offers schedule keys); legacy rows are surfaced by the resync
  orphan report and the nightly drift check.
- **Zone renamed:** the settings editor has no rename affordance (rows are
  keyed by name); a "rename" is a remove+add, which triggers the §A dropped-
  zone degraded event for the old name's clients.
- **Schedule option empty/corrupt:** `day_for_zone` returns null; form save
  refuses to derive (validation error on the zone field rather than writing
  a blank day); propagation and resync skip with a degraded event.
- **Saturday/Sunday legacy values:** no current zone uses them; the trimmed
  whitelist prevents new ones. A stored legacy weekend day on a client is
  corrected by the resync/nightly check like any other drift.

## Testing

- New: `tests/test-zone-day.php` — lookup happy path, blank/missing/unknown
  zone, missing day, corrupt option shape.
- New: form-save derivation — posted `delivery_day` ignored; derived value
  stored lowercase; save rejected when the zone is unresolvable.
- New: schedule-save propagation — day change updates active clients in that
  zone only; dropped zone records degraded event.
- New: resync — overwrites wrong values, counts already-correct, reports
  orphans, emits the degraded event.
- Existing tests (calculator, slip occurrence, derived-value-check, client
  form) must stay green; the derived-value-check test gains an assertion that
  it routes through `MealsDB_Zone_Day`.

## Rollout order (operational)

1. Deploy the code.
2. Run "Resync delivery days from zones"; fix reported orphans by editing
   each client's zone.
3. Enable the `delivery_day` auto-correct toggle.
4. Spot-check the next slip run (Wednesday/Thursday/Friday zones) against the
   previous week's slips.
