<?php
/**
 * Tests for directive GUI-F3F5-v2 — client create anchored to a validated WP
 * user (Validate + Pull Data endpoints + the shared usermeta mapper).
 *
 * Covers:
 *   T-1 validate endpoint: existing id → success + billing name; nonexistent →
 *       error; 0/blank → error; capability/nonce/rate-limit enforced.
 *   T-2 already-linked flag: validating a WP user tied to a client returns
 *       already_linked = that client id.
 *   T-3 pull endpoint maps correctly via the shared MealsDB_WP_User_Mapper
 *       (asserted against the migration's usermeta keys — guards drift).
 *   T-4 pull normalizes: province → 2-letter, postal → A1A1A1, phone formatted.
 *
 * (T-5..T-8 — the create/update WP-user requirement — live in
 * test-client-create-gui-f3f5.php, which exercises save()/update() directly.)
 *
 * Run: php tests/test-client-wp-user-anchor-gui-f3f5-v2.php
 */

if (!defined('ABSPATH'))  { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A'))  { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT'))   { define('OBJECT', 'OBJECT'); }
if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
}

// ---------------------------------------------------------------------------
// Controllable guard flags + the fixture WP user store.
// ---------------------------------------------------------------------------
$GLOBALS['NONCE_OK'] = true;
$GLOBALS['CAP_OK']   = true;
$GLOBALS['RL_OK']    = true;
$GLOBALS['LINKED_CLIENT_ID'] = 0; // what find_client_id_by_wp_user resolves to

// usermeta keyed by uid → [meta_key => value]; users with no entry don't exist.
$GLOBALS['WP_USERS'] = [];
$GLOBALS['WP_USERMETA'] = [];

// ---------------------------------------------------------------------------
// WP function stubs.
//
// In real WP, wp_send_json_* calls wp_die()/exit — execution STOPS at the
// first response. The handlers here wrap their happy path in
// try { … } catch (\Throwable). A naive throwing stub would be caught by that
// very catch and converted into an error send, so we record the FIRST response
// (which is the one real WP would have exited on) and ignore later sends, then
// throw a marker purely to unwind the stack. call_endpoint reads the recorded
// first response.
// ---------------------------------------------------------------------------
$GLOBALS['JSON'] = null;
class WpJsonExit extends \Exception {}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status = null) {
        if ($GLOBALS['JSON'] === null) { $GLOBALS['JSON'] = ['type' => 'success', 'data' => $data, 'status' => $status]; }
        throw new WpJsonExit();
    }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status = null) {
        if ($GLOBALS['JSON'] === null) { $GLOBALS['JSON'] = ['type' => 'error', 'data' => $data, 'status' => $status]; }
        throw new WpJsonExit();
    }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($a, $n = false, $die = true) { return (bool) $GLOBALS['NONCE_OK']; }
}
if (!function_exists('absint'))   { function absint($v) { return abs((int) $v); } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; } }
if (!function_exists('__'))        { function __($t, $d = null) { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('add_action')) { function add_action() { return true; } }
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return is_string($v) ? trim(preg_replace('/[\r\n\t]+/', ' ', $v)) : $v; }
}

// WP_User + get_userdata + get_user_meta backed by the fixture store.
if (!class_exists('WP_User')) {
    class WP_User {
        public $ID = 0;
        public $first_name = '';
        public $last_name = '';
        public $display_name = '';
        public $user_email = '';
    }
}
if (!function_exists('get_userdata')) {
    function get_userdata($id) {
        $id = (int) $id;
        if (!isset($GLOBALS['WP_USERS'][$id])) {
            return false;
        }
        $u = new WP_User();
        $u->ID = $id;
        foreach ($GLOBALS['WP_USERS'][$id] as $k => $v) { $u->$k = $v; }
        return $u;
    }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($id, $key, $single = false) {
        $id = (int) $id;
        return $GLOBALS['WP_USERMETA'][$id][$key] ?? '';
    }
}

