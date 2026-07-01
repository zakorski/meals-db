# Directive — Add a repeat-safe "Case Count Sync" button (legacy case_size → meals_products.case_size)

## Problem
Real per-product case sizes exist in postmeta under the BARE key `case_size` (legacy/imported data).
The plugin's canonical store for case size is the **`meals_products.case_size` COLUMN** — populated ONLY
from the product-tab save (`MealsDB_WC_Product_Tab::save_product_data()` reads `$_POST['_mealsdb_case_size']`
and writes the column via `MealsDB_Products::save_product_data()`). **IMPORTANT:** `_mealsdb_case_size` is
only the WooCommerce form-field NAME — the plugin NEVER persists it as postmeta (`grep update_post_meta …
case_size` is empty), and the product tab reads its displayed value back from the COLUMN, not from any
postmeta. So the column is the single source of truth, and the legacy bare `case_size` postmeta has never
been migrated into it. Result: `meals_products.case_size` is stuck at the default `1`, so the PO's
resolution (`reports.php`: `column > 0 ? column : (legacy case_size ?: 1)`) always uses the column's `1`
and never falls back — Purchase Orders show case quantity 1 and the cases/order-qty math is wrong. The
existing "Sync Products" button does NOT sync case_size (display fields + type/taxable only), so it can't
fix this.

## Goal
Add a new Data Ops button **"Case Count Sync"** that bridges the legacy data: for every product, if
`meals_products.case_size` is still the default (`<= 1`) and a positive legacy `case_size` meta exists,
set the COLUMN to that legacy value. The `meals_products.case_size` column is BOTH the thing read (to
decide whether to act) AND the thing written (the canonical store). Do NOT key the decision off the
`_mealsdb_case_size` postmeta — it is virtually always empty (see Problem), so using it as the guard would
re-fill on every run and could overwrite a column that was already set correctly via the product tab.
Must be:
- **Repeat-safe (idempotent):** running it twice changes nothing the second time; safe to click anytime.
- **Non-destructive:** never overwrites a `meals_products.case_size` that already holds a real value
  (`> 1`), never lowers/zeroes a value, never deletes the legacy `case_size` meta, never touches products
  that already have a correct case size. It only FILLS IN the default.

## Reference (mirror these existing patterns exactly — v1.0.480)
- Button markup: `views/data-ops.php` ~lines 230–238 (the "Sync Product Display Data" / "Sync Products"
  button: `<button id="mealsdb-sync-products">` + a `<span id="...-result">`).
- AJAX handler pattern: `includes/class-product-display-sync.php::ajax_full_sync()` (~145): nonce
  (`mealsdb_nonce`) → capability (`MealsDB_Permissions::required_capability()`) → rate limit
  (`MealsDB_Rate_Limiter::check_rate_limit('settings_modify')`) → do work → `wp_send_json([...])`.
- Catalog walk: `full_sync()` (~192): paginated `wc_get_products(['status'=>'publish','category'=>
  MealsDB_Quick_Order_Products::get_allowed_category_slugs(), ...])` in pages of 100.
- The write into meals_products: `MealsDB_Products::save_product_data()` / the upsert in
  `includes/class-products.php` (writes the `case_size` column; reads its value from product data).
- JS handler pattern: `assets/js/settings.js` ~328 (`#mealsdb-sync-products` click → `$.post` with
  `action` + `nonce: nonces.general` → show result text).
- AJAX action registration: alongside `add_action('wp_ajax_mealsdb_sync_product_display', ...)`
  (`class-product-display-sync.php` ~38).

## Implementation

### 1. New AJAX handler — `ajax_case_count_sync()`
Add to a sensible home (either `class-product-display-sync.php` next to `ajax_full_sync`, or a small new
`class-case-count-sync.php`; prefer next to the existing sync for cohesion). Register:
```php
add_action('wp_ajax_mealsdb_case_count_sync', [self::class, 'ajax_case_count_sync']);
```
Handler structure (copy the guard stack from `ajax_full_sync` verbatim — nonce `mealsdb_nonce`,
capability, rate-limit `settings_modify`), then call the worker and return counts:
```php
$result = self::case_count_sync(); // ['scanned'=>N,'filled'=>N,'already_ok'=>N,'no_legacy'=>N,'failed'=>N]
wp_send_json([
    'success' => true,
    'message' => sprintf(
        /* translators: counts */ __('Case Count Sync complete: %1$d products scanned, %2$d filled from legacy data, %3$d already correct, %4$d had no legacy value, %5$d failed to write.', 'meals-db'),
        $result['scanned'], $result['filled'], $result['already_ok'], $result['no_legacy'], $result['failed']
    ),
]);
```

