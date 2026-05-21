# Phase O: Appetito Purchase Order — Weighted Demand with Seasonal Awareness

## Goal

Replace the current `generate_purchase_order()` with a demand projection algorithm that uses recency-weighted averaging, seasonal index adjustment from year-over-year data, and inventory awareness. WooCommerce has no built-in forecasting — all third-party options are SaaS products ($50–$300+/month) that are overkill for a single-supplier meal delivery operation. This is self-contained.

## Data available

The site has ~20 months of WC order history (July 2024 – March 2026, ~30,000+ orders). There is full year-over-year overlap from August through March, which is enough to compute per-product seasonal indices for those months. For months without YoY data (April–July of the first year), the algorithm gracefully falls back to non-seasonal projection.

---

## Algorithm: Three-Layer Demand Projection

### Layer 1: Weighted Recent Demand (baseline)

Use **exponentially weighted weekly demand** where recent weeks matter more than older weeks.

```
For each product, over the trailing period (default 12 weeks):
  weighted_avg = Σ(weekly_qty × decay^week_index) / Σ(decay^week_index)
  where decay = 0.85, week_index = 0 for most recent week
```

This tracks trends naturally — if fish is selling more this month, the projection rises. If turkey sales dropped after Christmas, the projection falls. One-off bulk orders don't dominate because they're averaged, not max'd.

### Layer 2: Seasonal Index (adjustment)

Compare current-period demand to same-period-last-year demand to detect seasonal patterns. A seasonal index > 1.0 means demand is historically higher in the upcoming period; < 1.0 means lower.

```
For the upcoming order horizon (e.g. the next 6 weeks):
  Look at same calendar weeks last year for this product.
  Look at the weeks immediately preceding those same weeks last year.
  
  seasonal_index = (avg demand in target weeks last year) / (avg demand in preceding weeks last year)
  
  If no prior-year data exists: seasonal_index = 1.0 (no adjustment)
```

**Example — fish around Good Friday:**
- Weeks 11-12 last year (pre-Easter): fish sold 30/week
- Weeks 13-14 last year (Easter): fish sold 55/week
- seasonal_index = 55/30 = 1.83
- If current weighted baseline for fish is 35/week, adjusted = 35 × 1.83 = 64/week

**Example — turkey around Christmas:**
- Weeks 48-49 last year (pre-Christmas): turkey sold 20/week
- Weeks 50-51 last year (Christmas): turkey sold 45/week
- seasonal_index = 45/20 = 2.25

**Guardrails:** Clamp the seasonal index between 0.3 and 3.0 to prevent absurd projections from thin data. Require at least 2 weeks of prior-year data on both sides to compute the index — otherwise fall back to 1.0.

### Layer 3: Inventory Subtraction (final)

```
projected_need = (weighted_avg × seasonal_index) × order_horizon_weeks
qty_needed = projected_need + buffer
total_available = current_stock + incoming_future_stock
units_needed = MAX(qty_needed - total_available, 0)
cases_to_buy = CEIL(units_needed / case_size)
```

---

## Implementation

### Method signature

```php
/**
 * Generate a seasonally-adjusted purchase order projection.
 *
 * @param int   $trailing_weeks       Weeks of recent history for baseline (default 12)
 * @param int   $order_horizon_weeks  Weeks of stock to order for (default 6)
 * @param float $decay_factor         Recency weight decay, 0-1 (default 0.85)
 * @return array
 */
public function generate_purchase_order(
    int $trailing_weeks = 12,
    int $order_horizon_weeks = 6,
    float $decay_factor = 0.85
): array
```

### Step 1: Query weekly demand (recent + prior year)

Extend the existing `get_demand_history()` SQL to also fetch the same calendar weeks from the prior year.

Two queries (or one with UNION):

**Query A — Recent trailing period:**
```sql
SELECT product_id, YEARWEEK(date_created_gmt, 1) AS year_week,
       SUM(quantity) AS weekly_qty
FROM wc_orders + order_items + itemmeta
WHERE date_created_gmt >= (today - trailing_weeks * 7 days)
GROUP BY product_id, year_week
```

