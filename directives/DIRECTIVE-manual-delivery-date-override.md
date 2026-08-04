# Directive — Default + operator-overridable delivery date (Quick Order + regular orders)

## Goal
Make delivery dates default to what the system computes, while letting the operator override the date on
a per-order basis for edge cases. Two user-facing pieces:
1. **Quick Order** prefills a delivery-date field with the client's computed `next_delivery_date` (the
   value the scheduler/task trunk already derives). Operator can accept or change it.
2. **A manually-editable delivery-date field** on BOTH Quick Order and the regular WooCommerce order-edit
   admin screen. Whatever the operator sets is written to the order's **`_delivery_date`** meta, which the
   slip generator already treats as the highest-priority delivery date **for the date PRINTED on the slip**.
   > **CORRECTED 2026-08-04:** the original version of this directive claimed the meta write alone
   > "produces the override automatically." That is wrong — `_delivery_date` is honoured only by
   > `resolve_delivery_date()` (the slip HEADER), not by slip SELECTION. Selection is computed-occurrence
   > only (client `delivery_day` weekday match + `delivery_occurrence_for_order()`), so without Section D
   > below, an overridden order stays on its OLD day's slip printing the NEW date, and never appears on
   > the override date's slip at all. Section D (override-aware selection) is REQUIRED, not optional.

### Locked semantics (operator decisions)
- **One-time only:** a manual delivery date changes THIS order's delivery date only. It must NOT
  re-anchor the client's recurring cadence — `next_order_date` / `next_delivery_date` on the client record
  are still computed from the normal frequency/day, exactly as today. (So: do NOT feed the override back
  into `persist_next_dates()` or the client-dates calculator.)
- **Soft-warn, don't block:** if the chosen date is in the past or not a configured delivery day
  (zone schedule is Mon–Fri), show a non-blocking warning but ALLOW the save — edge cases are the point.
- **Applies to both** Quick Order and regular WC orders.

## Risk assessment (re-verified at HEAD, 2026-08-04 — previously "why this is low-risk", v1.0.509)
**Moderate risk, not low.** The UI/write pieces (A, B, C) are genuinely low-risk, but Section D touches
the slip SELECTION logic (`get_orders_for_delivery_range` / `delivery_occurrence_for_order`), which is
the MAJ-6 / GUI-SLIP-RANGE area — itself a recent bug-fix zone. Facts confirmed in code:
- `MealsDB_Slip_PDF_Generator::resolve_delivery_date()` (~line 631) resolution order is already:
  (1) the order's `_delivery_date` meta ["the most authoritative source"], (2) computed
  `delivery_occurrence`, (3) creation date. So `_delivery_date` already wins for the PRINTED date —
  but ONLY the printed date. Selection ignores it (see Section D).
- Repo-wide, `_delivery_date` is read in exactly ONE place (`resolve_delivery_date()`) and never written
  automatically — no nightly/rebuild/sync job writes it. So an operator-set value is durable ("sticky")
  with no extra guarding.
