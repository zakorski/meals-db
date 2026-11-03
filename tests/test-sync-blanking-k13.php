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
chk(($map['client_phone_2']['key'] ?? '') === 'shipping_phone', 'ITEM1 (K19): client_phone_2 -> shipping_phone');
chk(($map['alternate_contact_name']['key'] ?? '') === 'alternate_contact_name', 'ITEM1: alt_contact_name unprefixed');
chk(($map['alternate_contact_phone_1']['key'] ?? '') === 'alternate_contact_phone_1', 'ITEM1: alt_phone_1 unprefixed');
chk(($map['alternate_contact_phone_2']['key'] ?? '') === 'alternate_contact_phone_2', 'ITEM1: alt_phone_2 unprefixed');
chk(($map['alternate_contact_email']['key'] ?? '') === 'alternate_contact_email', 'ITEM1: alt_email unprefixed');
// No remaining mapping may point at a mealsdb_* address/contact key.
foreach (['street_name','delivery_street_name','client_phone_2','alternate_contact_name','alternate_contact_phone_1','alternate_contact_phone_2','alternate_contact_email'] as $f) {
    chk(strpos($map[$f]['key'] ?? '', 'mealsdb_') !== 0, "ITEM1: {$f} no longer mealsdb_ prefixed");
}

// ===== Task 2: ITEM 2/3 decisions, restated for K21 =====
// decide_field_action() (the WP-wins decision) and the presence-aware reader
// were removed with the WP-wins nightly and hooks (K21 ITEMS 7 + 13). The K13
// guarantees are now properties of MealsDB_Sync_Reconciler::evaluate_field(),
// which every sync path uses. An absent WP key reads as '' (blank).
$E = 'MealsDB_Sync_Reconciler';
$r = $E::evaluate_field('street_name', '', [1 => '123 Main St']);
chk($r['outcome'] === 'fill' && $r['fill_wp'] === true && $r['fill_clients'] === [], 'ITEM2/3: absent or empty WP over a populated column -> fill WP, never blank MealsDB (tests 1, 2)');
chk($E::evaluate_field('street_name', '', [1 => ''])['outcome'] === 'blank', 'ITEM2: empty over empty -> nothing');
chk($E::evaluate_field('street_name', '5 Elm St', [1 => '5 elm st'])['outcome'] === 'equal', 'ITEM2: equal (normalised) -> no write');
chk($E::evaluate_field('street_name', '5 Elm St', [1 => 'Old Rd'])['outcome'] === 'conflict', 'K21: real difference -> conflict, no write (was WP-wins write, test 3)');
$r = $E::evaluate_field('street_name', '5 Elm St', [1 => '']);
chk($r['outcome'] === 'fill' && $r['fill_clients'] === [1], 'ITEM2: present WP value fills a blank column (test 4)');

// ===== Task 3: ITEM 3 empty-overwrite guard on the live WordPress writer =====
// K21: MealsDB_Sync_Mutate::apply_wp_user_update() was retired; the one live
// WordPress writer is MealsDB_Sync_Resolver::write_wp_field(), which carries
// the same K13 guard.
$W = static function (int $uid, string $column, string $value) {
    return MealsDB_Sync_Resolver::write_wp_field(new WP_User($uid), MealsDB_Sync_Registry::get($column), $column, $value, 'sync_resolve_wp', 'test');
};

// DB->WP (test 5): empty over a populated billing_address_1 -> refused.
$GLOBALS['meta_store']  = ['7|billing_address_1' => '5 Elm St'];
$GLOBALS['meta_exists'] = ['7|billing_address_1' => true];
MealsDB_Event_Log::$events = [];
$res = $W(7, 'street_name', '');
chk(is_wp_error($res), 'ITEM3 DB->WP: empty over real -> WP_Error (test 5)');
chk($res->get_error_code() === 'mealsdb_sync_empty_overwrite_refused', 'ITEM3 DB->WP: refusal error code');
chk(($GLOBALS['meta_store']['7|billing_address_1'] ?? '') === '5 Elm St', 'ITEM3 DB->WP: billing_address_1 unchanged (test 5)');
$refused = array_filter(MealsDB_Event_Log::$events, fn($e) => ($e['event'] ?? '') === 'sync.empty_overwrite_refused');
chk(count($refused) === 1, 'ITEM3 DB->WP: one empty_overwrite_refused event');

// DB->WP (test 6): real over a different real value -> succeeds. (The
// writer is also resolution's writer; callers decide WHEN to overwrite.)
$GLOBALS['meta_store']  = ['7|billing_address_1' => 'Old Rd'];
$res = $W(7, 'street_name', '9 Oak Ave');
chk($res === true, 'ITEM3 DB->WP: real over real -> success (test 6)');
chk(($GLOBALS['meta_store']['7|billing_address_1'] ?? '') === '9 Oak Ave', 'ITEM3 DB->WP: real value persisted');

// DB->WP: empty over ALREADY-empty is allowed (no refusal).
$GLOBALS['meta_store']  = ['7|billing_address_1' => ''];
MealsDB_Event_Log::$events = [];
$res = $W(7, 'street_name', '');
chk(!is_wp_error($res), 'ITEM3 DB->WP: empty over empty -> not refused');

// ===== Task 4: ITEM 4 mass-blank breaker (pure helpers) =====
// is_over_mass_blank_threshold(int $blank_count, int $active_count): bool â strictly > 20%.
chk(MealsDB_Sync::is_over_mass_blank_threshold(21, 100) === true,  'ITEM4: 21/100 over threshold');
chk(MealsDB_Sync::is_over_mass_blank_threshold(20, 100) === false, 'ITEM4: 20/100 NOT over (boundary)');
chk(MealsDB_Sync::is_over_mass_blank_threshold(1, 100) === false,  'ITEM4: 1/100 under (test 8)');
chk(MealsDB_Sync::is_over_mass_blank_threshold(1, 0) === false,    'ITEM4: guards divide-by-zero');

// tally_blank_candidates() and the nightly pre-flight it fed were retired
// with the WP-wins nightly loop (K21 ITEM 7): under fill-blank a WP key that
// is present-but-empty over a populated column is a fill of WordPress, not a
// blanking. The breaker is restated in MealsDB_Sync_Reconciler::breaker() and
// tested in tests/test-sync-nightly-k21.php (same threshold helper as above).

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
