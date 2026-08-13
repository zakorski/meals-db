# Directive — Fix Quick Order delivery-date prefill + day-mismatch warning (A2 / B7)

## Problem (root cause confirmed in code — ONE cause, two symptoms)
GUI test v517 found two Quick Order defects:
- **A2:** the "Delivery Date (this order)" field (`#mealsdb-qo-delivery-date`) does NOT prefill with the
  client's computed next delivery date — it stays blank.
- **B7:** the day-mismatch soft-warning ("... is a Thursday — this client's deliveries run on Monday")
  never fires in Quick Order, though it DOES fire on the regular WC order-edit screen.

**Both have the same single root cause.** The client-side code is actually PRESENT and correct:
`quick-order.js::deliveryDateWarning(ymd, expectedDay)` fully implements past/weekend/day-mismatch, and the
prefill line sets the field from `next_delivery_date`. But BOTH depend on values that come from the
`get_next_dates` AJAX response:
- prefill uses `resp.data.next_delivery_date`
- the mismatch warning uses `state.clientDeliveryDay`, set from `resp.data.delivery_day`

And `MealsDB_Quick_Order_Ajax::get_next_dates()` populates those two fields by reading the client's
**STORED** columns: `SELECT ... delivery_day, next_delivery_date FROM meals_clients`. When those stored
columns are empty/stale for a client (which happens — e.g. a client whose zone-day was never synced, the
exact case the tester hit), the response carries `delivery_day = ''` and `next_delivery_date = null`, so:
- the field prefills to '' (A2), and
- `clientDeliveryDay` is '' → the warning's `expected` is empty → it can only fall through to the
  weekend check and NEVER does the day-mismatch (B7).

By contrast the order-edit screen works because it derives the day FRESH from the client's zone via
`MealsDB_Delivery_Date_Advisor::expected_day_for_wp_user()` -> `MealsDB_Zone_Day::day_for_zone()`, not from
the stored column. That's the asymmetry to remove.

## Goal
Make `get_next_dates()` return a RELIABLE delivery day and delivery date — derived from the zone schedule
(the source of truth) and the date calculator — rather than depending on possibly-empty stored columns.
Then A2 and B7 both work, because the client-side code they feed is already correct.

## Reference (v1.0.518)
- `MealsDB_Quick_Order_Ajax::get_next_dates()` — reads stored `delivery_day, next_delivery_date` and sends
  them in `wp_send_json_success([... 'delivery_day' => ..., 'next_delivery_date' => ...])`.
- Reliable derivations already used by the order-edit screen / zone-day feature:
  - `MealsDB_Delivery_Date_Advisor::expected_day_for_wp_user(int $wp_user_id): ?string` -> zone-derived
    lowercase day name.
  - `MealsDB_Zone_Day::day_for_zone(?string $area_name): ?string`.
  - `MealsDB_Date_Calculator::next_date(string $last_date, int $frequency, ?string $delivery_day)` and its
    sibling that computes the next occurrence WITHOUT advancing — use the canonical calculator, do not
    reimplement date math.
- Client-side (already correct, do NOT rewrite): `deliveryDateWarning(ymd, expectedDay)` (~1803),
  `refreshDeliveryDateWarning()` (~1830), the prefill + `state.clientDeliveryDay` assignment in
  `fetchNextDates().done()` (~1777-1779).

## Change (server-side, in get_next_dates)

> **Amended 2026-08-04 after code review of the original draft.** Two changes from the first version:
> (a) the delivery-day fallback reuses the row the endpoint ALREADY loaded instead of calling
> `expected_day_for_wp_user()` — the advisor runs its own `WHERE wp_user_id = %d ... LIMIT 1` query,
> which under the known MAJ-1 duplicate-`wp_user_id` case can pick a DIFFERENT client than the
> `get_active_client_id_for_user()` resolution this endpoint just made. Same logic, same row, one query.
> Note the effective precedence is **stored day first, zone fallback** — identical to what
> `expected_day_for_wp_user()` does internally and what the order-edit screen shows. Do NOT "fix" this
> to zone-first: that would make QO and order-edit disagree for a client with a stale stored day.
> (b) the computed next-delivery-date MUST use the delivery-slip occurrence semantics
> (`delivery_occurrence_for_order`: snap to the weekday in the CURRENT week, roll forward a cycle only
> if it already passed), NOT `MealsDB_Date_Calculator::next_date()`. `next_date()` always projects a
> full frequency cycle, so it can never land in the current week — a prefill computed with it would sit
> one week LATER than the slip pipeline's no-override fallback whenever the client's delivery day is
> still upcoming, and since the prefill is written as the `_delivery_date` override, that would actively
> delay real deliveries by a week. Parity with the slip fallback is the correctness bar.

