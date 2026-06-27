# Directive: Harden Form-side vs DB-side Column Name Boundary

**Severity:** MEDIUM structural (STRUCT-1 from synthesis)
**Audit reference:** `recon-09-synthesis.md` STRUCT-1
**Target file:** `includes/services/class-clients-repository.php` (plus optional changes to consumers)
**Estimated scope:** ~40-60 lines added to the repository
**Risk:** LOW — adds logging without changing existing behavior
**Must complete before:** any v1.1+ work that touches the repository

---

## Context

The plugin maintains two parallel column name vocabularies:
- **Form-side** (used in UI, `$_POST` keys): `wordpress_user_id`, `phone_primary`, `address_postal`, etc.
- **DB-side** (canonical schema): `wp_user_id`, `client_phone_1`, `postal_code`, etc.

There are ~20 such pairs. `MealsDB_Client_Form::load_client` maps DB → form (for edit views) and `MealsDB_Client_Form::map_form_to_db` maps form → DB (for save paths).

The problem: `MealsDB_Clients_Repository::filter_to_known_columns` validates incoming column names against the canonical schema (DB-side) and **silently drops unknown keys**. When a caller uses form-side names where DB-side is expected:
- The unknown key is dropped.
- The update appears successful (zero rows affected is technically success).
- The change disappears.
- The audit log captures the intended change as if it happened.

**Confirmed instances**:
- CRIT-2: `MealsDB_Ajax_Clients::link_client_to_wp_user` writes `wordpress_user_id` instead of `wp_user_id`. Already fixed in directive 2.

**Potential future instances**: any new code path that writes to the repository directly. The silent-drop behavior is a footgun.

This directive **does NOT rename anything**. It adds defensive logging so future instances are detected immediately rather than silently breaking.

---

## Pre-flight verification

### Step P1: Locate `filter_to_known_columns`

```bash
grep -n "filter_to_known_columns\|function filter_to_known" includes/services/class-clients-repository.php
```

Read the method body. Understand:
- How it determines what columns are "known".
- What it does with unknown columns (drops them, presumably without logging).
- What it returns (the filtered array).

### Step P2: Find all callers of `filter_to_known_columns`

```bash
grep -rn "filter_to_known_columns" includes/ --include="*.php"
```

Most likely callers: `create_client`, `update_client`. These methods accept arbitrary arrays from caller code and need to filter to schema-known columns.

Document the call sites in your response.

### Step P3: Check for an existing logging pattern in the repository

```bash
grep -n "MealsDB_Logger::error\|error_log" includes/services/class-clients-repository.php
```

If the repository already uses `MealsDB_Logger::error` for some conditions, follow that pattern. If not, decide on the cleanest integration.

---

## The fix

### Step F1: Add warning log when unknown keys are dropped

Modify `filter_to_known_columns` to log unknown keys instead of silently dropping. The change is additive — existing behavior (returning the filtered array) is preserved.

Before (illustrative):
```php
private static function filter_to_known_columns(array $data): array {
    $schema = MealsDB_Schema::get_table_schema(MealsDB_Tables::CLIENTS);
    $known = array_keys($schema['columns']);
    return array_intersect_key($data, array_flip($known));
}
```

