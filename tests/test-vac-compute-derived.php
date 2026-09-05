<?php
/**
 * DIRECTIVE 5 — MealsDB_Invoice_Generator::compute_vac_row_derived().
 *
 * VAC is now billed mains + sides with a computed HST and a DVA-coverage dollar
 * ceiling; the old fold_amount/fold_hst inputs are GONE.
 *
 *   mains_value   = bill_mains × bill_rate
 *   sides_value   = (tax + nontax sides) × side rate
 *   hst           = taxable sides × side rate × hst rate   (mains never taxed)
 *   vac_total     = mains_value + sides_value + hst
 *   ceiling       = permitted_mains × DVA coverage rate ($11.14)
 *   over_ceiling  = vac_total > ceiling
 *
 * Fixtures are the directive's real August-2026 figures at $9.50 / $4.25 / 15%
 * HST with the $11.14 coverage ceiling.
 *
 * Run: php tests/test-vac-compute-derived.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!function_exists('__')) { function __($t, $d = null) { return $t; } }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($got, $exp, string $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($exp, true), var_export($got, true));
}

/** A VAC row carrying the frozen Directive-5 rates ($9.50 / $4.25 / 15% / $11.14). */
function vac_row(array $over = []): array {
    return array_merge([
        'bill_mains' => 0, 'bill_rate' => 9.50,
        'allocated_tax_sides' => 0, 'allocated_nontax_sides' => 0,
        'info_mains_allowance' => 31, 'info_sides_allowance' => 0,
        'vac_side_rate' => 4.25, 'vac_coverage_rate' => 11.14, 'vac_hst_rate' => 0.15,
    ], $over);
}

// --- Julien Robichaud: 22 mains + 10 taxable sides, permitted 22 → OVER. ----
$r = MealsDB_Invoice_Generator::compute_vac_row_derived(vac_row([
    'bill_mains' => 22, 'allocated_tax_sides' => 10, 'info_mains_allowance' => 22,
]));
chk($r['vet_mains_cost_cents'], 20900, 'Robichaud: mains 22 × 9.50 = 209.00');
chk($r['sides_value_cents'],     4250, 'Robichaud: sides 10 × 4.25 = 42.50');
chk($r['hst_cents'],              638, 'Robichaud: HST 10 × 4.25 × 15% = 6.38');
chk($r['vac_total_cents'],      25788, 'Robichaud: total = 257.88');
chk($r['ceiling_cents'],        24508, 'Robichaud: ceiling 22 × 11.14 = 245.08');
chk($r['over_ceiling'],          true, 'Robichaud: OVER the ceiling');
chk($r['total_items'],             32, 'Robichaud: total items = 22 + 10');

// --- David Lavender: 31 mains + 10 taxable sides, permitted 31 → the sentinel,
//     clears by $1.96 at the $4.25 side rate. -------------------------------
$l = MealsDB_Invoice_Generator::compute_vac_row_derived(vac_row([
    'bill_mains' => 31, 'allocated_tax_sides' => 10, 'info_mains_allowance' => 31,
]));
chk($l['vac_total_cents'], 34338, 'Lavender: total = 343.38');
chk($l['ceiling_cents'],   34534, 'Lavender: ceiling 31 × 11.14 = 345.34');
chk($l['over_ceiling'],    false, 'Lavender: UNDER by $1.96');

// --- Janet's worked example: 7 mains + 2 NON-taxable sides = $75.00 under
//     a 7 × $11.14 = $77.98 ceiling; HST = 0. ---------------------------------
$j = MealsDB_Invoice_Generator::compute_vac_row_derived(vac_row([
    'bill_mains' => 7, 'allocated_nontax_sides' => 2, 'info_mains_allowance' => 7,
]));
chk($j['hst_cents'],         0, "Janet: non-taxable sides → HST 0");
chk($j['vac_total_cents'], 7500, 'Janet: 66.50 + 8.50 = 75.00');
chk($j['ceiling_cents'],   7798, 'Janet: ceiling 7 × 11.14 = 77.98');
chk($j['over_ceiling'],   false, 'Janet: under, no flag');

// --- ITEM 2: leftover fold_* keys are ignored (never summed). ---------------
$noFold = MealsDB_Invoice_Generator::compute_vac_row_derived(vac_row([
    'bill_mains' => 7, 'fold_amount' => 99.99, 'fold_hst' => 12.34,
]));
chk($noFold['vac_total_cents'], 6650, 'fold_* ignored: total = mains only (7 × 9.50)');

// --- Fallback: bill_* absent → allocated_mains / resolved_rate. -------------
$b = MealsDB_Invoice_Generator::compute_vac_row_derived([
    'allocated_mains' => 10, 'resolved_rate' => 9.50,
    'vac_side_rate' => 4.25, 'vac_coverage_rate' => 11.14, 'vac_hst_rate' => 0.15,
    'info_mains_allowance' => 10,
]);
chk($b['vet_mains_cost_cents'], 9500, 'fallback: allocated_mains × resolved_rate');

echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
