# Phase 4 — QuickOrder: Remove Transaction Logging, Add Rate Selector

## Objective

Remove the `log_transaction()` call from QuickOrder's order creation flow. Replace the flat `billing_rate` float field with a `rate_id` selector that reads from `meals_client_rates`. Write `mealsdb_rate_id` as order meta on the WC order. Add a new AJAX endpoint to fetch rates for a given client.

---

## Context

The relevant files are:
- `includes/class-quick-order-ajax.php` — AJAX handlers including `create_order()` and `get_client_rate()`
- `includes/class-quick-order-ui.php` — Admin page renderer
- `assets/js/quick-order.js` — Frontend behaviour
- New file: `includes/ajax/class-ajax-rates.php` — Rate fetch endpoint

The `create_order()` method currently:
1. Creates a WC order via `create_wc_order()`
2. Writes `mealsdb_client_user_id` and `mealsdb_client_id` as order meta ✓ keep
3. Calls `log_transaction()` which inserts into `meals_transactions` ✗ remove

The `get_client_rate()` method currently fetches a single `rate` decimal from `meals_clients`. This is replaced by a multi-rate endpoint.

---

## Step 1 — Create `includes/ajax/class-ajax-rates.php`

Create a new class `MealsDB_Ajax_Rates` with a single AJAX endpoint:

### 1.1 Register the action
```
add_action('wp_ajax_mealsdb_get_client_rates', [self::class, 'get_client_rates']);
```

### 1.2 Implement `get_client_rates()`
- Verify nonce (`mealsdb_nonce`) and user capability via `MealsDB_Permissions::required_capability()`
- Apply rate limiting via `MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')`
- Read `$_REQUEST['user_id']` as an integer
- If `user_id <= 0`, return error: `'Invalid user ID.'`
- Look up `client_id` from `meals_clients` WHERE `wp_user_id = $user_id` AND `active = 1` LIMIT 1
- If not found, return `['success' => true, 'rates' => [], 'default_rate_id' => null]` (Private clients have no rates)
- Query `meals_client_rates` WHERE `client_id = $client_id` ORDER BY `is_default DESC, label ASC`
- Return:
```json
{
  "success": true,
  "rates": [
    {"rate_id": 1, "label": "Standard", "rate": "12.50", "is_default": 1},
    {"rate_id": 2, "label": "Subsidized", "rate": "8.00", "is_default": 0}
  ],
  "default_rate_id": 1
}
```

---

## Step 2 — Update `includes/class-quick-order-ajax.php`

### 2.1 Remove `get_client_rate()` method entirely
This method fetched a single `rate` decimal from `meals_clients.rate`. It is replaced by `MealsDB_Ajax_Rates::get_client_rates()`. Remove:
- The `add_action('wp_ajax_mealsdb_qo_get_client_rate', ...)` registration in `init()`
- The entire `get_client_rate()` method

### 2.2 Update `create_order()` — replace `billing_rate` with `rate_id`
- Remove: `$billing_rate = isset($_POST['billing_rate']) ? floatval($_POST['billing_rate']) : null;`
- Add: `$rate_id = isset($_POST['rate_id']) ? intval($_POST['rate_id']) : 0;`
- Remove the validation: `if ($billing_rate !== null && $billing_rate < 0)`
- Add validation: if `$rate_id` is provided (> 0), verify it belongs to this client by querying `meals_client_rates` WHERE `rate_id = $rate_id` AND `client_id = $client_db_id`. If not found, return error: `'Invalid rate selection.'`

### 2.3 Update `create_order()` — write `mealsdb_rate_id` order meta
After writing `mealsdb_client_user_id` and `mealsdb_client_id`:
```php
if ($rate_id > 0) {
    $order->update_meta_data('mealsdb_rate_id', $rate_id);
}
```
Save the order after all meta is set.

