# Phase 8 — Delivery Slip Generator

## Objective

Create a new service class `MealsDB_Delivery_Slip_Generator` that produces packing, picking, and delivery slips from WC HPOS order data joined with `meals_clients` delivery information. Privacy is preserved by using `delivery_initials` (3-letter) rather than full names on routing documents.

---

## Context

Three document types are needed:

| Document | Purpose | Grouped By | Uses Full Name? |
|---|---|---|---|
| Packing Slip | Staff packs orders | Client initials | No — initials only |
| Picking Slip | Warehouse pulls product | Product name | No |
| Delivery Slip | Driver route sheet | Delivery zone + day | No — initials only |

All three are generated for a specific date (single delivery day), not a date range. Orders are identified by `date_created_gmt` matching the delivery date.

The `meals_clients` columns used:
- `client_id`, `wp_user_id`, `delivery_initials`, `delivery_area_zone`, `delivery_area_name`, `delivery_day`, `delivery_city`, `delivery_street_number`, `delivery_street_name`, `delivery_apartment_number`

---

## Step 1 — Create `includes/services/class-delivery-slip-generator.php`

Create a new class `MealsDB_Delivery_Slip_Generator`.

### 1.1 Constructor
```php
public function __construct(MealsDB_WC_Order_Query $order_query)
```
Store `$order_query` as a private property.

### 1.2 Implement `get_clients_for_delivery_date(string $delivery_date): array`
- Query `meals_clients` WHERE `active = 1` AND `wp_user_id > 0`
- Filter to clients whose `delivery_day` matches the day-of-week of `$delivery_date`
  - Use `DAYNAME(?)` or PHP `date('l', strtotime($delivery_date))`
  - `delivery_day` stores a day name string (e.g., `'Monday'`) — match case-insensitively
- Return rows with: `client_id`, `wp_user_id`, `delivery_initials`, `delivery_area_zone`, `delivery_area_name`, `delivery_city`, `delivery_street_number`, `delivery_street_name`, `delivery_apartment_number`
- Keyed by `wp_user_id`

### 1.3 Implement `get_orders_for_date(array $wp_user_ids, string $delivery_date): array`
- Call `$this->order_query->get_orders_with_items_for_users($wp_user_ids, $delivery_date, $delivery_date)`
- Returns orders with items attached

### 1.4 Implement `generate_packing_slip(string $delivery_date): array`
Returns a structured array (not yet formatted as a document), one entry per order:

```
[
  'initials'      => 'ABC',
  'zone'          => 'M',
  'area_name'     => 'Moncton North',
  'items'         => [
    ['name' => 'Butter Chicken', 'quantity' => 2, 'product_type' => 'meal'],
    ['name' => 'Dinner Roll', 'quantity' => 2, 'product_type' => 'side'],
  ]
]
```

Logic:
- Call `get_clients_for_delivery_date($delivery_date)`
- Call `get_orders_for_date(array_keys($clients), $delivery_date)`
- For each order, look up the client by `wp_user_id`
- Merge item names with product type from `meals_products` (via `get_product_types_for_ids()`)
- Sort output by `zone` ASC, then `initials` ASC

### 1.5 Implement `generate_picking_slip(string $delivery_date): array`
Returns a product-grouped summary:

```
[
  ['product_name' => 'Butter Chicken', 'product_type' => 'meal', 'total_quantity' => 14],
  ['product_name' => 'Dinner Roll',    'product_type' => 'side', 'total_quantity' => 8],
]
```

Logic:
- Same order/client fetch as packing slip
- Aggregate quantities by `wc_product_id` across all orders for the date
- Join against `meals_products` for `product_type`
- Sort by `product_type` ASC, then `product_name` ASC

### 1.6 Implement `generate_delivery_slip(string $delivery_date): array`
Returns a route-grouped list:

```
[
  'zone'    => 'M',
  'area'    => 'Moncton North',
  'stops'   => [
    [
      'initials'  => 'ABC',
      'address'   => '123 Main St, Apt 4, Moncton',
      'item_summary' => '2x Meal + 2x Side',
    ]
  ]
]
```

Logic:
- Same order/client fetch as packing slip
- Group by `delivery_area_zone`, then `delivery_area_name`
- Within each group, sort stops by `delivery_street_name` ASC, `delivery_street_number` ASC
- Format address from `delivery_street_number`, `delivery_street_name`, `delivery_apartment_number`, `delivery_city`
- Item summary: count meals vs sides using `meals_products.product_type`

---

## Step 2 — Create AJAX handler `includes/ajax/class-ajax-delivery-slips.php`

Create a new class `MealsDB_Ajax_Delivery_Slips`.

### 2.1 Register AJAX actions
```
wp_ajax_mealsdb_generate_packing_slip
wp_ajax_mealsdb_generate_picking_slip
wp_ajax_mealsdb_generate_delivery_slip
```

All require `MealsDB_Permissions::required_capability()`. Apply nonce and rate limiting.

### 2.2 Each handler
- Read `delivery_date` from `$_REQUEST` — sanitize as `Y-m-d` format
- Validate it parses as a valid date
- Instantiate `MealsDB_Delivery_Slip_Generator` with a new `MealsDB_WC_Order_Query($GLOBALS['wpdb'])`
- Call the appropriate generate method
- Return `['success' => true, 'data' => $result]`

---

## Step 3 — Add admin UI

### 3.1 Add a "Daily Slips" page or section
Add a new admin menu entry or sub-section under the existing Meals DB menu. UI needs:
- A date picker defaulting to today's date
- Three buttons: "Generate Packing Slip", "Generate Picking Slip", "Generate Delivery Slip"
- A display area for the generated slip (rendered as a print-friendly HTML table)
- A "Print" button that triggers `window.print()`

### 3.2 Print CSS
Add a print media query in `assets/css/admin.css` (or a new `admin-slips.css`) that:
- Hides all WordPress admin navigation when printing
- Shows only the slip content area
- Uses a clean, readable table layout

---

## Step 4 — Register in plugin bootstrap

### 4.1 Autoloader
- Confirm `MealsDB_Delivery_Slip_Generator` and `MealsDB_Ajax_Delivery_Slips` are reachable

### 4.2 Initialize
- Call `MealsDB_Ajax_Delivery_Slips::init()` in the AJAX bootstrap
- Register the admin page in `MealsDB_Admin_UI`

---

## Verification Checklist

- `generate_packing_slip('2025-03-03')` returns one entry per order for clients with `delivery_day = 'Monday'` (if March 3, 2025 is a Monday)
- Entries are sorted by zone then initials
- `generate_picking_slip()` correctly aggregates quantities across all orders for the date
- `generate_delivery_slip()` groups stops by zone and area, sorted by street
- Full names do not appear anywhere in packing or delivery slip output — only `delivery_initials`
- Items are correctly classified as meal or side using `meals_products.product_type`
- Print view hides WP admin chrome
