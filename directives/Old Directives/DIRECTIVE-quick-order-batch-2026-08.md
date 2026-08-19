# DIRECTIVE — Quick Order batch: zone display, sticky summary, price columns, clone fixes, allocation links

**Baseline:** v1.0.553.
**Source:** stakeholder call 2026-08-14 (Janet).
**Severity:** UX polish + two functional clone defects. **No billing math changes.**

Six independent items — build in any order, revert individually if one misbehaves. Files:
`assets/js/quick-order.js`, `includes/class-quick-order-ajax.php`, `includes/class-quick-order-ui.php`,
`assets/css/` (whichever sheet serves Quick Order), plus the client-area allocation history view.

**Prerequisite note:** v553 (government price suppression + category entity decode) has **never been
GUI-verified**. Items 1 and 3 touch the same summary/price rendering. If v553 turns out to have a defect,
these changes sit on top of it — worth running the v553 test plan first, or at least being aware when
triaging.

================================================================================
## ITEM 1 — Show the client's delivery zone number
================================================================================

Janet wants the delivery zone visible when a client is selected.

**The data isn't currently sent.** `get_client_allocation()`
(`includes/class-quick-order-ajax.php` ~line 1405) selects only:
```sql
SELECT client_id, client_type, delivery_frequency, delivery_fee, client_contribution
FROM {$clients_table} WHERE wp_user_id = %d AND active = 1
```
Add **`delivery_area_zone`** to that `SELECT` and include it in the JSON response (the shape that already
carries `permitted_mains`, `remaining_sides`, `client_contribution`, etc.). It's a plain column — not in
`ENCRYPTED_CLIENT_COLUMNS` — so no decryption.

Display it near the client name / allocation panel, e.g. `Zone 3`. **A client may have no zone** (~440
have `delivery_area_zone` NULL, and per Janet some are deliberately unzoned because Jim coordinates ad-hoc
deliveries). Render something explicit for that case — "No zone" or similar — **not** an empty gap or
"Zone " with nothing after it. An unzoned client is operationally meaningful, not a rendering accident.

================================================================================
## ITEM 2 — Order Summary sticks while scrolling
================================================================================

The Order Summary panel scrolls out of view on long product lists. Make it stick.

