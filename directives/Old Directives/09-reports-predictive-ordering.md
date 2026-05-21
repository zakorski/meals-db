# Phase 9 — Reports and Predictive Ordering Update

## Objective

Update `MealsDB_Reports` to use the HPOS query service (completed in Phase 5). Add a predictive ordering layer that calculates product demand projections from historical WC order data and produces an Apetito purchase order output.

---

## Context

`includes/services/class-reports.php` currently has:
- `get_resupply_requirements($start_date, $end_date)` — queries WC order items + `meals_products`; joins against `wp_posts` (legacy, must be updated to HPOS)
- `get_meal_breakdown($start_date, $end_date)` — same legacy join
- `export_to_csv(array $rows)` — utility, keep as-is

The predictive ordering feature needs to:
1. Calculate average weekly demand per product over a configurable trailing period (default: 8 weeks)
2. Apply a simple forward projection (default: next 1 week)
3. Account for known upcoming order volume from `meals_clients` (clients with active requisitions)
4. Output a purchase order in Apetito's expected format

---

## Step 1 — Confirm HPOS table updates to `MealsDB_Reports` (from Phase 5)

This was specified in Phase 5, Step 3. Verify before proceeding that:
- `get_resupply_requirements()` uses `wc_orders` not `wp_posts`
- `get_meal_breakdown()` uses `wc_orders` not `wp_posts`
- Both use `wc_order_items` not `woocommerce_order_items`
- All `$wpdb->prepare()` calls are correct for the new table names

If Phase 5 is complete, no additional changes to these two methods are needed here.

---

## Step 2 — Add `get_demand_history()` to `MealsDB_Reports`

```php
public function get_demand_history(int $trailing_weeks = 8): array
```

### 2.1 Calculate date range
- `$end_date = current date (Y-m-d)`
- `$start_date = $end_date minus ($trailing_weeks * 7) days`

### 2.2 Query weekly demand per product
```sql
SELECT
    CAST(product_meta.meta_value AS UNSIGNED) AS wc_product_id,
    oi.order_item_name AS product_name,
    YEARWEEK(o.date_created_gmt, 1) AS year_week,
    SUM(CAST(qty_meta.meta_value AS DECIMAL(10,2))) AS weekly_quantity
FROM {wc_order_items} oi
INNER JOIN {woocommerce_order_itemmeta} product_meta
    ON product_meta.order_item_id = oi.order_item_id
    AND product_meta.meta_key = '_product_id'
INNER JOIN {woocommerce_order_itemmeta} qty_meta
    ON qty_meta.order_item_id = oi.order_item_id
    AND qty_meta.meta_key = '_qty'
INNER JOIN {wc_orders} o
    ON o.id = oi.order_id
    AND o.type = 'shop_order'
    AND o.status NOT IN ('wc-cancelled','wc-trash','trash')
WHERE DATE(o.date_created_gmt) BETWEEN %s AND %s
    AND oi.order_item_type = 'line_item'
GROUP BY wc_product_id, product_name, year_week
ORDER BY wc_product_id, year_week
```

### 2.3 Calculate averages
For each `wc_product_id`:
- Collect all `weekly_quantity` values across the trailing period
- `avg_weekly_demand = sum(weekly_quantities) / $trailing_weeks`
  - Use `$trailing_weeks` as denominator (not the count of weeks with orders) to correctly represent weeks with zero orders

### 2.4 Return structure
```
[
  wc_product_id => [
    'wc_product_id'    => int,
    'product_name'     => string,
    'avg_weekly_demand' => float,
    'weekly_history'   => [year_week => quantity, ...],
    'total_trailing'   => float,
  ],
  ...
]
```

---

## Step 3 — Add `generate_purchase_order()` to `MealsDB_Reports`

```php
public function generate_purchase_order(int $weeks_ahead = 1, int $trailing_weeks = 8): array
```

### 3.1 Get demand history
Call `get_demand_history($trailing_weeks)`.

### 3.2 Get product metadata
Query `meals_products` for all products: `wc_product_id`, `case_size`, `unit_cost`, `product_type`, `taxable`.

### 3.3 Calculate order quantities
For each product:
- `projected_units = avg_weekly_demand * $weeks_ahead`
- `cases_needed = ceil($projected_units / case_size)` (minimum 1 if `projected_units > 0`)
- `estimated_cost = cases_needed * unit_cost`

### 3.4 Return structure
```
[
  [
    'wc_product_id'    => int,
    'product_name'     => string,
    'product_type'     => 'meal'|'side',
    'avg_weekly_demand' => float,
    'projected_units'  => float,
    'case_size'        => int,
    'cases_needed'     => int,
    'unit_cost'        => float,
    'estimated_cost'   => float,
  ],
  ...
]
```
Sort by `product_type` ASC then `product_name` ASC.

---

## Step 4 — Add `export_purchase_order_csv()` to `MealsDB_Reports`

```php
public function export_purchase_order_csv(array $po_rows): string
```

### 4.1 CSV format
Header row:
```
Product Name,Product Type,Avg Weekly Units,Projected Units,Case Size,Cases to Order,Unit Cost,Estimated Cost
```

### 4.2 Summary row
After all product rows, add a blank row then a totals row:
```
TOTAL,,,,,{total_cases},{},{total_estimated_cost}
```

### 4.3 Use existing `export_to_csv()` pattern
Follow the same `fopen('php://temp')` pattern already used in `export_to_csv()`.

---

## Step 5 — Add AJAX endpoint `mealsdb_generate_purchase_order`

Add to `includes/ajax/class-ajax-sync.php` or a new `includes/ajax/class-ajax-reports.php`:

### 5.1 Handler
- Read `weeks_ahead` (int, default 1, max 12) and `trailing_weeks` (int, default 8, max 52) from `$_REQUEST`
- Instantiate `MealsDB_Reports` with global `$wpdb`
- Call `generate_purchase_order($weeks_ahead, $trailing_weeks)`
- Return `['success' => true, 'data' => $po_rows, 'csv' => $csv_string]`

---

## Step 6 — Add admin UI for purchase order generation

### 6.1 Add a "Purchase Order" section to the reports/dashboard area
UI needs:
- "Trailing period" selector: 4 weeks / 8 weeks / 12 weeks (default 8)
- "Order horizon" selector: 1 week / 2 weeks (default 1)
- "Generate" button
- Results table showing product name, avg demand, cases to order, estimated cost
- "Export CSV" button that downloads the CSV

---

## Verification Checklist

- `get_demand_history(8)` returns correct average weekly demand for a known product with a known order history
- Products with zero orders in the trailing period return `avg_weekly_demand = 0.0`
- `generate_purchase_order()` returns `cases_needed = 0` for products with zero demand
- `generate_purchase_order()` correctly uses `case_size` from `meals_products` (not hardcoded 1)
- Exported CSV includes a correct totals row
- No reference to `wp_posts` remains in `class-reports.php`
- Resupply and meal breakdown reports still return correct data post-HPOS migration
