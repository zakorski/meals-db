# Meals DB Database Inventory

## External DB Connectivity
- **Connection creation**: `includes/class-db.php` — `MealsDB_DB::connection()` (alias `get_connection()`) builds a `mysqli` connection using `MealsDB_Config` credentials, sets charset, and caches the handle. Credentials resolved via `MealsDB_Config::db_host()`, `db_user()`, `db_pass()`, and `db_name()`. Fallback logging when missing.
- **Credential/config source**: `MealsDB_Config` (assumed singleton) provides DB host/user/pass/name; `MealsDB_Config::is_db_configured()` gatechecked in installer.
- **Call sites using the connection** (all rely on `MealsDB_DB::get_connection()` unless noted):
  - `includes/install-schema.php`: `MealsDB_Installer::install()`, `create_table_transactions()`, `alter_transactions_add_status()`, `create_table_transaction_items()`, `upgrade_meals_clients_table()`, `create_meals_products_table()` rely on live connection for CREATE/ALTER/SHOW queries against the external database.
  - `includes/class-products.php`: `install_table()`, `get_product_data()`, `save_product_data()` read/write `meals_products` via mysqli.
  - `includes/class-transactions.php`: CRUD helpers (`record_order_transaction()`, `record_transaction_items()`, `get_transactions_by_client()`, `get_recent_transactions()`, `get_transaction_items()`, `get_transactions_for_export()`, `get_transaction_for_order()`, `get_transactions_for_client()`, etc.) use mysqli statements on Meals tables.
  - `includes/class-clients.php`: `delete_clients_with_dependencies()` and `delete_client()` use mysqli statements against `meals_clients`, `meals_drafts`, and `meals_ignored_conflicts`.
  - `includes/class-clients-repository.php`: constructor stores mysqli; methods like `list_clients()`, `get_client()`, `search_clients()`, `save_client()`, `delete_client()`, `get_primary_phone()`, `get_client_for_user()`, and helpers call the external connection.
  - `includes/class-client-form.php`: numerous helpers (`save_client()`, draft handlers, index maintenance, validation helpers) query/alter `meals_clients` and related tables via mysqli.
  - `includes/class-quick-order-ajax.php`: AJAX handlers (`get_categories()`, `get_products_by_category()`, `create_order()`, `clone_order()`, helper queries) open mysqli connections to fetch Meals data and link orders.
  - `includes/class-quick-order-ui.php`: `render_quick_order_page()` fetches Meals clients via mysqli for dropdowns.
  - `includes/class-initials.php`: `generate_initials_for_client()` and `validate_initials()` check `meals_clients` via mysqli.
  - `includes/class-logger.php`: `log()` writes to `meals_audit_log`; `fetch_recent_logs()` reads via mysqli.
  - `includes/class-staff.php` and `includes/ajax/class-ajax-staff.php`: staff CRUD and AJAX helpers call mysqli to operate on `meals_staff`.
  - `includes/class-quick-order-products.php`: cache invalidation hooks rely on product metadata but delegate DB I/O to `MealsDB_Products` (mysqli-backed).
  - `includes/services/sync/class-sync-query.php`: constructor accepts mysqli; `get_meals_clients()`, `get_ignored_conflicts()`, `get_staff_list()`, and candidate-match helpers run SELECTs via mysqli connection when provided.
  - `includes/services/sync/class-sync-mutate.php`: constructor accepts mysqli; `push_to_meals_db()`, `update_meals_client()`, `create_meals_client()`, `link_meals_client_to_wc_user()` perform UPDATE/INSERTs with mysqli and optionally mirror to WP.
  - `includes/class-schema-sync.php`: `run_full_sync()` and internal helpers ensure `meals_clients` schema using mysqli SHOW/ALTER/CREATE.
  - `includes/class-updates.php`: product update routines fetch and upsert Meals product rows over mysqli.
  - `includes/class-quick-order-products.php`: uses `MealsDB_DB::get_table_name()` but actual DB operations happen through `MealsDB_Products` (mysqli).
  - `views/*.php`: `views/drafts.php`, `views/ignored.php`, `views/admin-transactions.php`, `views/admin-transaction-details.php` consume mysqli results passed from controllers or directly call `MealsDB_DB::get_connection()` (e.g., ignored conflicts list). 
  - `uninstall.php`: drops external Meals tables using mysqli connection.

## WordPress DB Usage
- **$wpdb accesses**:
  - `includes/services/class-reports.php` — constructor stores `$wpdb`; `get_resupply_requirements()` and `get_meal_breakdown()` query WooCommerce tables (`woocommerce_order_items`, `woocommerce_order_itemmeta`, `posts`) and join to external `meals_products`.
  - `includes/services/sync/class-sync-query.php` — `find_candidate_wc_matches_for_client()` queries `$wpdb->users` and `$wpdb->usermeta` for potential matches based on name/phone.
  - Other standard WordPress option helpers (`get_option`, `update_option`, etc.) are used for settings/version flags.
  - No MealsDB tables are created or altered in the WordPress database; Meals data lives exclusively in the external DB.

## MealsDB Data Tables Inventory
- **`meals_clients`**: external primary table for clients accessed by most mysqli-based classes (`class-clients-repository.php`, `class-client-form.php`, `class-clients.php`, `class-quick-order-ajax.php`, `class-initials.php`, `class-transactions.php`, `class-schema-sync.php`, `class-quick-order-ui.php`, `class-sync-query.php`, `class-sync-mutate.php`, `class-staff.php`, `uninstall.php`). Legacy alias `mealsdb_clients` used in `MealsDB_DB::get_all_clients()` (external fallback).
- **`meals_drafts`**: created/queried in `install-schema.php`, used in `class-client-form.php` (save/delete/get draft), `views/drafts.php`, and `class-clients.php` dependency cleanup — **external**.
- **`meals_ignored_conflicts`**: created in installer; manipulated in `includes/ajax/class-ajax-sync.php` (insert/delete ignores), read in `class-sync-query.php` and `views/ignored.php`, cleaned in `class-clients.php` — **external**.
- **`meals_audit_log`**: created in installer; written/read in `class-logger.php` — **external**.
- **`meals_transactions`**: created in installer; used in `class-transactions.php` and transaction views; referenced in uninstall — **external**.
- **`meals_transaction_items`**: created in installer; used in `class-transactions.php` for item records — **external**.
- **`meals_products`**: created in installer and `class-products.php`; accessed in `class-products.php`, `class-reports.php`, `class-updates.php`, and via `MealsDB_DB::get_table_name()` in several files — **external**.
- **`meals_staff`**: created in installer; accessed in `class-staff.php`, `ajax/class-ajax-staff.php`, `class-sync-query.php` staff list — **external**.

## Schema Tools & Update UI
- “Update Database Schema” button/form lives in `views/updates.php`; submitted to `includes/class-admin-ui.php::render_main_page()` which checks nonce and calls `MealsDB_Schema_Sync::run_full_sync()`.
- The sync routine currently targets external `meals_clients` only (ensuring existence/columns/indexes) via `class-schema-sync.php` helper methods; no other tables altered by this UI.

## Risks / Ambiguities
- Legacy name `mealsdb_clients` still referenced (`class-db.php::get_all_clients()`), suggesting possible historical table naming; confirm presence before removal.
- Table prefix handling via `MealsDB_DB::get_table_name()` means final external names may include configured prefixes; ensure consistency when cross-referencing.
- Installer drops/creates some tables directly while schema sync UI only touches `meals_clients`, leaving other tables unmanaged; potential drift.
