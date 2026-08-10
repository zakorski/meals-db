<?php
/**
 * Tests for the products/HST taxability cluster fix (audit-2026-08 B10).
 *
 * Two pure pieces are unit-tested here:
 *
 *  1. MealsDB_Operational_Constants::side_category_slugs() /
 *     ::taxable_side_category_slugs() — the SINGLE source of truth for the
 *     taxable-side rule, replacing the three hardcoded copies that used to
 *     live in class-wc-product-tab.php and class-product-display-sync.php
 *     (a rename/addition in one and not the others silently under-reported
 *     HST on the government CSV).
 *
 *  2. MealsDB_Products::resolve_taxable_override() — the per-product override
 *     decision. The operator's "Taxable" checkbox is now honoured, but a value
 *     is only marked as an OVERRIDE when it DIVERGES from the category-derived
 *     default; re-matching the default releases it back to category tracking
 *     (so a later category change re-derives normally, and the display sync
 *     does not clobber a genuine override).
 *
 * Run: php tests/test-product-taxability.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function eq($actual, $expected, string $label): void {
    global $failures, $passed;
    if ($actual === $expected) { $passed++; return; }
    $failures[] = sprintf("%s\n  expected: %s\n  actual:   %s",
        $label, var_export($expected, true), var_export($actual, true));
}

$C = 'MealsDB_Operational_Constants';
$P = 'MealsDB_Products';

// --- Single source of truth: side + taxable-side category slugs -----------
$sides   = $C::side_category_slugs();
$taxable = $C::taxable_side_category_slugs();

eq(in_array('dessert', $sides, true), true, 'dessert is a side category');
eq(in_array('muffin', $sides, true),  true, 'muffin is a side category');
eq(in_array('cereal', $sides, true),  true, 'cereal is a side category');
eq(in_array('soup', $sides, true),    true, 'soup is a side category');
eq(in_array('thickened', $sides, true), true, 'thickened is a side category');

eq($taxable, ['dessert', 'muffin'], 'taxable sides are exactly dessert + muffin');

// The taxable set must be a SUBSET of the side set (else a taxable category
// that isn't recognised as a side would never be reached).
eq(array_values(array_diff($taxable, $sides)), [], 'taxable sides are all sides');

// Non-taxable sides really are non-taxable.
eq(in_array('cereal', $taxable, true), false, 'cereal is a non-taxable side');
eq(in_array('soup', $taxable, true),   false, 'soup is a non-taxable side');
eq(in_array('thickened', $taxable, true), false, 'thickened is a non-taxable side');

// --- resolve_taxable_override(): meals are never taxed --------------------
// Mains are never taxed and the control is disabled for meals — a stray
// posted "checked" must not tax a meal, and it is never an override.
eq($P::resolve_taxable_override('meal', true,  1), ['taxable' => 0, 'overridden' => 0], 'meal + checked  -> not taxed, not overridden');
eq($P::resolve_taxable_override('meal', false, 0), ['taxable' => 0, 'overridden' => 0], 'meal + unchecked -> not taxed');

// --- resolve_taxable_override(): side matching the category default -------
// Checkbox agrees with the derived value => NOT an override (stays category-tracked).
eq($P::resolve_taxable_override('side', true,  1), ['taxable' => 1, 'overridden' => 0], 'side checked, derived taxable -> taxed, not overridden');
eq($P::resolve_taxable_override('side', false, 0), ['taxable' => 0, 'overridden' => 0], 'side unchecked, derived non-taxable -> not taxed, not overridden');

// --- resolve_taxable_override(): side DIVERGING from the default ----------
// The operator forces a value different from the category => override flagged.
eq($P::resolve_taxable_override('side', true,  0), ['taxable' => 1, 'overridden' => 1], 'side checked on a non-taxable category -> override');
eq($P::resolve_taxable_override('side', false, 1), ['taxable' => 0, 'overridden' => 1], 'side unchecked on a taxable category -> override');

// --- resolve_taxable_override(): fee/other behave like non-meals ----------
eq($P::resolve_taxable_override('fee',   true,  0), ['taxable' => 1, 'overridden' => 1], 'fee checked on non-taxable default -> override');
eq($P::resolve_taxable_override('other', false, 0), ['taxable' => 0, 'overridden' => 0], 'other unchecked, non-taxable default -> not overridden');

$total = $passed + count($failures);
echo "Ran {$total} checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
