<?php
/**
 * Tests for MealsDB_Invoice_Generator::recompute_sdnb_legacy_lines() — the
 * shared SDNB-legacy per-line derivation (directive INVOICE-DRAFT-SPREADSHEET
 * SDNB scope 3a). Both serialize_sdnb_legacy (finalize) and the draft grid call
 * the SAME adapter + split_into_invoice_lines, so the grid's per-line preview
 * equals what finalize emits.
 *
 * A client splits into 1 OR 2 invoice lines. Verified behaviour:
 *   - no sides              → 1 line (units = mains, basic = mains × rate).
 *   - mains > sides         → 2 lines (line-1 units = sides, line-2 = the rest).
 *   - contribution is line-1 only; line-2 contribution is always 0.
 *   - line_total_cents = basic + tax − contribution.
 *   - bill_sides is DERIVED from tax+nontax (NOT read from allocated_sides) —
 *     proven safe because the ledger writes sides_count = tax + nontax.
 *   - editing mains down to <= sides collapses 2 lines back to 1.
 * Exact line-2 money is derived from the real Operational_Constants API (not a
 * magic number) so a future rate change can't make this test lie.
 *
 * Run: php tests/test-sdnb-legacy-compute.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!function_exists('__')) { function __($t, $d = null) { return $t; } }
if (!function_exists('wp_timezone')) { function wp_timezone() { return new DateTimeZone('UTC'); } }

// WC-sourced HST = 15% (LB-7); rate definitions at defaults.
$GLOBALS['__wc_hst_percent'] = 15.0;
if (!class_exists('WC_Tax')) {
    class WC_Tax {
        public static function get_rates($tax_class = '') {
            $p = $GLOBALS['__wc_hst_percent'] ?? null;
            return $p === null ? [] : [['rate' => $p]];
        }
    }
}
$GLOBALS['TEST_OPTIONS'] = ['mealsdb_rate_definitions' => []];
if (!function_exists('get_option')) {
    function get_option($name, $default = false) { return $GLOBALS['TEST_OPTIONS'][$name] ?? $default; }
}
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

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

/** A stored SDNB-legacy phase-2 row (build_sdnb_legacy_draft_rows shape). */
function sdnb_row(array $over = []): array {
    return array_merge([
        'client_id' => 501, 'wp_user_id' => 9001,
        'first_name' => 'Ada', 'last_name' => 'Brammah',
        'service_id' => '356029', 'requisition_id' => 'REQ-1',
        'individual_id' => 'IND-501', 'delivery_area_zone' => 'M',
        'allocated_mains' => 10, 'allocated_sides' => 0,
        'allocated_tax_sides' => 0, 'allocated_nontax_sides' => 0,
        'resolved_rate' => 11.40, 'contribution_cents' => 0,
    ], $over);
}

// --- Case 1: no sides → exactly one line. ----------------------------------
$l = MealsDB_Invoice_Generator::recompute_sdnb_legacy_lines(sdnb_row());
chk(count($l), 1, 'no-sides: single line');
chk($l[0]['units'], 10, 'no-sides: units = mains');
chk($l[0]['basic_cost_cents'], 11400, 'no-sides: basic = 10 × 11.40');
chk($l[0]['tax_cents'], 0, 'no-sides: no HST');
chk($l[0]['line_total_cents'], 11400, 'no-sides: total = basic');

// --- Case 2: mains (12) > sides (8) → two lines. ---------------------------
$row2 = sdnb_row([
    'allocated_mains' => 12,
    'allocated_tax_sides' => 5, 'allocated_nontax_sides' => 3, 'allocated_sides' => 8,
    'contribution_cents' => 500,
]);
$l2 = MealsDB_Invoice_Generator::recompute_sdnb_legacy_lines($row2);
chk(count($l2), 2, 'two-line: a client over the sides count splits');
chk($l2[0]['units'], 8,  'two-line: line-1 units = sides');
chk($l2[1]['units'], 4,  'two-line: line-2 units = mains − sides');
chk($l2[0]['client_contribution_cents'], 500, 'two-line: contribution on line 1');
chk($l2[1]['client_contribution_cents'], 0,   'two-line: NO contribution on line 2');
// line-1 money — basic = 8 × 11.40; HST = 5 taxable sides × urban side rate × 15%.
$side_rate = MealsDB_Operational_Constants::get_sdnb_side_rate(false);
$hst_l1    = MealsDB_Money::percent_of(MealsDB_Money::multiply(5, $side_rate), 0.15); // WC stub = 15%
chk($l2[0]['basic_cost_cents'], 9120, 'two-line: line-1 basic = 8 × 11.40');
chk($l2[0]['tax_cents'], $hst_l1, 'two-line: line-1 HST = 5 tax sides × side rate × 15%');
chk($l2[0]['line_total_cents'], 9120 + $hst_l1 - 500, 'two-line: line-1 total = basic + tax − contribution');
// line-2 money — units 4 at the secondary main rate from constants (not a magic number).
$sec_rate = MealsDB_Operational_Constants::get_sdnb_main_rate('secondary', false);
chk($l2[1]['rate'], (float) $sec_rate, 'two-line: line-2 rate = secondary main rate (constants)');
chk($l2[1]['basic_cost_cents'], MealsDB_Money::multiply(4, $sec_rate), 'two-line: line-2 basic = 4 × secondary rate');
chk($l2[1]['line_total_cents'], MealsDB_Money::multiply(4, $sec_rate), 'two-line: line-2 total (no tax/contrib)');

// --- bill_sides is DERIVED from tax+nontax, NOT read from allocated_sides. --
// A deliberately-wrong allocated_sides must not change the split.
$desync = MealsDB_Invoice_Generator::recompute_sdnb_legacy_lines(sdnb_row([
    'allocated_mains' => 12,
    'allocated_tax_sides' => 5, 'allocated_nontax_sides' => 3,
    'allocated_sides' => 999, // stale/wrong — must be ignored
]));
chk(count($desync), 2, 'derive: split uses tax+nontax (8), not stale allocated_sides (999)');
chk($desync[0]['units'], 8, 'derive: line-1 units track tax+nontax');

// --- Line-count flip: editing mains down to == sides collapses to one line.
// (Every side fits beside a main on line 1, with no leftover mains or sides.)
$flip = MealsDB_Invoice_Generator::recompute_sdnb_legacy_lines(sdnb_row([
    'allocated_mains' => 8, // was 12 → 2 lines; now == sides (8) → 1 line
    'allocated_tax_sides' => 5, 'allocated_nontax_sides' => 3, 'allocated_sides' => 8,
]));
chk(count($flip), 1, 'flip: mains == sides → single line');
chk($flip[0]['units'], 8, 'flip: line-1 units = all mains');

// --- Zero mains → no lines (adapter returns null). -------------------------
$zero = MealsDB_Invoice_Generator::recompute_sdnb_legacy_lines(sdnb_row(['allocated_mains' => 0]));
chk($zero, [], 'zero mains → no lines');

echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
