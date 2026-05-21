# Phase 6 — Invoice Generator Rewrite

## Objective

Rewrite all four invoice generation methods in `includes/services/class-invoice-generator.php` to use the `MealsDB_WC_Order_Query` service instead of querying `meals_transactions` and `meals_transaction_items`. The invoice output format and CSV structure must remain identical to the current implementation.

---

## Context

The four invoice types and their current data source:

| Method | Invoice Type | Zone | Legacy? |
|---|---|---|---|
| `generate_sdnb_legacy($zone, $start, $end)` | Moncton Legacy SDNB or Sussex Legacy SDNB | M or S | `use_legacy_billing = 1` |
| `generate_sdnb_new_portal($start, $end)` | New SDNB Format | Both | `use_legacy_billing = 0` |
| `generate_vac_csv($start, $end)` | VAC CSV | Both | client_type = 'Veteran' |
| `generate_vac_pdf($start, $end)` | VAC PDF | Both | client_type = 'Veteran' |

All four currently query `meals_transactions` joined to `meals_transaction_items`. All four must be rewritten to query `meals_clients` → WC HPOS.

The `meals_clients` columns used by invoices:
- `client_id`, `wp_user_id`, `client_type`, `use_legacy_billing`
- `first_name`, `last_name`, `service_id`, `requisition_id`, `individual_id`
- `sdnb_service_request_id`, `client_contribution`, `delivery_area_zone`
- `default_rate_id`

The rate for each order comes from `meals_client_rates` via `mealsdb_rate_id` order meta (resolved by `MealsDB_WC_Order_Query::resolve_rate_for_order()`).

---

## Step 1 — Add a shared data-fetch method

Add a private static method `get_invoice_data_for_clients()` to `MealsDB_Invoice_Generator`:

```php
private static function get_invoice_data_for_clients(
    array $client_rows,       // rows from meals_clients
    string $start_date,       // Y-m-d
    string $end_date          // Y-m-d
): array
```

### 1.1 Build `wp_user_id` list
- Extract all `wp_user_id` values from `$client_rows`
- Build an associative index: `$clients_by_user_id[wp_user_id] = client_row`

### 1.2 Fetch WC orders
- Instantiate `MealsDB_WC_Order_Query` with global `$wpdb`
- Call `get_orders_with_items_for_users($wp_user_ids, $start_date, $end_date)`

### 1.3 Resolve rates and aggregate
For each order:
- Find the matching client row via `$clients_by_user_id[$order['wp_user_id']]`
- Get `rate_id` from `$order['mealsdb_rate_id']` (cast to int, 0 if null)
- Resolve rate via `resolve_rate_for_order($rate_id, $client['client_id'])`
- Aggregate across items:
  - `total_units` = sum of `quantity` across all line items
  - `basic_cost` = `total_units * resolved_rate` (government billing is units × rate, not WC line totals)
  - `tax_amount` = sum of `line_tax` across taxable items (check `meals_products.taxable` per `wc_product_id`)
  - `total_cost` = `basic_cost + tax_amount - client_contribution` (verify against existing invoice logic)

### 1.4 Return structure
Return an array of rows, each containing:
```
client_id, wp_user_id, first_name, last_name, service_id, requisition_id,
individual_id, sdnb_service_request_id, client_contribution, delivery_area_zone,
use_legacy_billing, resolved_rate, total_units, basic_cost, tax_amount, total_cost,
order_date (from wc_orders.date_created_gmt)
```

---

## Step 2 — Rewrite `generate_sdnb_legacy($zone, $start_date, $end_date)`

### 2.1 Query `meals_clients` for eligible clients
```sql
SELECT client_id, wp_user_id, first_name, last_name, service_id, requisition_id,
       individual_id, client_contribution, delivery_area_zone, default_rate_id
FROM meals_clients
WHERE client_type = 'SDNB'
  AND use_legacy_billing = 1
  AND delivery_area_zone = ?
  AND active = 1
  AND wp_user_id > 0
```
Use `MealsDB_DB::get_connection()` with a prepared statement. Bind `$zone` as string.

### 2.2 Fetch invoice data
Call `get_invoice_data_for_clients($client_rows, $start_date, $end_date)`.

