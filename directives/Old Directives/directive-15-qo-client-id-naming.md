# Directive: Clean Up `client_id` Semantics in Quick Order AJAX

**Severity:** LOW (STRUCT-6 from synthesis)
**Audit reference:** `recon-08-phase-w.md` cross-cutting observation 14; `recon-09-synthesis.md` STRUCT-6
**Target file:** `includes/class-quick-order-ajax.php`
**Estimated scope:** ~30-60 lines of renames in one file
**Risk:** LOW — naming change only, no behavioral change
**Must complete before:** v1.1 release (not pre-cutover blocking)

---

## Context

In `class-quick-order-ajax.php`, the term `client_id` has THREE different meanings depending on context:

1. **`$client_id` as a local variable** — often refers to **WP user_id** despite the name. Example: `create_order()` line 283 reads `intval($_POST['client_id'])`, but the POST value is the WP user ID, not the meals_clients PK.

2. **`$client_db_id`** — refers to **meals_clients.client_id** (primary key). Resolved via `get_active_client_id_for_user($client_id)`.

3. **`$mealsdb_client_id`** (parameter name in `create_wc_order`) — same as `$client_db_id`. Yet another name for the same concept.

The code is internally consistent — it works. But the inconsistent vocabulary is a tripwire for future maintainers. Someone reading the code who assumes `$client_id` means "the meals_clients PK" (the more natural interpretation) will misread the logic.

This directive renames consistently:
- `$wp_user_id` — always means WP user ID.
- `$client_id` — always means meals_clients.client_id (PK).

After the rename, anywhere `$client_id` is used, it unambiguously means the meals_clients PK.

---

## Pre-flight verification

### Step P1: Inventory `client_id` usage

```bash
grep -n "client_id\|wp_user_id\|wordpress_user_id" includes/class-quick-order-ajax.php
```

Document every match. Categorize each:
- **A**: Reading from `$_POST['client_id']` where the actual value is WP user ID. Rename target.
- **B**: Local variable `$client_id` holding a WP user ID. Rename target.
- **C**: Local variable `$client_db_id` holding meals_clients PK. Rename target (rename to `$client_id`).
- **D**: Parameter `$mealsdb_client_id`. Rename target (rename to `$client_id`).
- **E**: Database column reference (e.g. `c.client_id` in SQL). Do NOT rename — these are correct.
- **F**: Already-correct usage of `$wp_user_id` or `$client_id` (PK). Leave as-is.

### Step P2: Check external interface contracts

The AJAX endpoint accepts `$_POST['client_id']` from JS. If the JS sends a WP user ID as `client_id`, that's the bug we're encoding away. Two options:

**Option I (recommended): Accept BOTH parameter names for backward compatibility.**

The JS continues sending `client_id` (with WP user ID value), but the PHP renames internally. Add a `wp_user_id` parameter that takes precedence if both are sent. Provides a migration path.

**Option II: Change the JS to send `wp_user_id`.**

The JS files need updating. More invasive. Risk of cached browser sessions failing.

**Recommended: Option I.** Document the JS-side parameter name as `client_id` but treat it internally as WP user ID. The frontend contract stays the same.

Confirm with the dev before proceeding.

### Step P3: Check `clone_order` and similar methods

The audit noted that `clone_order` and `clone_get_order` have similar semantic confusion. Check those methods too. If they have the same issue, include them in the rename.

---

## The fix

### Step F1: Rename in `create_order`

Locate `create_order` (around line 263). Read the full method.

For each occurrence:

| Before | After |
|---|---|
| `$client_id = intval($_POST['client_id'] ?? 0);` (where value is WP user ID) | `$wp_user_id = intval($_POST['client_id'] ?? $_POST['wp_user_id'] ?? 0);` |
| `if ($client_id <= 0)` | `if ($wp_user_id <= 0)` |
| `user_exists($client_id)` | `user_exists($wp_user_id)` |
| `$client_db_id = get_active_client_id_for_user($client_id)` | `$client_id = get_active_client_id_for_user($wp_user_id)` |
| Subsequent `$client_db_id` references | `$client_id` |

Add a comment block at the top of `create_order`:

```php
/**
 * Create a WC order via Quick Order.
 *
 * NAMING CONVENTION (consistent across this method):
 *   $wp_user_id — WP user ID (the customer's WordPress account).
 *   $client_id  — meals_clients.client_id (the PK of the meals
 *                  client record linked to the WP user).
 *
 * The JS frontend posts the WP user ID under the parameter name
 * 'client_id' (historical, retained for backward compatibility).
 * Internally we rename for clarity. A 'wp_user_id' POST parameter
 * is also accepted, taking precedence if both are sent.
 */
```

### Step F2: Rename in `create_wc_order`

The method signature likely has `$mealsdb_client_id` as a parameter. Read the method and its callers (`create_order` is the primary caller).

```php
// Before:
private static function create_wc_order(
    int $mealsdb_client_user_id,
    int $mealsdb_client_id,
    ...
): WC_Order_Result { ... }

// After:
private static function create_wc_order(
    int $wp_user_id,
    int $client_id,
    ...
): WC_Order_Result { ... }
```

