# Phase 1 — Schema Changes

## Objective

Remove `meals_transactions` and `meals_transaction_items` from the canonical schema. Add the new `meals_client_rates` table. Update `meals_clients` to reference rates via `default_rate_id` instead of a flat `rate` decimal. Update all constants, schema definitions, installer, and uninstaller.

---

## Step 1 — Update `includes/class-tables.php`

### 1.1 Remove defunct constants
- Remove `public const TRANSACTIONS = 'meals_transactions';`
- Remove `public const TRANSACTION_ITEMS = 'meals_transaction_items';`

### 1.2 Add new constant
- Add `public const CLIENT_RATES = 'meals_client_rates';`

### 1.3 Update `all()` method
- Remove `self::TRANSACTIONS` from the returned array
- Remove `self::TRANSACTION_ITEMS` from the returned array
- Add `self::CLIENT_RATES` to the returned array

---

## Step 2 — Update `includes/class-schema.php`

### 2.1 Remove transaction table schema definitions
- Remove the entire `MealsDB_Tables::TRANSACTIONS` entry from the array returned by `get_canonical_schema()`
- Remove the entire `MealsDB_Tables::TRANSACTION_ITEMS` entry from the array returned by `get_canonical_schema()`

### 2.2 Add `meals_client_rates` schema definition
Add the following entry to the array returned by `get_canonical_schema()`, keyed as `MealsDB_Tables::CLIENT_RATES`:

```
table:   MealsDB_Tables::CLIENT_RATES
engine:  InnoDB
columns:
  rate_id        INT UNSIGNED NOT NULL AUTO_INCREMENT
  client_id      BIGINT(20) UNSIGNED NOT NULL          — FK to meals_clients.client_id
  label          VARCHAR(100) NOT NULL                  — e.g. "Standard", "Subsidized"
  rate           DECIMAL(10,2) NOT NULL DEFAULT 0.00
  is_default     TINYINT(1) NOT NULL DEFAULT 0
  effective_date DATE NULL
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
primary_key: [rate_id]
indexes:
  - name: idx_client_id, type: INDEX, columns: [client_id]
  - name: idx_is_default, type: INDEX, columns: [client_id, is_default]
foreign_keys (metadata only — not executed by sync):
  - name: fk_client_rates_client
    columns: [client_id]
    referenced_table: MealsDB_Tables::CLIENTS
    referenced_columns: [client_id]
    on_delete: CASCADE
```

### 2.3 Modify `meals_clients` schema definition
- Remove the `'rate' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00'` column entry
- Add `'default_rate_id' => 'BIGINT(20) UNSIGNED NULL'` column entry after `use_legacy_billing`

---

## Step 3 — Update `includes/install-schema.php` (`MealsDB_Installer` class)

### 3.1 Remove transaction table creation
- Remove any direct `CREATE TABLE` calls or helper method calls that create `meals_transactions` or `meals_transaction_items`
- Remove the `create_table_transactions()` method if it exists as a standalone method
- Remove the `create_table_transaction_items()` method if it exists as a standalone method
- Remove the `alter_transactions_add_status()` method if it exists

### 3.2 Remove the `migrate_service_name_course_to_meal_type` one-time migration
- This migration has already run in production. Remove the method body and its call from `install()` to keep the installer clean. Replace with a no-op comment: `// Migration: service_name_course → meal_type completed in v1.x`

### 3.3 Add `meals_client_rates` table creation
- The installer already loops over `MealsDB_Schema::get_canonical_schema()` and calls `generate_create_table_sql()` for each. Because `meals_client_rates` is now in the canonical schema (Step 2.2), no additional installer code is needed — verify the loop covers all canonical tables.

### 3.4 Add a one-time migration: seed `meals_client_rates` from existing `meals_clients.rate`
Add a new private method `migrate_rate_to_client_rates(mysqli $conn)` and call it from `install()` after the schema loop:

```
- Check if meals_client_rates table is empty (COUNT(*) = 0)
- If empty AND meals_clients.rate column still exists:
    INSERT INTO meals_client_rates (client_id, label, rate, is_default, created_at)
    SELECT client_id, 'Standard', rate, 1, NOW()
    FROM meals_clients
    WHERE rate > 0
- After insert, UPDATE meals_clients SET default_rate_id = (
    SELECT rate_id FROM meals_client_rates
    WHERE meals_client_rates.client_id = meals_clients.client_id
    AND is_default = 1 LIMIT 1
  )
- Then check if meals_clients.rate column still exists via INFORMATION_SCHEMA
- If it exists, ALTER TABLE meals_clients DROP COLUMN rate
```

### 3.5 Add a one-time migration: drop `meals_transactions` and `meals_transaction_items`
Add a new private method `drop_defunct_transaction_tables(mysqli $conn)` and call it from `install()`:

```
- DROP TABLE IF EXISTS meals_transaction_items
- DROP TABLE IF EXISTS meals_transactions
- Log each drop with error_log('[MealsDB Installer] Dropped defunct table: ...')
```

---

## Step 4 — Update `uninstall.php`

### 4.1 Remove transaction table drops
- Remove `DROP TABLE IF EXISTS meals_transactions`
- Remove `DROP TABLE IF EXISTS meals_transaction_items`

### 4.2 Add `meals_client_rates` drop
- Add `DROP TABLE IF EXISTS meals_client_rates`
- Ensure drop order respects FK dependency: drop `meals_client_rates` before `meals_clients`

---

## Step 5 — Update `includes/class-schema-sync.php`

### 5.1 Verify sync scope
- `MealsDB_Schema_Sync::run_full_sync()` currently only targets `meals_clients`. Confirm it does not attempt to sync `meals_transactions` or `meals_transaction_items`.
- If any reference to `MealsDB_Tables::TRANSACTIONS` or `MealsDB_Tables::TRANSACTION_ITEMS` exists in this file, remove it.

---

## Verification Checklist

- `MealsDB_Tables::all()` returns exactly: `meals_clients`, `meals_products`, `meals_client_rates`, `meals_staff`, `meals_drafts`, `meals_audit_log`, `meals_ignored_conflicts`
- `MealsDB_Schema::get_canonical_schema()` contains no `meals_transactions` or `meals_transaction_items` entries
- `meals_client_rates` schema is present in canonical schema with correct columns, PK, and indexes
- `meals_clients` schema no longer contains `rate` column; contains `default_rate_id` column
- Installer seeds `meals_client_rates` from existing `meals_clients.rate` data on first run
- Installer drops `meals_transactions` and `meals_transaction_items` on first run
- Uninstaller drops `meals_client_rates` before `meals_clients`
- No PHP errors when schema loop runs against the updated canonical schema