// ---------------------------------------------------------------------------
// Stub plugin collaborators BEFORE the autoloader so the real ones don't load.
// (We want the REAL Ajax_Clients / WP_User_Mapper / Client_Form, but stubbed
// Permissions / Rate_Limiter / Logger / Clients_Repository.)
// ---------------------------------------------------------------------------
class MealsDB_Permissions {
    public static function can_access_plugin(): bool { return (bool) $GLOBALS['CAP_OK']; }
    public static function required_capability(): string { return 'manage_woocommerce'; }
}
class MealsDB_Rate_Limiter {
    public static function check_rate_limit(string $bucket, ?int $uid = null): bool { return (bool) $GLOBALS['RL_OK']; }
}
class MealsDB_Logger {
    public static function error($m) { /* swallow in tests */ }
}
// Only the one static method the endpoints touch; returns the configured id.
class MealsDB_Clients_Repository {
    public static function find_client_id_by_wp_user(int $wp_user_id): ?int {
        $id = (int) $GLOBALS['LINKED_CLIENT_ID'];
        return $id > 0 ? $id : null;
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// ---------------------------------------------------------------------------
// Tiny assert harness.
// ---------------------------------------------------------------------------
$failures = [];
$passed = 0;
function check($cond, string $label) {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = 'FAIL: ' . $label;
}

// Invoke an endpoint and capture its FIRST JSON response (success|error). The
// catch swallows both the marker unwind AND any secondary send the handler's
// \Throwable catch may emit — the recorded first response is authoritative.
function call_endpoint(callable $fn): array {
    $GLOBALS['JSON'] = null;
    try {
        $fn();
    } catch (\Throwable $e) {
        // intentional: real WP would have exited at the first send.
    }
    return $GLOBALS['JSON'] ?? ['type' => 'none', 'data' => null, 'status' => null];
}

// Seed a fixture WP user.
function seed_user(int $id, array $userprops, array $meta = []) {
    $GLOBALS['WP_USERS'][$id] = $userprops;
    $GLOBALS['WP_USERMETA'][$id] = $meta;
}

// ===========================================================================
// T-1: validate endpoint
// ===========================================================================
seed_user(500, ['first_name' => 'Acct', 'last_name' => 'Name', 'display_name' => 'Acct Name'], [
    'billing_first_name' => 'Janet',
    'billing_last_name'  => 'Doe',
]);
$GLOBALS['LINKED_CLIENT_ID'] = 0;

$_POST = ['wp_user_id' => '500'];
$r = call_endpoint(['MealsDB_Ajax_Clients', 'validate_wp_user']);
check($r['type'] === 'success', 'T-1 validate existing user → success');
check(($r['data']['name'] ?? '') === 'Janet Doe', 'T-1 validate echoes BILLING name (Janet Doe), got: ' . ($r['data']['name'] ?? ''));
check(($r['data']['wp_user_id'] ?? 0) === 500, 'T-1 validate returns the uid');
check(array_key_exists('already_linked', $r['data']) && $r['data']['already_linked'] === null, 'T-1 validate not-yet-linked → already_linked null');

$_POST = ['wp_user_id' => '99999'];
$r = call_endpoint(['MealsDB_Ajax_Clients', 'validate_wp_user']);
check($r['type'] === 'error', 'T-1 validate nonexistent id → error');

$_POST = ['wp_user_id' => '0'];
$r = call_endpoint(['MealsDB_Ajax_Clients', 'validate_wp_user']);
check($r['type'] === 'error', 'T-1 validate 0 → error');

$_POST = [];
$r = call_endpoint(['MealsDB_Ajax_Clients', 'validate_wp_user']);
check($r['type'] === 'error', 'T-1 validate blank → error');

// Capability + rate-limit enforced.
$GLOBALS['CAP_OK'] = false;
$_POST = ['wp_user_id' => '500'];
$r = call_endpoint(['MealsDB_Ajax_Clients', 'validate_wp_user']);
check($r['type'] === 'error', 'T-1 validate without capability → error');
$GLOBALS['CAP_OK'] = true;

$GLOBALS['RL_OK'] = false;
$r = call_endpoint(['MealsDB_Ajax_Clients', 'validate_wp_user']);
check($r['type'] === 'error' && $r['status'] === 429, 'T-1 validate over rate limit → 429');
$GLOBALS['RL_OK'] = true;

// Billing name falls back to account name then display name.
seed_user(501, ['first_name' => 'Acc', 'last_name' => 'Ount', 'display_name' => 'Display Only'], []);
$_POST = ['wp_user_id' => '501'];
$r = call_endpoint(['MealsDB_Ajax_Clients', 'validate_wp_user']);
check(($r['data']['name'] ?? '') === 'Acc Ount', 'T-1 validate falls back to account name when no billing name');

// ===========================================================================
// T-2: already-linked flag
// ===========================================================================
$GLOBALS['LINKED_CLIENT_ID'] = 314;
$_POST = ['wp_user_id' => '500'];
$r = call_endpoint(['MealsDB_Ajax_Clients', 'validate_wp_user']);
check(($r['data']['already_linked'] ?? null) === 314, 'T-2 already-linked returns the linked client id');
$GLOBALS['LINKED_CLIENT_ID'] = 0;

// ===========================================================================
// T-3 / T-4: pull endpoint maps + normalizes via the shared mapper
// ===========================================================================
seed_user(600, [
    'first_name'   => 'Ignored',
    'last_name'    => 'Ignored',
    'display_name' => 'Ignored Display',
    'user_email'   => 'acct@example.com',
], [
    'billing_first_name' => 'Mary',
    'billing_last_name'  => 'Sue',
    'billing_phone'      => '5065551234',          // → (506)-555-1234
    'billing_address_1'  => '12 Main St',
    'billing_city'       => 'Moncton',
    'billing_state'      => 'New Brunswick',        // → NB
    'billing_postcode'   => 'e1c 1a1',              // → E1C1A1
    'payment_method'     => 'cheque',
    'ordering_frequency' => '4',
    'delivery_frequency' => '2',
    'contribution'       => '25.50',
    'delivery_fee'       => '0',                    // zero → omitted
    // shipping empty → falls back to billing for delivery_* fields
]);

$_POST = ['wp_user_id' => '600'];
$r = call_endpoint(['MealsDB_Ajax_Clients', 'pull_wp_user_data']);
check($r['type'] === 'success', 'T-3 pull existing user → success');
$f = $r['data']['fields'] ?? [];

check(($f['first_name'] ?? '') === 'Mary', 'T-3 first_name from billing_first_name');
check(($f['last_name'] ?? '') === 'Sue', 'T-3 last_name from billing_last_name');
check(($f['client_email'] ?? '') === 'acct@example.com', 'T-3 client_email from account email');
check(($f['address_street_name'] ?? '') === '12 Main St', 'T-3 street from billing_address_1');
check(($f['address_city'] ?? '') === 'Moncton', 'T-3 city from billing_city');
check(($f['payment_method'] ?? '') === 'cheque', 'T-3 payment_method mapped');
check(($f['ordering_frequency'] ?? '') === '4', 'T-3 ordering_frequency mapped');
check(($f['delivery_frequency'] ?? '') === '2', 'T-3 delivery_frequency mapped');
check(($f['client_contribution'] ?? '') === '25.50', 'T-3 contribution mapped');
check(!array_key_exists('delivery_fee', $f), 'T-3 zero delivery_fee is omitted (not blanked)');

// T-4 normalization
check(($f['address_province'] ?? '') === 'NB', 'T-4 province "New Brunswick" → NB');
check(($f['address_postal'] ?? '') === 'E1C1A1', 'T-4 postal "e1c 1a1" → E1C1A1');
check(($f['phone_primary'] ?? '') === '(506)-555-1234', 'T-4 phone 5065551234 → (506)-555-1234');

// Delivery address falls back to billing when shipping meta is empty.
check(($f['delivery_address_street_name'] ?? '') === '12 Main St', 'T-4 delivery street falls back to billing');
check(($f['delivery_address_province'] ?? '') === 'NB', 'T-4 delivery province falls back + normalizes');
check(($f['delivery_address_postal'] ?? '') === 'E1C1A1', 'T-4 delivery postal falls back + normalizes');

// Shipping present → used over billing.
$GLOBALS['WP_USERMETA'][600]['shipping_address_1'] = '99 Other Rd';
$GLOBALS['WP_USERMETA'][600]['shipping_city']      = 'Dieppe';
$GLOBALS['WP_USERMETA'][600]['shipping_state']     = 'NB';
$GLOBALS['WP_USERMETA'][600]['shipping_postcode']  = 'E1A2B3';
$_POST = ['wp_user_id' => '600'];
$r = call_endpoint(['MealsDB_Ajax_Clients', 'pull_wp_user_data']);
$f = $r['data']['fields'] ?? [];
check(($f['delivery_address_street_name'] ?? '') === '99 Other Rd', 'T-4 shipping street used when present');
check(($f['delivery_address_city'] ?? '') === 'Dieppe', 'T-4 shipping city used when present');

// 11-digit NANP with country code → stripped + formatted.
$GLOBALS['WP_USERMETA'][600]['billing_phone'] = '15065559999';
$_POST = ['wp_user_id' => '600'];
$r = call_endpoint(['MealsDB_Ajax_Clients', 'pull_wp_user_data']);
$f = $r['data']['fields'] ?? [];
check(($f['phone_primary'] ?? '') === '(506)-555-9999', 'T-4 11-digit phone drops leading 1 and formats');

// pull on a nonexistent user → error; capability/rate-limit enforced.
$_POST = ['wp_user_id' => '77777'];
$r = call_endpoint(['MealsDB_Ajax_Clients', 'pull_wp_user_data']);
check($r['type'] === 'error', 'T-3 pull nonexistent user → error');

$GLOBALS['RL_OK'] = false;
$_POST = ['wp_user_id' => '600'];
$r = call_endpoint(['MealsDB_Ajax_Clients', 'pull_wp_user_data']);
check($r['type'] === 'error' && $r['status'] === 429, 'T-3 pull over rate limit → 429');
$GLOBALS['RL_OK'] = true;

// Mapper returns ONLY non-empty fields (a sparse user yields a small map).
seed_user(610, ['display_name' => 'Solo Name'], ['billing_phone' => '5065550000']);
$got = MealsDB_WP_User_Mapper::map_usermeta_to_client_fields(610);
check(($got['phone_primary'] ?? '') === '(506)-555-0000', 'mapper: sparse user maps the one field it has');
check(!array_key_exists('address_city', $got), 'mapper: empty fields are omitted entirely');
check(($got['first_name'] ?? '') === 'Solo', 'mapper: display name splits into first when no billing/account name');

// ---------------------------------------------------------------------------
if (!empty($failures)) {
    echo implode("\n", $failures) . "\n";
    echo sprintf("\n%d passed, %d FAILED\n", $passed, count($failures));
    exit(1);
}
echo sprintf("All %d assertions passed.\n", $passed);
