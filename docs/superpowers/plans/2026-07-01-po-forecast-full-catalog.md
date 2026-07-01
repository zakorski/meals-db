# PO Forecast Full-Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `MealsDB_Reports::generate_purchase_order()` forecast every published `meal`/`side` product in `meals_products` (each on its own trailing-12-week history, zero where none), instead of only products with sales in the trailing 84 days — mirroring the validated back-test simulation.

**Architecture:** Seed the product universe from a catalog query against `meals_products` (`product_type IN ('meal','side') AND is_published = 1`) so every eligible product has an entry with empty `weekly_history`. Query A (recent sales) then only ATTACHES weekly history to those entries (and still includes any sold-but-uncatalogued product). The forecasting math is UNCHANGED — it already yields `weighted_avg = 0` for empty history with no divide-by-zero. A `case_size >= 1` floor is preserved so a fallback-seeded product can never divide by zero.

**Tech Stack:** PHP 8.2 / WordPress (HPOS) / WooCommerce, `$wpdb`, bespoke `php tests/test-*.php` harness (no PHPUnit).

**Source directive:** `directives/DIRECTIVE-po-forecast-full-catalog.md` (patched — keep the existing `empty($products)` guard, filter `is_published = 1`, preserve the `case_size >= 1` floor).

**Key facts verified against the code (do not re-derive):**
- The ONLY place products enter `$products` today is the Query A recent-sales loop (`class-reports.php:198-206`), gated by `WHERE o.date_created_gmt >= today-84d` — this is the bug.
- The early `if (!is_array($recent_rows) || empty($recent_rows)) return [];` is at `:192-194`.
- There is ALREADY a `if (empty($products)) return [];` guard at `:269-271` (after category exclusion). **Keep it; rely on it. Do NOT add an `empty($catalog)` guard** (it would drop real demand when the catalog is empty-but-sold, and would break the existing test).
- The forecasting math (`:283-350`) handles empty `weekly_history`: the weight loop skips, the backfill loop (`:297-299`) keeps `weight_sum > 0`, so `weighted_avg = 0`. No change needed.
- Seasonal Query B (`:308-327`) tolerates missing pids → `seasonal_index = 1.0`. No change needed.
- Category exclusion via `has_term` (`:261-267`) stays.
- Schema: `meals_products` has `is_published TINYINT(1) DEFAULT 1` (`class-schema.php:123`), `product_type ENUM('meal','side','fee','other')` (`:124`), `case_size INT DEFAULT 1` (`:129`).
- `case_size` resolution today is `$meta['case_size'] > 0 ? ... : (get_post_meta($pid,'case_size') ?: 1)` (`:337-340`) — a floor of 1. Line `:349` divides `$units_needed / $case_size`.
- Table name via `MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS)` (same call `MealsDB_WC_Order_Query::get_product_types_for_ids()` uses).
- The existing test `tests/test-purchase-order-3week-buffer.php` stubs the catalog query to `[]` (its `PoWpdb::get_results` only answers the query containing `order_item_name AS product_name`), so its product 555 will arrive via the Query A fallback and pass through the retained `empty($products)` guard — it must stay GREEN after this change.

---

## File Structure

| File | Change |
|---|---|
| `includes/services/class-reports.php` | In `generate_purchase_order()`: seed `$products` from the catalog; change Query A to attach history; remove the early empty-`$recent_rows` return; add the `product_name` fallback; add the `case_size >= 1` floor. Nothing else. |
| `tests/test-po-full-catalog.php` | New TDD test: full-catalog universe, the empty-catalog guard, and the case_size floor. |

**No other file changes.** The math, CSV export, category exclusion, seasonal logic, and `get_product_types_for_ids()` are untouched.

---

## Task 1: Full-catalog forecasting (TDD, single-method change)

**Files:**
- Create: `tests/test-po-full-catalog.php`
- Modify: `includes/services/class-reports.php` (`generate_purchase_order()`, ~`:162-207`, `:192-194`, `:337-367`)

### Step 1: Write the failing test

Create `tests/test-po-full-catalog.php` with exactly this content. It stubs `$wpdb` to answer three query shapes (catalog / recent-sales / everything-else) and drives the real `generate_purchase_order()`. Modeled on `tests/test-purchase-order-3week-buffer.php`.

