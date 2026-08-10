<?php
/**
 * Tests for the destructive-op capability gate in MealsDB_Clients (audit
 * low-vuln, class-clients.php).
 *
 * delete_client() / set_client_active_status() guarded their capability check
 * with `function_exists('current_user_can') && ...` — so when the WP capability
 * API is NOT available (a non-WP / pre-init / CLI context), the guard was
 * skipped ENTIRELY and the destructive op ran unguarded (fail-OPEN). It must
 * fail CLOSED: no capability API -> refuse.
 *
 * The gate is now the pure helper MealsDB_Clients::is_permitted().
 *
 * Run: php tests/test-clients-destructive-authz.php
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

$rm = new ReflectionMethod('MealsDB_Clients', 'is_permitted');
$rm->setAccessible(true);

// 1. FAIL CLOSED: with current_user_can undefined (as here, before we stub it),
// the destructive gate must refuse — the whole point of the fix.
chk($rm->invoke(null) === false, 'is_permitted() is false when the WP capability API is unavailable (fail closed)');

// Now provide the WP capability surface so can_access_plugin() can resolve.
$GLOBALS['cap'] = true;
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!function_exists('get_option'))        { function get_option($k, $d = false) { return $d; } }
if (!function_exists('apply_filters'))     { function apply_filters($t, $v, ...$a) { return $v; } }
if (!function_exists('__'))                { function __($t, $d = 'default') { return $t; } }
if (!function_exists('current_user_can'))  { function current_user_can($c) { return (bool) ($GLOBALS['cap'] ?? false); } }

// 2. Permitted when the API is present and the capability is granted.
$GLOBALS['cap'] = true;
chk($rm->invoke(null) === true, 'is_permitted() is true when logged in with the capability');

// 3. Refused when the capability is missing.
$GLOBALS['cap'] = false;
chk($rm->invoke(null) === false, 'is_permitted() is false when the capability is missing');

// 4. delete_client() refuses (returns false) when not permitted, before any DB work.
$GLOBALS['cap'] = false;
chk(MealsDB_Clients::delete_client(5) === false, 'delete_client() refuses without permission');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