### 2. The worker — `case_count_sync(): array`  (idempotent, non-destructive)
Walk the published product catalog the SAME way `full_sync()` does (paginated `wc_get_products`, same
category filter). For each product, read the CURRENT value from the canonical store — the
`meals_products.case_size` COLUMN via `MealsDB_Products::get_product_data()` — NOT from the
`_mealsdb_case_size` postmeta (which is virtually always empty; see Problem). The column is BOTH the guard
and the write target:
```php
$pid      = $product->get_id();
$existing = MealsDB_Products::get_product_data($pid);     // canonical row; case_size from the COLUMN
$current  = (int) ($existing['case_size'] ?? 1);          // the REAL current case size
$legacy   = (int) get_post_meta($pid, 'case_size', true); // legacy bare key (READ ONLY)
$scanned++;

if ($current > 1) {
    // Column already holds a real (non-default) case size -> leave it ALONE.
    $already_ok++;
} elseif ($legacy > 1) {
    // Column is at the default (<= 1) and legacy has a real value -> fill the COLUMN from
    // legacy, reusing the product-tab upsert so every column stays consistent. Do NOT touch
    // the legacy `case_size` meta (leave the source intact).
    $ok = MealsDB_Products::save_product_data($pid, array_merge($existing, ['case_size' => $legacy]));
    if ($ok) {
        $filled++;
    } else {
        $failed++; // upsert refused (e.g. capability) — do NOT report a phantom success
    }
} else {
    $no_legacy++;  // nothing to copy; leave everything as-is
}
```
IMPORTANT idempotency/safety rules:
- The guard reads the COLUMN (`get_product_data()['case_size']`), the single source of truth — NOT the
  `_mealsdb_case_size` postmeta. This is what makes it safe: after a first successful run the column holds
  the real value (`> 1`), so a second run hits `$already_ok` and writes nothing — idempotent. Keying off
  the (empty) postmeta instead would re-fill every run AND could OVERWRITE a column that was already set
  correctly via the product tab with a stale/differing legacy value — the destructive case this rule
  exists to prevent.
- Treat `<= 1` as "default / not really set" for the purpose of FILLING. NEVER write when `$legacy <= 1`;
  and because the fill branch only fires when `$current <= 1`, the upsert can never LOWER a real value.
- NEVER delete or modify the legacy `case_size` meta (non-destructive; leave the source intact).
- `save_product_data()` returns `bool` and re-checks `edit_product`/`manage_woocommerce` internally. Only
  count `$filled` on a `true` return; count `$failed` otherwise. (A `manage_options`-only operator passes
  the AJAX gate — `required_capability()` whitelists it — but would fail this internal cap, so surface the
  failure rather than reporting a false success.)

### 3. The COLUMN write IS the propagation (no separate step)
There is no "fill the meta, then propagate" split: the worker writes the canonical `meals_products.case_size`
COLUMN directly via `save_product_data()` (§2). The PO and every other consumer
(`class-wc-order-query.php`, `class-products-loader.php`, `class-quick-order-products.php`) read that
column, so writing it fixes them all at once — which is exactly why the data-fix beats patching the PO's
fallback. Reuse the upsert (do NOT hand-write SQL) so `last_updated` etc. behave normally;
`array_merge($existing, ['case_size'=>$legacy])` preserves the row's other fields. Idempotent: writing the
same value via the upsert is a no-op change.
- EDGE: if a product has no `meals_products` row yet, `get_product_data()` returns defaults and the upsert
  INSERTs a cost-only row (empty display fields). In practice products in scope are display-synced first;
  if you want to be strict, run/require the display sync first or skip products with no existing row.
- OPTIONAL: you MAY also `update_post_meta($pid, '_mealsdb_case_size', $legacy)` in the `$filled` branch
  for tidiness, but it is bookkeeping only — nothing in the plugin reads that postmeta — and it must NEVER
  be the guard.

