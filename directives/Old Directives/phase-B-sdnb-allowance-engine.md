# Phase B — SDNB Allowance Calculation Engine

## Objective

Add a private static method to `MealsDB_Invoice_Generator` that computes monthly mains/sides allowances, splits ordered items into billable vs overage quantities, and separates sides into taxable vs non-taxable — all driven by `meals_clients` columns and `meals_products` metadata. Then rewrite `generate_sdnb_legacy()` to use this engine instead of flat per-order rows.

---

## Context — How the old system calculated allowances

The old `sdnb-month-end.php` worked like this for each client:

1. Read per-user values: `mains` (int), `sides` (int), `service` (string: `day`/`week`/`month`)
2. Convert to monthly allowance using `weeks_in_month` (admin input) and `days_in_month` (derived from end date)
3. Compare ordered quantities to allowance → billable vs overage
4. Split sides into taxable (desserts + muffins) and non-taxable (cereal + soup)
5. Allocate taxable sides first against the sides allowance, then non-taxable with whatever remains

### Your plugin equivalents:

| Old concept | Your plugin's actual field/table | Column/method |
|---|---|---|
| `user_meta('mains')` | `meals_clients.allowance_mains` | Added in Phase A |
| `user_meta('sides')` | `meals_clients.allowance_sides` | Added in Phase A |
| `user_meta('service')` | `meals_clients.requisition_period` | Already exists, values: `Day`, `Week`, `Month` |
| `user_meta('basic_cost')` | `meals_client_rates.rate` via `default_rate_id` | Already exists |
| `user_meta('contribution')` | `meals_clients.client_contribution` | Already exists |
| Product categories 25/37 (taxable sides) | `meals_products.product_type = 'side' AND meals_products.taxable = 1` | Already exists |
| Product categories 23/43 (non-taxable sides) | `meals_products.product_type = 'side' AND meals_products.taxable = 0` | Already exists |
| Product category 35 (mains) | `meals_products.product_type = 'meal'` | Already exists |

---

## Step 1 — Add `weeks_in_month` parameter to the invoice form

### 1.1 Update the admin invoice view

**File:** `views/admin-invoice.php`

Add a new table row for "Number of Wednesdays" immediately after the `end_date` row (after line 84) and before the closing `</tbody>`. This field should only be visible when `sdnb_legacy` is selected.

```html
<tr id="weeks_row" style="display: none;">
    <th scope="row">
        <label for="weeks_in_month">Number of Wednesdays</label>
    </th>
    <td>
        <input type="number" name="weeks_in_month" id="weeks_in_month" class="small-text" min="1" max="6" value="4">
        <p class="description">The number of Wednesdays in the billing month. Used to calculate weekly client allowances.</p>
    </td>
</tr>
```

### 1.2 Update invoice.js to show/hide and submit the field

**File:** `assets/js/invoice.js`

In the `$('#invoice_type').on('change', ...)` handler (line 31), add visibility toggling for `#weeks_row` alongside the existing `#zone_row` logic:

```javascript
if (invoiceType === 'sdnb_legacy') {
    $('#zone_row').show();
    $('#zone').prop('required', true);
    $('#weeks_row').show();
} else {
    $('#zone_row').hide();
    $('#zone').prop('required', false);
    $('#weeks_row').hide();
}
```

In the download form construction (around line 78), add the `weeks_in_month` hidden field after the `end_date` field:

```javascript
downloadForm.append($('<input>', {
    type: 'hidden',
    name: 'weeks_in_month',
    value: $('#weeks_in_month').val() || '4'
}));
```

### 1.3 Update the AJAX handler to pass the parameter through

**File:** `includes/ajax/class-ajax-invoice.php`

In the `generate_invoice()` method (line 27), add after line 44:

```php
$weeks_in_month = intval($_POST['weeks_in_month'] ?? 4);
if ($weeks_in_month < 1 || $weeks_in_month > 6) {
    $weeks_in_month = 4;
}
```

