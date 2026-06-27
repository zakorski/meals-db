# Directive: Post-Merge Cleanups from PR Review

**Severity:** LOW (cleanup follow-ups identified during code review of the prior PR)
**Target files:** 4 files, small surgical changes
**Estimated scope:** ~30-50 lines across 4 files
**Risk:** LOW — all changes are either cosmetic, dead-code removal, or alignment with patterns already established in sibling code
**Must complete before:** v1.1 release (not pre-cutover blocking)

---

## Context

A prior PR addressed CRIT-1 through CRIT-4 and most STRUCT findings from the v1.0.346 audit. Post-merge review identified four follow-up items: three are pure cleanup, one closes a security inconsistency the prior PR missed.

Each Part is independent. Tackle them in order, committing each separately.

---

## Part A: Align `backfill_allocation_engine` with sibling backfills

**File:** `includes/ajax/class-ajax-migration.php`

### Context

The prior PR's directive 16 Pass A added `manage_options` capability checks and `migration_destructive` (5/hr) rate limits to `backfill_allowances` and `backfill_addresses`. The third backfill in the same file, `backfill_allocation_engine`, was missed — it still uses `manage_woocommerce` (weaker capability) and has no rate limit at all.

This is a pre-existing inconsistency the directive should have caught and didn't. The three backfills are siblings in every other respect (same file, same purpose, same destructive impact on the meals_clients table) and should have matching protection.

### Pre-flight verification

#### Step A-P1: Confirm the current state

```bash
grep -A 25 "function backfill_allocation_engine" includes/ajax/class-ajax-migration.php | head -25
```

Expected current state:
- Capability check: `current_user_can('manage_woocommerce')`
- No `MealsDB_Rate_Limiter::check_rate_limit` call.

If the capability is already `manage_options` and the rate limit is already present, this Part is complete — skip to Part B.

#### Step A-P2: Confirm the sibling pattern

```bash
grep -B 1 -A 5 "MealsDB_Rate_Limiter::check_rate_limit.*migration_destructive" includes/ajax/class-ajax-migration.php
```

Expected: matches inside `backfill_allowances` and `backfill_addresses`. Confirm the exact form of the rate limit check so this Part matches it.

#### Step A-P3: Confirm caller capability is `manage_options`

Tightening from `manage_woocommerce` to `manage_options` is correct ONLY if no production caller has `manage_woocommerce` without `manage_options`.

```bash
wp eval '
$users = get_users(["role__in" => ["administrator", "shop_manager"]]);
foreach ($users as $u) {
    $can_wc = user_can($u->ID, "manage_woocommerce");
    $can_opt = user_can($u->ID, "manage_options");
    echo $u->user_login . " | manage_woocommerce=" . ($can_wc ? "Y" : "N") . " | manage_options=" . ($can_opt ? "Y" : "N") . "\n";
}
'
```

Any user with `manage_woocommerce=Y` and `manage_options=N` would be locked out by this change. If there are such users AND any of them legitimately need to run this backfill, **STOP** and ask the dev whether the capability tightening is appropriate or whether the looser cap is intentional.

The expected real-world result on this site: only `administrator` users (Janet and the dev) have either capability, and they have both. The tightening is safe.

### The fix

Locate `backfill_allocation_engine` (approximately line 572). Modify the capability check and add the rate limit check immediately after.

Before:
```php
public static function backfill_allocation_engine(): void {
    $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';
    if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'mealsdb_nonce' ) ) {
        wp_send_json( [ 'success' => false, 'message' => 'Invalid request.' ] );
    }

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json( [ 'success' => false, 'message' => 'Insufficient permissions.' ], 403 );
    }

    $start_month = isset( $_REQUEST['start_month'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['start_month'] ) ) : '';
```

