# PO One-Click Optimized Draft Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clicking **Generate draft PO** on the forecast tab creates a pallet-optimized draft PO and opens its detail page in one step; the forecast preview is removed and CSV export moves to the draft detail page.

**Architecture:** The existing `mealsdb_po_save_draft` AJAX endpoint already regenerates forecast rows server-side, optionally optimizes, and creates the draft — we make optimization unconditional and point the Generate button straight at it. The preview machinery (table, summary banner, forecast CSV, and its orphaned read endpoint) is deleted. A new client-side CSV export on the draft detail page builds rows from the grid's `data-*` attributes so it always reflects live stepper edits, routed through `Report.csvCell` (CSV-injection guard).

**Tech Stack:** WordPress plugin PHP 8.2 (`$wpdb`, admin-ajax), jQuery, shared `assets/js/report-utils.js` helpers, standalone `php tests/test-*.php` test scripts.

**Spec:** `docs/superpowers/specs/2026-07-11-po-one-click-draft-design.md`

**Baseline note:** two PDF tests fail on this machine (missing mbstring/imagick) — that is pre-existing; ignore those two failures.

---

### Task 0: Branch

**Files:** none

- [ ] **Step 1: Create the feature branch**

```bash
cd /mnt/fastssd/meals-db && git checkout -b feat/po-one-click-draft
```

---

### Task 1: Server always optimizes the saved draft

**Files:**
- Modify: `includes/ajax/class-ajax-purchase-orders.php` (method `save_draft`, ~lines 45–74)
- Tests (existing, must stay green): `tests/test-po-draft-lifecycle.php`, `tests/test-po-freight-optimization.php`

No new test: the handler is 4 lines of AJAX glue calling `wp_send_json_*`; the optimizer itself is fully covered by `test-po-freight-optimization.php` (FR-1…FR-8) and draft creation by `test-po-draft-lifecycle.php`. Stubbing `MealsDB_Reports::generate_purchase_order()`'s order/product DB queries to test 3 lines of glue is not worth the fixture weight.

- [ ] **Step 1: Make optimization unconditional in `save_draft()`**

Replace the docblock and body of `save_draft()` in `includes/ajax/class-ajax-purchase-orders.php`. Current code:

```php
    /**
     * Forecast tab "Save as draft PO". The rows are REGENERATED server-side
     * rather than accepted from the browser: the on-screen table is display
     * data, not a trusted payload. The operator saves "what the model says
     * right now" — identical to the preview unless stock/orders moved in the
     * seconds between Generate and Save.
     */
    public static function save_draft(): void {
        if (!self::guard('client_modify')) {
            return;
        }
        try {
            $reports = new MealsDB_Reports($GLOBALS['wpdb']);
            $rows    = $reports->generate_purchase_order();
            if (!empty($_POST['optimize'])) {
                $optimized = MealsDB_Reports::optimize_po_for_pallets($rows);
                $rows      = $optimized['rows'];
            }
```

New code:

```php
    /**
     * Forecast tab "Generate draft PO" (one-click flow, spec 2026-07-11).
     * The rows are REGENERATED server-side rather than accepted from the
     * browser: the on-screen page is display data, not a trusted payload.
     * Drafts are ALWAYS pallet-optimized — the preview and its optimize
     * toggle were removed; the optimizer is a pure post-processor over the
     * forecast rows (test-po-freight-optimization.php) and the draft page
     * is where the operator reviews and edits the result.
     */
    public static function save_draft(): void {
        if (!self::guard('client_modify')) {
            return;
        }
        try {
            $reports   = new MealsDB_Reports($GLOBALS['wpdb']);
            $optimized = MealsDB_Reports::optimize_po_for_pallets($reports->generate_purchase_order());
            $rows      = $optimized['rows'];
```

The rest of the method (`$service = new MealsDB_Purchase_Orders(); $po_id = $service->create_draft($rows); …`) is unchanged.

- [ ] **Step 2: Lint and run the PO tests**

