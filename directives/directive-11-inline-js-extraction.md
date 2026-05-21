# Directive: Extract Inline JavaScript Blobs to Asset Files

**Severity:** LOW (STRUCT-8 from synthesis)
**Audit reference:** `recon-07-admin-ui.md`; `recon-09-synthesis.md` STRUCT-8
**Target files:** `includes/class-admin-ui.php`, `includes/class-wc-product-tab.php`; new files in `assets/js/`
**Estimated scope:** ~200 lines moved (not deleted)
**Risk:** LOW — pure extraction, no logic change
**Must complete before:** v1.1 release (not pre-cutover blocking)

---

## Context

Two large inline JavaScript blocks live inside PHP files:

1. **`class-admin-ui.php` lines ~1599-1737**: 161 lines of inline JS for the client allocation history widget. Mixed PHP/JS context using `esc_js(__('...', 'meals-db'))` for i18n.

2. **`class-wc-product-tab.php` line ~94**: ~30 lines of inline JS for tax-field synchronization when product type changes between meal/side.

Both are functional but have downsides:
- Not cacheable independently (changes to PHP version-bust the inline script).
- Cannot be CSP-tightened (would require `'unsafe-inline'` which weakens the whole policy).
- Hard to maintain (PHP/JS context switching, escape rules).
- Cannot be linted by JS tooling.

Extracting them to proper JS files in `assets/js/` is straightforward. The existing JS-enqueue infrastructure supports this cleanly.

This directive handles both extractions in separate Parts. Each is independent.

---

## Part A: Client allocation history widget (`class-admin-ui.php`)

### Pre-flight verification

```bash
# Confirm the inline script block exists
grep -n "<script>" includes/class-admin-ui.php | head -10
grep -n "allocation_history\|alloc_history" includes/class-admin-ui.php | head -10
```

Locate the `<script>` block within `render_client_form()`. Read the full block. Document:
- The script's purpose (what it does in the DOM).
- All `esc_js(__('...', 'meals-db'))` translated strings — these become a separate data payload.
- The AJAX endpoint(s) it calls (likely `mealsdb_get_client_allocation_history`).
- The nonce(s) it uses.
- Any client_id, user_id, or other context the PHP passes in.

### Step F1: Create the JS file

Create `assets/js/client-allocation-history.js`:

```javascript
/**
 * Client Allocation History widget.
 *
 * Renders the per-client monthly allocation history table on the
 * Edit Client admin page. Extracted from inline PHP-generated JS
 * to keep the PHP file readable and enable CSP tightening.
 *
 * Expects window.mealsdbAllocationHistory to be set by the PHP
 * enqueue:
 *   {
 *     clientId:  int,        // meals_clients.client_id
 *     ajaxUrl:   string,
 *     nonce:     string,
 *     i18n: {
 *       loading:        string,
 *       loadFailed:     string,
 *       noHistory:      string,
 *       month:          string,
 *       mainsPermitted: string,
 *       mainsUsed:      string,
 *       sidesPermitted: string,
 *       sidesUsed:      string,
 *       overage:        string,
 *       status:         string,
 *       expand:         string,
 *       collapse:       string
 *     }
 *   }
 */
(function () {
    'use strict';

    if (typeof window.mealsdbAllocationHistory === 'undefined') {
        // Not on a page that needs this widget.
        return;
    }

    var config = window.mealsdbAllocationHistory;

    // Defensive: confirm the container exists.
    var container = document.getElementById('mealsdb-allocation-history');
    if (!container) {
        return;
    }

    // <... insert the existing JS logic from the PHP block ...>

    // Helpers — kept inside the IIFE for scope isolation.
    function escHtml(s) {
        // ... existing implementation
    }

    function intText(n) {
        // ... existing implementation
    }

    function loadHistory() {
        // ... existing AJAX call and table rendering
    }

    // Initial load
    loadHistory();
})();
```

**Important conversion notes**:
- Replace `<?php echo esc_js(__('Loading...', 'meals-db')); ?>` patterns with `config.i18n.loading` (configured from PHP via `wp_localize_script` or `wp_add_inline_script`).
- Replace `<?php echo (int) $client_id; ?>` with `config.clientId`.
- Replace `<?php echo wp_create_nonce('mealsdb_nonce'); ?>` with `config.nonce`.

**Do NOT** introduce jQuery if the original JS doesn't use it. Match the existing pattern. If the original uses `jQuery(function($) { ... })`, keep that. If it uses vanilla DOM, keep that.

### Step F2: Update the PHP to enqueue the JS file

