# Quick Order batch (zone / sticky / columns / clone fixes / allocation links) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Six independent Quick Order UX/wiring fixes: show delivery zone, sticky Order Summary, columnar price display, two clone-path defects (next-dates + monthly allowance), and linked allocation-history order numbers. **Display and wiring only — no billing/allocation/tax math changes.**

**Architecture:** Mostly `assets/js/quick-order.js` + `assets/css/quick-order.css` + `includes/class-quick-order-ui.php`, with two server-side data additions (`get_client_allocation` returns `delivery_area_zone` at top level; the allocation-history AJAX enriches each detail row with `order_exists`). Clone fixes are additive explicit calls on the clone path, not changes to `applyClonedClient`'s existing trigger.

**Tech Stack:** PHP 8.2 / WordPress admin AJAX / vanilla-jQuery / CSS. No JS unit harness exists in this repo; automated coverage here = `php -l` + `node --check` + the existing PHP regression suites (`tests/test-quick-order-*`). Functional verification is the directive's screenshot checklist on staging.

**Source directive:** `directives/DIRECTIVE-quick-order-batch-2026-08.md` (baseline v1.0.553).

### Findings that shape this plan (verified against code)
- **Item 1 placement correction:** `get_client_allocation` has THREE response exits — no-client (1417), **non-gov/private (1431)**, full/gov (1482). The directive says put zone "in the shape that carries `permitted_mains`", but that shape is gov-only. Zone goes as a **top-level response field on every meaningful branch** (private + gov), else private clients — the bulk of zoned clients — get no zone.
- **Clone path is via a hidden input `#client_id`:** `applyClonedClient` sets it and `trigger('change')` → `handleClientSelectionChange` fires all three fetches with a valid id. So the directive's "missing call" framing (Items 4/5) is imprecise.
  - **Item 4 real cause (static, confirmed):** the trigger's `fetchNextDates` runs *before* the order date is applied (order date set at 430, trigger at 531/420) → next-dates panel uses the pre-clone date. Fix = explicit re-fetch after the date is set. `fetchNextDates.done` also rewrites the one-time delivery override (line 1842) — must NOT happen on clone — so add a `skipDeliveryPrefill` option and pass it whenever cloning.
  - **Item 5 (runtime-confirm):** allocation *is* fetched with a valid id and nothing clears `state.allocation` except an invalid-id call. Static analysis cannot explain the empty panel; the directive itself demands a one-console-check confirmation. Implement a safe idempotent explicit re-fetch at the end of the clone flow (wins any ordering race); **gate merge on the runtime check (verify step 5) — do not claim it fixed without it.**