```php
<?php
/**
 * Tests for the full-catalog PO forecast (DIRECTIVE-po-forecast-full-catalog).
 *
 * generate_purchase_order() must forecast EVERY published meal/side product in
 * meals_products — not just products sold in the trailing 84 days.
 *
 *   FC-1  full catalog: 3 published products (2 sold, 1 unsold) → THREE rows;
 *         the unsold product present with weighted_avg 0 and its catalog case_size
 *   FC-2  each row carries its real catalog case_size (24 / 12 / 36)
 *   FC-3  sold products order on 9-week coverage (units 70): case 24→qty 72, case 12→qty 72
 *   FC-4  unsold product: order_quantity 0 but STILL a row
 *   FC-5  empty-catalog guard: catalog []=empty but recent sales present → sold product STILL forecast
 *         (proves the empty($products) guard is used, not empty($catalog))
 *   FC-6  case_size floor: sold-but-uncatalogued product with no case_size meta → case_size 1,
 *         no divide-by-zero (units 70 / case 1 → qty 70)
 *   FC-7  source: the catalog query filters is_published = 1
 *
 * Run: php tests/test-po-full-catalog.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id(): int { return 0; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(): bool { return true; } }
if (!function_exists('current_user_can')) { function current_user_can(string $c): bool { return true; } }
if (!function_exists('apply_filters')) { function apply_filters(string $t, $v, ...$a) { return $v; } }
if (!function_exists('get_option')) {
    // Empty excluded-category list → no category exclusion, has_term never called.
    function get_option(string $name, $default = false) { return $name === 'mealsdb_appetito_excluded_categories' ? [] : $default; }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($id, string $key, bool $single = false) {
        // No legacy case_size meta (so FC-6's floor is exercised); no future inventory.
        if ($key === '_future_inventory_quantity') { return 0; }
        return '';
    }
}
class FcFakeProduct {
    private int $id;
    public function __construct(int $id) { $this->id = $id; }
    public function get_sku(): string { return 'SKU' . $this->id; }
    public function get_name(): string { return 'WC Name ' . $this->id; }
    public function get_stock_quantity(): int { return 20; }
}
if (!function_exists('wc_get_product')) { function wc_get_product($id) { return new FcFakeProduct((int) $id); } }
if (!function_exists('has_term')) { function has_term($t, $tax, $id) { return false; } }

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $output = OBJECT) { return []; }
        public function get_row($query, $output = OBJECT, $y = 0) { return null; }
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function query($query) { return 0; }
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

/**
 * Query-shape-aware wpdb stub.
 *  - Catalog seed  → query contains 'is_published'      → returns $GLOBALS['FC_CATALOG']
 *  - Recent sales  → query contains 'order_item_name'   → weekly rows for $GLOBALS['FC_SOLD']
 *  - Everything else (LY seasonal, product-type lookup) → []
 */
class FcWpdb extends wpdb {
    public function __construct() { $this->prefix = 'wp_'; }
    public function prepare($query, ...$args) { return $query; }
    public function get_results($query, $output = ARRAY_A) {
        if (strpos($query, 'is_published') !== false) {
            return $GLOBALS['FC_CATALOG'];
        }
        if (strpos($query, 'order_item_name AS product_name') !== false) {
            $rows = [];
            foreach ($GLOBALS['FC_SOLD'] as $pid) {
                for ($w = 1; $w <= 12; $w++) {
                    $rows[] = [
                        'wc_product_id' => $pid,
                        'product_name'  => 'Sold ' . $pid,
                        'year_week'     => sprintf('2026%02d', $w),
                        'weekly_qty'    => 10.0,
                    ];
                }
            }
            return $rows;
        }
        return [];
    }
    public function get_row($query, $output = OBJECT, $y = 0) { return null; }
    public function get_var($query, $x = 0, $y = 0) { return null; }
    public function query($query) { return 0; }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function chk_true($c, $l) { chk((bool) $c, true, $l); }
function fc_row_by_sku(array $rows, string $sku) {
    foreach ($rows as $r) { if (($r['sku'] ?? '') === $sku) { return $r; } }
    return null;
}

// ---- Scenario 1: full catalog (FC-1..FC-4) ----
// Catalog has 3 published products; only 101 and 102 sold recently. 103 unsold.
$GLOBALS['FC_CATALOG'] = [
    ['wc_product_id' => 101, 'product_type' => 'meal', 'case_size' => 24],
    ['wc_product_id' => 102, 'product_type' => 'side', 'case_size' => 12],
    ['wc_product_id' => 103, 'product_type' => 'meal', 'case_size' => 36],
];
$GLOBALS['FC_SOLD'] = [101, 102];
$GLOBALS['wpdb'] = new FcWpdb();
$reports = new MealsDB_Reports($GLOBALS['wpdb']);
$rows = $reports->generate_purchase_order();

chk(is_array($rows) ? count($rows) : -1, 3, 'FC-1 three rows (unsold product included)');
$r101 = fc_row_by_sku($rows, 'SKU101');
$r102 = fc_row_by_sku($rows, 'SKU102');
$r103 = fc_row_by_sku($rows, 'SKU103');
chk_true($r101 && $r102 && $r103, 'FC-1 all three products present');
chk($r101['case_size'] ?? null, 24, 'FC-2 pid101 catalog case_size 24');
chk($r102['case_size'] ?? null, 12, 'FC-2 pid102 catalog case_size 12');
chk($r103['case_size'] ?? null, 36, 'FC-2 pid103 catalog case_size 36');
chk($r101['order_quantity'] ?? null, 72, 'FC-3 pid101 order_quantity 72 (ceil(70/24)*24)');
chk($r102['order_quantity'] ?? null, 72, 'FC-3 pid102 order_quantity 72 (ceil(70/12)*12)');
chk($r103['weighted_avg_weekly'] ?? null, 0, 'FC-4 unsold pid103 weighted_avg 0');
chk($r103['order_quantity'] ?? null, 0, 'FC-4 unsold pid103 order_quantity 0');
chk($r103['product_name'] ?? null, 'WC Name 103', 'FC-4 unsold pid103 name from WC fallback');

// ---- Scenario 2: empty catalog + recent sales (FC-5, FC-6) ----
// Catalog empty, but product 555 sold recently and has NO case_size meta.
$GLOBALS['FC_CATALOG'] = [];
$GLOBALS['FC_SOLD'] = [555];
$GLOBALS['wpdb'] = new FcWpdb();
$reports2 = new MealsDB_Reports($GLOBALS['wpdb']);
$rows2 = $reports2->generate_purchase_order();

chk(is_array($rows2) ? count($rows2) : -1, 1, 'FC-5 empty catalog + sales → sold product still forecast');
$r555 = fc_row_by_sku($rows2, 'SKU555');
chk_true($r555 !== null, 'FC-5 pid555 present via fallback');
chk($r555['case_size'] ?? null, 1, 'FC-6 case_size floored to 1 (no catalog/meta)');
chk($r555['order_quantity'] ?? null, 70, 'FC-6 order_quantity 70 (units 70 / case 1) — no divide-by-zero');

// ---- FC-7: source-level assertion that the catalog query filters is_published ----
$src = file_get_contents(__DIR__ . '/../includes/services/class-reports.php');
chk_true(strpos($src, 'is_published = 1') !== false, 'FC-7 catalog query filters is_published = 1');

echo "\n=== PO forecast — full catalog ===\n";
if (empty($failures)) { echo "PASS — {$passed} checks\n"; exit(0); }
echo "FAIL — {$passed} passed, " . count($failures) . " failed:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
```

