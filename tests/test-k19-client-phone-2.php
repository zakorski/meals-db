<?php
/**
 * DIRECTIVE K19 — client_phone_2 is the WordPress shipping_phone.
 *
 *   T1  Sync reads shipping_phone into client_phone_2 (map + presence read +
 *       decision). FAILS against v1.0.588 (mapped to billing_phone_2).
 *   T2  A blank client_phone_2 pushed DB->WP does not overwrite a populated
 *       shipping_phone (K13 ITEM 3 guard, now reachable via shipping_phone).
 *   T3  Slip prints ONE phone line when client_phone_2 equals client_phone_1,
 *       including formatting-only and leading-country-code differences.
 *       FAILS against v1.0.588 (printed phone_secondary whenever non-empty).
 *   T4  Slip prints TWO lines when the numbers genuinely differ — including a
 *       multi-number field whose LAST number equals phone 1 (must not hide).
 *   AC  No sync mapping references billing_phone_2.
 *
 * Tests 5–7 (order billing/shipping phone) live in
 * test-quick-order-order-address.php next to the K15 cases they extend.
 *
 * Run: php tests/test-k19-client-phone-2.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

// --- WP / plugin stubs (same shape as test-sync-blanking-k13.php) -----------
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

$GLOBALS['TEST_OPTIONS'] = [];
if (!function_exists('get_option')) {
    function get_option($name, $default = false) { return $GLOBALS['TEST_OPTIONS'][$name] ?? $default; }
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

$GLOBALS['meta_store']  = [];   // "uid|key" => value
$GLOBALS['meta_exists'] = [];   // "uid|key" => true
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

// ===== T1: sync reads shipping_phone into client_phone_2 =====================
$map = MealsDB_Sync::get_field_to_wp_meta_map();
chk(($map['client_phone_2'] ?? null) === ['type' => 'meta', 'key' => 'shipping_phone'], 'T1: client_phone_2 maps to meta shipping_phone');
chk(in_array('client_phone_2', MealsDB_Sync::get_wp_authoritative_fields(), true), 'T1: client_phone_2 stays WP-authoritative');

$rmp = new ReflectionMethod('MealsDB_Sync', 'read_wp_field_value_with_presence');
$rmp->setAccessible(true);
$GLOBALS['meta_store']  = ['41|shipping_phone' => '506-858-7777', '41|billing_phone' => '506-858-1234'];
$GLOBALS['meta_exists'] = ['41|shipping_phone' => true, '41|billing_phone' => true];
[$val, $present] = $rmp->invoke(null, new WP_User(41), $map['client_phone_2']);
chk($val === '506-858-7777' && $present === true, 'T1: shipping_phone read as present value');
chk(MealsDB_Sync::decide_field_action($present, $val, '') === 'write', 'T1: empty column + shipping_phone -> write');

// Stored verbatim even when equal to billing_phone: the column mirrors WP.
$GLOBALS['meta_store']['41|shipping_phone'] = '506-858-1234';
[$val_same, $present_same] = $rmp->invoke(null, new WP_User(41), $map['client_phone_2']);
chk(MealsDB_Sync::decide_field_action($present_same, $val_same, '') === 'write', 'T1: shipping_phone == billing_phone is still written (dedup is display-only)');

// ===== T2: blank client_phone_2 does not overwrite populated shipping_phone ==
$GLOBALS['wpdb'] = new wpdb();
$mut = new MealsDB_Sync_Mutate();
$rm = new ReflectionMethod('MealsDB_Sync_Mutate', 'apply_wp_user_update');
$rm->setAccessible(true);

$GLOBALS['meta_store']  = ['42|shipping_phone' => '506-858-7777'];
$GLOBALS['meta_exists'] = ['42|shipping_phone' => true];
MealsDB_Event_Log::$events = [];
$res = $rm->invoke($mut, new WP_User(42), 'client_phone_2', '');
chk(is_wp_error($res) && $res->get_error_code() === 'mealsdb_sync_empty_overwrite_refused', 'T2: empty client_phone_2 push refused');
chk(($GLOBALS['meta_store']['42|shipping_phone'] ?? '') === '506-858-7777', 'T2: shipping_phone unchanged');
$refused = array_filter(MealsDB_Event_Log::$events, fn($e) => ($e['event'] ?? '') === 'sync.empty_overwrite_refused'
    && ($e['context']['meta_key'] ?? '') === 'shipping_phone');
chk(count($refused) === 1, 'T2: refusal recorded against shipping_phone');

// A real value still pushes (the allowlist accepts the new key).
$res = $rm->invoke($mut, new WP_User(42), 'client_phone_2', '506-858-8888');
chk($res === true, 'T2: real client_phone_2 push succeeds');
chk(($GLOBALS['meta_store']['42|shipping_phone'] ?? '') === '506-858-8888', 'T2: shipping_phone updated by real push');
chk(!array_key_exists('42|billing_phone_2', $GLOBALS['meta_store']), 'T2: nothing written to billing_phone_2');

// ===== T3: slip prints one line for a duplicate ==============================
$count_phone_lines = static fn (string $html): int => substr_count($html, 'class="d4-phone"');
$base = ['client_name' => 'Test Client', 'street' => '1 Main St', 'city' => 'Moncton', 'postal' => 'E1C 1A1'];

foreach ([
    'identical'          => ['506-858-1234', '506-858-1234'],
    'formatting only'    => ['506-858-1234', '(506)-858-1234'],
    'leading country 1'  => ['506-858-1234', '1-506-858-1234'],
] as $label => [$p1, $p2]) {
    $html = MealsDB_Slip_PDF_Generator::driver_block_inner_html($base + ['phone' => $p1, 'phone_secondary' => $p2]);
    chk($count_phone_lines($html) === 1, "T3: one phone line ({$label})");
}

// ===== T4: slip prints two lines for genuinely different numbers =============
foreach ([
    'different number'   => ['506-858-1234', '506-858-9999'],
    'multi-number field' => ['506-333-4444', '506-111-2222 / 506-333-4444'],
    'contact name'       => ['506-858-1234', '506-858-1234 Denise'],
] as $label => [$p1, $p2]) {
    $html = MealsDB_Slip_PDF_Generator::driver_block_inner_html($base + ['phone' => $p1, 'phone_secondary' => $p2]);
    chk($count_phone_lines($html) === 2, "T4: two phone lines ({$label})");
    chk(strpos($html, htmlspecialchars($p2, ENT_QUOTES)) !== false, "T4: secondary number printed ({$label})");
}

// Secondary alone (primary empty) still prints.
$html = MealsDB_Slip_PDF_Generator::driver_block_inner_html($base + ['phone' => '', 'phone_secondary' => '506-858-1234']);
chk($count_phone_lines($html) === 1 && strpos($html, '506-858-1234') !== false, 'T4: secondary prints when primary is empty');

// ===== AC: no mapping references billing_phone_2 =============================
foreach ($map as $field => $desc) {
    chk(($desc['key'] ?? '') !== 'billing_phone_2', "AC: {$field} does not map to billing_phone_2");
}

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