After:
```php
/**
 * Filter an associative array to only contain keys that match
 * known schema columns on meals_clients.
 *
 * Unknown keys are dropped, but a warning is logged so the
 * caller can detect bugs where form-side column names (e.g.
 * `wordpress_user_id`) are passed where DB-side names (`wp_user_id`)
 * are expected. Silent drop has been a recurring bug class —
 * see CRIT-2 in the audit (link_client_to_wp_user wrote
 * 'wordpress_user_id', got silently dropped, returned success
 * while doing nothing).
 *
 * @param array $data Caller-supplied array of column => value.
 * @return array Filtered to known columns only.
 */
private static function filter_to_known_columns(array $data): array {
    $schema = MealsDB_Schema::get_table_schema(MealsDB_Tables::CLIENTS);
    $known = array_keys($schema['columns']);

    $known_keys = [];
    $unknown_keys = [];

    foreach ($data as $key => $value) {
        if (in_array($key, $known, true)) {
            $known_keys[$key] = $value;
        } else {
            $unknown_keys[] = $key;
        }
    }

    if (!empty($unknown_keys) && class_exists('MealsDB_Logger')) {
        // Log at error level — these are bugs, not warnings. A
        // caller writing unknown column names is silently losing
        // data. We log the column names but not the values
        // (values may be PII).
        //
        // Include a stack trace fragment so the call site is
        // identifiable even in production logs. Limited to top 3
        // frames to avoid log spam.
        $caller_info = self::get_caller_info(3);

        MealsDB_Logger::error(sprintf(
            '[MealsDB Repository] filter_to_known_columns dropped unknown column(s): %s. Called from: %s',
            implode(', ', $unknown_keys),
            $caller_info
        ));
    }

    return $known_keys;
}

/**
 * Get a compact caller chain for logging.
 *
 * Returns up to $depth frames of the call stack in the form:
 *   "ClassName::method (file:line) <- ClassName::method (file:line)"
 *
 * Excludes the immediate caller (this helper itself) and the
 * filter_to_known_columns frame.
 *
 * @param int $depth Max frames to include.
 * @return string
 */
private static function get_caller_info(int $depth = 3): string {
    $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $depth + 2);
    // Skip get_caller_info itself and filter_to_known_columns
    $frames = array_slice($frames, 2);

    $parts = [];
    foreach ($frames as $f) {
        $where = '';
        if (!empty($f['class']) && !empty($f['function'])) {
            $where = $f['class'] . '::' . $f['function'];
        } elseif (!empty($f['function'])) {
            $where = $f['function'];
        }

        $location = '';
        if (!empty($f['file']) && !empty($f['line'])) {
            // Strip the WP_CONTENT_DIR prefix for log readability.
            $file = $f['file'];
            if (defined('WP_CONTENT_DIR')) {
                $file = str_replace(WP_CONTENT_DIR, '', $file);
            }
            $location = ' (' . basename($file) . ':' . $f['line'] . ')';
        }

        if ($where !== '') {
            $parts[] = $where . $location;
        }
    }

    return $parts ? implode(' <- ', $parts) : '(unknown caller)';
}
```

### Step F2: Add a rate-limit on the log to prevent log flooding

If a buggy caller fires `update_client` in a loop with the wrong column names, the above would generate one log entry per loop iteration. Protect with a per-process throttle.

Add to the class:

```php
/**
 * Track recent unknown-key warnings to avoid log flooding.
 *
 * If a buggy caller invokes update_client in a tight loop with
 * the wrong column names, naive logging would write thousands of
 * entries per minute. We dedupe by the unknown-key signature
 * within this request.
 *
 * @var array<string, true>
 */
private static $logged_unknown_signatures = [];
```

Modify `filter_to_known_columns` to use this:

```php
if (!empty($unknown_keys) && class_exists('MealsDB_Logger')) {
    sort($unknown_keys);
    $signature = implode(',', $unknown_keys);

    if (!isset(self::$logged_unknown_signatures[$signature])) {
        self::$logged_unknown_signatures[$signature] = true;

        $caller_info = self::get_caller_info(3);

        MealsDB_Logger::error(sprintf(
            '[MealsDB Repository] filter_to_known_columns dropped unknown column(s): %s. Called from: %s',
            $signature,
            $caller_info
        ));
    }
}
```

This way, even a tight loop with the same unknown keys logs once per request. Across requests, it logs once per request (which is acceptable — operators want to know about the bug).

### Step F3: Add a CLAUDE.md cross-reference comment

At the top of `class-clients-repository.php`, add a class-level docblock note:

```php
/**
 * Repository for meals_clients table operations.
 *
 * <existing docblock if present>
 *
 * COLUMN NAME CONVENTION: This class uses DB-side column names
 * exclusively (e.g. `wp_user_id`, NOT `wordpress_user_id`). Callers
 * must convert form-side names to DB-side before calling. The
 * MealsDB_Client_Form::map_form_to_db helper handles this for the
 * standard form flow. Direct callers (sync, migration, backfill)
 * must convert manually.
 *
 * filter_to_known_columns will detect and log unknown column
 * names but cannot fix them. A logged warning indicates a caller
 * bug.
 *
 * See CLAUDE.md section "Form-side vs DB-side column names" for
 * the full mapping table.
 */
```

---

## Testing

### Step T1: Static check

```bash
php -l includes/services/class-clients-repository.php
```

### Step T2: Functional test — confirm logging fires for unknown columns

Run from wp-cli:

```bash
wp eval '
$repo = new MealsDB_Clients_Repository();
$result = $repo->update_client(1, ["wordpress_user_id" => 99]);
// Intentionally pass wrong column name.
'
```

Check `wp_content/debug.log` for an entry matching `filter_to_known_columns dropped unknown column(s): wordpress_user_id`.

If the entry is present, logging works. If not, debug.

### Step T3: Functional test — confirm legitimate calls don't log

```bash
wp eval '
$repo = new MealsDB_Clients_Repository();
$result = $repo->update_client(1, ["wp_user_id" => 99, "active" => 1]);
// Both columns are DB-side; should not log.
'
```

Verify no new entry in debug.log from this call.

### Step T4: Functional test — confirm dedupe works

```bash
wp eval '
$repo = new MealsDB_Clients_Repository();
for ($i = 0; $i < 100; $i++) {
    $repo->update_client(1, ["wordpress_user_id" => 99]);
}
'
```

Verify only ONE entry appears in debug.log for these 100 calls (within this single PHP process).

### Step T5: Regression — confirm no existing legitimate caller now logs

Run the standard plugin operations on staging and tail the debug log:

```bash
tail -f wp-content/debug.log | grep "filter_to_known_columns"
```

In another tab, exercise the plugin:
- Save a client via the form (uses MealsDB_Client_Form::update — should map names correctly, no log).
- Run a sync from the dashboard.
- Create a Quick Order.
- Add/edit a staff member.

If any of these produce a `filter_to_known_columns` log entry, **you've found a bug**. Document it as a follow-up directive — do not fix in this directive.

---

## Out of scope for this directive

- Do NOT add automatic name translation (e.g. detect `wordpress_user_id` and rewrite to `wp_user_id`). That would mask bugs rather than surface them.
- Do NOT rename any DB column or form-side name. The vocabulary unification is a v2 architectural change.
- Do NOT add the same logging to other repositories (`MealsDB_Orders_Repository`, etc.) in this directive. They have their own column conventions that need separate analysis. **Note as follow-up.**
- Do NOT change `MealsDB_Client_Form::map_form_to_db` or `load_client`. Those are working correctly.
- Do NOT make the logging configurable. It's a bug detector; bugs should always be logged.

---

## Acceptance criteria

The directive is complete when:

1. ✅ Pre-flight steps P1-P3 are complete and documented.
2. ✅ `filter_to_known_columns` logs when it drops unknown columns.
3. ✅ The dedupe mechanism prevents log flooding within a request.
4. ✅ `get_caller_info` helper exists and returns formatted caller chains.
5. ✅ Class-level docblock notes the convention and links to CLAUDE.md.
6. ✅ All four functional tests (T2-T5) pass.
7. ✅ `php -l` passes.

When complete, your final response should include:
- A diff of `class-clients-repository.php`.
- Sample log entries from the functional tests (T2 and T4).
- Confirmation that legitimate operations (T5) produced zero log entries — or a list of bugs found if not.