**Query B — Same-weeks-last-year + preceding weeks:**
```sql
-- Target weeks: the calendar weeks corresponding to "today + 1 week" through "today + order_horizon_weeks"
-- Preceding weeks: the trailing_weeks before those target weeks, all from last year

SELECT product_id, YEARWEEK(date_created_gmt, 1) AS year_week,
       SUM(quantity) AS weekly_qty
FROM wc_orders + order_items + itemmeta
WHERE date_created_gmt >= (target_start_last_year - trailing_weeks * 7 days)
  AND date_created_gmt < (target_end_last_year)
GROUP BY product_id, year_week
```

The calendar week mapping: if today is 2026-W14, the target weeks are 2026-W15 through 2026-W20 (for a 6-week horizon). Last year's equivalents are 2025-W15 through 2025-W20. The preceding weeks last year are 2025-W03 through 2025-W14 (the 12 trailing weeks before the target).

### Step 2: Compute seasonal index per product

```php
foreach ($products as $pid => &$product) {
    // Last year's demand in the target weeks (upcoming period)
    $ly_target_weeks = get_year_weeks_for_range($target_start_ly, $target_end_ly);
    $ly_preceding_weeks = get_year_weeks_for_range($preceding_start_ly, $preceding_end_ly);
    
    $ly_target_total = 0;
    $ly_target_count = 0;
    $ly_preceding_total = 0;
    $ly_preceding_count = 0;
    
    foreach ($ly_target_weeks as $yw) {
        $ly_target_total += $ly_data[$pid][$yw] ?? 0;
        $ly_target_count++;
    }
    
    foreach ($ly_preceding_weeks as $yw) {
        $ly_preceding_total += $ly_data[$pid][$yw] ?? 0;
        $ly_preceding_count++;
    }
    
    $ly_target_avg = $ly_target_count > 0 ? $ly_target_total / $ly_target_count : 0;
    $ly_preceding_avg = $ly_preceding_count > 0 ? $ly_preceding_total / $ly_preceding_count : 0;
    
    // Need both sides with meaningful data to compute index
    $min_weeks_required = 2;
    if ($ly_target_count >= $min_weeks_required 
        && $ly_preceding_count >= $min_weeks_required
        && $ly_preceding_avg > 0) {
        $raw_index = $ly_target_avg / $ly_preceding_avg;
        $seasonal_index = max(0.3, min(3.0, $raw_index));  // guardrails
    } else {
        $seasonal_index = 1.0;  // no adjustment
    }
    
    $product['seasonal_index'] = $seasonal_index;
}
```

### Step 3: Weighted average (same as before)

```php
krsort($weekly_history);  // most recent first
$weighted_sum = 0.0;
$weight_sum = 0.0;
$week_index = 0;

foreach ($weekly_history as $week => $qty) {
    $weight = pow($decay_factor, $week_index);
    $weighted_sum += $qty * $weight;
    $weight_sum += $weight;
    $week_index++;
}
// Include zero-sale weeks in denominator
for ($i = $week_index; $i < $trailing_weeks; $i++) {
    $weight_sum += pow($decay_factor, $i);
}
$weighted_avg = $weight_sum > 0 ? $weighted_sum / $weight_sum : 0;
```

### Step 4: Category exclusion

```php
$excluded = get_option('mealsdb_appetito_excluded_categories', [98, 104, 103, 102, 101, 88, 109]);
```

Batch-fetch product→category mappings and skip excluded products.

### Step 5: Stock lookup and final calculation

```php
$adjusted_weekly = $weighted_avg * $seasonal_index;
$projected = $adjusted_weekly * $order_horizon_weeks;
$qty_needed = $projected + $buffer;
$total_stock = $current_stock + $future_inv;
$units_needed = max(0, (int) ceil($qty_needed) - $total_stock);
$cases_to_buy = $units_needed > 0 ? (int) ceil($units_needed / $case_size) : 0;
```

### Return format

```php
[
    'sku'                  => 'CD-001',
    'product_name'         => 'Chicken Dinner',
    'weighted_avg_weekly'  => 22.5,       // baseline demand
    'seasonal_index'       => 1.83,       // 1.0 = no adjustment
    'adjusted_weekly'      => 41.2,       // weighted_avg × seasonal_index
    'projected_need'       => 247,        // adjusted × horizon weeks
    'buffer'               => 5,
    'qty_needed'           => 252,
    'current_stock'        => 8,
    'future_inventory'     => 24,
    'total_available'      => 32,
    'units_needed'         => 220,
    'case_size'            => 12,
    'cases_to_buy'         => 19,
    'order_quantity'       => 228,        // cases × case_size
    'seasonal_note'        => 'Seasonal uplift: +83% vs trailing baseline (Easter period)',
    // Raw data for staff who want to see it:
    'weekly_history'       => [28, 25, 22, 20, 15, 11, 12, 10],
]
```

