# Directive: Fix `backfill_deterministic_indexes` Double Bug

**Severity:** CRITICAL by classification, LOW operational impact (CRIT-4)
**Audit reference:** `recon-06-migration-encryption.md`; `recon-07-admin-ui.md` lines 660-672; `recon-09-synthesis.md` CRIT-4
**Target file:** `includes/class-client-form.php`
**Estimated scope:** ~30-40 lines in one method
**Risk:** LOW — the function is currently broken (effectively dead). Any fix is an improvement.
**Must complete before:** any future schema migration that adds a deterministic index column to an existing install

---

## Context

The method `MealsDB_Client_Form::backfill_deterministic_indexes()` (approximately lines 1703-1755) has **two compounding bugs**:

### Bug 1: Wrong primary key column name

The UPDATE statement uses `WHERE id = %d`, but the `meals_clients` table's primary key is `client_id`, not `id`. Every UPDATE fails with "Unknown column 'id'".

### Bug 2: Hashes ciphertext instead of plaintext for encrypted columns

The method targets four columns: `individual_id`, `requisition_id`, `vet_health_card`, `delivery_initials`. Three of these (the first three) are encrypted at rest. For these, the SELECT returns ciphertext. The code then computes `deterministic_hash($value)` directly on the ciphertext.

But: future uniqueness queries (in `MealsDB_Client_Form::check_unique_fields()`) hash the **plaintext** input from the form. The plaintext hash and the ciphertext hash will never match. Even if Bug 1 were fixed, this would produce a corrupt index for the three encrypted columns.

For `delivery_initials` (plaintext column), the existing logic is correct.

### Why this hasn't fired in production

The `MealsDB_Client_Form::ensure_index_columns_exist()` method calls this backfill only when index columns are detected as newly added. In the current production install, all index columns were created at install time and populated by the regular `save()`/`update()` paths using plaintext. **The backfill has never actually executed against rows with values.**

But it WILL execute the next time the dev adds a new deterministic index column to an existing install (any v1.x → v1.y upgrade that adds an index column). And when it does, it will fail per-row and leave `indexes_ensured = false`, causing every subsequent request to retry the same broken backfill.

---

## Pre-flight verification

### Step P1: Locate the method

Open `includes/class-client-form.php` and find `backfill_deterministic_indexes`. Read the surrounding methods:
- `ensure_index_columns_exist` (caller)
- `deterministic_hash` (helper)
- `$deterministic_index_map` (static property)
- `MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS` (canonical encrypted list)

Confirm the bugs are still present in the current codebase:

```bash
grep -n "WHERE.*id.*= %d" includes/class-client-form.php
grep -n "backfill_deterministic_indexes" includes/class-client-form.php
```

If `WHERE client_id = %d` is already in place, the column-name bug has been fixed. STOP and verify what other parts of this directive still apply.

### Step P2: Confirm `meals_clients` primary key

```bash
wp db query "SHOW CREATE TABLE 2xnIt_meals_clients" | grep -i "PRIMARY KEY"
```

The PRIMARY KEY must be `(client_id)`. Confirm in your response.

### Step P3: Confirm the encrypted column list

Open `includes/class-encryption.php` and find `ENCRYPTED_CLIENT_COLUMNS`. Verify it contains at minimum:
- `individual_id`
- `requisition_id`
- `vet_health_card`
- `diet_concerns`
- `customer_comments`