After:
```php
public static function backfill_allocation_engine(): void {
    $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';
    if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'mealsdb_nonce' ) ) {
        wp_send_json( [ 'success' => false, 'message' => 'Invalid request.' ] );
    }

    // Aligned with sibling backfills (backfill_allowances,
    // backfill_addresses) per directive 16 Pass A. A previous
    // version gated this endpoint with only manage_woocommerce and
    // no rate limit — every other destructive migration endpoint
    // uses manage_options plus migration_destructive (5/hr).
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json( [ 'success' => false, 'message' => 'Insufficient permissions.' ], 403 );
    }

    if ( class_exists( 'MealsDB_Rate_Limiter' )
        && ! MealsDB_Rate_Limiter::check_rate_limit( 'migration_destructive' ) ) {
        wp_send_json_error( [ 'message' => __( 'Backfill is rate-limited. Please wait before retrying.', 'meals-db' ) ], 429 );
    }

    $start_month = isset( $_REQUEST['start_month'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['start_month'] ) ) : '';
```

The rest of the method stays unchanged.

### Testing for Part A

```bash
php -l includes/ajax/class-ajax-migration.php
```

Manual smoke test:

> 1. As an administrator, run `backfill_allocation_engine` once (e.g. via the migration UI in dry-run mode). Confirm it succeeds.
> 2. Repeat 6 times in quick succession. The 6th call should return 429 "Backfill is rate-limited" because `migration_destructive` is capped at 5/hr.
> 3. (Optional) Test with a non-admin user (if any exist with manage_woocommerce but not manage_options) — expect 403.

---

## Part B: Remove dead `MealsDB_Clients::table_has_column` helper

**File:** `includes/class-clients.php`

### Context

The prior PR removed the dead cascade code in `MealsDB_Clients::delete_client` (the loop that attempted to DELETE from `meals_drafts` and `meals_ignored_conflicts` by `client_id`). That cascade was the only caller of the private static helper `MealsDB_Clients::table_has_column` (around line 239). The helper is now orphaned.

This is straightforward dead-code removal.

### Pre-flight verification

#### Step B-P1: Confirm the helper has no remaining callers

```bash
grep -n "table_has_column" includes/class-clients.php
```

Expected after the prior PR:
- One match at the method declaration (`private static function table_has_column`).
- Zero call sites inside the class.

If a call site appears, **STOP** — the helper is still in use and this Part doesn't apply.

#### Step B-P2: Confirm the helper isn't used by any other class

```bash
grep -rn "MealsDB_Clients::table_has_column" . --include="*.php"
```

The helper is `private static`, so calls from outside the class are PHP-syntactically impossible. But check anyway as a defensive measure.

Expected: zero matches.

#### Step B-P3: Check whether `MealsDB_DB` has an equivalent

There may already be a canonical `MealsDB_DB::table_has_column` helper that the dead one in `class-clients.php` was duplicating.

```bash
grep -n "function table_has_column" includes/class-db.php includes/services/*.php
```

If `MealsDB_DB::table_has_column` exists, this finding is doubly justified — the private helper was redundant with a public one. Document the finding but don't refactor other callers in this directive.

### The fix

Locate the `table_has_column` method declaration in `includes/class-clients.php` (around line 239). Delete the entire method body, including its docblock if present.

The deleted block should look something like (your exact code may differ):
```php
    /**
     * Check whether the given table has the named column.
     */
    private static function table_has_column(string $table_name, string $column): bool {
        global $wpdb;
        $columns = $wpdb->get_col($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table_name}` LIKE %s",
            $column
        ));
        return !empty($columns);
    }