In the `sdnb_legacy` case (line 69), change the method call to pass `$weeks_in_month`:

```php
self::download_sdnb_legacy($zone, $start_date, $end_date, $weeks_in_month);
```

Update the `download_sdnb_legacy()` private method signature (line 107) to accept the new parameter:

```php
private static function download_sdnb_legacy($zone, $start_date, $end_date, $weeks_in_month = 4) {
```

And pass it through to the generator:

```php
$csv_content = MealsDB_Invoice_Generator::generate_sdnb_legacy($zone, $start_date, $end_date, $weeks_in_month);
```

---

## Step 2 — Create the allowance calculation method

**File:** `includes/services/class-invoice-generator.php`

Add this private static method to the `MealsDB_Invoice_Generator` class. Place it after the existing `get_invoice_data_for_clients()` method (after line 151).

### 2.1 Method signature

```php
/**
 * Aggregate orders per client and compute allowance-based billing splits.
 *
 * Returns one row per client (not per order) with mains/sides broken into
 * billable and overage quantities, with sides further split by taxable status.
 *
 * @param array  $client_rows     Rows from meals_clients.
 * @param string $start_date      Y-m-d.
 * @param string $end_date        Y-m-d.
 * @param int    $weeks_in_month  Number of Wednesdays in the billing month.
 *
 * @return array One row per client with allowance calculations.
 */
private static function get_allowance_data_for_clients(
    array $client_rows,
    string $start_date,
    string $end_date,
    int $weeks_in_month = 4
): array {
```

### 2.2 Build user index and fetch orders (same pattern as `get_invoice_data_for_clients`)

```php
if (empty($client_rows)) {
    return [];
}

$clients_by_user_id = [];
$wp_user_ids        = [];
foreach ($client_rows as $c) {
    $uid = (int) $c['wp_user_id'];
    if ($uid > 0) {
        $clients_by_user_id[$uid] = $c;
        $wp_user_ids[]            = $uid;
    }
}

if (empty($wp_user_ids)) {
    return [];
}

$order_query = new MealsDB_WC_Order_Query($GLOBALS['wpdb']);
$orders      = $order_query->get_orders_with_items_for_users($wp_user_ids, $start_date, $end_date);

if (empty($orders)) {
    return [];
}

// Look up product types for all items.
$all_product_ids = [];
foreach ($orders as $order) {
    foreach ($order['items'] as $item) {
        $pid = (int) $item['wc_product_id'];
        if ($pid > 0) {
            $all_product_ids[$pid] = $pid;
        }
    }
}
$product_types = $order_query->get_product_types_for_ids(array_values($all_product_ids));
```

### 2.3 Aggregate per client

```php
$days_in_month = (int) date('t', strtotime($end_date));

// Accumulate totals per client.
$client_aggregates = [];

foreach ($orders as $order) {
    $uid    = (int) $order['wp_user_id'];
    $client = isset($clients_by_user_id[$uid]) ? $clients_by_user_id[$uid] : null;
    if (!$client) {
        continue;
    }

    $cid = (int) $client['client_id'];
    if (!isset($client_aggregates[$cid])) {
        $rate_id       = isset($order['mealsdb_rate_id']) ? (int) $order['mealsdb_rate_id'] : 0;
        $resolved_rate = $order_query->resolve_rate_for_order($rate_id, $cid);

        $client_aggregates[$cid] = [
            'client'               => $client,
            'resolved_rate'        => $resolved_rate,
            'total_mains'          => 0,
            'total_sides_taxable'  => 0,
            'total_sides_nontax'   => 0,
            'total_tax_amount'     => 0.0,
        ];
    }

    foreach ($order['items'] as $item) {
        $pid  = (int) $item['wc_product_id'];
        $qty  = (float) $item['quantity'];
        $prod = isset($product_types[$pid]) ? $product_types[$pid] : null;

        $ptype  = $prod ? $prod['product_type'] : 'meal';
        $is_tax = $prod ? !empty($prod['taxable']) : false;

        if ($ptype === 'meal') {
            $client_aggregates[$cid]['total_mains'] += $qty;
        } elseif ($ptype === 'side') {
            if ($is_tax) {
                $client_aggregates[$cid]['total_sides_taxable'] += $qty;
                $client_aggregates[$cid]['total_tax_amount']    += (float) ($item['line_tax'] ?? 0);
            } else {
                $client_aggregates[$cid]['total_sides_nontax'] += $qty;
            }
        }
    }
}
```

