# Directive: Extract Operational Constants to Single Source

**Severity:** LOW (STRUCT-4 from synthesis)
**Audit reference:** `recon-09-synthesis.md` STRUCT-4
**Target files:** New file `includes/class-operational-constants.php`; references across ~5-10 files
**Estimated scope:** New ~80-line file + updates to existing files
**Risk:** MEDIUM — touches billing-critical values; must not change any value during the refactor
**Must complete before:** v1.1 release (not pre-cutover blocking)

---

## Context

Operational values critical to SDNB/VAC billing are scattered across the codebase:

| Constant | Currently lives in | Used by |
|---|---|---|
| Client Contribution product ID (5675) | Hardcoded in queries (after directive 3, also in `MealsDB_Order_Fees`) | Fee reconciliation, invoice generation |
| Delivery Fee product ID (4122) | Hardcoded in queries | Same |
| Overage workaround product IDs (5056, 5059, 5180) | `views/settings.php` defaults + `mealsdb_overage_product_ids` option | Invoice generators |
| Category IDs (35 mains, 43 soup, 37 muffins, 23 cereal, 25 dessert) | Hardcoded in slip generator, PO algorithm | Slip generation, PO calculation |
| SDNB rates ($14.66, $10.18, $4.48, rural $15.47, $10.93, $4.54) | `class-rates.php` static arrays | Invoice generators |
| VAC rates ($9.05, $4.10) | `class-rates.php` static arrays | Invoice generators |
| HST multipliers (0.672, 0.82, 0.681) | `class-rates.php` static arrays | Invoice tax calculation |
| Apetito pallet size (75 cases) | Operator's head (not in code) | PO algorithm parameter |

The risks of the current state:
- A new dev has to grep across 10+ files to understand the operational vocabulary.
- If a rate or product ID ever changes, the change is scattered.
- Some values exist only in defaults that can be overridden via `wp_options` — a dev might miss the option as the actual source.

This directive consolidates these into one file. **It does NOT change any values.** It only changes the source location.

---

## Pre-flight verification

### Step P1: Inventory current values

Run these greps and document each match in your response:

```bash
# Product IDs
grep -rn "5675\|4122\|5056\|5059\|5180" includes/ views/ --include="*.php" 2>/dev/null | grep -v "vendor"

# Rate values (these may appear as numeric literals)
grep -rn "14.66\|10.18\|4.48\|15.47\|10.93\|4.54\|9.05\|4.10\|10.64" includes/ views/ --include="*.php" 2>/dev/null | grep -v "vendor"

# HST multipliers
grep -rn "0.672\|0.82\|0.681\|0.681" includes/ --include="*.php" 2>/dev/null | grep -v "vendor"
```

Each match is a candidate for replacement. Some matches will be:
- The current `class-rates.php` static arrays (the canonical source for rates — keep these).
- Comments documenting the values (leave as-is).
- Test fixtures (leave as-is, but consider in a separate pass).
- Backup-of-backup paths (defaults in settings.php, hardcoded fallbacks in service code).

Document the full list. Group by:
- **Type 1 — canonical source** (the one true definition). Should remain or move to the new constants file.
- **Type 2 — fallback default** (used when an option is empty). Should reference the new constants file.
- **Type 3 — hardcoded duplicate** (literally the same number written somewhere unrelated). Should reference the new constants file.

### Step P2: Confirm rates are not currently env-configurable

```bash
grep -n "MEALS_DB_SDNB_RATE\|MEALSDB_RATE" includes/ wp-config.php 2>/dev/null
```

If wp-config or any constant overrides the rates, the new constants file needs to honor that override pattern. Document the finding.

### Step P3: Read the existing `class-rates.php`

This is the closest thing to an operational-constants file already. Understand its structure:

```bash
wc -l includes/class-rates.php
grep -n "static.*=\|const " includes/class-rates.php | head -20
```

