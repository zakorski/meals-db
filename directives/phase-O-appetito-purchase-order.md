# Phase O: Appetito Purchase Order Algorithm Alignment

## Goal

Align the new `generate_purchase_order()` method with the old `appetito/appetito.php` logic. The current implementation uses a fundamentally different algorithm that will produce different ordering quantities.

## Algorithm comparison

### Old system (appetito.php)
```
Input: end_date, weeks (period length, e.g. 6)
3 equal periods, each "weeks" long, going backward from end_date

Per product:
  max_sold = MAX(period_1_qty, period_2_qty, period_3_qty)
  qty_needed = max_sold + buffer
  total_stock = current_wc_inventory + future_inventory_quantity
  units_needed = MAX(qty_needed - total_stock, 0)
  cases_to_buy = CEIL(units_needed / case_size)
```

### New system (class-reports.php)
```
Input: weeks_ahead, trailing_weeks
Rolling weekly averages across trailing period

Per product:
  avg_weekly_demand = total_trailing / trailing_weeks
  projected_units = avg_weekly_demand * weeks_ahead
  cases_needed = CEIL(projected_units / case_size)
```

### Six differences that change ordering results

| # | Issue | Old behavior | New behavior | Impact |
|---|---|---|---|---|
| 1 | Peak vs Average | Takes HIGHEST of 3 periods | Takes AVERAGE across all weeks | New will underorder during peak periods |
| 2 | Buffer | Adds per-product buffer | No buffer | New will underorder by the buffer amount |
| 3 | Inventory | Subtracts current stock + future stock | Ignores stock levels | New will overorder if stock exists |
| 4 | Category exclusions | Excludes cats 98, 104, 103, 102, 101, 88, 109 | No exclusions | New includes fee products, non-food items |
| 5 | SKU | Outputs SKU, sorts by SKU | No SKU in output | Staff lose the product identifier they're used to |
| 6 | Data source for buffer/case_size | WC product meta (post_meta) | meals_products table | `buffer` doesn't exist in meals_products at all |

---

## Required changes

### 1. Add `buffer` column to `meals_products`

**File:** `includes/class-schema.php`

In the `MealsDB_Tables::PRODUCTS` definition, add after `case_size`:
```php
'buffer' => 'INT NOT NULL DEFAULT 0',
```

### 2. Rewrite `generate_purchase_order()` to use peak-of-3-periods

**File:** `includes/services/class-reports.php`

Replace the current `generate_purchase_order()` and `get_demand_history()` methods.

#### New method signature:
```php
/**
 * Generate an Appetito-style purchase order.
 *
 * @param string $end_date             Y-m-d (usually today)
 * @param int    $weeks_per_period     Weeks per period (usually 6)
 * @param string $future_inv_date      Y-m-d for future inventory arrival
 * @return array
 */
public function generate_purchase_order(
    string $end_date,
    int $weeks_per_period = 6,
    string $future_inv_date = ''
): array
```

#### New algorithm (matching old exactly):

```
1. Calculate 3 periods backward from end_date:
   period_1: (end_date - weeks) to end_date
   period_2: (end_date - 2*weeks) to (end_date - weeks)
   period_3: (end_date - 3*weeks) to (end_date - 2*weeks)

2. Query all WC orders across the full 3-period span.
   Group items by wc_product_id and period.

3. Exclude products in categories: 98, 104, 103, 102, 101, 88, 109
   (use WC product category lookup or meals_products filtering)

4. For each product:
   - SKU from WC product or meals_products
   - buffer from meals_products (fallback: WC post_meta 'buffer')
   - case_size from meals_products (fallback: WC post_meta 'case_size')
   - current_inventory from WC product stock_quantity
   - future_inventory from WC post_meta '_future_inventory_quantity'
   
   max_sold = MAX(period_1, period_2, period_3)
   qty_needed = max_sold + buffer
   total_stock = current_inventory + future_inventory
   units_needed = MAX(qty_needed - total_stock, 0)
   cases_to_buy = CEIL(units_needed / case_size)
   future_inventory_to_set = cases_to_buy * case_size

5. Sort by SKU ASC.
```

#### Return format (matching old CSV columns):

```php
[
    'sku'                      => 'CD-001',
    'product_name'             => 'Chicken Dinner',
    'period_1'                 => 45,
    'period_2'                 => 38,
    'period_3'                 => 42,
    'highest_sold'             => 45,
    'buffer'                   => 5,
    'case_size'                => 12,
    'current_inventory'        => 8,
    'future_inventory'         => 0,
    'future_inventory_date'    => '2025-04-15',
    'qty_needed'               => 50,
    'units_needed'             => 42,
    'cases_to_buy'             => 4,
]
```

### 3. Update CSV export

**Update `export_purchase_order_csv()`** to match old column layout:
```
SKU, Product Name, Period 1, Period 2, Period 3, Highest Sold, Buffer,
Case Size, Inventory, Future Inventory, Future Inv Date,
Qty Needed, Units Needed, Cases to Buy
```

### 4. Update the UI

**File:** `views/purchase-order.php`

Replace the trailing/horizon dropdowns with inputs matching the old plugin:

```html
<label>End Date:</label>
<input type="date" id="mealsdb-po-end-date" value="(today)" />

<label>Weeks per Period:</label>
<input type="number" id="mealsdb-po-weeks" value="6" min="1" max="12" />

<label>Future Inventory Date:</label>
<input type="date" id="mealsdb-po-future-date" value="(today)" />
```

Update the JS table renderer to show all the new columns.

### 5. Excluded categories config

Store excluded category IDs as a WordPress option:
```php
add_option('mealsdb_appetito_excluded_categories', [98, 104, 103, 102, 101, 88, 109]);
```

### 6. Backfill buffer values

The old system stored `buffer` as WC product post_meta. After adding the `buffer` column to `meals_products`, create a one-time backfill that copies `buffer` from `wp_postmeta` to `meals_products.buffer` for all synced products.

Alternatively, always fall back to reading `buffer` from WC post_meta if `meals_products.buffer` is 0. This avoids the need for a backfill and keeps the WC product edit as the source of truth for buffer values.

---

## Backward compatibility

The current `get_demand_history()` method is also used by the resupply report. Don't delete it — keep it as-is for that purpose. The purchase order method should be rewritten independently.

---

## Key constraints

- WC product stock via `wc_get_product($id)->get_stock_quantity()`
- WC product SKU via `wc_get_product($id)->get_sku()`
- WC product meta via `get_post_meta($id, 'buffer', true)` and `get_post_meta($id, '_future_inventory_quantity', true)`
- meals_products via `MealsDB_DB::get_connection()` (external DB)
- Excluded categories from WP option, not hardcoded
- The `future-dated-inventory` plugin manages `_future_inventory_quantity` and `_future_inventory_date` — this data lives in WC `wp_postmeta`, not in meals_products
