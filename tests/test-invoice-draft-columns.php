<?php
/**
 * Tests for MealsDB_Invoice_Draft_Page::column_map() — the curated per-pipeline
 * column lists that replace the array_keys dump (directive
 * INVOICE-DRAFT-SPREADSHEET Part 1).
 *
 * The VAC map is the load-bearing decision from the 2026-06-29 operator review:
 *   - bill_mains / bill_rate / fold_amount / fold_hst are EDITABLE inputs
 *     (fold_amount/fold_hst hand-entered).
 *   - vet_mains_cost / vac_total are DERIVED (read-only), keyed into
 *     compute_vac_row_derived's output — NEVER editable.
 *   - The dead client contribution and the mock's "VAC Portion" are NOT columns
 *     (contribution is pulled but never billed; surfacing it would lie).
 * Pipelines without a curated map yet return null (caller falls back to the raw
 * array_keys grid), so SDNB drafts keep rendering unchanged.
 *
 * Run: php tests/test-invoice-draft-columns.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!function_exists('__')) { function __($t, $d = null) { return $t; } }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
require_once __DIR__ . '/../includes/admin/class-invoice-draft-page.php';

$failures = []; $passed = 0;
function chk($got, $exp, string $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($exp, true), var_export($got, true));
}
function chk_true($cond, string $label) {
    global $failures, $passed;
    if ($cond === true) { $passed++; return; }
    $failures[] = "FAIL: $label (expected true)";
}

$vac = MealsDB_Invoice_Draft_Page::column_map('vac');
chk_true(is_array($vac), 'VAC map is an array');

// Index entries by field for assertions.
$by_field = [];
foreach ($vac as $entry) { $by_field[$entry['field']] = $entry; }

// Editable inputs present, correctly typed.
chk($by_field['bill_mains']['type']  ?? null, 'input-int',   'bill_mains is input-int');
chk($by_field['bill_rate']['type']   ?? null, 'input-money', 'bill_rate is input-money (editable — operator decision)');
chk($by_field['fold_amount']['type'] ?? null, 'input-money', 'fold_amount is input-money (hand-entered)');
chk($by_field['fold_hst']['type']    ?? null, 'input-money', 'fold_hst is input-money (hand-entered)');

// Derived columns present, read-only, keyed into compute_vac_row_derived.
chk($by_field['vet_mains_cost']['type'] ?? null, 'derived-money', 'vet_mains_cost is derived-money');
chk($by_field['vac_total']['type']      ?? null, 'derived-money', 'vac_total is derived-money');
$derived_keys = array_keys(MealsDB_Invoice_Generator::compute_vac_row_derived([]));
chk_true(in_array($by_field['vet_mains_cost']['derived_key'] ?? '', $derived_keys, true), 'vet_mains_cost.derived_key is a real compute output');
chk_true(in_array($by_field['vac_total']['derived_key'] ?? '', $derived_keys, true), 'vac_total.derived_key is a real compute output');

// The dead contribution / mock VAC-Portion are NOT surfaced as columns.
chk_true(!isset($by_field['contribution_cents']),  'contribution_cents is NOT a curated column');
chk_true(!isset($by_field['client_contribution']), 'client_contribution is NOT a curated column');
chk_true(!isset($by_field['vac_portion']),         'vac_portion is NOT a curated column');

// Pipelines without a curated map fall back (null) — SDNB unchanged this pass.
chk(MealsDB_Invoice_Draft_Page::column_map('sdnb_legacy'),    null, 'sdnb_legacy has no curated map yet (fallback)');
chk(MealsDB_Invoice_Draft_Page::column_map('sdnb_new_portal'), null, 'sdnb_new_portal has no curated map yet (fallback)');
chk(MealsDB_Invoice_Draft_Page::column_map('bogus'),           null, 'unknown pipeline → null');

// ---------------------------------------------------------------------------
// SDNB-legacy uses a bespoke per-line "client block" model (NOT the flat
// column_map — that stays null so the VAC path is untouched). Assert the
// header (client-level editable) + line (per-invoice-line) column model.
// ---------------------------------------------------------------------------
$sdnb = MealsDB_Invoice_Draft_Page::sdnb_legacy_column_model();
chk_true(isset($sdnb['header']) && isset($sdnb['line']), 'SDNB model has header + line lists');

$hdr = [];
foreach ($sdnb['header'] as $c) { $hdr[$c['field']] = $c['type']; }
chk($hdr['client'] ?? null,                 'identity-name',    'SDNB header: client is identity-name');
chk($hdr['allocated_mains'] ?? null,        'input-int',        'SDNB header: mains is input-int');
chk($hdr['allocated_tax_sides'] ?? null,    'input-int',        'SDNB header: tax sides is input-int');
chk($hdr['allocated_nontax_sides'] ?? null, 'input-int',        'SDNB header: non-tax sides is input-int');
chk($hdr['contribution_cents'] ?? null,     'input-money-cents','SDNB header: contribution edited as dollars→cents');
chk_true(!isset($hdr['bill_mains']) && !isset($hdr['bill_sides']), 'SDNB header: no bill_* columns');

$line = [];
foreach ($sdnb['line'] as $c) { $line[$c['key']] = $c['type']; }
chk($line['line_number'] ?? null,      'line-label',    'SDNB line: line number');
chk($line['units'] ?? null,            'derived-int',   'SDNB line: units derived');
chk($line['rate'] ?? null,             'line-rate',     'SDNB line: rate (editable line-1 / derived line-2)');
chk($line['basic_cost_cents'] ?? null, 'derived-money', 'SDNB line: basic derived-money');
chk($line['tax_cents'] ?? null,        'derived-money', 'SDNB line: HST derived-money');
chk($line['line_total_cents'] ?? null, 'derived-money', 'SDNB line: total derived-money');

echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
