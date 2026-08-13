# Directive — Quick Order must create orders in a slip-eligible (active) status

## Problem (confirmed in code + reproduced in GUI test)
Quick Order's `create_wc_order()` builds the order with `wc_create_order()`, adds items, calculates
totals, and returns it — but **never sets an order status**. `wc_create_order()` defaults new orders to
**`wc-pending` ("Pending payment")**, so every Quick Order stays `pending` forever.

This breaks two things:

1. **Slips:** `MealsDB_WC_Order_Query` EXPLICITLY EXCLUDES `wc-pending` (class-wc-order-query.php ~45-50:
   "wc-pending is excluded ... never represents a real order") and only includes
   `wc-processing / wc-completed / wc-paid` (~653-654, 692-693, 729-730). A `pending` order can NEVER
   appear on any packer/driver slip run.
2. **Fees (delivery fee + monthly contribution) are never applied to QO orders** — but the mechanism
   is NOT "the fee hook only fires on the pending→processing transition." The real mechanism
   (verified in code review, 2026-08-04):
   - `woocommerce_new_order` fires **inside `wc_create_order()`** (class-quick-order-ajax.php:842),
     BEFORE `set_customer_id()` (:847), before any items, and before the `mealsdb_client_user_id`
     meta is set by the caller. So `MealsDB_Allocation_Hooks::on_order_created()` →
     `MealsDB_Order_Fees::apply_to_order()` runs against an EMPTY order, `resolve_government_client()`
     returns null, and fees silently no-op. The caller's final `$order->save()` fires only
     `woocommerce_update_order` (not hooked), and no status transition ever happens — so nothing
     ever re-runs the fee applier against the populated order.
   - The comment at class-allocation-hooks.php:39-40 ("Quick Order creates orders via
     wc_create_order() which fires woocommerce_new_order. No additional hook needed for Quick
     Order.") is therefore WRONG — the hook fires, but too early to see any order data. Replace
     that comment as part of this fix.
3. **Allocation of QO MEALS is probably NOT broken** — the original version of this directive
   overstated this. The allocation rebuilder (class-allocation-engine.php:550) excludes only
   `cancelled/failed/refunded/trash/checkout-draft`; **`wc-pending` is INCLUDED**, and `pending` is
   in the hooks' `$active_statuses` (class-allocation-hooks.php:149). The nightly rebuild has been
   picking up pending QO orders' meals all along. The historical gap is therefore **missed slips and
   missed fee/contribution lines — meal counts were likely billed correctly.**

GUI test v517 reproduced this: QO orders #27693 (control) and #27694 (override) both sat at "Pending
payment", reverted to pending after a manual Processing change, and were absent from EVERY Zone 1 slip
run. A regular WC order (#27670) held Processing and its delivery-date override worked perfectly
(F18/F19) — isolating the defect to QO-created orders' status, NOT the delivery-date feature.

> **OPEN QUESTION — the reversion is NOT explained by this diagnosis.** A never-set status explains
> "born pending," not "reverts to pending after a manual Processing change." A grep of the entire
> plugin finds NO call to `set_status()`/`update_status()` anywhere — nothing in this plugin can
> revert a status. If the GUI-observed reversion was real (and not a test observation error — stale
> page, wrong order, unclicked Update), something EXTERNAL is resetting it (another plugin, HPOS
> compat-mode sync, WC unpaid-order handling), and this fix could exhibit the same reversion. The
> "HOLDS across reloads" verification step below is therefore LOAD-BEARING: if a freshly created
> `processing` QO order ever reverts, STOP and re-diagnose — the root cause is not the one fixed here.

## Goal
Quick Order should create orders in an **active, slip-eligible status** — `processing` — so they (a)
appear on slip runs and (b) trigger the fee/allocation/contribution hooks like a normal active-status
order. Setting an active status is the missing piece; the resulting pending→processing transition at
the caller's final `$order->save()` fires `woocommerce_order_status_changed`, whose reprocess branch
(class-allocation-hooks.php:177-179) runs `apply_to_order()` + `allocate_order()` against the
fully-populated order — closing the empty-order timing gap described above.

## Decision (recommended)
Target status = **`processing`**. Slip-eligible, fires the allocation/fee transition, and is the normal
"needs fulfilment" state for these operator-entered delivery orders (not card-payment e-commerce).
`completed` would also be slip-eligible but implies already-delivered, wrong at creation. Use `processing`.

## Reference (v1.0.517)
- `MealsDB_Quick_Order_Ajax::create_wc_order()` (~837-952): builds `$order = wc_create_order()`, adds
  items, `calculate_totals()`, sets date, `return $order;`. NO status is ever set. (Note: private
  helper `create_wc_order()`, not the public AJAX handler `create_order()`.)
- Caller (~360-365) persists: `apply_delivery_date_override()` then `$order->save()`.
- Slip-eligible statuses: `wc-processing`, `wc-completed`, `wc-paid` (class-wc-order-query.php).
- Fee/allocation reprocess branch: `class-allocation-hooks.php:177-179` (any transition INTO an
  active status re-runs `apply_to_order()` + `allocate_order()`).

## Change
Set the order to `processing` BEFORE the final save, so the transition fires the allocation/fee hooks:
```php
// Quick Order creates operator-entered delivery orders, not card-payment
// e-commerce orders. Put them straight into an active, slip-eligible status so
// they (a) appear on packer/driver slip runs and (b) fire the allocation +
// fee/contribution hooks on the pending->processing transition, like a normal
// placed order. Without this they default to wc-pending, which the slip query
// excludes — and because woocommerce_new_order fires inside wc_create_order()
// on a still-EMPTY order (no customer, no items, no meta), the fee applier
// no-ops at creation and nothing ever re-runs it. The pending->processing
// transition at the caller's save() is what re-runs fees/allocation against
// the populated order.
$order->set_status('processing', __('Created via Meals DB Quick Order.', 'meals-db'));
```
Placement:
- AFTER items added + totals calculated, and BEFORE/at the final `$order->save()`. `set_status()` records
  the change; the subsequent `save()` writes it and fires the `woocommerce_order_status_*` transitions the
  fee/allocation system listens for.
- Set it inside `create_wc_order()` before returning (caller's save persists+transitions) OR in the
  caller right before `$order->save()`. Pick ONE; do not double-set.
- Keep the negative-total guard and the `added_count===0` delete BEFORE the status set (don't activate an
  order you're about to reject).
- **Also replace the stale comment at class-allocation-hooks.php:39-40** with one stating the truth:
  `woocommerce_new_order` fires before QO populates the order, so QO coverage comes from the
  status-changed reprocess branch via the `processing` status set at creation.

## Must NOT change
- The delivery-date override logic (works correctly — proven on regular orders).
- Fee/contribution logic in `MealsDB_Order_Fees` — this fix just lets QO orders REACH the transition.
- Order-date, client-routing, or product-adding logic.

## Verify
```
php -l includes/class-quick-order-ajax.php
php tests/test-*.php
```
- Create a Quick Order -> status is **Processing** (not Pending payment), and **HOLDS across reloads**
  (load-bearing — see the OPEN QUESTION above; a reversion here means an undiagnosed external actor).
- Generate the slip run for that order's zone + delivery date -> the QO order now **APPEARS** (E13/E15
  fix: re-run that scenario; confirm both a control QO order and an overridden QO order show on their
  respective date runs).
- Confirm the delivery-date OVERRIDE now works for QO orders too: a QO order with an override date appears
  on the override date's run and is absent from the client's normal-day run.
- **Billing check (important):** confirm a QO order now receives its delivery fee + monthly contribution
  (the pending->processing transition at save fires the reprocess branch). Compare a QO order's
  totals/fees to a regular placed order for the same client. QO orders were previously missing fees —
  flag the historical gap to the operator (fees + slips only; meal counts were allocated via the
  rebuilder, which includes wc-pending).

## Test to add
`test-quick-order-status.php`: assert `create_wc_order()` yields an order whose status is `processing`
(set exactly once, with the QO note); assert the rejection paths (no valid products, negative total)
delete the order WITHOUT ever activating it; assert the tie to slip eligibility — `wc-processing` is
absent from `MealsDB_WC_Order_Query::get_orders_for_users()`'s default excluded-status list while
`wc-pending` is present, so the created status is slip-eligible where the old default was not.

## Operator note (out of code scope)
QO orders created BEFORE this fix sit at `wc-pending` — not on slips, and missing their delivery fee +
monthly contribution lines. Their MEALS were still allocated/billed (the rebuilder includes pending),
so the historical gap is **fees and slips, not meal counts**. Consider a one-time review:
bulk-transition legitimate pending QO orders to processing (also fires the fee hooks), after
confirming they're real. **Caveat:** scope the remediation to OPEN months. For orders in FINALIZED
months, the rebuilder (post-LB-3) skips the target month and surfaces those meals as spillover
errors, and new fee lines change historical order totals on already-submitted invoices. Data
remediation, separate from the code fix.

## Known latent issue (out of scope, log separately)
The same empty-order `woocommerce_new_order` timing means
`MealsDB_Client_Dates::advance_on_order` (class-allocation-hooks.php:113-120) no-ops for QO orders
(customer_id is 0 at fire time), despite the comment at class-quick-order-ajax.php:371-373 claiming
the allocation hook writes `last_order_date`. The status-changed branch does NOT call
`advance_on_order`, so this fix does not close that gap. QO's own `persist_next_dates()` covers part
of it; audit separately.
