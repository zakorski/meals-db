# Meals & More — WordPress/WooCommerce SQL Dump: Schema Breakdown
**Source file:** `mealsand_wp_ba74f.sql` (189 MB, ~2.44M lines)  
**Analysis date:** 2026-03-04  
**Purpose:** Migration tool reference — developer handoff

---

## 1. Table Prefix

```
2xnIt_
```

This is a **custom, non-default prefix** (not the common `wp_`). All table names below omit the prefix for readability; developer must prepend `2xnIt_` in all queries.

---

## 2. WordPress Core Tables

All standard WP core tables are present:

| Table | Notes |
|---|---|
| `2xnIt_users` | 1,517 rows |
| `2xnIt_usermeta` | ~80,000+ rows — **heavily used for client data** (see §5) |
| `2xnIt_posts` | 408 INSERT blocks — pages, attachments, nav items, WC products |
| `2xnIt_postmeta` | 313 INSERT blocks — product metadata, SKUs, WC product flags |
| `2xnIt_options` | Present |
| `2xnIt_comments` | Present |
| `2xnIt_commentmeta` | Present |
| `2xnIt_terms` | Present |
| `2xnIt_termmeta` | Present |
| `2xnIt_term_taxonomy` | Present |
| `2xnIt_term_relationships` | Present |
| `2xnIt_links` | Present |

---

## 3. WooCommerce Tables & Order Storage Mode

### Order Storage: **HPOS (High-Performance Order Storage) — PRIMARY**

The site has **fully migrated to HPOS**. Orders live in the HPOS tables. The `2xnIt_posts` table contains **zero `shop_order` post_type rows**. The legacy `woocommerce_order_items` and `woocommerce_order_itemmeta` tables are **still present and populated** (they serve as the line-item store even under HPOS), but the order headers are exclusively in `wc_orders`.

**Verdict: HPOS only. No legacy `wp_posts`-based orders.**

### WooCommerce Tables Found

| Table | Description | Row Count |
|---|---|---|
| `2xnIt_wc_orders` | HPOS order headers | **16,340** |
| `2xnIt_wc_orders_meta` | HPOS order metadata | ~high (26 distinct keys) |
| `2xnIt_wc_order_addresses` | Billing/shipping addresses per order | present |
| `2xnIt_wc_order_operational_data` | HPOS operational fields | present |
| `2xnIt_wc_order_stats` | Analytics | present |
| `2xnIt_wc_order_product_lookup` | Product lookup index | present |
| `2xnIt_wc_order_coupon_lookup` | Coupon lookup | present |
| `2xnIt_wc_order_tax_lookup` | Tax lookup | present |
| `2xnIt_wc_customer_lookup` | Customer analytics | present |
| `2xnIt_wc_admin_notes` | Admin inbox | present |
| `2xnIt_wc_admin_note_actions` | Admin note actions | present |
| `2xnIt_wc_category_lookup` | Category index | present |
| `2xnIt_wc_product_attributes_lookup` | Attribute index | present |
| `2xnIt_wc_product_meta_lookup` | Product meta index | present |
| `2xnIt_wc_product_download_directories` | Download dirs | present |
| `2xnIt_wc_download_log` | Download log | present |
| `2xnIt_wc_tax_rate_classes` | Tax rate classes | present |
| `2xnIt_wc_rate_limits` | Rate limiting | present |
| `2xnIt_wc_reserved_stock` | Stock reservation | present |
| `2xnIt_wc_webhooks` | Webhooks | present |
| `2xnIt_woocommerce_order_items` | Line items (HPOS compatible) | **128,104** |
| `2xnIt_woocommerce_order_itemmeta` | Line item metadata | ~1M+ rows |
| `2xnIt_woocommerce_tax_rates` | Tax rates | present |
| `2xnIt_woocommerce_tax_rate_locations` | Tax rate locations | present |
| `2xnIt_woocommerce_shipping_zones` | Shipping zones | present |
| `2xnIt_woocommerce_shipping_zone_locations` | Zone locations | present |
| `2xnIt_woocommerce_shipping_zone_methods` | Zone methods | present |
| `2xnIt_woocommerce_payment_tokens` | Saved payment tokens | present |
| `2xnIt_woocommerce_payment_tokenmeta` | Payment token meta | present |
| `2xnIt_woocommerce_downloadable_product_permissions` | DL permissions | present |
| `2xnIt_woocommerce_log` | WC log | present |
| `2xnIt_woocommerce_sessions` | Guest sessions | present |
| `2xnIt_woocommerce_api_keys` | REST API keys | present |
| `2xnIt_woocommerce_attribute_taxonomies` | Attribute taxonomy | present |
| `2xnIt_woocommerce_gc_cards` | Gift card data | present |
| `2xnIt_woocommerce_gc_cardsmeta` | Gift card metadata | present |
| `2xnIt_woocommerce_gc_activity` | Gift card activity | present |