### 2.4 Calculate allowances and splits for each client

This is the core allowance logic, translated from the old `sdnb-month-end.php`:

```php
$results = [];

foreach ($client_aggregates as $cid => $agg) {
    $client = $agg['client'];

    $user_mains   = (int) ($client['allowance_mains'] ?? 0);
    $user_sides   = (int) ($client['allowance_sides'] ?? 0);
    $user_service = strtolower(trim($client['requisition_period'] ?? 'week'));

    // --- Allowance calculation ---
    $mains_allowed = 0;
    $sides_allowed = 0;

    switch ($user_service) {
        case 'month':
            $mains_allowed = ($user_mains == 31) ? $days_in_month : $user_mains;
            $sides_allowed = ($user_sides == 31) ? $days_in_month : $user_sides;
            break;

        case 'week':
            $mains_allowed = $user_mains * $weeks_in_month;
            $sides_allowed = $user_sides * $weeks_in_month;

            // Override: 7 per week = every day; 14 per week = twice per day
            if ($user_mains == 7) {
                $mains_allowed = $days_in_month;
            }
            if ($user_mains == 14) {
                $mains_allowed = 2 * $days_in_month;
            }
            if ($user_sides == 7) {
                $sides_allowed = $days_in_month;
            }
            if ($user_sides == 14) {
                $sides_allowed = 2 * $days_in_month;
            }
            break;

        case 'day':
            $mains_allowed = $user_mains * $days_in_month;
            $sides_allowed = $user_sides * $days_in_month;
            break;
    }

    // --- Mains split ---
    $total_mains = (int) $agg['total_mains'];
    $bill_mains  = min($total_mains, $mains_allowed);
    $bnm_mains   = max(0, $total_mains - $mains_allowed);

    // --- Sides split (taxable first, then non-taxable with remaining allowance) ---
    $taxable_sides     = (int) $agg['total_sides_taxable'];
    $non_taxable_sides = (int) $agg['total_sides_nontax'];
    $total_sides       = $taxable_sides + $non_taxable_sides;

    // Taxable sides get priority against the allowance.
    $bill_tax_sides    = min($sides_allowed, $taxable_sides);
    $overage_tax_sides = $taxable_sides - $bill_tax_sides;

    // Remaining allowance after taxable sides.
    $remaining_sides = ($overage_tax_sides == 0)
        ? max(0, $sides_allowed - $taxable_sides)
        : 0;

    // Non-taxable sides fill whatever allowance remains.
    $bill_nontax_sides    = min($non_taxable_sides, $remaining_sides);
    $overage_nontax_sides = $non_taxable_sides - $bill_nontax_sides;

    $bill_sides = $bill_tax_sides + $bill_nontax_sides;

    $results[] = [
        'client'               => $client,
        'resolved_rate'        => $agg['resolved_rate'],
        'client_contribution'  => (float) ($client['client_contribution'] ?? 0),

        // Mains
        'total_mains'          => $total_mains,
        'mains_allowed'        => $mains_allowed,
        'bill_mains'           => $bill_mains,
        'bnm_mains'            => $bnm_mains,

        // Sides totals
        'total_sides'          => $total_sides,
        'sides_allowed'        => $sides_allowed,
        'taxable_sides'        => $taxable_sides,
        'non_taxable_sides'    => $non_taxable_sides,

        // Sides splits
        'bill_tax_sides'       => $bill_tax_sides,
        'overage_tax_sides'    => $overage_tax_sides,
        'remaining_sides'      => $remaining_sides,
        'bill_nontax_sides'    => $bill_nontax_sides,
        'overage_nontax_sides' => $overage_nontax_sides,
        'bill_sides'           => $bill_sides,

        // Tax
        'total_tax_amount'     => $agg['total_tax_amount'],

        // Service info
        'user_service'         => $user_service,
    ];
}

// Sort by last_name, first_name.
usort($results, function ($a, $b) {
    $cmp = strcmp($a['client']['last_name'] ?? '', $b['client']['last_name'] ?? '');
    return $cmp !== 0 ? $cmp : strcmp($a['client']['first_name'] ?? '', $b['client']['first_name'] ?? '');
});

return $results;
```

