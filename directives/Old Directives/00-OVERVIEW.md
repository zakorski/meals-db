# Meals DB Plugin — Refactor Overview

## Goal

Simplify the Meals DB plugin by removing all transaction-related infrastructure and replacing it with direct WooCommerce HPOS queries, driven through the plugin's own `meals_clients` table as the entry point. The result is a leaner plugin where WooCommerce is the authoritative order store and the encrypted external database holds only what WooCommerce cannot: government client data, product metadata, staff records, and operational data.

---

## Guiding Principles

- **No schema changes to WordPress or WooCommerce tables.** The plugin reads WC/WP data via WooCommerce's own CRUD API and `$wpdb` queries only. It writes order meta via `$order->update_meta_data()` (standard WC API — not a schema change).
- **`meals_clients` is the entry point for all government reporting.** Invoice and slip generators start by querying `meals_clients` for SDNB or Veteran clients, resolve their `wp_user_id`, then traverse WC tables from there.
- **Private clients do not exist in the external DB.** They are WP/WC users only. The sync system and client importer enforce this gate.
- **`meals_transactions` and `meals_transaction_items` are eliminated entirely.** All order data lives in WooCommerce HPOS tables (`wc_orders`, `wc_order_items`, `wc_order_itemmeta`).
- **Rate management moves to a new `meals_client_rates` table.** Clients may have multiple rates; one is flagged as default. QuickOrder presents a rate selector per client. The selected `rate_id` is written as `mealsdb_rate_id` order meta on the WC order.

---

## External Database — Final Table Set

| Table | Status | Purpose |
|---|---|---|
| `meals_clients` | **Keep / Modify** | SDNB and Veteran clients only. Remove `rate` decimal column; add `default_rate_id` FK. |
| `meals_client_rates` | **New** | Multiple named rates per client with default flag. |
| `meals_products` | **Keep** | WC product enrichment (type, taxable, case size, cost). |
| `meals_staff` | **Keep** | Staff records. |
| `meals_drafts` | **Keep** | Client form drafts. |
| `meals_audit_log` | **Keep** | Audit trail. |
| `meals_ignored_conflicts` | **Keep** | Sync conflict ignores. |
| `meals_transactions` | **Drop** | Replaced by direct WC HPOS queries. |
| `meals_transaction_items` | **Drop** | Replaced by direct WC HPOS queries. |

---

## WooCommerce Order Meta Written by Plugin

| Meta Key | Written By | Purpose |
|---|---|---|
| `mealsdb_client_user_id` | QuickOrder on order creation | Links WC order to WP user ID |
| `mealsdb_client_id` | QuickOrder on order creation | Links WC order to `meals_clients.client_id` |
| `mealsdb_rate_id` | QuickOrder on order creation | FK to `meals_client_rates.rate_id` |

These are written via `$order->update_meta_data()` — no schema changes to WC tables.

---

## Join Chain for Invoice / Slip Generation

```
meals_clients (client_id, wp_user_id, client_type, ...)
    ↓  wp_user_id = wc_orders.customer_id
wc_orders (id, customer_id, status, date_created_gmt, ...)
    ↓  order meta: mealsdb_rate_id → meals_client_rates.rate_id
    ↓  id = wc_order_items.order_id
wc_order_items (order_item_id, order_id, order_item_name, ...)
    ↓  order_item_id = wc_order_itemmeta.order_item_id
wc_order_itemmeta (_product_id, _qty, _line_subtotal, _line_tax, _line_total)
    ↓  _product_id = meals_products.wc_product_id
meals_products (product_type, taxable, case_size, unit_cost, ...)
```

---

## Plugin Files — What Changes

### Deleted entirely
- `includes/class-transactions.php`
- `includes/ajax/class-ajax-drafts.php` *(if draft system is transactions-related only — verify)*
- `views/admin-transactions.php`
- `views/admin-transaction-details.php`

### Heavily rewritten
- `includes/class-schema.php` — remove `TRANSACTIONS` and `TRANSACTION_ITEMS` schema definitions; add `CLIENT_RATES` schema
- `includes/class-tables.php` — remove `TRANSACTIONS` and `TRANSACTION_ITEMS` constants; add `CLIENT_RATES`
- `includes/class-quick-order-ajax.php` — remove `log_transaction()` call; replace flat `billing_rate` field with `rate_id` selector; write `mealsdb_rate_id` order meta
- `includes/services/class-invoice-generator.php` — rewrite all four generator methods to query via `meals_clients` → WC HPOS join chain
- `includes/services/class-reports.php` — update `get_resupply_requirements()` and `get_meal_breakdown()` to use HPOS tables (`wc_orders` not `posts`)
- `includes/install-schema.php` — remove transaction table creation; add `meals_client_rates` creation; add drop routine for old tables
- `uninstall.php` — remove transaction table drops; add `meals_client_rates` drop

### Modified
- `includes/services/class-client-importer.php` — add `client_type` gate (skip external DB write for Private clients)
- `includes/services/sync/class-sync-mutate.php` — add `client_type` gate; enforce SDNB/Veteran-only writes to external DB
- `includes/services/sync/class-sync-query.php` — add nightly cron hook registration
- `includes/class-quick-order-ui.php` — add rate selector dropdown to QuickOrder form
- `assets/js/quick-order.js` — wire rate selector; fetch rates per client on client change

### New files
- `includes/services/class-wc-order-query.php` — reusable service for querying WC HPOS order data by `wp_user_id` list and date range
- `includes/services/class-historical-import.php` — one-time importer that writes `mealsdb_client_user_id`, `mealsdb_client_id`, and `mealsdb_rate_id` meta onto existing WC orders for SDNB/VAC clients
- `includes/services/class-delivery-slip-generator.php` — packing/picking/delivery slip generator
- `includes/ajax/class-ajax-rates.php` — AJAX endpoint to fetch rates for a given client

---

## Seven Requirements — Implementation Map

| # | Requirement | Implementation |
|---|---|---|
| 1 | One-time import of 14,500 orders + clients | `class-historical-import.php` writes order meta onto existing WC orders; client CSV import already functional |
| 2 | Only SDNB/Veteran clients in external DB | `client_type` gate in importer and sync mutate |
| 3 | Bidirectional sync + nightly cron | Existing sync infrastructure + hook registrations + WP-Cron scheduler |
| 4 | QuickOrder as WC POS | Already correct at order creation; remove `log_transaction()`; add rate selector |
| 5 | Monthly government invoices | Rewritten `class-invoice-generator.php` using HPOS join chain |
| 6 | Packing/picking/delivery slips | New `class-delivery-slip-generator.php` |
| 7 | Predictive ordering / PO prep | Updated `class-reports.php` using HPOS; forecasting layer built on top |

---

## Phase Breakdown

Each phase has its own detailed prompt document:

- **Phase 1** — Schema changes: drop transaction tables, add `meals_client_rates`
- **Phase 2** — Client type gate: enforce SDNB/Veteran-only in importer and sync
- **Phase 3** — Sync triggers and nightly cron
- **Phase 4** — QuickOrder: remove transaction logging, add rate selector
- **Phase 5** — WC HPOS query service layer
- **Phase 6** — Invoice generator rewrite
- **Phase 7** — Historical order import utility
- **Phase 8** — Delivery slip generator
- **Phase 9** — Reports and predictive ordering update
- **Phase 10** — Cleanup: remove dead code, dead views, dead AJAX handlers
