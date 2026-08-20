# v560 test findings (audit picker, draft visibility, PO UX, category decode) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the v560 GUI-test findings — make the Add Item picker render, show added items live in the draft audit, surface the un-accept refusal, validate the approve prompt, stop cross-row stepper edit loss, decode the category tab, and document the Reconcile Stock column.

**Architecture:** Mostly `assets/js/order-audit.js` + `assets/js/purchase-orders.js`, one line in `class-products-loader.php`, one comment in `views/purchase-orders.php`, and two i18n keys. No server logic changes.

**Tech Stack:** vanilla-jQuery / selectWoo (select2) / PHP. No JS render harness exists — gate = `node --check` + `php -l` + the existing PHP regression suites; functional truth is the directive's screenshot checklist.

**Source directive:** `directives/DIRECTIVE-v560-test-findings.md` (baseline v1.0.560).

### Corrections folded in (from the code, verified)
- **Item 1:** my #526 already inits selectWoo on the Add Item *click* (editor visible), not "at injection." The real culprit is `width: '260px'` + `dropdownParent: $line` (a shrink-wrapped node) initialising before layout. Fix = **`width: '100%'`, drop `dropdownParent`, defer init one tick, guard with `.data('select2')`** — not just "init later."
- **Item 2:** the SKU cell already server-renders added items for **all** rows (drafts included) — `render_row` does it before the editable check. The gap is purely client-side: the `.oa-editor-save` success handler never refreshes the SKU cell (finalize only "works" because it reloads). Fix = rebuild the row's `.oa-sku` added-badges in the save handler, markup identical to the PHP.
- **Item 5:** the per-SKU debounce already coalesces *same-row* rapid clicks. The observed loss is *cross-row* saves racing on the shared PO payload (documented last-write-wins). Fix = **serialize saves globally** (one in-flight at a time), not per-line coalescing. `tr.mealsdb-po-saving` CSS already exists for the indicator.
- **Item 3:** a client-side empty-reason guard already calls `msg()`; on the list view that element is at the top of the page, far from the clicked row → looks silent. Fix = **re-prompt on empty** (the directive explicitly allows it) so the refusal is a modal that can't be missed.

### Not in this build
- **Item 7 (client search misses):** diagnostic-only — no fix. Left as an operator-run probe (Adminer + Network) documented in the directive; cannot run from here.
- Automatic ENUM/online-unsupported DDL — out of scope (operator closed).

---

## Task 1: Item 6 — category entity decode (quick, isolated)

**Files:** Modify `includes/class-products-loader.php:240`

- [ ] **Step 1: Decode the category name**

```php
            $categories[] = [
                'id'   => (int) $term->term_id,
                // Category names are consumed as TEXT only (the QO tab strip uses
                // jQuery `text:`), so decoding here is safe — this is the loader
                // that populates the cache the tabs read (the v553 fix only covered
                // MealsDB_Quick_Order_Products::get_product_categories()). If a
                // category name is ever moved into an .html()/concatenated HTML
                // context, it MUST be escaped at that point.
                'name' => html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8'),
                'slug' => $term->slug,
            ];
```

- [ ] **Step 2: Lint + commit** (cache flush from v553 already fires on version bump)

```bash
php -l includes/class-products-loader.php
git add includes/class-products-loader.php
git commit -m "fix(products): decode category names in the loader cache (v560 ITEM 6)"
```

---

## Task 2: Item 1 — Add Item picker renders at full width

**Files:** Modify `assets/js/order-audit.js` (the `.oa-editor-add-item` handler, selectWoo init ~197-204)

- [ ] **Step 1: Replace the fragile init**

Change the selectWoo block. Give the select a full-width style, defer init one tick so the freshly-appended element is laid out before select2 measures it, use `width: '100%'`, drop `dropdownParent`, and guard against double-init:

```javascript
                var $qty = $('<input type="number" min="1" class="oa-added-qty" value="1" style="width:70px;" />');
                var $rm = $('<button type="button" class="button-link oa-added-remove">&times;</button>');
                $line.append('<span class="oa-added-label" style="color:#8a6d00;">'
                    + esc(i18n.addedLabel || 'added — not on original order') + '</span> ');
                $sel.css('width', '100%');
                $line.append($sel).append(' ').append($qty).append(' ').append($rm);
                $added.append($line);
                // Searchable picker (v560 ITEM 1). Init DEFERRED one tick so the
                // just-appended element is laid out before select2 measures it —
                // measuring a not-yet-laid-out element yields a collapsed 103×15
                // container (the v558/#526 bug). width:'100%' fills the field;
                // no dropdownParent (it positions against body correctly); guarded
                // so a second open can't stack widgets. Substring match on the
                // option text ("Name (SKU)") covers name AND SKU. Falls back to the
                // plain <select> if selectWoo is unavailable.
                if ($.fn.selectWoo) {
                    window.setTimeout(function () {
                        if (!$sel.data('select2')) {
                            $sel.selectWoo({
                                width: '100%',
                                placeholder: i18n.selectProduct || 'Select a product…'
                            });
                        }
                        $sel.trigger('focus');
                    }, 0);
                }
```