```bash
cd /mnt/fastssd/meals-db && php -l includes/ajax/class-ajax-purchase-orders.php \
  && php tests/test-po-draft-lifecycle.php && php tests/test-po-freight-optimization.php
```

Expected: `No syntax errors detected`, both test scripts end with all passes / 0 failures.

- [ ] **Step 3: Commit**

```bash
git add includes/ajax/class-ajax-purchase-orders.php
git commit -m "feat(po): always pallet-optimize saved draft POs

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Remove the orphaned forecast-preview endpoint

**Files:**
- Modify: `includes/ajax/class-ajax-reports.php` (registration line in `init()`, method `generate_purchase_order()` ~lines 70–120)
- Modify: `includes/services/class-reports.php` (docblock of `export_purchase_order_csv()`, ~line 492)
- Tests (existing, must stay green): `tests/test-purchase-order-3week-buffer.php`, `tests/test-reports-csv-injection.php`

The endpoint's only caller was the deleted preview in `purchase-order.js`. The service methods `MealsDB_Reports::generate_purchase_order()` and `::optimize_po_for_pallets()` stay (used by `save_draft` + tests). `export_purchase_order_csv()` also stays — it is a pure, tested function (`test-purchase-order-3week-buffer.php`, `test-reports-csv-injection.php`) — but gets a docblock note that it has no production caller.

- [ ] **Step 1: Delete the endpoint**

In `includes/ajax/class-ajax-reports.php`:

1. In `init()`, delete this line:

```php
        add_action('wp_ajax_mealsdb_generate_purchase_order', [self::class, 'generate_purchase_order']);
