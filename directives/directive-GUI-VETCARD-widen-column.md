# Directive GUI-VETCARD (SURGICAL, v446) — Widen vet_health_card so encrypted values fit

## HOW TO EXECUTE — READ FIRST
- This is **3 edits** across 2 files. Do exactly these 3. For each: `read` the file, find the EXACT
  verbatim FIND, apply. Do NOT regenerate any method or array from memory. If a FIND doesn't match
  verbatim, STOP and report.

**Why:** `vet_health_card` is an ENCRYPTED field (AES-256-CBC, stored as
`base64(hmac . iv . ciphertext)` — ~90+ chars even for a short input). Its column is **VARCHAR(50)**,
far too small, so EVERY value overflows the column at `$wpdb->insert` → rejected → surfaced as
"the value for 'Veteran Health Identification Card #' is invalid or too long." This blocks ALL
Veteran client creation (confirmed: existing Veterans have empty vet_health_card — it has never been
able to store a value). Its sibling encrypted fields `individual_id` and `requisition_id` are
correctly **VARCHAR(500)**; vet_health_card was left at 50 by oversight. Fix: widen it to VARCHAR(500)
to match, in BOTH the schema definition (fresh installs) AND a one-time migration (existing installs).

**Important:** `MealsDB_Schema_Sync::run_full_sync()` only auto-applies ADD COLUMN for MISSING
columns; for an existing column with a wrong TYPE it only RECORDS a mismatch (it does not MODIFY).
So changing the schema definition alone does NOT fix existing installs — the explicit migration
(EDIT 3) is required.

---

## EDIT 1 — widen the column in the schema definition (fixes fresh installs)
**File:** `includes/class-schema.php`
**FIND (verbatim, 1 line):**
```
                    'vet_health_card'              => 'VARCHAR(50) NULL',
```
**REPLACE WITH:**
```
                    'vet_health_card'              => 'VARCHAR(500) NULL',
```
(Now matches `individual_id` / `requisition_id`, the other encrypted VARCHAR(500) fields.)

---

## EDIT 2 — call a new one-time migration from install()
**File:** `includes/install-schema.php`
**FIND (verbatim, 3 lines):**
```
        // Run one-time migrations
        self::migrate_rate_to_client_rates();
        self::drop_defunct_transaction_tables();
```
**REPLACE WITH:**
```
        // Run one-time migrations
        self::migrate_rate_to_client_rates();
        self::widen_vet_health_card_column();
        self::drop_defunct_transaction_tables();
```

---

## EDIT 3 — add the migration method (guarded, idempotent)
**File:** `includes/install-schema.php`
**FIND (verbatim — the start of the existing migration method, to insert BEFORE it):**
```
    private static function migrate_rate_to_client_rates(): void {
        global $wpdb;
```
**INSERT IMMEDIATELY BEFORE that line:**
```
    /**
     * One-time: widen meals_clients.vet_health_card from VARCHAR(50) to VARCHAR(500).
     *
     * vet_health_card is AES-256 encrypted (base64(hmac.iv.ciphertext), ~90+ chars),
     * so VARCHAR(50) overflows for every value and blocked all Veteran creates. Its
     * sibling encrypted fields (individual_id, requisition_id) are already VARCHAR(500);
     * this brings vet_health_card in line. Idempotent: only ALTERs when the live column
     * is still narrower than 500. Safe to run on every install/upgrade.
     */
    private static function widen_vet_health_card_column(): void {
        global $wpdb;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // Read the live column type; only act if it's not already VARCHAR(500)+.
        $col = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT CHARACTER_MAXIMUM_LENGTH AS len
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = 'vet_health_card'",
                $clients_table
            ),
            ARRAY_A
        );

        // If we can't read it, or it's already wide enough, do nothing.
        if (!is_array($col) || !isset($col['len'])) {
            return;
        }
        if ((int) $col['len'] >= 500) {
            return;
        }

        $alter_sql = "ALTER TABLE `{$clients_table}` MODIFY COLUMN `vet_health_card` VARCHAR(500) NULL";
        if ($wpdb->query($alter_sql) === false) {
            error_log('[MealsDB Installer] Failed to widen vet_health_card column: ' . $wpdb->last_error);
        }
    }

```
**NOTE:** uses `MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS)` exactly as the neighboring
migration does — do not hardcode the table name. The information_schema check makes it idempotent
(re-running install/upgrade won't re-ALTER an already-wide column).

---

## VERIFICATION
```bash
cd <plugin-root>
grep -n "vet_health_card" includes/class-schema.php | grep VARCHAR        # expect VARCHAR(500)
grep -n "widen_vet_health_card_column" includes/install-schema.php        # expect 2 hits (call + def)
php tests/test-*.php   # expect green
```
**Manual (the real check) on staging AFTER the upgrade runs:**
- Trigger the schema upgrade (re-activate the plugin, or run the Data Ops schema sync / installer path
  that calls `install()`), so `widen_vet_health_card_column()` executes.
- In Adminer/DB: confirm `meals_clients.vet_health_card` is now `varchar(500)`.
- Create a NEW Veteran client with a health card value (e.g. `123456789`) → it must SAVE (no
  "invalid or too long"), and the value round-trips (reopen the client, the card shows decrypted).
- A short value and a longer value both save (confirms it's no longer length-bound at 50).

## DO NOT
- Do not change individual_id / requisition_id (already VARCHAR(500)).
- Do not remove vet_health_card from the encrypted-fields set or its index — it stays encrypted with
  its deterministic index; this only widens the storage column.
- Do not rely on run_full_sync to apply the type change — it won't MODIFY an existing column; the
  explicit migration (EDIT 3) is required for existing installs.
- Do not hardcode the table name or the prefix — use the helper as shown.
