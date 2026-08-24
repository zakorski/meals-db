<?php
/**
 * MealsDB_Tax::resolve_nb_hst_rate() — DIRECTIVE hst-rate-source ITEM 1.
 * Asks WC_Tax::find_rates() for the CA/NB row in the 'hst' class explicitly
 * and asserts EXACTLY one row, so the answer no longer depends on the store
 * base and a reset()-and-hope can't slip through.
 *
 * Run: php tests/test-tax-nb-hst-rate.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');

// $GLOBALS['__nb_rows'] is what the mocked find_rates() returns for a CA/NB/hst
// query: an array of rate rows keyed by id. Any other query returns [].
if (!class_exists('WC_Tax')) {
    class WC_Tax {
        public static function find_rates($args = []) {
            $match = ($args['country'] ?? '') === 'CA'
                && ($args['state'] ?? '') === 'NB'
                && ($args['tax_class'] ?? '') === 'hst';
            return $match ? ($GLOBALS['__nb_rows'] ?? []) : [];
        }
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

$failures = []; $passed = 0;
function nb_eq($label, $expected, $actual): void {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label,
        var_export($expected, true), var_export($actual, true));
}

// One well-formed NB row at 15% → 0.15
$GLOBALS['__nb_rows'] = [7 => ['rate' => '15.0000', 'label' => 'HST', 'shipping' => 'yes', 'compound' => 'no']];
nb_eq('single NB row 15% -> 0.15', 0.15, MealsDB_Tax::resolve_nb_hst_rate());

// No NB row → 0.0 (no silent store-base fallback)
$GLOBALS['__nb_rows'] = [];
nb_eq('no NB row -> 0.0', 0.0, MealsDB_Tax::resolve_nb_hst_rate());

// Ambiguous (two rows) → 0.0, we refuse to guess
$GLOBALS['__nb_rows'] = [
    7 => ['rate' => '15.0000'],
    8 => ['rate' => '14.0000'],
];
nb_eq('ambiguous rows -> 0.0', 0.0, MealsDB_Tax::resolve_nb_hst_rate());

// Non-positive rate → 0.0
$GLOBALS['__nb_rows'] = [7 => ['rate' => '0']];
nb_eq('zero rate -> 0.0', 0.0, MealsDB_Tax::resolve_nb_hst_rate());

if ($failures) { echo implode("\n", $failures) . "\n"; echo "FAILED ({$passed} passed)\n"; exit(1); }
echo "OK ({$passed} passed)\n";