In `class-admin-ui.php`, locate where the inline `<script>` is currently echoed within `render_client_form()`. Replace the entire `<script>` block with NOTHING (delete it).

In the appropriate `enqueue_assets` method (likely already exists for the edit-client page), add:

```php
// Allocation history widget — only enqueued on edit-client view.
wp_register_script(
    'mealsdb-allocation-history',
    plugins_url('assets/js/client-allocation-history.js', MEALS_DB_PLUGIN_FILE),
    [], // no jquery dep unless the source uses it
    MEALS_DB_VERSION,
    true // load in footer
);

wp_localize_script('mealsdb-allocation-history', 'mealsdbAllocationHistory', [
    'clientId' => (int) $client_id,
    'ajaxUrl'  => admin_url('admin-ajax.php'),
    'nonce'    => wp_create_nonce('mealsdb_nonce'),
    'i18n'     => [
        'loading'        => __('Loading allocation history...', 'meals-db'),
        'loadFailed'     => __('Failed to load allocation history.', 'meals-db'),
        'noHistory'      => __('No allocation history available.', 'meals-db'),
        'month'          => __('Month', 'meals-db'),
        'mainsPermitted' => __('Mains Permitted', 'meals-db'),
        'mainsUsed'      => __('Mains Used', 'meals-db'),
        'sidesPermitted' => __('Sides Permitted', 'meals-db'),
        'sidesUsed'      => __('Sides Used', 'meals-db'),
        'overage'        => __('Overage', 'meals-db'),
        'status'         => __('Status', 'meals-db'),
        'expand'         => __('Show details', 'meals-db'),
        'collapse'       => __('Hide details', 'meals-db'),
    ],
]);

wp_enqueue_script('mealsdb-allocation-history');
```

**Gating note**: only enqueue this on the edit-client page. The existing `enqueue_assets` method has page detection (`if ($hook === 'toplevel_page_mealsdb' && $tab === 'clients' && $action === 'edit')`). Match that gate.

### Step F3: Verify the script can find the container

In the PHP template `render_client_form()`, ensure the `<div id="mealsdb-allocation-history">` (or whatever ID the existing inline script targeted) is present in the rendered HTML. Don't delete it during extraction.

### Testing for Part A

```bash
php -l includes/class-admin-ui.php
# JS lint via the project's lint setup if any; otherwise skip.
```

Functional test:

> **Manual test required:**
> 1. Navigate to Meals DB → Clients → Edit a client.
> 2. Scroll to the Allocation History section.
> 3. Verify the table populates (the AJAX call fires and renders rows).
> 4. Click an "Show details" link on one of the rows.
> 5. Verify per-month details expand.
> 6. Open browser DevTools → Network. Verify:
>    - `client-allocation-history.js` is loaded (with correct version query string).
>    - The AJAX call to `mealsdb_get_client_allocation_history` succeeds.
> 7. Confirm no inline `<script>` tags in the page source (View Source).
> 8. Confirm no JS errors in the browser console.

---

## Part B: Tax field synchronization (`class-wc-product-tab.php`)

### Pre-flight verification

```bash
grep -n "wp_add_inline_script\|<script>" includes/class-wc-product-tab.php
```

