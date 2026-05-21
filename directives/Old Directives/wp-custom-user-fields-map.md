# Custom User Fields Map — WordPress/WooCommerce Database

## Purpose

This document maps every custom user field used by Meals and More across the WordPress/WooCommerce database. Any code that reads or writes user data needs to know:

1. **Where the field lives** — table, column, and (for `wp_usermeta`) the `meta_key`
2. **Who created it** — the old Enzebra `Custom User Fields` plugin, WooCommerce core, or other Enzebra plugins
3. **What it represents** — the business meaning
4. **How the new MealsDB plugin uses it** — column in `meals_clients` it syncs to (if any)

The data verification was done against the production database dump (`mealsand_wp_ba74f.sql`, prefix `2xnIt_`).

---

## Database tables involved

All fields live in two tables. WordPress/WooCommerce do not create plugin-specific tables for these.

- **`2xnIt_users`** — core WP user record. Standard columns: `ID`, `user_login`, `user_email`, `user_registered`, etc.
- **`2xnIt_usermeta`** — key/value table. Schema: `umeta_id`, `user_id`, `meta_key`, `meta_value`. **All custom fields live here.**

The plugin's mirror table is `meals_clients` in the external encrypted database (separate connection from the main WordPress DB). It is the canonical store going forward; `2xnIt_usermeta` is treated as a sync source/destination for back-compat with WC and any code that still reads usermeta.

---

## Fields defined by the old Enzebra `Custom User Fields` plugin

These 24 keys were registered by `custom_user_fields/custom_user_fields.php`. They are now also tracked in the `meals_clients` table by the new plugin. The user counts below are from the production database dump (out of ~1,500 active users, ~558 of which are government clients).

| Meta Key | Type | Business Meaning | meals_clients Column | Live Count |
|---|---|---|---|---|
| `customer_group` | text | Categorization label (rarely populated meaningfully) | _not synced — legacy only_ | 909 |
| `service_id` | text | SDNB service identifier | `service_id` | 834 |
| `requisition_id` | text | SDNB requisition number | `requisition_id` (encrypted) + `requisition_id_index` (HMAC for lookup) | 834 |
| `individual_id` | text | SDNB individual identifier | `individual_id` (encrypted) + `individual_id_index` (HMAC) | 846 |
| `basic_cost` | text/decimal | Per-unit basic cost (legacy field, not used in current billing) | _not synced — legacy only_ | 909 |
| `rate` | text | Government billing rate label (e.g., "14.66" or "15.47") — drives mains/sides pricing | `default_rate_id` (FK to `meals_client_rates`) | 846 |
| `payment_method` | text | "Cash", "Stripe", "Bank", etc. — drives driver collection logic | `payment_method` | 909 |
| `mains` | text/integer | Monthly mains allowance | `allowance_mains` | 846 |
| `sides` | text/integer | Monthly sides allowance | `allowance_sides` | 846 |
| `service` | text | SDNB service name (e.g., "M&M", "GHS") | _not synced — legacy reference field only_ | 846 |
| `commence_date` | date | Service start date | `service_commence_date` | 846 |
| `service_termination_date` | date | Service end date | `termination_date` | 846 |
| `service_centre_charged` | text | SDNB service centre code | `service_center_charged` | 909 |
| `ordering_frequency` | integer | **Days** between orders (1, 7, 14, 28, etc.) | `ordering_frequency` | 909 |
| `ordering_contact_method` | text | "Phone", "Email", "MaM Calls Client" — used by call list | `ordering_contact_method` | 909 |
| `delivery_frequency` | integer | **Days** between deliveries | `delivery_frequency` | 909 |
| `freeze_capacity` | text | Freezer capacity descriptor | `freezer_capacity` | 909 |
| `delivery_fee` | decimal | Per-delivery fee charged to client (driver collects) | `delivery_fee` | 909 |
| `requisition_units` | integer | SDNB approved unit count | `units` | 909 |
| `last_call_date` | date | Date of last phone call to client | _wp_usermeta only — call log writes; QuickOrder updates_ | 865 |
| `last_order_date` | datetime | Timestamp of last order placed | _wp_usermeta only — QuickOrder updates_ | 865 |
| `dietary_needs` | text | Allergens / dietary restrictions | `diet_concerns` | 909 |
| `contribution` | decimal | Monthly client contribution (deducted from SDNB invoice) | `client_contribution` | 762 |
| `customer_comments` | textarea | Free-form notes about the client | `customer_comments` | 909 |
| `next_email_date` | textarea | Next scheduled email date for email clients | _wp_usermeta only — call log uses_ | 661 |