### `wc_orders` Schema

```sql
CREATE TABLE `2xnIt_wc_orders` (
  `id`                   bigint UNSIGNED NOT NULL,
  `status`               varchar(20),          -- e.g. 'wc-processing', 'wc-completed', 'wc-paid'
  `currency`             varchar(10),          -- 'CAD' throughout
  `type`                 varchar(20),          -- 'shop_order'
  `tax_amount`           decimal(26,8),
  `total_amount`         decimal(26,8),
  `customer_id`          bigint UNSIGNED,      -- FK → 2xnIt_users.ID
  `billing_email`        varchar(320),
  `date_created_gmt`     datetime,
  `date_updated_gmt`     datetime,
  `parent_order_id`      bigint UNSIGNED,
  `payment_method`       varchar(100),         -- 'cod', 'cheque', '' (mapped to eTransfer/Cash)
  `payment_method_title` varchar(100),
  `transaction_id`       varchar(200),
  `ip_address`           varchar(100),
  `user_agent`           text,
  `customer_note`        text                  -- used for client notes/billing references
)
```

### Order Status Distribution

| Status | Count |
|---|---|
| `wc-processing` | 11,540 |
| `wc-completed` | 3,109 |
| `wc-paid` | 1,634 |
| `wc-cancelled` | 56 |
| `wc-on-hold` | 1 |
| **Total** | **16,340** |

### `wc_order_addresses` Schema

```sql
CREATE TABLE `2xnIt_wc_order_addresses` (
  `id`           bigint UNSIGNED NOT NULL,
  `order_id`     bigint UNSIGNED NOT NULL,
  `address_type` varchar(20),    -- 'billing' or 'shipping'
  `first_name`   text,
  `last_name`    text,
  `company`      text,
  `address_1`    text,
  `address_2`    text,
  `city`         text,
  `state`        text,
  `postcode`     text,
  `country`      text,
  `email`        text,
  `phone`        text
)
```

---

## 4. Custom / Plugin Tables

**There are NO `meals_` prefixed tables in this dump.** The Meals DB plugin's custom tables (meals_clients, meals_transactions, etc.) exist in the **separate external encrypted MySQL database**, not in the WordPress database. This dump is the legacy WordPress/WooCommerce site only.

### Notable Third-Party Plugin Tables

| Table | Plugin | Row Count | Notes |
|---|---|---|---|
| `2xnIt_actionscheduler_actions` | Action Scheduler (WC core) | high | Scheduled background jobs |
| `2xnIt_actionscheduler_claims` | Action Scheduler | present | |
| `2xnIt_actionscheduler_groups` | Action Scheduler | present | |
| `2xnIt_actionscheduler_logs` | Action Scheduler | present | |
| `2xnIt_simple_history` | Simple History | **34,538** | Admin audit log — valuable for migration forensics |
| `2xnIt_simple_history_contexts` | Simple History | present | |
| `2xnIt_snippets` | Code Snippets | 5 | Custom PHP snippets active on site |
| `2xnIt_icwp_wpsf_*` (13 tables) | Shield Security (iControlWP) | present | Security logs, IP rules, MFA, bot signals |
| `2xnIt_e_events` / `2xnIt_e_notes` / `2xnIt_e_submissions` | Elementor | 0 | Form submissions — empty |
| `2xnIt_tinvwl_lists` / `2xnIt_tinvwl_items` / `2xnIt_tinvwl_analytics` | TI WooCommerce Wishlist | 0 rows | Unused |
| `2xnIt_smush_dir_images` | Smush (image optimizer) | present | |
| `2xnIt_woostify_*` (7 tables) | Woostify theme | present | Product/filter index tables |
| `2xnIt_wpforms_payments` / `2xnIt_wpforms_logs` | WPForms | present | |
| `2xnIt_wt_iew_action_history` / `2xnIt_wt_iew_mapping_template` | WebToffee Import/Export | present | Product import tool |
| `2xnIt_wpmailsmtp_*` | WP Mail SMTP | present | |