NOTE on legitimate case size of 1: if any product legitimately has case_size 1, this logic can't
distinguish it from "unset default 1". That's acceptable here because (a) the back-test showed real case
sizes are 12/24/36/48/100 — 1 is the default, not a real case — and (b) the operation is non-destructive
so a true-1 product simply stays 1. State this assumption in a code comment.

### 4. Button markup — `views/data-ops.php`
Add directly AFTER the existing "Sync Product Display Data" block, mirroring it:
```php
<h2><?php echo esc_html__( 'Case Count Sync', 'meals-db' ); ?></h2>
<p class="description">
    <?php echo esc_html__( 'Fills in product case sizes from legacy data and propagates them to the products table used by Purchase Orders. Safe to run repeatedly; it only fills missing values and never overwrites or deletes existing data.', 'meals-db' ); ?>
</p>
<p>
    <button type="button" class="button" id="mealsdb-case-count-sync">
        <?php echo esc_html__( 'Case Count Sync', 'meals-db' ); ?>
    </button>
    <span id="mealsdb-case-count-sync-result" style="margin-left:12px;"></span>
</p>
```

### 5. JS — `assets/js/settings.js`
Add a handler mirroring the `#mealsdb-sync-products` one (~line 328):
```js
$('#mealsdb-case-count-sync').on('click', function () {
    var $btn = $(this), $result = $('#mealsdb-case-count-sync-result');
    $btn.prop('disabled', true);
    $result.text('Syncing case counts...'); tint($result, '#666');
    $.post(ajaxUrl, { action: 'mealsdb_case_count_sync', nonce: nonces.general || '' }, function (resp) {
        $btn.prop('disabled', false);
        if (resp && resp.success) { $result.text(resp.message || 'Done.'); tint($result, '#46b450'); }
        else { $result.text((resp && resp.message) || 'Sync failed.'); tint($result, '#dc3232'); }
    }).fail(function () {
        $btn.prop('disabled', false);
        $result.text('Request failed.'); tint($result, '#dc3232');
    });
});
```

## Out of scope (do NOT change here)
- Do NOT alter the PO engine math, the "Sync Products" (display) button, or the product tab save path.
- Do NOT migrate/rename the legacy `case_size` meta destructively — only READ it.
- (Optional, mention only) The PO's fallback `... case_size > 0 ? primary : get_post_meta($pid,'case_size')`
  treats `1` as valid and never falls back — note it as a separate latent issue, but the Case Count Sync
  fixes the data so it no longer bites. Leave the PO code unchanged unless you choose to harden it.

## Verify
```
php -l (every changed/added PHP file)
php tests/test-*.php
```
- Before: `SELECT wc_product_id, case_size FROM 2xnIt_meals_products WHERE case_size > 1` → empty.
- Click **Case Count Sync** on Data Ops. Result message reports products filled + already-correct.
- After: same SELECT now returns the real case sizes (12/24/36/48/100) in `meals_products.case_size`; the
  legacy `case_size` meta is UNCHANGED (still present). (`_mealsdb_case_size` postmeta is NOT required — the
  column is the source of truth.)
- Generate a NEW Purchase Order → Cases and Order Qty now use real case sizes (orders round to whole
  cases), not 1.
- Click **Case Count Sync** AGAIN → result reports 0 newly filled / all "already correct" (idempotent);
  no values changed.
- A product with no legacy case_size stays at default; a product with an already-correct
  `meals_products.case_size` (`> 1`) is left untouched.

## Suggested test (add)
`tests/test-case-count-sync.php`:
- **Fill from legacy:** seed a product whose `meals_products.case_size` is the default `1` and a legacy
  `case_size=24` meta; run the worker; assert the COLUMN `meals_products.case_size`==24 (the source of
  truth) and the legacy meta is UNCHANGED (still 24). Run the worker AGAIN; assert no change and the
  `already_ok` path is taken (idempotent).
- **Destructive-case guard (the bug the column-keyed worker prevents):** seed a product whose
  `meals_products.case_size` is ALREADY a real value (e.g. 36) AND a DIFFERING legacy `case_size=24`; run
  the worker; assert the column STAYS 36 (counted as `already_ok`, never lowered to 24).
- **No legacy:** seed a product with no legacy `case_size`; assert the column stays default and it counts
  as `no_legacy`.
