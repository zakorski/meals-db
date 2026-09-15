# K14 — Quick Order Form Reset Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** After a clean Quick Order create, clear the form (empty basket + deselect client) so a second Create can't place a duplicate order, and close the one remaining double-submit window.

**Architecture:** Two edits to `assets/js/quick-order.js`. ITEM 1 extracts the reopen branch's form-clear into a single `resetAfterOrderCreated()` method and calls it from ONE gated point (`!isDraftResp && !hasDropped && !hasClamped`) that covers both the normal and reopen success paths. ITEM 2 disables the Create button synchronously after validation (reusing `setCreateOrderBusy`) and re-enables it on the confirm-cancel path.

**Tech Stack:** Vanilla ES / jQuery in `assets/js/quick-order.js`. **No JS test runner exists** (no package.json/jest). Mechanical gate is `node --check assets/js/quick-order.js` (syntax); behavioural verification is the directive's operator manual steps (listed in Task 3) — stated honestly, not faked.

---

## Spec reference

`docs/superpowers/specs/2026-09-14-k14-quick-order-reset-design.md`. Directive: `directives/DIRECTIVE-K14-quick-order-form-reset.md`.

## File structure

- **Modify** `assets/js/quick-order.js`:
  - Add method `resetAfterOrderCreated()` immediately after `clearCart()` (ends line 537).
  - Rewire the reopen branch of the create `.done()` handler (currently `:1816-1838`) — keep reopen-mode teardown, remove the inline form-clear.
  - Add the single gated reset call after the success/warning toast (after `:1869`, before the delivery-date override clear at `:1874`).
  - ITEM 2: `setCreateOrderBusy(true)` after validation (after `:1755`); `setCreateOrderBusy(false)` on the confirm-cancel path (`:1924`).

## Conventions

- Locate every anchor by the QUOTED surrounding code, not line numbers (they may drift as you edit).
- Comments explain **why** (house style). Reference K14.
- After EVERY file edit run `node --check assets/js/quick-order.js` — it must print nothing and exit 0.
- Do NOT bump `MEALS_DB_VERSION` — CI owns version bumps on merge.
- Harness note: Edit/Write may throw `Path must be a string`; shell `>`/`|`/`tee` throw `H.replace`. If Edit fails after one retry, edit via `python3 - <<'PYEOF'` read-replace-write (assert the OLD anchor matches exactly once). `<<` heredocs are fine; only `>`/`|` break. Commit with plain `git commit -m "..."`. Verify with Read or `python3 -c "print(open(f).read())"`.

---

### Task 1: ITEM 1 — Clear the form on a clean create (shared, gated, deselect)

**Files:**
- Modify: `assets/js/quick-order.js`

- [ ] **Step 1: Add `resetAfterOrderCreated()` after `clearCart()`.**

Find the end of `clearCart()` — it closes with:
```js
            this.renderSummary();
            this.updateSummaryPanel();
            this.updateAllocationWithCart();
        },
```
Immediately AFTER that closing `},` (and before `maybeLoadClonedOrder() {`), insert:
```js

        // K14 ITEM 1: clear the whole form after a clean placed order — empty the
        // basket AND deselect the client — so a second Create (a double-click or a
        // stale open tab) cannot place a duplicate order for the same client. This
        // mirrors the draft-completion branch (Directive C ITEM 5); both success
        // paths now call this ONE method so they can't drift apart again — that
        // drift is exactly what left the normal path un-cleared. Unlike clearCart()
        // (which deliberately keeps the client), this also deselects: clearing
        // #client_id and cascading handleClientSelectionChange resets the
        // context/allowance/zone panels and the summary.
        resetAfterOrderCreated() {
            this.clearCart();
            if (this.$clientSelect && this.$clientSelect.length) {
                this.$clientSelect.val('').data('clientType', '').data('clientAllergens', []);
            }
            if (this.$clientSearch && this.$clientSearch.length) {
                this.$clientSearch.val('');
            }
            this.handleClientSelectionChange();
        },
```

- [ ] **Step 2: Syntax check.** Run `node --check assets/js/quick-order.js` → exit 0, no output.

