<?php
/**
 * Tests for MealsDB_Sync_Mutate user-meta writes treating a WP "value
 * unchanged" no-op as a sync FAILURE (audit-2026-08 B07 / H11).
 *
 * update_user_meta() returns false BOTH on a real failure AND when the new
 * value already equals the stored value. The old `update_user_meta(...) !== false`
 * check therefore reported an already-correct field as a failure — a bogus
 * WP_Error surfaced in the admin UI plus a spurious sync_partial_failure audit
 * row. Pushing a field that already matches its target must be a SUCCESS.
 *
 * Run: php tests/test-sync-mutate-meta-noop.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class MealsDB_Logger {
    public static array $logs = [];
    public static function log(...$a): void { self::$logs[] = $a; }
    public static function error($m): void {}
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $t, $v, ...$a) { return $v; } }
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message;
        public function __construct($c = '', $m = '') { $this->code = $c; $this->message = $m; }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!class_exists('wpdb')) { class wpdb { public $prefix = 'wp_'; } }
if (!class_exists('WP_User')) { class WP_User { public $ID = 0; public $first_name = ''; public $last_name = ''; public $user_email = ''; public function __construct($id = 0) { $this->ID = $id; } } }

// Controllable user-meta store: update_user_meta mirrors WP (false on no-op).
$GLOBALS['meta_store'] = [];
$GLOBALS['uum_calls']  = [];
$GLOBALS['uum_force_false'] = false;
if (!function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single = false) { return $GLOBALS['meta_store']["$uid|$key"] ?? ''; }
}
if (!function_exists('update_user_meta')) {
    function update_user_meta($uid, $key, $val) {
        $GLOBALS['uum_calls'][] = [$uid, $key, $val];
        if (!empty($GLOBALS['uum_force_false'])) { return false; }
        $cur = $GLOBALS['meta_store']["$uid|$key"] ?? null;
        if ($cur !== null && (string) $cur === (string) $val) { return false; } // WP no-op
        $GLOBALS['meta_store']["$uid|$key"] = $val;
        return true;
    }
}

$failures = []; $passed = 0;
function chk($cond, string $l): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $l;
}

// --- unit: the extracted idempotent helper --------------------------------
$ph = new ReflectionMethod('MealsDB_Sync_Mutate', 'persist_user_meta');
$ph->setAccessible(true);
$GLOBALS['uum_calls'] = []; $GLOBALS['uum_force_false'] = false;
chk($ph->invoke(null, 7, 'billing_phone', '555', '555') === true, 'helper: already-equal -> true');
chk(count($GLOBALS['uum_calls']) === 0, 'helper: already-equal -> update_user_meta NOT called');
$GLOBALS['meta_store'] = []; $GLOBALS['uum_calls'] = [];
chk($ph->invoke(null, 7, 'billing_phone', '', '555') === true, 'helper: changed -> true (meta added)');
$GLOBALS['uum_force_false'] = true;
chk($ph->invoke(null, 7, 'billing_phone', 'old', 'new') === false, 'helper: changed but update fails -> false');
$GLOBALS['uum_force_false'] = false;

// --- integration: apply_wp_user_update phone path -------------------------
$GLOBALS['wpdb'] = new wpdb();
$mut = new MealsDB_Sync_Mutate();
$rm = new ReflectionMethod('MealsDB_Sync_Mutate', 'apply_wp_user_update');
$rm->setAccessible(true);

// Phone already equals target → update_user_meta would no-op (false); must be SUCCESS.
$GLOBALS['meta_store'] = ['7|billing_phone' => '5551234'];
$GLOBALS['uum_calls'] = []; MealsDB_Logger::$logs = [];
$res = $rm->invoke($mut, new WP_User(7), 'client_phone_1', '5551234');
chk($res === true, 'apply: already-correct phone -> true (not a WP_Error) [H11]');

// Genuine change → success, meta updated.
$GLOBALS['meta_store'] = ['7|billing_phone' => '111'];
$res = $rm->invoke($mut, new WP_User(7), 'client_phone_1', '222');
chk($res === true, 'apply: changed phone -> true');
chk(($GLOBALS['meta_store']['7|billing_phone'] ?? '') === '222', 'apply: changed phone persisted');

// Genuine failure (forced) → WP_Error.
$GLOBALS['meta_store'] = ['7|billing_phone' => '111'];
$GLOBALS['uum_force_false'] = true;
$res = $rm->invoke($mut, new WP_User(7), 'client_phone_1', '999');
chk(is_wp_error($res), 'apply: real update failure -> WP_Error');
$GLOBALS['uum_force_false'] = false;

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
