# Phase 10 — Dead Code Cleanup

## Objective

Remove all code, files, views, AJAX handlers, and references that became defunct after the previous nine phases. Leave the codebase with no orphaned classes, dead constants, unreachable methods, or stale view files.

---

## Step 1 — Delete defunct PHP files

The following files must be deleted entirely. Verify each is unreferenced before deletion.

### 1.1 `includes/class-transactions.php`
- Contains `MealsDB_Transactions` class
- All methods reference `meals_transactions` and `meals_transaction_items` which no longer exist
- Verify no remaining call sites: search for `MealsDB_Transactions::` across all plugin PHP files
- If any call sites remain (they should not after Phase 4 and 6), remove those first
- Delete the file

### 1.2 `views/admin-transactions.php`
- The transaction list admin view
- Verify the admin menu registration in `includes/class-admin-ui.php` no longer registers a "Transactions" menu item
- If the menu item still exists, remove its `add_submenu_page()` call first
- Delete the file

### 1.3 `views/admin-transaction-details.php`
- The single transaction detail view
- Same verification as 1.2
- Delete the file

---

## Step 2 — Remove defunct constants from `includes/class-tables.php`

This was completed in Phase 1. Verify:
- `MealsDB_Tables::TRANSACTIONS` does not exist
- `MealsDB_Tables::TRANSACTION_ITEMS` does not exist
- `MealsDB_Tables::CLIENT_RATES` exists

---

## Step 3 — Clean up `includes/class-quick-order-ajax.php`

### 3.1 Verify removal completed in Phase 4
- `log_transaction()` private method is gone
- `get_client_rate()` method is gone
- `wp_ajax_mealsdb_qo_get_client_rate` action registration is gone
- `billing_rate` float handling is gone from `create_order()`

### 3.2 Remove any remaining dead private helpers
- `client_is_active()` — used only in `log_transaction()` flow. If no longer called, remove it.
- Search for any other private methods only called by the removed methods

---

## Step 4 — Clean up `includes/services/class-invoice-generator.php`

### 4.1 Remove defunct `MealsDB_DB::table()` calls
- After Phase 6 rewrite, no method should call `MealsDB_DB::table('transactions')` or `MealsDB_DB::table('transaction_items')`
- Search the file for any remaining `table('transaction')` references and remove them

### 4.2 Verify `MealsDB_DB::table()` method itself
- If `MealsDB_DB::table()` is a convenience wrapper that only mapped shorthand names to table constants, and it no longer has callers for `'transactions'` or `'transaction_items'`, update its internal map to remove those entries
- Do not remove the method itself if it still handles other table names

---

## Step 5 — Clean up `includes/class-schema.php`

### 5.1 Verify transaction table entries are removed (Phase 1)
- `get_canonical_schema()` must not contain `MealsDB_Tables::TRANSACTIONS` or `MealsDB_Tables::TRANSACTION_ITEMS`
- Run a search for the string `meals_transactions` and `meals_transaction_items` in this file — zero results expected

### 5.2 Verify `meals_client_rates` schema is correct
- Confirm all columns, PK, and indexes from Phase 1, Step 2.2 are present

---

## Step 6 — Clean up `includes/install-schema.php`

### 6.1 Verify defunct migration method removal (Phase 1)
- `migrate_service_name_course_to_meal_type()` body is replaced with a comment
- `create_table_transactions()` is removed
- `create_table_transaction_items()` is removed
- `alter_transactions_add_status()` is removed

### 6.2 Search for any remaining `meals_transactions` or `meals_transaction_items` strings
- Zero results expected in this file after cleanup

---

## Step 7 — Clean up `uninstall.php`

### 7.1 Verify (Phase 1)
- `DROP TABLE IF EXISTS meals_transactions` is removed
- `DROP TABLE IF EXISTS meals_transaction_items` is removed
- `DROP TABLE IF EXISTS meals_client_rates` is present (dropped before `meals_clients`)

---

## Step 8 — Clean up `includes/class-admin-ui.php`

### 8.1 Remove the Transactions admin menu entry
- Search for `add_submenu_page` calls that register the transactions list or transaction detail pages
- Remove those registrations
- If the transactions page had an associated capability check or screen option, remove those too

### 8.2 Verify new menu entries are registered
- "Daily Slips" page (Phase 8)
- "Purchase Order" / Reports section (Phase 9)

---

## Step 9 — Clean up `includes/class-ajax.php`

### 9.1 Remove defunct AJAX class initializations
- If `MealsDB_Ajax_Drafts` is only related to transaction drafts (verify), remove its `init()` call
- **Note:** `meals_drafts` table is for client form drafts, not transaction drafts. Verify `class-ajax-drafts.php` before removing — if it handles client form drafts it must be kept

### 9.2 Add new AJAX class initializations (if not already done in prior phases)
- `MealsDB_Ajax_Rates::init()`
- `MealsDB_Ajax_Historical_Import::init()`
- `MealsDB_Ajax_Delivery_Slips::init()`

---

## Step 10 — Global search for stale references

Run the following searches across all plugin PHP files. Each should return zero results:

| Search String | Expected Result |
|---|---|
| `meals_transactions` | 0 (except comments and migration method comment) |
| `meals_transaction_items` | 0 (except comments) |
| `MealsDB_Tables::TRANSACTIONS` | 0 |
| `MealsDB_Tables::TRANSACTION_ITEMS` | 0 |
| `MealsDB_Transactions::` | 0 |
| `log_transaction` | 0 |
| `mealsdb_qo_get_client_rate` | 0 |
| `get_client_rate` (as method name) | 0 in quick-order-ajax.php |
| `billing_rate` (as POST field) | 0 |
| `wp_posts` (in report/invoice queries) | 0 |
| `woocommerce_order_items` (as table name in queries) | 0 (replaced by `wc_order_items`) |
| `admin-transactions.php` | 0 |

---

## Step 11 — Final `DEV-NOTES` update

### 11.1 Update `DEV-NOTES-db-inventory.md`
- Remove all references to `meals_transactions` and `meals_transaction_items`
- Add `meals_client_rates` entry
- Update the "Risks / Ambiguities" section to reflect resolved items

### 11.2 Update `DEV-NOTES-add-new-client-fields.md`
- If this file contains any reference to `rate` as a flat column on `meals_clients`, update to reflect the `meals_client_rates` table and `default_rate_id` FK

---

## Final Verification Checklist

- Plugin activates without PHP errors or warnings
- `MealsDB_Tables::all()` returns exactly 7 tables: `meals_clients`, `meals_products`, `meals_client_rates`, `meals_staff`, `meals_drafts`, `meals_audit_log`, `meals_ignored_conflicts`
- No 404 or broken menu links in the WP admin
- QuickOrder creates a WC order with correct meta and no transaction log
- Invoice generation produces correct CSV output from WC HPOS data
- Historical import tags existing orders correctly in dry-run and live modes
- Daily slips generate correctly for a known delivery date
- Purchase order CSV exports with correct demand projections
- All nightly sync and cron events are registered
- `uninstall.php` drops all plugin tables cleanly including `meals_client_rates`
