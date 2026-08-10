<?php
/**
 * Tests for MealsDB_Event_Log::is_sensitive_key() sourcing its PII key list
 * from MealsDB_Logger (audit synthesis T8 / class-event-log.php:494).
 *
 * The event-log trunk kept its OWN hardcoded copy of the sensitive-field list,
 * which had to be manually kept in sync with MealsDB_Logger::SENSITIVE_FIELDS —
 * so a PII key added to the Logger (e.g. an `*_index` fingerprint column or an
 * alternate-contact field) would reach the central trunk RAW. is_sensitive_key
 * now derives from MealsDB_Logger::sensitive_fields() unioned with the few
 * trunk-only extras, so the Logger is the single source.
 *
 * Run: php tests/test-event-log-sensitive-keys.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($cond, string $l): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $l;
}

$rm = new ReflectionMethod('MealsDB_Event_Log', 'is_sensitive_key');
$rm->setAccessible(true);
$sensitive = static function (string $k) use ($rm): bool { return (bool) $rm->invoke(null, $k); };

// Keys that live in MealsDB_Logger::SENSITIVE_FIELDS but were NOT in the trunk's
// old hardcoded copy — these prove the single-source union works.
chk($sensitive('individual_id_index'), 'Logger-only key (individual_id_index) is scrubbed via the shared list');
chk($sensitive('alternate_contact_name'), 'Logger alt-contact key is scrubbed via the shared list');
chk($sensitive('vet_health_card_index'), 'Logger index column is scrubbed');

// Trunk-only extras must still be honoured.
chk($sensitive('postal_code'), 'trunk-only extra (postal_code) still scrubbed');
chk($sensitive('birth_date'), 'trunk-only extra (birth_date) still scrubbed');
chk($sensitive('delivery_street_name'), 'trunk-only extra (delivery_street_name) still scrubbed');

// Shared canonical keys.
chk($sensitive('individual_id'), 'individual_id scrubbed');
chk($sensitive('client_phone_1'), 'client_phone_1 scrubbed');

// Case-insensitive.
chk($sensitive('INDIVIDUAL_ID'), 'match is case-insensitive');

// Non-PII keys pass through.
chk(!$sensitive('client_id'), 'client_id is NOT scrubbed');
chk(!$sensitive('billing_month'), 'billing_month is NOT scrubbed');

// The Logger accessor exists and returns its field list.
chk(is_array(MealsDB_Logger::sensitive_fields()) && in_array('individual_id', MealsDB_Logger::sensitive_fields(), true),
    'MealsDB_Logger::sensitive_fields() exposes the canonical list');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