---

## 5. Client Data Shape

### Where Client Data Lives

**All client-specific data is stored in `2xnIt_usermeta`** — there is no separate client table in this WordPress DB. Every client is a WordPress user (`2xnIt_users`), and their operational data is stored as individual usermeta key/value rows.

### Custom usermeta keys for client management

The following non-standard keys were added by custom plugin(s). Each key has ~890 rows (one per active client user):

| Meta Key | Count | Description |
|---|---|---|
| `customer_group` | 890 | Client type: `sdnb`, `private`, `veterans`, `sdnb rural`, `SDNB`, `Extra Mural` |
| `service_id` | 815 | Government service ID (SDNB requisition service identifier) |
| `requisition_id` | 815 | SDNB requisition number |
| `individual_id` | 827 | Government individual ID (VAC: 7-digit; SDNB: 6-digit) |
| `mains` | 827 | Number of main meals per delivery |
| `sides` | 827 | Number of side meals per delivery |
| `rate` | 827 | Billing rate period: `week`, `month`, `day` |
| `basic_cost` | 890 | Per-meal unit cost |
| `delivery_fee` | 890 | Delivery charge |
| `service` | 827 | Service frequency unit |
| `service_centre_charged` | 890 | Which service centre: `Moncton`, `Sussex`, `veterans`, `0` |
| `delivery_frequency` | 890 | Delivery frequency (numeric) |
| `ordering_frequency` | 890 | How often client orders |
| `payment_method` | 890 | `invoice`, `cash`, `cheque`, `etransfer`, etc. |
| `dietary_needs` | 890 | Free-text dietary restrictions/notes |
| `customer_comments` | 890 | Free-text operational notes |
| `freeze_capacity` | 890 | Number of meals client's freezer holds |
| `commence_date` | 827 | Service start date |
| `requisition_units` | 890 | Units on requisition |
| `invoice_ask` | 227 | Invoice preference |
| `vat_number` | 768 | HST/VAT exempt number |
| `service_termination_date` | 827 | End of service date |
| `contribution` | 743 | Client contribution amount |
| `last_call_date` | 845 | Last staff call date |
| `last_order_date` | 845 | Last order date |
| `next_email_date` | 640 | Scheduled next email |

### Customer Group Distribution

| Group | Count | Notes |
|---|---|---|
| `sdnb` | 476 | SDNB government-billed (main Moncton region) |
| `private` | 333 | Private pay clients |
| `veterans` | 63 | Veterans Affairs Canada (VAC) |
| `sdnb rural` | 7 | Rural SDNB |
| `SDNB` | 2 | Case variant (same as sdnb — data quality issue) |
| `Extra Mural` | 2 | Extra Mural program |
| **Total** | **883** | |

### Sample Client Record — SDNB (redacted)

```
user_id:              516
customer_group:       sdnb
service_id:           [6-digit SDNB service ID]
requisition_id:       [5-digit requisition number]
individual_id:        [6-digit individual ID]
mains:                1
sides:                1
rate:                 week
basic_cost:           14.66
delivery_fee:         0
service:              day
delivery_frequency:   1
payment_method:       invoice
commence_date:        2023-12-19
freeze_capacity:      4
service_centre_charged: Moncton
dietary_needs:        0 (none)
```

### Sample Client Record — Veterans (redacted)

```
user_id:              633
customer_group:       veterans
individual_id:        [7-digit VAC individual ID]
mains:                7
sides:                0
rate:                 week
basic_cost:           9.05
delivery_fee:         0
service:              week
delivery_frequency:   2
ordering_frequency:   2
payment_method:       invoice
commence_date:        2024-05-09
freeze_capacity:      2
service_centre_charged: veterans
customer_comments:    [contact name noted]
```

