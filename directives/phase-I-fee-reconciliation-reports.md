# Phase I: Fee Reconciliation Reports

## Goal

Build two reconciliation reports — Contribution Checker and Delivery Fee Checker — as methods on `MealsDB_Reports`, replacing the standalone old plugins (`contribution-checker/contribution-checker.php` and `delivery-fee-checker/delivery-fee-checker.php`).

## How fees work in this system

### Client Contribution
- **What it is:** A fixed monthly dollar amount that SDNB requires certain clients to pay toward their meals. Set by SDNB, not computed.
- **Where it's stored:** `meals_clients.client_contribution` (DECIMAL(10,2), 23/558 clients have non-zero values, ranging from $10.24 to $316.15)
- **How it appears on invoices:** Already handled. The SDNB Legacy invoice subtracts it from total cost (`$total_cost = $basic_cost + $tax_amount - $client_contribution`). The SDNB New Portal invoice outputs it in the "Client Contribution" CSV column (column index 15). The VAC invoice does NOT use it.
- **How it's collected:** Staff manually adds WooCommerce product ID **5675** ("Client Contribution") as a line item on customer orders. This is NOT automatic — it's a manual process.
- **What the checker does:** Compares the expected contribution amount (from the client record) against what was actually paid via product 5675 in a date range. Shows the difference so staff can spot missed charges.

### Delivery Fee
- **What it is:** A per-order fee charged to certain clients for delivery. Usually $10. Set by staff, not computed.
- **Where it's stored:** `meals_clients.delivery_fee` (DECIMAL(10,2), 45/558 clients have non-zero values, mostly $10)
- **How it appears on invoices:** It does NOT appear on any government invoice (SDNB or VAC). It appears on Jim's delivery slip (the driver PDF from `woo-order-export`) as "Collect: $X" and "Delivery Fee: $X".
- **How it's collected:** Staff manually adds WooCommerce product ID **4122** ("Delivery Fee") as a line item on customer orders. This is NOT automatic.
- **What the checker does:** Compares expected fees (num_orders × delivery_fee) against actual paid via product 4122. Shows the difference.

---

## Fee product ID configuration

Store the fee product WooCommerce IDs as a WordPress option, following the same pattern as overage product IDs:

```php
// In plugin settings or install-schema.php:
add_option('mealsdb_fee_product_ids', [
    'client_contribution' => 5675,
    'delivery_fee'        => 4122,
]);
```

Add a helper to retrieve them:

```php
public static function get_fee_product_ids(): array {
    return get_option('mealsdb_fee_product_ids', [
        'client_contribution' => 5675,
        'delivery_fee'        => 4122,
    ]);
}
```

This goes in whichever class currently hosts `get_overage_product_ids()` — likely `MealsDB_Invoice_Generator` or a config class. If no such method exists yet, add it to `MealsDB_Invoice_Generator`.

---

## Report 1: Contribution Checker

### Method signature

Add to `includes/services/class-reports.php`:

```php
/**
 * Reconcile client contributions: expected (from meals_clients) vs actual paid (product 5675).
 *
 * @param string $start_date Y-m-d
 * @param string $end_date   Y-m-d
 * @return array ['rows' => [...], 'summary' => [...]]
 */
public static function contribution_reconciliation(string $start_date, string $end_date): array
```

### Logic

1. **Query `meals_clients`** where `client_contribution > 0 AND active = 1 AND wp_user_id > 0`. Select: `client_id`, `wp_user_id`, `first_name`, `last_name`, `client_contribution`, `client_type`.

2. **For each client**, query WooCommerce orders in the date range for that `wp_user_id`. Use `MealsDB_WC_Order_Query` — specifically `get_orders_for_users()` or equivalent. The existing method returns orders with items. Sum `line_subtotal` for items where `wc_product_id` equals the contribution product ID (5675 from the fee config option).

3. **Calculate difference:** `expected - actual_paid`. A positive difference means they were undercharged.

4. **Return format:**
```php
[
    'rows' => [
        [
            'client_id'      => 42,
            'wp_user_id'     => 516,
            'first_name'     => 'Jane',
            'last_name'      => 'Doe',
            'client_type'    => 'SDNB',
            'expected'       => 91.13,    // meals_clients.client_contribution
            'actual_paid'    => 82.00,    // Sum of product 5675 line_subtotals
            'difference'     => 9.13,     // expected - actual_paid
        ],
        // ...
    ],
    'summary' => [
        'total_clients'    => 23,
        'total_expected'   => 2145.50,
        'total_paid'       => 1980.00,
        'total_difference' => 165.50,
    ],
]
```

### How the old plugin queried orders

The old plugin used `wc_get_orders()` per user and iterated items:

```php
$orders = wc_get_orders([
    'limit'        => -1,
    'customer_id'  => $user_id,
    'date_created' => $start_date . '...' . $end_date,
]);
foreach ($orders as $order) {
    foreach ($order->get_items() as $item) {
        if ($item->get_product_id() == 5675) {
            $total_paid += $item->get_total();
        }
    }
}
```

