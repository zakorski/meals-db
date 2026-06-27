# Directive: Dead Code Removal Batch

**Severity:** LOW-MEDIUM (MAJ-1, MAJ-5, MAJ-6, plus other dead code items from synthesis)
**Audit reference:** `recon-09-synthesis.md` "Dead code / cleanup candidates" section
**Target files:** Multiple — see individual parts
**Estimated scope:** ~200 lines removed across 4 files
**Risk:** LOW — these items are confirmed unreferenced in the audit
**Must complete before:** v1.1 release (not pre-cutover blocking)

---

## Context

The audit identified several files and code blocks that are no longer referenced anywhere in the codebase. Each was confirmed dead via `grep` across all PHP files. Removing dead code reduces:
- Audit surface area (less code to secure)
- Maintainer confusion (no "what does this do?")
- Risk of accidental future use (someone copies dead code thinking it's a working pattern)

This directive handles **four** dead code items in sequence. Each Part is independent — if any fails verification, skip it but proceed with the others.

---

## Part A: Delete `class-ajax-staff.php` (MAJ-1)

### Context

`includes/ajax/class-ajax-staff.php` (91 lines) contains three AJAX handlers (`add_staff`, `update_staff`, `deactivate_staff`) that all return `"X via AJAX is not available at this time"` with a logger error call. They are stubs that were never implemented.

The real Staff CRUD path is `admin_post_mealsdb_save_staff` (a standard HTML form POST handler) in `includes/class-staff.php`. The Staff Directory admin page does not use these AJAX endpoints.

### Pre-flight verification

```bash
# Confirm the file exists and contains stub returns
grep -n "not available at this time" includes/ajax/class-ajax-staff.php
```

Expected: 3 matches (one per handler).

```bash
# Confirm no other PHP file references the class or its actions
grep -rn "MealsDB_Ajax_Staff\|mealsdb_add_staff\|mealsdb_update_staff\|mealsdb_deactivate_staff" \
  includes/ views/ assets/ --include="*.php" --include="*.js" 2>/dev/null
```

Expected callers:
- `meals-db-main.php` — `MealsDB_Ajax_Staff::init()` call in `plugins_loaded` action.
- Possibly the class file itself.

If grep finds anything else (a JS file dispatching these actions, another PHP file calling these methods), **STOP** and report. The file is not actually dead.

```bash
# Confirm the JS doesn't dispatch these actions
grep -rn "mealsdb_add_staff\|mealsdb_update_staff\|mealsdb_deactivate_staff" assets/js/ 2>/dev/null
```

Expected: zero matches.

### The fix

1. **Delete the file** `includes/ajax/class-ajax-staff.php`.
2. **Remove the init call** from `meals-db-main.php`. Locate the line:
   ```php
   MealsDB_Ajax_Staff::init();
   ```
   It's in the `plugins_loaded` action block, approximately lines 88-134. Delete the line entirely (don't comment it out).
3. **Verify the autoloader directory list** doesn't need updating. The autoloader walks `includes/ajax/` so removing one file doesn't change the directory structure.

### Testing for Part A

```bash
# Confirm file is gone
ls includes/ajax/class-ajax-staff.php 2>&1
# Expected: "No such file or directory"

# Confirm no broken references
grep -rn "MealsDB_Ajax_Staff" . --include="*.php" 2>/dev/null
# Expected: zero matches
```

Functional test:

> **Manual test required:**
> 1. Navigate to Meals DB → Staff Directory.
> 2. Add a new staff member via the form.
> 3. Verify the form POSTs to admin-post.php with action `mealsdb_save_staff` (the non-AJAX path).
> 4. Verify the staff member is saved.
> 5. Edit and re-save the same staff member.
> 6. Verify no PHP errors in `wp_content/debug.log`.

---

## Part B: Delete `views/partials/client-form-fields.php` (MAJ-5)

### Context

This is a 59-line partial defining a minimal client form (first_name, last_name, client_email, wordpress_user_id, phone_primary, address_postal, client_type, birth_date). The audit confirmed via grep that **no PHP file references it**. The actual client form is rendered by `MealsDB_Admin_UI::render_client_form` (lines 850-1741 of `class-admin-ui.php`).

### Pre-flight verification

```bash
grep -rn "client-form-fields" . --include="*.php" 2>/dev/null
```

Expected: zero matches (or only matches inside the file itself).

If grep finds an `include`, `require`, or string-based reference to this file from another PHP file, **STOP** — the file is not dead and removing it would break the reference.

### The fix

Delete the file `views/partials/client-form-fields.php`.

### Testing for Part B

```bash
ls views/partials/client-form-fields.php 2>&1
# Expected: "No such file or directory"

# Confirm no broken includes
grep -rn "client-form-fields" . --include="*.php" 2>/dev/null
# Expected: zero matches
```

Functional test:

> **Manual test required:**
> 1. Navigate to Meals DB → Add New Client.
> 2. Verify the form renders correctly.
> 3. Save a new client.
> 4. Edit the client.
> 5. Verify edit form renders correctly.
> 6. No PHP errors in `wp_content/debug.log`.

---

## Part C: Remove `__()` fallback in `class-client-form.php`

### Context

At the top of `includes/class-client-form.php` (lines 4-8):

```php
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default') {
        return $text;
    }
}
```

This defines a global fallback for WP's `__()` translation function. WordPress's `__()` is also guarded with `!function_exists`. If this file ever loads before WP's translation system, this fallback wins permanently — all translations silently return the source string.