```

Delete the whole method. Do NOT leave a "removed in vX.Y.Z" placeholder comment — the git history is sufficient.

### Testing for Part B

```bash
php -l includes/class-clients.php
```

Confirm no syntax errors. The method is private and unused, so no functional test is needed beyond `php -l`. As a sanity check:

```bash
grep -rn "table_has_column" includes/class-clients.php
```

Expected: zero matches.

---

## Part C: Merge stacked docblocks on `backfill_deterministic_indexes`

**File:** `includes/class-client-form.php`

### Context

The prior PR's CRIT-4 fix added a detailed BUG HISTORY docblock above `backfill_deterministic_indexes`. The original docblock (an `@return bool` stub) was not removed, leaving two adjacent docblocks. PHP tolerates this but it's ugly and the second docblock (the new one) is what reflection / IDE tools will actually use.

### Pre-flight verification

#### Step C-P1: Confirm the two docblocks are still adjacent

```bash
sed -n '1695,1735p' includes/class-client-form.php
```

Expected output: two `/** ... */` blocks separated only by whitespace or a single line, immediately before the `private static function backfill_deterministic_indexes` declaration.

The first docblock will be short (just `@return bool` and a brief description). The second will be the long BUG HISTORY block added by the prior PR.

If only one docblock is present, this Part is complete — skip to Part D.

### The fix

Delete the SHORTER (original) docblock. Keep the longer BUG HISTORY docblock — it contains all the information the short one had, plus the bug history context.

Verify the surviving docblock contains:
- A description of what the method does.
- The BUG HISTORY section.
- An `@return bool` annotation.

If the BUG HISTORY docblock somehow doesn't have `@return bool`, add it. Don't merge the two — delete the redundant short one.

After the change, there should be exactly one docblock immediately above `private static function backfill_deterministic_indexes(...)`.

### Testing for Part C

```bash
php -l includes/class-client-form.php
```

Visual confirmation:

```bash
sed -n '1695,1740p' includes/class-client-form.php
```

Expected: one `/** ... */` block, then the function declaration.

---

## Part D: Verify VAC operational constants are correct

**File:** `includes/class-operational-constants.php`

### Context

The prior PR added four VAC-related constants to `MealsDB_Operational_Constants`:
- `VAC_PER_MAIN_ALLOWANCE = 10.64` ✓ matches the v1.0.346 session context.
- `VAC_RATE_SIDE = 4.10` ✓ matches the v1.0.346 session context.
- `VAC_SIDES_CONVERSION_RATE = 4.715` — NOT in the original directive 08 spec.
- `VAC_SIDES_HST_RATE = 0.15` — NOT in the original directive 08 spec.

Additionally, the v1.0.346 audit's session context lists VAC main rate as `$9.05`, but the prior PR did NOT add a `VAC_RATE_MAIN` constant for it. This was either:
- A correct omission (the value `9.05` is derived elsewhere, not hardcoded).
- An oversight that should be corrected.

This Part verifies the two new constants against the invoice generator's actual usage, and decides whether `VAC_RATE_MAIN = 9.05` should be added.

### Pre-flight verification — DO NOT skip this Part's pre-flight

This Part involves numeric values that flow directly into VAC invoice generation. Getting any of them wrong has direct billing impact. Do not make code changes without completing these steps.

#### Step D-P1: Find every hardcoded VAC-related numeric literal

```bash
grep -rn "4.715\|0.15\|9.05" includes/services/class-invoice-generator.php
```

For each match, document:
- The line number.
- The variable name or comment context.
- What the value represents.

#### Step D-P2: Find every reference to `VAC_SIDES_CONVERSION_RATE` and `VAC_SIDES_HST_RATE`

```bash
grep -rn "VAC_SIDES_CONVERSION_RATE\|VAC_SIDES_HST_RATE" includes/ --include="*.php"
```

Expected:
- Definitions in `class-operational-constants.php`.
- Zero callers (these are new constants the prior PR added but didn't wire to consumers).

If there are callers, document which ones and verify each call produces the same numeric result it did before the constants were introduced.

#### Step D-P3: Numeric equivalence test for ALL declared constants

```bash
wp eval '
$tests = [
    "SDNB_RATE_PRIMARY_MAIN"          => [14.66, MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN],
    "SDNB_RATE_PRIMARY_MAIN_RURAL"    => [15.47, MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN_RURAL],
    "SDNB_RATE_SECONDARY_MAIN"        => [10.18, MealsDB_Operational_Constants::SDNB_RATE_SECONDARY_MAIN],
    "SDNB_RATE_SECONDARY_MAIN_RURAL"  => [10.93, MealsDB_Operational_Constants::SDNB_RATE_SECONDARY_MAIN_RURAL],
    "SDNB_RATE_SIDE"                  => [4.48,  MealsDB_Operational_Constants::SDNB_RATE_SIDE],
    "SDNB_RATE_SIDE_RURAL"            => [4.54,  MealsDB_Operational_Constants::SDNB_RATE_SIDE_RURAL],
    "VAC_PER_MAIN_ALLOWANCE"          => [10.64, MealsDB_Operational_Constants::VAC_PER_MAIN_ALLOWANCE],
    "VAC_RATE_SIDE"                   => [4.10,  MealsDB_Operational_Constants::VAC_RATE_SIDE],
    "VAC_SIDES_CONVERSION_RATE"       => [4.715, MealsDB_Operational_Constants::VAC_SIDES_CONVERSION_RATE],
    "VAC_SIDES_HST_RATE"              => [0.15,  MealsDB_Operational_Constants::VAC_SIDES_HST_RATE],
    "HST_MULTIPLIER_PRIMARY_MAIN"     => [0.672, MealsDB_Operational_Constants::HST_MULTIPLIER_PRIMARY_MAIN],
    "HST_MULTIPLIER_RURAL_MAIN"       => [0.82,  MealsDB_Operational_Constants::HST_MULTIPLIER_RURAL_MAIN],
    "HST_MULTIPLIER_SECONDARY_MAIN"   => [0.681, MealsDB_Operational_Constants::HST_MULTIPLIER_SECONDARY_MAIN],
    "PRODUCT_ID_CLIENT_CONTRIBUTION"  => [5675,  MealsDB_Operational_Constants::PRODUCT_ID_CLIENT_CONTRIBUTION],
    "PRODUCT_ID_DELIVERY_FEE"         => [4122,  MealsDB_Operational_Constants::PRODUCT_ID_DELIVERY_FEE],
    "PRODUCT_ID_OVERAGE_MAIN"         => [5056,  MealsDB_Operational_Constants::PRODUCT_ID_OVERAGE_MAIN],
    "PRODUCT_ID_OVERAGE_SIDE_NONTAX"  => [5059,  MealsDB_Operational_Constants::PRODUCT_ID_OVERAGE_SIDE_NONTAX],
    "PRODUCT_ID_OVERAGE_SIDE_TAX"     => [5180,  MealsDB_Operational_Constants::PRODUCT_ID_OVERAGE_SIDE_TAX],
    "CATEGORY_ID_MAINS"               => [35,    MealsDB_Operational_Constants::CATEGORY_ID_MAINS],
    "CATEGORY_ID_SOUP"                => [43,    MealsDB_Operational_Constants::CATEGORY_ID_SOUP],
    "CATEGORY_ID_MUFFINS"             => [37,    MealsDB_Operational_Constants::CATEGORY_ID_MUFFINS],
    "CATEGORY_ID_CEREAL"              => [23,    MealsDB_Operational_Constants::CATEGORY_ID_CEREAL],
    "CATEGORY_ID_DESSERT"             => [25,    MealsDB_Operational_Constants::CATEGORY_ID_DESSERT],
    "APETITO_CASES_PER_PALLET"        => [75,    MealsDB_Operational_Constants::APETITO_CASES_PER_PALLET],
];
$pass = 0; $fail = 0;
foreach ($tests as $name => $t) {
    if (abs($t[0] - $t[1]) < 0.0001) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $name expected " . $t[0] . " got " . $t[1] . "\n";
    }
}
echo "Passed: $pass / Failed: $fail\n";
'
```

All 24 must pass. If any fail, the constants are wrong and the prior PR introduced a regression — STOP and report.

#### Step D-P4: Locate VAC main rate usage

```bash
grep -rn "9.05\|VAC_RATE_MAIN" includes/services/class-invoice-generator.php
```

Document every match. The question to answer: is `$9.05` referenced as a literal anywhere, or is it derived from `VAC_PER_MAIN_ALLOWANCE` via some formula?

The session context lists VAC rates as "`$9.05/$4.10`". If `$9.05` appears as a literal in the invoice generator, it should be a constant. If it's derived (e.g. `$9.05 = $10.64 - some_HST_portion`), then no new constant is needed.

### The fix

This Part has up to three possible code changes depending on what pre-flight finds. Apply only the ones that pre-flight justifies.

#### Step D-F1: Wire up the two new VAC constants (if there are consumers)

If Step D-P2 found that `VAC_SIDES_CONVERSION_RATE` and `VAC_SIDES_HST_RATE` have no callers, AND Step D-P1 found literal `4.715` and `0.15` in `class-invoice-generator.php`, replace each literal with a constant reference.

If pre-flight found NO literal `4.715` or `0.15` in the invoice generator, the constants are unused. **Halt and ask the dev** whether the constants should be removed (since they have no consumers) or kept as documentation. Do not make code changes either way without confirmation.

#### Step D-F2: Add `VAC_RATE_MAIN` if Step D-P4 found a literal `9.05`

If `9.05` appears as a literal anywhere in `class-invoice-generator.php`, add to `class-operational-constants.php`:

```php
    /** VAC main meal billing rate. */
    const VAC_RATE_MAIN = 9.05;
