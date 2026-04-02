# Phase L: Private Customer Sales Report

## Goal

Add a sales report for private (non-government) customers, replicating `privates_customer_report/privates_customer_report.php`. This is a CSV download: per-client totals of mains, sides, subtotals, tax, and final totals within a date range.

## What the old system did

### Output format

CSV with columns:
```
First Name, Last Name, Total Mains, Total Sides, Total Purchased Before Tax, Total Tax Charged, Final Total
```

Plus a "Grand Total" row at the bottom summing all numeric columns.

### Selection criteria

- Users with `customer_group = 'private'` in wp_usermeta
- WC orders with statuses: `processing`, `completed`, `paid`
- Date range filter on `date_created`
- Skip clients with zero orders or zero mains+sides+subtotal

### Category definitions

- **Mains:** WC product category ID **35**
- **Sides:** WC product category IDs **25, 23, 37, 43** (Dessert, Cereal, Muffins, Soup)

### Sorting

Alphabetical by first name, then last name.

---

## Implementation

### New method on `MealsDB_Reports`

```php
/**
 * Generate private customer sales report.
 *
 * @param string $start_date Y-m-d
 * @param string $end_date   Y-m-d
 * @return array ['rows' => [...], 'grand_totals' => [...]]
 */
public function private_customer_report(string $start_date, string $end_date): array
```

### Logic

1. **Query `meals_clients`** where `client_type = 'Private' AND active = 1 AND wp_user_id > 0`. Select: `client_id`, `wp_user_id`, `first_name`, `last_name`. Decrypt first_name and last_name using `MealsDB_Encryption::decrypt()`.

2. **For each client**, query WC orders in the date range for that `wp_user_id`. Statuses: `processing`, `completed`, `paid` (matching old plugin exactly).

3. **For each order**, count items by WC product category:
   - Mains: items whose WC product belongs to category **35**
   - Sides: items whose WC product belongs to categories **25, 23, 37, 43**
   
   Sum financial totals from the WC order:
   - `get_subtotal()` → total before tax
   - `get_total_tax()` → total tax
   - Final total = subtotal + tax

4. **Skip** clients where total_mains == 0 AND total_sides == 0 AND total_before_tax == 0.

5. **Sort** by first_name ASC, then last_name ASC.

### Counting items by category

The old plugin used `has_term($category_id, 'product_cat', $product->get_id())` per item per order. In the new plugin, you have two options:

**Option A (recommended):** Build a product-to-category lookup once from `meals_products` where `product_type = 'meal'` (mains) or `product_type = 'side'` (sides). This avoids per-item taxonomy queries.

**Option B:** Use WC taxonomy lookup. For each order item, get the product and check `has_term()`. This is what the old plugin did, but it's slower.

If using Option A, the `meals_products` table has `product_type ENUM('meal','side','fee','other')`. Map `meal` → mains count, `side` → sides count. This is cleaner than hardcoding WC category IDs, and it uses data already in the external DB.

**Caveat with Option A:** The `meals_products` table may not include all WC products (only those that have been synced). For products not in `meals_products`, fall back to WC category lookup using the hardcoded category IDs as a safety net.

### Return format

```php
[
    'rows' => [
        [
            'first_name'       => 'Alice',
            'last_name'        => 'Smith',
            'total_mains'      => 12,
            'total_sides'      => 8,
            'total_before_tax' => 234.50,
            'total_tax'        => 35.18,
            'final_total'      => 269.68,
        ],
        // ...
    ],
    'grand_totals' => [
        'total_mains'      => 156,
        'total_sides'      => 89,
        'total_before_tax' => 3456.00,
        'total_tax'        => 518.40,
        'final_total'      => 3974.40,
    ],
]
```

---

## CSV export method

```php
/**
 * Export private customer report to CSV string.
 */
public function export_private_report_csv(array $data): string
```

Outputs the same format as the old plugin:
```
First Name,Last Name,Total Mains,Total Sides,Total Purchased Before Tax,Total Tax Charged,Final Total
Alice,Smith,12,8,234.50,35.18,269.68
...
Grand Total,,156,89,3456.00,518.40,3974.40
```

---

## AJAX endpoint

**File:** `includes/ajax/class-ajax-reports.php`

Add action: `wp_ajax_mealsdb_private_customer_report`

Handler:
- Check nonce and `manage_options`
- Read `$_POST['start_date']` and `$_POST['end_date']`
- Call `MealsDB_Reports::private_customer_report($start_date, $end_date)`
- Return via `wp_send_json_success()`

---

## UI

Two options for where to put this:

**Option A:** Add a "Private Sales" tab to the main Meals DB interface. This keeps all reports in one place.

**Option B:** Add it as a section on the existing Fee Reconciliation tab (rename tab to "Reports" with sub-sections). This avoids tab proliferation.

**Recommended:** Option A — add as its own tab `privates` (parallel to `fees`, `po`, `slips`).

### Tab addition

In `class-admin-ui.php` around line 664, add:
```php
'privates' => __('Private Sales', 'meals-db'),
```

In the switch statement around line 535, add:
```php
case 'privates':
    include MealsDB_Plugin::path('views/private-sales.php');
    break;
```

### View file: `views/private-sales.php`

Date range picker (start/end), "Run Report" button, results table, "Export CSV" button. Follow the same pattern as fee-reconciliation.php — inline JS with AJAX call, table rendering, and client-side CSV export.

---

## Hardcoded values

| Constant | Value | Notes |
|---|---|---|
| Mains category | 35 | WC product_cat term_id |
| Sides categories | 25, 23, 37, 43 | Dessert, Cereal, Muffins, Soup |
| Client type filter | 'Private' | meals_clients.client_type |
| Order statuses | processing, completed, paid | Match old plugin exactly |

Consider making the category IDs configurable via `get_option('mealsdb_category_ids')` alongside the fee product IDs, rather than hardcoding. But for initial implementation, hardcoded constants matching the old plugin are fine.

---

## Key constraints

- External DB via `MealsDB_DB::get_connection()` for client queries
- WC orders via `wc_get_orders()` or `$wpdb` HPOS queries
- Decrypt PII (first_name, last_name) using `MealsDB_Encryption::decrypt()`
- meals_clients.client_type uses 'Private' (title case) — match exactly
- The old plugin used `wc_get_orders(['customer_id' => $user_id])` which maps to `customer_id` in HPOS
- Category-based item counting: prefer `meals_products.product_type` with WC category fallback