### Important units note

`ordering_frequency` and `delivery_frequency` are stored as **integer days**, not weeks. The current allocation engine reads `delivery_frequency` and uses it as days directly (`class-allocation-engine.php` line 187: `$cursor->modify('+' . $delivery_frequency . ' days')`). Any new code must follow this convention.

---

## Standard WooCommerce fields the plugin reads/writes

These are NOT defined by Enzebra plugins — they're WC core. The MealsDB plugin uses them for identity sync.

### Customer billing address (canonical name + phone source)

| Meta Key | meals_clients Column | Notes |
|---|---|---|
| `billing_first_name` | _used to derive `first_name`_ | Encrypted before storage |
| `billing_last_name` | _used to derive `last_name`_ | Encrypted before storage |
| `billing_phone` | `client_phone_1` | Primary client phone |
| `billing_email` | `client_email` | Primary client email |
| `billing_address_1` | `street_name` (sync) | Street address line 1 |
| `billing_city` | `city` | City |
| `billing_state` | `province` | Province (e.g., "NB") |
| `billing_postcode` | `postal_code` | Postal code |
| `billing_country` | _not synced_ | Always "CA" in this dataset |
| `billing_company` | _not synced_ | Rarely populated |

### Customer shipping address (canonical delivery info)

| Meta Key | meals_clients Column | Notes |
|---|---|---|
| `shipping_first_name` | _not directly synced_ | Generally same as billing |
| `shipping_last_name` | _not directly synced_ | Generally same as billing |
| `shipping_phone` | _not synced; only ~60% populated_ | |
| `shipping_address_1` | `delivery_street_name` | Street address line 1 |
| `shipping_address_2` | `delivery_area_name` | **CRITICAL: This is where the delivery zone is stored** (e.g., "Zone 1", "Zone 3"). 1,096 of 1,533 users have a zone here. |
| `shipping_city` | `delivery_city` | City |
| `shipping_state` | `delivery_province` | Province |
| `shipping_postcode` | `delivery_postal_code` | Postal code |
| `shipping_country` | _not synced_ | Always "CA" |

### `shipping_address_2` is special

The old `woo-order-export` plugin used `shipping_address_2` to determine which zone an order belonged to, then mapped that zone to a delivery day. The plugin now syncs this into `meals_clients.delivery_area_name`. If you're querying for delivery scheduling, use `delivery_area_name` rather than `shipping_address_2` directly — it's already been normalized and is queryable on the external DB.

The actual zone values found in production are: `Zone 0`, `Zone 1`, `Zone 2`, `Zone 3`, `Zone 4`, `Zone 5`, `Zone 6`. Plus a small number (~613) of empty values and ~10 rows where someone entered an apartment number or similar instead of a zone — these are data-cleanup candidates.

---

## Standard WordPress fields

The plugin reads these via `get_userdata()` (WP_User properties), not `get_user_meta()`.

| Source | meals_clients Column |
|---|---|
| `WP_User::user_email` | `client_email` |
| `WP_User::first_name` (via `first_name` meta) | `first_name` (encrypted) |
| `WP_User::last_name` (via `last_name` meta) | `last_name` (encrypted) |

Note: `first_name` and `last_name` exist as user meta keys but are also exposed as properties on the `WP_User` object. The new plugin reads them as core fields (type=`core`) in the sync map.

---

## New plugin's own custom meta keys

The new MealsDB plugin uses its own `mealsdb_*` namespace for fields that have no standard WC equivalent. These are PUSHED to wp_usermeta from `meals_clients` so legacy code can still find them, but the canonical store is the `meals_clients` table.

