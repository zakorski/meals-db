<?php
/**
 * Tests for MealsDB_Invoice_Generator::compute_vac_row_derived() — the shared
 * VAC per-row derived-money function (directive INVOICE-DRAFT-SPREADSHEET 3a).
 *
 * This is the SINGLE source of truth the VAC serializer (finalize) AND the
 * draft grid (render + live recompute) both call. It takes a stored VAC
 * `current` row and returns the derived figures in integer cents / counts:
 *   vet_mains_cost_cents      = bill_mains × bill_rate
 *   vac_total_cents           = vet_mains_cost + fold_amount + fold_hst
 *   remaining_sides           = max(0, sides_allowance − allocated_tax_sides)
 *   allowance_remaining_cents = max(0, monthly_allowance − vet_mains_cost)
 *
 * fold_amount / fold_hst are INPUTS (hand-entered, operator decision
 * 2026-06-29) — the fn READS them, it never derives them. Sides are NOT
 * billed: they appear only in the informational remaining_sides figure.
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

/** A VAC row in the shape build_vac_draft_rows produces. */
function vac_row(array $over = []): array {
    return array_merge([
        'allocated_mains' => 12, 'allocated_tax_sides' => 5, 'allocated_nontax_sides' => 2,
        'bill_mains' => 12, 'bill_rate' => 9.05, 'fold_amount' => 0.0, 'fold_hst' => 0.0,
        'info_mains_allowance' => 31, 'info_sides_allowance' => 31,
        'info_monthly_allowance_cents' => 33000, 'info_sides_cost_cents' => 2870,
    ], $over);
}

// --- Clean veteran: mains-only, no fold. -----------------------------------
$d = MealsDB_Invoice_Generator::compute_vac_row_derived(vac_row());
chk($d['vet_mains_cost_cents'],      10860, 'clean: vet_mains_cost = 12 × 9.05');
chk($d['vac_total_cents'],           10860, 'clean: vac_total = mains-only (fold 0)');
chk($d['remaining_sides'],              26, 'clean: remaining_sides = 31 − 5 tax sides');
chk($d['allowance_remaining_cents'], 22140, 'clean: allowance_remaining = 33000 − 10860');

// --- Folded veteran: fold_amount + fold_hst flow into the total. -----------
$f = MealsDB_Invoice_Generator::compute_vac_row_derived(vac_row(['fold_amount' => 28.70, 'fold_hst' => 3.08]));
chk($f['vet_mains_cost_cents'], 10860, 'folded: mains cost unchanged by fold');
chk($f['vac_total_cents'],      14038, 'folded: vac_total = 10860 + 2870 + 308');

// --- allowance_remaining clamps at 0 when mains cost exceeds the allowance. -
$c = MealsDB_Invoice_Generator::compute_vac_row_derived(vac_row(['info_monthly_allowance_cents' => 5000]));
chk($c['allowance_remaining_cents'], 0, 'clamp: allowance_remaining never negative');

// --- remaining_sides clamps at 0 when tax sides exceed the allowance. ------
$s = MealsDB_Invoice_Generator::compute_vac_row_derived(vac_row(['allocated_tax_sides' => 40]));
chk($s['remaining_sides'], 0, 'clamp: remaining_sides never negative');

// --- Fallback: a bare phase-2 row (no bill_*) falls back to allocated_*. ----
$b = MealsDB_Invoice_Generator::compute_vac_row_derived([
    'allocated_mains' => 10, 'resolved_rate' => 11.40,
    'allocated_tax_sides' => 0, 'allocated_nontax_sides' => 0,
    'info_sides_allowance' => 0, 'info_monthly_allowance_cents' => 0,
]);
chk($b['vet_mains_cost_cents'], 11400, 'fallback: bill_mains→allocated_mains, bill_rate→resolved_rate');

echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
