# Directive: Fix "Link to This User" AJAX Handler

**Severity:** CRITICAL (CRIT-2)
**Audit reference:** `recon-07-admin-ui.md` lines 803-820; `recon-09-synthesis.md` CRIT-2
**Target file:** `includes/ajax/class-ajax-clients.php`
**Estimated scope:** ~10 lines of changes in one method
**Risk:** VERY LOW — the handler currently does nothing, so any fix is an improvement
**Must complete before:** cutover (the button is visible to operators and silently fails)

---

## Context

The plugin maintains two parallel column name vocabularies (see CLAUDE.md section "Form-side vs DB-side column names"):
- **Form-side** (used in `$_POST` keys and form values): `wordpress_user_id`
- **DB-side** (actual database column on `meals_clients`): `wp_user_id`

The `MealsDB_Clients_Repository::filter_to_known_columns()` method validates incoming column names against the canonical schema (which uses DB-side names) and **silently drops unknown keys**.

The AJAX handler `MealsDB_Ajax_Clients::link_client_to_wp_user` mistakenly uses the form-side name `wordpress_user_id` in two places where it should use the DB-side name `wp_user_id`:

1. Line ~94: Reading the existing value from `$client_row['wordpress_user_id']` — but `get_client_by_id()` returns the raw DB row, which has `wp_user_id` (not `wordpress_user_id`). The read always returns `null`.
2. Line ~99: Writing the new value via `$repository->update_client($client_id, ['wordpress_user_id' => $wp_user_id])` — `filter_to_known_columns` strips `wordpress_user_id` and the update writes nothing. The repository reports success because zero rows updated is technically not an error.

The handler returns success. The audit log records a "change" from null to the supplied value. **The actual database row is unchanged.**

This handler is wired to the "Link to This User" button rendered by `MealsDB_Admin_UI::render_unlinked_client_matches()` (in the unlinked-clients-matches view). Operators click the button, see a success message, and the link doesn't happen.

A sibling handler `MealsDB_Ajax_Clients::link_client` (using the `mealsdb_link_client` AJAX action, not `mealsdb_link_client_to_wp_user`) works correctly via `MealsDB_Sync::link_client_to_wordpress_user()`. The sync dashboard's compare-databases workflow uses the working handler. Only the unlinked-matches UI uses the broken one.

---

## Pre-flight verification

### Step P1: Confirm the bug exists in the current codebase

Open `includes/ajax/class-ajax-clients.php` and locate the `link_client_to_wp_user` method (NOT `link_client` — they are different methods). It's approximately lines 64-112.

Within this method, verify the following two problematic lines exist:

1. A line that reads `$client_row['wordpress_user_id']` (around line 94). If the access is via `$existing_wp_user_id = ...wordpress_user_id...`, that's the line.
2. A line that calls `$repository->update_client($client_id, ['wordpress_user_id' => $wp_user_id])` (around line 99).

If both are present, the bug is confirmed. If either has already been changed to `wp_user_id`, **STOP** and report — the directive is already partially or fully addressed.

### Step P2: Confirm the canonical column name

Check the canonical schema definition in `includes/class-schema.php`. Find the `meals_clients` table's column list and confirm the column is named `wp_user_id` (NOT `wordpress_user_id`).

If the column is actually `wordpress_user_id`, **STOP** — the bug isn't where the audit says it is, and the fix below would break things.

### Step P3: Confirm there are no other callers writing `wordpress_user_id` to the repository

Run this grep across the entire codebase:

```bash
grep -rn "'wordpress_user_id'" includes/ views/ --include="*.php"
```

Each match needs review:
- If it's setting a key in an array passed to `update_client()`, `create_client()`, or `$wpdb->update()` against `meals_clients`, that's another instance of the same bug — report it but do NOT fix in this directive.
- If it's reading from `$_POST['wordpress_user_id']` (form-side input), that's correct.
- If it's used as a form field name in HTML markup, that's correct.
- If it's a key in `MealsDB_Client_Form::$db_columns` (the form-side allowed list), that's correct.
- If it's in `MealsDB_Client_Form::load_client()` doing the DB→form remap, that's correct.

Document any additional bug instances found but do NOT modify them in this directive — each one needs its own commit so the change history is clean. If you find more, list them in your final response.

---

## The fix

### Step F1: Change the read

Locate this line (approximately line 94):

```php
$raw_existing = $client_row['wordpress_user_id'] ?? null;
```

Change `'wordpress_user_id'` to `'wp_user_id'`:

```php
$raw_existing = $client_row['wp_user_id'] ?? null;
```

### Step F2: Change the write

Locate this line (approximately line 99):

```php
if (!$repository->update_client($client_id, ['wordpress_user_id' => $wp_user_id])) {
```