| Meta Key | meals_clients Column | Notes |
|---|---|---|
| `mealsdb_client_phone_2` | `client_phone_2` | Secondary phone |
| `mealsdb_street_name` | `street_name` | Cleaned street name (separate from `billing_address_1`) |
| `mealsdb_delivery_street_name` | `delivery_street_name` | Cleaned delivery street name |
| `mealsdb_alternate_contact_name` | `alternate_contact_name` | E.g., spouse, daughter |
| `mealsdb_alternate_contact_phone_1` | `alternate_contact_phone_1` | |
| `mealsdb_alternate_contact_phone_2` | `alternate_contact_phone_2` | |
| `mealsdb_alternate_contact_email` | `alternate_contact_email` | |

These are defined in `class-sync.php::get_field_to_wp_meta_map()` as type=`meta` entries.

---

## Other meta keys present in the database (informational)

The production dump contains additional custom meta keys not used by MealsDB. Documented here so they're not mistaken for plugin-managed data:

| Meta Key | Source | Notes |
|---|---|---|
| `vat_number`, `billing_vat_number` | WooCommerce VAT plugin | EU VAT — not relevant in NB; legacy data |
| `last_update` | unknown WC plugin | Stale field, ignored by MealsDB |
| `default_password_nag` | WordPress core | "Set a real password" reminder |
| `source_user_id` | unknown migration | Likely from when users were imported |
| `role` | unknown plugin | Distinct from `2xnIt_capabilities` (which is the real role) |
| `icwp-wpsf-meta` | Shield Security plugin | Unrelated security plugin data |
| `screen_layout_inspire_invoice` | Inspire Invoices plugin | Unrelated |
| `manageedit-inspire_invoicecolumnshidden` | Inspire Invoices plugin | Unrelated |
| `invoice_ask` | Inspire Invoices plugin | Unrelated |

If you encounter these in queries or backfills, ignore them.

---

## Bidirectional sync map — canonical reference

The new plugin's `MealsDB_Sync::get_field_to_wp_meta_map()` is the single source of truth for which `meals_clients` columns sync to which WP storage. Reproduced here:

```php
[
    // Core WP user fields (read via WP_User property)
    'first_name'                    => ['type' => 'core', 'key' => 'first_name'],
    'last_name'                     => ['type' => 'core', 'key' => 'last_name'],
    'client_email'                  => ['type' => 'core', 'key' => 'user_email'],

    // Standard WC billing meta
    'client_phone_1'                => ['type' => 'meta', 'key' => 'billing_phone'],
    'city'                          => ['type' => 'meta', 'key' => 'billing_city'],
    'province'                      => ['type' => 'meta', 'key' => 'billing_state'],
    'postal_code'                   => ['type' => 'meta', 'key' => 'billing_postcode'],

    // Standard WC shipping meta
    'delivery_city'                 => ['type' => 'meta', 'key' => 'shipping_city'],
    'delivery_province'             => ['type' => 'meta', 'key' => 'shipping_state'],
    'delivery_postal_code'          => ['type' => 'meta', 'key' => 'shipping_postcode'],

    // Plugin-managed custom meta (no standard WC equivalent)
    'client_phone_2'                => ['type' => 'meta', 'key' => 'mealsdb_client_phone_2'],
    'street_name'                   => ['type' => 'meta', 'key' => 'mealsdb_street_name'],
    'delivery_street_name'          => ['type' => 'meta', 'key' => 'mealsdb_delivery_street_name'],
    'alternate_contact_name'        => ['type' => 'meta', 'key' => 'mealsdb_alternate_contact_name'],
    'alternate_contact_phone_1'     => ['type' => 'meta', 'key' => 'mealsdb_alternate_contact_phone_1'],
    'alternate_contact_phone_2'     => ['type' => 'meta', 'key' => 'mealsdb_alternate_contact_phone_2'],
    'alternate_contact_email'       => ['type' => 'meta', 'key' => 'mealsdb_alternate_contact_email'],
]
```

### What's NOT in this map

The 24 Enzebra-defined keys (top section of this document) are tracked in `meals_clients` but are NOT in the bidirectional sync map. They were imported once during the initial backfill but going forward, the `meals_clients` columns are authoritative — the wp_usermeta values are not kept in sync. The exception is QuickOrder, which writes `last_order_date` and `last_call_date` directly to wp_usermeta after each order so the legacy call log manager (and the upcoming Phase R2 task engine) can read them.

If a future feature needs bidirectional sync for one of those legacy keys, it must be added explicitly to the sync map AND to the sync hooks; otherwise admin-side edits in `meals_clients` will silently diverge from wp_usermeta.

