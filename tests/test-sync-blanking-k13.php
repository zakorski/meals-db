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

// ===== Task 2: ITEM 2 pure decision helper =====
// decide_field_action(bool $present, string $wp_value, string $client_value): string
chk(MealsDB_Sync::decide_field_action(false, '', '123 Main St') === 'skip_absent', 'ITEM2: absent key -> skip_absent (test 1)');
chk(MealsDB_Sync::decide_field_action(false, '', '') === 'skip_absent', 'ITEM2: absent key wins even when column empty');
chk(MealsDB_Sync::decide_field_action(true, '5 Elm St', '5 Elm St') === 'noop', 'ITEM2: equal -> noop');
chk(MealsDB_Sync::decide_field_action(true, '5 Elm St', 'Old Rd') === 'write', 'ITEM2: real diff -> write (test 3)');
chk(MealsDB_Sync::decide_field_action(true, '', '123 Main St') === 'skip_blank', 'ITEM2/3: present-empty over real -> skip_blank (test 2)');
chk(MealsDB_Sync::decide_field_action(true, '', '') === 'noop', 'ITEM2: empty over empty -> noop');

// presence-aware read helper
$rmp = new ReflectionMethod('MealsDB_Sync', 'read_wp_field_value_with_presence');
$rmp->setAccessible(true);
$GLOBALS['meta_store']  = ['9|billing_address_1' => '5 Elm St'];
$GLOBALS['meta_exists'] = ['9|billing_address_1' => true];
[$val, $present] = $rmp->invoke(null, new WP_User(9), ['type'=>'meta','key'=>'billing_address_1']);
chk($val === '5 Elm St' && $present === true, 'ITEM2: present meta read (value+present) (test 4)');
[$val2, $present2] = $rmp->invoke(null, new WP_User(9), ['type'=>'meta','key'=>'mealsdb_street_name']);
chk($val2 === '' && $present2 === false, 'ITEM2: absent meta read -> present=false');

// ===== Task 3: ITEM 3 mutator guards =====
$GLOBALS['wpdb'] = new wpdb();
$mut = new MealsDB_Sync_Mutate();
$rm = new ReflectionMethod('MealsDB_Sync_Mutate', 'apply_wp_user_update');
$rm->setAccessible(true);

// DB->WP (test 5): push empty over a populated billing_address_1 -> refused.
$GLOBALS['meta_store']  = ['7|billing_address_1' => '5 Elm St'];
$GLOBALS['meta_exists'] = ['7|billing_address_1' => true];
MealsDB_Event_Log::$events = [];
$res = $rm->invoke($mut, new WP_User(7), 'street_name', '');
chk(is_wp_error($res), 'ITEM3 DB->WP: empty over real -> WP_Error (test 5)');
chk($res->get_error_code() === 'mealsdb_sync_empty_overwrite_refused', 'ITEM3 DB->WP: refusal error code');
chk(($GLOBALS['meta_store']['7|billing_address_1'] ?? '') === '5 Elm St', 'ITEM3 DB->WP: billing_address_1 unchanged (test 5)');
$refused = array_filter(MealsDB_Event_Log::$events, fn($e) => ($e['event'] ?? '') === 'sync.empty_overwrite_refused');
chk(count($refused) === 1, 'ITEM3 DB->WP: one empty_overwrite_refused event');

// DB->WP (test 6): real over a different real value -> succeeds.
$GLOBALS['meta_store']  = ['7|billing_address_1' => 'Old Rd'];
$GLOBALS['meta_exists'] = ['7|billing_address_1' => true];
$res = $rm->invoke($mut, new WP_User(7), 'street_name', '9 Oak Ave');
chk($res === true, 'ITEM3 DB->WP: real over real -> success (test 6)');
chk(($GLOBALS['meta_store']['7|billing_address_1'] ?? '') === '9 Oak Ave', 'ITEM3 DB->WP: real value persisted');

// DB->WP: empty over ALREADY-empty is allowed (no refusal).
$GLOBALS['meta_store']  = ['7|billing_address_1' => ''];
$GLOBALS['meta_exists'] = ['7|billing_address_1' => true];
MealsDB_Event_Log::$events = [];
$res = $rm->invoke($mut, new WP_User(7), 'street_name', '');
chk(!is_wp_error($res), 'ITEM3 DB->WP: empty over empty -> not refused');

// ===== Task 4: ITEM 4 mass-blank breaker (pure helpers) =====
// is_over_mass_blank_threshold(int $blank_count, int $active_count): bool â strictly > 20%.
chk(MealsDB_Sync::is_over_mass_blank_threshold(21, 100) === true,  'ITEM4: 21/100 over threshold');
chk(MealsDB_Sync::is_over_mass_blank_threshold(20, 100) === false, 'ITEM4: 20/100 NOT over (boundary)');
chk(MealsDB_Sync::is_over_mass_blank_threshold(1, 100) === false,  'ITEM4: 1/100 under (test 8)');
chk(MealsDB_Sync::is_over_mass_blank_threshold(1, 0) === false,    'ITEM4: guards divide-by-zero');

// tally_blank_candidates: count ONLY present-but-empty. An absent key is skipped
// by the real write path (ITEM 2 metadata_exists), so it must NOT trip the breaker.
// $read_raw returns [value, present].
$rows = [];
for ($i = 1; $i <= 100; $i++) { $rows[] = ['wp_user_id' => $i, 'street_name' => '123 Main St']; }

// Present-but-empty on every client -> counts -> over threshold.
$read_present_empty = function (int $uid, array $desc) { return ['', true]; };
$tally = MealsDB_Sync::tally_blank_candidates($rows, ['street_name'], MealsDB_Sync::get_field_to_wp_meta_map(), $read_present_empty, 'wp_user_id');
chk(($tally['street_name'] ?? 0) === 100, 'ITEM4: 100 present-empty candidates counted (test 7)');
chk(MealsDB_Sync::is_over_mass_blank_threshold($tally['street_name'], count($rows)) === true, 'ITEM4: 100/100 present-empty -> abort (test 7)');

// Codex P1: ABSENT key on every client (populated column) is a SAFE skip (ITEM 2),
// NOT a blanking candidate -> tally 0 -> run must proceed, not brick the nightly sync.
$read_absent = function (int $uid, array $desc) { return ['', false]; };
$tally_absent = MealsDB_Sync::tally_blank_candidates($rows, ['street_name'], MealsDB_Sync::get_field_to_wp_meta_map(), $read_absent, 'wp_user_id');
chk(($tally_absent['street_name'] ?? 0) === 0, 'ITEM4: absent key is NOT a blank candidate (Codex P1)');
chk(MealsDB_Sync::is_over_mass_blank_threshold($tally_absent['street_name'] ?? 0, count($rows)) === false, 'ITEM4: absent key -> run proceeds (Codex P1)');

// Under threshold: only 1 client present-but-empty.
$rows2 = [];
for ($i = 1; $i <= 100; $i++) { $rows2[] = ['wp_user_id' => $i, 'street_name' => '123 Main St']; }
$read_one_empty = function (int $uid, array $desc) { return $uid === 1 ? ['', true] : ['5 Elm St', true]; };
$tally2 = MealsDB_Sync::tally_blank_candidates($rows2, ['street_name'], MealsDB_Sync::get_field_to_wp_meta_map(), $read_one_empty, 'wp_user_id');
chk(($tally2['street_name'] ?? -1) === 1, 'ITEM4: 1 present-empty candidate (test 8)');
chk(MealsDB_Sync::is_over_mass_blank_threshold($tally2['street_name'], count($rows2)) === false, 'ITEM4: 1/100 -> proceed (test 8)');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
