<?php
/**
 * K12: MealsDB_Apetito_Nutridata — code validation, structured non-200
 * failure, and cache-hit-no-refetch. WP HTTP + transients are stubbed so no
 * network and no WP runtime are required.
 *
 * Run: php tests/test-apetito-fetcher.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
ini_set('error_log', '/dev/null');

// --- Minimal WP stubs the fetcher touches ---
$GLOBALS['__http_calls'] = 0;
$GLOBALS['__http_response'] = ['code' => 200, 'body' => '<html>ok</html>'];
$GLOBALS['__transients'] = [];
if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) { $GLOBALS['__http_calls']++; return ['__stub' => true]; }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!class_exists('WP_Error')) { class WP_Error { public $msg; function __construct($c='', $m='') { $this->msg=$m; } } }
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($r) { return $GLOBALS['__http_response']['code']; }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($r) { return $GLOBALS['__http_response']['body']; }
}
if (!function_exists('get_transient')) {
    function get_transient($k) { return $GLOBALS['__transients'][$k] ?? false; }
}
if (!function_exists('set_transient')) {
    function set_transient($k, $v, $ttl) { $GLOBALS['__transients'][$k] = $v; return true; }
}
if (!function_exists('__')) { function __($t, $d = 'default') { return $t; } }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$fail = []; $pass = 0;
function ok($l, $e, $a) { global $fail,$pass; if ($e===$a){$pass++;return;} $fail[]="FAIL: $l\n  expected: ".var_export($e,true)."\n  actual:   ".var_export($a,true); }

// Test 10 — code validation rejects bad input
foreach (['abc','1211','../etc/passwd',"12111'",'123456',''] as $bad) {
    $res = MealsDB_Apetito_Nutridata::fetch($bad);
    ok("reject '$bad'", false, $res['ok']);
}

// Test 11 — non-200 => structured failure, body NOT returned/parsed
$GLOBALS['__http_response'] = ['code' => 500, 'body' => 'System.NullReferenceException at D:\\a\\1\\s\\...'];
$GLOBALS['__transients'] = [];
$res = MealsDB_Apetito_Nutridata::fetch('99999');
ok('non-200 ok=false', false, $res['ok']);
ok('non-200 hides apetito body', false, strpos((string)($res['reason'] ?? ''), 'NullReferenceException') !== false);
ok('non-200 mentions code', true, strpos((string)($res['reason'] ?? ''), '99999') !== false);

// Test 12 — cache hit => no second HTTP call
$GLOBALS['__http_response'] = ['code' => 200, 'body' => '<html>PAGE</html>'];
$GLOBALS['__transients'] = [];
$GLOBALS['__http_calls'] = 0;
$a = MealsDB_Apetito_Nutridata::fetch('12212');
$b = MealsDB_Apetito_Nutridata::fetch('12212');
ok('first fetch ok', true, $a['ok']);
ok('returns body', '<html>PAGE</html>', $a['html']);
ok('cached second fetch ok', true, $b['ok']);
ok('only one HTTP call', 1, $GLOBALS['__http_calls']);

if ($fail) { echo implode("\n",$fail)."\n"; echo "FAILED ({$pass} passed)\n"; exit(1); }
echo "OK ({$pass} passed)\n";