1. **Delivery day:** add `delivery_area_name` to the existing client-row SELECT, then resolve:
   ```php
   $delivery_day = strtolower(trim((string) ($client['delivery_day'] ?? '')));
   if ($delivery_day === '') {
       // Not-yet-resynced row: derive from the zone schedule (source of truth),
       // same fallback expected_day_for_wp_user() uses — but on the row we
       // already resolved, avoiding the advisor's second LIMIT 1 query (MAJ-1).
       $delivery_day = (string) (MealsDB_Zone_Day::day_for_zone($client['delivery_area_name'] ?? null) ?? '');
   }
   ```
   Send this as `delivery_day` in the response (lowercase full day name, matching what
   `deliveryDateWarning`'s `expected` compares against; `null` when still empty — the JS `|| ''` handles it).
2. **Next delivery date:** if the stored `next_delivery_date` is empty, compute it with the SAME
   occurrence mapping the slip pipeline uses as its no-override fallback, so prefill and slip agree by
   construction:
   ```php
   $next_delivery = (string) ($client['next_delivery_date'] ?? '');
   if ($next_delivery === '' && $delivery_day !== '') {
       $next_delivery = (string) (MealsDB_Delivery_Slip_Generator::delivery_occurrence_for_order(
           $order_date_ymd,
           ['delivery_day' => $delivery_day, 'delivery_frequency' => $delivery_freq]
       ) ?? '');
   }
   ```
   Send as `next_delivery_date`. If it genuinely can't be computed (no day at all), send null (the field
   stays blank and the slip pipeline falls back to the computed occurrence — existing behavior, no
   regression). Note `delivery_occurrence_for_order` defaults a missing/zero frequency to 1 (weekly) —
   that is deliberate parity with the slip pipeline, not a bug.
3. Keep `has_client`, `next_order_date`, `rule_default_*` behavior unchanged. Only `delivery_day` and
   `next_delivery_date` gain the reliable-derivation fallback.

## Do NOT change
- The client-side JS (`deliveryDateWarning`, `refreshDeliveryDateWarning`, `fetchNextDates`) — it is
  already correct; it was starved of data, not broken.
- The regular WC order-edit delivery-date field (already works).
- The one-time-override semantics or `_delivery_date` write path.

## Verify
```
php -l includes/class-quick-order-ajax.php
php tests/test-*.php
```
- Select a client WITH synced zone-day: QO delivery-date field prefills the computed next delivery date;
  setting a different weekday shows the day-mismatch warning ("... is a <Day> — this client's deliveries
  run on <ExpectedDay>."). (A2 + B7 fixed.)
- Select a client whose STORED next_delivery_date/delivery_day columns are empty but who HAS a zone: field
  still prefills (computed) and the mismatch warning still fires (zone-derived day) — the exact case that
  failed before.
- Select a client with no zone/cadence at all: field prefills blank, no error, weekend-only warning still
  works — no regression.
- Select a client whose delivery day is LATER THIS WEEK (e.g. on a Monday, client delivers Thursdays,
  stored next_delivery_date blank): the prefill lands on THIS week's Thursday — the same date the slip
  pipeline would compute with no override — not next week's. (Guards against the next_date() mistake
  called out in the amendment note above.)
- Confirm the QO mismatch warning wording now matches the order-edit screen wording (consistency).
- Past-date and weekend warnings still fire (unchanged).

## Test to add
`test-quick-order-next-dates-derivation.php`: assert `get_next_dates()` returns a zone-derived
`delivery_day` (not the empty stored column) for a client whose stored day is blank but whose zone has a
schedule; and returns a computed `next_delivery_date` when the stored one is empty. A pure/handler-level
test that proves the response carries reliable values, which is what both A2 and B7 consume.

Must also assert (per the amendment): the computed `next_delivery_date` uses the slip occurrence
semantics — anchor date before the delivery weekday → SAME week's occurrence; anchor date after it →
next cycle. And: a stored `delivery_day` is preferred over the zone-derived day when both exist.
