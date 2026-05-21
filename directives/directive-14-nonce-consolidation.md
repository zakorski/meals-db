# Directive: Consolidate Nonce Contexts

**Severity:** LOW (STRUCT-5 from synthesis)
**Audit reference:** `recon-07-admin-ui.md`; `recon-09-synthesis.md` STRUCT-5
**Target files:** Multiple — see scope below
**Estimated scope:** ~100-200 lines touched across 10-15 files
**Risk:** MEDIUM — nonce mismatches break AJAX. Must be tested thoroughly.
**Must complete before:** v1.1 release (not pre-cutover blocking)

---

## Context

The plugin uses 10+ distinct nonce contexts. The audit catalogued them:

| Nonce | Used by |
|---|---|
| `mealsdb_nonce` | Most general AJAX |
| `mealsdb_settings_nonce` | Settings save/key gen |
| `mealsdb_migration_nonce` | Migration phases |
| `mealsdb_generate_initials` | Initials generate |
| `mealsdb_validate_initials` | Initials validate |
| `mealsdb_invoice_nonce` | Invoice generation |
| `mealsdb_compare_databases` | Sync compare form |
| `mealsdb_force_rebuild` | Force rebuild form |
| `mealsdb_update_schema` | Update schema form |
| `mealsdb_delete_nonadmin_users` | Delete users form |
| `mealsdb_quick_order_create_order` | QO create (stricter) |

The contexts are well-scoped (one per sensitive action) but inconsistent. Some action-specific nonces (`mealsdb_generate_initials`, `mealsdb_validate_initials`) are arguably over-specific. Others (`mealsdb_force_rebuild`, `mealsdb_delete_nonadmin_users`) make sense as destructive-action-specific.

The synthesis recommendation: consolidate into TWO nonces:
- `mealsdb_admin_nonce` — read operations and ordinary CRUD.
- `mealsdb_destructive_nonce` — operations that delete, rebuild, migrate, or modify settings.

Plus the stricter QO create-order nonce keeps its own context since it has separate rate limit semantics.

---

## Pre-flight verification

### Step P1: Inventory all nonce usage

```bash
grep -rn "wp_create_nonce\|wp_verify_nonce\|check_ajax_referer\|check_admin_referer" includes/ views/ --include="*.php" 2>/dev/null
```

Document every match. For each:
- The file and line.
- The nonce context name being created or verified.
- The endpoint being protected (AJAX action name or form action).

This is the complete inventory the directive will work from.

### Step P2: Categorize each context

For each nonce context found, categorize as:

**Category 1 — Destructive** (should use `mealsdb_destructive_nonce`):
- `mealsdb_force_rebuild`
- `mealsdb_update_schema`
- `mealsdb_delete_nonadmin_users`
- `mealsdb_settings_nonce` (settings save modifies config)
- `mealsdb_migration_nonce` (data migration is destructive)
- Anything else that deletes, rebuilds, modifies settings, or migrates.

**Category 2 — Standard** (should use `mealsdb_admin_nonce`):
- `mealsdb_nonce` (general)
- `mealsdb_generate_initials`
- `mealsdb_validate_initials`
- `mealsdb_invoice_nonce` (invoice generation is read-heavy with file output)
- `mealsdb_compare_databases` (read-only comparison)
- Anything that reads or performs ordinary CRUD without destruction.

**Category 3 — Keep separate** (genuinely distinct semantics):
- `mealsdb_quick_order_create_order` — separate rate limit bucket, kept distinct.

In your response, list every nonce in one of these three categories. **The dev must confirm the categorization before code changes.**

### Step P3: Confirm the JS side will be updated

Many of these nonces are emitted by PHP and consumed by JS (`wp_localize_script` or inline data attributes). The JS files need to read from the new payload keys:

```bash
grep -rn "mealsdb_nonce\|mealsdb_settings_nonce\|mealsdb_migration_nonce" assets/js/ 2>/dev/null
```

Document every JS file that references a soon-to-be-consolidated nonce. The directive needs to update both the PHP emission AND the JS consumption.

---

## The fix

### Step F1: Add the new nonce contexts to a constants class

Create a helper to centralize nonce context names. Add to `includes/class-permissions.php` (or wherever existing security constants live):