Update every internal reference in the method body. Update every call site (`create_order`'s call to this method).

### Step F3: Rename in cloning methods

If `clone_order` and `clone_get_order` have the same vocabulary issues, apply the same rename. Read each method fully before renaming to confirm the semantics match.

### Step F4: Rename in `get_client_allocation` and `get_client_allocation_history`

These methods accept a `client_id` parameter. Determine which semantic it actually carries (WP user ID? meals PK?) and rename accordingly.

If the AJAX endpoint accepts a WP user ID from the frontend, rename the local variable to `$wp_user_id`, then resolve to the meals PK with the standard `get_active_client_id_for_user` helper.

### Step F5: Apply the same rename to `helper methods within the same class`

```bash
grep -n "function " includes/class-quick-order-ajax.php
```

For each private/static helper that takes a `$client_id` or `$mealsdb_client_id` parameter:
- Confirm which semantic the parameter carries.
- Rename per the convention.
- Update internal references AND the call sites in this class.

### Step F6: Add a class-level docblock note

At the top of the class:

```php
/**
 * Quick Order AJAX handlers.
 *
 * <existing docblock>
 *
 * NAMING CONVENTION:
 *   $wp_user_id — WordPress user ID (wp_users.ID).
 *   $client_id  — meals_clients.client_id (the PK, linked to a
 *                  WP user via meals_clients.wp_user_id).
 *
 * The JS frontend posts the WP user ID under the historical
 * parameter name 'client_id'. Internally this class renames to
 * $wp_user_id for clarity. A 'wp_user_id' POST parameter is also
 * accepted for callers that prefer the explicit name.
 *
 * Database queries and SQL column references use the actual DB
 * column names (`c.client_id`, `c.wp_user_id`) regardless of the
 * PHP variable naming.
 */
```

---

## Testing

### Step T1: Static check

```bash
php -l includes/class-quick-order-ajax.php
```

### Step T2: Functional test — Quick Order works

> **Manual test required:**
> 1. Navigate to Meals DB → Quick Order.
> 2. Search for a client. Verify search returns results.
> 3. Select a client. Verify the per-client allocation panel populates.
> 4. Add products to the cart.
> 5. Create the order. Verify it saves successfully.
> 6. Check the resulting WC order: `mealsdb_client_user_id` should be the WP user ID, `mealsdb_client_id` should be the meals_clients PK.
> 7. Try cloning an existing order via the "Clone to Quick Order" feature.
> 8. Verify clone produces correct items and client linkage.
> 9. View allocation history widget on the edit-client page.
> 10. Verify it loads and shows correct data.

### Step T3: Backward compatibility test

Submit a Quick Order create-order request the OLD way (with `client_id` POST parameter containing a WP user ID):

```bash
wp eval '
$_POST = [
    "client_id" => 123, // WP user ID under old name
    "date" => "2026-05-20",
    "items" => [],
    "nonce" => wp_create_nonce("mealsdb_quick_order_create_order"),
];
// Trigger the AJAX handler.
'
```

Expected: works exactly as before (the historical contract is preserved).

Then submit the same request with the new explicit name:

```bash
wp eval '
$_POST = [
    "wp_user_id" => 123, // explicit name
    "date" => "2026-05-20",
    "items" => [],
    "nonce" => wp_create_nonce("mealsdb_quick_order_create_order"),
];
'
```

Expected: also works.

### Step T4: Grep for remaining inconsistencies

```bash
grep -n "client_db_id\|mealsdb_client_id" includes/class-quick-order-ajax.php
```

Expected: zero matches in PHP variable names (matches in SQL column references, docblocks, or order meta keys are acceptable — `_mealsdb_client_id` is the order meta key name and stays).

---

## Out of scope for this directive

- Do NOT rename the order meta keys `mealsdb_client_id` and `mealsdb_client_user_id`. Those are persistent on existing orders and renaming would break legacy data.
- Do NOT change the actual DB column name `wp_user_id` or `client_id`. These are correct.
- Do NOT rename `client_id` variables in OTHER files. Each file needs its own analysis. This directive is scoped to Quick Order AJAX only.
- Do NOT change the JS-side parameter name. Backward compat is preserved by accepting both names in PHP.

---

## Acceptance criteria

The directive is complete when:

1. ✅ Pre-flight P1, P2, P3 are complete.
2. ✅ The dev has confirmed Option I (backward-compat) or Option II (JS rename).
3. ✅ Every method in `class-quick-order-ajax.php` uses the naming convention consistently.
4. ✅ Class-level docblock documents the convention.
5. ✅ Each method that uses `$wp_user_id` and `$client_id` has a method-level comment if the distinction is non-obvious.
6. ✅ `php -l` passes.
7. ✅ T2 (Quick Order works) passes.
8. ✅ T3 (backward compatibility) passes.
9. ✅ T4 (orphan name grep) returns expected results.

When complete, your final response should include:
- A summary of methods renamed.
- The chosen option (I or II).
- The functional test results.
- A note on whether `clone_order` / `clone_get_order` / allocation methods also needed renaming.