Change the array key from `'wordpress_user_id'` to `'wp_user_id'`:

```php
if (!$repository->update_client($client_id, ['wp_user_id' => $wp_user_id])) {
```

### Step F3: Decide on the audit log field name

There's a logger call right after the update (around lines 105-109):

```php
MealsDB_Logger::log(
    'link_client_to_wp_user',
    $client_id,
    'wordpress_user_id',
    $existing_wp_user_id !== null ? (string) $existing_wp_user_id : null,
    (string) $wp_user_id
);
```

The third parameter is the `field` name being audited. **Change `'wordpress_user_id'` to `'wp_user_id'`** to match the actual column being modified. This makes the audit log accurate and consistent with other handlers.

```php
MealsDB_Logger::log(
    'link_client_to_wp_user',
    $client_id,
    'wp_user_id',
    $existing_wp_user_id !== null ? (string) $existing_wp_user_id : null,
    (string) $wp_user_id
);
```

**Note:** the `'link_client_to_wp_user'` action name in the first parameter is fine as-is — it's an action category, not a column name. The directive only changes the column name.

### Step F4: Add a comment

Above the `link_client_to_wp_user` method, add a comment block (if there isn't already a docblock — if there is, add a note to it):

```php
/**
 * Direct-update path for linking a client to a WP user.
 *
 * <existing docblock if any>
 *
 * COLUMN NAME NOTE: The DB column on meals_clients is `wp_user_id`,
 * NOT `wordpress_user_id` (which is the form-side vocabulary). A
 * previous version used `wordpress_user_id` here; the repository's
 * filter_to_known_columns silently dropped it, the read returned
 * null, and the handler reported success while doing nothing.
 * See CLAUDE.md section "Form-side vs DB-side column names".
 */
```

This comment is mandatory. The bug class is recurring and silent; documenting the fix discourages reverting it.

---

## Testing

### Step T1: Static check

Run `php -l includes/ajax/class-ajax-clients.php`. Must pass.

### Step T2: Manual test instruction

The dev needs to verify the fix manually. Include this in your final response:

> **Manual test required:**
> 1. In WP admin, navigate to Meals DB → Sync Dashboard.
> 2. Find a client that shows "Probable matches" with "Link to This User" buttons.
> 3. Click "Link to This User" for one match.
> 4. Verify the client now shows as linked (refresh the page).
> 5. Verify `2xnIt_meals_clients.wp_user_id` is set to the correct WP user ID for that client_id (via wp-cli or phpMyAdmin).
> 6. Verify `2xnIt_meals_audit_log` shows a new row with `action='link_client_to_wp_user'`, `field='wp_user_id'`, `old_value=null`, `new_value=<wp_user_id>`.

### Step T3: Regression check — the working sibling handler

The other handler `MealsDB_Ajax_Clients::link_client` (action `mealsdb_link_client`, not `mealsdb_link_client_to_wp_user`) should be unchanged. Verify by:

```bash
git diff includes/ajax/class-ajax-clients.php
```

The diff should only show changes within the `link_client_to_wp_user` method. If the diff shows changes elsewhere, revert those — they're out of scope.

---

## Out of scope for this directive

- Do NOT modify the sibling `link_client` handler (it works correctly).
- Do NOT modify `MealsDB_Sync::link_client_to_wordpress_user` or related sync code.
- Do NOT modify the form-side `wordpress_user_id` vocabulary usage in views, form rendering, or `MealsDB_Client_Form::$db_columns`. The form-side name is intentional; only the DB-write boundary is fixed.
- Do NOT add a global rename or vocabulary unification. That's a STRUCT-1 architectural decision for v2.
- Do NOT change `filter_to_known_columns` to log unknown keys. (That's its own separate hardening directive — see directive 11.)

---

## Acceptance criteria

The directive is complete when:

1. ✅ Pre-flight steps P1, P2, P3 are confirmed.
2. ✅ Line ~94 reads from `'wp_user_id'` instead of `'wordpress_user_id'`.
3. ✅ Line ~99 writes `['wp_user_id' => ...]` instead of `['wordpress_user_id' => ...]`.
4. ✅ Line ~107 logs `field='wp_user_id'` instead of `field='wordpress_user_id'`.
5. ✅ The COLUMN NAME NOTE comment block is added.
6. ✅ `php -l` passes.
7. ✅ `git diff` shows changes only within the `link_client_to_wp_user` method.
8. ✅ The sibling `link_client` method is unchanged.

When complete, your final response should include:
- The diff of the changes.
- Confirmation that pre-flight checks P1-P3 succeeded.
- Any additional `wordpress_user_id`-write-to-DB instances found during P3 (for future directives, not this one).
- The manual test instructions for the dev.