- [ ] **Step 3: Rewire the reopen branch — remove the inline form-clear.**

Find this block in the create `.done()` handler:
```js
                } else if (reopenOrderId) {
                    successMessage = this.translate('Draft completed — order placed.');
                    // The draft is now a placed order; a second Create must not
                    // try to reopen it. Drop reopen mode and restore the label.
                    this.state.reopenOrderId = 0;
                    if (this.$createOrder && this.$createOrder.length) {
                        this.$createOrder.text(this.translate('Create Order'));
                    }
                    // FOLLOW-UP DIRECTIVE C (ITEM 5): after a completion the form
                    // clears fully — NO client and NO items — so pressing Create
                    // again cannot place a second order for the same client.
                    // clearCart() empties the basket (it deliberately keeps the
                    // client), so also deselect the client: clearing #client_id and
                    // firing change cascades through handleClientSelectionChange to
                    // reset the context/allowance/zone panels and the summary.
                    this.clearCart();
                    if (this.$clientSelect && this.$clientSelect.length) {
                        this.$clientSelect.val('').data('clientType', '').data('clientAllergens', []);
                    }
                    if (this.$clientSearch && this.$clientSearch.length) {
                        this.$clientSearch.val('');
                    }
                    this.handleClientSelectionChange();
                } else {
```
Replace it with (keep the reopen-mode teardown; drop the inline clear — it moves to the shared gated call in Step 5):
```js
                } else if (reopenOrderId) {
                    successMessage = this.translate('Draft completed — order placed.');
                    // The draft is now a placed order; a second Create must not
                    // try to reopen it. Drop reopen mode and restore the label.
                    // The form-clear itself is the shared, dropped/clamped-gated
                    // resetAfterOrderCreated() call below (K14 ITEM 1) — so a
                    // completion that dropped/clamped items now keeps its context
                    // on screen for review, exactly like a normal create does.
                    this.state.reopenOrderId = 0;
                    if (this.$createOrder && this.$createOrder.length) {
                        this.$createOrder.text(this.translate('Create Order'));
                    }
                } else {
```

- [ ] **Step 4: Syntax check.** Run `node --check assets/js/quick-order.js` → exit 0.

- [ ] **Step 5: Add the single gated reset call after the toast.**

Find the toast block followed by the delivery-date override clear:
```js
                if (hasDropped || hasClamped) {
                    const parts = [];
                    if (hasDropped) {
                        parts.push(`${droppedItems.length} item(s) could not be added and were dropped`);
                    }
                    if (hasClamped) {
                        parts.push(`${clampedItems.length} line(s) were reduced to the 100-per-line limit`);
                    }
                    qoShowToast(`Order saved, but ${parts.join('; ')}. Review the order before delivery.`, 'warning');
                } else {
                    qoShowToast(successMessage, 'success');
                }

                // The override is one-time-only: clear it after a
```
Insert the gated reset between the toast's closing `}` and the `// The override is one-time-only:` comment, so the region reads:
```js
                } else {
                    qoShowToast(successMessage, 'success');
                }

                // K14 ITEM 1: clear the form ONLY on a clean placed order — not a
                // draft (deliberately kept) and not a dropped/clamped result (the
                // operator must review that order before delivery, so keep its
                // context on screen). Covers BOTH the normal and reopen success
                // paths via one call, so they can't diverge again. isDraftResp,
                // hasDropped and hasClamped are all already computed above.
                if (!isDraftResp && !hasDropped && !hasClamped) {
                    this.resetAfterOrderCreated();
                }

                // The override is one-time-only: clear it after a
```
Note: the delivery-date override clear (`$('#mealsdb-qo-delivery-date').val('')` + `refreshDeliveryDateWarning()`) stays exactly where it is, UNCONDITIONAL — do not move it into the gated block. It must clear even on a dropped/clamped order so it can't ride onto the next one.

- [ ] **Step 6: Syntax check + scope self-check.**

