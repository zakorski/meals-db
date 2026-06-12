<?php
/**
 * SEC-MEDIUM regression tests.
 *
 *  A1  MealsDB_Schema_Sync::run_full_sync re-checks manage_options (the
 *      destructive schema sync was reachable at baseline capability).
 *  B1  MealsDB_Logger::sanitize_for_log redacts phone numbers and bare
 *      government-ID digit runs (the docblock promised this; the code only
 *      scrubbed emails + base64 blobs, so a phone/SIN reached error_log and the
 *      digest email).
 *  B2  MealsDB_Logger::fingerprint_value exists and produces the audit
 *      fingerprint shape (used to keep cleartext PII out of the delete_client
 *      audit snapshot).
 *
 * Run: php tests/test-security-medium.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

$GLOBALS['__sec_can'] = false;
if (!function_exists('current_user_can'))  { function current_user_can($c) { return (bool) ($GLOBALS['__sec_can'] ?? false); } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!function_exists('__'))                { function __($t, $d = 'default') { return $t; } }
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message;
        public function __construct($code = '', $message = '', $data = '') { $this->code = $code; $this->message = $message; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($cond, $label) {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = $label; }
}

// ---------------------------------------------------------------------------
// A1 — run_full_sync refuses without manage_options.
// ---------------------------------------------------------------------------
$GLOBALS['__sec_can'] = false;
require_once __DIR__ . '/../includes/class-schema-sync.php';
$r = MealsDB_Schema_Sync::run_full_sync();
chk(($r instanceof WP_Error) && stripos((string) $r->get_error_code(), 'forbid') !== false,
    '[A1] run_full_sync refuses without manage_options');

// ---------------------------------------------------------------------------
// B1 — sanitize_for_log scrubs phones and ID digit-runs (and still emails).
// ---------------------------------------------------------------------------
$phone = MealsDB_Logger::sanitize_for_log('Delivery failed for 506-555-1234 today');
chk(strpos($phone, '506-555-1234') === false, '[B1] phone digits removed');
chk(strpos($phone, '[phone:') !== false,       '[B1] phone replaced with a fingerprint');

$bare = MealsDB_Logger::sanitize_for_log('Lookup for 5065551234 returned nothing');
chk(strpos($bare, '5065551234') === false, '[B1] bare 10-digit phone removed');

$sin = MealsDB_Logger::sanitize_for_log('individual_id 123456789 not found');
chk(strpos($sin, '123456789') === false, '[B1] 9-digit government ID removed');
chk(strpos($sin, '[id:') !== false || strpos($sin, '[phone:') !== false, '[B1] ID replaced with a fingerprint');

$email = MealsDB_Logger::sanitize_for_log('failed for jane.doe@example.com');
chk(strpos($email, 'jane.doe@example.com') === false, '[B1] email still redacted');

// A short non-PII number is left intact (no over-redaction).
$safe = MealsDB_Logger::sanitize_for_log('order 4521 reconciled');
chk(strpos($safe, '4521') !== false, '[B1] short numbers (order ids) are not over-redacted');

// ---------------------------------------------------------------------------
// B2 — fingerprint_value helper.
// ---------------------------------------------------------------------------
$fp = MealsDB_Logger::fingerprint_value('jane@example.com');
chk(is_string($fp) && strpos($fp, '[redacted:sha256=') === 0, '[B2] fingerprint_value produces the audit fingerprint shape');
chk(MealsDB_Logger::fingerprint_value(null) === null, '[B2] null passes through');
chk(MealsDB_Logger::fingerprint_value('') === '', '[B2] empty passes through');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
