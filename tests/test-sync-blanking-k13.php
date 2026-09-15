<?php
/**
 * K13 v2 — sync must not blank a column from a meta key that does not exist.
 * Run: php tests/test-sync-blanking-k13.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

// --- WP / plugin stubs ----------------------------------------------------
class MealsDB_Logger {
    public static array $logs = [];
    public static function log(...$a): void { self::$logs[] = $a; }
    public static function error($m): void {}
}
class MealsDB_Event_Log {
    public const OUTCOME_DEGRADED = 'degraded';
    public static array $events = [];
    public static function record(array $e): int { self::$events[] = $e; return 1; }
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
if (!class_exists('wpdb')) { class wpdb { public $prefix = 'wp_'; public $last_error = ''; } }
if (!class_exists('WP_User')) { class WP_User { public $ID = 0; public $first_name=''; public $last_name=''; public $user_email=''; public function __construct($id=0){$this->ID=$id;} } }

// Controllable usermeta store shared by all tasks' tests.
$GLOBALS['meta_store']  = [];   // "uid|key" => value  (present)
$GLOBALS['meta_exists'] = [];   // "uid|key" => true   (metadata_exists)
if (!function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single = false) { return $GLOBALS['meta_store']["$uid|$key"] ?? ''; }
}
if (!function_exists('metadata_exists')) {
    function metadata_exists($type, $uid, $key) { return !empty($GLOBALS['meta_exists']["$uid|$key"]); }
}
if (!function_exists('update_user_meta')) {
    function update_user_meta($uid, $key, $val) {
        $cur = $GLOBALS['meta_store']["$uid|$key"] ?? null;
        if ($cur !== null && (string) $cur === (string) $val) { return false; }
        $GLOBALS['meta_store']["$uid|$key"] = $val;
        $GLOBALS['meta_exists']["$uid|$key"] = true;
        return true;
    }
}

$failures = []; $passed = 0;
function chk($cond, string $l): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $l;
}

// ===== Task 1: ITEM 1 map =====
$map = MealsDB_Sync::get_field_to_wp_meta_map();
chk(($map['street_name']['key'] ?? '') === 'billing_address_1', 'ITEM1: street_name -> billing_address_1');
chk(($map['delivery_street_name']['key'] ?? '') === 'shipping_address_1', 'ITEM1: delivery_street_name -> shipping_address_1');
chk(($map['client_phone_2']['key'] ?? '') === 'billing_phone_2', 'ITEM1: client_phone_2 -> billing_phone_2');
chk(($map['alternate_contact_name']['key'] ?? '') === 'alternate_contact_name', 'ITEM1: alt_contact_name unprefixed');
chk(($map['alternate_contact_phone_1']['key'] ?? '') === 'alternate_contact_phone_1', 'ITEM1: alt_phone_1 unprefixed');
chk(($map['alternate_contact_phone_2']['key'] ?? '') === 'alternate_contact_phone_2', 'ITEM1: alt_phone_2 unprefixed');
chk(($map['alternate_contact_email']['key'] ?? '') === 'alternate_contact_email', 'ITEM1: alt_email unprefixed');
// No remaining mapping may point at a mealsdb_* address/contact key.
foreach (['street_name','delivery_street_name','client_phone_2','alternate_contact_name','alternate_contact_phone_1','alternate_contact_phone_2','alternate_contact_email'] as $f) {
    chk(strpos($map[$f]['key'] ?? '', 'mealsdb_') !== 0, "ITEM1: {$f} no longer mealsdb_ prefixed");
}

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
