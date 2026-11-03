# DIRECTIVE — v558 test findings: schema migration, clone next-dates, allocation links, audit gaps

**Baseline:** v1.0.558.
**Source:** full GUI test run 2026-08-19 (`Meals DB — v558 Full Test`).
**Priority order below is the build order.** ITEM 1 blocks the entire Apetito feature.

---

# ITEM 1 — The `accepted` ENUM migration never applied (BLOCKER)

## What happened

`SHOW COLUMNS FROM 2xnIt_meals_purchase_orders` on staging returns:
```
status  enum('planned','placed','arrived','counted','reconciled','cancelled')
```
`accepted` is **absent**; `counted` is **still present**. The new `accepted_by` / `accepted_at` columns DID
apply — so the migration ran and only the ENUM change was withheld.

Consequence, observed on three POs: MySQL is not in strict mode on this connection, so `status = 'accepted'`
was silently coerced to **`''`**. An empty status matches no UI branch, so the PO loses its status badge and
every action except Review / Export CSV. **Mark Received, Un-accept and Reconcile all become unreachable**,
and the PO is stranded with committed stock and no route forward.

The audit log proves the application code is correct — `po_accepted` records `new_value = 'accepted'`. Only
the column cannot hold it.

## Root cause — and it was a mistake in the previous directive

`MealsDB_Schema_Sync` auto-applies **SAFE** column drifts and withholds **RISKY** ones for the operator's
preview + typed-confirm tool. The classification is at `includes/class-schema-sync.php` **line 179** (safe)
and **line 254** (risky):

- *adding* an ENUM value → **SAFE**, auto-applies
- ***removing*** *an ENUM value* → **RISKY**, withheld

The previous directive instructed dropping `counted` in the same change as adding `accepted`. That single
instruction reclassified the whole column change as RISKY, so **nothing** applied — including the
`accepted` addition that would have gone through on its own.

## Fix

**Put `counted` back in the canonical ENUM.** In `includes/class-schema.php` (~line 490):

```php
'status' => "ENUM('planned','placed','accepted','arrived','counted','reconciled','cancelled') NOT NULL DEFAULT 'planned'",
```

Adding `accepted` alone is a SAFE drift and will auto-apply on the version bump. `counted` is an unused
member costing nothing; removing it is cosmetic and can be done later through the operator's schema tool as
a deliberate, confirmed risky change. **Do not bundle a removal with an addition again.**

Do NOT add a bespoke `ALTER TABLE` to the installer to force the removal — that bypasses the risky-change
gate, which exists precisely to stop unattended destructive DDL.

## Guard against silent coercion

This failure was silent because an invalid ENUM write became `''` instead of erroring. `mark_accepted()`
already checks `transition()`'s return, but `$wpdb->update()` reported 1 row changed — it *did* write,
just not the value asked for. Add a cheap post-write assertion in `mark_accepted()`: re-read `status` and,
if it is not `STATUS_ACCEPTED`, log an error and return a `WP_Error` naming a probable schema drift.

**Order matters:** the stock bump currently runs after `transition()`. Move the verification **between**
them, so a coerced status aborts before inventory is committed. That is exactly the failure mode seen here —
stock was committed against a PO that then became unreachable.

## Verify
1. `SHOW COLUMNS FROM 2xnIt_meals_purchase_orders` → ENUM contains `accepted`. 📷
2. Approve → Accept a PO → `SELECT po_id, status, accepted_by, accepted_at` shows **`accepted`**, not `''`. 📷
3. The status badge renders **Accepted**, and **Mark Received** and **Un-accept** are both offered. 📷
4. Then re-run the previously blocked assertions: **A4** (stock does NOT change at Received), **A6**
   (un-accept reverses stock exactly), **A9** (reconcile applies deltas only).

---

# ITEM 2 — Clone: Next Order / Next Delivery still anchored to today

## What happened

Cloning order #28411 (order dated **2026-08-05**) shows Next Order / Next Delivery = **2026-09-16**, which
is identical to what a fresh non-clone page shows for the same client. The client's `delivery_frequency` is
4 and `delivery_day` is wednesday; today (2026-08-19) + 4 weeks = 2026-09-16. Anchored to the cloned date it
should read **2026-09-02**. The "Normally:" hint is likewise today-anchored.

## Root cause — a race, NOT the ordering