Sort by SKU ASC.

### Seasonal note generation

When `seasonal_index != 1.0`, generate a human-readable note:

```php
if ($seasonal_index > 1.05) {
    $pct = round(($seasonal_index - 1) * 100);
    $note = "Seasonal uplift: +{$pct}% vs trailing baseline";
} elseif ($seasonal_index < 0.95) {
    $pct = round((1 - $seasonal_index) * 100);
    $note = "Seasonal dip: -{$pct}% vs trailing baseline";
} else {
    $note = '';
}
```

---

## CSV export

```
SKU, Product Name, Avg/Week, Seasonal Idx, Adj/Week, Projected, Buffer,
Qty Needed, Stock, Future, Available, Units Needed, Case Size, Cases, Order Qty, Note
```

---

## UI updates

**File:** `views/purchase-order.php`

Inputs:
```html
<label>Trailing Period:</label>
<select id="mealsdb-po-trailing">
    <option value="8">8 weeks</option>
    <option value="12" selected>12 weeks</option>
    <option value="16">16 weeks</option>
</select>

<label>Order Horizon:</label>
<select id="mealsdb-po-horizon">
    <option value="4">4 weeks</option>
    <option value="6" selected>6 weeks</option>
    <option value="8">8 weeks</option>
</select>
```

Table columns: SKU, Product, Avg/Wk, Seasonal, Adj/Wk, Projected, Buffer, Needed, Stock, Cases, Order Qty, Note.

Highlight rows where `seasonal_index > 1.2` (amber) or `seasonal_index > 1.5` (amber-bold) to draw attention to seasonal spikes.

Products with `seasonal_index < 0.8` could be shown in light blue to indicate "order less than usual."

---

## Data source for buffer, case_size, SKU, stock

All from WooCommerce, not meals_products:
- `buffer` → `get_post_meta($pid, 'buffer', true)` (set by staff on product edit)
- `case_size` → `meals_products.case_size` with fallback to `get_post_meta($pid, 'case_size', true)`
- `_future_inventory_quantity` → `get_post_meta($pid, '_future_inventory_quantity', true)` (managed by future-dated-inventory plugin)
- SKU → `wc_get_product($pid)->get_sku()`
- Current stock → `wc_get_product($pid)->get_stock_quantity()`

Batch-fetch where possible to avoid N+1 queries.

---

## Edge cases

| Situation | Handling |
|---|---|
| Product didn't exist last year | seasonal_index = 1.0 (no adjustment) |
| Product had zero sales last year in target weeks | If preceding weeks also had zero, index = 1.0. If preceding had sales but target had zero, index = 0.3 (clamped floor) |
| First year of operation (no prior year data at all) | All products get seasonal_index = 1.0 — pure weighted-average mode |
| April 2025 data spike (7843 orders, likely bulk import) | The weighted average naturally dampens this since it's 12+ months ago. For seasonal index, it would inflate both target and preceding equally, so the ratio stays reasonable |
| Product discontinued but had sales last year | Excluded by category filter or by having zero recent sales (weighted_avg = 0, so cases_to_buy = 0) |

---

## Backward compatibility

Keep `get_demand_history()` as-is for the resupply report. The purchase order rewrites `generate_purchase_order()` only. The AJAX handler signature stays the same — just add the new parameter pass-through.

---

## Key constraints

- WC product data via `wc_get_product()` for stock and SKU
- WC product meta via `get_post_meta()` for buffer, case_size, _future_inventory_quantity
- Excluded categories from `get_option('mealsdb_appetito_excluded_categories')`
- meals_products via `MealsDB_DB::get_connection()` for case_size fallback
- Weekly demand from HPOS tables via `$wpdb`
- decay_factor, seasonal guardrails (0.3–3.0), min_weeks_required (2) should be constants
- Batch-fetch WC product data to avoid N+1 queries
- The seasonal index query adds one additional SQL query (prior-year data) — acceptable for a batch report