### Sample Client Record — Private (redacted)

```
user_id:              543
customer_group:       private
basic_cost:           9.05
delivery_fee:         0
delivery_frequency:   none
payment_method:       cash
last_call_date:       2025-11-20
last_order_date:      2025-11-20
service_id:           (empty)
requisition_id:       (empty)
individual_id:        (empty)
```

### No `meals_clients` or `meals_client_rates` tables

These **do not exist** in this WordPress database. The Meals DB plugin's `meals_clients` table (with encrypted fields) lives in the **external encrypted MySQL database** — separate from this dump.

---

## 6. Order Meta Keys

### `wc_orders_meta` — Distinct Keys (HPOS order meta)

| Meta Key | Count | Relevance |
|---|---|---|
| `_billing_address_index` | 16,340 | Searchable billing address blob |
| `_shipping_address_index` | 16,340 | Searchable shipping address blob |
| `_wc_order_attribution_source_type` | 16,018 | Traffic source |
| `_edit_lock` | 10,483 | Admin lock |
| `_billing_vat_number` | 9,864 | HST/VAT exemption number per order |
| `_vibe_clone_orders_cloned_from` | 4,855 | Clone/repeat order reference — **important for migration** |
| `_wc_order_attribution_*` | ~2,700 | Session/UTM tracking |
| `is_vat_exempt` | 2,686 | Tax exemption flag |
| `_billing_invoice_ask` | 2,446 | Invoice request flag |
| `wf_order_exported_status` | 1,074 | Export tracking |
| `_invoice_generated` | 251 | Invoice generation flag |
| `_deleted_from` | 9 | Deletion audit trail |

> **Migration note:** `_vibe_clone_orders_cloned_from` appears on 4,855 orders — this site uses an order-cloning plugin to create repeat weekly/biweekly orders. Migrated orders should preserve or map this parent-child relationship.

### `woocommerce_order_itemmeta` — Distinct Keys (Line Item Meta)

| Meta Key | Count | Description |
|---|---|---|
| `_tax_class` | 119,560 | Tax class per line item |
| `_line_total` | 119,560 | Line item total |
| `_line_tax` | 119,560 | Line item tax |
| `_line_tax_data` | 119,560 | Serialized tax breakdown |
| `_product_id` | 119,553 | WC product ID |
| `_variation_id` | 119,553 | Variation ID (0 if no variation) |
| `_qty` | 119,553 | Quantity |
| `_line_subtotal` | 119,553 | Subtotal before discount |
| `_line_subtotal_tax` | 119,553 | Tax on subtotal |
| `_reduced_stock` | 110,874 | Stock reduction tracking |
| `rate_id` | 9,378 | Tax rate identifier (tax line items) |
| `label` | 9,378 | Tax label |
| `compound` | 9,378 | Compound tax flag |
| `tax_amount` | 9,378 | Tax amount |
| `shipping_tax_amount` | 9,378 | Shipping tax |
| `rate_percent` | 9,378 | Tax rate percentage |
| `_fee_amount` | 7 | Fee line amounts |
| `_tax_status` | 7 | Tax status on fee lines |
| `wc_gc_giftcard_*` | 4–16 | Gift card fields |

> **No custom `meals_`, `client_`, `sdnb_`, `veteran_`, or `rate_` keys found in order item meta.** Billing-type information is captured on the user record, not embedded in order line items.

---

## 7. Product Data

| Metric | Value |
|---|---|
| Distinct WooCommerce products (by SKU) | **168** |
| Product SKU format | 5-digit numeric (e.g. `12026`, `14107`) or special codes (e.g. `FEE`) |
| `meals_products` table | **Does not exist in this dump** |
| Product storage | `2xnIt_posts` (post_type = `product`) + `2xnIt_postmeta` (price, SKU, stock, etc.) |
| Gift card products | Present (`wc_gc_*` tables active, gift card line item meta found) |
| Product categories | Dietary-need based: Diabetic, Low Sodium, Low Calorie, Low Fat, Gluten Free, Halal |
| Typical SKU range | `12xxx` = mains; `14xxx` = sides/desserts; `1xxxx` = other |