Locate the inline JS at ~line 94. Read it. Document:
- The script's purpose (sync taxable + tax_status fields when product type changes).
- The DOM selectors it uses (likely WC's product-data tabs).
- Any data the PHP passes in (unlikely, since this is a small UI sync).

### Step F1: Create the JS file

Create `assets/js/wc-product-tab-tax-sync.js`:

```javascript
/**
 * Sync the WooCommerce taxable / tax_status / tax_class fields based
 * on the Meals DB product_type (meal vs side) selection in the
 * product editor.
 *
 * Rules:
 *   - product_type = 'meal'    → force taxable = false, tax_status = 'none',
 *                                disable tax_status and tax_class inputs.
 *   - product_type = 'side', taxable = true  → tax_status = 'taxable'
 *   - product_type = 'side', taxable = false → tax_status = 'none'
 *
 * The server-side save handler enforces the same rules independently
 * (defense in depth — JS can be bypassed by a determined user).
 *
 * Extracted from inline PHP-generated JS.
 */
(function ($) {
    'use strict';

    if (typeof $ === 'undefined') {
        return;
    }

    $(document).ready(function () {
        var typeSelect = $('#_mealsdb_product_type');
        var taxableCheckbox = $('#_mealsdb_taxable');
        var wcTaxStatus = $('#_tax_status');
        var wcTaxClass = $('#_tax_class');

        function syncTaxFields() {
            var type = typeSelect.val();
            var taxable = taxableCheckbox.is(':checked');

            if (type === 'meal') {
                taxableCheckbox.prop('checked', false).prop('disabled', true);
                wcTaxStatus.val('none').prop('disabled', true);
                wcTaxClass.prop('disabled', true);
            } else {
                // type === 'side' (or any non-meal)
                taxableCheckbox.prop('disabled', false);
                wcTaxStatus.prop('disabled', false);
                wcTaxClass.prop('disabled', false);
                wcTaxStatus.val(taxable ? 'taxable' : 'none');
            }
        }

        typeSelect.on('change', syncTaxFields);
        taxableCheckbox.on('change', syncTaxFields);

        // Apply on page load to ensure consistent state.
        syncTaxFields();
    });
})(jQuery);
```

Adjust selectors to match the actual IDs in the existing inline JS. Read the PHP file carefully to extract the right ones.

### Step F2: Update the PHP to enqueue the JS

In `class-wc-product-tab.php`, locate the inline JS block. Delete it.

In the same class's init/setup method (or a new method called from `init`), add an enqueue:

```php
/**
 * Enqueue the tax-field sync JS on the WC product edit screen.
 */
public static function enqueue_product_tab_assets($hook): void {
    // Only on product edit pages.
    global $post;
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
        return;
    }
    if (!$post || $post->post_type !== 'product') {
        return;
    }

    wp_enqueue_script(
        'mealsdb-wc-product-tab-tax-sync',
        plugins_url('assets/js/wc-product-tab-tax-sync.js', MEALS_DB_PLUGIN_FILE),
        ['jquery'],
        MEALS_DB_VERSION,
        true
    );
}
```

Register this method on `admin_enqueue_scripts`:

```php
add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_product_tab_assets']);
```

Add this to the class's existing `init()` method if there is one; otherwise add it as a static initializer.

### Testing for Part B

```bash
php -l includes/class-wc-product-tab.php
```

Functional test:

> **Manual test required:**
> 1. Navigate to Products → Add New Product.
> 2. Click the "Meals DB Data" tab.
> 3. Switch the product type to "Meal".
> 4. Verify:
>    - The "Taxable" checkbox becomes disabled and unchecked.
>    - The Tax Status field (in WC's "General" or "Tax" tab) becomes disabled and set to "None".
>    - The Tax Class field becomes disabled.
> 5. Switch back to "Side".
> 6. Verify the fields become re-enabled.
> 7. Check the "Taxable" checkbox.
> 8. Verify Tax Status changes to "Taxable".
> 9. Uncheck "Taxable".
> 10. Verify Tax Status changes back to "None".
> 11. Save the product. Verify the saved values match the UI state (server-side validation works independently).
> 12. View page source — no inline `<script>` for tax sync should appear.
> 13. No JS errors in browser console.

---

## Out of scope for this directive

- Do NOT change the server-side tax-field logic. The `save_product_data` method already enforces the same rules independently. That's the defense-in-depth and stays.
- Do NOT introduce a JS framework (React, Vue) for these widgets. They're small enough that vanilla / jQuery is appropriate.
- Do NOT extract every inline `<script>` in the codebase — only the two flagged in the audit as large enough to warrant extraction. Smaller inline scripts (a few lines) can stay inline.
- Do NOT modify the AJAX endpoint `mealsdb_get_client_allocation_history` itself; only the JS that calls it.

---

## Acceptance criteria

The directive is complete when:

**Part A:**
1. ✅ `assets/js/client-allocation-history.js` exists with the extracted logic.
2. ✅ The inline `<script>` block is removed from `class-admin-ui.php`.
3. ✅ `wp_register_script` + `wp_localize_script` + `wp_enqueue_script` are wired in `enqueue_assets`.
4. ✅ The enqueue is gated to the edit-client page only.
5. ✅ All i18n strings are passed via `wp_localize_script` payload.
6. ✅ Manual test (T-A) passes.

**Part B:**
7. ✅ `assets/js/wc-product-tab-tax-sync.js` exists with the extracted logic.
8. ✅ The inline `<script>` block is removed from `class-wc-product-tab.php`.
9. ✅ `wp_enqueue_script` is wired via `admin_enqueue_scripts` action.
10. ✅ The enqueue is gated to product edit pages.
11. ✅ Manual test (T-B) passes.

**Both:**
12. ✅ `php -l` passes on all modified files.
13. ✅ No inline `<script>` for these widgets remains in the PHP source.
14. ✅ The pages function identically to before (no behavioral regression).

When complete, your final response should include:
- The new JS file paths and a summary of their contents.
- Diffs of the PHP files showing the inline block removal and the enqueue addition.
- Manual test results.
