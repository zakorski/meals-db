# Phase 5 — WC HPOS Query Service Layer

## Objective

Create a reusable service class `MealsDB_WC_Order_Query` that encapsulates all queries against WooCommerce HPOS tables. This class is the single point through which invoice generators, slip generators, and reports access WC order data. No other class should query WC tables directly.

---

## Context

WooCommerce 9.x with HPOS enabled (no compatibility mode) uses the following tables (all prefixed with `$wpdb->prefix`):

| Table | Contents |
|---|---|
| `wc_orders` | Order header: `id`, `customer_id`, `status`, `date_created_gmt`, `date_updated_gmt`, `total_amount`, `tax_amount` |
| `wc_orders_meta` | Order meta: `order_id`, `meta_key`, `meta_value` — includes `mealsdb_client_user_id`, `mealsdb_client_id`, `mealsdb_rate_id` |
| `wc_order_items` | Line items: `order_item_id`, `order_id`, `order_item_name`, `order_item_type` |
| `wc_order_operational_data` | Operational fields per order |
| `woocommerce_order_items` | Legacy alias — do NOT use; use `wc_order_items` |
| `woocommerce_order_itemmeta` | Item meta: `meta_id`, `order_item_id`, `meta_key`, `meta_value` — includes `_product_id`, `_qty`, `_line_subtotal`, `_line_tax`, `_line_total`, `_line_subtotal_tax` |

The join chain is:
```
meals_clients.wp_user_id
    = wc_orders.customer_id
    → wc_orders_meta (mealsdb_rate_id → meals_client_rates.rate_id)
    → wc_order_items (order_id)
    → woocommerce_order_itemmeta (_product_id, _qty, _line_subtotal, _line_tax, _line_total)
    → meals_products.wc_product_id
```

---

## Step 1 — Create `includes/services/class-wc-order-query.php`

Create a new class `MealsDB_WC_Order_Query`.

### 1.1 Constructor
```php
public function __construct(wpdb $wpdb)
```
Store `$wpdb` as a private property. Do not use the global `$wpdb` directly inside methods — always use the injected instance.

### 1.2 Table name helpers (private methods)
Add private methods that return fully-prefixed table names:
- `orders_table(): string` → `$this->wpdb->prefix . 'wc_orders'`
- `orders_meta_table(): string` → `$this->wpdb->prefix . 'wc_orders_meta'`
- `order_items_table(): string` → `$this->wpdb->prefix . 'wc_order_items'`
- `order_itemmeta_table(): string` → `$this->wpdb->prefix . 'woocommerce_order_itemmeta'`

### 1.3 Implement `get_orders_for_users()`
```php
public function get_orders_for_users(
    array $wp_user_ids,
    string $start_date,
    string $end_date,
    array $exclude_statuses = ['wc-cancelled', 'wc-trash', 'trash']
): array
```
- Returns an array of order rows for all users in `$wp_user_ids` within the date range
- Query:
```sql
SELECT
    o.id              AS order_id,
    o.customer_id     AS wp_user_id,
    o.status,
    o.date_created_gmt,
    o.total_amount,
    o.tax_amount,
    rate_meta.meta_value AS mealsdb_rate_id
FROM {orders_table} o
LEFT JOIN {orders_meta_table} rate_meta
    ON rate_meta.order_id = o.id
    AND rate_meta.meta_key = 'mealsdb_rate_id'
WHERE o.customer_id IN ({placeholders})
    AND DATE(o.date_created_gmt) BETWEEN %s AND %s
    AND o.status NOT IN ({status_placeholders})
    AND o.type = 'shop_order'
ORDER BY o.date_created_gmt ASC
```
- Use `$wpdb->prepare()` with proper placeholders for all parameters
- Return empty array if `$wp_user_ids` is empty

### 1.4 Implement `get_order_items()`
```php
public function get_order_items(array $order_ids): array
```
- Returns all line items for the given order IDs, keyed by `order_id`
- Query:
```sql
SELECT
    oi.order_item_id,
    oi.order_id,
    oi.order_item_name,
    product_meta.meta_value   AS wc_product_id,
    qty_meta.meta_value       AS quantity,
    subtotal_meta.meta_value  AS line_subtotal,
    tax_meta.meta_value       AS line_tax,
    total_meta.meta_value     AS line_total
FROM {order_items_table} oi
INNER JOIN {order_itemmeta_table} product_meta
    ON product_meta.order_item_id = oi.order_item_id
    AND product_meta.meta_key = '_product_id'
INNER JOIN {order_itemmeta_table} qty_meta
    ON qty_meta.order_item_id = oi.order_item_id
    AND qty_meta.meta_key = '_qty'
INNER JOIN {order_itemmeta_table} subtotal_meta
    ON subtotal_meta.order_item_id = oi.order_item_id
    AND subtotal_meta.meta_key = '_line_subtotal'
LEFT JOIN {order_itemmeta_table} tax_meta
    ON tax_meta.order_item_id = oi.order_item_id
    AND tax_meta.meta_key = '_line_tax'
INNER JOIN {order_itemmeta_table} total_meta
    ON total_meta.order_item_id = oi.order_item_id
    AND total_meta.meta_key = '_line_total'
WHERE oi.order_id IN ({placeholders})
    AND oi.order_item_type = 'line_item'
ORDER BY oi.order_id, oi.order_item_id
```
- Return as a flat array of rows; callers group by `order_id` as needed
- Return empty array if `$order_ids` is empty

