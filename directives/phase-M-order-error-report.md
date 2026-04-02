# Phase M: Standalone Order Error Report

## Goal

Add a standalone data quality report that checks ALL WooCommerce orders for common problems, replicating `error-orders/error-orders.php`. Phase E added `validate_client_row()` and `check_new_user_flag()` which run during invoice generation, but those only catch errors for government clients at invoice time. This report covers all client types and runs on demand.

## What the old system checked

The old `error-orders.php` plugin scanned orders in a date range and flagged:

1. **Missing first name** — `billing_first_name` is empty
2. **Missing last name** — `billing_last_name` is empty
3. **Nickname > 3 characters** — The WP `nickname` meta (which maps to `delivery_initials` / bag initials) should be exactly 3 characters. Longer values suggest someone typed a name instead of initials.
4. **Missing zone** — `billing_address_2` (zone field) is empty. In the new system this is `delivery_area_name` in meals_clients.
5. **Missing shipping address** — `shipping_address_1` is empty. Means the order can't be routed for delivery.
6. **Invalid zone** — Zone value doesn't match expected patterns ("Zone 1", "Zone 2", etc.)

### Output

An HTML table showing one row per flagged order with: Order ID (linked to WC edit page), Customer Name, Error Type, Error Detail. Grouped by error type with counts.

---

## Implementation

### New method on `MealsDB_Reports`

```php
/**
 * Run data quality checks across WC orders in a date range.
 *
 * @param string $start_date Y-m-d
 * @param string $end_date   Y-m-d
 * @return array ['errors' => [...], 'summary' => [...]]
 */
public function order_error_report(string $start_date, string $end_date): array
```

### Logic

1. **Query WC orders** in the date range. Use HPOS tables (`wc_orders`). Statuses: all non-trashed statuses (or at minimum: `pending`, `processing`, `on-hold`, `completed`, `paid`).

2. **For each order**, look up the customer's `meals_clients` record via `wp_user_id`. Also get WC order address data.

3. **Run checks:**

```php
$errors = [];

// Check 1: Missing first name
$first = $order->get_billing_first_name();
if (empty(trim($first))) {
    $errors[] = ['type' => 'missing_first_name', 'detail' => 'Billing first name is empty'];
}

// Check 2: Missing last name
$last = $order->get_billing_last_name();
if (empty(trim($last))) {
    $errors[] = ['type' => 'missing_last_name', 'detail' => 'Billing last name is empty'];
}

// Check 3: Nickname too long (bag initials should be exactly 3 chars)
// Get from meals_clients.delivery_initials or wp_usermeta.nickname
$initials = $client['delivery_initials'] ?? '';
if (strlen($initials) > 3) {
    $errors[] = [
        'type' => 'nickname_too_long',
        'detail' => 'Initials "' . $initials . '" is ' . strlen($initials) . ' chars (expected 3)',
    ];
}

// Check 4: Missing zone
$zone = $client['delivery_area_name'] ?? '';
if (empty(trim($zone))) {
    $errors[] = ['type' => 'missing_zone', 'detail' => 'No delivery zone assigned'];
}

// Check 5: Missing shipping/delivery address
$address = $client['delivery_street_name'] ?? '';
if (empty(trim($address))) {
    // Fall back to WC shipping address
    $wc_address = $order->get_shipping_address_1();
    if (empty(trim($wc_address))) {
        $errors[] = ['type' => 'missing_address', 'detail' => 'No shipping/delivery address'];
    }
}

// Check 6: Invalid zone format
if (!empty($zone)) {
    $valid_zones = ['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5', 'Zone 6'];
    if (!in_array($zone, $valid_zones)) {
        $errors[] = [
            'type' => 'invalid_zone',
            'detail' => 'Zone "' . $zone . '" is not a recognized zone',
        ];
    }
}
```

4. **For orders with no matching meals_clients record** (guest orders, or wp_user_id not in meals_clients), still check WC billing/shipping fields directly. Flag as `no_client_record` if the customer has placed an order but doesn't exist in the meals_clients database.

### Return format

```php
[
    'errors' => [
        [
            'order_id'      => 12345,
            'order_date'    => '2025-03-15',
            'customer_name' => 'Jane Doe',
            'wp_user_id'    => 516,
            'error_type'    => 'missing_zone',
            'error_detail'  => 'No delivery zone assigned',
        ],
        // ...
    ],
    'summary' => [
        'total_orders_checked'  => 450,
        'orders_with_errors'    => 23,
        'error_counts' => [
            'missing_first_name' => 2,
            'missing_last_name'  => 1,
            'nickname_too_long'  => 5,
            'missing_zone'       => 8,
            'missing_address'    => 4,
            'invalid_zone'       => 3,
            'no_client_record'   => 7,
        ],
    ],
]
```

---

## AJAX endpoint

**File:** `includes/ajax/class-ajax-reports.php`

Add action: `wp_ajax_mealsdb_order_error_report`

Handler:
- Check nonce and `manage_options`
- Read `$_POST['start_date']` and `$_POST['end_date']`
- Call `$reports->order_error_report($start_date, $end_date)`
- Return via `wp_send_json_success()`

---

## UI

Add as a section on the Fee Reconciliation tab (rename to "Reports & Reconciliation") or as its own tab. Given the existing tabs are already numerous, adding it as a third section on the `fees` tab makes sense — rename the tab to `reports`.

Alternatively, add a new tab `errors`:

In `class-admin-ui.php` tabs array:
```php
'errors' => __('Order Errors', 'meals-db'),
```

### View file: `views/order-errors.php`

Date range picker, "Run Report" button, results table with error type color coding, summary stats at top, "Export CSV" button.

Error type color coding:
- `missing_first_name`, `missing_last_name` → red background
- `nickname_too_long` → yellow background
- `missing_zone`, `invalid_zone` → orange background
- `missing_address` → orange background
- `no_client_record` → blue background (informational)

---

## Performance note

The old plugin iterated over every order with `wc_get_order()` per order. For large date ranges this is slow. The new implementation should:

1. Batch-fetch all order IDs + basic data from `wc_orders` HPOS table
2. Batch-fetch all customer_ids and look up meals_clients records in one query
3. Only instantiate `wc_get_order()` for orders that need WC-level address checks (no meals_clients record)

---

## Relationship to Phase E

Phase E's `validate_client_row()` runs inline during invoice generation and checks government-specific things (duplicate names, rate mismatches). This standalone report is complementary — it checks WC order data quality across all client types. They can share validation logic where it overlaps, but the standalone report should NOT depend on invoice generation code.

---

## Key constraints

- WC orders via HPOS tables (`wc_orders` in `$wpdb`)
- meals_clients via `MealsDB_DB::get_connection()` (mysqli)
- The valid zone list should come from a config constant or option, not hardcoded in the check logic
- Decrypt `delivery_initials` is NOT needed (it's stored as plaintext)
- The report should handle orders where the customer doesn't exist in meals_clients gracefully
- Performance: avoid instantiating `wc_get_order()` for every order — batch-fetch from HPOS where possible