Run `node --check assets/js/quick-order.js` → exit 0.
Then read the edited `.done()` handler top-to-bottom and confirm:
- `isDraftResp` (defined at the `const isDraftResp = ...` line), `hasDropped`, `hasClamped` are all declared BEFORE the new gated `if`.
- `showOrderSuccess(successMessage, orderId, orderLink)` still runs AFTER the reset and reads the local `successMessage`/`orderId`/`orderLink` (NOT the form) — so the banner + order link render post-clear.
- The reopen branch still sets `this.state.reopenOrderId = 0` and restores the button label unconditionally.

- [ ] **Step 7: Commit.**
```bash
git add assets/js/quick-order.js
git commit -m "K14 ITEM 1: clear the form after a clean Quick Order create (deselect)"
```

---

### Task 2: ITEM 2 — Close the double-submit window (reuse setCreateOrderBusy)

**Files:**
- Modify: `assets/js/quick-order.js`

- [ ] **Step 1: Disable the button synchronously after validation passes.**

Find, in `handleCreateOrder`, the validation-fail guard immediately followed by the `submit` closure comment:
```js
            if (!Number.isInteger(clientId) || clientId <= 0 || !items.length) {
                qoShowToast('Please select a client and at least one product.', 'error');
                this.clearCreateOrderLoading(createButton);
                return;
            }

            // The actual submit, gated below behind the in-page date-sanity
```
Insert the disable BETWEEN the guard's closing `}` and the `// The actual submit` comment:
```js
            if (!Number.isInteger(clientId) || clientId <= 0 || !items.length) {
                qoShowToast('Please select a client and at least one product.', 'error');
                this.clearCreateOrderLoading(createButton);
                return;
            }

            // K14 ITEM 2: disable Create NOW — synchronously, before the async
            // date-sanity confirm — so a rapid double-click can't start a second
            // submit while the confirm modal is open. In-flight disabling via
            // setCreateOrderBusy(true) inside submit() leaves that async window
            // uncovered. Re-enabled by .always() on the submit path and on the
            // confirm-cancel path below. The validation-fail return above runs
            // BEFORE this, so it can never leave the button stuck disabled (J1).
            this.setCreateOrderBusy(true);

            // The actual submit, gated below behind the in-page date-sanity
```
Leave the existing `this.setCreateOrderBusy(true);` inside `submit()` as-is — it is idempotent and harmless.

- [ ] **Step 2: Syntax check.** Run `node --check assets/js/quick-order.js` → exit 0.

- [ ] **Step 3: Re-enable the button on the confirm-cancel path.**

Find the cancel branch inside the date-sanity confirm `.then`:
```js
                        if (!proceed) {
                            // K6 ITEM 2: on cancel, move focus to the Order Date
                            // field the operator needs to correct — NOT the trigger
                            // (#qo-create-order) the helper restores to. This .then
                            // runs AFTER the helper's restore, so it wins.
                            if (this.$orderDate && this.$orderDate.length) {
                                this.$orderDate.trigger('focus');
                            }
                            this.clearCreateOrderLoading(createButton);
                            return;
                        }
```
Add the re-enable immediately before `this.clearCreateOrderLoading(createButton);`:
```js
                        if (!proceed) {
                            // K6 ITEM 2: on cancel, move focus to the Order Date
                            // field the operator needs to correct — NOT the trigger
                            // (#qo-create-order) the helper restores to. This .then
                            // runs AFTER the helper's restore, so it wins.
                            if (this.$orderDate && this.$orderDate.length) {
                                this.$orderDate.trigger('focus');
                            }
                            // K14 ITEM 2: operator cancelled the date confirm — re-enable
                            // the Create button disabled after validation above.
                            this.setCreateOrderBusy(false);
                            this.clearCreateOrderLoading(createButton);
                            return;
                        }
```

- [ ] **Step 4: Syntax check + path self-check.**

Run `node --check assets/js/quick-order.js` → exit 0.
Read `handleCreateOrder` and enumerate every exit from the point the button is disabled:
- **submit path** (no date warnings, or "Create anyway"): `$.ajax(...).always(() => { this.setCreateOrderBusy(false); ... })` re-enables. ✅
- **confirm-cancel**: the new `setCreateOrderBusy(false)` re-enables. ✅
- **validation-fail**: returns BEFORE the disable — nothing to re-enable. ✅
Confirm there is no path that disables the button and then returns without a re-enable.

