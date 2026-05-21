# Phase H: Backfill Remaining Migration Gaps

## Goal

One-time backfill that fixes all remaining data gaps from the initial migration. Run AFTER Phase G (address consolidation) is merged.

## What this backfill fixes

1. **`delivery_area_name`** — Zone data ("Zone 1", "Zone 3", etc.) is stuck in `apartment_number` because the migration wrongly mapped `billing_address_2` there. Move it to `delivery_area_name`.
2. **`street_name`** — Ensure the full `billing_address_1` string is written here (not decomposed).
3. **`delivery_street_name`** — Same for `shipping_address_1`.
4. **`default_rate_id`** — Link to the default rate for clients where `create_rates()` created a rate row but didn't link it.
5. **`apartment_number` cleanup** — Clear zone data from `apartment_number` and `delivery_apartment_number` (these columns will be dropped later).

## What will remain blank after this backfill (no source data exists)

| Column | Why | Impact |
|---|---|---|
| `delivery_day` | 0/558 old users had this | Delivery slips won't generate until staff enters manually |
| `required_start_date` | Never tracked | Client form display only |
| `initial_renewal_termination_date` | Never tracked | Client form and sync only |
| `most_recent_renewal_termination_date` | Never tracked | Client form and sync only |
| `sdnb_service_request_id` | New portal field | Only needed when `use_legacy_billing = 0` |

---

## Create the backfill class

**File:** `includes/services/class-backfill-addresses.php`

### Logic per client (pseudocode):

```
For each meals_clients row WHERE wp_user_id > 0:

  1. Read from WordPress usermeta:
     - billing_address_1
     - billing_address_2  (zone data)
     - shipping_address_1
     - shipping_address_2  (zone data)

  2. If delivery_area_name is empty AND billing_address_2 has a value:
     → SET delivery_area_name = billing_address_2

  3. If apartment_number starts with "Zone":
     → SET apartment_number = NULL

  4. If delivery_apartment_number starts with "Zone":
     → SET delivery_apartment_number = NULL

  5. If street_name is empty OR street_name != billing_address_1:
     → SET street_name = billing_address_1
     → SET street_number = NULL

  6. If shipping_address_1 is not empty AND delivery_street_name != shipping_address_1:
     → SET delivery_street_name = shipping_address_1
     → SET delivery_street_number = NULL

  7. If default_rate_id is empty:
     → Query meals_client_rates WHERE client_id = ? AND is_default = 1 LIMIT 1
     → If found: SET default_rate_id = rate_id
```

### Data sources

| Target column | Source | WordPress meta key |
|---|---|---|
| `delivery_area_name` | wp_usermeta | `billing_address_2` |
| `street_name` | wp_usermeta | `billing_address_1` |
| `delivery_street_name` | wp_usermeta | `shipping_address_1` |
| `default_rate_id` | meals_client_rates | `is_default = 1` |

### Database access patterns

- WordPress usermeta: `$GLOBALS['wpdb']->get_results()` with parameterized query
- meals_clients reads/writes: `MealsDB_DB::get_connection()` (returns `mysqli`)
- Table names: `MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS)` and `MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES)`

### Dry-run support

The class must accept a `$dry_run` parameter (default `true`). In dry-run mode, log what would change but don't write. Return stats array with counts: `total`, `zones_fixed`, `addresses_fixed`, `rates_linked`, `skipped`, `errors`.

---

## Wire into AJAX handler

**File:** `includes/ajax/class-ajax-migration.php`

Add action: `wp_ajax_mealsdb_backfill_addresses` → handler method.

Handler:
- Check nonce (`mealsdb_nonce`) and `manage_options` capability
- Read `$_POST['dry_run']` flag
- `require_once` the backfill class (not autoloaded)
- Call `MealsDB_Backfill_Addresses::run($dry_run)`
- Return stats via `wp_send_json_success()`

---

## Wire into admin UI

Add a "Backfill Addresses & Rates" card to the migration admin page, following the same pattern as the allowance backfill button. Two buttons: "Dry Run" and "Run Backfill" (disabled until dry run completes). Results div shows stats.

---

## Fix the migration for future runs

**File:** `includes/services/class-migration.php`

### Fix 1: Remove billing_address_2 → apartment_number fallback

Around line 933:
```php
// BEFORE:
$apt_number = $meta['mealsdb_apartment_number'] ?? $meta['billing_address_2'] ?? null;
// AFTER:
$apt_number = $meta['mealsdb_apartment_number'] ?? null;
```

### Fix 2: Same for shipping_address_2

Around line 941:
```php
// BEFORE:
$del_apt_number = $meta['mealsdb_delivery_apartment_number'] ?? $meta['shipping_address_2'] ?? null;
// AFTER:
$del_apt_number = $meta['mealsdb_delivery_apartment_number'] ?? null;
```

### Fix 3: Add delivery_area_name to INSERT

After the line where `$zone` is computed (around line 862), add:
```php
$delivery_area_name = $meta['billing_address_2'] ?? null;
```

Add `delivery_area_name` to the INSERT column list and bind it as type `s`.

---

## Key constraints

- External DB via `MealsDB_DB::get_connection()` — NOT `$wpdb`
- WordPress usermeta via `$GLOBALS['wpdb']`
- Table names via `MealsDB_DB::get_table_name()`
- Column names exactly: `apartment_number`, `delivery_apartment_number`, `street_number`, `street_name`, `delivery_street_number`, `delivery_street_name`, `delivery_area_name`, `default_rate_id`
- WordPress meta keys exactly: `billing_address_1`, `billing_address_2`, `shipping_address_1`, `shipping_address_2`
- Do NOT create new tables or columns
- The `require_once` for the new class goes in the AJAX handler, not the autoloader