Close the method with `}`.

---

## Step 3 — Update `generate_sdnb_legacy()` signature

**File:** `includes/services/class-invoice-generator.php`

Change the method signature at line 203 from:

```php
public static function generate_sdnb_legacy($zone, $start_date, $end_date) {
```

To:

```php
public static function generate_sdnb_legacy($zone, $start_date, $end_date, $weeks_in_month = 4) {
```

---

## Step 4 — Update the client query to include allowance fields

In `generate_sdnb_legacy()`, the SQL query (around line 214) currently selects:

```sql
SELECT client_id, wp_user_id, first_name, last_name, service_id, requisition_id,
       individual_id, client_contribution, delivery_area_zone, default_rate_id
FROM meals_clients
WHERE ...
```

Add the three allowance-related columns to the SELECT:

```sql
SELECT client_id, wp_user_id, first_name, last_name, service_id, requisition_id,
       individual_id, client_contribution, delivery_area_zone, default_rate_id,
       allowance_mains, allowance_sides, requisition_period
FROM meals_clients
WHERE ...
```

The WHERE clause remains unchanged.

---

## Step 5 — Replace `get_invoice_data_for_clients` with `get_allowance_data_for_clients`

In `generate_sdnb_legacy()`, at line 226, replace:

```php
$invoice_rows = self::get_invoice_data_for_clients($client_rows, $start_date, $end_date);
```

With:

```php
$invoice_rows = self::get_allowance_data_for_clients($client_rows, $start_date, $end_date, $weeks_in_month);
```

The rest of the CSV building logic will be updated in Phase C (two-line invoice) to use the new row structure. For now, the method returns the data; Phase C will consume it.

---

## Verification checklist

- [ ] `generate_sdnb_legacy()` accepts a 4th parameter `$weeks_in_month` with default `4`
- [ ] `get_allowance_data_for_clients()` exists as a `private static` method on `MealsDB_Invoice_Generator`
- [ ] It returns one row per client, not per order
- [ ] Each row contains: `bill_mains`, `bnm_mains`, `mains_allowed`, `bill_tax_sides`, `overage_tax_sides`, `bill_nontax_sides`, `overage_nontax_sides`, `bill_sides`, `sides_allowed`, `remaining_sides`, `resolved_rate`, `client_contribution`, `user_service`
- [ ] Product type lookup uses `meals_products.product_type` (`meal` vs `side`) and `meals_products.taxable` (1 vs 0) — NOT hardcoded WooCommerce category IDs
- [ ] Client data uses `meals_clients.allowance_mains`, `meals_clients.allowance_sides`, `meals_clients.requisition_period` — NOT WordPress user meta
- [ ] Rate resolution uses the existing `$order_query->resolve_rate_for_order()` method
- [ ] The invoice form shows a "Number of Wednesdays" input when `sdnb_legacy` is selected
- [ ] The AJAX handler passes `$weeks_in_month` through to the generator
- [ ] No new database tables, classes, or files were created — all changes are in existing files
