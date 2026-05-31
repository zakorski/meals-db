<?php
/**
 * Tests for STR-10a/b crypto hardening in MealsDB_Encryption:
 *   - STR-10b: cipher and MAC use distinct derived subkeys.
 *   - STR-10a: create_index_v2 is a keyed HMAC, distinct from the v1 bare hash.
 *   - Legacy-format read path preserved (no data lock-out during transition).
 *
 * Covers directive tests T-1 (split-key round-trip), T-2 (tamper detection),
 * T-3 (keyed index), and T-4 (legacy shared-key read).
 *
 * Run with: php tests/test-encryption-split-keys.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

// 32-byte master key, base64 with the required 'base64:' prefix.
$master_bytes = str_repeat("\x2b", 32);
if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode($master_bytes));
}

$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}
function assert_true($cond, string $label) { assert_equal(true, (bool) $cond, $label); }
function assert_false($cond, string $label) { assert_equal(false, (bool) $cond, $label); }

// ---------------------------------------------------------------------------
// T-1 — round-trip under split cipher/MAC keys.
// ---------------------------------------------------------------------------
$plain = 'Veteran 123-456-789';
$ct = MealsDB_Encryption::encrypt($plain);
assert_equal($plain, MealsDB_Encryption::decrypt($ct), 'T-1: split-key encrypt/decrypt round-trips');
assert_true(MealsDB_Encryption::is_split_key_payload($ct), 'T-1: fresh ciphertext is split-key format');

// Sanity: the cipher key and MAC key are actually different. If they were the
// same (the old shared-key bug), a value MAC-verified under the master key
// would also verify under the "mac" subkey — it must NOT.
$master = $master_bytes;
$raw    = base64_decode($ct);
$iv     = substr($raw, 32, 16);
$body   = substr($raw, 48);
$master_mac = hash_hmac('sha256', $iv . $body, $master, true);
$stored_mac = substr($raw, 0, 32);
assert_false(hash_equals($master_mac, $stored_mac), 'T-1: split-key MAC is NOT the master-key MAC (keys are distinct)');

// ---------------------------------------------------------------------------
// T-2 — tamper detection still fires under the new MAC key.
// ---------------------------------------------------------------------------
$tampered_raw = $raw;
$tampered_raw[60] = ($tampered_raw[60] === "\x00") ? "\x01" : "\x00"; // flip a ciphertext byte
$tampered = base64_encode($tampered_raw);
$threw = false;
try {
    MealsDB_Encryption::decrypt($tampered);
} catch (\Throwable $e) {
    $threw = (strpos($e->getMessage(), 'integrity') !== false);
}
assert_true($threw, 'T-2: a flipped ciphertext byte fails the split-key HMAC');

// ---------------------------------------------------------------------------
// T-3 — keyed index (create_index_v2).
// ---------------------------------------------------------------------------
$id = '  ABC-123  ';
$v1 = MealsDB_Encryption::create_index_v1($id);
$v2a = MealsDB_Encryption::create_index_v2($id);
$v2b = MealsDB_Encryption::create_index_v2('abc-123'); // same after normalize
assert_equal($v2a, $v2b, 'T-3: v2 index is deterministic for equal normalised inputs');
assert_true($v1 !== $v2a, 'T-3: v2 keyed index differs from the v1 bare hash');
assert_equal(64, strlen($v2a), 'T-3: v2 index fits CHAR(64)');
assert_true(ctype_xdigit($v2a), 'T-3: v2 index is hex');
// The v2 index must actually depend on the key: it is NOT a plain HMAC under
// the master key (it uses the derived index subkey).
$naive = hash_hmac('sha256', 'abc-123', $master, false);
assert_true($naive !== $v2a, 'T-3: v2 index uses the derived index subkey, not the raw master');

// create_index dispatches on the version flag. No flag/option set here, so the
// default is v1 (back-compat until the migrator flips it).
assert_equal($v1, MealsDB_Encryption::create_index($id), 'T-3: create_index defaults to v1 before activation');
assert_false(MealsDB_Encryption::index_format_is_v2(), 'T-3: index format defaults to v1');

// ---------------------------------------------------------------------------
// T-4 — legacy shared-key value (pre-STR-10b) still decrypts via fallback.
// Build a value the OLD way: encrypt AND MAC both under the master key.
// ---------------------------------------------------------------------------
$legacy_plain = 'old shared-key secret';
$liv = random_bytes(16);
$lct = openssl_encrypt($legacy_plain, 'aes-256-cbc', $master, OPENSSL_RAW_DATA, $liv);
$lmac = hash_hmac('sha256', $liv . $lct, $master, true);
$legacy_value = base64_encode($lmac . $liv . $lct);

assert_false(MealsDB_Encryption::is_split_key_payload($legacy_value), 'T-4: shared-key value is NOT classified split-key');
assert_equal($legacy_plain, MealsDB_Encryption::decrypt($legacy_value), 'T-4: shared-key value decrypts via the legacy fallback branch');

// And a pre-HMAC legacy value (IV + CT only) still decrypts too.
$pre_iv = random_bytes(16);
$pre_ct = openssl_encrypt('pre-hmac legacy', 'aes-256-cbc', $master, OPENSSL_RAW_DATA, $pre_iv);
$pre_value = base64_encode($pre_iv . $pre_ct);
assert_equal('pre-hmac legacy', MealsDB_Encryption::decrypt($pre_value), 'T-4: pre-HMAC legacy value still decrypts (no lock-out)');

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