---

## Sync trigger points

The plugin keeps `meals_clients` in step with WP/WC via three hooks plus a nightly cron:

| Hook | When | What it does |
|---|---|---|
| `profile_update` | Admin/user edits a WP user profile | Pulls WP-side fields into `meals_clients` (only if a govt client record exists for this user) |
| `woocommerce_customer_save_address` | Customer saves billing or shipping address | Pulls address fields into `meals_clients` |
| `woocommerce_created_customer` | New customer registers | Creates skeleton record IF criteria met (Phase S adds this) |
| `mealsdb_nightly_sync` (cron, 2 AM) | Daily | Reconciles all govt client records — useful for catching drift |

The reverse direction (writes to `meals_clients` flowing back to wp_usermeta) happens via the client form save flow in `class-client-form.php`. The form saves to the external DB first, then calls `MealsDB_Sync::push_meals_db_to_wp()` which writes the matching keys back to wp_usermeta.

---

## Key constraints when working with this data

1. **wp_usermeta is unindexed for value lookups.** `meta_value` is a `LONGTEXT` and queries with `LIKE '%needle%'` will table-scan. The plugin already comments on this in `class-sync-query.php` lines 391-417. Any new feature that needs to find users by partial name match should use the `meals_clients` indexed columns instead, not wp_usermeta.

2. **PII fields are encrypted in `meals_clients`.** `first_name`, `last_name`, `requisition_id`, `individual_id`, and `vet_health_card` are all stored encrypted. Each has a corresponding `*_index` column that holds an HMAC for equality lookups (you can find a client by their requisition_id without decrypting the whole table). Use `MealsDB_Encryption::decrypt()` to read; use `MealsDB_Encryption::hmac()` to compute the lookup index.

3. **HPOS is enabled.** WC orders are in `2xnIt_wc_orders` (the new HPOS table), NOT `2xnIt_posts` with `post_type='shop_order'`. Any user-to-orders join must use `2xnIt_wc_orders.customer_id`.

4. **Government client filter.** Several queries filter on `meals_clients.client_type IN ('SDNB', 'Veteran')`. After Phase S ships, sync queries will expand to include `'Private'`, but allocation queries (allowances, contributions) will remain government-only.

5. **wp_user_id linkage.** `meals_clients.wp_user_id` is the primary join key against `2xnIt_users.ID`. There's an index on it. A `wp_user_id` of 0 means "no WP user yet linked" (unusual; should not occur after the migration).

---

## Production data notes (current as of database dump)

- ~558 active government clients in `meals_clients` (482 SDNB + 65 Veteran + handful of misc)
- 909 wp_users have populated `customer_group` meta — most are stale or inactive accounts
- 846 have `mains` and `sides` populated — these are the active govt clients with allowance data
- 1,096 of 1,533 users have a zone in `shipping_address_2`
- 0 of 558 govt clients currently have `delivery_day` populated in `meals_clients` — Phase Q's backfill from zone schedule is required to populate this
- Stripe customer IDs and other payment-related meta exist in subscription/order meta, not user meta

---

## Quick lookup for common operations

**"How do I find a client's phone number?"**  
Read `meals_clients.client_phone_1`. If null, fall back to `wp_usermeta.billing_phone`. Don't make a separate WP call — the sync keeps these aligned.

**"How do I find a client's delivery zone?"**  
Read `meals_clients.delivery_area_name`. The raw value is also in `wp_usermeta.shipping_address_2` but the meals_clients column is the queryable one.

**"How do I find when a client last ordered?"**  
Read `wp_usermeta.last_order_date` for the user. This is updated by QuickOrder's `create_order()` flow with the actual order timestamp. There's no `meals_clients.last_order_date` column — this lives in usermeta only.

**"How do I update a client's allowance?"**  
Update `meals_clients.allowance_mains` and `meals_clients.allowance_sides`. The wp_usermeta keys `mains` and `sides` are not kept in sync going forward — they were imported once.

**"How do I create a new client?"**  
Create the `meals_clients` row with `wp_user_id` set, then call `MealsDB_Sync::push_meals_db_to_wp($client_id)`. This handles encryption, HMAC indexing, and writing the legacy wp_usermeta keys for back-compat.