The obvious suspect is wrong: `loadClonedOrder()` (~line 429) sets `$orderDate.val(payload.order_date)`
**before** calling `fetchNextDates(...)`, and `fetchNextDates` reads `order_date: this.$orderDate.val()` at
call time. That sequence is correct.

The problem is that **two `get_next_dates` requests are in flight** and the wrong one lands last:

1. `applyClonedClient()` (~line 481) ends with `this.$clientSelect.trigger('change')` →
   `handleClientSelectionChange()` (~line 1825) fires `fetchNextDates(clientId, …)`. At that moment the
   order-date field still holds the **pre-clone** value (empty, or today), so the server falls back to "now".
2. The clone path then sets the date and fires its own `fetchNextDates` with the **cloned** date.

Both `.done()` handlers write `#mealsdb-qo-next-order-date` / `#mealsdb-qo-next-delivery-date`
unconditionally (~line 1893). Whichever response returns last wins. Request 1 was issued first but is not
guaranteed to resolve first — and the observed values are request 1's.

**Confirm this at runtime before building.** Open the Network tab, clone an order, and record: how many
`mealsdb_qo_get_next_dates` requests fire, the `order_date` parameter each sends, and the order in which
they *complete*. If exactly one request fires and it carries the cloned date, this diagnosis is wrong —
stop and report. (This project has repeatedly written fixes against the wrong cause on this file; one
console check settles it.)

## Fix — a request-sequence token

Give `fetchNextDates()` a monotonically increasing token. Increment `this.state.nextDatesSeq` on entry,
capture it in the closure, and in `.done()` write to the DOM **only if** the captured token still equals the
current one. A superseded response is discarded.

This fixes the general class rather than the clone symptom — the same double-fetch shape exists whenever a
client change and another trigger overlap.

**Do NOT fix it by suppressing the change-triggered fetch during a clone.** `state.isCloning` is cleared in
the `.always()` handler (~line 526), i.e. when the clone AJAX settles — which may be before or after the
change-triggered fetch resolves. Gating on that flag reintroduces the same race with different timing.

**Do NOT fix it by firing a `change` event** on the order-date field — that re-enters `fetchNextDates` and
one clone call site sits inside that handler (recursion). The existing comment at ~line 436 records this.

## Must not change
- The one-time delivery override staying **empty** after a clone (verified passing).
- `skipDeliveryPrefill` behaviour.
- Order Date and Order Summary date both taking the cloned date (verified passing).

## Verify
1. Clone #28411 → Next Order / Next Delivery = **2026-09-02**, and the "Normally:" hint agrees. 📷
2. Fresh non-clone page, same client → **2026-09-16** (today-anchored). The two must now differ. 📷
3. Clone a second order for a different client and confirm the panel matches that order's date.
4. Regression: the delivery-override field is still empty after a clone; Order Date still matches source. 📷

---

# ITEM 3 — Allocation history order links were never built

## What happened

The Allocation History table on a client record has eight columns — Month · Mains Allowed · Mains Used ·
Mains Overage · Sides Allowed · Sides Used · Sides Overage · Status — and **no order column at all**.
`querySelectorAll('a')` on the section returns empty. Checked on two clients, including the only client in
the database whose allocation row carries a `contribution_order_id`. Also absent from the WP user profile
and from Meals DB → Reports.

This is not a broken link. The feature was specified and not implemented.

## Fix

- Add an **Order** column to the Allocation History table.
- Source the id from `2xnIt_meals_client_allocations.contribution_order_id` (the column exists).
- Render as a link to the **HPOS** order route: `admin.php?page=wc-orders&action=edit&id=<id>` — **not**
  the legacy `post.php?post=<id>&action=edit`. Escape the id; open in a new tab.
- Where `contribution_order_id` is NULL, render an empty cell — most rows have no contribution order.
- Where the id is set but the order no longer exists, render the number as **plain text**, not a dead link.

**Data caveat for whoever tests this:** exactly one row in the entire allocations table has a populated
`contribution_order_id` (client 186, 2026-08, order 28529). Testing the populated case means using that
client. The deleted-order case cannot be exercised on current data — order #28528 is referenced by no
allocation row — so verify the fallback by other means or record it as untestable.

## Verify
1. Allocation History for client 186 shows an **Order** column with **28529** as a link. 📷
2. Clicking it opens WooCommerce order 28529 in a new tab. 📷
3. Rows with no contribution order show an empty cell, not "0" or a broken link. 📷