CSS-only where possible: `position: sticky` with a `top` offset that clears the WP admin bar (and the
admin bar's mobile/collapsed heights). Constrain with `max-height: calc(100vh - <offset>)` and
`overflow-y: auto` so a long cart scrolls **inside** the panel rather than growing past the viewport.

Do not reach for a JS scroll handler unless sticky genuinely fails — a scroll listener here would fight the
grid re-renders. The v553 tester noted the summary panel changes height as items are added, which shifted
the product grid mid-sequence; sticky positioning should improve that, so **re-check it after this change**.

================================================================================
## ITEM 3 — Price display in columns
================================================================================

Janet's words: the price display "is ugly — it's not organized into columns, it's just lines of text."

`renderSummary()` (`assets/js/quick-order.js` ~line 1389 onward) emits each cart line as concatenated text,
which is why the rendered output reads `Baked Ham #12115x 1CA$9.50`. Restructure each line into aligned
columns: **product name | qty | line price**, right-aligning the numeric columns so they form a readable
money column.

Constraints:
- **Government-invoiced clients show no prices** (v553). The columns must collapse cleanly to name + qty
  for SDNB/Veteran clients — not leave empty price cells or a stranded column header.
- **Also fix the dangling label while here:** the v552 tester found `Subtotal (before tax)` renders with no
  value beside it for government clients — `updateSummaryPanel()` (~line 2137) hides the figure but not its
  label. Since v553 turned suppression on for manually-selected clients too, that orphan label now shows for
  every SDNB and Veteran client. Hide the label with the figure.
- Keep the element ids (`#mealsdb-quick-order-summary-total`, `#mealsdb-quick-order-summary-date`,
  `#mealsdb-quick-order-summary-items`) — the JS binds to them and every existing test plan references them.
- Keep the label text `Subtotal (before tax)` as-is.

================================================================================
## ITEM 4 — Clone: date defaults are wrong
================================================================================

Reported as "clone dates defaulting weird." This is a **known, previously-deferred defect** — it was flagged
as out-of-scope twice during the v550/v551 clone work and listed as a known artifact in three test plans. A
user has now reported it, so it gets built.

**What happens:** `loadClonedOrder()` sets the Order Date from the cloned order (~line 431) and calls
`updateSummaryDate()`, but does **not** recompute the next-cycle panel. So `#mealsdb-qo-next-order-date` and
`#mealsdb-qo-next-delivery-date` continue to show values derived from the *pre-clone* state.

**Fix:** after the cloned order date is applied, re-run the next-dates fetch for the resolved client using
the cloned order date, so the panel matches the date now in the field.

**Do NOT do this by firing a `change` event on the order-date field.** That is the trap the v549 work
already hit: `change` re-enters `fetchNextDates()`, whose `.done()` handler rewrites the delivery-date
field — and one of the clone call sites is *inside* that same handler, so triggering change there recurses.
Call the fetch directly with the cloned date, exactly as the v549 fix called `updateSummaryDate()` directly
instead of dispatching an event.

**Three date fields, easily confused — get the right one:**
- `#mealsdb-quick-order-date` = **Order Date** (required for creation)
- `#mealsdb-qo-delivery-date` = **Delivery Date (this order)** — the one-time OVERRIDE
- `#mealsdb-qo-next-delivery-date` = **Next Delivery Date** — cadence DISPLAY only

The one-time delivery override must remain **empty** after a clone — it is deliberately not inherited
(verified in v550 TEST G). Do not populate it from the source order.

================================================================================
## ITEM 5 — Clone: Monthly Allowance not populating in the Order Summary
================================================================================

**Cause is the same shape as ITEM 4 — a missing call on the clone path.**

`handleClientSelectionChange()` (~line 1795) calls, in order:
```js
this.fetchClientRates(clientId);
this.fetchClientAllocation(clientId);   // <-- populates state.allocation → renderAllocationPanel()
this.fetchNextDates(clientId);
```

`loadClonedOrder()` calls `applyClonedClient()` and `fetchClientRates()` — but **never
`fetchClientAllocation()`**. `state.allocation` stays null, so `renderAllocationPanel()` has nothing to draw
and the "Monthly Allowance (<month>)" block (~line 2223) is empty.

**Fix:** call `fetchClientAllocation()` on the clone path once the client is resolved.

**Check before writing the fix:** `applyClonedClient()` (~line 481) ends with
`this.$clientSelect.trigger('change')`, which should route into `handleClientSelectionChange()` and pull
allocation for free. If it does, allocation would already be populating — and since Janet reports it isn't,
either that trigger isn't reaching the handler on the clone path or something later clears
`state.allocation`. **Confirm which at runtime before implementing** — this is exactly the situation where
the project has previously written a fix against the wrong cause three times over. One console check of
whether `mealsdb_qo_get_client_allocation` fires during a clone settles it.

Note `fetchClientAllocation()` also drives `hideProductPrices()` / `showProductPrices()` off
`response.client_type` — so whichever way this is fixed, **re-verify government price suppression on the
clone path** (v553 behaviour) rather than assuming it's untouched.

================================================================================
## ITEM 6 — Allocation history order numbers link to the WooCommerce order
================================================================================

In the client area, allocation history rows show order numbers as plain text. Make each a link to that
WooCommerce order.

- Data comes from `wp_ajax_mealsdb_get_client_allocation_history`
  (`class-quick-order-ajax.php` line 40 / ~line 1512). Ensure the order id is in the response.
- This is **HPOS** — build admin order URLs from the HPOS route
  (`admin.php?page=wc-orders&action=edit&id=<id>`), not the legacy `post.php?post=<id>&action=edit`.
- Escape the id and open in a new tab so the operator doesn't lose their place in the client record.
- If the order no longer exists (deleted test orders — #28528 was permanently deleted during the July run),
  render the number as plain text rather than a dead link.

================================================================================
## Must NOT change
================================================================================
- Any billing, fee, tax, allocation, or contribution-claim math. **This batch is display and wiring only.**
- The v553 government price suppression (four gated sites), or `isGovernmentInvoiced()`.
- The pre-tax subtotal semantics — the figure stays a subtotal and the label stays
  `Subtotal (before tax)`.
- The clone response contract (`success, client_id, client_type, client_name, order_date, items, products,
  rate_id`), the fee/overage exclusion, and the `(object)` casts on `items`/`products`.
- The clone notice gating (success / warning / error) and its message wording.
- The one-time delivery-date override remaining empty after a clone and after order creation.
- Element ids listed in ITEM 3.
- The MAJ-1 single-active-client guards in `clone_get_order()`.

================================================================================
## Verify
================================================================================
1. **Zone**: select a zoned client → `Zone 3` (or correct number) shows. Select an unzoned client → an
   explicit "no zone" state, not a blank. 📷
2. **Sticky**: with a long product list, scroll — the Order Summary stays visible; a long cart scrolls
   inside the panel, not past the viewport. Re-check that adding items no longer shifts the product grid
   underneath the cursor. 📷
3. **Columns**: private-pay client with 3–4 items → name / qty / price align in columns. 📷
   Government client → collapses to name + qty with **no orphan `Subtotal (before tax)` label** and no empty
   price column. 📷
4. **Clone dates**: clone an order → Order Date = source date, summary date matches, **and** the Next
   Order / Next Delivery panel recomputes to match the cloned date. Delivery-date override field is
   **empty**. 📷
5. **Clone allowance**: clone an order for a government client → "Monthly Allowance (2026-08)" populates
   with permitted/remaining mains and sides. 📷
6. **Clone price suppression**: cloning an SDNB/Veteran order still hides prices at all four sites (v553
   regression). 📷
7. **Allocation history links**: click an order number → opens the correct WooCommerce order in a new tab.
   A deleted order renders as plain text. 📷
8. **Regressions**: normal (non-clone) client selection still prefills Order Date to today, prefills the
   delivery date, shows the day-mismatch warning, and creates an order with status `processing`. 📷
