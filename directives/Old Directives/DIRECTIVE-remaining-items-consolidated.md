# Consolidated Directive — Remaining Meals DB items (v1.0.546 baseline)

Three independent items, each self-contained. Build in any order; they don't depend on each other. Items 1–2
are Quick Order polish; item 3 is a data-model cleanup. Priority order as listed (1 and 3 are the most
user-visible).

Engine note: all four are engine-agnostic (JS wiring, category logic, PHP date math) — real on live MySQL 8,
not MariaDB-staging artifacts.

================================================================================
## ITEM 1 — Quick Order: prefill the Order Date (fix silent no-op on create)
================================================================================
### Problem
On Quick Order, the **Order Date** field (`#mealsdb-quick-order-date`, the REQUIRED field for creation) does
NOT prefill on client select. If the operator doesn't manually set it, clicking **Create Order** silently
does nothing — no error, no feedback (confirmed in v546 testing: first create attempts no-op'd until Order
Date was filled). The create guard (quick-order.js ~1412) requires `orderDate` but nothing populates it and
nothing tells the user why the click did nothing.

### Fix (choose 1a; 1b is a complementary safety net)
**1a — Prefill Order Date on client select.** The `get_next_dates` response already returns a usable value
(`next_order_date` / `rule_default_order`). In `fetchNextDates().done()` (quick-order.js ~1777, right where
`#mealsdb-qo-delivery-date` is prefilled), also prefill the main order-date field, defaulting to TODAY if no
computed value (order date is normally "today", not the future cadence date):
```js
// Prefill the required Order Date so Create Order doesn't silently no-op.
// Default to today; the operator can change it.
if (self.$orderDate && self.$orderDate.length && !self.$orderDate.val()) {
    self.$orderDate.val(<today's date as YYYY-MM-DD>);
}
```
(Use the site's local date. Do NOT overwrite a value the operator already typed — only fill when empty.)
Note: keep this distinct from `#mealsdb-qo-next-order-date` (the cadence DISPLAY) — the field to fill is
`#mealsdb-quick-order-date` (the Order Date used for creation).

**1b — Never silently no-op.** In the create handler (quick-order.js ~1412), when the guard fails because
`orderDate` is empty, surface a visible inline validation message ("Please set an Order Date.") instead of
returning silently, and focus the field. This makes the failure legible even if 1a ever regresses.

### Verify
- Open Quick Order, select a client → Order Date auto-fills (today) and Create Order works immediately
  without a manual step. 📷
- Clear Order Date, click Create → an inline "set an Order Date" message shows, button doesn't silently
  no-op. 📷
- Regression: a normal QO create still succeeds and the delivery-date prefill (v546) still works.

================================================================================
## ITEM 2 — Backfill Next Dates: populate next_delivery_date (currently fills 0)
================================================================================
### Problem
"Backfill Next Dates" (Data Ops) populates `next_order_date` but ZERO `next_delivery_date` (confirmed: "7
order dates, 0 delivery dates updated"). Root cause: `MealsDB_Migration_Consolidated::run_phase_next_dates()`
(class-migration-consolidated.php ~1108) only computes `next_delivery_date` when a `last_delivery_date`
usermeta exists (`$last_delivery`), and that usermeta is empty for these clients — so the delivery branch
never fires. Meanwhile the system CAN compute a correct next delivery date from zone day + frequency (the
`get_next_dates` endpoint does exactly this).

### Fix
In `run_phase_next_dates()`, when `next_delivery_date` is empty AND there's no `last_delivery_date` to
compute from, fall back to computing from the client's **delivery_day + delivery_frequency** using the same
canonical calculator the endpoint uses (`MealsDB_Date_Calculator`), anchored on today / the client's
next_order_date, instead of skipping. i.e. mirror the order-date branch's resilience:
```php
if (empty($row['next_delivery_date'])) {
    // Prefer computing from last_delivery_date; if absent, fall back to the
    // zone delivery_day + delivery_frequency (same basis as get_next_dates),
    // so a client with no delivery history still gets a correct stored date.
    $next_delivery = <compute via MealsDB_Date_Calculator from delivery_day + delivery_frequency,
                       anchored on today or next_order_date, when $last_delivery is empty>;
    if ($next_delivery) { $patch['next_delivery_date'] = $next_delivery; }
}
```
Keep the existing last_delivery_date path when it IS present. Do not overwrite a non-empty
next_delivery_date.

### Verify
- Run Backfill Next Dates → it now reports a non-zero "delivery dates" count. 📷
- Adminer (read-only): sampled clients now have `next_delivery_date` populated on the weekday matching their
  `delivery_day` (e.g. a Zone-5/Friday client → a Friday). 📷
- This also unblocks the long-deferred resync positive demo: after backfill, a resync's `dates_recomputed`
  can be shown correcting a drifted stored date.

================================================================================
## ITEM 3 — Remove the vestigial per-product Taxable checkbox
================================================================================
(This is the previously-written taxable-removal directive, unchanged — still applies to v546; the checkbox,
`taxable_overridden` flag, and clobber bug are all still present. See DIRECTIVE-remove-taxable-checkbox.md
for the full detail; summarized here so it's in one place.)

### Summary
Taxation is category-level (dessert + muffin taxable; others not — `TAXABLE_SIDE_CATEGORY_SLUGS`). The
per-product checkbox is a retired manual override that also has a clobber bug (unchecking reverts within the
same request). Remove it; KEEP the `meals_products.taxable` COLUMN (the allocation rebuilder reads it for HST
side counts — load-bearing) as a purely category-derived cache.

### Fix (see full directive for line refs)
- Remove the `_mealsdb_taxable` checkbox UI + its JS (`wc-product-tab-tax-sync.js`).
- Save path: derive `taxable` from category, drop `resolve_taxable_override()` and `taxable_overridden`.
- Sync (`class-product-display-sync.php`): always category-derive; delete the override-preserve branch (this
  removes the clobber bug by construction).
- Schema: drop `taxable_overridden` (explicit migration; the `taxable` column stays).

### Verify (the key regression)
- Generate an SDNB invoice before/after for a client with dessert+muffin+cereal → HST side count and amount
  IDENTICAL (this is a UI/data-model removal, not a tax-rule change). 📷
- Product edit tab no longer shows a Taxable checkbox; `grep -rn taxable_overridden includes/` returns
  nothing (bar the migration).
- Operator note: before dropping the column, confirm no product had an override differing from its category.

================================================================================
## Build & verify all
```
php -l includes/class-quick-order-ajax.php includes/class-products.php \
       includes/class-wc-product-tab.php includes/class-product-display-sync.php \
       includes/services/class-migration-consolidated.php
grep -rn "taxable_overridden" includes/   # only the migration should remain (item 3)
php tests/test-*.php
```
Each item's Verify section lists its own GUI checks. After 1–3 land, a single Quick Order + Products GUI pass (with Shadow Mode temporarily OFF for the QO
checks) confirms all of them.
