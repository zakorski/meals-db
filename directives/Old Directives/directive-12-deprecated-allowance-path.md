# Directive: Drop Deprecated SDNB Allowance Calculation Path

**Severity:** LOW maintenance, MEDIUM if rules ever change (STRUCT-2 from synthesis)
**Audit reference:** `recon-02-billing-path.md` lines 485-505; `recon-09-synthesis.md` STRUCT-2
**Target file:** `includes/class-invoice-generator.php`
**Estimated scope:** ~200-400 lines deleted (depends on what `get_allowance_data_for_clients` includes)
**Risk:** MEDIUM — touches billing calculation code; must not change any active code path
**Must complete before:** v1.1 release; only AFTER the shadow-mode trial confirms the allocation engine produces correct numbers for all client types

---

## Context

`MealsDB_Invoice_Generator` has TWO implementations of SDNB allowance calculation:

1. **`get_allocation_based_billing()`** — Uses `MealsDB_Allocation_Engine::calculate_permitted_for_month()`. The canonical path. Used when `use_legacy_billing = 0`.

2. **`get_allowance_data_for_clients()`** — Marked DEPRECATED in code comments. Replicates the same allowance logic as the allocation engine (mains/sides per requisition_period, 5-week month corrections, etc.) but as an inline static method. Used when `use_legacy_billing = 1`.

The deprecation comment in the file:

> "Use get_allocation_based_billing() for allowance-based generators. Retained as fallback for historical months before the allocation engine."

The risks of keeping both:
- If the allowance rules change (5-week month handling, allowance formulas, anything), TWO places must be updated.
- Updating one and forgetting the other produces silent divergence — the same client gets different bills under the two paths.
- `get_allowance_data_for_clients` accepts a `$weeks_in_month = 4` parameter that is never used (dead parameter), suggesting partial refactor abandoned mid-way.

**This directive removes the deprecated path** AFTER the shadow-mode trial confirms parity. It is NOT safe to do this before the trial because the trial may surface bugs in the new path that require falling back to the old path while fixes are developed.

---

## Pre-flight verification

### Step P1: Confirm the shadow-mode trial is complete and parity is established

**This is a gate, not a verification.** Do not proceed past this step until the dev has confirmed:

1. The shadow-mode trial has run for at least one full SDNB billing cycle.
2. Invoice totals from `get_allocation_based_billing` match the legacy system within acceptable tolerance for ALL active SDNB clients.
3. Any discrepancies have been investigated and reconciled (typically traced to known operational quirks like the $17K under-billing or fee mechanism differences).
4. The dev is willing to accept that the deprecated path will no longer be available as a fallback.

**Without explicit dev confirmation of these four conditions, STOP and do not proceed.**

### Step P2: Confirm all current callers use the canonical path

Find every caller of the invoice generator:

```bash
grep -rn "MealsDB_Invoice_Generator::" includes/ views/ --include="*.php"
```

For each caller, determine which method it ends up calling. Document:
- The caller (file + line).
- The method called.
- Whether the caller passes `use_legacy_billing` filter explicitly or relies on per-client value.

Common callers:
- `views/invoices.php` (Government invoice generation view)
- `class-ajax-invoice.php` (AJAX wrapper)
- Possibly batch generation paths

### Step P3: Check production client distribution

```bash
wp db query "SELECT use_legacy_billing, client_type, COUNT(*) AS n
  FROM 2xnIt_meals_clients
  WHERE active = 1
  GROUP BY use_legacy_billing, client_type
  ORDER BY client_type, use_legacy_billing"
```

If ANY active SDNB or Veteran clients still have `use_legacy_billing = 1`, the deprecated path is still in use. **STOP** and report. The deprecated path can only be removed after every client has been migrated to `use_legacy_billing = 0`.

### Step P4: Read both implementations in full

Open `class-invoice-generator.php`. Locate:
- `get_allowance_data_for_clients` (the deprecated method).
- `get_allocation_based_billing` (the canonical method).
- Any routing logic that selects between them (likely based on `use_legacy_billing` value).

Read both methods in full. Document:
- The exact return shape of each (they should be equivalent).
- Where `get_allowance_data_for_clients` is called from within the file.
- The `$weeks_in_month` parameter — confirm it's unused (line ~619 per audit).

---

## The fix

### Step F1: Remove the routing branch

Locate the routing logic that selects between the two implementations. Likely shape:

```php
if ($client_uses_legacy_billing) {
    $allowance_data = self::get_allowance_data_for_clients(...);
} else {
    $allowance_data = self::get_allocation_based_billing(...);
}
```

Replace with the canonical path only:

```php
$allowance_data = self::get_allocation_based_billing(...);
```

Preserve the parameter list. The canonical method's signature should accept the same inputs the routing branch was passing.

### Step F2: Delete `get_allowance_data_for_clients`