The decision in Step F1 (below) will be whether to:
- Build a new `MealsDB_Operational_Constants` class and have `MealsDB_Rates` reference it.
- Expand `MealsDB_Rates` to be the canonical home for all constants.

The synthesis recommended a new class. But if `class-rates.php` is already filling the same role, expanding it is simpler. Document your choice in your response with rationale.

---

## The fix

### Step F1: Create the constants file

Create `includes/class-operational-constants.php`:

```php
<?php
defined('ABSPATH') || exit;

/**
 * Single source of truth for operational constants that define
 * the business logic of the Meals DB plugin.
 *
 * These values were previously scattered across multiple files
 * (rates in class-rates.php, product IDs hardcoded in queries,
 * category IDs in slip generators, etc.). Consolidating them here
 * lets a new maintainer understand the operational vocabulary
 * in one place.
 *
 * IMPORTANT: Changing any value here changes plugin behavior.
 * Before modifying, confirm with the operator (Janet) that the
 * change reflects an actual operational change. Each value has a
 * confirmed source documented in the comment.
 *
 * Do NOT add general-purpose configuration here. This class is
 * specifically for values that map to real-world MealsAndMoreNB
 * operational decisions (product mapping, category mapping,
 * billing rates).
 */
class MealsDB_Operational_Constants {

    // -------------------------------------------------------------
    // WC Product IDs — fee mechanism
    // -------------------------------------------------------------
    //
    // The legacy Enzebra system used these product IDs as line
    // items to represent fees. Quick Order uses WC_Order_Item_Fee
    // instead. Both mechanisms coexist; see MealsDB_Order_Fees.
    //
    // Source: confirmed via the production WooCommerce store.

    /** Client Contribution fee product ID (legacy mechanism). */
    const PRODUCT_ID_CLIENT_CONTRIBUTION = 5675;

    /** Delivery Fee product ID (legacy mechanism). */
    const PRODUCT_ID_DELIVERY_FEE = 4122;

    // -------------------------------------------------------------
    // WC Product IDs — overage workaround (legacy, NOT extended)
    // -------------------------------------------------------------
    //
    // The legacy system used these products as line items to
    // represent "overage" (delivered meals beyond the SDNB
    // allowance). The new allocation engine tracks overage as
    // ledger entries instead. These IDs remain as fallbacks for
    // the legacy invoice generator path while clients still have
    // use_legacy_billing = 1.
    //
    // Source: legacy plugin overage workaround.

    /** Overage workaround for mains (legacy). */
    const PRODUCT_ID_OVERAGE_MAIN = 5056;

    /** Overage workaround for non-taxable Z sides (legacy). */
    const PRODUCT_ID_OVERAGE_SIDE_NONTAX = 5059;

    /** Overage workaround for taxable Z sides (legacy). */
    const PRODUCT_ID_OVERAGE_SIDE_TAX = 5180;

    // -------------------------------------------------------------
    // WC Category IDs
    // -------------------------------------------------------------
    //
    // These IDs match the production WC taxonomy. If categories
    // are ever rebuilt (e.g. import from staging), these IDs may
    // shift and need updating.
    //
    // Source: production WC product_cat taxonomy.

    const CATEGORY_ID_MAINS   = 35;
    const CATEGORY_ID_SOUP    = 43;
    const CATEGORY_ID_MUFFINS = 37;
    const CATEGORY_ID_CEREAL  = 23;
    const CATEGORY_ID_DESSERT = 25;

    // -------------------------------------------------------------
    // SDNB billing rates (CAD dollars)
    // -------------------------------------------------------------
    //
    // Source: SDNB contract; rates are reviewed annually.
    // Rural rates apply to Sussex zone clients per the SDNB
    // contract addendum.

    const SDNB_RATE_PRIMARY_MAIN          = 14.66;
    const SDNB_RATE_PRIMARY_MAIN_RURAL    = 15.47;
    const SDNB_RATE_SECONDARY_MAIN        = 10.18;
    const SDNB_RATE_SECONDARY_MAIN_RURAL  = 10.93;
    const SDNB_RATE_SIDE                  = 4.48;
    const SDNB_RATE_SIDE_RURAL            = 4.54;

    // -------------------------------------------------------------
    // VAC billing rates (CAD dollars)
    // -------------------------------------------------------------
    //
    // Source: Veterans Affairs Canada contract.
    // Per-main allowance is the dollar amount allocated per main
    // when calculating monthly VAC allowance budget.

    const VAC_RATE_MAIN              = 9.05;
    const VAC_RATE_SIDE              = 4.10;
    const VAC_PER_MAIN_ALLOWANCE     = 10.64;

    // -------------------------------------------------------------
    // HST multipliers — net portion for invoice line items
    // -------------------------------------------------------------
    //
    // SDNB invoices show base rate plus HST as separate line
    // items. These multipliers represent the NET portion (rate
    // before HST) for each gross rate value:
    //   gross_rate * multiplier = net (pre-HST)
    //   gross_rate * (1 - multiplier) = HST portion
    //
    // Source: per the SDNB invoice template format. Values are
    // historical and derived from the HST/rate breakdown the
    // legacy invoice generator produces. If HST rate changes,
    // recalculate these.

    const HST_MULTIPLIER_PRIMARY_MAIN   = 0.672;
    const HST_MULTIPLIER_RURAL_MAIN     = 0.82;
    const HST_MULTIPLIER_SECONDARY_MAIN = 0.681;

    // -------------------------------------------------------------
    // Apetito supplier configuration
    // -------------------------------------------------------------
    //
    // Source: confirmed with Janet (May 2026).

    /** Standard Apetito pallet size in cases. */
    const APETITO_CASES_PER_PALLET = 75;

    // -------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------

    /**
     * Get all fee-related product IDs as an array.
     *
     * Used by reports that need to identify fee line items
     * regardless of which fee they represent.
     *
     * @return int[]
     */
    public static function get_fee_product_ids(): array {
        return [
            self::PRODUCT_ID_CLIENT_CONTRIBUTION,
            self::PRODUCT_ID_DELIVERY_FEE,
        ];
    }

    /**
     * Get all overage workaround product IDs.
     *
     * @return int[]
     */
    public static function get_overage_product_ids(): array {
        return [
            self::PRODUCT_ID_OVERAGE_MAIN,
            self::PRODUCT_ID_OVERAGE_SIDE_NONTAX,
            self::PRODUCT_ID_OVERAGE_SIDE_TAX,
        ];
    }

    /**
     * Get the SDNB rate for a client (rural or non-rural).
     *
     * @param string $tier 'primary' or 'secondary'
     * @param bool $rural Whether the client is in a rural zone.
     * @return float Rate in dollars.
     */
    public static function get_sdnb_main_rate(string $tier, bool $rural = false): float {
        if ($tier === 'primary') {
            return $rural ? self::SDNB_RATE_PRIMARY_MAIN_RURAL : self::SDNB_RATE_PRIMARY_MAIN;
        }
        // tier === 'secondary'
        return $rural ? self::SDNB_RATE_SECONDARY_MAIN_RURAL : self::SDNB_RATE_SECONDARY_MAIN;
    }

    /**
     * Get the SDNB side rate.
     *
     * @param bool $rural Whether the client is in a rural zone.
     * @return float Rate in dollars.
     */
    public static function get_sdnb_side_rate(bool $rural = false): float {
        return $rural ? self::SDNB_RATE_SIDE_RURAL : self::SDNB_RATE_SIDE;
    }
}
```

