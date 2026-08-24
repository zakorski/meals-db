<?php
/**
 * Tests for LB-7: SDNB HST = taxable sides × pre-tax side rate × 15%,
 * rates sourced from MealsDB_Operational_Constants, rurality derived
 * from the client's delivery zone (NOT the rate value).
 *
 * This is the first dedicated money/tax unit test in the suite.
 *
 * The HST figures here intentionally DIFFER from the old
 * net-portion-multiplier model (0.672/0.82/0.681). That model was
 * wrong for pre-tax pricing; the correct basis is the taxable side
 * price × the HST rate. Do NOT "fix" these expectations back to the old
 * multiplier output — the change is the correction (LB-7).
 *
 * The HST RATE is sourced LIVE from WooCommerce (no fallback) per the
 * operator's decision, so this test mocks WC_Tax. A configurable global
 * lets us also assert the no-fallback behavior (WC unconfigured → 0%).
 *
 * Run with: php tests/test-invoice-hst-lb7.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// Mock WooCommerce's tax API. resolve_hst_rate() calls WC_Tax::get_rates('').
// $GLOBALS['__wc_hst_percent'] controls the returned rate: a number → that
// percent; null → no rate configured (the no-fallback 0% case).
$GLOBALS['__wc_hst_percent'] = 15.0;
if (!class_exists('WC_Tax')) {
    class WC_Tax {
        public static function get_rates($tax_class = '') {
            $p = $GLOBALS['__wc_hst_percent'] ?? null;
            return $p === null ? [] : [['rate' => $p]];
        }
        // resolve_hst_rate() now delegates to MealsDB_Tax::resolve_nb_hst_rate(),
        // which calls find_rates() for the CA/NB row in the 'hst' class.
        public static function find_rates($args = []) {
            $p = $GLOBALS['__wc_hst_percent'] ?? null;
            if ($p === null) { return []; }
            $match = ($args['country'] ?? '') === 'CA'
                && ($args['state'] ?? '') === 'NB'
                && ($args['tax_class'] ?? '') === 'hst';
            return $match ? [1 => ['rate' => $p, 'label' => 'HST', 'shipping' => 'yes', 'compound' => 'no']] : [];
        }
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

// The WC-sourced rate the mock returns by default (0.15).
$HST = 15.0 / 100;

$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}
function assert_true($cond, string $label) {
    global $failures, $passed;
    if ($cond === true) { $passed++; return; }
    $failures[] = "FAIL: $label (expected true)";
}

// Invoke the private static split_into_invoice_lines() via reflection so
// we exercise the real two-line logic, not a reimplementation.
$split = new ReflectionMethod('MealsDB_Invoice_Generator', 'split_into_invoice_lines');
$split->setAccessible(true);
$run_split = function (array $row) use ($split) {
    return $split->invoke(null, $row);
};

// ---------------------------------------------------------------------------
// Rurality is derived from the delivery zone, NOT the rate value.
// ---------------------------------------------------------------------------
assert_true(MealsDB_Operational_Constants::is_rural_zone('S'), 'zone S is rural');
assert_true(MealsDB_Operational_Constants::is_rural_zone(' s '), 'zone S is rural (trim/case-insensitive)');
assert_equal(false, MealsDB_Operational_Constants::is_rural_zone('M'), 'zone M is urban');
assert_equal(false, MealsDB_Operational_Constants::is_rural_zone(''), 'empty zone is not rural');
assert_equal(false, MealsDB_Operational_Constants::is_rural_zone(null), 'null zone is not rural');

// ---------------------------------------------------------------------------
// HST math primitive: taxable sides × side rate × 15%, half-up to cents.
// Urban side rate = 4.48; rural side rate = 4.54. Mains never enter this.
// ---------------------------------------------------------------------------
$hst = function (int $count, bool $rural) use ($HST): int {
    $rate = MealsDB_Operational_Constants::get_sdnb_side_rate($rural);
    return MealsDB_Money::percent_of(MealsDB_Money::multiply($count, $rate), $HST);
};
// 4 × 4.48 = 17.92 → ×0.15 = 2.688 → 269 cents (half-up).
assert_equal(269, $hst(4, false), 'urban HST: 4 taxable sides @ 4.48 × 15%');
// 4 × 4.54 = 18.16 → ×0.15 = 2.724 → 272 cents.
assert_equal(272, $hst(4, true), 'rural HST: 4 taxable sides @ 4.54 × 15%');
// 0 taxable sides → no HST.
assert_equal(0, $hst(0, false), 'no taxable sides → no HST');

// ---------------------------------------------------------------------------
// Two-line split, URBAN: mains overflow onto line 2 → line-2 main rate is
// the SECONDARY main rate from constants; HST sits on the line that holds
// the taxable sides; mains carry no HST.
// ---------------------------------------------------------------------------
$urban_client = [
    'service_id'        => 'SVC1',
    'requisition_id'    => 'REQ1',
    'individual_id'     => 'IND1',
    'last_name'         => 'Doe',
    'first_name'        => 'Jane',
    'delivery_area_zone'=> 'M',
];
$lines = $run_split([
    'client'              => $urban_client,
    'resolved_rate'       => MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN, // 14.66 (line-1 primary)
    'bill_mains'          => 10,
    'bill_sides'          => 4,
    'bill_tax_sides'      => 4,
    'bill_nontax_sides'   => 0,
    'client_contribution' => 0,
]);
assert_equal(2, count($lines), 'urban: mains overflow produces two lines');
// Line 1: 4 mains @ 14.66 primary, HST on the 4 taxable sides (269 cents).
assert_equal(14.66, $lines[0]['rate'], 'urban line-1 rate is the primary main rate');
assert_equal(269, $lines[0]['tax_cents'], 'urban line-1 HST = 4 sides @ 4.48 × 15%');
// Line 2: 6 mains @ secondary main rate (10.18), no taxable sides → no HST.
assert_equal(10.18, $lines[1]['rate'], 'urban line-2 rate is the SECONDARY main rate from constants');
assert_equal(0, $lines[1]['tax_cents'], 'urban line-2 has no taxable sides → no HST');

// ---------------------------------------------------------------------------
// Two-line split, RURAL: same shape but rural rates must be selected purely
// from the zone — resolved_rate is left at the URBAN primary on purpose to
// prove the side/secondary rates do NOT come from the rate value.
// ---------------------------------------------------------------------------
$rural_client = $urban_client;
$rural_client['delivery_area_zone'] = 'S';
$lines = $run_split([
    'client'              => $rural_client,
    'resolved_rate'       => MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN, // deliberately urban value
    'bill_mains'          => 10,
    'bill_sides'          => 4,
    'bill_tax_sides'      => 4,
    'bill_nontax_sides'   => 0,
    'client_contribution' => 0,
]);
assert_equal(2, count($lines), 'rural: mains overflow produces two lines');
assert_equal(272, $lines[0]['tax_cents'], 'rural line-1 HST uses the RURAL side rate (4.54), not the urban rate value');
assert_equal(10.93, $lines[1]['rate'], 'rural line-2 rate is the RURAL secondary main rate from constants');

// ---------------------------------------------------------------------------
// Sides-only line 2: when line 2 carries only sides, its rate is the side
// rate (not a main rate).
// ---------------------------------------------------------------------------
$lines = $run_split([
    'client'              => $urban_client,
    'resolved_rate'       => MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN,
    'bill_mains'          => 2,
    'bill_sides'          => 4,
    'bill_tax_sides'      => 2,
    'bill_nontax_sides'   => 2,
    'client_contribution' => 0,
]);
assert_equal(2, count($lines), 'sides-only overflow produces two lines');
assert_equal(4.48, $lines[1]['rate'], 'urban sides-only line-2 rate is the side rate');

// ---------------------------------------------------------------------------
// Single line: no overflow, no taxable sides → one line, no HST.
// ---------------------------------------------------------------------------
$lines = $run_split([
    'client'              => $urban_client,
    'resolved_rate'       => MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN,
    'bill_mains'          => 5,
    'bill_sides'          => 0,
    'bill_tax_sides'      => 0,
    'bill_nontax_sides'   => 0,
    'client_contribution' => 0,
]);
assert_equal(1, count($lines), 'no overflow → single line');
assert_equal(0, $lines[0]['tax_cents'], 'no taxable sides → no HST');

// ---------------------------------------------------------------------------
// The obsolete net-portion multiplier constants are gone, and there is no
// SDNB HST constant any more (the rate is WC-sourced, no fallback).
// ---------------------------------------------------------------------------
assert_equal(false, defined('MealsDB_Operational_Constants::HST_MULTIPLIER_PRIMARY_MAIN'), 'HST_MULTIPLIER_PRIMARY_MAIN constant removed');
assert_equal(false, defined('MealsDB_Operational_Constants::HST_RATE'), 'SDNB HST_RATE constant removed (rate now sourced from WC)');

// ---------------------------------------------------------------------------
// NO FALLBACK: when WooCommerce has no standard rate configured, HST is 0
// (the deliberate, operator-chosen behavior). A rural client with taxable
// sides that would otherwise bill HST must produce 0 tax_cents.
// ---------------------------------------------------------------------------
$GLOBALS['__wc_hst_percent'] = null; // simulate WC tax unconfigured
$lines = $run_split([
    'client'              => $rural_client, // zone 'S', would be rural HST
    'resolved_rate'       => MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN,
    'bill_mains'          => 10,
    'bill_sides'          => 4,
    'bill_tax_sides'      => 4,
    'bill_nontax_sides'   => 0,
    'client_contribution' => 0,
]);
assert_equal(0, $lines[0]['tax_cents'], 'no WC rate → 0% HST (no fallback to a constant)');
$GLOBALS['__wc_hst_percent'] = 15.0; // restore for any later assertions

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
