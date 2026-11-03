<?php
/**
 * Tests for the K21 write layer: the one live WordPress writer
 * (MealsDB_Sync_Resolver::write_wp_field — routes by registry descriptor,
 * K13 empty-overwrite guard on core and meta fields, no-op idempotency, both
 * address_2 keys) and MealsDB_Sync_Mutate's registry-driven column map.
 * (The writer's tests moved here from the retired
 * MealsDB_Sync_Mutate::apply_wp_user_update.)
 *
 * Run: php tests/test-sync-mutate-registry-k21.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class MealsDB_Logger {
    public static array $logs = [];
    public static function log(...$a): void { self::$logs[] = $a; }
    public static function error($m): void {}
    public static function sanitize_for_log($m) { return $m; }
    public static function sensitive_fields(): array { return []; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $f = 0, $d = 512) { return json_encode($v, $f, $d); } }
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message;
        public function __construct($c = '', $m = '') { $this->code = $c; $this->message = $m; }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!class_exists('wpdb')) {
    class wpdb {
        public $prefix = 'wp_'; public $insert_id = 0; public $last_error = '';
        public array $events = [];
        public function insert($t, $d, $f = null) { if (isset($d['event'])) { $this->events[] = $d['event']; } return 1; }
    }
}
if (!class_exists('WP_User')) {
    class WP_User {
        public $ID = 0; public $first_name = ''; public $last_name = ''; public $user_email = '';
        public function __construct($id = 0) { $this->ID = $id; }
    }
}

$GLOBALS['meta_store'] = [];
$GLOBALS['uum_calls']  = [];
$GLOBALS['wuu_calls']  = [];
if (!function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single = false) { return $GLOBALS['meta_store']["$uid|$key"] ?? ''; }
}
if (!function_exists('update_user_meta')) {
    function update_user_meta($uid, $key, $val) {
        $GLOBALS['uum_calls'][] = [$uid, $key, $val];
        $GLOBALS['meta_store']["$uid|$key"] = $val;
        return true;
    }
}
$GLOBALS['filters'] = [];
if (!function_exists('add_filter')) { function add_filter($t, $cb, $p = 10, $a = 1) { $GLOBALS['filters'][$t][] = $cb; return true; } }
if (!function_exists('remove_filter')) {
    function remove_filter($t, $cb, $p = 10) {
        $GLOBALS['filters'][$t] = array_values(array_filter($GLOBALS['filters'][$t] ?? [], static fn ($x) => $x !== $cb));
        return true;
    }
}
if (!function_exists('wp_update_user')) {
    function wp_update_user($data) { $GLOBALS['wuu_calls'][] = $data; return (int) $data['ID']; }
}

$failures = []; $passed = 0;
function chk($cond, string $l): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $l;
}

$GLOBALS['wpdb'] = new wpdb();
$mut = new MealsDB_Sync_Mutate();
$reset = static function (): void {
    $GLOBALS['uum_calls'] = []; $GLOBALS['wuu_calls'] = []; $GLOBALS['wpdb']->events = [];
};
// The one live WordPress writer (K21): the retired
// MealsDB_Sync_Mutate::apply_wp_user_update() guarantees, re-asserted here.
$W = static function (WP_User $u, string $column, string $value) {
    return MealsDB_Sync_Resolver::write_wp_field($u, MealsDB_Sync_Registry::get($column), $column, $value, 'sync_resolve_wp', 'test');
};

$user = new WP_User(7);
$user->first_name = 'Ann';
$user->last_name  = 'Smith';
$user->user_email = 'ann@example.com';

// --- core fields: routed to wp_update_user with the registry key ----------
$reset();
chk($W($user, 'first_name', 'Anne') === true, 'core: first_name change succeeds');
chk($GLOBALS['wuu_calls'] === [['ID' => 7, 'first_name' => 'Anne']], 'core: first_name -> wp_update_user(first_name)');

$reset();
chk($W($user, 'client_email', 'ann.s@example.com') === true, 'core: client_email change succeeds');
chk($GLOBALS['wuu_calls'] === [['ID' => 7, 'user_email' => 'ann.s@example.com']], 'core: client_email -> wp_update_user(user_email)');
chk(($GLOBALS['filters']['send_email_change_email'] ?? []) === [], 'core: email-change filter removed after the write');

// --- core fields: empty-overwrite refused ----------------------------------
foreach (['first_name', 'last_name', 'client_email'] as $f) {
    $reset();
    $r = $W($user, $f, '   ');
    chk(is_wp_error($r) && $r->get_error_code() === 'mealsdb_sync_empty_overwrite_refused', "core guard: blank $f refused");
    chk($GLOBALS['wuu_calls'] === [], "core guard: wp_update_user not called for blank $f");
    chk(in_array('sync.empty_overwrite_refused', $GLOBALS['wpdb']->events, true), "core guard: refusal logged for $f");
}
$reset();
chk($W(new WP_User(8), 'first_name', '') === true, 'core guard: blank over blank is not refused');

// --- meta: guard, routing, idempotency, address pair -----------------------
$reset();
$GLOBALS['meta_store'] = ['7|billing_phone' => '506-555-0100'];
$r = $W($user, 'client_phone_1', '');
chk(is_wp_error($r) && $r->get_error_code() === 'mealsdb_sync_empty_overwrite_refused', 'meta guard: blank billing_phone refused');
chk($GLOBALS['uum_calls'] === [], 'meta guard: billing_phone left untouched');

$reset();
chk($W($user, 'client_phone_1', '506-555-0199') === true && $GLOBALS['uum_calls'] === [[7, 'billing_phone', '506-555-0199']],
    'meta: client_phone_1 -> billing_phone');

// update_user_meta() returns false on a no-op; the writer must treat an
// already-equal value as success without calling it (audit-2026-08 B07/H11).
$reset();
chk($W($user, 'client_phone_1', '506-555-0199') === true && $GLOBALS['uum_calls'] === [], 'meta: already-equal value is a success, no write');

$reset();
$GLOBALS['meta_store'] = ['7|shipping_address_2' => 'Zone 1', '7|billing_address_2' => 'Zone 1'];
chk($W($user, 'delivery_area_name', 'Zone 3') === true
    && $GLOBALS['uum_calls'] === [[7, 'shipping_address_2', 'Zone 3'], [7, 'billing_address_2', 'Zone 3']],
    'ITEM 4: delivery_area_name writes both address_2 keys');

// --- column map comes from the registry ------------------------------------
$cmap = new ReflectionMethod('MealsDB_Sync_Mutate', 'build_identity_column_map');
$cmap->setAccessible(true);
$available = array_fill_keys(array_merge(
    array_keys(MealsDB_Sync_Registry::fields()),
    ['client_id', 'wp_user_id', 'individual_id', 'street_number']
), true);
$map = $cmap->invoke($mut, $available);
foreach (MealsDB_Sync_Registry::two_way_columns() as $col) {
    chk(($map[$col] ?? null) === $col, "column map: $col");
}
chk(!isset($map['delivery_initials']), 'column map: delivery_initials never writable (ITEM 5)');
chk(!isset($map['individual_id']), 'column map: encrypted column not in the map');
chk(!isset($map['street_number']), 'column map: non-registry column not in the map');
chk(($map['phone_primary'] ?? null) === 'client_phone_1', 'column map: legacy phone_primary alias kept');
chk(($map['wordpress_user_id'] ?? null) === 'wp_user_id', 'column map: legacy wordpress_user_id alias kept');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