### 2.3 Build CSV
- The CSV structure (rows 1–6, column positions, flags) must remain exactly as in the current implementation
- Replace `$trans['billing_rate']` with `$row['resolved_rate']`
- Replace `$trans['total_units']` with `$row['total_units']`
- Replace `$trans['basic_cost']` with `$row['basic_cost']`
- Replace `$trans['tax_amount']` with `$row['tax_amount']`
- Replace `$trans['total_cost']` with `$row['total_cost']`
- All other column positions and static values remain unchanged

---

## Step 3 — Rewrite `generate_sdnb_new_portal($start_date, $end_date)`

### 3.1 Query `meals_clients` for eligible clients
```sql
SELECT client_id, wp_user_id, first_name, last_name, sdnb_service_request_id,
       client_contribution, default_rate_id
FROM meals_clients
WHERE client_type = 'SDNB'
  AND use_legacy_billing = 0
  AND active = 1
  AND wp_user_id > 0
```

### 3.2 Fetch invoice data
Call `get_invoice_data_for_clients($client_rows, $start_date, $end_date)`.

### 3.3 Build CSV
- Header row and column structure must remain identical to current implementation
- The `SCI-` prefixed ID previously used `transaction_id`. Replace with a deterministic identifier: `'SCI-' . str_pad($row['order_id'], 8, '0', STR_PAD_LEFT)` where `order_id` comes from the WC order
- Replace all `$row['billing_rate']`, `$row['total_units']`, `$row['tax_amount']` with values from the new data structure

---

## Step 4 — Rewrite `generate_vac_csv($start_date, $end_date)`

### 4.1 Query `meals_clients` for eligible clients
```sql
SELECT client_id, wp_user_id, first_name, last_name, requisition_id,
       vet_health_card, requisition_period, client_contribution, default_rate_id
FROM meals_clients
WHERE client_type = 'Veteran'
  AND active = 1
  AND wp_user_id > 0
```

### 4.2 Fetch invoice data
Call `get_invoice_data_for_clients($client_rows, $start_date, $end_date)`.

### 4.3 Build CSV
- Review the current `generate_vac_csv()` implementation fully before rewriting — it has its own column structure and VAC-specific allowance logic (`$vac_allowances` array)
- The VAC allowance lookup (`day`, `week`, `month` keyed by `requisition_period`) reads from the static `$vac_allowances` property — keep this logic unchanged
- Replace transaction table references with the new data structure
- `vet_health_card` is encrypted — decrypt using the existing encryption utility before writing to CSV (verify current implementation does this, maintain the same approach)

---

## Step 5 — Rewrite `generate_vac_pdf($start_date, $end_date)`

### 5.1 Query `meals_clients` — same as Step 4.1

### 5.2 Fetch invoice data — same as Step 4.2

### 5.3 Build PDF
- The PDF generation logic (layout, fonts, table structure) must remain unchanged
- Only replace the data source from transaction table rows to the new data structure
- All field references follow the same substitution pattern as the CSV methods

---

## Step 6 — Remove defunct references

### 6.1 Remove `MealsDB_DB::table()` calls for transaction tables
- The current generators use `MealsDB_DB::table('transactions')` and `MealsDB_DB::table('transaction_items')`
- After rewriting, these calls must not appear anywhere in `class-invoice-generator.php`
- If `MealsDB_DB::table()` is a convenience alias that only exists for these tables, check whether it can be removed after Phase 10

---

## Verification Checklist

- `generate_sdnb_legacy('M', '2025-01-01', '2025-01-31')` returns a CSV string with the correct header structure and correct row count based on SDNB Legacy Moncton clients with orders in January 2025
- `generate_sdnb_legacy('S', '2025-01-01', '2025-01-31')` returns Sussex-specific clients only
- `generate_sdnb_new_portal()` returns only SDNB clients with `use_legacy_billing = 0`
- `generate_vac_csv()` returns only Veteran clients
- Rate values in output match `meals_client_rates` records (not hard-coded or stale values)
- No reference to `meals_transactions` or `meals_transaction_items` remains in the file
- CSV column positions are identical to the pre-refactor output for the same data set
