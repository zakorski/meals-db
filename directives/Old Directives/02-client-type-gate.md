# Phase 2 — Client Type Gate

## Objective

Enforce that only clients with `client_type` of `'SDNB'` or `'Veteran'` are written to the external encrypted database. Private clients exist only as WordPress/WooCommerce users. Apply this gate in the CSV importer and in the sync mutate layer.

---

## Context

The `meals_clients` table has a `client_type` column defined as `ENUM('Private','SDNB','Veteran') NOT NULL`. The `wp_user_id` column on `meals_clients` is the bridge to the WordPress user. Currently the importer and sync treat all client types the same way.

---

## Step 1 — Update `includes/services/class-client-importer.php`

### 1.1 Add a type gate method
Add a private helper method `is_government_client(string $client_type): bool` that returns `true` if `$client_type === 'SDNB' || $client_type === 'Veteran'`.

### 1.2 Gate the external DB write
- Locate the section of the import loop that writes a record to `meals_clients` via the external mysqli connection
- Before this write, check `is_government_client($client_type)`
- If `false` (Private client):
  - Still create or update the WordPress user as normal
  - Skip the `meals_clients` INSERT/UPDATE entirely
  - Increment a `$stats['private_clients_wp_only']` counter (add this counter to the `$stats` array)
  - Log: `[MealsDB Importer] Skipped external DB write for Private client: {first_name} {last_name} (wp_user_id: {id})`
  - Continue to next row

### 1.3 Update dry-run output
- When dry-run mode is active and a Private client is encountered, include it in the dry-run log as `SKIPPED (Private — WP only)` rather than as an error or success

### 1.4 Update stats summary
- Add `private_clients_wp_only` to the final stats summary that is returned and logged at the end of the import

---

## Step 2 — Update `includes/services/sync/class-sync-mutate.php`

### 2.1 Add a type gate to `create_meals_client()`
- Before executing the INSERT into `meals_clients`, retrieve the `client_type` value from the data being written
- If `client_type` is `'Private'`, return a `WP_Error` with code `mealsdb_sync_private_client` and message: `'Private clients are not stored in the Meals DB external database.'`

### 2.2 Add a type gate to `update_meals_client()`
- Before executing the UPDATE on `meals_clients`, query the existing record for its `client_type`
- If `client_type` is `'Private'`, return a `WP_Error` with code `mealsdb_sync_private_client` and message: `'Private clients are not stored in the Meals DB external database.'`

### 2.3 Add a type gate to `link_meals_client_to_wc_user()`
- After resolving the `meals_clients` record, check its `client_type`
- If `'Private'`, return a `WP_Error` with the same code and message as above

### 2.4 Update `push_to_meals_db()`
- This method syncs a single field from WooCommerce to the external DB
- After resolving the `client_id`, before executing any write, check the `client_type` of that client
- If `'Private'`, return `true` silently (no error — there is simply nothing to write)

---

## Step 3 — Update `includes/services/sync/class-sync-query.php`

### 3.1 Filter `get_meals_clients()` to government clients only
- The method that fetches all `meals_clients` records for sync comparison should add a `WHERE client_type IN ('SDNB', 'Veteran')` clause
- This ensures the sync mismatch UI never surfaces Private clients as candidates for sync

### 3.2 Verify `get_wp_users()` is unaffected
- This method fetches all WP users for matching — it should remain unfiltered, as Private clients still have WP accounts

---

## Step 4 — Update `includes/ajax/class-ajax-sync.php`

### 4.1 No changes required to sync field push/pull handlers
- The gate in `sync-mutate.php` handles the enforcement. However, if the `sync_field` AJAX handler surfaces errors to the UI, ensure that a `mealsdb_sync_private_client` error code produces a user-friendly message rather than a generic error alert.

---

## Step 5 — Update `includes/services/class-clients-repository.php`

### 5.1 Audit all write methods
- Review `save_client()`, and any other method that performs an INSERT or UPDATE on `meals_clients`
- Add `is_government_client()` checks consistent with Step 1.1 before any external DB write that originates from a non-import context (e.g., manual client creation via the admin UI)
- If `client_type` is `'Private'` and a write to the external DB is attempted, log a warning and skip the write rather than throwing a fatal error

---

## Verification Checklist

- Importing a CSV row with `client_type = 'Private'` creates a WP user but does NOT insert a row into `meals_clients`
- Importing a CSV row with `client_type = 'SDNB'` or `'Veteran'` creates a WP user AND inserts into `meals_clients`
- The dry-run log accurately reflects which rows were written to external DB vs. WP only
- The sync mismatch UI does not show Private clients
- Manually triggering a sync push for a Private client silently succeeds (no error shown, no DB write)
- `stats['private_clients_wp_only']` is accurate in import results
