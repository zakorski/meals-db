# Phase K: Jim's Driver Delivery Slips

## Goal

Add a driver-facing delivery slip that shows one page per order with full customer details and collection amounts. This replicates `woo-order-export/includes/export-orders.php → fetch_jims_orders / export_jims_orders_pdf`.

## What the old system did

Jim's driver slips are a separate output from the staff packing/picking/delivery slips. They're printed for the delivery driver and show customer-visible information (full names, addresses, phone numbers) plus cash collection amounts.

### Old output per order:

```
[Customer Full Name]
[Address]
[City]
[Phone]

Subtotal: $XX.XX
Tax: $XX.XX
Total: $XX.XX

Collect: $XX.XX          ← conditional, based on payment method + delivery fee
Delivery Fee: $XX.XX     ← shown if fee > 0
```

### Collection logic from the old plugin (export-orders.php lines 425–466):

```php
$delivery_fee = (float) get_user_meta($user_id, 'delivery_fee', true);
$payment_method = $order->get_payment_method();
$customer_group = get_user_meta($user_id, 'customer_group', true);
$subtotal = $order->get_subtotal();
$tax = $order->get_total_tax();
$total = $order->get_total();

if ($payment_method === 'cash' && $customer_group === 'private') {
    // Private cash customers: collect total + delivery fee
    $total_with_delivery = $total + $delivery_fee;
    $pdf->Cell(0, 10, 'Collect: $' . number_format($total_with_delivery, 2), 0, 1, 'L');
} elseif ($payment_method !== 'cash' && $delivery_fee > 0) {
    // Non-cash customers with delivery fee: collect fee only
    $pdf->Cell(0, 10, 'Collect: $' . number_format($delivery_fee, 2), 0, 1, 'L');
}

// Always show delivery fee line if set
if ($delivery_fee > 0) {
    $pdf->Cell(0, 10, 'Delivery Fee: $' . number_format($delivery_fee, 2), 0, 1, 'L');
}
```

### Grouping

Orders are grouped by zone (`shipping_address_2` in old system → `delivery_area_name` in meals_clients). Zone header printed before each zone group.

---

## Implementation

### New method on `MealsDB_Delivery_Slip_Generator`

```php
/**
 * Generate driver delivery slips for a specific date.
 *
 * Unlike packing/picking/delivery slips which use initials for privacy,
 * these show full customer info for the delivery driver.
 *
 * @param string $delivery_date Y-m-d
 * @return array Array of slip data grouped by zone
 */
public function generate_driver_slips(string $delivery_date): array
```

### Data sources

For each client scheduled for delivery on that date, fetch:

**From `meals_clients`** (external DB via `MealsDB_DB::get_connection()`):
- `first_name`, `last_name` (decrypted PII)
- `delivery_street_name` (full address)
- `delivery_city`
- `client_phone_1`
- `delivery_fee` (DECIMAL)
- `payment_method` (VARCHAR)
- `client_type` (ENUM — used as `customer_group`)
- `delivery_area_name` (zone name for grouping)
- `delivery_area_zone` (zone code for sorting)

**From WC orders** (via `MealsDB_WC_Order_Query`):
- Order ID
- `get_subtotal()` (before tax)
- `get_total_tax()`
- `get_total()` (after tax)
- `get_payment_method()` — NOTE: use WC order payment method, NOT the `payment_method` from meals_clients. The meals_clients field is the client's *preferred* method; the WC order has the *actual* method used for that specific order.

### SQL changes

Add to the client query (currently `get_clients_for_delivery_date()`): extend the SELECT or create a separate method that fetches the additional PII fields. The current method only fetches `client_id, wp_user_id, delivery_initials, delivery_area_zone, delivery_area_name, delivery_city, delivery_street_name`. The driver slip needs `first_name, last_name, client_phone_1, delivery_fee, payment_method, client_type` too.

**Recommended approach:** Create a new method `get_clients_for_driver_slips(string $delivery_date)` that queries the full set of fields including encrypted PII. Decrypt `first_name` and `last_name` using `MealsDB_Encryption::decrypt()`.

### Return format

```php
[
    [
        'zone'      => 'Zone 1',
        'zone_code' => 'M',
        'orders'    => [
            [
                'order_id'       => 12345,
                'first_name'     => 'Jane',
                'last_name'      => 'Doe',
                'address'        => 'Apt I - 127 Main St',
                'city'           => 'Moncton',
                'phone'          => '506-555-1234',
                'subtotal'       => 45.50,
                'tax'            => 6.83,
                'total'          => 52.33,
                'collect'        => 62.33,    // or null if nothing to collect
                'delivery_fee'   => 10.00,    // or 0
                'payment_method' => 'cash',   // from WC order
                'client_type'    => 'private',
            ],
            // ...
        ],
    ],
    // more zones...
]
```