- The B1 limitation comment on `delivery_occurrence_for_order()` (class-delivery-slip-generator.php ~403)
  already anticipated this feature: "True per-client phase would require order-time delivery-date
  capture (directive's B2)" — capture PLUS selection use. This directive is that B2; Section D is the
  selection half.
- `MealsDB_Quick_Order_Ajax::get_next_dates()` (~line, `wp_ajax_mealsdb_qo_get_next_dates`) already
  returns `next_delivery_date` (timezone-correct, from `delivery_frequency` + `delivery_day`) on client
  selection — the prefill source already exists.
- `MealsDB_Date_Calculator::next_date()` / `snap_to_delivery_day()` are the canonical date math (do NOT
  reimplement; reuse for any validation/snap hint).
- QO order is built in `create_order()` via `wc_create_order()` (~line 800).
- A WC order-admin hook is already used: `woocommerce_admin_order_data_after_order_details`
  (`class-admin-ui.php` ~229) — use the same hook for the regular-order field.

## Implementation

### A. Quick Order — prefill + editable field + write on create
1. **UI (views + quick-order.js):** add a "Delivery date" date input to the Quick Order form, near the
   order-date / client area. When a client is selected, the existing `get_next_dates` call already returns
   `next_delivery_date` — populate the field with it as the DEFAULT. If `next_delivery_date` is null
   (no cadence yet), leave blank and fall back to the computed occurrence at slip time (no regression).
2. **Soft warning (client-side):** when the operator changes the field, if the value is in the past or not
   a Mon–Fri delivery day (compare to the zone schedule / a simple weekday check), show an inline,
   non-blocking warning (e.g. "Heads up: 2026-07-25 is a Saturday — no delivery runs that day. Saving
   anyway is allowed."). Never disable the create button for this.
3. **Write on create (`create_order()`):** read a posted `delivery_date` (sanitize; accept '' = none).
   After `wc_create_order()` builds `$order`, if a valid `Y-m-d` was provided, set it:
   `$order->update_meta_data('_delivery_date', $sanitized_ymd); $order->save();`
   - If blank/omitted, do NOT write the meta (order falls through to computed occurrence — unchanged
     behavior).
   - This write handles the PRINTED date only. For the order to actually LAND on the override date's
     slip (and leave its computed-occurrence date's slip), Section D's selection changes are required.
4. **Do NOT** pass the override into `persist_next_dates()` — the client's next_order/next_delivery dates
   must keep computing from normal cadence (one-time-only semantics).

### B. Regular WC order — editable field on the order-edit admin screen
5. **Render:** on `woocommerce_admin_order_data_after_order_details`, render a "Delivery date" date input
   pre-filled from the order's existing `_delivery_date` meta if set; otherwise blank (placeholder can show
   the computed occurrence as a hint, read-only, if cheap — optional).
6. **Save:** on `woocommerce_process_shop_order_meta` (or `woocommerce_admin_order_data_after_...`'s
   corresponding save hook), sanitize the posted delivery date and:
   `$order->update_meta_data('_delivery_date', $ymd)` when valid; if the field was cleared, delete the meta
   (`$order->delete_meta_data('_delivery_date')`) so the order reverts to the computed occurrence.
   Guard with `current_user_can('edit_shop_orders')` and a nonce.
7. **Soft warning:** same past/non-delivery-day warning, shown as an admin notice or inline note after
   save; never block the save.

### C. Shared validation helper
8. Add one small helper (reused by both A and B, and mirrored client-side) that, given a `Y-m-d`, returns
   a warning string or '' : past date → warn; weekday not in the configured delivery days → warn. Resolve
   the valid-day set from the CLIENT'S ZONE in `mealsdb_zone_delivery_schedule` (it is per-zone — a global
   Mon–Fri check is the fallback only when the client has no zone or the schedule is unset). Server-side
   validation is advisory only (returned in the AJAX response / notice), matching the soft-warn decision.

### D. Override-aware slip selection (REQUIRED — the feature does not work without it)
Currently slip selection is computed-occurrence only; `_delivery_date` never enters it. Without these
changes an overridden order prints the new date on the OLD day's slip and never appears on the new day's
slip — and a Saturday override (the edge case this directive exists for) appears on NO slip, because no
client has `delivery_day = saturday`. All changes are contained in
`includes/services/class-delivery-slip-generator.php` (+ its order-query dependency); the by-zone batch
paths route through the same `get_orders_for_delivery_range()`, so fixing it here covers batch slips too.

9. **Selection rule — meta wins, occurrence otherwise.** In `get_orders_for_delivery_range()`, for each
   candidate order: if `_delivery_date` meta is set and well-formed, the order belongs to THAT date —
   include it iff the meta date is in `[start, end]`, and EXCLUDE it if the meta date is outside the
   range even when its computed occurrence is in-range (overridden OUT = leaves the old slip). Orders
   without the meta keep the existing `delivery_occurrence_for_order()` filter unchanged. Apply the same
   "meta wins" rule consistently in selection and printing so no order can appear on two slip dates
   (the occurrence math's exactly-one-slip guarantee must survive the override).
10. **Candidate window.** The creation-date pre-filter window (`creation ∈ (start − max_freq·7, end]`)
    can miss an overridden order created outside it (an override can move delivery arbitrarily far from
    creation). Fetch override candidates by a `wc_orders_meta` query on `meta_key = '_delivery_date'`
    with `meta_value BETWEEN start AND end` (HPOS — `wc_orders_meta` join or `wc_get_orders` meta_query),
    union with the existing creation-window candidates, then apply rule 9. Keep the occurrence pre-filter
    as-is for non-overridden orders — do not widen it.
11. **Client inclusion.** `get_clients_for_delivery_date()` / `get_clients_for_driver_slips()` select by
    `delivery_day` weekday match, so the owner of an overridden order is absent from the candidate set
    when the override lands on a different weekday (always, for the Saturday case). Additionally include
    clients who own an order with `_delivery_date` in the slip date/range, regardless of `delivery_day`.
    (Cheapest: run the rule-10 meta query first and add its `customer_id → wp_user_id` owners to the
    client fetch.)
12. **Out of scope, stated deliberately:** the override does NOT touch allocation/billing. The allocation
    engine keys off order creation/occurrence, so an override that drags delivery across a month boundary
    leaves the billing month where it was. This is intentional (one-time delivery convenience, not a
    billing event) — confirm with the operator only if that assumption is ever challenged.

## Must NOT change
- The slip generator's `resolve_delivery_date()` resolution order (it already does the right thing).
- The client-dates calculator / `persist_next_dates` cadence math — the override is per-order and must not
  re-anchor recurring dates.
- Any automatic writer of `_delivery_date` (there are none — keep it that way; only operator actions write
  it).
- The occurrence math itself (`delivery_occurrence_for_order()`) — Section D layers "meta wins" AROUND it;
  the non-overridden path must behave byte-identically (MAJ-6 / GUI-SLIP-RANGE regression risk).

## Verify
```
php -l includes/class-quick-order-ajax.php includes/class-admin-ui.php \
      includes/services/class-delivery-slip-generator.php
php tests/test-*.php
```
- QO: select a client with a cadence → delivery-date field prefills with the computed next_delivery_date.
- QO: accept default → create order → order has `_delivery_date` = that date → it appears on that date's
  packing/driver slip.
- QO: change the date to a different valid day → create → order rides the NEW date on slips (leaves the old
  occurrence, joins the new). Confirm the client's next_order_date/next_delivery_date are UNCHANGED
  (cadence not re-anchored).
- QO: set a Saturday / past date → soft warning shows, save still succeeds. The Saturday order then
  appears on the SATURDAY slip (client included despite non-matching `delivery_day` — Section D.11) and
  NOT on the computed-occurrence day's slip.
- QO: leave blank → order created with no `_delivery_date`; slip uses computed occurrence (unchanged).
- Regular order: open an existing order, set a delivery date, save → `_delivery_date` written → order moves
  to that date's slip. Clear it → meta removed → reverts to computed occurrence.
- Selection: an overridden order appears on exactly ONE slip date (the override date) — never on both the
  override and occurrence dates. An order overridden OUT of a queried range is excluded even though its
  occurrence is in-range.
- By-zone batch slips: an overridden order lands in the batch for its override date (shared selection path).
- Regression: generate a slip for a date/range containing only non-overridden orders → output identical to
  pre-change (occurrence path untouched).

## Tests to add
- `test-quick-order-delivery-date-override.php`: create_order with an explicit delivery_date writes
  `_delivery_date`; without one writes nothing; the override does NOT alter client next_* dates.
- `test-delivery-date-soft-validation.php`: the helper flags past + non-delivery-day dates and passes valid
  Mon–Fri future dates; zone-scheduled day sets resolve per-zone.
- `test-slip-delivery-date-override-selection.php` (Section D): (a) an order with `_delivery_date` in
  range is selected even when its computed occurrence is out of range AND its creation date is outside the
  candidate pre-filter window; (b) an order overridden out of range is excluded despite an in-range
  occurrence; (c) an overridden order maps to exactly one slip date; (d) the client of a Saturday-overridden
  order is included in the client set despite `delivery_day` mismatch; (e) non-overridden orders select
  identically to before (occurrence-path regression guard).
- Extend a slip test: an order with an operator-set `_delivery_date` is selected onto that date's slip and
  off its computed-occurrence date, and the slip header prints the override date
  (`test-pdf-slip-delivery-occurrence-date.php` already covers the header half).