```

2. Delete the entire `generate_purchase_order()` method — from its docblock

```php
    /**
     * Generate a seasonally-adjusted purchase order projection.
     */
    public static function generate_purchase_order(): void {
```

through the closing brace after `wp_send_json($response);`. (It ends immediately before the `contribution_reconciliation()` docblock.)

- [ ] **Step 2: Note the exporter's status**

In `includes/services/class-reports.php`, find the docblock of `export_purchase_order_csv(array $po_rows): string` and add this line to it (keep the existing content):

```php
     * NOTE (2026-07-11): no production caller since the forecast-tab preview
     * was removed (one-click draft flow); kept as a tested pure exporter —
     * the draft detail page builds its CSV client-side via Report.csvCell.
```

- [ ] **Step 3: Verify nothing else references the endpoint, lint, run tests**

```bash
cd /mnt/fastssd/meals-db && grep -rn "mealsdb_generate_purchase_order" --include="*.php" --include="*.js" . ; \
php -l includes/ajax/class-ajax-reports.php && php -l includes/services/class-reports.php \
  && php tests/test-purchase-order-3week-buffer.php && php tests/test-reports-csv-injection.php
```

Expected: grep prints nothing (exit 1 is fine), `No syntax errors detected` twice, both tests all-pass.

- [ ] **Step 4: Commit**

```bash
git add includes/ajax/class-ajax-reports.php includes/services/class-reports.php
git commit -m "refactor(po): remove orphaned forecast-preview AJAX endpoint

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Forecast tab becomes one button

**Files:**
- Modify: `views/purchase-order.php` (full rewrite, currently 95 lines)
- Modify: `assets/js/purchase-order.js` (full rewrite, currently 241 lines)

- [ ] **Step 1: Rewrite `views/purchase-order.php`**

Replace the entire file with:

```php
<?php
/**
 * Purchase Order — one-click draft generation (spec 2026-07-11).
 *
 * The forecast preview that used to render here (table, pallet-optimisation
 * summary, CSV export, optimize toggle) was removed: Generate persists a
 * pallet-optimized draft server-side and navigates straight to the draft
 * detail page, which is the real review surface (editable steppers, coverage
 * warnings, pallet totals, CSV export).
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

?>
<div id="mealsdb-purchase-order" class="mealsdb-purchase-order">
    <p class="description">
        <?php echo esc_html__('Generate a seasonally-adjusted purchase order. Uses recency-weighted demand, year-over-year seasonal indices, and current inventory levels.', 'meals-db'); ?>
    </p>

    <p class="description">
        <?php echo esc_html__('Forecast model (fixed, validated by back-test): 12-week recency-weighted history, 6-week order horizon plus a 3-week demand-proportional safety buffer (9 weeks of coverage), seasonal index clamped to 0.3–3.0. Not configurable.', 'meals-db'); ?>
    </p>

    <p class="description">
        <?php echo esc_html__('Generating creates a pallet-optimized draft PO and opens it for review. The order is snapped to whole Apetito pallets (75 cases): filled up if the partial pallet is at least a third full, otherwise trimmed — within a 7–52 week coverage guard. Adjust individual rows on the draft page.', 'meals-db'); ?>
    </p>

    <div class="mealsdb-po-controls" style="margin-bottom:16px;">
        <button type="button" class="button button-primary" id="mealsdb-po-generate">
            <?php echo esc_html__('Generate draft PO', 'meals-db'); ?>
        </button>
    </div>

    <div id="mealsdb-po-status" class="notice" style="display:none;"></div>
</div>

<?php
// Server data for assets/js/purchase-order.js — JSON island, read by element
// id. JSON_HEX_* makes it safe inside the <script> tag (do NOT esc_js — this
// is JSON data, not JS source).
$mealsdb_po_island = array(
    'ajaxUrl' => admin_url('admin-ajax.php'),
    // PO draft workflow: its own nonce context (destructive family) and the
    // detail page to open after a successful save.
    'poNonce'    => wp_create_nonce(MealsDB_Ajax_Purchase_Orders::NONCE_ACTION),
    'poAdminUrl' => admin_url('admin.php?page=mealsdb&tab=po_admin'),
    'i18n'    => array(
        'generating'      => __('Generating…', 'meals-db'),
        'requestFailed'   => __('Request failed.', 'meals-db'),
        'draftSaveFailed' => __('Could not save the draft purchase order.', 'meals-db'),
    ),
);
?>
<script type="application/json" id="mealsdb-purchase-order-data"><?php echo wp_json_encode($mealsdb_po_island, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
```

- [ ] **Step 2: Rewrite `assets/js/purchase-order.js`**

Replace the entire file with:

```js
/**
 * Purchase Order — one-click draft generation (spec 2026-07-11).
 *
 * Generate posts mealsdb_po_save_draft (the server REGENERATES the forecast
 * rows and pallet-optimizes them; the browser never supplies row data) and
 * redirects to the new draft's detail page. The old forecast preview (table
 * render, pallet summary banner, CSV export) lives on only as the draft
 * detail page itself.
 */
(function ($) {
    'use strict';

    var _el  = document.getElementById('mealsdb-purchase-order-data');
    var data = _el ? JSON.parse(_el.textContent || '{}') : {};

    var i18n    = data.i18n || {};
    var ajaxUrl = data.ajaxUrl || window.ajaxurl;

    // Shared report helpers. Guard so an absent report-utils.js degrades
    // (minimal inline fallback) rather than crashing the view.
    var R = window.MealsDBReport || {};

    function t(key, fallback) {
        return (i18n[key] != null) ? i18n[key] : fallback;
    }

    function showStatus(msg, type) {
        var $el = $('#mealsdb-po-status');
        if (R.showStatus) {
            R.showStatus($el, msg, type);
            return;
        }
        $el.show()
            .removeClass('notice-info notice-success notice-error notice-warning')
            .addClass('notice-' + type)
            .html($('<p>').text(msg == null ? '' : String(msg))); // .text() — no HTML injection
    }

    $('#mealsdb-po-generate').on('click', function () {
        // Disabled while in flight: a double-click must not create two drafts.
        var $btn = $(this).prop('disabled', true);
        showStatus(t('generating', 'Generating…'), 'info');
        $.post(ajaxUrl, {
            action: 'mealsdb_po_save_draft',
            nonce: data.poNonce || ''
        }, function (res) {
            if (res && res.success && res.data && res.data.po_id) {
                window.location.href = (data.poAdminUrl || '') + '&po_id=' + parseInt(res.data.po_id, 10);
                return;
            }
            $btn.prop('disabled', false);
            showStatus((res && res.data && res.data.message) || t('draftSaveFailed', 'Could not save the draft purchase order.'), 'error');
        }).fail(function () {
            $btn.prop('disabled', false);
            showStatus(t('requestFailed', 'Request failed.'), 'error');
        });
    });
})(jQuery);
```

- [ ] **Step 3: Lint PHP and check for stale references**

```bash
cd /mnt/fastssd/meals-db && php -l views/purchase-order.php && \
grep -rn "mealsdb-po-optimize\|mealsdb-po-save-draft\|mealsdb-po-export\b\|optimized_csv" --include="*.php" --include="*.js" .
```

Expected: `No syntax errors detected`; grep prints nothing (`mealsdb-po-export-csv` on the detail page arrives in Task 4 and does not match `mealsdb-po-export\b`).

- [ ] **Step 4: Commit**

```bash
git add views/purchase-order.php assets/js/purchase-order.js
git commit -m "feat(po): one-click Generate creates and opens an optimized draft

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Export CSV on the draft detail page

**Files:**
- Modify: `includes/class-admin-ui.php` (the `po_admin` case in `enqueue_tab_view_scripts()`, ~line 130)
- Modify: `views/purchase-orders.php` (detail-actions paragraph ~lines 141–159, JSON island call ~line 320)
- Modify: `assets/js/purchase-orders.js` (new export handler + header comment)

- [ ] **Step 1: Give `purchase-orders.js` the report-utils dependency**

In `includes/class-admin-ui.php`, in `enqueue_tab_view_scripts()`, change:

```php
            case 'po_admin':
                $enqueue('purchase-orders');
```

to:

```php
            case 'po_admin':
                // report-utils supplies csvRow/exportCsv for the detail-page
                // CSV export (Pattern 14 injection guard lives there).
                $enqueue('purchase-orders', [self::register_report_utils_script()]);
```

- [ ] **Step 2: Add the button and `poNumber` to the detail view**

In `views/purchase-orders.php`:

1. The `<p class="mealsdb-po-detail-actions">` block renders for every workflow PO (all statuses). Add the export button just before the `<span id="mealsdb-po-action-msg" ...>` line:

```php
                <button type="button" class="button" id="mealsdb-po-export-csv"><?php esc_html_e('Export CSV', 'meals-db'); ?></button>
                <span id="mealsdb-po-action-msg" role="status"></span>
```

(Legacy task-created POs — `$is_workflow === false` — never render this paragraph, so they get no export button, per spec.)

2. In the island call at the bottom of the detail branch, add `poNumber`:

```php
    $mealsdb_po_render_island([
        'poId'       => $po_id,
        'poNumber'   => (string) $po['po_number'],
        'mode'       => $mode,
        'palletSize' => class_exists('MealsDB_Operational_Constants') ? (int) MealsDB_Operational_Constants::APETITO_CASES_PER_PALLET : 0,
    ]);
```

- [ ] **Step 3: Add the export handler to `assets/js/purchase-orders.js`**

1. In the file's header docblock, extend the "Three concerns" list with a fourth line:

```
 *   4. Detail-page CSV export, built from the live grid rows (so stepper
 *      edits are reflected) through Report.csvRow/exportCsv (Pattern 14).
```

2. Append this block just before the final `})(jQuery);`:

```js
    // ------------------------------------------------------------------
    // Export CSV (detail view). Values come from the row's data-* snapshot
    // attributes plus the live case count — NOT the formatted cell text —
    // so locale thousands-separators never leak into the CSV. Every cell
    // routes through Report.csvRow (formula-injection guard, Pattern 14);
    // if report-utils failed to load we refuse rather than emit unguarded
    // cells. In reconcile mode "Cases" is the received count (what the
    // grid shows).
    // ------------------------------------------------------------------
    var R = window.MealsDBReport || {};

    $(document).on('click', '#mealsdb-po-export-csv', function () {
        if (!R.csvRow || !R.exportCsv) {
            msg(t('requestFailed', 'Request failed.'), true);
            return;
        }
        var csv = R.csvRow(['SKU', 'Product', 'Adj/Wk', 'Stock', 'Case size', 'Cases', 'Order qty', 'Coverage (wks)', 'Forecast note']);
        $('#mealsdb-po-grid tbody tr').each(function () {
            var $row     = $(this);
            var caseSize = parseInt($row.data('case-size'), 10) || 1;
            var cases    = parseInt($row.find('.mealsdb-po-cases').attr('data-cases'), 10);
            if (isNaN(cases)) { // locked mode renders plain text, no stepper span
                cases = parseInt($row.data('ordered-cases'), 10) || 0;
            }
            csv += R.csvRow([
                String($row.data('sku')),
                $.trim($row.find('td').eq(1).text()),
                String(parseFloat($row.data('adjusted-weekly')) || 0),
                String(parseInt($row.data('stock'), 10) || 0),
                String(caseSize),
                String(cases),
                String(cases * caseSize),
                String($row.find('.mealsdb-po-coverage').attr('data-coverage') || ''),
                $.trim($row.find('td').last().text())
            ]);
        });
        var slug = String(cfg.poNumber || cfg.poId || 'draft').replace(/[^\w.-]+/g, '-');
        R.exportCsv(csv, 'po-' + slug + '-' + new Date().toISOString().slice(0, 10) + '.csv');
    });