### Step F2: Update `class-rates.php` to reference the constants

Open `includes/class-rates.php`. The existing rate arrays should reference the new constants:

Before:
```php
private static $sdnb_rates = [
    'primary_main' => 14.66,
    'secondary_main' => 10.18,
    'side' => 4.48,
    // ...
];
```

After:
```php
private static $sdnb_rates = null; // Lazy-init via get_sdnb_rates()

private static function get_sdnb_rates(): array {
    if (self::$sdnb_rates === null) {
        self::$sdnb_rates = [
            'primary_main'         => MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN,
            'primary_main_rural'   => MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN_RURAL,
            'secondary_main'       => MealsDB_Operational_Constants::SDNB_RATE_SECONDARY_MAIN,
            'secondary_main_rural' => MealsDB_Operational_Constants::SDNB_RATE_SECONDARY_MAIN_RURAL,
            'side'                 => MealsDB_Operational_Constants::SDNB_RATE_SIDE,
            'side_rural'           => MealsDB_Operational_Constants::SDNB_RATE_SIDE_RURAL,
        ];
    }
    return self::$sdnb_rates;
}
```

Similarly for `$vac_rates` and HST multipliers.

Update all consumers within `class-rates.php` to call the getter method instead of the static array.

**Verify:** the numeric values must be IDENTICAL after the refactor. Run the tests below to confirm no numeric drift.

