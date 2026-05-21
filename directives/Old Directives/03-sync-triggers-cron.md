# Phase 3 — Sync Triggers and Nightly Cron

## Objective

Add automatic sync triggers so that changes to a government client's WordPress user record are immediately reflected in `meals_clients`, and vice versa. Add a nightly WP-Cron sweep to catch any drift. Codify the conflict resolution rule: WP/WC is authoritative for contact fields; `meals_clients` is authoritative for government billing fields.

---

## Context

The infrastructure for sync already exists in `includes/services/sync/class-sync-mutate.php` and `class-sync-query.php`. What is missing is the hook registration layer and the cron scheduler. The `MealsDB_Sync` facade class in `includes/class-sync.php` is the right place to register hooks.

The field mapping between WP user meta and `meals_clients` columns is already partially defined in `class-sync-mutate.php` via `build_identity_column_map()`. That map defines which fields are considered shared (contact fields). Government billing fields (`sdnb_service_request_id`, `requisition_id`, `individual_id`, `service_id`, `use_legacy_billing`, `default_rate_id`, `meal_type`, `delivery_initials`, `delivery_area_zone`, etc.) exist only in `meals_clients` and are never synced to WP.

---

## Step 1 — Define the authoritative field split

Add a static method `MealsDB_Sync::get_wp_authoritative_fields(): array` that returns the list of fields where WP/WC is the source of truth and changes should flow WP → `meals_clients`:

```
first_name, last_name, client_email, client_phone_1, client_phone_2,
street_number, street_name, apartment_number, city, province, postal_code,
delivery_street_number, delivery_street_name, delivery_apartment_number,
delivery_city, delivery_province, delivery_postal_code,
alternate_contact_name, alternate_contact_phone_1, alternate_contact_phone_2,
alternate_contact_email
```

Add a static method `MealsDB_Sync::get_mealsdb_authoritative_fields(): array` that returns the fields that exist only in `meals_clients` and are never overwritten by WP data:

```
client_type, sdnb_service_request_id, requisition_id, individual_id,
individual_id_index, service_id, vendor_number, service_center_charged,
use_legacy_billing, default_rate_id, meal_type, delivery_initials,
delivery_initials_index, delivery_area_zone, delivery_area_name,
delivery_day, delivery_frequency, ordering_frequency, ordering_contact_method,
units, client_contribution, vet_health_card, vet_health_card_index,
open_date, birth_date, gender, assigned_worker_name, assigned_worker_email,
requisition_id_index, requisition_period, service_name_zone,
service_commence_date, expected_termination_date,
initial_renewal_termination_date, most_recent_renewal_termination_date,
notes_to_service_provider, freezer_capacity, delivery_fee, diet_concerns,
customer_comments, payment_method, do_not_call_client_phone,
required_start_date, active
```

---

## Step 2 — Register WordPress hooks in `includes/class-sync.php`

Add a static method `MealsDB_Sync::register_hooks(): void` and call it from the plugin bootstrap (`includes/class-plugin.php` or wherever hooks are registered during `init`).

### 2.1 Hook: WP user profile updated
```
add_action('profile_update', [MealsDB_Sync::class, 'on_wp_user_updated'], 10, 2);
```
Handler `on_wp_user_updated(int $user_id, WP_User $old_user_data)`:
- Check if the updated user has a corresponding `meals_clients` record via `wp_user_id = $user_id` AND `client_type IN ('SDNB','Veteran')`
- If not found, return early
- For each field in `get_wp_authoritative_fields()`, compare the new WP user meta value against the `meals_clients` value
- For any fields that differ, call `MealsDB_Sync::push_to_meals_db($client_id, $field, $new_value)`
- Log any sync errors to `meals_audit_log` with `action = 'sync_wp_to_mealsdb'`

### 2.2 Hook: WooCommerce customer address saved
```
add_action('woocommerce_customer_save_address', [MealsDB_Sync::class, 'on_wc_address_saved'], 10, 2);
```
Handler `on_wc_address_saved(int $user_id, string $address_type)`:
- Only process `$address_type === 'billing'` or `'shipping'`
- Look up `meals_clients` record for this `wp_user_id` with government client type
- If not found, return early
- Map WC billing/shipping fields to `meals_clients` columns
- Push changed fields via `push_to_meals_db()`

### 2.3 Hook: WooCommerce customer created
```
add_action('woocommerce_created_customer', [MealsDB_Sync::class, 'on_wc_customer_created'], 10, 1);
```
Handler `on_wc_customer_created(int $customer_id)`:
- This fires when a new WC customer is created
- Check if the new user has a `meals_clients` record (e.g., they were imported with a matching `wp_user_id`)
- If a record exists, run a full field comparison and push any WP-authoritative fields that differ

---

## Step 3 — Nightly cron job

### 3.1 Register cron schedule
In `MealsDB_Sync::register_hooks()`, also register the cron:
```
if (!wp_next_scheduled('mealsdb_nightly_sync')) {
    wp_schedule_event(strtotime('tomorrow 02:00:00'), 'daily', 'mealsdb_nightly_sync');
}
add_action('mealsdb_nightly_sync', [MealsDB_Sync::class, 'run_nightly_sync']);
```

### 3.2 Implement `run_nightly_sync()`
```
public static function run_nightly_sync(): void
```
- Query all `meals_clients` records WHERE `client_type IN ('SDNB','Veteran')` AND `wp_user_id > 0`
- For each client, retrieve the corresponding `WP_User` via `get_userdata($wp_user_id)`
- If user not found, log warning to `meals_audit_log` with `action = 'sync_nightly_missing_user'` and skip
- For each field in `get_wp_authoritative_fields()`:
  - Compare WP user meta value to `meals_clients` column value
  - If different, call `push_to_meals_db($client_id, $field, $wp_value)` (WP wins for these fields)
- Log completion to `meals_audit_log` with `action = 'sync_nightly_complete'`, `new_value = JSON summary of counts`

### 3.3 Cron deactivation hook
In `uninstall.php` or the plugin deactivation hook, add:
```
wp_clear_scheduled_hook('mealsdb_nightly_sync');
```

---

## Step 4 — Verify `includes/services/sync/class-sync-mutate.php` handles the field map correctly

### 4.1 Confirm `push_to_meals_db()` only writes WP-authoritative fields
- `push_to_meals_db(int $client_id, string $field, string $new_value)` should validate that `$field` is in the `get_wp_authoritative_fields()` list before executing the UPDATE
- If the field is not in the list, return a `WP_Error` with code `mealsdb_sync_readonly_field`

### 4.2 Confirm `push_to_woocommerce()` only writes WP-authoritative fields
- Similarly, `push_to_woocommerce()` should only push fields in the WP-authoritative list
- `meals_db` authoritative fields must never be pushed to WP

---

## Verification Checklist

- Updating a government client's billing address in WooCommerce triggers an immediate update to the matching `meals_clients` row
- Updating a Private client's profile in WP does NOT trigger any write to `meals_clients`
- `mealsdb_nightly_sync` appears in `wp_options` scheduled events after plugin activation
- Nightly sync log entry appears in `meals_audit_log` after cron runs
- Attempting to push a `meals_db` authoritative field (e.g., `sdnb_service_request_id`) via `push_to_meals_db()` returns a `WP_Error`
- Plugin deactivation clears the cron event