### 2.4 Remove `log_transaction()` call
- Remove the entire block:
```php
if ($client_db_id > 0 && !self::log_transaction($order, $client_db_id, $order_date, $billing_rate)) {
    $order_id = $order->get_id();
    if ($order_id > 0) {
        wp_trash_post($order_id);
    }
    throw new Exception(__('Failed to record Meals DB transaction.', 'meals-db'));
}
```
- The WC order creation itself is now the complete record. No rollback on transaction log failure.

### 2.5 Remove `log_transaction()` private method
- Remove the entire `private static function log_transaction(...)` method. It inserts into `meals_transactions` which no longer exists.

### 2.6 Update `init()` — register new rate endpoint
- Add: `MealsDB_Ajax_Rates::init();` (or register within this class's `init()` if preferred — ensure the action is registered)

---

## Step 3 — Update `includes/class-quick-order-ui.php`

### 3.1 Localize the rate fetch nonce
In the `wp_localize_script()` call within `render_quick_order_page()`, ensure `mealsdb_nonce` is included in the localized data (it likely already is — verify).

### 3.2 No other UI changes required in PHP
The rate selector UI is rendered by JavaScript based on the AJAX response. The PHP layer only needs to ensure the nonce is available.

---

## Step 4 — Update `assets/js/quick-order.js`

### 4.1 Remove `billing_rate` field handling
- Remove any code that reads, displays, or posts a flat `billing_rate` input field
- Remove any call to `mealsdb_qo_get_client_rate`

### 4.2 Add rate selector fetch on client change
When a client is selected from the client dropdown:
- Make an AJAX call to `mealsdb_get_client_rates` with `user_id = selected_client_wp_user_id`
- On success:
  - If `rates` array is empty, hide the rate selector container (Private client or no rates set)
  - If `rates` array is non-empty, populate a `<select>` dropdown with one `<option>` per rate: value = `rate_id`, label = `"{label} — ${rate}"`
  - Set the selected option to the one matching `default_rate_id`
  - Show the rate selector container

### 4.3 Post `rate_id` on order submission
- When the order is submitted, include `rate_id: selectedRateId` in the POST data sent to `mealsdb_qo_create_order`
- If no rate is selected (empty rates for Private client), post `rate_id: 0`

### 4.4 Update clone order flow
- When an order is cloned, read `mealsdb_rate_id` from the order being cloned (this is already returned in `clone_get_order` response if added — see Step 5)
- Pre-select the matching rate in the rate selector after rates are fetched

---

## Step 5 — Update `clone_get_order()` in `includes/class-quick-order-ajax.php`

### 5.1 Return `mealsdb_rate_id` in clone response
- After fetching `$client_id` and `$client_db_id` from order meta, also read:
```php
$rate_id = intval($source_order->get_meta('mealsdb_rate_id'));
```
- Include `'rate_id' => $rate_id > 0 ? $rate_id : null` in the `wp_send_json()` response array

---

## Step 6 — Register `MealsDB_Ajax_Rates` in the plugin bootstrap

### 6.1 Autoloader
- Confirm `includes/class-autoloader.php` will locate `includes/ajax/class-ajax-rates.php` based on the existing naming convention. If not, add a manual `require_once` in the bootstrap.

### 6.2 Call `MealsDB_Ajax_Rates::init()`
- In `includes/class-ajax.php` or wherever other AJAX classes are initialized (e.g., `MealsDB_Ajax_Clients::init()`, `MealsDB_Ajax_Staff::init()`), add `MealsDB_Ajax_Rates::init()`

---

## Verification Checklist

- Selecting a client in QuickOrder triggers an AJAX fetch that populates the rate dropdown
- Private clients (no `meals_clients` record) show no rate selector
- SDNB/Veteran clients show all their rates with the default pre-selected
- Submitting an order writes `mealsdb_client_user_id`, `mealsdb_client_id`, and `mealsdb_rate_id` to WC order meta
- No write to `meals_transactions` occurs at any point during order creation
- Submitting an order with an invalid `rate_id` (not belonging to the client) returns an error
- Cloning an order pre-selects the correct rate from the original order
- `get_client_rate` AJAX action no longer exists (returns 400/nopriv if called)