Your plugin should use `MealsDB_WC_Order_Query` methods instead if possible (they use HPOS-compatible queries). If the existing query service doesn't support per-user date-range queries with item-level detail, add a method:

```php
/**
 * Get total paid for a specific product by a specific user in a date range.
 *
 * @param int    $wp_user_id
 * @param int    $wc_product_id
 * @param string $start_date Y-m-d
 * @param string $end_date   Y-m-d
 * @return float
 */
public function get_total_paid_for_product(int $wp_user_id, int $wc_product_id, string $start_date, string $end_date): float
```

This method should use WooCommerce's HPOS tables (`wc_orders` + `wc_order_product_lookup` or `woocommerce_order_items` + `woocommerce_order_itemmeta`) to sum the line total for matching product/user/date combinations.

---

## Report 2: Delivery Fee Checker

### Method signature

Add to `includes/services/class-reports.php`:

```php
/**
 * Reconcile delivery fees: expected (num_orders × fee) vs actual paid (product 4122).
 *
 * @param string $start_date Y-m-d
 * @param string $end_date   Y-m-d
 * @return array ['rows' => [...], 'summary' => [...]]
 */
public static function delivery_fee_reconciliation(string $start_date, string $end_date): array
```

### Logic

1. **Query `meals_clients`** where `delivery_fee > 0 AND active = 1 AND wp_user_id > 0`. Select: `client_id`, `wp_user_id`, `first_name`, `last_name`, `delivery_fee`, `client_type`.

2. **For each client:**
   - Count total WC orders in the date range (ALL orders, not just ones with product 4122). Statuses: `pending`, `processing`, `on-hold`, `completed`, `paid`.
   - Sum `line_subtotal` for items with `wc_product_id` = 4122 (delivery fee product).
   - Calculate: `total_owed = num_orders × delivery_fee`
   - Calculate: `difference = total_owed - actual_paid`

3. **Return format:**
```php
[
    'rows' => [
        [
            'client_id'    => 42,
            'wp_user_id'   => 516,
            'first_name'   => 'Jane',
            'last_name'    => 'Doe',
            'client_type'  => 'SDNB',
            'delivery_fee' => 10.00,   // Per-order fee rate
            'num_orders'   => 4,       // Total orders in period
            'total_owed'   => 40.00,   // num_orders × delivery_fee
            'actual_paid'  => 30.00,   // Sum of product 4122
            'difference'   => 10.00,   // owed - paid
        ],
    ],
    'summary' => [
        'total_clients'    => 45,
        'total_owed'       => 1800.00,
        'total_paid'       => 1650.00,
        'total_difference' => 150.00,
    ],
]
```

---

## AJAX handlers

**File:** `includes/ajax/class-ajax-invoice.php` (or create `class-ajax-reports.php` if that's more appropriate)

### Contribution checker AJAX

```php
add_action('wp_ajax_mealsdb_contribution_reconciliation', [__CLASS__, 'contribution_reconciliation']);
```

Handler:
- Check nonce and `manage_options`
- Read `$_POST['start_date']` and `$_POST['end_date']`
- Call `MealsDB_Reports::contribution_reconciliation($start_date, $end_date)`
- Return via `wp_send_json_success()`

### Delivery fee checker AJAX

```php
add_action('wp_ajax_mealsdb_delivery_fee_reconciliation', [__CLASS__, 'delivery_fee_reconciliation']);
```

Same pattern.

---

## Admin UI

Add a "Fee Reconciliation" tab/section to the Reports admin page. Two sub-sections:

### Contribution Checker
- Date range picker (start/end)
- "Run Report" button
- Results table: First Name, Last Name, Client Type, Expected Contribution, Actual Paid, Difference
- Rows with non-zero difference highlighted
- Summary row at bottom: totals for Expected, Paid, Difference
- "Export CSV" button

### Delivery Fee Checker
- Date range picker (start/end)
- "Run Report" button
- Results table: First Name, Last Name, Client Type, Fee Rate, # Orders, Total Owed, Actual Paid, Difference
- Toggle checkboxes: "Show zero fee" and "Show zero difference" (hidden by default, matching old plugin behavior)
- Summary row at bottom
- "Export CSV" button

The old plugins also allowed inline editing of the contribution/delivery_fee values and had an "Update" button. This is NOT needed in the new plugin because staff can edit these values on the client edit form. The report should link each client name to their edit page instead.

---

## Key constraints

- External DB via `MealsDB_DB::get_connection()` (mysqli)
- WordPress/WooCommerce via `$wpdb` and `wc_get_orders()` or HPOS tables
- Table names via `MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS)`
- Fee product IDs from `get_option('mealsdb_fee_product_ids')` — NOT hardcoded
- The `meals_products` table has `product_type ENUM('meal','side','fee','other')` — products 5675 and 4122 should be tagged as `fee` type if they exist in meals_products, but the reconciliation reports should match by WC product ID, not by product_type
- WC order statuses to include: `pending`, `processing`, `on-hold`, `completed`, `paid` (matches old plugins)
- Do NOT add inline editing of fees — link to client edit page instead
