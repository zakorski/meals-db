# Phase 7 — Historical Order Import Utility

## Objective

Create a one-time admin utility that writes `mealsdb_client_user_id`, `mealsdb_client_id`, and `mealsdb_rate_id` order meta onto existing WooCommerce orders for all SDNB and Veteran clients. This makes historical orders queryable by the invoice generators and reports without any schema changes to WC tables.

---

## Context

The existing 14,500+ WC orders were created before the Meals DB plugin and have no `mealsdb_*` order meta. The invoice generators need this meta to link orders to `meals_clients` records. The historical import utility adds these meta values to existing orders using the WooCommerce CRUD API (`$order->update_meta_data()`).

Only orders belonging to SDNB and Veteran clients need to be tagged. Private client orders do not need meta tagging.

The utility must support:
- Dry-run mode (no writes, just counting and logging)
- Batched processing (to avoid PHP timeout on 14,500 orders)
- Resumability (track progress via a WP option)
- Comprehensive logging

---

## Step 1 — Create `includes/services/class-historical-import.php`

Create a new class `MealsDB_Historical_Import`.

### 1.1 Class constants
```php
const BATCH_SIZE = 100;        // Orders processed per AJAX call
const PROGRESS_OPTION = 'mealsdb_historical_import_progress';
const LOG_OPTION = 'mealsdb_historical_import_log';
```

### 1.2 Implement `get_government_clients(): array`
- Query `meals_clients` WHERE `client_type IN ('SDNB','Veteran')` AND `wp_user_id > 0` AND `active = 1`
- Return array of rows with `client_id`, `wp_user_id`, `default_rate_id`
- Keyed by `wp_user_id` for O(1) lookup

### 1.3 Implement `get_orders_for_batch(int $offset): array`
- Query `wc_orders` WHERE `customer_id IN ($government_user_ids)` AND `type = 'shop_order'`
- ORDER BY `id ASC`
- LIMIT `self::BATCH_SIZE` OFFSET `$offset`
- Returns array of `[id, customer_id]`
- Uses `$wpdb->prepare()` — never raw string interpolation

### 1.4 Implement `get_total_order_count(): int`
- COUNT(*) query with the same WHERE conditions as `get_orders_for_batch()`
- Returns total number of orders to process

### 1.5 Implement `process_batch(int $offset, bool $dry_run = true): array`
Returns a stats array: `['processed' => int, 'tagged' => int, 'already_tagged' => int, 'skipped' => int, 'errors' => int]`

For each order in the batch:
- Load the WC order: `$order = wc_get_order($order_id)`
- If not a valid `WC_Order` instance, increment `errors`, continue
- Check if `mealsdb_client_user_id` meta already exists on this order:
  - If yes and non-zero, increment `already_tagged`, continue
- Look up the client in the government clients index by `customer_id`
  - If not found (order belongs to a Private client somehow in the result set), increment `skipped`, continue
- Resolve `rate_id`:
  - Query `meals_client_rates` WHERE `client_id = $client['client_id']` AND `is_default = 1` LIMIT 1
  - Use `rate_id` if found, else `0`
- If `$dry_run` is `false`:
  - `$order->update_meta_data('mealsdb_client_user_id', $client['wp_user_id'])`
  - `$order->update_meta_data('mealsdb_client_id', $client['client_id'])`
  - If `$rate_id > 0`: `$order->update_meta_data('mealsdb_rate_id', $rate_id)`
  - `$order->save()`
- Increment `tagged`

### 1.6 Implement `get_progress(): array`
- Read from `get_option(self::PROGRESS_OPTION, ['offset' => 0, 'total' => 0, 'complete' => false])`
- Return the array

### 1.7 Implement `save_progress(int $offset, int $total, bool $complete = false): void`
- `update_option(self::PROGRESS_OPTION, ['offset' => $offset, 'total' => $total, 'complete' => $complete])`

### 1.8 Implement `reset_progress(): void`
- `delete_option(self::PROGRESS_OPTION)`
- `delete_option(self::LOG_OPTION)`