---

# ITEM 4 — Audit Add Item dropdown is not searchable

## What happened

The product control is a plain native `<select class="oa-added-select">` with **164 options** — no search
input, no select2/selectWoo wrapper (`document.querySelector('.select2-container')` returns null). Typing
`chi` does what a native select does: jumps to the first option beginning "Chi" while the option count stays
at 164. Substring matching is impossible, so an operator looking for "pot pie" must scroll 164 items.

The directive specified a **searchable** dropdown. This is a plain select.

## Fix

Make it filter on substring as the operator types. Either:
- initialise the existing **selectWoo/select2** that WooCommerce already ships in admin (preferred — it is
  loaded, styled consistently, and needs no new asset), or
- a text input that filters the option list client-side against the already-loaded product list.

Either way: match on **substring, case-insensitively, against both product name and SKU** — an operator may
type `12135` as readily as `pot pie`.

## Must not change
- The added-item storage shape (`added_items` with `product_id`, `sku`, `product_name`, `qty`) — verified
  working.
- The amber "added — not on original order" label and the `×` remove control.
- **No inventory effect** (verified passing — stock unchanged at 499 / 372 / 694 through add, save and
  finalize).

## Verify
1. Click Add Item, type `pot pie` → the list filters to Chicken Pot Pie. 📷
2. Type `12135` → the same product is found by SKU. 📷
3. Select, save, reopen → the added item persists with its SKU. 📷

---

# ITEM 5 — Added items are invisible on a finalized audit

## What happened

After finalizing, the added Chicken Pot Pie #12135 appears **nowhere** on the page — not the product name,
not the SKU in that client's SKU column, not the "not on original order" label. The only surviving trace is
a Δ beside Mains and the Edited status. The data is still stored (it is in the encrypted payload); it simply
is not rendered.

Recording an unexpectedly shipped item is the entire purpose of Add Item, and it is invisible in the
permanent record.

## Fix

Render added items in the **finalized** (read-only) view:
- Show them in the row's SKU column alongside snapshot SKUs, visually marked as added.
- Or expand the finalized row to list its items read-only.

Either is acceptable; the requirement is that a finalized audit **shows what was added**, without offering
any edit control.

## Must not change
- The finalize gate itself: Add Item, quantity inputs, Confirm buttons and edit pencils must all remain
  **absent** after finalize (verified passing).
- Snapshot `mains_count` / `sides_count` staying unchanged by an added item (verified passing).

## Verify
1. Add an item to a draft row, finalize, and confirm the added product **and its SKU** are visible on the
   finalized audit. 📷
2. Confirm no edit control appears anywhere on the finalized audit. 📷

---

# ITEM 6 — Two cosmetics (batch with the above)

**6a — Stale help text.** The Purchase Orders page still reads *"Approve locks a draft; Mark received adds
it to inventory; Reconcile records what actually arrived."* On v558 the inventory commit is at **Accept**.
Update to describe the real flow: Approve locks · **Accept commits inventory (vendor confirmed)** · Mark
received records arrival · Reconcile records what actually arrived.

**6b — Sticky summary overruns by ~24px.** `.mealsdb-quick-order__summary` uses
`position: sticky; top: 42px; max-height: 818px`. Measured bottom is 894 against a viewport of 870, so the
panel's bottom — where **Create Order** sits — falls just below the fold with a long item list. Replace the
fixed max-height with `calc(100vh - 42px)` (or whatever offset the admin bar actually needs).

---

# Not in this directive

- **Remediation of the v558 test run's side effects** — three POs stranded at `status = ''` and **132 units
  of phantom stock** across six products (2818 +12, 2819 +24, 2718 +36, 2820 +12, 2714 +24, 2738 +24).
  Neither is reversible through the GUI. Separate SQL, to run after ITEM 1 lands.
- **Category decode** at `class-products-loader.php:240` (still showing `Chicken &amp; Turkey`) — known,
  previously deferred.
- **PO order date cannot be set** (fixed to today, stamped at approval), so the Tuesday-cadence table is
  unverifiable. The offset rule itself is confirmed correct: T+8 / T+10 / T+13 / T+28 reproduced exactly.
  Only raise this if Janet needs to backdate a PO.
- Compound-surname audit sorting — unverified for lack of data, not a known defect. Sorting is correct on
  everything present, including case-insensitivity and multi-word first names.
