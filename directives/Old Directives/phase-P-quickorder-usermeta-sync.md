# Phase P: QuickOrder wp_usermeta Sync

## Goal

Ensure the QuickOrder system updates `last_order_date` and `last_call_date` in wp_usermeta when creating orders, matching what the old `admin-pos-order` did. This is required for the call log manager to function correctly.

## The problem

The old POS plugin (`admin-pos-order.php` lines 350-351) ran this after every order:
```php
update_user_meta($user_id, 'last_order_date', current_time('mysql'));
update_user_meta($user_id, 'last_call_date', current_time('mysql'));
```

The new QuickOrder (`class-quick-order-ajax.php → create_order()`) does NOT do this. It creates the WC order and saves `mealsdb_client_user_id`, `mealsdb_client_id`, and `mealsdb_rate_id` as order meta, but never touches wp_usermeta.

The call log manager (`call-log-manager.php`) calculates "next call date" as:
```
next_call = last_order_date + (ordering_frequency * 7 days)
```

If `last_order_date` never updates, every client will appear overdue for a call and the call log becomes useless.

## The fix

### In `create_order()` — `includes/class-quick-order-ajax.php`

After `$order->save()` succeeds (around line 210), before the success response, add:

```php
// Update operational wp_usermeta fields (matches old admin-pos-order behavior).
// These are read by the call-log-manager to schedule follow-up calls.
$wp_user_id = self::get_user_id_for_client($client_id);
if ($wp_user_id > 0) {
    update_user_meta($wp_user_id, 'last_order_date', current_time('mysql'));
    update_user_meta($wp_user_id, 'last_call_date', current_time('mysql'));
}
```

**Wait — `$client_id` in create_order is already the wp_user_id** (the parameter is named `client_id` but the comment says "WordPress user ID for the client placing the order" and it's validated with `self::user_exists($client_id)` which checks `get_userdata()`). So the fix is simply:

```php
// After $order->save():
update_user_meta($client_id, 'last_order_date', current_time('mysql'));
update_user_meta($client_id, 'last_call_date', current_time('mysql'));
```

### In `clone_order()` — same file

The clone_order method (around line 230) also creates a WC order. It should also update these meta fields. After the cloned order is saved, add the same two `update_user_meta()` calls.

For clone_order, the user_id comes from:
```php
$client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
```

Same pattern — add after the order save succeeds.

---

## What NOT to do

- Do NOT add `last_order_date` or `last_call_date` to the `meals_clients` external DB table. These are operational/scheduling fields that change with every order and are tightly coupled to the call log which reads from wp_usermeta. Keep them in WordPress.
- Do NOT try to sync these back via the bidirectional sync system. The sync deals with client record fields, not operational timestamps.

---

## Call Log Manager (future phase — not in scope here)

For reference, the call log manager will eventually need to be rebuilt as Phase Q. It reads from wp_usermeta:
- `ordering_contact_method` — how the client prefers to be contacted
- `ordering_frequency` — how often they order (in weeks)
- `last_order_date` — when they last placed an order
- `last_call_date` — when staff last called them
- `next_call_date` — manual override for next call
- `billing_address_2` — zone (for filtering)
- `billing_phone` — phone number

It also manages VM/EM (voicemail/email) tracking slots stored in usermeta. The full call log rebuild is a larger project, but the wp_usermeta sync fix in this phase ensures the data foundation is correct when that work happens.

---

## Key constraints

- `$client_id` in `create_order()` IS the WordPress user ID (despite the variable name)
- `current_time('mysql')` returns the WordPress site's local time in MySQL datetime format
- Both `last_order_date` and `last_call_date` should be updated simultaneously (matching old POS behavior — placing an order counts as a "contact")
- This is a 4-line change total (2 lines in create_order, 2 lines in clone_order)
