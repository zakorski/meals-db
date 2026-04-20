<?php
/**
 * Regression tests for MealsDB_Initials_Validator::is_address_empty() (Q8).
 *
 * The helper previously checked $address['postal_code'] but
 * normalize_delivery_address() returns the normalised array keyed as
 * 'postal' — so is_address_empty() always returned true, and
 * address-based initials sharing was silently broken for every real
 * household. Pin the fixed key name.
 *
 * Run with: php tests/test-initials-address-empty.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $t, $v, ...$a) { return $v; } }

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public function prepare($q, ...$a) { return $q; }
        public function get_results($q, $o = 'OBJECT') { return []; }
        public function get_col($q, $x = 0) { return []; }
        public function get_var($q, $x = 0, $y = 0) { return null; }
        public function query($q) { return 0; }
    }
}

$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}

$m = new ReflectionMethod('MealsDB_Initials_Validator', 'is_address_empty');
$m->setAccessible(true);

// ---------------------------------------------------------------------------
// A fully-populated NORMALISED address (the shape normalize_delivery_address
// actually returns, with key 'postal') is NOT empty.
// Before the fix, is_address_empty() checked $address['postal_code']
// which never exists on a normalised array, so this returned true.
// ---------------------------------------------------------------------------
$normalised_full = [
    'street_name' => '123 main st',
    'city'        => 'moncton',
    'postal'      => 'e1c1a1',
];
assert_equal(false, $m->invoke(null, $normalised_full), 'populated normalised address (key: postal) is not empty');

// Missing each required component makes it empty.
$no_street = $normalised_full; $no_street['street_name'] = '';
$no_city   = $normalised_full; $no_city['city']   = '';
$no_postal = $normalised_full; $no_postal['postal'] = '';
assert_equal(true, $m->invoke(null, $no_street), 'missing street_name → empty');
assert_equal(true, $m->invoke(null, $no_city),   'missing city → empty');
assert_equal(true, $m->invoke(null, $no_postal), 'missing postal → empty');

// The old wrong key name (postal_code) is NOT what the helper inspects
// now — populating it alone leaves the address "empty" via the real
// key ('postal') being absent. This locks in the exact field name
// the normaliser emits.
$wrong_key = ['street_name' => '123 main st', 'city' => 'moncton', 'postal_code' => 'e1c1a1'];
assert_equal(true, $m->invoke(null, $wrong_key), 'populating postal_code only does NOT satisfy the non-empty contract');

// End-to-end: the real normaliser produces the right shape, and
// is_address_empty agrees.
$n = new ReflectionMethod('MealsDB_Initials_Validator', 'normalize_delivery_address');
$n->setAccessible(true);
$norm = $n->invoke(null, [
    'delivery_address_street_name'  => '42 River Rd',
    'delivery_address_city'         => 'Sackville',
    'delivery_address_postal_code'  => 'E4L 3M7',
]);
assert_equal(false, $m->invoke(null, $norm), 'normaliser output flows through to is_address_empty as non-empty');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