### 1.5 Implement `get_orders_with_items_for_users()`
```php
public function get_orders_with_items_for_users(
    array $wp_user_ids,
    string $start_date,
    string $end_date,
    array $exclude_statuses = ['wc-cancelled', 'wc-trash', 'trash']
): array
```
- Calls `get_orders_for_users()` to get orders
- If no orders found, return `[]`
- Extracts all `order_id` values
- Calls `get_order_items()` with those IDs
- Groups items by `order_id` and attaches them to each order as `$order['items']`
- Returns the enriched orders array

### 1.6 Implement `get_product_types_for_ids()`
```php
public function get_product_types_for_ids(array $wc_product_ids): array
```
- Queries `meals_products` (external DB via `MealsDB_DB::get_connection()`) for `wc_product_id`, `product_type`, `taxable`, `case_size`, `unit_cost`
- Returns array keyed by `wc_product_id`
- Returns empty array if `$wc_product_ids` is empty

### 1.7 Implement `resolve_rate_for_order()`
```php
public function resolve_rate_for_order(int $rate_id, int $client_id): float
```
- If `$rate_id > 0`, query `meals_client_rates` WHERE `rate_id = $rate_id` AND `client_id = $client_id` — return `rate` as float
- If not found or `$rate_id = 0`, query `meals_client_rates` WHERE `client_id = $client_id` AND `is_default = 1` LIMIT 1 — return `rate` as float
- If still not found, return `0.00`
- Uses `MealsDB_DB::get_connection()` (external DB)

---

## Step 2 — Register in autoloader / bootstrap

### 2.1 Confirm autoloader coverage
- The file is `includes/services/class-wc-order-query.php`
- Confirm `MealsDB_Autoloader` will find it, or add a `require_once` in `includes/class-plugin.php`

### 2.2 Instantiation pattern
`MealsDB_WC_Order_Query` should be instantiated with the global `$wpdb`:
```php
$query = new MealsDB_WC_Order_Query($GLOBALS['wpdb']);
```
Invoice generators and report classes create their own instance. No singleton needed.

---

## Step 3 — Update `includes/services/class-reports.php`

### 3.1 Replace legacy post-table queries in `get_resupply_requirements()`
The current query joins against `$this->wpdb->posts` (`p.post_date`, `p.post_status`). Replace with HPOS:
- Join against `wc_orders` instead of `posts`
- Use `o.date_created_gmt` instead of `p.post_date`
- Use `o.status NOT IN ('wc-cancelled','wc-trash','trash')` instead of `p.post_status LIKE 'wc-%'`
- Use `wc_order_items` instead of `woocommerce_order_items`
- Use the `order_item_id` join to `woocommerce_order_itemmeta` (this table name is unchanged)

### 3.2 Replace legacy post-table queries in `get_meal_breakdown()`
Same substitution as 3.1.

### 3.3 Inject `MealsDB_WC_Order_Query` into `MealsDB_Reports`
- Add `MealsDB_WC_Order_Query $order_query` as a constructor parameter (optional, default to creating one internally using global `$wpdb`)
- Use the order query's table name helpers to keep table references consistent

---

## Verification Checklist

- `MealsDB_WC_Order_Query::get_orders_for_users()` returns correct orders for a known test user within a date range
- `get_order_items()` returns correct line items with `wc_product_id`, `quantity`, `line_subtotal`, `line_tax`, `line_total`
- `get_orders_with_items_for_users()` returns orders with `items` array attached
- `resolve_rate_for_order()` returns the rate from `meals_client_rates` when `rate_id` is provided
- `resolve_rate_for_order()` falls back to the default rate when `rate_id` is 0 or not found
- `get_resupply_requirements()` in `MealsDB_Reports` returns correct data using HPOS tables (no reference to `wp_posts`)
- `get_meal_breakdown()` in `MealsDB_Reports` returns correct data using HPOS tables
- No direct references to `wp_posts`, `woocommerce_order_items` (legacy), or `woocommerce_order_itemmeta` remain in `class-reports.php`
