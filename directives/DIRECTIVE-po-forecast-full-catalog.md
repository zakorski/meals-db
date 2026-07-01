# Directive — PO forecast must cover the full product catalog (mirror the 3-week-buffer simulation)

## Problem (confirmed in code, not suspected)
`MealsDB_Reports::generate_purchase_order()` only ever forecasts products that have order line items in
the trailing 84-day window. A product enters `$products` ONLY if it appears in "Query A" (the recent-sales
query, class-reports.php ~165-206, `WHERE o.date_created_gmt >= today-84d`). Any product with no sales in
that window is ABSENT from the PO entirely — not zero, missing. Result: the PO has far fewer rows than the
catalog, and legitimately-needed items can be omitted just because they had a quiet 12 weeks.

This diverges from the validated back-test simulation, which forecast the ENTIRE product set (every SKU),
computing each one's trailing-12-week recency-weighted average (absent weeks = 0) and ordering
`ceil(adjusted_weekly * 9) - stock`. The simulation never dropped a product for lacking recent sales.

## Goal
Make the live engine mirror the simulation exactly: **forecast every active Apetito product** (all rows in
`meals_products` that are food/orderable), each on its own trailing-12-week history (zero where none),
running the ALREADY-CORRECT 9-week-coverage math. Well-stocked products show order_quantity 0; low-stock
products show real amounts; nothing is dropped for want of recent sales.

DO NOT change the forecasting math (decay 0.85, 12-week trailing, 6+3=9 week coverage, seasonal index,
case rounding, stock subtraction). ONLY change WHICH products are forecast and how their history is
attached.

## Reference (v1.0.482)
- `includes/services/class-reports.php::generate_purchase_order()`:
  - Query A recent-sales (~165-188) builds `$recent_rows`; the loop (~198-206) is the ONLY place products
    enter `$products` — this is the gate to fix.
  - Early return if `$recent_rows` empty (~190-193).
  - Category exclusion (~261-264): `has_term($excluded_cats,...)` unsets excluded products — KEEP.
  - Per-product build loop (~282+) and the `$products[$pid]['weekly_history']` structure it consumes.
  - Prior-year seasonal query B (~211-251) keyed by pid — unchanged, but must tolerate pids with no LY data
    (it already defaults seasonal_index=1.0 when data is insufficient — verify).
- Product universe source: `meals_products` table. `MealsDB_WC_Order_Query::get_product_types_for_ids()`
  (~247-278) already reads `wc_product_id, product_type, case_size` from it — use the same table.
- `MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS)` for the table name.

## Change

### 1. Seed the product universe from the catalog, not from recent sales
Before/independent of Query A, enumerate every eligible product from `meals_products`:
```php
$products_table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
$catalog = $this->wpdb->get_results(
    "SELECT wc_product_id, product_type, case_size
     FROM `{$products_table}`
     WHERE product_type IN ('meal','side')   -- orderable Apetito food products
       AND is_published = 1",                -- exclude delisted/trashed products
    ARRAY_A
);
```
> **Why `is_published = 1` (IMPORTANT):** `meals_products.is_published` (schema `TINYINT(1) DEFAULT 1`) is
> flipped to 0 by `full_sync()`/`set_published()` when a product is trashed or falls out of the allowed
> categories. Without this filter the full-catalog PO would forecast DISCONTINUED products — the old
> recent-sales approach excluded them implicitly (no sales for 12+ weeks). The category-exclusion step
> (`has_term`) does NOT catch this: it filters product_cat, not publish status.

Initialize `$products` from this catalog so EVERY eligible product has an entry with an empty
`weekly_history` (defaults to zero demand):
```php
$products = [];
foreach ($catalog as $row) {
    $pid = (int) $row['wc_product_id'];
    if ($pid <= 0) continue;
    $products[$pid] = [
        'product_name'   => '',            // filled from WC or order history below
        'weekly_history' => [],            // zero until Query A fills weeks
        'case_size'      => (int) $row['case_size'],
    ];
}
```
Decision to confirm with operator if ambiguous: universe = `product_type IN ('meal','side')`. If some
orderable products lack a product_type in meals_products, widen the filter (e.g. also include rows with a
valid case_size) — but do NOT include non-food (fees/containers/gift). The excluded-category step below is
the backstop.

### 2. Query A now ATTACHES history to existing entries (does not create the universe)
Keep Query A exactly as is, but change the fill loop so it only augments products already in the catalog
set, and no longer the sole source:
```php
foreach ($recent_rows as $row) {
    $pid = (int) $row['wc_product_id'];
    if (!isset($products[$pid])) {
        // Sold but not in meals_products catalog (e.g. unsynced) — include it so we don't lose real
        // demand; seed a minimal entry. (Or skip, per operator preference — default: include.)
        $products[$pid] = ['product_name' => (string) $row['product_name'], 'weekly_history' => [], 'case_size' => 0];
    }
    if ($products[$pid]['product_name'] === '') {
        $products[$pid]['product_name'] = (string) $row['product_name'];
    }
    $products[$pid]['weekly_history'][$row['year_week']] = (float) $row['weekly_qty'];
}
```
REMOVE the early `return [];` when `$recent_rows` is empty (line ~190-193) — an empty recent-sales result
must NOT empty the PO now; the catalog still needs forecasting (everything will just have zero recent
demand and order to cover stock gaps).