---

## Step 2 — Create AJAX handler `includes/ajax/class-ajax-historical-import.php`

Create a new class `MealsDB_Ajax_Historical_Import`.

### 2.1 Register AJAX actions
```
wp_ajax_mealsdb_historical_import_start
wp_ajax_mealsdb_historical_import_batch
wp_ajax_mealsdb_historical_import_reset
wp_ajax_mealsdb_historical_import_status
```

All actions require `manage_options` capability. Apply nonce verification (`mealsdb_nonce`).

### 2.2 `start()` handler
- Read `dry_run` from `$_POST` (bool, default `true`)
- Verify no import is already in progress (`get_progress()['complete'] !== false` and offset > 0 → return error)
- Get total order count via `MealsDB_Historical_Import::get_total_order_count()`
- Save initial progress: `save_progress(0, $total, false)`
- Return `['success' => true, 'total' => $total, 'dry_run' => $dry_run]`

### 2.3 `batch()` handler
- Read `dry_run` from `$_POST` (bool)
- Read current progress via `get_progress()`
- If already complete, return `['success' => true, 'complete' => true]`
- Call `MealsDB_Historical_Import::process_batch($progress['offset'], $dry_run)`
- Advance offset: `$new_offset = $progress['offset'] + MealsDB_Historical_Import::BATCH_SIZE`
- If `$new_offset >= $progress['total']`: `save_progress($new_offset, $progress['total'], true)`
- Else: `save_progress($new_offset, $progress['total'], false)`
- Return stats plus `complete` flag and `percent` calculated from `$new_offset / $total * 100`

### 2.4 `reset()` handler
- Call `MealsDB_Historical_Import::reset_progress()`
- Return `['success' => true]`

### 2.5 `status()` handler
- Return current progress from `get_progress()`

---

## Step 3 — Add admin UI in `views/updates.php` or a new view

### 3.1 Add a "Historical Order Import" section to the updates/tools admin page
The UI needs:
- A description: "Tags existing WooCommerce orders with Meals DB client identifiers for SDNB and Veteran clients. Run once after initial setup."
- A "Start Dry Run" button
- A "Run Import" button (disabled until dry run has been completed once)
- A progress bar (hidden until import is started)
- A status message area showing counts per batch
- A "Reset" button to clear progress and start over

### 3.2 Wire the UI with JavaScript
Add a small inline script or a new `assets/js/admin-historical-import.js` file:
- On "Start Dry Run" click: POST to `mealsdb_historical_import_start` with `dry_run: 1`
- On success, begin polling batches: repeatedly POST to `mealsdb_historical_import_batch` with `dry_run: 1`
- Show progress bar updating after each batch response
- On `complete: true`, show summary and enable the "Run Import" button
- Same flow for "Run Import" with `dry_run: 0`

---

## Step 4 — Register in plugin bootstrap

### 4.1 Autoloader
- Confirm `MealsDB_Historical_Import` and `MealsDB_Ajax_Historical_Import` are reachable by the autoloader, or add `require_once` entries

### 4.2 Initialize AJAX handler
- In `includes/class-ajax.php` or the plugin bootstrap, call `MealsDB_Ajax_Historical_Import::init()`

---

## Verification Checklist

- Dry run reports the correct total number of SDNB/Veteran orders without writing any meta
- After a full live run, spot-check 10 orders: each should have `mealsdb_client_user_id` matching the correct WP user, `mealsdb_client_id` matching the correct `meals_clients.client_id`, and `mealsdb_rate_id` matching the default rate
- Re-running the import on already-tagged orders increments `already_tagged` and makes no writes
- Progress is correctly resumed if the page is refreshed mid-import
- Reset clears all progress and allows a fresh run
- Private client orders do not receive any `mealsdb_*` meta
- No direct SQL writes to `wc_orders` or `wc_orders_meta` — all writes via `$order->update_meta_data()` and `$order->save()`