```php
/**
 * Nonce context constants.
 *
 * The plugin uses TWO general-purpose nonces consolidated from 10+
 * previous action-specific contexts. Destructive ops (delete, rebuild,
 * migrate, modify settings) use the destructive nonce; everything
 * else uses the admin nonce.
 *
 * Quick Order's create-order action retains its own context because
 * it has a separate rate-limit bucket and stricter authorization.
 */
const NONCE_ADMIN       = 'mealsdb_admin_nonce';
const NONCE_DESTRUCTIVE = 'mealsdb_destructive_nonce';
const NONCE_QO_CREATE   = 'mealsdb_quick_order_create_order';
```

Place these constants in `MealsDB_Permissions` or a new `MealsDB_Nonces` class — your call based on existing patterns.

### Step F2: Add migration helpers

Some users will have JS-cached pages open during the deployment. The old nonce names will be on those pages. Add backward-compatibility shims that accept BOTH old and new nonce names for a transition period.

In a new helper or in an existing security class:

```php
/**
 * Verify a nonce, accepting both new and legacy context names.
 *
 * During the v1.1 nonce consolidation, callers may submit nonces
 * created with old context names (mealsdb_settings_nonce,
 * mealsdb_migration_nonce, etc). This helper accepts both the
 * canonical new name and a list of legacy aliases.
 *
 * After all browser sessions have refreshed (give it 24 hours
 * post-deploy), the legacy aliases can be removed.
 *
 * @param string $supplied_nonce The nonce value from $_REQUEST.
 * @param string $canonical      The canonical context name (NONCE_ADMIN, etc).
 * @param string[] $legacy_aliases Optional legacy context names that should also be accepted.
 * @return bool True if the nonce verifies under any accepted name.
 */
public static function verify_nonce_with_legacy(
    string $supplied_nonce,
    string $canonical,
    array $legacy_aliases = []
): bool {
    if (wp_verify_nonce($supplied_nonce, $canonical)) {
        return true;
    }
    foreach ($legacy_aliases as $alias) {
        if (wp_verify_nonce($supplied_nonce, $alias)) {
            return true;
        }
    }
    return false;
}
```

### Step F3: Update PHP nonce creation sites

For each `wp_create_nonce('<old_context>')` call:

If the context is in Category 1 (destructive):
```php
wp_create_nonce(MealsDB_Permissions::NONCE_DESTRUCTIVE)
```

If Category 2 (standard):
```php
wp_create_nonce(MealsDB_Permissions::NONCE_ADMIN)
```

If Category 3 (kept separate):
```php
wp_create_nonce(MealsDB_Permissions::NONCE_QO_CREATE)
```

**Do these one file at a time, committing each.**

### Step F4: Update PHP nonce verification sites

For each `wp_verify_nonce` or `check_ajax_referer` call:

```php
// Before:
if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'mealsdb_force_rebuild')) {
    wp_send_json_error(['message' => 'Invalid nonce'], 403);
}

// After (with legacy alias for transition):
if (!MealsDB_Permissions::verify_nonce_with_legacy(
    $_POST['_wpnonce'] ?? '',
    MealsDB_Permissions::NONCE_DESTRUCTIVE,
    ['mealsdb_force_rebuild'] // legacy alias
)) {
    wp_send_json_error(['message' => 'Invalid nonce'], 403);
}
```

The `check_ajax_referer` calls use a different signature; consult the existing pattern.

### Step F5: Update JS-side nonce consumption

For each JS file that reads a nonce from `wp_localize_script` payload:

```bash
grep -rn "nonce\|_wpnonce" assets/js/ 2>/dev/null
```

The JS likely reads `window.mealsdb_data.nonce` or similar. The payload structure shouldn't change — the PHP changes are in the `wp_create_nonce` call. As long as the PHP emits the nonce to the same payload key (e.g. `'nonce' => wp_create_nonce(...)`), the JS doesn't need changes.

**Verify** by checking each `wp_localize_script` call: the payload key (e.g. `nonce`, `_wpnonce`) should stay the same; only the `wp_create_nonce` argument changes.

If any JS file hardcodes the nonce context name (passes it as a string to wp_verify_nonce on the server somehow), update that too. **Rare but possible.**

### Step F6: Plan the legacy-alias removal