Delete the entire method body. This is likely the largest single deletion (~200-300 lines per the audit's reading of lines 214-414).

**Before deleting**, copy the method to a separate text file as a backup. Save it as `directives-archive/deprecated-get-allowance-data-for-clients.php` in your local working directory (NOT in the plugin tree). This is for forensic reference if the deletion needs to be partially reverted during a hotfix.

The deletion is the body of the method only. Any helper methods it calls internally need separate analysis:
- If the helper is ONLY called by `get_allowance_data_for_clients`, delete it too.
- If the helper is called by other methods, keep it.

For each helper called by the deprecated method, run:

```bash
grep -n "self::<helper_method_name>\|::<helper_method_name>" includes/class-invoice-generator.php
```

If the grep returns only matches inside the deprecated method (now deleted), the helper is also dead.

### Step F3: Update comments and docblocks

If the file's top docblock mentions "two allowance calculation paths" or similar, update it to reflect the single canonical path.

If the canonical method's docblock says "use this instead of `get_allowance_data_for_clients`" or similar, update it to remove the obsolete reference.

Add a removal note above the canonical method:

```php
/**
 * <existing docblock>
 *
 * HISTORY: A deprecated alternative method `get_allowance_data_for_clients`
 * existed alongside this one, used when use_legacy_billing = 1. After all
 * clients were migrated to the allocation engine and shadow-mode trial
 * confirmed parity, the deprecated method was removed in <version>.
 * If you need to inspect the old logic for forensic reasons, see git
 * history or directives-archive/deprecated-get-allowance-data-for-clients.php
 * in the dev's local archive.
 */
```

### Step F4: Update `use_legacy_billing` semantics

Per session context, the `use_legacy_billing` column is the cutover switch. After this directive:
- The column still exists.
- All active clients should have `use_legacy_billing = 0`.
- Reading code paths that branch on this value need review — most can be deleted.

For this directive, ONLY remove the deprecated allowance calc routing. Do NOT delete the `use_legacy_billing` column or the `MealsDB_Invoice_Page::get_available_zones` filter (directive 5 already handles that one). The column may be referenced elsewhere (admin filters, reports). Leave it for now and address in a follow-up directive if desired.

Add a TODO comment in the code where the routing used to be:

```php
// TODO: With the deprecated allowance path removed, the use_legacy_billing
// column has reduced utility. A follow-up directive may evaluate removing
// it entirely once all consumer code paths are reviewed.
```

---

## Testing

### Step T1: Static check

```bash
php -l includes/class-invoice-generator.php
```

### Step T2: Regression — invoice equivalence

This is the critical test. Generate the same invoice before and after this directive's changes. They MUST be byte-identical (modulo timestamp metadata in the PDF generation).

> **Manual regression test required:**
> 1. Before applying this directive: on staging, generate a sample SDNB invoice for the most recent completed billing cycle. Save as `before-deprecation.pdf`.
> 2. Apply this directive's changes.
> 3. Re-generate the same invoice. Save as `after-deprecation.pdf`.
> 4. Compare the two PDFs:
>    - All line items must match.
>    - Totals must match.
>    - Tax breakdowns must match.
>    - Only PDF metadata (creation timestamp, PDF version) may differ.
> 5. If ANY content differs, the deprecated method had logic the canonical method doesn't. STOP and investigate before committing.

### Step T3: Multi-client regression

Generate invoices for 5+ different SDNB clients across different requisition periods (week/2week/month, different mains/sides allowances, different rural status). Repeat the byte-identical comparison for each.

If ANY client produces different output, the canonical method has a bug or missing edge case that the deprecated method handled. Document and STOP.

### Step T4: Grep for orphaned references

```bash
grep -rn "get_allowance_data_for_clients" . --include="*.php"
```

Expected: zero matches. If any remain, they need updating to call the canonical method.

---

## Out of scope for this directive

- Do NOT remove the `use_legacy_billing` column from the schema. That requires a migration and broader analysis.
- Do NOT remove `MealsDB_Allocation_Engine` or modify the canonical path. This directive only removes the deprecated alternative.
- Do NOT modify the VAC billing path. VAC has its own calculation in `class-invoice-generator.php` and is unaffected.
- Do NOT modify the fee mechanism logic. That's directive 03's scope.

---

## Acceptance criteria

The directive is complete when:

1. ✅ Pre-flight gate P1 is explicitly confirmed by the dev.
2. ✅ P2 produces a complete caller inventory.
3. ✅ P3 confirms zero clients still on `use_legacy_billing = 1`.
4. ✅ The deprecated method is deleted.
5. ✅ The routing branch is removed.
6. ✅ Any helpers exclusive to the deprecated method are also deleted.
7. ✅ T1 (php -l) passes.
8. ✅ T2 (single-client invoice regression) passes — byte-identical output.
9. ✅ T3 (multi-client regression across 5+ clients) passes.
10. ✅ T4 (orphan reference grep) returns zero.

When complete, your final response should include:
- Confirmation of pre-flight gate.
- Total lines deleted.
- List of helper methods also removed (if any).
- T2 and T3 regression test results from the dev.