### Step 2: Run the test — expect FAIL

Run: `php tests/test-po-full-catalog.php`
Expected: FAIL. Before the change the engine builds the universe only from recent sales, so Scenario 1 returns 2 rows (101, 102), not 3 — `FC-1` fails. (`FC-7` also fails: no `is_published = 1` in source yet.)

### Step 3: Implement the engine change — Edit A (remove the early empty-recent return)

In `includes/services/class-reports.php`, find (`~:192-194`):

```php
        if (!is_array($recent_rows) || empty($recent_rows)) {
            return [];
        }
```

Replace with (an empty recent-sales result must no longer empty the PO; just normalize to an array):

```php
        // An empty recent-sales result must NOT empty the PO now — the full
        // catalog is still forecast (everything just has zero recent demand and
        // orders only to cover stock gaps). The empty($products) guard after the
        // catalog seed + category exclusion (below) is the real gate.
        if (!is_array($recent_rows)) {
            $recent_rows = [];
        }
```

### Step 4: Implement the engine change — Edit B (seed universe from catalog; Query A attaches history)

Find (`~:196-207`):

```php
        // Build per-product weekly history.
        $products = [];
        foreach ($recent_rows as $row) {
            $pid = (int) $row['wc_product_id'];
            if (!isset($products[$pid])) {
                $products[$pid] = [
                    'product_name'   => (string) $row['product_name'],
                    'weekly_history' => [],
                ];
            }
            $products[$pid]['weekly_history'][$row['year_week']] = (float) $row['weekly_qty'];
        }
```

Replace with:

```php
        // --- Seed the product universe from the full active catalog. ---
        // Forecast EVERY published meal/side product in meals_products, not just
        // those sold in the trailing window — mirrors the validated back-test
        // simulation, which never dropped a product for a quiet 12 weeks. Query A
        // (below) only ATTACHES recent weekly history to these entries.
        $products_table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $catalog = $this->wpdb->get_results(
            "SELECT wc_product_id, product_type, case_size
             FROM `{$products_table}`
             WHERE product_type IN ('meal','side')
               AND is_published = 1",
            ARRAY_A
        );

        $products = [];
        if (is_array($catalog)) {
            foreach ($catalog as $row) {
                $pid = (int) $row['wc_product_id'];
                if ($pid <= 0) {
                    continue;
                }
                $products[$pid] = [
                    'product_name'   => '',                 // filled from sales or WC below
                    'weekly_history' => [],                 // zero until Query A fills weeks
                    'case_size'      => (int) $row['case_size'],
                ];
            }
        }

        // Query A now ATTACHES recent weekly history to the catalog entries. A
        // product SOLD but absent from meals_products (e.g. unsynced) is still
        // included so we don't lose real demand (seeded with case_size 0, floored
        // to 1 at row-build time).
        foreach ($recent_rows as $row) {
            $pid = (int) $row['wc_product_id'];
            if ($pid <= 0) {
                continue;
            }
            if (!isset($products[$pid])) {
                $products[$pid] = [
                    'product_name'   => (string) $row['product_name'],
                    'weekly_history' => [],
                    'case_size'      => 0,
                ];
            }
            if ($products[$pid]['product_name'] === '') {
                $products[$pid]['product_name'] = (string) $row['product_name'];
            }
            $products[$pid]['weekly_history'][$row['year_week']] = (float) $row['weekly_qty'];
        }
```

### Step 5: Implement the engine change — Edit C (product_name fallback + case_size floor)

Find (`~:337-344`):

```php
            $meta   = isset($product_meta[$pid]) ? $product_meta[$pid] : [];
            $case_size = isset($meta['case_size']) && (int) $meta['case_size'] > 0
                ? (int) $meta['case_size']
                : ((int) get_post_meta($pid, 'case_size', true) ?: 1);

            $wc_product    = wc_get_product($pid);
            $sku           = $wc_product ? $wc_product->get_sku() : '';
            $current_stock = $wc_product ? max(0, (int) $wc_product->get_stock_quantity()) : 0;
```

Replace with:

```php
            $meta = isset($product_meta[$pid]) ? $product_meta[$pid] : [];
            // Prefer the catalog case_size (authoritative once Case Count Sync has
            // run); fall back to the product-meta lookup, then the legacy postmeta,
            // and finally a floor of 1 — NEVER 0. Line below divides
            // $units_needed / $case_size, so a 0 (e.g. a fallback-seeded
            // sold-but-uncatalogued product) would divide by zero.
            $case_size = (int) ($p['case_size'] ?? 0) > 0
                ? (int) $p['case_size']
                : (isset($meta['case_size']) && (int) $meta['case_size'] > 0
                    ? (int) $meta['case_size']
                    : ((int) get_post_meta($pid, 'case_size', true) ?: 1));

            $wc_product    = wc_get_product($pid);
            // Catalog products with no recent sales have an empty product_name;
            // fall back to the WC product title (the WC object is already loaded
            // here for SKU/stock).
            $product_name  = $p['product_name'] !== ''
                ? $p['product_name']
                : ($wc_product ? $wc_product->get_name() : '');
            $sku           = $wc_product ? $wc_product->get_sku() : '';
            $current_stock = $wc_product ? max(0, (int) $wc_product->get_stock_quantity()) : 0;
```

Then find the row assembly (`~:367`):

```php
                'product_name'        => $p['product_name'],
```

Replace with:

```php
                'product_name'        => $product_name,
```

### Step 6: Run the new test — expect PASS

Run: `php tests/test-po-full-catalog.php`
Expected: `PASS — 17 checks`

### Step 7: Confirm the existing PO test stays GREEN

Run: `php tests/test-purchase-order-3week-buffer.php`
Expected: `PASS — <n> checks` (unchanged). Product 555 now arrives via the Query A fallback; the retained `empty($products)` guard passes it through; `case_size` resolves to 12 via the legacy-meta fallback (its catalog seed value is 0, and `get_product_types_for_ids` returns `[]` in that stub). `order_quantity` is still 72. If this test goes red, re-check Edits A/B (you must NOT have added an `empty($catalog)` guard) and Edit C (the floor must fall through to `get_post_meta ?: 1`).