### Step F3: Update other consumer files

For each Type 3 hardcoded duplicate found in Step P1, replace the numeric literal with the constant reference.

Examples (your actual changes will differ based on the grep results):

In `views/settings.php` default values:

Before:
```php
$default_overage_mains = 5056;
```

After:
```php
$default_overage_mains = MealsDB_Operational_Constants::PRODUCT_ID_OVERAGE_MAIN;
```

In `MealsDB_Order_Fees` (created by directive 3):

Before:
```php
if ($product_id === 5675) {
    $contribution += $total;
} elseif ($product_id === 4122) {
    $delivery_fee += $total;
}
```

After:
```php
if ($product_id === MealsDB_Operational_Constants::PRODUCT_ID_CLIENT_CONTRIBUTION) {
    $contribution += $total;
} elseif ($product_id === MealsDB_Operational_Constants::PRODUCT_ID_DELIVERY_FEE) {
    $delivery_fee += $total;
}
```

Continue for each match. **Do each file as a separate small commit.**

### Step F4: Add the file to the autoloader

The autoloader (`class-autoloader.php`) walks `includes/` by default, so the new file should be picked up automatically. Verify by:

```bash
wp eval 'var_dump(class_exists("MealsDB_Operational_Constants"));'
```

Expected output: `bool(true)`.

If `false`, the autoloader has a directory list that doesn't include `includes/` — investigate. (Per audit, the default list does include `includes/`.)

---

## Testing

### Step T1: Numeric equivalence test

The most critical test: confirm no value changed during the refactor.

```bash
wp eval '
$tests = [
    ["before" => 14.66, "after" => MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN],
    ["before" => 10.18, "after" => MealsDB_Operational_Constants::SDNB_RATE_SECONDARY_MAIN],
    ["before" => 4.48,  "after" => MealsDB_Operational_Constants::SDNB_RATE_SIDE],
    ["before" => 15.47, "after" => MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN_RURAL],
    ["before" => 10.93, "after" => MealsDB_Operational_Constants::SDNB_RATE_SECONDARY_MAIN_RURAL],
    ["before" => 4.54,  "after" => MealsDB_Operational_Constants::SDNB_RATE_SIDE_RURAL],
    ["before" => 9.05,  "after" => MealsDB_Operational_Constants::VAC_RATE_MAIN],
    ["before" => 4.10,  "after" => MealsDB_Operational_Constants::VAC_RATE_SIDE],
    ["before" => 10.64, "after" => MealsDB_Operational_Constants::VAC_PER_MAIN_ALLOWANCE],
    ["before" => 0.672, "after" => MealsDB_Operational_Constants::HST_MULTIPLIER_PRIMARY_MAIN],
    ["before" => 0.82,  "after" => MealsDB_Operational_Constants::HST_MULTIPLIER_RURAL_MAIN],
    ["before" => 0.681, "after" => MealsDB_Operational_Constants::HST_MULTIPLIER_SECONDARY_MAIN],
    ["before" => 5675,  "after" => MealsDB_Operational_Constants::PRODUCT_ID_CLIENT_CONTRIBUTION],
    ["before" => 4122,  "after" => MealsDB_Operational_Constants::PRODUCT_ID_DELIVERY_FEE],
    ["before" => 5056,  "after" => MealsDB_Operational_Constants::PRODUCT_ID_OVERAGE_MAIN],
    ["before" => 5059,  "after" => MealsDB_Operational_Constants::PRODUCT_ID_OVERAGE_SIDE_NONTAX],
    ["before" => 5180,  "after" => MealsDB_Operational_Constants::PRODUCT_ID_OVERAGE_SIDE_TAX],
    ["before" => 75,    "after" => MealsDB_Operational_Constants::APETITO_CASES_PER_PALLET],
];
$pass = 0;
$fail = 0;
foreach ($tests as $t) {
    if (abs($t["before"] - $t["after"]) < 0.0001) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: expected {$t[\"before\"]}, got {$t[\"after\"]}\n";
    }
}
echo "Passed: $pass / Failed: $fail\n";
'
```