In practice, the file is autoloaded via `plugins_loaded` action, well after WP's translation init. The fallback never wins. But the smell remains: defining global functions inside class files is fragile.

### Pre-flight verification

```bash
grep -n "function_exists.*'__'\|function __(" includes/class-client-form.php
```

Confirm the fallback exists. If absent, this Part is already done.

```bash
# Confirm the file is gated by ABSPATH so it only loads in WP context
head -3 includes/class-client-form.php
```

Expected first 3 lines:
```php
<?php
defined('ABSPATH') || exit;
```

This gate ensures the file only loads inside WordPress, where `__()` is guaranteed to exist by the time autoloading runs.

### The fix

Delete the 5-line fallback block. Replace with a single comment explaining why it's not needed:

```php
// NOTE: This file is gated by defined('ABSPATH') and autoloaded
// after plugins_loaded fires, by which point WP's __() is defined.
// A previous version had a function_exists('__') fallback here that
// would shadow WP's __() if it ever loaded first. The defensive
// fallback is unnecessary in WP runtime and risks silently breaking
// translations if the load order ever changes.
```

### Testing for Part C

```bash
php -l includes/class-client-form.php
```

Verify translations still work:

> **Manual test required:**
> 1. If a non-English locale is configured: navigate to a page rendered by class-client-form (e.g. add-client form).
> 2. Verify labels appear in the configured locale, not English.
> 3. On default English install, no functional difference; just verify no PHP errors.

---

## Part D: Remove auto-init at file scope in `class-quick-order-products.php`

### Context

At the bottom of `includes/class-quick-order-products.php` (around line 596):

```php
MealsDB_Quick_Order_Products::init();
```

This line runs when the autoloader loads the file (the first time the class is referenced). Most other classes register hooks via explicit `init()` call from `meals-db-main.php`'s `plugins_loaded` action. This class breaks the pattern.

The auto-init is idempotent (via a `$hooks_registered` static), so no functional bug. But it makes load order non-deterministic.

### Pre-flight verification

```bash
tail -10 includes/class-quick-order-products.php
```

Confirm the auto-init line exists at the bottom of the file (outside the class body).

```bash
grep -n "MealsDB_Quick_Order_Products::init" meals-db-main.php
```

Document whether `meals-db-main.php` already calls `MealsDB_Quick_Order_Products::init()`. If yes, the auto-init is redundant (move just removes redundancy). If no, the auto-init is the only call site — we need to add an explicit call to `meals-db-main.php` when removing it.

### The fix

**Step F1:** In `meals-db-main.php`, locate the `plugins_loaded` action block where other `init()` calls live. The list is alphabetically or category-grouped. Add:

```php
MealsDB_Quick_Order_Products::init();
```

Position it near related Quick Order classes (`MealsDB_Quick_Order_Ajax`, `MealsDB_Quick_Order_UI`).

**Step F2:** In `class-quick-order-products.php`, delete the trailing `MealsDB_Quick_Order_Products::init();` line (around line 596).

Replace it with a comment:

```php
// NOTE: Hook registration is triggered explicitly from
// meals-db-main.php's plugins_loaded action, matching the pattern
// used by every other class in the plugin. A previous version
// auto-init'd here at file scope (when the autoloader loaded the
// class), which worked but made load order implicit.
```

### Testing for Part D

```bash
php -l includes/class-quick-order-products.php
php -l meals-db-main.php
```

Functional test:

> **Manual test required:**
> 1. Navigate to Meals DB → Quick Order.
> 2. Verify the page loads.
> 3. Verify products and categories appear (these are loaded via hooks registered in `init`).
> 4. Search for a product. Verify results appear.
> 5. Try cloning an existing order via the "Clone to Quick Order" button.
> 6. No PHP errors in `wp_content/debug.log`.

---

## Out of scope for this directive

- Do NOT touch other historical-import / client-importer dead code candidates mentioned in `recon-01-dev-context.md` until confirmed they're still in the codebase. (Synthesis flagged them with "if present" qualifier.)
- Do NOT touch `STATUS_COUNTED` (MAJ-6) — that's a separate decision in directive 7.
- Do NOT extract operational constants (5675, 4122, etc.) — that's directive 8.
- Do NOT rename or unify form-side/DB-side column vocabulary — that's a v2 architectural decision.

---

## Acceptance criteria

The directive is complete when:

**Part A:**
1. ✅ `includes/ajax/class-ajax-staff.php` is deleted.
2. ✅ The `MealsDB_Ajax_Staff::init()` call is removed from `meals-db-main.php`.
3. ✅ Grep confirms no remaining references.
4. ✅ Manual test (staff CRUD via form) passes.

**Part B:**
5. ✅ `views/partials/client-form-fields.php` is deleted.
6. ✅ Grep confirms no remaining references.
7. ✅ Manual test (add/edit client form) passes.

**Part C:**
8. ✅ The `__()` fallback block in `class-client-form.php` is removed.
9. ✅ Comment explaining why no fallback is needed is added.
10. ✅ `php -l` passes.

**Part D:**
11. ✅ Auto-init at file scope removed from `class-quick-order-products.php`.
12. ✅ Explicit `init()` call added to `meals-db-main.php`.
13. ✅ Comment added to the class file explaining the move.
14. ✅ Manual test (Quick Order page functionality) passes.

When complete, your final response should include:
- A summary of files deleted and lines removed.
- A diff of `meals-db-main.php` showing the init list change.
- Confirmation that all grep checks returned zero references.
- Manual test results from the dev (or instructions if dev needs to verify).