The first three are the deterministic-index targets. `diet_concerns` and `customer_comments` are encrypted but NOT in the index map (they're free-text). Confirm this matches.

### Step P4: Confirm `$deterministic_index_map`

Open `includes/class-client-form.php` and find the static `$deterministic_index_map`. It should map:
- `individual_id` → `individual_id_index`
- `requisition_id` → `requisition_id_index`
- `vet_health_card` → `vet_health_card_index`
- `delivery_initials` → `delivery_initials_index`

If the map differs, the fix below needs adjustment. Report any discrepancies.

### Step P5: Read the current implementation

Read the full body of `backfill_deterministic_indexes` and document its current logic in your response. Note:
- Whether there's already a `class_exists('MealsDB_Encryption')` guard.
- How errors are reported (return false? log and continue?).
- Whether `MealsDB_Encryption::safe_decrypt` is used anywhere similar.

---

## The fix

### Step F1: Identify which columns need plaintext vs ciphertext handling

In the loop body, distinguish columns that need decryption from those that don't:

```php
$encrypted_columns = class_exists('MealsDB_Encryption')
    ? MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS
    : [];
```

For each `$field => $index_column` pair in `self::$deterministic_index_map`:
- If `in_array($field, $encrypted_columns, true)`, the column value needs `safe_decrypt` before hashing.
- Otherwise, hash the value directly.

### Step F2: Rewrite the method

Replace the entire body. The new version:

```php
private static function backfill_deterministic_indexes(): bool {
    global $wpdb;
    $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
    $all_successful = true;

    $encrypted_columns = class_exists('MealsDB_Encryption')
        ? MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS
        : [];

    foreach (self::$deterministic_index_map as $field => $index_column) {

        // Defensive: confirm both columns exist before querying.
        // ensure_index_columns_exist should have created the index
        // column, but if dbDelta failed silently we'd UPDATE a
        // nonexistent column otherwise.
        if (!MealsDB_DB::table_has_column($table, $field) ||
            !MealsDB_DB::table_has_column($table, $index_column)) {
            continue;
        }

        // Select rows where the index is null/empty but the source
        // field has a value. The result is the candidate set for
        // backfill — we never re-hash already-populated indexes.
        $sql = $wpdb->prepare(
            "SELECT client_id, `{$field}` AS source_value
             FROM `{$table}`
             WHERE (`{$index_column}` IS NULL OR `{$index_column}` = '')
               AND `{$field}` IS NOT NULL
               AND `{$field}` != ''",
            // No placeholder values — column names are escaped in
            // backticks above. The %d/%s would mismatch %1$s style
            // because we're interpolating column names, which can't
            // be parameterised in $wpdb->prepare. Backticks around
            // whitelisted column names are safe.
        );

        // Strip the prepare result back to plain SQL since we used
        // no placeholder values. (prepare returns the SQL unchanged
        // in this case but emits an E_USER_NOTICE in newer WP.)
        // Alternative: run $wpdb->get_results directly without prepare.
        $rows = $wpdb->get_results(
            "SELECT client_id, `{$field}` AS source_value
             FROM `{$table}`
             WHERE (`{$index_column}` IS NULL OR `{$index_column}` = '')
               AND `{$field}` IS NOT NULL
               AND `{$field}` != ''",
            ARRAY_A
        );

        if ($rows === null) {
            // Query error — log and mark as failed.
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::error(
                    '[MealsDB Index Backfill] SELECT failed for field ' . $field
                    . ': ' . $wpdb->last_error
                );
            }
            $all_successful = false;
            continue;
        }

        if (empty($rows)) {
            // Nothing to backfill for this column. Not a failure.
            continue;
        }

        foreach ($rows as $row) {
            $client_id = (int) $row['client_id'];
            $source_value = (string) $row['source_value'];

            // CRITICAL: for encrypted columns, the SELECT returns
            // ciphertext. We must decrypt before hashing — future
            // uniqueness queries (check_unique_fields) hash the
            // plaintext input from the form. Hashing ciphertext
            // here would create indexes that never match.
            //
            // For plaintext columns (delivery_initials), we hash
            // the value directly.
            if (in_array($field, $encrypted_columns, true)) {
                if (!class_exists('MealsDB_Encryption')) {
                    // Cannot decrypt without the encryption class.
                    // Skip — better to leave the index empty than
                    // to write a wrong hash.
                    $all_successful = false;
                    continue;
                }
                $plaintext = MealsDB_Encryption::safe_decrypt($source_value);
                // safe_decrypt returns the input unchanged on failure.
                // If it returns the source value (likely ciphertext
                // we couldn't decrypt), skip rather than write a
                // wrong hash.
                if ($plaintext === $source_value) {
                    // Decrypt didn't help — either ciphertext is
                    // corrupted or the key is wrong. Either way,
                    // we can't index this row. Log and skip.
                    if (class_exists('MealsDB_Logger')) {
                        MealsDB_Logger::error(
                            '[MealsDB Index Backfill] Could not decrypt '
                            . $field . ' for client_id=' . $client_id
                            . '; index row skipped'
                        );
                    }
                    $all_successful = false;
                    continue;
                }
                $hash = self::deterministic_hash($plaintext);
            } else {
                $hash = self::deterministic_hash($source_value);
            }

            // Write the hash. CRITICAL: WHERE client_id, NOT WHERE id.
            // meals_clients.client_id is the primary key column;
            // there is no 'id' column on this table. The previous
            // implementation used WHERE id = %d which threw
            // "Unknown column 'id'" on every UPDATE.
            $update_result = $wpdb->update(
                $table,
                [$index_column => $hash],
                ['client_id' => $client_id],
                ['%s'],
                ['%d']
            );

            if ($update_result === false) {
                if (class_exists('MealsDB_Logger')) {
                    MealsDB_Logger::error(
                        '[MealsDB Index Backfill] UPDATE failed for '
                        . $field . ' / client_id=' . $client_id
                        . ': ' . $wpdb->last_error
                    );
                }
                $all_successful = false;
            }
        }
    }

    return $all_successful;
}
```

### Step F3: Add a docblock

Above the method:

```php
/**
 * Populate empty deterministic index columns by hashing existing data.
 *
 * Runs once per request after ensure_index_columns_exist() detects a
 * newly added index column. The normal save()/update() paths populate
 * indexes from plaintext input; this backfill exists for the edge
 * case where an index column is added to a table that already has
 * data.
 *
 * BUG HISTORY: A previous implementation had two compounding bugs:
 *   1. Used WHERE id = %d, but the primary key is client_id. Every
 *      UPDATE failed with "Unknown column 'id'".
 *   2. Hashed ciphertext for encrypted columns. Future uniqueness
 *      queries hash plaintext, so the indexes never matched. The
 *      backfill was effectively dead — never executed against real
 *      data because the schema created index columns at install time.
 *      But any future schema change adding a new index column would
 *      have triggered the bug.
 *
 * The fix decrypts encrypted columns before hashing and uses
 * client_id as the WHERE column.
 *
 * @return bool True if all backfills succeeded. False if any row
 *              failed (corrupted ciphertext, query error, etc.).
 *              Failed rows are logged via MealsDB_Logger::error.
 */
```

### Step F4: Verify the helper `MealsDB_DB::table_has_column` exists

The new method calls `MealsDB_DB::table_has_column($table, $column)`. Confirm this method exists:

```bash
grep -n "function table_has_column" includes/class-db.php
```

If it doesn't exist or has a different signature, adapt the fix. Don't add a new method to `class-db.php` in this directive — find the existing equivalent or use raw `INFORMATION_SCHEMA` queries.

If `table_has_column` doesn't exist:

```php
// Inline equivalent if the helper isn't available:
$columns_query = $wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = %s
       AND COLUMN_NAME IN (%s, %s)",
    $table,
    $field,
    $index_column
);
$found_columns = $wpdb->get_col($columns_query);
if (count($found_columns) !== 2) {
    continue;
}
```

---

## Testing

### Step T1: Static check

`php -l includes/class-client-form.php` must pass.

### Step T2: Manual verification

Since the method is normally only triggered after a schema change adds an index column, manual testing requires creating that condition. Do NOT do this on production.

In your response, include:

> **Manual verification on staging (NOT production):**
> 1. Pick a client with an `individual_id` value (must be encrypted in the production schema).
> 2. Manually NULL their `individual_id_index` column:
>    `wp db query "UPDATE 2xnIt_meals_clients SET individual_id_index = NULL WHERE client_id = <ID>"`
> 3. Reset the `$indexes_ensured` static (requires a class reload — easiest is to test in a fresh request).
> 4. Trigger any AJAX call that goes through `ensure_index_columns_exist` (e.g. save a different client).
> 5. Verify the `individual_id_index` for the NULLed client is now populated.
> 6. Verify the hash matches `MealsDB_Encryption::create_index($plaintext_individual_id)` for the same client.
> 7. Restore the original `individual_id_index` or re-run the backfill globally.

### Step T3: Confirm idempotency

The backfill should be safe to run multiple times. Verify by:
1. Run the backfill (artificially trigger it).
2. Run it again immediately.
3. The second run should produce zero UPDATEs because the index columns are now non-empty.

---

## Out of scope for this directive

- Do NOT change `deterministic_hash`. It's the same hash function used elsewhere; changing it would invalidate all existing indexes.
- Do NOT change `check_unique_fields` (the consumer of these indexes). The fix is in the writer, not the reader.
- Do NOT add a new "backfill all index columns" admin button. The automatic trigger via `ensure_index_columns_exist` is sufficient.
- Do NOT modify `ensure_index_columns_exist`. It's the caller; only the callee is broken.

---

## Acceptance criteria

The directive is complete when:

1. ✅ Pre-flight steps P1-P5 are complete.
2. ✅ The method uses `WHERE client_id = %d`, not `WHERE id = %d`.
3. ✅ The method decrypts encrypted columns before hashing.
4. ✅ The method skips (and logs) rows where decryption fails.
5. ✅ The bug history docblock is added.
6. ✅ `php -l` passes.
7. ✅ Manual verification instructions are included in the response.
8. ✅ The method is confirmed idempotent (re-running produces zero UPDATEs).

When complete, your final response should include:
- The full diff of the change.
- Confirmation of all pre-flight checks.
- The manual verification instructions.
- A note on whether `MealsDB_DB::table_has_column` existed or required workaround.
