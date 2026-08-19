# v558 test findings (ENUM blocker, clone race, allocation links, audit gaps, cosmetics, remediation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the v558 GUI-test findings — unblock the Apetito `accepted` migration (BLOCKER), stop the clone next-dates race, build the allocation-history order column, make Add Item searchable, render added items on finalized audits, two cosmetics — and deliver a reviewed remediation script for the test run's data side effects.

**Architecture:** Item 1 restores `counted` to the canonical ENUM (so adding `accepted` alone is a SAFE auto-applied drift) and adds a post-write status re-read in `mark_accepted()` that aborts BEFORE the stock bump on a coerced write. Item 2 adds a request-sequence token to `fetchNextDates()`. Item 3 adds an Order column to the summary allocation-history table (shared link helper with #523's detail links). Items 4–6 are order-audit/PO/CSS refinements. The remediation SQL/WP-CLI is a committed, operator-run script — never executed from here.

**Tech Stack:** PHP 8.2 / WordPress admin / WooCommerce (HPOS, selectWoo) / vanilla-jQuery / CSS. Tests: the repo's in-memory-`wpdb` harness (`php tests/<file>.php`); JS/CSS gated by `node --check` + the directive's screenshot verify.

**Source directive:** `directives/DIRECTIVE-v558-test-findings.md` (baseline v1.0.558). **Build order = item order; Item 1 first (blocks the whole Apetito feature and is leaking phantom stock).**

### Corrections to the directive (verified against code)
- **Item 6b is arithmetically backwards.** Current CSS is `max-height: calc(100vh - 52px)`; the directive says use `calc(100vh - 42px)`, which is *taller* and worsens the ~24px overrun. The real cause is `box-sizing: content-box` + `padding: 1rem` (32px not counted in max-height): `42 + 818 + 32 ≈ 892` vs 870. Fix with **`box-sizing: border-box`**, not the offset.
- **Item 3 overlaps with PR #523.** #523 already links the per-delivery *detail* sub-table (`buildDetailTable`, `wc_order_id`). Item 3 targets the *summary* table's `contribution_order_id` — a different row/source. Build it with a **shared `orderCell()` helper** so the two don't diverge, reusing the `adminOrderUrlBase` #523 already localized.

### Must NOT change (from directive, across items)
The one-time delivery override staying empty after clone; `skipDeliveryPrefill`; Order Date + Summary date taking the cloned date; `added_items` storage shape; the amber "added" label + `×` remove; **no inventory effect from the audit**; the finalize gate (no edit controls after finalize); snapshot `mains_count`/`sides_count`; the risky-change gate (no bespoke destructive `ALTER` in the installer).

---

## Task 1: Item 1a — restore `counted` so `accepted` auto-applies

**Files:** Modify `includes/class-schema.php:490`

- [ ] **Step 1: Put `counted` back into the canonical ENUM**

```php
                    'status'           => "ENUM('planned','placed','accepted','arrived','counted','reconciled','cancelled') NOT NULL DEFAULT 'planned'",
```

Adding `accepted` alone is a SAFE drift and auto-applies on the version bump; `counted` is an unused member costing nothing. Removing it is a later, operator-confirmed risky change through the schema tool — do NOT bundle a removal with an addition, and do NOT add a bespoke `ALTER` to force it.

- [ ] **Step 2: Update the schema comment to record why**

Find the PO status comment block (added in PR #521, ~lines 494-499) and replace the "drop the dead 'counted'" rationale with:

```php
                    // --- PO draft workflow (2026-07 spec; 'accepted' added 2026-08).
                    // 'accepted' is added as a SAFE, auto-applied ENUM drift. 'counted'
                    // is retained UNUSED on purpose: bundling its removal with the
                    // 'accepted' addition reclassified the whole column change as RISKY
                    // and withheld BOTH (v558 ITEM 1 — the accepted migration silently
                    // never applied, coercing writes to ''). Remove 'counted' later as a
                    // standalone operator-confirmed risky change, never bundled.
```

- [ ] **Step 3: Verify the ENUM string**

Run: `grep -n "ENUM('planned'" includes/class-schema.php`
Expected: contains both `'accepted'` and `'counted'`.

- [ ] **Step 4: Commit** (CI owns the `MEALS_DB_VERSION` bump)

```bash
git add includes/class-schema.php
git commit -m "fix(schema): retain 'counted' so the SAFE 'accepted' ENUM drift auto-applies (v558 ITEM 1)"
```

---

## Task 2: Item 1b — abort `mark_accepted` on a coerced status, before the bump

**Files:** Modify `includes/services/class-purchase-orders.php` (`mark_accepted`); Test `tests/test-po-accepted-status.php`

- [ ] **Step 1: Write the failing test (coerced status → WP_Error, no bump)**

Add to `tests/test-po-accepted-status.php`. First, near the wpdb stubs, add a coercing variant + a `get_var` reader. After the `AcceptWpdb` class definition, add:

```php
// Simulates a non-strict MySQL connection whose status column has NOT migrated
// to allow 'accepted': the invalid write is silently coerced to '' and still
// reports 1 row changed (v558 ITEM 1).
class CoerceWpdb extends AcceptWpdb {
    public function update($table, $data, $where, $df = null, $wf = null) {
        if (strpos($table, 'meals_purchase_orders') !== false
            && isset($data['status']) && $data['status'] === 'accepted') {
            $data['status'] = '';
        }
        return parent::update($table, $data, $where, $df, $wf);
    }
}
```

Add a `get_var` method to `AcceptWpdb` (so the re-read works) — inside the `AcceptWpdb` class body:

```php
    public function get_var($q) {
        if (stripos($q, 'meals_purchase_orders') !== false && preg_match('/po_id = (\d+)/', $q, $m)) {
            return $this->pos[(int) $m[1]]['status'] ?? null;
        }
        return null;
    }
```

Then add the test case (after the A-6 block):

```php
// ===========================================================================
// A-drift: a coerced status (unmigrated ENUM) aborts BEFORE the stock bump.
// ===========================================================================
$w = new CoerceWpdb();
$GLOBALS['wpdb'] = $w;
$GLOBALS['wc_stock'] = [101 => 50, 102 => 20];
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
$svc->approve($id);
$res = $svc->mark_accepted($id);
chk_true(is_wp_error($res), 'A-drift: coerced status returns WP_Error');
chk($res->get_error_code(), 'schema_drift', 'A-drift: error code names schema drift');
chk($GLOBALS['wc_stock'][101], 50, 'A-drift: CD-001 stock NOT bumped on drift');
chk($GLOBALS['wc_stock'][102], 20, 'A-drift: SD-002 stock NOT bumped on drift');
```

- [ ] **Step 2: Run — fails**

Run: `php tests/test-po-accepted-status.php`
Expected: A-drift FAILs (currently `mark_accepted` bumps stock and returns true).

- [ ] **Step 3: Add the post-write assertion between transition and bump**

In `mark_accepted()`, replace the block from the `transition()` success through the bump:

```php
        $ok = $this->transition($po_id, self::STATUS_PLACED, self::STATUS_ACCEPTED, [
            'accepted_by' => get_current_user_id() ?: null,
            'accepted_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            return new WP_Error('race', __('Could not mark accepted (a concurrent change happened) — reload.', 'meals-db'));
        }

        // Post-write assertion (v558 ITEM 1): a non-strict MySQL connection
        // silently coerces an out-of-range ENUM write to '' and STILL reports 1
        // row changed, so transition() returns true even though the column did
        // not store 'accepted'. Re-read BEFORE the stock bump — a coerced status
        // must abort before inventory is committed (the exact failure that
        // stranded three POs with phantom stock in the v558 run).
        $po_table = MealsDB_DB::get_table_name(MealsDB_Tables::PURCHASE_ORDERS);
        $written  = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT status FROM `{$po_table}` WHERE po_id = %d",
            $po_id
        ));
        if ((string) $written !== self::STATUS_ACCEPTED) {
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::error(sprintf(
                    '[MealsDB Purchase Orders] mark_accepted: status stored as "%s" not "accepted" (po_id=%d) — probable schema drift (the accepted ENUM value never migrated). Inventory NOT committed.',
                    (string) $written,
                    $po_id
                ));
            }
            return new WP_Error(
                'schema_drift',
                __('Could not mark accepted: the purchase-order status column has not been migrated to allow "accepted". Apply the schema update and retry — inventory was not changed.', 'meals-db')
            );
        }

        self::apply_inventory_bump((array) $po['items']);
```

- [ ] **Step 4: Run — passes (and the existing A-1…A-8 still pass)**

Run: `php tests/test-po-accepted-status.php`
Expected: all pass, including A-drift.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-purchase-orders.php tests/test-po-accepted-status.php
git commit -m "fix(po): mark_accepted re-reads status and aborts before bump on schema drift (v558 ITEM 1)"
```

---

## Task 3: Item 2 — sequence token kills the clone next-dates race

**Files:** Modify `assets/js/quick-order.js` (`fetchNextDates` ~1834; state init)

- [ ] **Step 1: Initialise the token in state**

Find the `state` object init (near the top, where `isCloning: false` lives) and add:

```php
                nextDatesSeq: 0,
```

- [ ] **Step 2: Capture and check the token in `fetchNextDates`**

In `fetchNextDates`, increment on entry and capture in the closure; in `.done()`, write to the DOM only if the captured token is still current. Change the method opening:

```php
        fetchNextDates(userId, options = {}) {
            const skipDeliveryPrefill = !!options.skipDeliveryPrefill;
            if (!Number.isInteger(userId) || userId <= 0) {
                $('#mealsdb-qo-next-dates').hide();
                return;
            }
            const seq = ++this.state.nextDatesSeq;
            const self = this;
```

Then guard the whole `.done()` body so a superseded response is discarded. Wrap the existing done handler's contents:

```php
            }).done(function(resp) {
                // Discard a superseded response: only the most recent
                // fetchNextDates may write the panel. This kills the clone race —
                // the change-triggered fetch (pre-clone date) and the clone's
                // fetch (cloned date) are both in flight; last-issued wins,
                // last-to-RESOLVE no longer does (v558 ITEM 2).
                if (seq !== self.state.nextDatesSeq) { return; }
                if (!resp || !resp.success) return;
```

(Leave the rest of the `.done()` body — the `skipDeliveryPrefill` block, panel writes at ~1893-94, the order-date empty-prefill — unchanged; they now run only for the winning response. Note the existing body already uses `self`, so no other change.)

- [ ] **Step 3: Syntax**

Run: `node --check assets/js/quick-order.js`
Expected: exits 0.

- [ ] **Step 4: Commit**

```bash
git add assets/js/quick-order.js
git commit -m "fix(quick-order): sequence token discards superseded next-dates responses (v558 ITEM 2)"
```

---

## Task 4: Item 3 — Order column on the summary allocation-history table

**Files:** Modify `includes/class-quick-order-ajax.php` (enrich `history` rows); `includes/class-admin-ui.php` (thead); `assets/js/client-allocation-history.js` (shared `orderCell` + summary row + colspans)

- [ ] **Step 1: Enrich history rows with `order_exists` (server)**

In `get_client_allocation_history` (the block that already enriches `$details`), also enrich `$history` on `contribution_order_id`. Immediately after the existing `$details` enrichment loop, add:

```php
        if (function_exists('wc_get_order')) {
            foreach ($history as &$hist_row) {
                $coid = isset($hist_row['contribution_order_id']) ? (int) $hist_row['contribution_order_id'] : 0;
                $hist_row['order_exists'] = ($coid > 0 && wc_get_order($coid) instanceof WC_Order);
            }
            unset($hist_row);
        }
```

(`get_client_history` is `SELECT *`, so `contribution_order_id` is already present in each row.)

- [ ] **Step 2: Add the Order column header**

In `class-admin-ui.php` (thead, ~line 2296), add an Order header after Status:

```php
                            <th><?php esc_html_e('Status', 'meals-db'); ?></th>
                            <th><?php esc_html_e('Order', 'meals-db'); ?></th>
```

And bump the placeholder colspan (line 2300) from `8` to `9`:

```php
                        <tr><td colspan="9"><?php esc_html_e('Loading...', 'meals-db'); ?></td></tr>
```

- [ ] **Step 3: Shared `orderCell` helper + summary Order cell + colspans (JS)**

In `client-allocation-history.js`, add a shared helper (near the other helpers, after `escHtml`/`intText`) that both tables use — reconciling with the #523 detail links:

```javascript
    // Shared order-number cell: HPOS admin link when the order exists, plain
    // text when deleted, empty when there is no order id. Used by both the
    // summary table (contribution_order_id) and the detail table (wc_order_id).
    function orderCell(rawId, exists) {
        var id = parseInt(rawId, 10) || 0;
        if (id <= 0) { return ''; }
        if (exists && config.adminOrderUrlBase) {
            var href = config.adminOrderUrlBase + encodeURIComponent(id);
            return '<a href="' + escHtml(href) + '" target="_blank" rel="noopener noreferrer">' + escHtml(id) + '</a>';
        }
        return escHtml(id);
    }
```

Add the Order cell to each summary row (the `rows += '<tr ...>` block, after the Status `<td>`), and update the two `colspan="8"` in this function to `9` (the detail sub-row and the no-history/load-fail cells). The summary row becomes:

```javascript
                    '<td>' + escHtml(status) + '</td>' +
                    '<td>' + orderCell(row.contribution_order_id, row.order_exists) + '</td>' +
                '</tr>' +
                '<tr class="mealsdb-allocation-detail-row" data-month="' + month + '" style="display: none;">' +
                    '<td colspan="9"><em>' + escHtml(i18n.loadingDetails || '') + '</em></td>' +
                '</tr>';
```

Also update: `rows = '<tr><td colspan="9">' + escHtml(i18n.noHistory ...` and the `.fail()` `colspan="9"`.

- [ ] **Step 4: Reuse `orderCell` in `buildDetailTable` (reconcile with #523)**

Replace the #523 inline link logic in `buildDetailTable` with the shared helper so there is ONE implementation:

```javascript
                var orderId = parseInt(d.wc_order_id, 10) || 0;
                var orderTd = orderCell(d.wc_order_id, d.order_exists);
                html += '<tr>' +
                    '<td>' + escHtml(d.delivery_date || '') + '</td>' +
                    '<td>' + orderTd + '</td>' +
                    '<td>' + intText(d.mains_count) + '</td>' +
                    '<td>' + intText(d.sides_count) + '</td>' +
                '</tr>';
```

- [ ] **Step 5: Lint + syntax**

Run: `php -l includes/class-quick-order-ajax.php && php -l includes/class-admin-ui.php && node --check assets/js/client-allocation-history.js`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add includes/class-quick-order-ajax.php includes/class-admin-ui.php assets/js/client-allocation-history.js
git commit -m "feat(allocation-history): Order column linked to the contribution order (v558 ITEM 3)"
```

---

## Task 5: Item 4 — searchable Add Item dropdown (selectWoo)

**Files:** Modify `includes/admin/class-order-audit-page.php` (enqueue deps + style); `assets/js/order-audit.js` (init selectWoo on the created select)

- [ ] **Step 1: Add selectWoo as a dependency + enqueue its style**

In `enqueue_scripts()`, change the script deps and enqueue the select2 stylesheet WooCommerce ships:

```php
        wp_enqueue_script(
            'mealsdb-order-audit-js',
            plugins_url('assets/js/order-audit.js', dirname(dirname(__FILE__))),
            ['jquery', 'selectWoo'],
            defined('MEALS_DB_VERSION') ? MEALS_DB_VERSION : false,
            true
        );
        // selectWoo (WooCommerce's select2 fork) powers the searchable Add-Item
        // product picker. Registered by WC in admin; the style handle is 'select2'.
        if (wp_style_is('select2', 'registered')) {
            wp_enqueue_style('select2');
        }
```

- [ ] **Step 2: Initialise selectWoo on the dynamically-created select**

In `order-audit.js`, in the `.oa-editor-add-item` handler, after `$added.append($line);`, initialise selectWoo (guarded — fall back to the plain select if unavailable). Change the tail of that handler:

```javascript
                $line.append('<span class="oa-added-label" style="color:#8a6d00;">'
                    + esc(i18n.addedLabel || 'added — not on original order') + '</span> ');
                $line.append($sel).append(' ').append($qty).append(' ').append($rm);
                $added.append($line);
                // Searchable picker: selectWoo matches the option text
                // (rendered "Name (SKU)") on substring, case-insensitively — so
                // typing "pot pie" or "12135" both find the product (v558 ITEM 4).
                if ($.fn.selectWoo) {
                    $sel.selectWoo({
                        width: '260px',
                        placeholder: i18n.selectProduct || 'Select a product…',
                        dropdownParent: $line
                    });
                    $sel.trigger('focus');
                }
```

(The option text is already `"Name (SKU)"` from #522, so name+SKU substring search works with no data change. The existing `$sel.on('change')` still fires under selectWoo, so `data-product-id` still updates.)

- [ ] **Step 3: Syntax + lint**

Run: `php -l includes/admin/class-order-audit-page.php && node --check assets/js/order-audit.js`
Expected: clean.

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-order-audit-page.php assets/js/order-audit.js
git commit -m "feat(order-audit): searchable Add Item picker via selectWoo (v558 ITEM 4)"
```

---

## Task 6: Item 5 — show added items on a finalized audit

**Files:** Modify `includes/admin/class-order-audit-page.php` (`render_row` SKU cell, before the editable-only editor)

- [ ] **Step 1: Append added-item badges to the SKU cell (visible on all rows)**

The SKU cell (built in #522) currently lists only snapshot SKUs and sits BEFORE the `if (!$editable) return;`, so it renders on finalized audits too — the right seam. Extend it to append each added item, marked, so a finalized audit shows what was added. Replace the SKU-cell block:

```php
        $skus = [];
        foreach ($items as $it) {
            $s = is_array($it) ? trim((string) ($it['sku'] ?? '')) : '';
            if ($s !== '') { $skus[] = $s; }
        }
        $sku_html = esc_html(implode(', ', $skus));
        // Added items (shipped but not on the original order) render here too, so
        // they survive into the FINALIZED read-only view — the editor block that
        // also lists them only renders while $editable (v558 ITEM 5). Amber, and
        // shown as "Name (SKU)" so the permanent record names what was added.
        $added_items = (isset($row['added_items']) && is_array($row['added_items'])) ? $row['added_items'] : [];
        foreach ($added_items as $ai) {
            if (!is_array($ai)) { continue; }
            $a_name = trim((string) ($ai['product_name'] ?? ''));
            $a_sku  = trim((string) ($ai['sku'] ?? ''));
            $label  = $a_name . ($a_sku !== '' ? ' (' . $a_sku . ')' : '');
            $sku_html .= ' <span class="oa-sku-added" style="color:#8a6d00;" title="'
                . esc_attr__('added — not on original order', 'meals-db') . '">&#10010; '
                . esc_html($label) . '</span>';
        }
        echo '<td class="oa-sku">' . $sku_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- each piece escaped above
```

- [ ] **Step 2: Lint**

Run: `php -l includes/admin/class-order-audit-page.php`
Expected: clean.

- [ ] **Step 3: Commit**

```bash
git add includes/admin/class-order-audit-page.php
git commit -m "feat(order-audit): added items shown in the SKU column on finalized audits (v558 ITEM 5)"
```

---

## Task 7: Item 6a — correct the stale PO help text

**Files:** Modify `views/purchase-orders.php:391`

- [ ] **Step 1: Describe the real flow (inventory commits at Accept)**

```php
    <p class="description"><?php esc_html_e('Generate creates a seasonally-adjusted, pallet-optimized draft and opens it for review. Approve locks a draft; Accept commits it to inventory (vendor confirmed); Mark received records arrival; Reconcile records what actually arrived.', 'meals-db'); ?></p>
```

- [ ] **Step 2: Lint + commit**

```bash
php -l views/purchase-orders.php
git add views/purchase-orders.php
git commit -m "docs(po): help text reflects inventory commit at Accept (v558 ITEM 6a)"
```

---

## Task 8: Item 6b — sticky summary fits the viewport (box-sizing, not the offset)

**Files:** Modify `assets/css/quick-order.css` (`.mealsdb-quick-order__summary`)

- [ ] **Step 1: Add `box-sizing: border-box` so padding is inside max-height**

The overrun is the 1rem padding falling outside `max-height` (content-box), not the offset — the directive's `calc(100vh - 42px)` is *taller* and would worsen it. Add `box-sizing: border-box` to the rule (keep the existing `top`/`max-height`):

```css
.mealsdb-quick-order__summary {
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 1rem;
    background: #fff;
    box-sizing: border-box;
    /* Item 2 (#523): keep the Order Summary in view; long cart scrolls inside.
       box-sizing:border-box (v558 ITEM 6b) folds the 1rem padding INTO max-height
       so the panel bottom — with the Create Order button — no longer overruns. */
    position: sticky;
    top: 42px;
    max-height: calc(100vh - 52px);
    overflow-y: auto;
    align-self: start;
}
```

- [ ] **Step 2: Sanity + commit**

```bash
grep -c "box-sizing: border-box" assets/css/quick-order.css
git add assets/css/quick-order.css
git commit -m "fix(quick-order): sticky summary box-sizing so it fits the viewport (v558 ITEM 6b)"
```

---

## Task 9: Remediation script for the v558 data side effects (operator-run)

**Files:** Create `scripts/sql/v558-remediation.sql`

**This script is NOT executed from here.** It reverses the test run's two side effects — three POs stranded at `status = ''` and 132 phantom stock units — and is written to be **run once on staging first, with the SELECTs verified before and after.** Stock is reversed via WP-CLI (`wc_update_product_stock`, which updates postmeta + the product-meta-lookup + stock status atomically) rather than raw SQL; only the plugin's own PO table is touched with SQL.

- [ ] **Step 1: Write the script**

```sql
-- =====================================================================
-- v558 test-run remediation — RUN ONCE, ON STAGING FIRST.
-- Prereq: DIRECTIVE-v558-test-findings ITEM 1 (accepted ENUM) is deployed,
-- so re-accept works cleanly after this runs.
--
-- Two side effects from the v558 test run:
--   (A) 3 purchase orders stranded at status = '' (coerced from 'accepted').
--   (B) 132 phantom stock units committed for those POs across 6 products:
--         2818 +12, 2819 +24, 2718 +36, 2820 +12, 2714 +24, 2738 +24.
--
-- End state: POs reset to 'placed' (Approved) and stock returned to the
-- pre-accept baseline, so the operator can re-Accept each PO normally (which
-- re-commits the stock ONCE, correctly). If instead these were throwaway test
-- POs, set the target status to 'cancelled' in Part A — the stock reversal is
-- identical either way.
--
-- NOT idempotent. Running Part B twice double-decrements. Verify with the
-- SELECTs before running, and do not re-run.
-- =====================================================================

-- ---------- PART A: unstrand the POs (plugin table — safe SQL) ----------
-- 1. INSPECT first — confirm exactly the expected rows (expect 3):
SELECT po_id, po_number, status, accepted_at
FROM   2xnIt_meals_purchase_orders
WHERE  status = '' AND accepted_at IS NOT NULL;

-- 2. Reset them to 'placed' (Approved). Guarded to the stranded set only.
--    (Wrap in a transaction so a surprise row count can be rolled back.)
START TRANSACTION;
UPDATE 2xnIt_meals_purchase_orders
SET    status = 'placed', accepted_by = NULL, accepted_at = NULL
WHERE  status = '' AND accepted_at IS NOT NULL;
-- Confirm the affected-row count is 3 before COMMIT; else ROLLBACK.
COMMIT;

-- 3. VERIFY — expect 0 rows:
SELECT po_id, status FROM 2xnIt_meals_purchase_orders WHERE status = '';

-- ---------- PART B: reverse the phantom stock (WooCommerce — via WP-CLI) ----------
-- Run from the site root. wc_update_product_stock() updates _stock postmeta,
-- the wc_product_meta_lookup table, and stock status together — do NOT hand-edit
-- postmeta. Print current stock first, decrement, print again.
--
--   wp eval '
--     $deltas = [2818=>12, 2819=>24, 2718=>36, 2820=>12, 2714=>24, 2738=>24];
--     foreach ($deltas as $pid => $qty) {
--       $p = wc_get_product($pid);
--       if (!$p) { WP_CLI::warning("missing product $pid"); continue; }
--       $before = (int) $p->get_stock_quantity();
--       $after  = wc_update_product_stock($p, $qty, "decrease");
--       WP_CLI::log("product $pid: $before -> $after (-$qty)");
--     }'
--
-- Expected total reduction across the six products: 132 units.
-- =====================================================================
```

- [ ] **Step 2: Commit the script (not executed)**

```bash
git add scripts/sql/v558-remediation.sql
git commit -m "chore(remediation): v558 stranded-PO + phantom-stock cleanup script (operator-run)"
```

---

## Task 10: Full sweep

**Files:** none (verification only)

- [ ] **Step 1: PO + quick-order + order-audit regression suites**

Run:
```bash
for f in test-po-accepted-status test-po-draft-lifecycle test-po-reconcile-deltas test-order-audit test-ajax-order-audit test-quick-order-status test-quick-order-next-dates-derivation test-quick-order-delivery-date-override; do echo "== $f =="; php "tests/$f.php" 2>&1 | tail -2; done
```
Expected: each reports 0 failures.

- [ ] **Step 2: Lint everything touched**

Run:
```bash
php -l includes/class-schema.php && php -l includes/services/class-purchase-orders.php && php -l includes/class-quick-order-ajax.php && php -l includes/class-admin-ui.php && php -l includes/admin/class-order-audit-page.php && php -l views/purchase-orders.php && node --check assets/js/quick-order.js && node --check assets/js/order-audit.js && node --check assets/js/client-allocation-history.js
```
Expected: clean.

- [ ] **Step 3: Confirm the tree is clean.** `git status` → all committed.

---

## Verify (directive §Verify — staging, with the operator)
1. **Item 1:** `SHOW COLUMNS` ENUM contains `accepted`; Approve→Accept stores `accepted` (not `''`); badge renders **Accepted**; Mark Received + Un-accept offered; re-run A4/A6/A9. 📷
2. **Item 1 guard:** on an un-migrated DB, Accept returns the schema-drift error and stock is unchanged (auto: A-drift).
3. **Item 2:** clone #28411 → Next Order/Delivery = **2026-09-02**; fresh page same client = **2026-09-16**; override still empty. 📷
4. **Item 3:** client 186 history shows an **Order** column with **28529** linking to the WC order (new tab); NULL rows blank; deleted → plain text. 📷
5. **Item 4:** Add Item → type `pot pie` filters to the product; `12135` finds it by SKU; select/save/reopen persists. 📷
6. **Item 5:** add an item, finalize → the added product + SKU are visible read-only; no edit control appears. 📷
7. **Item 6a/6b:** PO help text names Accept; sticky summary's Create Order button stays within the viewport. 📷
8. **Remediation (after Item 1 deploys):** run `scripts/sql/v558-remediation.sql` on staging — 3 POs return to Approved, stock drops by 132; re-Accept one and confirm a single clean bump.

## Self-review notes (author)
- **Coverage:** 1a→T1, 1b→T2 (with a regression test proving no-bump-on-drift), 2→T3, 3→T4, 4→T5, 5→T6, 6a→T7, 6b→T8, remediation→T9.
- **Directive corrections baked in:** `counted` retained (T1); Item 6b via `box-sizing`, not the backwards offset (T8); Item 3 shares one `orderCell` with #523's detail links (T4).
- **Safety:** the only automated data-integrity test is A-drift (guard). The remediation script is committed but operator-run, staging-first, with inspect/verify SELECTs and WC-safe stock reversal; NOT executed here. Functional items (2–6) need the staging screenshot checklist — no JS render harness exists.
