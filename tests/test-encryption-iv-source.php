<?php
/**
 * Regression test for MealsDB_Encryption::encrypt() IV source (A2).
 *
 * encrypt() now pulls its AES-CBC IV from random_bytes() rather than
 * openssl_random_pseudo_bytes(). Verify:
 *
 *   1. encrypt() round-trips correctly with the new IV source.
 *   2. Two calls with the same plaintext produce different ciphertexts
 *      (confirming the IV actually varies — catches a regression where
 *      someone replaces random_bytes with a constant or cache).
 *
 * Run with: php tests/test-encryption-iv-source.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('get_option')) {
    function get_option(string $name, $default = false) { return $default; }
}

// 32-byte key (256 bits), prefixed as the resolver expects.
if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
}

$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}
function assert_not_equal($a, $b, string $label) {
    global $failures, $passed;
    if ($a !== $b) { $passed++; return; }
    $failures[] = "FAIL: $label (values unexpectedly equal)";
}

// ---------------------------------------------------------------------------
// Round-trip with the new IV source.
// ---------------------------------------------------------------------------
$plain = 'Jane Doe 506-555-0100';
$ct    = MealsDB_Encryption::encrypt($plain);
assert_equal($plain, MealsDB_Encryption::decrypt($ct), 'encrypt/decrypt round-trips with random_bytes IV');

// ---------------------------------------------------------------------------
// IV varies: two encrypts of the same plaintext produce distinct
// ciphertexts, confirming random_bytes() is actually supplying fresh
// entropy each call.
// ---------------------------------------------------------------------------
$ct1 = MealsDB_Encryption::encrypt($plain);
$ct2 = MealsDB_Encryption::encrypt($plain);
$ct3 = MealsDB_Encryption::encrypt($plain);
assert_not_equal($ct1, $ct2, 'two encrypts of the same plaintext differ');
assert_not_equal($ct2, $ct3, 'three encrypts produce three distinct ciphertexts');
assert_not_equal($ct1, $ct3, 'first and third ciphertexts differ');

// All three still round-trip independently.
assert_equal($plain, MealsDB_Encryption::decrypt($ct1), 'ct1 decrypts to original plaintext');
assert_equal($plain, MealsDB_Encryption::decrypt($ct2), 'ct2 decrypts to original plaintext');
assert_equal($plain, MealsDB_Encryption::decrypt($ct3), 'ct3 decrypts to original plaintext');

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
