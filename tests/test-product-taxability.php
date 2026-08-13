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
 *  2. MealsDB_Product_Display_Sync::taxable_for_slugs() — the ONLY taxability
 *     decision for sides now that the per-product "Taxable" checkbox and its
 *     `taxable_overridden` flag are removed (DIRECTIVE-remaining-items-
 *     consolidated ITEM 3). Taxability is purely category-derived; the removed
 *     override path (and its same-request clobber bug) is gone by construction.
 *     The old resolve_taxable_override() must no longer exist.
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

// --- ITEM 3: the override path is removed ---------------------------------
// The vestigial per-product checkbox + taxable_overridden flag are gone;
// resolve_taxable_override() must not be reintroduced.
eq(method_exists($P, 'resolve_taxable_override'), false, 'resolve_taxable_override() is removed');

// --- taxable_for_slugs(): purely category-derived side taxability ---------
$S = 'MealsDB_Product_Display_Sync';
eq($S::taxable_for_slugs(['dessert']),          1, 'dessert -> taxable');
eq($S::taxable_for_slugs(['muffin']),           1, 'muffin -> taxable');
eq($S::taxable_for_slugs(['cereal']),           0, 'cereal -> non-taxable');
eq($S::taxable_for_slugs(['soup']),             0, 'soup -> non-taxable');
eq($S::taxable_for_slugs(['thickened']),        0, 'thickened -> non-taxable');
// A product carrying any taxable-side category is taxable.
eq($S::taxable_for_slugs(['cereal', 'dessert']), 1, 'mixed with a taxable category -> taxable');
// No categories at all -> non-taxable.
eq($S::taxable_for_slugs([]),                   0, 'no categories -> non-taxable');

$total = $passed + count($failures);
echo "Ran {$total} checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