> **Note:** The product catalog in this legacy site maps to Apetito supplier SKUs. The 168 SKUs represent the active storefront. Historical order items may reference retired SKU IDs.

---

## 8. Row Counts — Key Tables

| Table | Row Count | Storage Note |
|---|---|---|
| `2xnIt_users` | **1,517** | All WordPress users including admins |
| `2xnIt_wc_orders` | **16,340** | HPOS — all orders Jul 2024 – Mar 2026 |
| `2xnIt_woocommerce_order_items` | **128,104** | Line items across all orders |
| `2xnIt_woocommerce_order_itemmeta` | ~1,000,000+ | Item metadata |
| `2xnIt_simple_history` | **34,538** | Admin audit log |
| `meals_clients` | **N/A** | Not in this DB — external encrypted DB |
| `meals_client_rates` | **N/A** | Not in this DB — external encrypted DB |
| `meals_products` | **N/A** | Not in this DB |

**Order date range:** 2024-07-26 through 2026-03-18

---

## 9. Encrypted Fields

**No AES-256-CBC or base64-encoded blob columns were found** in this WordPress database dump. All values in `usermeta`, `wc_orders_meta`, and `postmeta` are stored as plaintext strings, serialized PHP arrays, or MD5 hashes.

Notable findings:

- `_shipping_hash`, `_coupons_hash`, `_fees_hash`, `_taxes_hash` in `wc_orders_meta` contain **MD5 hex hashes** (32-char hex strings) — used by WooCommerce for cache invalidation, not encrypted PII.
- `session_tokens` in `usermeta` contains **serialized PHP** with hashed session data (not base64-encoded ciphertext).
- `user_pass` in `2xnIt_users` is **phpBB/WordPress bcrypt hashed** (standard WP password storage).
- PII such as addresses, phone numbers, dietary notes, and client comments is stored **in plaintext** in `usermeta`.

> **Contrast with Meals DB:** The external encrypted database (not this dump) uses AES-256-CBC encryption on sensitive client fields. This legacy WordPress DB does **not** encrypt PII at rest.

---

## 10. Summary

This is the production WordPress/WooCommerce database for **Meals and More** (mealsandmore.ca), a frozen meal delivery service in New Brunswick serving approximately **883 active clients** across three client types. The site uses a **custom table prefix (`2xnIt_`)** and has fully adopted **WooCommerce HPOS** — all 16,340 orders (spanning July 2024 to March 2026) are stored in `wc_orders`, with zero legacy `shop_order` posts in `wp_posts`.

Client data is stored entirely within **WordPress usermeta** — there is no separate client table in this database. Each of the ~890 client-users has 20–25 custom usermeta keys recording government billing fields (`service_id`, `requisition_id`, `individual_id`), delivery parameters (`mains`, `sides`, `delivery_frequency`, `freeze_capacity`), and operational notes. The three client types are **SDNB** (~54%, government-billed via Service de nutrition du Nouveau-Brunswick), **private** (~38%, direct pay), and **veterans** (~7%, Veterans Affairs Canada). A small number of Edge cases exist: `sdnb rural` and `Extra Mural` variants, and two rows with a capitalization mismatch (`SDNB` vs `sdnb`) indicating a data quality issue to resolve pre-migration.

Orders connect to clients via `wc_orders.customer_id → 2xnIt_users.ID`. Each order has ~8 line items on average (128,104 items across 16,340 orders), all of type `line_item` or `tax`. A **repeat-order cloning plugin** was in use — 4,855 orders carry a `_vibe_clone_orders_cloned_from` meta key pointing to the parent order, which is important for preserving ordering cadence history. The product catalog has **168 distinct SKUs**, stored in `wp_posts` as WooCommerce products. PII (addresses, phone numbers, dietary notes) is stored **in plaintext** in usermeta — no column-level encryption exists in this database; encryption exists only in the separate Meals DB external MySQL database which is not included in this dump.

---

*Analysis performed via automated parsing of the raw SQL dump. Row counts are exact where each INSERT is one row; for bulk INSERT statements, counts are based on statement-level parsing.*