The `verify_nonce_with_legacy` shim is temporary. Schedule its removal:

Add a TODO comment to the helper:

```php
// TODO: Remove the legacy_aliases parameter and the foreach loop
// in v1.2 after one full deployment cycle (24+ hours post v1.1
// deploy). At that point all cached browser sessions will have
// refreshed and only the canonical nonces will be in flight.
```

Add the same TODO to each call site that passes legacy aliases.

---

## Testing

### Step T1: Static check

Run `php -l` on every modified PHP file. All must pass.

### Step T2: Per-endpoint AJAX test

For each AJAX action whose nonce was consolidated, manually exercise the action and verify:
- The action succeeds.
- The action does NOT succeed when supplied with a wrong nonce (manual tampering test).

Recommended tests (covers all consolidations):
1. Save settings (was `mealsdb_settings_nonce`).
2. Generate encryption key (was `mealsdb_settings_nonce`).
3. Validate initials (was `mealsdb_validate_initials`).
4. Generate initials (was `mealsdb_generate_initials`).
5. Force rebuild schema (was `mealsdb_force_rebuild`).
6. Update schema (was `mealsdb_update_schema`).
7. Delete non-admin users (was `mealsdb_delete_nonadmin_users`).
8. Run migration phase (was `mealsdb_migration_nonce`).
9. Sync compare databases (was `mealsdb_compare_databases`).
10. Generate invoice (was `mealsdb_invoice_nonce`).
11. Create Quick Order (was `mealsdb_quick_order_create_order` — should still work, kept separate).

> **Manual test instructions for the dev:** exercise each of the 11 actions above. For each, verify the action completes successfully. Then for each, try submitting with a tampered/empty nonce — the action must fail with 403.

### Step T3: Legacy alias test

To confirm the backward-compat shim works, manually craft an old-style nonce:

```bash
wp eval '
$old_nonce = wp_create_nonce("mealsdb_force_rebuild");
// Use this old nonce to call the destructive endpoint.
// Expected: succeeds because the legacy alias is accepted.
'
```

Document the test in your response. The dev can run it during T2 or as a separate step.

### Step T4: Grep for remaining old context names

```bash
grep -rn "'mealsdb_settings_nonce'\|'mealsdb_migration_nonce'\|'mealsdb_force_rebuild'" includes/ views/ assets/js/ --include="*.php" --include="*.js"
```

Each remaining match should be ONLY inside the `legacy_aliases` array argument. Any other match is unconverted and needs fixing.

---

## Out of scope for this directive

- Do NOT remove the `verify_nonce_with_legacy` helper or the legacy aliases. They stay for one deployment cycle, then a follow-up directive removes them.
- Do NOT change the rate limit buckets. Quick Order's bucket stays separate; that's the whole reason for `NONCE_QO_CREATE`.
- Do NOT change capability checks. Capabilities and nonces are orthogonal.
- Do NOT consolidate Quick Order's create-order into the destructive nonce. The two have different semantics (rate limits, frequency expectations).

---

## Acceptance criteria

The directive is complete when:

1. ✅ Pre-flight P1, P2, P3 are complete and the dev has confirmed the categorization.
2. ✅ `NONCE_ADMIN`, `NONCE_DESTRUCTIVE`, `NONCE_QO_CREATE` constants exist in a centralized location.
3. ✅ `verify_nonce_with_legacy` helper exists.
4. ✅ Every `wp_create_nonce` call in the plugin uses one of the three canonical contexts.
5. ✅ Every `wp_verify_nonce` / `check_ajax_referer` call uses `verify_nonce_with_legacy` with the appropriate legacy aliases.
6. ✅ The JS-side consumption is verified to not require changes (or any required JS changes are made).
7. ✅ `php -l` passes on every modified file.
8. ✅ T2 (per-endpoint AJAX test) confirms each endpoint works with new nonces.
9. ✅ T3 (legacy alias test) confirms backward compatibility works.
10. ✅ T4 (grep) returns matches only inside legacy_aliases arrays.
11. ✅ A v1.2 cleanup directive is queued to remove the legacy alias path.

When complete, your final response should include:
- The categorization table the dev confirmed.
- A summary of every file modified.
- The T2 test results.
- A note about the queued v1.2 cleanup.