**Do NOT add an `if (empty($catalog)) return [];` guard (IMPORTANT — this contradicts the fallback above).**
If `meals_products` is empty/unsynced but there ARE recent sales, an `empty($catalog)` guard would throw
away real demand and return an empty PO — a regression, and it breaks `test-purchase-order-3week-buffer.php`
(which stubs the catalog query to `[]` and relies on the sold product arriving via the fallback). Instead,
rely on the **existing** `if (empty($products)) return [];` guard already at ~line 269 (after the
category-exclusion step). That gate fires AFTER both the catalog seed and the Query A fallback have
populated `$products`, so: empty catalog + recent sales → forecasts the sold products; empty catalog + no
sales → returns `[]`. It is the structurally correct gate and makes the seed + fallback coherent.

### 3. Product name fallback
For catalog products with no recent sales, `product_name` is '' after the above. Fill it from WooCommerce
in the build loop (a `wc_get_product($pid)->get_name()` is already fetched there for SKU/stock — reuse it):
set `$product_name = $p['product_name'] !== '' ? $p['product_name'] : ($wc_product ? $wc_product->get_name() : '');`
and use that in the row.

### 4. Keep everything else identical
- Category exclusion (unset excluded cats) stays.
- The weighted-average / seasonal / coverage / stock-subtraction / case-rounding math is UNCHANGED.
- The case_size used in the row: prefer the catalog value already loaded (`$p['case_size']`) with the
  existing meta lookup as fallback — but since Case Count Sync now populates meals_products.case_size,
  the catalog value is authoritative. **CRITICAL — keep the `>= 1` floor (never 0).** Line ~349 computes
  `ceil($units_needed / $case_size)` whenever `units_needed > 0`; a `0` case_size is a division-by-zero.
  The Query A fallback (§2) seeds sold-but-uncatalogued products with `case_size => 0`, so a NAIVE
  `$case_size = $p['case_size']` would divide by zero for such a product with real demand. Resolve with a
  floor, e.g.:
  ```php
  $case_size = (int) $p['case_size'] > 0
      ? (int) $p['case_size']
      : ((isset($meta['case_size']) && (int) $meta['case_size'] > 0)
          ? (int) $meta['case_size']
          : ((int) get_post_meta($pid, 'case_size', true) ?: 1));
  ```
  This preserves the existing `?: 1` floor at line 338-340 — the resolved `$case_size` must never be 0.

## Result (what to expect)
- PO row count ≈ number of PUBLISHED `meal`/`side` products in `meals_products` (minus excluded
  categories) — i.e. the full active catalog (~120+), not "products sold in last 84 days".
- Well-stocked products: order_quantity 0 (correct — don't buy).
- Products low on stock relative to 9-week coverage: real case-rounded order quantities.
- A product that hasn't sold in 12 weeks still appears, with weighted_avg 0 → projected_need 0 →
  orders only if stock is negative/zero (generally 0). This matches the simulation's treatment.
- A DELISTED/trashed product (`is_published = 0`) does NOT appear (filtered by the catalog query).

> **Performance note:** the per-product build loop now runs over the full catalog (~120+) instead of the
> sold subset, so it makes ~120 each of `wc_get_product()`, `has_term()`, and `get_post_meta()` calls per
> PO generation. Acceptable for an on-demand report; if it drags, `has_term`/`wc_get_product` are batchable.
> Leave a comment noting the widened loop. Also note the catalog seed already loads `case_size`, so the
> `get_product_types_for_ids()` re-read of the same column is redundant (kept only as the fallback).

## Verify
```
php -l includes/services/class-reports.php
php tests/test-*.php   # update PO tests that assumed the recent-sales-only universe
```
- Generate a PO: row count now matches the catalog size (all meal/side products), not a small subset.
- A known steady seller with stock < 9 weeks shows a real order_quantity rounded to whole cases using its
  real case_size (12/24/36/48/100), NOT 1.
- A well-stocked product shows order_quantity 0 but STILL APPEARS as a row with its case_size.
- A product with no sales in 84 days APPEARS (previously absent).
- A product with `is_published = 0` does NOT appear even if it has a `meal`/`side` product_type.
- Cross-check one product against the simulation math by hand:
  ceil(weighted_avg * seasonal * 9) - (current_stock + future_inv), rounded up to whole cases.
- Re-run the EXISTING `test-purchase-order-3week-buffer.php` — it must stay GREEN. It stubs the catalog
  query to `[]`, so its product 555 now arrives via the Query A fallback and the retained
  `if (empty($products)) return [];` guard lets it through. If it goes red, the empty-guard was changed
  incorrectly (see §2).

## Test to add
`tests/test-po-full-catalog.php` (model the wpdb stub on `test-purchase-order-3week-buffer.php`, which
distinguishes the recent-sales query from others by substring — your stub must additionally answer the
catalog query `SELECT ... FROM ...meals_products WHERE product_type IN ('meal','side') AND is_published`):
seed meals_products with 3 published products (2 sold recently, 1 not sold in
84 days), give each a case_size; assert generate_purchase_order() returns THREE rows (the unsold one
present with weighted_avg 0), each carrying its real case_size, with order_quantity computed on 9-week
coverage minus stock. Add cases for the three corrections:
- **is_published:** seed a 4th product with `is_published = 0` (meal/side, with recent sales even); assert
  it is ABSENT from the result (the catalog query excludes it).
- **case_size floor:** for a sold-but-uncatalogued product (present in recent sales, ABSENT from the
  catalog stub) with real demand, assert generate_purchase_order() does NOT divide-by-zero and the row's
  `case_size >= 1` (falls back to the `?: 1` floor).
- **empty guard:** with an empty catalog stub but non-empty recent sales, assert the PO still contains the
  sold product(s) (proves the `empty($products)` guard is used, not `empty($catalog)`).