- [ ] **Step 2: Syntax + commit**

```bash
node --check assets/js/order-audit.js
git add assets/js/order-audit.js
git commit -m "fix(order-audit): Add Item picker renders full-width (deferred selectWoo init) (v560 ITEM 1)"
```

---

## Task 3: Item 2 — added items visible live in the draft list

**Files:** Modify `assets/js/order-audit.js` (the `.oa-editor-save` success callback)

- [ ] **Step 1: Rebuild the row's SKU added-badges on save**

In the `.oa-editor-save` success callback, before hiding the editor, refresh the row's `.oa-sku` cell so a draft shows added items without a reload — markup identical to the PHP `render_row` (`oa-sku-added`, U+271A `✚`, `Name (SKU)`):

```javascript
            }, function (d) {
                var $row = auditRow(orderId);
                applyRowStatus($row, 'edited');
                $row.find('.oa-delta').show();
                applyNoteIcon($row, note);
                // Refresh the SKU column's added-item badges live so a DRAFT shows
                // them without a reload (finalize only worked because it reloads).
                // Markup mirrors render_row()'s .oa-sku-added exactly (v560 ITEM 2).
                var $skuCell = $row.find('td.oa-sku');
                if ($skuCell.length) {
                    $skuCell.find('.oa-sku-added').remove();
                    $editor.find('.oa-added-line').each(function () {
                        var pid = parseInt($(this).attr('data-product-id'), 10) || 0;
                        if (pid <= 0) { return; }
                        var label = $(this).find('.oa-added-select option:selected').text()
                                 || String($(this).find('.oa-added-select').val() || '');
                        $skuCell.append(' <span class="oa-sku-added" style="color:#8a6d00;" title="'
                            + esc(i18n.addedLabel || 'added — not on original order')
                            + '">✚ ' + esc(label) + '</span>');
                    });
                }
                $editor.hide();
                updateProgress(d);
            });
```

- [ ] **Step 2: Syntax + commit**

```bash
node --check assets/js/order-audit.js
git add assets/js/order-audit.js
git commit -m "fix(order-audit): show added items in the draft SKU column live after save (v560 ITEM 2)"
```

---

## Task 4: Item 3 — un-accept empty-reason is never silent

**Files:** Modify `assets/js/purchase-orders.js` (the `unapprove || unaccept` branch ~210-220)

