<?php
/**
 * DIRECTIVE 5 (ITEM 3) — MealsDB_Invoice_Draft::vac_ceiling_blockers().
 *
 * A VAC client whose whole invoice (mains value + sides value + HST) exceeds
 * their DVA coverage ceiling (permitted_mains × $11.14) blocks finalization.
 * The pure blocker list drives both the AJAX message and the finalize guard.
 *
 * Run: php tests/test-vac-ceiling-block.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!function_exists('__')) { function __($t, $d = null) { return $t; } }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}

function vac_ceil_row(array $over = []): array {
    return array_merge([
        'bill_rate' => 9.50,
        'vac_side_rate' => 4.25, 'vac_coverage_rate' => 11.14, 'vac_hst_rate' => 0.15,
        'allocated_tax_sides' => 0, 'allocated_nontax_sides' => 0,
        'first_name' => 'Test', 'last_name' => 'Vet',
    ], $over);
}

// Robichaud (over) + Lavender (under) + Janet's example (under).
$current = [
    // 22 mains + 10 taxable sides, permitted 22 → $257.88 over $245.08.
    801 => vac_ceil_row(['first_name' => 'Julien', 'last_name' => 'Robichaud',
        'bill_mains' => 22, 'allocated_tax_sides' => 10, 'info_mains_allowance' => 22]),
    // 31 mains + 10 taxable sides, permitted 31 → $343.38 under $345.34.
    802 => vac_ceil_row(['first_name' => 'David', 'last_name' => 'Lavender',
        'bill_mains' => 31, 'allocated_tax_sides' => 10, 'info_mains_allowance' => 31]),
    // 7 mains + 2 non-taxable sides, permitted 7 → $75.00 under $77.98.
    803 => vac_ceil_row(['bill_mains' => 7, 'allocated_nontax_sides' => 2, 'info_mains_allowance' => 7]),
];

$blockers = MealsDB_Invoice_Draft::vac_ceiling_blockers($current);

chk(count($blockers), 1, 'only one client blocks (Robichaud)');
chk($blockers[0]['client_id'] ?? 0, 801, 'blocker is client 801 (Robichaud)');
chk($blockers[0]['name'] ?? '', 'Julien Robichaud', 'blocker names the client');
chk($blockers[0]['total_cents'] ?? 0, 25788, 'blocker total = 257.88');
chk($blockers[0]['ceiling_cents'] ?? 0, 24508, 'blocker ceiling = 245.08');

// No VAC rows over → no blockers.
chk(MealsDB_Invoice_Draft::vac_ceiling_blockers([802 => $current[802], 803 => $current[803]]), [], 'no over-ceiling clients → empty');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
