# K14 — Quick Order form must clear after a normal order

**Date:** 2026-09-14
**Directive:** `directives/DIRECTIVE-K14-quick-order-form-reset.md`
**Target:** `assets/js/quick-order.js`
**Severity:** MED-HIGH — duplicate-order risk on the phone-order workflow
**Reported by:** Janet, 2026-09-14 ("once the order is created, the screen does not clear without refreshing")

---

## Problem

In the create-order success handler (`handleCreateOrder` → `.done()` in `assets/js/quick-order.js`), the **reopenOrderId** branch (completing a saved draft) clears the form fully — empties the cart, deselects the client, clears the search box, and resets the context/allowance/zone panels via `handleClientSelectionChange()`. The **normal create** path falls through to the `else` branch and clears nothing but the one-time delivery-date override. The client stays selected, the basket stays full, and the Create button stays live.

The reasoning that justified clearing on the draft-completion path (Directive C ITEM 5: "so pressing Create again cannot place a second order for the same client") applies identically to the normal path — it was simply not applied there. The normal path is the one Janet uses all day. A second press of Create — a double-click, or an operator returning to a left-open tab — places another full order: duplicate meals, duplicate allocation, duplicate invoice line.

## Verified against the code (origin/main, post-K13 / v1.0.579)

| Fact | Evidence |
|---|---|
| Reopen branch clears the form fully | `quick-order.js:1831-1838` (clearCart + deselect `#client_id` + clear `$clientSearch` + `handleClientSelectionChange`) |
| Normal (`else`) branch clears nothing but the delivery-date override | `quick-order.js:1839-1841`, `:1874-1875` |
| `hasDropped`/`hasClamped` are computed AFTER the reopen-vs-normal branch | `quick-order.js:1855-1856` |
| `showOrderSuccess` reads local vars (orderId/orderLink/successMessage), not the form | `quick-order.js:1877`, `createOrderSuccessMessage:1935` |
| The button is ALREADY disabled in-flight | `setCreateOrderBusy(true)` at `:1766` sets `prop('disabled', true)`; `.always()` re-enables at `:1894` |
| `setCreateOrderBusy` toggles disabled + aria-busy | `quick-order.js:1959-1966` |
| The date-sanity confirm is async and runs BEFORE `submit()` | `quick-order.js:1905-1932`; cancel path at `:1924` |
| No JS test harness exists | no `package.json`/jest; `tests/test-quick-order-*.php` are server-side |

---

## Design

`assets/js/quick-order.js` only. Two items.

### ITEM 1 — Clear the form on a clean create; deselect the client

**Operator decision (Zak, 2026-09-14): deselect the client** — mirror the draft-completion branch exactly, for consistency and maximum duplicate-safety. (A back-to-back second order for the same client costs a re-select; both this and "keep client, empty cart" prevent duplicates, but deselect matches the existing branch and the reset code is then shared verbatim.)

1. **Extract** the reopen branch's form-clear (`:1831-1838`) into a new method `resetAfterOrderCreated()`:
   ```js
   resetAfterOrderCreated() {
       this.clearCart();
       if (this.$clientSelect && this.$clientSelect.length) {
           this.$clientSelect.val('').data('clientType', '').data('clientAllergens', []);
       }
       if (this.$clientSearch && this.$clientSearch.length) {
           this.$clientSearch.val('');
       }
       this.handleClientSelectionChange();
   }
   ```
   Defined once, called from one place — the reopen and normal paths cannot drift apart again (drift is what caused this bug).

2. **The reopen branch keeps only its reopen-mode teardown** — `this.state.reopenOrderId = 0` and restoring the "Create Order" button label (`:1820-1823`). These must run regardless of a dropped/clamped result, because the draft became a placed order. The inline form-clear (`:1831-1838`) is removed (it moves to the shared gated call).