- [ ] **Step 1: Re-prompt on empty (a modal that can't be missed)**

Replace the branch so an empty reason re-prompts instead of only setting a top-of-page message (which is far from the clicked row on the list view). Cancel still aborts with no action:

```javascript
        } else if (kind === 'unapprove' || kind === 'unaccept') {
            var promptTxt = (kind === 'unaccept')
                ? t('promptUnaccept', 'Enter a reason for un-accepting (required):')
                : t('promptUnapprove', 'Enter a reason for un-approving (required):');
            var reason = window.prompt(promptTxt);
            if (reason === null) { return; } // cancel = no action (not the same as empty)
            reason = reason.replace(/^\s+|\s+$/g, '');
            // Empty is NOT silent: surface the message AND re-prompt until a reason
            // is given or the operator cancels (v560 ITEM 3 — the reason refusal
            // was invisible because msg() renders at the top of the list view).
            while (reason === '') {
                msg(t('reasonRequired', 'A reason is required.'), true);
                var again = window.prompt(t('reasonRequired', 'A reason is required.') + ' ' + promptTxt);
                if (again === null) { return; }
                reason = again.replace(/^\s+|\s+$/g, '');
            }
            data.reason = reason;
        } else if (!window.confirm(map.confirm)) {
```

- [ ] **Step 2: Syntax + commit**

```bash
node --check assets/js/purchase-orders.js
git add assets/js/purchase-orders.js
git commit -m "fix(po): un-accept/-approve re-prompt on empty reason instead of silent refusal (v560 ITEM 3)"
```

---

## Task 5: Item 4 — approve prompt validates the date

**Files:** Modify `assets/js/purchase-orders.js` (approve list-prompt branch ~202-209); add i18n keys in `views/purchase-orders.php`

- [ ] **Step 1: Validate `YYYY-MM-DD`; empty → default; invalid → re-prompt**

Replace the list-page approve branch. A small validator rejects impossible dates (e.g. `2026-13-40`) via round-trip, matching what the operator expects; the server still guards:

```javascript
        if (kind === 'approve') {
            var $arrival = $('#mealsdb-po-expected-arrival');
            if ($arrival.length) {
                // Detail page: date input + the normal confirm dialog.
                if (!window.confirm(map.confirm)) { return; }
                data.expected_arrival = String($arrival.val() || '');
            } else {
                // List page: prompt doubles as confirm. Validate before submitting
                // so a typo can't masquerade as an accepted date (v560 ITEM 4).
                // Blank → the server's computed default; the prompt says so.
                var isValidYmd = function (s) {
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) { return false; }
                    var d = new Date(s + 'T00:00:00Z');
                    return !isNaN(d.getTime()) && d.toISOString().slice(0, 10) === s;
                };
                while (true) {
                    var picked = window.prompt(t('promptExpectedArrival', 'Expected arrival date (YYYY-MM-DD), or leave blank for the computed default — OK approves:'), '');
                    if (picked === null) { return; } // cancel aborts the approval
                    picked = picked.replace(/^\s+|\s+$/g, '');
                    if (picked === '') { data.expected_arrival = ''; break; } // → server default
                    if (isValidYmd(picked)) { data.expected_arrival = picked; break; }
                    msg(t('invalidDate', 'Enter a date as YYYY-MM-DD, or leave blank for the default.'), true);
                }
            }
        } else if (kind === 'unapprove' || kind === 'unaccept') {
```

- [ ] **Step 2: Add the two i18n strings to the PO island**

In `views/purchase-orders.php`, in the `mealsdb_po_render_island` `i18n` array, update the prompt text and add `invalidDate`:

```php
            'promptExpectedArrival' => __('Expected arrival date (YYYY-MM-DD), or leave blank for the computed default — OK approves:', 'meals-db'),
            'invalidDate'      => __('Enter a date as YYYY-MM-DD, or leave blank for the default.', 'meals-db'),
```

- [ ] **Step 3: Lint + syntax + commit**

```bash
php -l views/purchase-orders.php && node --check assets/js/purchase-orders.js
git add assets/js/purchase-orders.js views/purchase-orders.php
git commit -m "fix(po): validate the approve expected-arrival prompt; blank uses the default (v560 ITEM 4)"
```

---

## Task 6: Item 5 — serialize stepper saves so cross-row edits don't clobber

**Files:** Modify `assets/js/purchase-orders.js` (`queueSave` / `saveRow` ~121-162)

- [ ] **Step 1: Add a single-in-flight save queue**

The per-SKU debounce already coalesces same-row clicks; the loss is cross-row saves racing on the shared PO payload (last-write-wins). Serialize: one `$.post` at a time, next fires on completion. Replace `queueSave` + `saveRow`:

```javascript
    var savePending = {};    // sku -> $row: the latest debounced row awaiting save
    var saveInFlight = false;

    function queueSave($row) {
        var sku = String($row.data('sku'));
        if (saveTimers[sku]) { window.clearTimeout(saveTimers[sku]); }
        saveTimers[sku] = window.setTimeout(function () {
            delete saveTimers[sku];
            savePending[sku] = $row; // keep only the latest value for this sku
            flushSaves();
        }, 600);
    }

    // Serialize saves: only one is in flight at a time so edits to DIFFERENT rows
    // of the same PO can't race on the shared payload (last-write-wins would drop
    // an edit — v560 ITEM 5). Each queued save reads the payload only AFTER the
    // prior write has committed.
    function flushSaves() {
        if (saveInFlight) { return; }
        var skus = Object.keys(savePending);
        if (!skus.length) { return; }
        var sku = skus[0];
        var $row = savePending[sku];
        delete savePending[sku];
        saveRow($row);
    }

    function saveRow($row) {
        var sku   = String($row.data('sku'));
        var cases = currentCases($row);
        var data  = { nonce: cfg.nonce, po_id: cfg.poId, sku: sku };
        if (mode === 'draft') {
            data.action = 'mealsdb_po_edit_cases';
            data.cases  = cases;
        } else {
            data.action         = 'mealsdb_po_reconcile_edit';
            data.received_cases = cases;
            data.note           = String($row.find('.mealsdb-po-note').val() || '');
        }
        saveInFlight = true;
        $row.addClass('mealsdb-po-saving');
        $.post(cfg.ajaxUrl, data, function (res) {
            if (!res || !res.success) {
                msg((res && res.data && res.data.message) || t('requestFailed', 'Request failed.'), true);
                return;
            }
            msg('');
            if (res.data && res.data.changed) {
                var $count = $('#mealsdb-po-edit-count');
                if ($count.length) { $count.text((parseInt($count.text(), 10) || 0) + 1); }
            }
            $row.find('.mealsdb-po-coverage').addClass('mealsdb-po-recomputed');
            window.setTimeout(function () {
                $row.find('.mealsdb-po-coverage').removeClass('mealsdb-po-recomputed');
            }, 600);
        }).fail(function () {
            msg(t('requestFailed', 'Request failed.'), true);
        }).always(function () {
            $row.removeClass('mealsdb-po-saving');
            saveInFlight = false;
            flushSaves(); // run the next queued save, if any
        });
    }
```

- [ ] **Step 2: Syntax + commit**

```bash
node --check assets/js/purchase-orders.js
git add assets/js/purchase-orders.js
git commit -m "fix(po): serialize draft stepper saves so cross-row edits don't clobber (v560 ITEM 5)"
```

---

## Task 7: Reconcile Stock column — record the design intent

**Files:** Modify `views/purchase-orders.php` (Stock column header in the grid)

- [ ] **Step 1: Add the clarifying comment**

Find the grid's Stock `<th>` (the `Stock` column header, near the `Adj/Wk` / `Case size` headers) and add a comment above it so it isn't "fixed" by a future reader (queried twice now):

```php
                    <?php // Reconcile's Stock column intentionally shows the PO's
                          // GENERATION-TIME snapshot (current_stock from the draft),
                          // NOT live inventory. Reconcile confirms what the vendor
                          // shipped against what was ordered/accepted, so it must
                          // show the PO's own quantities. Do NOT switch this to a
                          // live stock read (v560 — confirmed correct by the operator). ?>
                    <th class="num"><?php esc_html_e('Stock', 'meals-db'); ?></th>
```

(Match the exact existing `<th>` for the Stock column — confirm with `grep -n "esc_html_e('Stock'" views/purchase-orders.php` before editing.)

- [ ] **Step 2: Lint + commit**

```bash
php -l views/purchase-orders.php
git add views/purchase-orders.php
git commit -m "docs(po): note Reconcile Stock column shows PO snapshot by design (v560)"
```

---

## Task 8: Full sweep

**Files:** none (verification only)

- [ ] **Step 1: Lint everything touched**

```bash
php -l includes/class-products-loader.php && php -l views/purchase-orders.php && node --check assets/js/order-audit.js && node --check assets/js/purchase-orders.js
```
Expected: clean.

- [ ] **Step 2: Regression suites (nothing here changes server logic, but confirm no collateral)**

```bash
for f in test-order-audit test-ajax-order-audit test-po-draft-lifecycle test-po-accepted-status test-quick-order-status; do echo "== $f =="; php "tests/$f.php" 2>&1 | grep -E "passed|PASS" | tail -1; done
```
Expected: each passes.

- [ ] **Step 3: `git status` clean.**

---

## Verify (directive §Verify — staging, with the operator)
1. **Item 1:** Add Item → picker renders full-width, no visible native dropdown; `pot pie` and `12135` both filter; close/reopen editor still renders correctly; added item still saves/persists/shows SKU. 📷
2. **Item 2:** add to a draft row, save → appears in that row's SKU column in the list, marked like the finalized view; finalize → unchanged. 📷
3. **Item 3:** Un-accept + empty reason → visible message + re-prompt; cancel → no action; valid reason still un-accepts and reverses stock. 📷
4. **Item 4:** approve empty → computed default (prompt says so); `not-a-date` → rejected, PO not approved; `2026-09-15` → stored exactly. 📷
5. **Item 5:** stepper ×5 rapid → final value = 5 increments, one matching `po_draft_edit`; reload matches screen; rapid edits across several rows all persist. 📷
6. **Item 6:** category tab reads `Chicken & Turkey`; no `&amp;`/`&#039;`/`&quot;` on any label; clicking still loads products. 📷
7. **Item 7 (probe, operator-run):** run the Adminer query + capture the `mealsdb_qo_search_clients` JSON for `Williamson` vs a working term; report both verbatim — the fix follows from which branch it lands in.

## Self-review notes (author)
- **Coverage:** 1→T2, 2→T3, 3→T4, 4→T5, 5→T6, 6→T1, reconcile-comment→T7. Item 7 intentionally not built (diagnostic).
- **Corrections baked in:** Item 1 width/dropdownParent/defer (not just timing); Item 2 client-side SKU refresh (server already renders); Item 5 global serialization (not per-line coalescing); Item 3 re-prompt (guard already existed).
- **Must-not-change respected:** finalized rendering untouched (Item 2 mirrors it); `Δ`, `mains/sides` snapshot counts, `MAX_CASES`, `po_draft_edit` one-entry-per-persist all unchanged (serialization doesn't alter what's logged, only ordering); selectWoo fallback kept.
- **Honesty:** no JS render harness — automated gate is lint + PHP regression; functional truth is the screenshot checklist. Item 3/Item 1 exact mechanisms should be confirmed on staging, but the fixes are robust to either cause.