- [ ] **Step 5: Commit.**
```bash
git add assets/js/quick-order.js
git commit -m "K14 ITEM 2: disable Create across the async date-confirm to block double-submit"
```

---

### Task 3: Verification + PR

**Files:** none (verification + integration)

- [ ] **Step 1: Final syntax + diff review.**

Run `node --check assets/js/quick-order.js` (exit 0). Then `git diff origin/main -- assets/js/quick-order.js` and confirm the diff contains ONLY: the new `resetAfterOrderCreated()` method, the trimmed reopen branch, the gated reset call, the post-validation `setCreateOrderBusy(true)`, and the cancel-path `setCreateOrderBusy(false)`. No other lines changed.

- [ ] **Step 2: Behavioural self-review against the directive's 7 checks** (by reading the code — there is no JS runner):
  1. Normal create (clean) → `resetAfterOrderCreated()` runs → cart empty + client deselected.
  2. Present-empty / normal create clears the client and cascades `handleClientSelectionChange()` (context/allowance/zone reset).
  3. `showOrderSuccess()` runs after the reset and shows the order number + link (reads locals).
  4. A create reporting `dropped_items`/`clamped_items` does NOT reset (gated out) and the warning toast remains.
  5. Draft-completion (reopen) with a clean result STILL clears (via the shared gated call).
  6. Draft-save (`isDraftResp`) still KEEPS the form (gated out).
  7. Double submit: button disabled from just-after-validation through settle; cancel re-enables → two rapid clicks produce one create.

- [ ] **Step 3: Push and open the PR.**
```bash
git push -u origin k14-quick-order-reset
```
Then write the PR body to a file and create the PR with `--body-file` (avoid shell `>`/`$(...)`):
```bash
python3 /tmp/writer.py /tmp/k14-pr-body.md <<'BODY'
Implements DIRECTIVE-K14. After a clean Quick Order create the form now clears (empties the basket and deselects the client) so a second Create can't place a duplicate order; also closes the one remaining double-submit window.

Spec: docs/superpowers/specs/2026-09-14-k14-quick-order-reset-design.md

- ITEM 1: extracted the reopen branch's form-clear into resetAfterOrderCreated(), called from ONE gated point (!isDraftResp && !hasDropped && !hasClamped) covering both the normal and reopen paths. Deselects the client (operator decision). Draft-save keeps the form; dropped/clamped keeps context for review; the one-time delivery-date override still clears unconditionally.
- ITEM 2: the Create button already disabled in-flight (setCreateOrderBusy); now also disabled synchronously right after validation to cover the async date-confirm window, and re-enabled on cancel. Validation-fail returns before the disable, so no stuck-button (J1) path.

No JS test harness exists in this repo; verified via node --check + code review + the directive's operator manual steps. Behavioural verification is operator staging (see spec).

Generated with Claude Code
BODY
gh pr create --title "K14: Quick Order form clears after a normal create" --body-file /tmp/k14-pr-body.md
```

- [ ] **Step 4: Report the PR URL.**

---

## Self-review notes

- **Spec coverage:** ITEM 1 (extract + reopen rewire + gated deselect reset) → Task 1; ITEM 2 (disable-after-validation + cancel re-enable) → Task 2; verification + operator steps → Task 3. Delivery-date override stays unconditional (Task 1 Step 5 note). Directive checks 1-7 → Task 3 Step 2.
- **Method-name consistency:** `resetAfterOrderCreated()` defined in Task 1 Step 1 and called in Task 1 Step 5 and referenced in the reopen-branch comment — spelled identically throughout. `setCreateOrderBusy(true/false)` reuses the existing helper verbatim.
- **No automated behavioural test (stated, not hidden):** repo has no JS runner; `node --check` is a syntax gate only. The seven behaviours are verified by code review + operator manual steps. If a JS harness is stood up later, those seven become the test list.