All must pass. If any fail, the constant has the wrong value — fix immediately.

### Step T2: Invoice regression test

Generate a sample invoice on staging before and after the refactor. The PDF/HTML output must be byte-identical (or at most differ in timestamp metadata, which is acceptable).

> **Manual test required:**
> 1. Before pushing the constants change: generate a sample SDNB invoice for January 2025. Save the PDF as `before.pdf`.
> 2. Push the constants change.
> 3. Re-generate the same invoice. Save as `after.pdf`.
> 4. Visually compare. The body content (rates, totals, line items) must match exactly. Only the generation timestamp should differ.

### Step T3: Static check

```bash
php -l includes/class-operational-constants.php
php -l includes/class-rates.php
# Plus any other files you modified.
```

All must pass.

### Step T4: Grep for remaining hardcoded literals

```bash
# After refactor, these should only appear in test fixtures, comments, or the constants file itself.
grep -rn "5675\|4122\|5056\|5059\|5180" includes/ views/ --include="*.php" 2>/dev/null | grep -v "vendor" | grep -v "class-operational-constants.php"

grep -rn "14.66\|10.18\|4.48" includes/ views/ --include="*.php" 2>/dev/null | grep -v "vendor" | grep -v "class-operational-constants.php" | grep -v "class-rates.php"
```

Each remaining match needs justification (comment, test fixture, etc.). Document.

---

## Out of scope for this directive

- Do NOT add general configuration to the constants class. This is operational values only.
- Do NOT make any constant filterable via WP hooks. The values represent real-world contracts and should not be filter-overridable at runtime.
- Do NOT make constants configurable via wp-config.php. Same reason.
- Do NOT change ANY numeric value. The refactor is move-only.
- Do NOT remove the `mealsdb_overage_product_ids` option. That stays as an operator-configurable override; the constants are the defaults.
- Do NOT touch the Apetito excluded categories option name (`mealsdb_appetito_excluded_categories` — the misspelling) in this directive. That's a separate cleanup.

---

## Acceptance criteria

The directive is complete when:

1. ✅ Pre-flight steps P1, P2, P3 are complete and documented.
2. ✅ `includes/class-operational-constants.php` is created with all constants and helper methods.
3. ✅ `class-rates.php` references the constants instead of hardcoding values.
4. ✅ All Type 3 hardcoded duplicates are replaced with constant references.
5. ✅ The numeric equivalence test (T1) passes 18/18.
6. ✅ The invoice regression test (T2) passes (byte-identical output).
7. ✅ `php -l` passes on all modified files.
8. ✅ The autoloader picks up the new class (T4 verification).

When complete, your final response should include:
- A list of all files modified.
- A diff summary for each.
- The numeric equivalence test output.
- The invoice regression test confirmation from the dev.
- Any remaining hardcoded literals found in the post-refactor grep (each with justification).
