# Directive — Fix Quick Order get_next_dates nonce mismatch (restores A2 prefill + B7 warning)

## Status: ROOT CAUSE CONFIRMED via runtime diagnostic (2026-08-12)
Captured on staging: the Quick Order `get_next_dates` AJAX returns `{success:false, message:"Invalid or
missing nonce."}`. With the correct nonce the endpoint returns valid data (has_client:true,
next_delivery_date computed, delivery_day). So the blank "Delivery Date (this order)" field (A2) and the
non-firing day-mismatch warning (B7) share ONE upstream cause: the request is rejected before any date
logic runs. This supersedes all earlier A2/B7 theories (stale stored column, missing computed fallback,
client-id resolution) — none of those were the cause.

## The bug (verified in code, v1.0.545)
- JS `fetchNextDates()` (assets/js/quick-order.js ~1762) sends:
  `nonce: this.getSecurityNonce('createOrder')`.
- `getSecurityNonce('createOrder')` returns the **createOrder** nonce, created server-side as
  `wp_create_nonce('mealsdb_quick_order_create_order')` (class-admin-ui.php ~831).
- But `get_next_dates` server-side calls `verify_request()` (class-quick-order-ajax.php ~639), which checks
  `wp_verify_nonce($nonce, 'mealsdb_nonce')`.
- `mealsdb_quick_order_create_order` != `mealsdb_nonce` → verification fails → the endpoint returns the
  nonce error → the whole response fails → prefill never runs (A2) and `clientDeliveryDay` is never set,
  so the mismatch warning has no expected day to compare (B7).

**Why get_next_dates is the ONLY broken one:** every other Quick Order "get" endpoint calls
`getSecurityNonce()` with NO argument, which falls through to the plain localized nonce
`mealsdbQuickOrder.nonce` = `wp_create_nonce('mealsdb_nonce')` — exactly what `verify_request()` checks.
`get_next_dates` is the lone call passing `'createOrder'`. (Its sibling `get_client_allocation` at ~2086
uses `getSecurityNonce()` and works — which is why the QO summary path worked while the prefill didn't,
matching the earlier 12C observation.)

## Fix (one line, minimal)
In `assets/js/quick-order.js`, the `get_next_dates` AJAX call (~1762): change
```js
nonce: this.getSecurityNonce('createOrder'),
```
to
```js
nonce: this.getSecurityNonce(),
```
so it sends the plain `mealsdb_nonce` that `get_next_dates`'s `verify_request()` verifies — matching every
other get-style endpoint. That single change restores BOTH A2 (prefill) and B7 (warning), because both
consume the now-succeeding response.

(Do NOT instead change the server to accept the createOrder nonce — the create-order nonce is a distinct,
stronger-scoped token that should stay reserved for the actual order-creation POST. Aligning the GET call
to the shared read nonce is the correct direction.)

## Alternative (only if a dedicated nonce is preferred)
If you'd rather each action have its own nonce: localize a `getNextDates` nonce
(`wp_create_nonce('mealsdb_nonce')` or a new action) into `mealsdbQuickOrder.nonces`, have
`getSecurityNonce('getNextDates')` return it, AND make `get_next_dates` verify that same action. More code,
no functional benefit here — the one-line fix above is preferred.

## Must NOT change
- The date computation in `get_next_dates` (it already returns correct computed values — verified: Zone-5
  client → next_delivery_date 2026-08-14 Friday, rule_default_delivery 2026-08-28 Friday).
- The prefill/warning JS logic (already correct — it was starved by the failed request, not broken).
- The createOrder / cloneOrder nonces or the create-order POST path.
- The `_delivery_date` override semantics.

## Verify
```
php -l includes/class-quick-order-ajax.php
```
(JS-only change; no PHP lint needed beyond sanity. No unit test can cover the nonce round-trip — verify in
the browser.)
On staging (Shadow Mode OFF so Quick Order is enabled):
- Select a client with a zone/cadence → the `get_next_dates` AJAX now returns `success:true` (check
  DevTools Network — no more "Invalid or missing nonce"). 📷
- "Delivery Date (this order)" (`#mealsdb-qo-delivery-date`) PREFILLS with the computed next delivery date
  (A2 fixed). 📷
- Set the field to a different weekday than the client's delivery day → the day-mismatch warning fires
  (`#mealsdb-qo-delivery-date-warning`), matching the wording on the regular order-edit screen (B7 fixed).
- Past-date and weekend warnings still fire; none block submission.
- Regression: create a Quick Order → still succeeds (the createOrder nonce path is untouched).

## Note on Shadow Mode
Quick Order is disabled under Shadow Mode; enable it (or toggle Shadow Mode off) to test. This is why the
bug persisted unnoticed — the feature is gated off in the current staging config.