```

Place it adjacent to the existing VAC constants. Then replace each literal `9.05` with `MealsDB_Operational_Constants::VAC_RATE_MAIN`.

If `9.05` does not appear as a literal (it's derived elsewhere), DO NOT add the constant. Document in your response why no constant was needed.

#### Step D-F3: Do nothing further

After D-F1 and D-F2, no other Part D changes. The prior PR's other constants are correct per Step D-P3's equivalence test.

### Testing for Part D

```bash
php -l includes/class-operational-constants.php
php -l includes/services/class-invoice-generator.php
```

Numeric equivalence: re-run the Step D-P3 test. All 24 (or 25 with VAC_RATE_MAIN) must still pass.

**Critical regression test**: VAC invoice equivalence.

> **Manual regression test required:**
> 1. Before applying any Part D changes: on staging, generate a sample VAC invoice for the most recent completed billing cycle. Save the PDF as `before-vac.pdf`.
> 2. Apply Part D changes.
> 3. Re-generate the same invoice. Save as `after-vac.pdf`.
> 4. Compare: all VAC line items, totals, and HST breakdowns must match exactly. Only PDF metadata (creation timestamp) may differ.
> 5. If ANY content differs, a value was changed during the refactor. STOP and investigate before committing.

---

## Out of scope for this directive

- Do NOT touch the schema ENUM for purchase order status (still includes `'counted'`). That's tracked separately and requires a `SELECT COUNT(*) WHERE status='counted'` production check first.
- Do NOT refactor `manage_woocommerce` → `manage_options` on endpoints OTHER than `backfill_allocation_engine`. Each endpoint's capability requirement should be evaluated separately, not bulk-changed.
- Do NOT add new operational constants beyond what pre-flight justifies. The constants class is for VALUES THAT EXIST IN THE CODEBASE, not aspirational documentation.
- Do NOT modify the migration `verify()` rate limit (the dev correctly removed it post-PR). It must remain unrate-limited so the chunked recursion in admin-migration.js continues working.

---

## Acceptance criteria

The directive is complete when:

**Part A:**
1. ✅ Pre-flight A-P1 / A-P2 / A-P3 are complete and documented.
2. ✅ `backfill_allocation_engine` uses `current_user_can('manage_options')` instead of `manage_woocommerce`.
3. ✅ `backfill_allocation_engine` includes the `migration_destructive` rate limit check.
4. ✅ Manual smoke test (success on first call, 429 on 6th call) passes.

**Part B:**
5. ✅ Pre-flight B-P1 / B-P2 / B-P3 are complete.
6. ✅ `MealsDB_Clients::table_has_column` is deleted.
7. ✅ `php -l includes/class-clients.php` passes.
8. ✅ Grep confirms zero remaining references.

**Part C:**
9. ✅ Pre-flight C-P1 confirms two adjacent docblocks were present.
10. ✅ Exactly one docblock remains above `backfill_deterministic_indexes`.
11. ✅ The surviving docblock contains the BUG HISTORY content and an `@return bool` annotation.
12. ✅ `php -l includes/class-client-form.php` passes.

**Part D:**
13. ✅ Pre-flight D-P1 through D-P4 are complete and documented.
14. ✅ Numeric equivalence test (D-P3) passes 24/24 (or 25/25 if VAC_RATE_MAIN was added).
15. ✅ Either D-F1 wires the new VAC constants to their consumers, OR the dev was asked to confirm removal of unused constants.
16. ✅ Either D-F2 adds `VAC_RATE_MAIN`, OR documentation explains why no constant was needed.
17. ✅ VAC invoice regression test (byte-identical output) passes.

When complete, your final response should include:
- A summary of what each Part changed (or didn't change, with justification).
- Pre-flight results for each Part.
- The numeric equivalence test output.
- VAC invoice regression test results from the dev.
- Confirmation that the migration `verify()` rate limit is still removed (it should be — this directive doesn't touch it, but a visual confirmation prevents accidental reintroduction during the work).