Also lint: `php -l includes/services/class-reports.php` → `No syntax errors detected`.

### Step 8: Run the full suite

Run:
```bash
pass=0; fail=0; failed=""
for t in tests/test-*.php; do php "$t" >/dev/null 2>&1 && pass=$((pass+1)) || { fail=$((fail+1)); failed="$failed $t"; }; done
echo "PASS: $pass  FAIL: $fail"; echo "Failed:$failed"
```
Expected: only the two documented dompdf/mbstring baseline failures (`tests/test-pdf-slip-binary-output.php`, `tests/test-vac-pdf.php` — `undefined function Dompdf\mb_internal_encoding()`). Everything else — including `test-purchase-order-3week-buffer.php`, `test-reports-authz.php`, `test-reports-date-boundary.php` — must pass. If any OTHER PO/report test fails, it assumed the recent-sales-only universe and needs updating; report it before proceeding.

### Step 9: Commit

```bash
git add includes/services/class-reports.php tests/test-po-full-catalog.php
git commit -m "feat(po): forecast the full published meal/side catalog, not just recent sales

Seed the PO product universe from meals_products (product_type IN meal/side AND
is_published=1); Query A now only attaches recent weekly history. Remove the
early empty-recent-sales return (rely on the existing empty(\$products) guard).
Add a product_name fallback to the WC title and keep a case_size >= 1 floor to
avoid divide-by-zero. Forecasting math unchanged.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Lint + both PO tests + full suite**

```bash
php -l includes/services/class-reports.php
php tests/test-po-full-catalog.php
php tests/test-purchase-order-3week-buffer.php
pass=0; fail=0; failed=""
for t in tests/test-*.php; do php "$t" >/dev/null 2>&1 && pass=$((pass+1)) || { fail=$((fail+1)); failed="$failed $t"; }; done
echo "PASS: $pass  FAIL: $fail"; echo "Failed:$failed"
```
Expected: lint clean; both PO tests PASS; full suite fails ONLY the two documented mbstring baseline tests.

- [ ] **Step 2: Confirm the change is scoped and the math is untouched**

```bash
git diff main -- includes/services/class-reports.php
```
Confirm the diff touches ONLY: the early-return normalization, the catalog seed + Query A fill loop, the `product_name` fallback, and the `case_size` floor. The weighted-average / seasonal / coverage / stock-subtraction / case-rounding blocks (`~:283-335`, `:345-351`) must be unchanged. No change to `export_purchase_order_csv`, category exclusion, or Query B.

- [ ] **Step 3: Manual smoke test (live host — needs WP/WC + real data)**

Cannot be verified locally (no WP runtime). On staging:
- Generate a PO → row count ≈ the full published meal/side catalog (~120+), not the small recent-sales subset.
- A steady seller with stock < 9 weeks shows a real case-rounded `order_quantity` using its real `case_size` (12/24/36/48/100), NOT 1.
- A well-stocked product shows `order_quantity` 0 but STILL appears as a row with its `case_size`.
- A product with no sales in 84 days APPEARS (previously absent).
- A delisted/trashed product (`is_published = 0`) does NOT appear.
- Hand-check one product: `ceil(weighted_avg * seasonal * 9) - (current_stock + future_inv)`, rounded up to whole cases.

---

## Self-Review Notes (author)

- **Spec coverage:** directive §1 (catalog seed + `is_published`) → Task 1 Edit B/Step 4; §2 (Query A attaches; remove early return; keep `empty($products)` guard, NOT `empty($catalog)`) → Edits A+B; §3 (product_name fallback) → Edit C; §4 (math unchanged; case_size floor) → Edit C + the untouched math. Directive "Verify"/"Test to add" (full catalog, is_published exclusion, case_size floor, empty-catalog guard, existing test green) → test FC-1..FC-7 + Steps 7-8.
- **The three patched corrections are all pinned:** FC-5 proves the `empty($products)` guard (not `empty($catalog)`); FC-7 asserts `is_published = 1` in source; FC-6 proves the `case_size >= 1` floor / no divide-by-zero.
- **Type/name consistency:** `$products[$pid]` entries carry `product_name`/`weekly_history`/`case_size` in both the catalog seed and the fallback; the build loop reads `$p['case_size']` and `$p['product_name']`; the row uses the local `$product_name`. Consistent across edits.
- **Math untouched:** empty `weekly_history` → `weighted_avg = 0` (no special case, no div-by-zero) is relied upon, not modified — confirmed against `:290-300`.