### Collection calculation (reproduce old logic exactly)

```php
$collect = null;
$wc_payment_method = $order_payment_method;  // from WC order, not meals_clients
$client_type = strtolower($client['client_type'] ?? '');
$delivery_fee = (float) ($client['delivery_fee'] ?? 0);
$total = (float) $order_total;

if ($wc_payment_method === 'cash' && $client_type === 'private') {
    $collect = $total + $delivery_fee;
} elseif ($wc_payment_method !== 'cash' && $delivery_fee > 0) {
    $collect = $delivery_fee;
}
```

---

## AJAX endpoint

**File:** `includes/ajax/class-ajax-delivery-slips.php`

Add action: `wp_ajax_mealsdb_generate_driver_slips`

Handler follows the same pattern as existing slip endpoints:
- Check nonce and capability
- Read `$_POST['delivery_date']`
- Instantiate `MealsDB_Delivery_Slip_Generator` with `MealsDB_WC_Order_Query`
- Call `generate_driver_slips($delivery_date)`
- Return via `wp_send_json_success()`

---

## UI changes

### Add button to `views/daily-slips.php`

Add a fourth button next to Packing/Picking/Delivery:

```html
<button type="button" class="button" id="mealsdb-gen-driver">
    Driver Slips
</button>
```

### JavaScript renderer

The driver slip renderer should produce an HTML table (like the others) but with full customer details. Unlike the privacy-focused packing/delivery slips that use initials, this one shows full names and addresses.

```javascript
function renderDriverSlips(data) {
    if (!data.length) return '<p>No orders found.</p>';
    var html = '<h3>Driver Delivery Slips</h3>';
    
    $.each(data, function(i, zone) {
        html += '<h3>' + esc(zone.zone) + '</h3>';
        html += '<table><thead><tr>';
        html += '<th>Name</th><th>Address</th><th>City</th><th>Phone</th>';
        html += '<th style="text-align:right">Subtotal</th>';
        html += '<th style="text-align:right">Tax</th>';
        html += '<th style="text-align:right">Total</th>';
        html += '<th style="text-align:right">Collect</th>';
        html += '<th style="text-align:right">Delivery Fee</th>';
        html += '</tr></thead><tbody>';
        
        $.each(zone.orders, function(j, o) {
            html += '<tr>';
            html += '<td>' + esc(o.first_name) + ' ' + esc(o.last_name) + '</td>';
            html += '<td>' + esc(o.address) + '</td>';
            html += '<td>' + esc(o.city) + '</td>';
            html += '<td>' + esc(o.phone) + '</td>';
            html += '<td style="text-align:right">$' + fmt(o.subtotal) + '</td>';
            html += '<td style="text-align:right">$' + fmt(o.tax) + '</td>';
            html += '<td style="text-align:right">$' + fmt(o.total) + '</td>';
            html += '<td style="text-align:right">' + (o.collect !== null ? '$' + fmt(o.collect) : '') + '</td>';
            html += '<td style="text-align:right">' + (o.delivery_fee > 0 ? '$' + fmt(o.delivery_fee) : '') + '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
    });
    
    return html;
}
```

### Print CSS

The existing print CSS in daily-slips.php already hides admin chrome. The driver slips should use the same print formatting. Consider adding `page-break-before: always` for each zone header in print mode.

---

## WC order financial data

The existing `get_orders_for_date()` returns orders via `get_orders_with_items_for_users()`, which pulls from HPOS tables. This returns `date_created_gmt` and item data, but NOT order totals (subtotal, tax, total). You'll need to either:

**Option A:** Extend `get_orders_with_items_for_users()` to also return financial fields from `wc_orders` (the `total_amount`, `tax_amount` columns).

**Option B:** After getting order IDs, call `wc_get_order($order_id)` to get `get_subtotal()`, `get_total_tax()`, `get_total()`, and `get_payment_method()`. This is slower but guaranteed accurate.

**Recommendation:** Option B for correctness. The driver slip is a daily report (5-50 orders), so the per-order WC object instantiation is acceptable.

---

## Key constraints

- Decrypt PII using `MealsDB_Encryption::decrypt()` for first_name and last_name
- Use WC order's `get_payment_method()` for the actual payment method (not meals_clients.payment_method)
- Use `meals_clients.delivery_fee` for the fee amount (not from WC order items)
- Use `meals_clients.client_type` as the customer_group equivalent
- The `delivery_day` column must be populated for this to work — currently 0/558 clients have this set
- External DB via `MealsDB_DB::get_connection()` (mysqli), WC orders via `$wpdb` / `wc_get_order()`