- **Item 6 data already present:** history detail rows already carry `wc_order_id` (rendered plain at `client-allocation-history.js:164`). Only new server data needed is an `order_exists` flag (the ledger row can outlive a deleted WC order, e.g. #28528).

### Must NOT change (from directive)
Billing/fee/tax/allocation/contribution math; v553 suppression sites + `isGovernmentInvoiced()`; pre-tax subtotal semantics + the `Subtotal (before tax)` label text; the clone response contract + `(object)` casts; clone notice gating; the one-time delivery override staying empty after clone/creation; element ids `#mealsdb-quick-order-summary-total|-date|-items`; MAJ-1 guards in `clone_get_order()`.

---

## Task 1: Item 1 — delivery zone

**Files:**
- Modify: `includes/class-quick-order-ajax.php` (`get_client_allocation` SELECT ~1408 + all response branches)
- Modify: `includes/class-quick-order-ui.php` (summary meta-row ~183)
- Modify: `assets/js/quick-order.js` (`fetchClientAllocation.done` ~2179; cache the element)

- [ ] **Step 1: Add `delivery_area_zone` to the SELECT**

In `get_client_allocation` (line 1408), extend the columns:

```php
            "SELECT client_id, client_type, delivery_frequency,
                    delivery_fee, client_contribution, delivery_area_zone
             FROM {$clients_table}
             WHERE wp_user_id = %d AND active = 1
             LIMIT 1",
```

- [ ] **Step 2: Return zone at the top level on both meaningful branches**

Resolve it once after `$client` is loaded (it's a plain column — no decryption). After line 1421 (`$client_type = $client['client_type'];`) add:

```php
        $client_type = $client['client_type'];
        // Plain column (not in ENCRYPTED_CLIENT_COLUMNS). Sent at the TOP LEVEL
        // of the response — NOT inside `allocation`, which only exists for
        // SDNB/Veteran — so private clients (the bulk of zoned clients) get it too.
        $delivery_area_zone = isset($client['delivery_area_zone']) && $client['delivery_area_zone'] !== ''
            ? (string) $client['delivery_area_zone'] : null;
```

Then add `'delivery_area_zone' => $delivery_area_zone,` to the non-gov response (line 1431) and the full response (line 1482 array):

Non-gov branch:

```php
        if (!in_array($client_type, ['SDNB', 'Veteran'], true)) {
            wp_send_json(['success' => true, 'allocation' => null, 'client_type' => $client_type, 'delivery_area_zone' => $delivery_area_zone]);
        }
```

Full branch — add the key alongside `client_type`:

```php
        wp_send_json([
            'success'            => true,
            'client_type'        => $client_type,
            'delivery_area_zone' => $delivery_area_zone,
            'allocation'         => $summary ? [
```

- [ ] **Step 3: Add a zone meta-row to the summary panel**

In `class-quick-order-ui.php`, after the Order Date meta-row (lines 180-183), add:

```php
                            <div class="mealsdb-quick-order__summary-meta-row">
                                <dt class="mealsdb-quick-order__summary-meta-label"><?php esc_html_e('Zone', 'meals-db'); ?></dt>
                                <dd class="mealsdb-quick-order__summary-meta-value" id="mealsdb-quick-order-summary-zone"><?php esc_html_e('—', 'meals-db'); ?></dd>
                            </div>
```

- [ ] **Step 4: Render the zone in `fetchClientAllocation.done`**

In `assets/js/quick-order.js`, inside `fetchClientAllocation`'s `.done()` (after line 2181 `this.state.allocation = ...`), set the zone display. A null/blank zone shows an explicit "No zone", never "Zone " with nothing:

```php
                if (response && response.success) {
                    this.state.allocation = response.allocation || null;
                    this.state.clientFees = response.fees || null;
                    this.state.nextDelivery = response.next_delivery || null;
                    this.state.straddlesMonth = response.straddles_month || false;
                    const $zone = $('#mealsdb-quick-order-summary-zone');
                    if ($zone.length) {
                        const zoneVal = response.delivery_area_zone;
                        $zone.text((zoneVal === null || zoneVal === undefined || zoneVal === '')
                            ? this.translate('No zone')
                            : this.translate('Zone %s').replace('%s', String(zoneVal)));
                    }
                    this.renderAllocationPanel();
```

(This block is JS despite the ```php fence.)

- [ ] **Step 5: Lint + syntax**

Run: `php -l includes/class-quick-order-ajax.php && php -l includes/class-quick-order-ui.php && node --check assets/js/quick-order.js`
Expected: all clean.

- [ ] **Step 6: Commit**

```bash
git add includes/class-quick-order-ajax.php includes/class-quick-order-ui.php assets/js/quick-order.js
git commit -m "feat(quick-order): show client delivery zone (top-level; No zone fallback)"
```

---

## Task 2: Item 2 — sticky Order Summary

**Files:**
- Modify: `assets/css/quick-order.css` (the `.mealsdb-quick-order__summary` rule)

- [ ] **Step 1: Find the current summary rule**

Run: `grep -n "mealsdb-quick-order__summary\b" assets/css/quick-order.css`
Note the selector/line to extend (do not duplicate the rule).

- [ ] **Step 2: Make the panel sticky**

Add to the `.mealsdb-quick-order__summary` rule (or append a new rule if the aside is a grid child). WP admin bar is 32px desktop / 46px mobile:

```css
.mealsdb-quick-order__summary {
    position: sticky;
    top: 42px; /* 32px admin bar + 10px breathing room */
    max-height: calc(100vh - 52px);
    overflow-y: auto;
    align-self: start; /* so a grid/flex child sticks rather than stretching full-height */
}

@media screen and (max-width: 782px) {
    .mealsdb-quick-order__summary {
        top: 56px; /* 46px collapsed admin bar + 10px */
        max-height: calc(100vh - 66px);
    }
}
```

- [ ] **Step 3: Syntax sanity**

Run: `grep -c "position: sticky" assets/css/quick-order.css`
Expected: `1`.

- [ ] **Step 4: Commit**

```bash
git add assets/css/quick-order.css
git commit -m "feat(quick-order): sticky Order Summary that scrolls internally"
```

---

## Task 3: Item 3 — price columns + orphan Subtotal label

**Files:**
- Modify: `assets/css/quick-order.css` (summary-item grid)
- Modify: `includes/class-quick-order-ui.php` (id on the Subtotal footer row ~206)
- Modify: `assets/js/quick-order.js` (`updateSummaryPanel` toggles the row ~2136)

- [ ] **Step 1: Column layout for each cart line (CSS)**

`renderSummary` already emits separate spans (`__summary-item-name` / `-qty` / `-total`, lines 1402-1406); the "lines of text" look is missing CSS. Add:

```css
.mealsdb-quick-order__summary-item {
    display: grid;
    grid-template-columns: 1fr auto auto;
    column-gap: 12px;
    align-items: baseline;
}
.mealsdb-quick-order__summary-item-qty,
.mealsdb-quick-order__summary-item-total {
    text-align: right;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
```

(Government clients emit only name + qty — the grid still aligns; the third column is simply empty, no stranded cell.)

- [ ] **Step 2: Give the Subtotal footer row an id**

In `class-quick-order-ui.php`, add an id to the Subtotal row div (line 206) — leaving the existing `dt`/`dd` ids and the label text untouched:

```php
                            <div class="mealsdb-quick-order__summary-total-row" id="mealsdb-qo-subtotal-row">
                                <dt class="mealsdb-quick-order__summary-total-label"><?php esc_html_e('Subtotal (before tax)', 'meals-db'); ?></dt>
                                <dd class="mealsdb-quick-order__summary-total-value" id="mealsdb-quick-order-summary-total">0</dd>
                            </div>
```

- [ ] **Step 3: Hide the whole row (label + figure) for government clients**

In `updateSummaryPanel` (lines 2136-2139), currently only the figure toggles. Toggle the row too so the label can't orphan:

```php
            if (this.$qoTotal && this.$qoTotal.length) {
                this.$qoTotal.text(govInvoiced ? '' : this.formatPrice(displayTotal));
                this.$qoTotal.toggle(!govInvoiced);
            }
            // Hide the whole Subtotal row for government clients so the
            // `Subtotal (before tax)` label doesn't strand beside an empty
            // figure (v552/v553: suppression now applies to manual selection too).
            $('#mealsdb-qo-subtotal-row').toggle(!govInvoiced);
```

- [ ] **Step 4: Lint + syntax**

Run: `php -l includes/class-quick-order-ui.php && node --check assets/js/quick-order.js`
Expected: clean.

- [ ] **Step 5: Commit**

```bash
git add assets/css/quick-order.css includes/class-quick-order-ui.php assets/js/quick-order.js
git commit -m "feat(quick-order): columnar summary lines; hide orphan Subtotal label for gov clients"
```

---

## Task 4: Item 4 — clone recomputes next-dates without touching the override

**Files:**
- Modify: `assets/js/quick-order.js` (`fetchNextDates` ~1814; `handleClientSelectionChange` ~1804; `loadClonedOrder` ~434)

- [ ] **Step 1: Add a `skipDeliveryPrefill` option to `fetchNextDates`**

Change the signature (line 1814) and guard ONLY the override-prefill block (lines 1841-1843) — the panel update and the order-date empty-prefill stay. The option is captured in the closure, so it survives async completion (unlike `state.isCloning`, which resets in `.always()` before these `.done()` handlers run):

```php
        fetchNextDates(userId, options = {}) {
            const skipDeliveryPrefill = !!options.skipDeliveryPrefill;
            if (!Number.isInteger(userId) || userId <= 0) {
                $('#mealsdb-qo-next-dates').hide();
                return;
            }
```

Then wrap the delivery-override prefill (lines 1841-1843):

```php
                // Delivery-date override (directive Section A.1): prefill
                // the per-order delivery date with the client's computed
                // next_delivery_date; blank when no cadence. SKIPPED when cloning
                // — the one-time override must stay empty after a clone (v550 TEST G).
                if (!skipDeliveryPrefill) {
                    self.state.clientDeliveryDay = d.has_client ? (d.delivery_day || '') : '';
                    $('#mealsdb-qo-delivery-date').val(d.has_client ? (d.next_delivery_date || '') : '');
                    self.refreshDeliveryDateWarning();
                }
```

- [ ] **Step 2: Make the trigger's fetch skip the override while cloning**

In `handleClientSelectionChange`, change the `fetchNextDates` call (line 1804) so the trigger fired *during* a clone doesn't prefill the override. `state.isCloning` is true at call time (captured now, into the option):

```php
            this.fetchClientRates(clientId);
            this.fetchClientAllocation(clientId);
            this.fetchNextDates(clientId, { skipDeliveryPrefill: this.state.isCloning });
```

- [ ] **Step 3: Re-run the fetch on the clone path with the cloned date**

In `loadClonedOrder`, after the order date is applied (lines 429-435), recompute the next-dates panel from the cloned date, still skipping the override. Call directly — never via a `change` event (recursion trap):

```php
                if (payload.order_date && this.$orderDate && this.$orderDate.length) {
                    this.$orderDate.val(payload.order_date);
                    this.updateSummaryDate();
                    // Recompute Next Order / Next Delivery from the CLONED date
                    // (the trigger's earlier fetch ran before this date was set,
                    // so the panel showed pre-clone values). Directly, not via a
                    // change event — change re-enters fetchNextDates and one clone
                    // call site is inside that handler (recursion). Override stays
                    // empty (skipDeliveryPrefill).
                    if (payload.client_id) {
                        this.fetchNextDates(parseInt(payload.client_id, 10), { skipDeliveryPrefill: true });
                    }
                }
```

- [ ] **Step 4: Syntax**

Run: `node --check assets/js/quick-order.js`
Expected: exits 0.

- [ ] **Step 5: Commit**

```bash
git add assets/js/quick-order.js
git commit -m "fix(quick-order): clone recomputes next-dates from the cloned date, override stays empty"
```

---

## Task 5: Item 5 — clone populates Monthly Allowance

**Files:**
- Modify: `assets/js/quick-order.js` (`loadClonedOrder` ~442, after items applied)

**Runtime-confirm (do this first, per the directive):** open a government order, clone it, and in DevTools Network confirm whether `mealsdb_qo_get_client_allocation` fires and what it returns. Static analysis shows it *is* fired (valid id via the hidden `#client_id` + trigger). If it fires and returns a populated allocation but the panel is still empty, the cause is a render/ordering race that the explicit late re-fetch below resolves. If it does NOT fire, the explicit call below also resolves it. Either way the fix is safe; the console check tells you which story to write in the PR.

- [ ] **Step 1: Explicit allocation re-fetch as the last writer on the clone path**

In `loadClonedOrder`, after `setMissingCloneItems` / `renderUnavailableTilesFromState` (around line 442), add an explicit fetch keyed on the payload client id so it lands last and wins any ordering race. Idempotent — harmless if the trigger already populated it:

```php
                this.setMissingCloneItems(hasMissing ? parsedItems.missing : []);
                this.renderUnavailableTilesFromState();

                // Monthly Allowance can read empty after a clone if the trigger's
                // allocation fetch is raced by the subsequent clone rendering.
                // Re-fetch explicitly with the resolved client id as the last
                // writer. fetchClientAllocation also drives hide/showProductPrices
                // off client_type, so this re-asserts v553 suppression on clone.
                if (payload.client_id) {
                    this.fetchClientAllocation(parseInt(payload.client_id, 10));
                }
```

- [ ] **Step 2: Syntax**

Run: `node --check assets/js/quick-order.js`
Expected: exits 0.

- [ ] **Step 3: Commit**

```bash
git add assets/js/quick-order.js
git commit -m "fix(quick-order): clone re-fetches client allocation so Monthly Allowance populates"
```

---

## Task 6: Item 6 — allocation-history order numbers link to WC (HPOS)

**Files:**
- Modify: `includes/class-quick-order-ajax.php` (`get_client_allocation_history` ~1541 — enrich with `order_exists`)
- Modify: `includes/class-admin-ui.php` (localize `adminOrderUrlBase` ~1106)
- Modify: `assets/js/client-allocation-history.js` (`buildDetailTable` order-number cell ~164)

- [ ] **Step 1: Add `order_exists` to each detail row (server)**

In `get_client_allocation_history` (line 1541), enrich `$details` before sending. The ledger row can outlive a deleted WC order, so a `wc_get_order()` check decides link-vs-plaintext:

```php
        $details = $engine->get_client_month_details($client_id, $billing_month);
        // Flag whether each order still exists so the client-side renders a live
        // link vs. plain text (deleted orders — e.g. #28528 — must not be dead
        // links). wc_get_order() is HPOS-correct.
        if (function_exists('wc_get_order')) {
            foreach ($details as &$detail_row) {
                $oid = isset($detail_row['wc_order_id']) ? (int) $detail_row['wc_order_id'] : 0;
                $detail_row['order_exists'] = ($oid > 0 && wc_get_order($oid) instanceof WC_Order);
            }
            unset($detail_row);
        }
```

- [ ] **Step 2: Localize the HPOS admin order URL base**

In `class-admin-ui.php`, find the `mealsdbAllocationHistory` localize array (~line 1106) and add:

```php
                    'adminOrderUrlBase' => admin_url('admin.php?page=wc-orders&action=edit&id='),
```

(Confirm the exact array with `grep -n "mealsdbAllocationHistory\|wp_localize_script" includes/class-admin-ui.php` before editing; add the key alongside `ajaxUrl` / `nonce` / `i18n`.)

- [ ] **Step 3: Render the order number as an HPOS link (client)**

In `client-allocation-history.js`, replace the plain order-number cell (line 164) with a link when the order exists. `config.adminOrderUrlBase` + escaped id; new tab; plain text when missing:

```javascript
                var orderId = parseInt(d.wc_order_id, 10) || 0;
                var orderCell;
                if (orderId > 0 && d.order_exists && config.adminOrderUrlBase) {
                    var href = config.adminOrderUrlBase + encodeURIComponent(orderId);
                    orderCell = '<a href="' + escHtml(href) + '" target="_blank" rel="noopener noreferrer">'
                        + escHtml(orderId) + '</a>';
                } else {
                    orderCell = intText(d.wc_order_id);
                }
                html += '<tr>' +
                    '<td>' + escHtml(d.delivery_date || '') + '</td>' +
                    '<td>' + orderCell + '</td>' +
                    '<td>' + intText(d.mains_count) + '</td>' +
                    '<td>' + intText(d.sides_count) + '</td>' +
                '</tr>';
```

(Replaces the existing `html += '<tr>' + ... intText(d.wc_order_id) ... '</tr>';` block — keep the `$.each` wrapper.)

- [ ] **Step 4: Lint + syntax**

Run: `php -l includes/class-quick-order-ajax.php && php -l includes/class-admin-ui.php && node --check assets/js/client-allocation-history.js`
Expected: clean.

- [ ] **Step 5: Commit**

```bash
git add includes/class-quick-order-ajax.php includes/class-admin-ui.php assets/js/client-allocation-history.js
git commit -m "feat(quick-order): allocation history order numbers link to the HPOS WC order"
```

---

## Task 7: Regression sweep + lint

**Files:** none (verification only)

- [ ] **Step 1: Run the quick-order + allocation regression suites**

Run:
```bash
for f in test-quick-order-status test-quick-order-delivery-date-override test-quick-order-next-dates-derivation; do echo "== $f =="; php "tests/$f.php" 2>&1 | tail -2; done
```
Expected: each reports pass / 0 failures.

- [ ] **Step 2: Lint everything touched**

Run:
```bash
php -l includes/class-quick-order-ajax.php && php -l includes/class-quick-order-ui.php && php -l includes/class-admin-ui.php && node --check assets/js/quick-order.js && node --check assets/js/client-allocation-history.js
```
Expected: clean.

- [ ] **Step 3: Confirm the tree is clean**

Run: `git status`
Expected: all six items committed.

---

## Verify (directive §Verify — staging, with the operator)

1. Zone shows (`Zone 3`) for a zoned client; explicit "No zone" for an unzoned client — not a blank. 📷 (Item 1)
2. Sticky: long product list scrolls, summary stays visible and scrolls internally; adding items no longer shifts the grid. 📷 (Item 2)
3. Private client: name/qty/price align in columns. Government client: name+qty only, **no orphan `Subtotal (before tax)` label**, no empty price column. 📷 (Item 3)
4. Clone: Order Date = source date, summary date matches, **Next Order/Next Delivery recompute to the cloned date**, delivery-override field **empty**. 📷 (Item 4)
5. **Clone allowance (RUNTIME-GATED):** clone a government order → "Monthly Allowance (YYYY-MM)" populates. Confirm via the DevTools Network check in Task 5 before claiming fixed. 📷 (Item 5)
6. Clone price suppression: cloning SDNB/Veteran still hides prices at all four v553 sites. 📷 (Items 4/5 re-verify)
7. History links: click an order number → correct WC order opens in a new tab; a deleted order renders as plain text. 📷 (Item 6)
8. Regressions: non-clone selection still prefills Order Date to today, prefills delivery date, shows the day-mismatch warning, creates status `processing`. 📷

**Prerequisite:** run/triage the v553 GUI test plan first — Items 1, 3, 5 sit on v553's suppression/summary rendering, which was never GUI-verified.

## Self-review notes (author)
- **Coverage:** Item 1 → Task 1; 2 → Task 2; 3 → Task 3; 4 → Task 4; 5 → Task 5; 6 → Task 6. Must-Not-Change respected: element ids preserved (new ids only added: `-summary-zone`, `-subtotal-row`); label text unchanged; override stays empty (skipDeliveryPrefill on every cloning fetch); no math touched; clone contract untouched (read-only additions to responses).
- **Directive corrections baked in:** zone at top level across branches (Item 1); Item 4 protects the override via `skipDeliveryPrefill`; Item 5 is an idempotent last-writer re-fetch gated on the runtime console check (not a claim against a disproven "missing call" cause); Item 6 adds the `order_exists` flag the "deleted → plain text" rule requires.
- **Honesty:** no JS rendering test harness exists — automated gate is lint + PHP regression suites; functional truth is the staging screenshot checklist. Item 5 must not be reported "fixed" without verify step 5.