3. **Single gated call**, placed AFTER the `hasDropped`/`hasClamped` computation (after `:1856`) and after the success/warning toast:
   ```js
   // K14 ITEM 1: clear the form ONLY on a clean placed order — not a draft
   // (deliberately kept) and not a dropped/clamped result (the operator must
   // review the order before delivery, so keep its context on screen).
   if (!isDraftResp && !hasDropped && !hasClamped) {
       this.resetAfterOrderCreated();
   }
   ```
   This covers the normal AND reopen paths uniformly.

4. **The delivery-date override clear stays UNCONDITIONAL** where it is (`:1874-1875`), NOT folded into the gated reset. It is a one-time-override guarantee: it must clear even on a dropped/clamped order so it cannot silently ride onto the operator's next order. The directive's "keep the existing delivery-date override clear" is satisfied by leaving it in place.

5. **Ordering:** the gated reset runs before `showOrderSuccess()` (`:1877`), which reads local variables, not the form — so the success banner and its order-number link still render after the clear. Confirm this holds when implementing (read, don't assume).

**Do NOT clear on a failed create** (`!isSuccessfulResponse` returns early at `:1795`, before any reset) or a partial one (dropped/clamped — gated out above).

### ITEM 2 — Close the double-submit gap (reuse the existing helper)

The button is already disabled during the in-flight request (`setCreateOrderBusy(true)` at `:1766`, re-enabled in `.always()`), and after ITEM 1 a post-success second click has an empty cart and fails validation. The one remaining window is the **async date-sanity confirm modal** (`:1905-1928`): between the click and `submit()`, the button is still live, so a rapid double-click could stack two confirms/submits.

Fix, reusing the existing helper — no new state flag:
- Call `this.setCreateOrderBusy(true)` **right after validation passes** (after `:1755`), before the confirm/submit branch — so the button greys out synchronously on the first click and the async-confirm window is covered.
- **Re-enable on the confirm-cancel path**: add `this.setCreateOrderBusy(false)` at the cancel branch (`:1924`, alongside the existing `clearCreateOrderLoading`). The submit path's `.always()` (`:1894`) already re-enables.
- The validation-fail early return (`:1751-1755`) happens BEFORE the new disable, so there is no stuck-button risk on that path — this deliberately avoids the J1 failure mode (button left stuck disabled). The redundant `setCreateOrderBusy(true)` at `:1766` inside `submit()` is harmless and may be left as-is.

---

## Out of scope

- The draft-save path (`Draft saved — not placed`) — deliberately keeps the form (`isDraftResp` gate excludes it from the reset).
- `clearCart()` semantics — it intentionally keeps the client; ITEM 1 deselects the client separately, exactly as the reopen branch does.
- Anything server-side — the order is created correctly; this is a UI-reset defect only.

---

## Testing

**There is no JavaScript test harness in this repo** (no `package.json`/jest; the `tests/test-quick-order-*.php` files exercise server-side derivation, not this DOM/jQuery code). Rather than stand up a JS harness for a single directive, verification is:

1. **Code review** of the diff (spec-compliance + quality), against the directive's 7 behaviours.
2. **Operator manual verification (staging)** — the directive's steps:
   - Normal create → basket empty, client cleared, context/allowance/zone panels reset, no page refresh.
   - Success banner still shows the order number and its link.
   - Second order immediately after → no residue.
   - Press Create twice quickly → exactly one order created.
   - Complete a saved draft → still clears (guards the refactor).
   - Save a draft → form still kept.

This limitation is stated explicitly, not hidden. If a JS harness is stood up later (separate infrastructure decision), the seven directive behaviours become the test list.

---

## Acceptance criteria

- The form-reset block exists ONCE (`resetAfterOrderCreated()`) and is reached by both the reopen and normal success paths through a single gated call.
- No clear occurs on a failed, dropped, or clamped result; the draft-save path still keeps the form.
- The delivery-date override still clears on every successful create (including dropped/clamped).
- The Create button is disabled from the first click through to settle (success or failure), including across the async date-confirm; it is re-enabled on cancel and on completion, with no stuck-disabled path.
- The success banner + order link still render after the form clears.
