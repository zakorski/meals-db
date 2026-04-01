# Phase G: Consolidate Address Fields to Single-String Model

## Goal

Replace the decomposed address columns (`street_number`, `street_name`, `apartment_number`) with a single `street_name` field containing the full address string, matching how the old system stored addresses in `billing_address_1` (e.g. "Apt I - 127 Main St"). The same applies to the `delivery_*` counterparts.

## Why

The old WordPress system stored complete addresses as one string in `billing_address_1`. The new schema decomposed this into `street_number`, `street_name`, and `apartment_number`, but:

1. The old data was never decomposed — `billing_address_1` values like "Apt I - 127 Main St" can't be reliably parsed into parts
2. 557/558 government clients have addresses in this format
3. Staff enter addresses as single strings — forcing decomposition adds friction
4. The invoice generator and delivery slip generator both reconstruct the full string from parts anyway

## Schema changes

**File:** `includes/class-schema.php`

### Remove these columns from the `meals_clients` table definition:

```
'street_number'                => 'VARCHAR(20) NULL',
'apartment_number'             => 'VARCHAR(20) NULL',
'delivery_street_number'       => 'VARCHAR(20) NULL',
'delivery_apartment_number'    => 'VARCHAR(20) NULL',
```

### Keep these columns (they become the single address field):

```
'street_name'                  => 'VARCHAR(255) NULL',
'delivery_street_name'         => 'VARCHAR(255) NULL',
```

### Do NOT run DROP COLUMN

The schema change only affects the PHP definition array. The actual MySQL columns should be left in place temporarily — they may still have data. The backfill (Phase G-backfill below) will consolidate data into `street_name` / `delivery_street_name`, and the columns can be dropped in a later cleanup migration.

---

## Code changes required

### 1. Invoice generator — `includes/services/class-invoice-generator.php`

#### VAC CSV billing address construction (around line 1002–1006):

**Current code:**
```php
$billing_address = '';
if (!empty($vet['apartment_number'])) {
    $billing_address .= $vet['apartment_number'] . ' - ';
}
$billing_address .= ($vet['street_number'] ?? '') . ' ' . ($vet['street_name'] ?? '');
$billing_address  = trim($billing_address);
```

**Replace with:**
```php
$billing_address = trim($vet['street_name'] ?? '');
```

#### VAC SQL SELECT (around line 877):

Remove `apartment_number, street_number,` from the SELECT. Keep `street_name`.

**Current:**
```sql
apartment_number, street_number, street_name, city, postal_code, client_phone_1,
```

**Replace with:**
```sql
street_name, city, postal_code, client_phone_1,
```

### 2. Delivery slip generator — `includes/services/class-delivery-slip-generator.php`

#### SQL SELECT (around line 46–47):

**Current:**
```sql
delivery_area_name, delivery_city, delivery_street_number,
delivery_street_name, delivery_apartment_number
```

**Replace with:**
```sql
delivery_area_name, delivery_city, delivery_street_name
```

#### Address construction (around lines 247–259):

**Current code:**
```php
$address_parts = [];
if (!empty($client['delivery_street_number'])) {
    $address_parts[] = $client['delivery_street_number'];
}
if (!empty($client['delivery_street_name'])) {
    $address_parts[] = $client['delivery_street_name'];
}
$address = implode(' ', $address_parts);
if (!empty($client['delivery_apartment_number'])) {
    $address .= ', Apt ' . $client['delivery_apartment_number'];
}
```

**Replace with:**
```php
$address = trim($client['delivery_street_name'] ?? '');
```

#### Stop sorting metadata (around line 289):

**Current:**
```php
'street_name'   => $client['delivery_street_name'] ?: '',
'street_number' => $client['delivery_street_number'] ?: '',
```

**Replace with:**
```php
'street_name'   => $client['delivery_street_name'] ?: '',
```

#### Sort comparison (around lines 293–300):

**Current:** sorts by `street_name` then `street_number` using `strnatcmp`.

**Replace:** sort by `street_name` only. Remove the `street_number` secondary sort entirely.

```php
usort($zone_stops, function ($a, $b) {
    return strcmp($a['street_name'], $b['street_name']);
});
```

#### Cleanup unset (around line 305):

**Current:**
```php
unset($stop['street_name'], $stop['street_number']);
```

**Replace with:**
```php
unset($stop['street_name']);
```

### 3. Client form — `includes/class-client-form.php`

#### Required fields array (around lines 51–57):

Remove `'address_street_number'` from the required fields list. Remove `'delivery_address_street_number'` from the delivery required fields list.

#### Field labels (around lines 162–168):

Remove entries for `'address_street_number'`, `'delivery_address_street_number'`.

Rename `'address_street_name'` label to `'Address'` and `'delivery_address_street_name'` label to `'Delivery Address'`.

#### DB-to-form name mapping (around lines 822–830):

Remove mappings for `street_number` → `address_street_number` and `delivery_street_number` → `delivery_address_street_number`.

Remove mappings for `apartment_number` → `address_unit` and `delivery_apartment_number` → `delivery_address_unit`.

#### Form-to-DB name mapping (around lines 869–877):

Remove the reverse mappings for these same fields.

### 4. Admin UI — `includes/class-admin-ui.php`

Remove the Street # input fields (around lines 980–981 and 1032–1033).
Remove the Apartment/Unit fields if they exist.
The Street Name field becomes "Address" — a single text input.

### 5. Sync — `includes/class-sync.php`

Remove `'street_number'`, `'apartment_number'`, `'delivery_street_number'`, `'delivery_apartment_number'` from the sync field lists (around lines 32–40).

Remove the corresponding meta key mappings (around lines 134–139).

### 6. Initials validator — `includes/class-initials-validator.php`

Simplify the address extraction logic (around lines 229–256). Instead of checking 6 different field names for street_number, just use `delivery_street_name` or `street_name` directly since they now contain the full address.

### 7. Migration — `includes/services/class-migration.php`

The migration already writes `billing_address_1` to `street_name` (the fallback in the existing code). Remove the `$street_number` and `$apt_number` variables and their INSERT column/value references. Just write `billing_address_1` → `street_name` and `shipping_address_1` → `delivery_street_name`.

### 8. JavaScript files

- `assets/js/client-initials.js` — remove references to `address_street_number` and `delivery_address_street_number`
- `assets/js/client-type-logic.js` — remove `findSectionForField('#address_street_number')` reference

### 9. Tests — `tests/test-client-form.php`

Update all test data arrays to remove `address_street_number` / `delivery_address_street_number` fields and consolidate addresses into the street name field.

---

## Key constraints

- External DB queries use `MealsDB_DB::get_connection()` (mysqli), WordPress queries use `$wpdb`
- Table names via `MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS)`
- Do NOT drop the old columns from MySQL — just stop reading/writing them. They'll be cleaned up later.
- The `street_name` column is `VARCHAR(255) NULL` — this is sufficient for full addresses like "Apt I - 127 Main St"