```

- [ ] **Step 4: Lint**

```bash
cd /mnt/fastssd/meals-db && php -l views/purchase-orders.php && php -l includes/class-admin-ui.php \
  && node --check assets/js/purchase-orders.js 2>/dev/null || echo "node not available — visually verify JS braces"
```

Expected: `No syntax errors detected` twice; node check passes if node exists.

- [ ] **Step 5: Commit**

```bash
git add includes/class-admin-ui.php views/purchase-orders.php assets/js/purchase-orders.js
git commit -m "feat(po): Export CSV on the draft detail page

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Copy update + full verification

**Files:**
- Modify: `views/purchase-orders.php` (list description, ~line 339)

- [ ] **Step 1: Update the list-page description**

In `views/purchase-orders.php`, replace:

```php
    <p class="description"><?php esc_html_e('Drafts are created from the Purchase Order forecast tab ("Save as draft PO"). Approve locks a draft; Mark received adds it to inventory; Reconcile records what actually arrived.', 'meals-db'); ?></p>
```

with:

```php
    <p class="description"><?php esc_html_e('Drafts are created from the Purchase Order tab ("Generate draft PO") and arrive pallet-optimized. Approve locks a draft; Mark received adds it to inventory; Reconcile records what actually arrived.', 'meals-db'); ?></p>
```

- [ ] **Step 2: Run the full test suite**

```bash
cd /mnt/fastssd/meals-db && fails=0; for f in tests/test-*.php; do \
  out=$(php "$f" 2>&1) || { fails=$((fails+1)); echo "FAIL: $f"; echo "$out" | tail -5; }; done; \
  echo "---"; echo "failing scripts: $fails"
```

Expected: only the two known PDF-test failures (missing mbstring/imagick baseline). Any other failure must be investigated before proceeding.

- [ ] **Step 3: Lint every touched PHP file once more**

```bash
cd /mnt/fastssd/meals-db && for f in includes/ajax/class-ajax-purchase-orders.php \
  includes/ajax/class-ajax-reports.php includes/services/class-reports.php \
  includes/class-admin-ui.php views/purchase-order.php views/purchase-orders.php; do php -l "$f"; done
```

Expected: `No syntax errors detected` for all six.

- [ ] **Step 4: Commit**

```bash
git add views/purchase-orders.php
git commit -m "docs(po): list-page copy matches the one-click draft flow

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
