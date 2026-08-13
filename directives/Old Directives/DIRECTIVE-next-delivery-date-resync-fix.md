# Directive — Recompute next_delivery_date on zone-day resync (fix QO date drift, 12C)

## Problem (root cause confirmed in code + GUI test v542 12C)
A client can show a next-delivery date that does not match their zone's delivery day. GUI test 12C:
Marjorie Acker (Zone 1 = Wednesday) shows "Next delivery: 2026-08-21" — a FRIDAY — in the Quick Order
summary. The stored `next_delivery_date` has drifted away from the client's actual delivery day.

Root cause: `MealsDB_Zone_Day::resync_all()` (and the settings-save `propagate_schedule_change()`) updates
each client's **`delivery_day`** when the zone schedule changes, but does NOT recompute their
**`next_delivery_date`**. So after any zone-day change or resync:
- `delivery_day` is corrected (e.g. to wednesday), but
- the STORED `next_delivery_date` still points at the OLD day (a Friday),
and code paths that read the stored column (the QO summary / allocation path) surface the stale Friday.

This is the same "stored value goes stale, different paths disagree" family as the original zone-day fix —
the resync closed the `delivery_day` half but left the `next_delivery_date` half drifting.

(Separately, the tester also found `get_next_dates` returned `has_client:false` for a manual call; that is
the WP-user-id vs client-row-id calling contract, not the drift bug, and is addressed by the QO
prefill/get_next_dates work — NOT this directive. This directive fixes the stored-date drift, which is the
concrete 12C.1 defect: a Friday shown for a Wednesday client.)

## Goal
When a client's `delivery_day` is (re)synced from the zone schedule, ALSO recompute their
`next_delivery_date` from that day, so the stored delivery date can never disagree with the zone. Use the
canonical `MealsDB_Date_Calculator` — do not reimplement date math.

## Reference (v1.0.542)
- `includes/services/class-zone-day.php`:
  - `resync_all()` (~203-260): does `UPDATE {clients} SET delivery_day = %s WHERE zone matches AND
    delivery_day <> %s` per zone. Only `delivery_day` is written.
  - `propagate_schedule_change()` (~121): same idea on a settings-save (per-zone day change).
- `MealsDB_Date_Calculator::next_date($anchor_ymd, $frequency, $delivery_day)` — canonical next-occurrence
  (snaps to the given day). Same method used by `persist_next_dates()` and the rule-default path.
- Clients carry `delivery_frequency` (and an anchor: last delivery / service-commence / next_order_date)
  used elsewhere to compute occurrences.

## Change
In `resync_all()` and `propagate_schedule_change()`, for every client whose `delivery_day` actually
changes, recompute and persist `next_delivery_date` in the SAME update path:
1. After determining a client's new `delivery_day`, compute
   `$next = MealsDB_Date_Calculator::next_date($anchor_ymd, $delivery_frequency, $new_delivery_day);`
   using the same anchor the rest of the system uses (the existing next_order_date / last-delivery anchor;
   match whatever `persist_next_dates()` uses so results are consistent).
2. Write BOTH columns: `SET delivery_day = %s, next_delivery_date = %s` for the affected rows.
   - The per-zone bulk UPDATE currently sets only `delivery_day`. Because `next_delivery_date` depends on
     each client's own frequency/anchor, the recompute is per-client — either iterate the affected clients
     and update each, or compute in PHP and update per row. Prefer correctness over a single bulk UPDATE.
3. If a client's frequency/anchor is missing so a date can't be computed, leave `next_delivery_date`
   as-is (do not null a value you can't replace) and count it as skipped — do not error the whole resync.
4. Keep the existing response contract (`N updated, M already correct, K orphans`); optionally add a
   `dates_recomputed` count for visibility (mirrors the Settings messaging directive, if present).

## Do NOT change
- The `delivery_day` resolution / orphan detection (works).
- `MealsDB_Date_Calculator` math.
- The one-time delivery-date OVERRIDE (`_delivery_date` order meta) — unrelated; a per-order override must
  still win at slip time regardless of the client's recomputed cadence date.

## Verify
```
php -l includes/services/class-zone-day.php
php tests/test-zone-day.php tests/test-client-dates.php
```
- Set a client's zone to a Wednesday zone, run **Resync Delivery Days** → the client's `delivery_day`
  becomes wednesday AND `next_delivery_date` becomes the next Wednesday occurrence (NOT a Friday).
- The QO summary and any stored-column reader now show a delivery date whose weekday matches the zone day.
- Change a zone's day in Settings (propagate) → affected clients' next_delivery_date recompute to the new
  day, not just delivery_day.
- A client with no frequency/anchor is skipped (next_delivery_date untouched), resync still succeeds.
- Re-run 12C probe: the summary next-delivery weekday matches the client's zone day.

## Test to add
Extend `test-zone-day.php`: after `resync_all()` changes a client's delivery_day, assert their stored
`next_delivery_date` falls on the new delivery weekday (not the old one), computed via Date_Calculator.

## Operator note
After this ships, run **Resync Delivery Days** once on staging/live to backfill every client whose
`next_delivery_date` already drifted (like Marjorie Acker). The resync becomes the remediation for existing
stale dates, not just a guard against future drift.
