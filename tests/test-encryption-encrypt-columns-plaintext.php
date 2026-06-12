<?php
/**
 * SEC regression test — encrypt_columns() must not silently store PII as
 * plaintext.
 *
 * The idempotency skip used the purely STRUCTURAL classify_payload() ('new' =
 * base64-decodes to >= 49 bytes), so an ordinary free-text note that happens to
 * decode under strict base64 was misclassified as already-encrypted and stored
 * in cleartext — defeating the fail-closed guarantee. The fix uses the
 * key-aware is_authenticated_payload(): a value is skipped only if its HMAC
 * actually verifies.
 *
 * Run: php tests/test-encryption-encrypt-columns-plaintext.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($cond, $label) {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = $label; }
}

// A plaintext value that strict-base64-decodes to >= 49 bytes, so the OLD
// structural check (classify_payload === 'new') would skip it and store it in
// cleartext — but it is NOT authenticated under the MAC key.
$plaintext = str_repeat('A', 80); // valid base64 → 60 bytes → classified 'new'
chk(MealsDB_Encryption::classify_payload($plaintext) === 'new',
    'precondition: the value is structurally classified "new"');
chk(MealsDB_Encryption::is_authenticated_payload($plaintext) === false,
    'precondition: the value does NOT authenticate (it is really plaintext)');

// [SEC-1] encrypt_columns must actually ENCRYPT it (not skip it).
$row = MealsDB_Encryption::encrypt_columns(['diet_concerns' => $plaintext]);
$stored = $row['diet_concerns'];
chk($stored !== $plaintext, '[SEC-1] plaintext PII is encrypted, not stored verbatim');
chk(MealsDB_Encryption::is_authenticated_payload($stored) === true,
    '[SEC-1] the stored value authenticates under the MAC key');
chk(MealsDB_Encryption::decrypt($stored) === $plaintext,
    '[SEC-1] it round-trips back to the original plaintext');

// [SEC-2] Idempotency preserved: a genuinely authenticated value is skipped
//         (not double-encrypted).
$again = MealsDB_Encryption::encrypt_columns(['diet_concerns' => $stored]);
chk($again['diet_concerns'] === $stored, '[SEC-2] already-encrypted value is left unchanged');

// [SEC-3] Empty / null still skipped.
$blank = MealsDB_Encryption::encrypt_columns(['diet_concerns' => '']);
chk($blank['diet_concerns'] === '', '[SEC-3] empty string is not encrypted');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
