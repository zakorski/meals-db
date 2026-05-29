<?php
/**
 * Tests for MealsDB_Rate_Definitions + the save endpoint (directive
 * DEFINITIONS-1). Covers T-1 … T-7 from the directive:
 *   T-1 accessor falls back to seed (no option set)
 *   T-2 option overrides seed; un-set keys still return their seed
 *   T-3 unknown key → null (no invented value)
 *   T-4 save validation: negative / non-numeric / over-ceiling rejected;
 *       valid set persists
 *   T-5 consumer migration: get_sdnb_side_rate() returns the Definitions
 *       value after an override (proves the generator reads through the accessor)
 *   T-6 audit per change: one changed + one unchanged rate → exactly ONE
 *       rate_definition_edit audit row
 *   T-7 endpoint security: rejected on bad nonce / missing manage_options /
 *       rate limit, BEFORE any option write or audit row
 *
 * (T-8 draft point-in-time lives with the draft fixtures — the draft layer
 * already proves resolved-rate self-containment in test-invoice-draft.php.)
 *
 * Run: php tests/test-rate-definitions.php
 *
 * Uses option stubs backed by $GLOBALS['TEST_OPTIONS'] and an in-memory $wpdb
 * that captures audit-log INSERTs, with the REAL Rate_Definitions / Logger /
 * Operational_Constants exercised end-to-end.
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }

// ---------------------------------------------------------------------------
// Controllable guard flags + recorded JSON response.
// ---------------------------------------------------------------------------
$GLOBALS['NONCE_OK'] = true;
$GLOBALS['CAP_OK']   = true;
$GLOBALS['RL_OK']    = true;
$GLOBALS['JSON']     = null;
$GLOBALS['TEST_OPTIONS'] = [];

// ---------------------------------------------------------------------------
// Minimal WP function stubs.
// ---------------------------------------------------------------------------
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['TEST_OPTIONS']) ? $GLOBALS['TEST_OPTIONS'][$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $prev = $GLOBALS['TEST_OPTIONS'][$name] ?? null;
        $GLOBALS['TEST_OPTIONS'][$name] = $value;
        return $prev !== $value; // mirror WP: false when unchanged.
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return 7; }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) { return gmdate('Y-m-d H:i:s'); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($v) {
        if (is_array($v)) { return array_map('wp_unslash', $v); }
        return is_string($v) ? stripslashes($v) : $v;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return is_string($v) ? trim(preg_replace('/[\r\n\t]+/', ' ', $v)) : $v; }
}
if (!function_exists('absint')) {
    function absint($v) { return abs((int) $v); }
}
if (!function_exists('__')) {
    function __($t, $d = null) { return $t; }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($a, $n = false, $die = true) { return (bool) $GLOBALS['NONCE_OK']; }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return (bool) $GLOBALS['CAP_OK']; }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status = null) {
        $GLOBALS['JSON'] = ['type' => 'success', 'data' => $data, 'status' => $status];
    }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status = null) {
        $GLOBALS['JSON'] = ['type' => 'error', 'data' => $data, 'status' => $status];
    }
}
if (!function_exists('add_action')) { function add_action() { return true; } }

// Rate_Limiter stub (defined before the autoloader so the real one is skipped).
class MealsDB_Rate_Limiter {
    public static function check_rate_limit(string $bucket, ?int $uid = null): bool { return (bool) $GLOBALS['RL_OK']; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!class_exists('wpdb')) { class wpdb {} }

/** In-memory wpdb capturing audit-log INSERTs (MealsDB_Logger::log). */
class RateDefWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public array $audit = [];

    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }

    public function query($q) {
        if (stripos($q, 'meals_audit_log') !== false && stripos($q, 'INSERT') !== false) {
            $this->audit[] = $q;
            return 1;
        }
        return true;
    }
}

$GLOBALS['wpdb'] = new RateDefWpdb();

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------
$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function chk_true($cond, $label) { chk((bool) $cond, true, $label); }

function reset_state(): void {
    $GLOBALS['TEST_OPTIONS'] = [];
    $GLOBALS['NONCE_OK'] = true;
    $GLOBALS['CAP_OK']   = true;
    $GLOBALS['RL_OK']    = true;
    $GLOBALS['JSON']     = null;
    $GLOBALS['wpdb']->audit = [];
}

// ---------------------------------------------------------------------------
// T-1 — accessor falls back to seed
// ---------------------------------------------------------------------------
reset_state();
chk(MealsDB_Rate_Definitions::get('vac_per_main_coverage'), 10.64, 'T-1 seed fallback (vac coverage)');
chk(MealsDB_Rate_Definitions::get('sdnb_side'), 4.48, 'T-1 seed fallback (sdnb_side)');
$all = MealsDB_Rate_Definitions::all();
chk(count($all), 11, 'T-1 all() returns full seed set');
chk($all['private_combo'], 13.75, 'T-1 all() includes born-here private_combo');

// ---------------------------------------------------------------------------
// T-2 — option overrides seed; un-set keys still return seed
// ---------------------------------------------------------------------------
reset_state();
chk_true(MealsDB_Rate_Definitions::save(['vac_per_main_coverage' => 11.14]), 'T-2 save returns true');
chk(MealsDB_Rate_Definitions::get('vac_per_main_coverage'), 11.14, 'T-2 override wins');
chk(MealsDB_Rate_Definitions::get('vac_side'), 4.10, 'T-2 un-set key still seed');

// ---------------------------------------------------------------------------
// T-3 — unknown key → null
// ---------------------------------------------------------------------------
reset_state();
chk(MealsDB_Rate_Definitions::get('not_a_rate'), null, 'T-3 unknown key → null');

// ---------------------------------------------------------------------------
// T-4 — save validation
// ---------------------------------------------------------------------------
reset_state();
chk(MealsDB_Rate_Definitions::save(['sdnb_side' => -1]), false, 'T-4 negative rejected');
chk_true(!array_key_exists('mealsdb_rate_definitions', $GLOBALS['TEST_OPTIONS']), 'T-4 negative wrote nothing');
chk(MealsDB_Rate_Definitions::save(['sdnb_side' => 'abc']), false, 'T-4 non-numeric rejected');
chk(MealsDB_Rate_Definitions::save(['sdnb_side' => 1114]), false, 'T-4 over-ceiling rejected');
chk_true(MealsDB_Rate_Definitions::save(['sdnb_side' => 5.00]), 'T-4 valid persists');
chk(MealsDB_Rate_Definitions::get('sdnb_side'), 5.00, 'T-4 valid value readable');
// Unknown keys are silently dropped, not a hard failure.
chk_true(MealsDB_Rate_Definitions::save(['sdnb_side' => 5.00, 'bogus' => 1]), 'T-4 unknown key dropped, save ok');

// ---------------------------------------------------------------------------
// T-5 — consumer migration: Operational_Constants reads through the accessor
// ---------------------------------------------------------------------------
reset_state();
chk(MealsDB_Operational_Constants::get_sdnb_side_rate(false), 4.48, 'T-5 side rate = seed pre-override');
chk_true(MealsDB_Rate_Definitions::save(['sdnb_side' => 5.00]), 'T-5 override side rate');
chk(MealsDB_Operational_Constants::get_sdnb_side_rate(false), 5.00, 'T-5 side rate now reads Definitions');
chk(MealsDB_Operational_Constants::get_sdnb_main_rate('primary', true), 15.47, 'T-5 rural main still seed');
chk_true(MealsDB_Rate_Definitions::save(['sdnb_primary_main_rural' => 16.00]), 'T-5 override rural main');
chk(MealsDB_Operational_Constants::get_sdnb_main_rate('primary', true), 16.00, 'T-5 rural main now Definitions');

// ---------------------------------------------------------------------------
// T-6 — audit per change (one changed + one unchanged → exactly ONE row)
// ---------------------------------------------------------------------------
reset_state();
$_POST = [
    'nonce'   => 'x',
    'confirm' => '1',
    'rates'   => [
        'vac_per_main_coverage' => '11.14', // changed from seed 10.64
        'vac_side'              => '4.10',   // unchanged from seed
    ],
];
MealsDB_Ajax_Rate_Definitions::save();
chk($GLOBALS['JSON']['type'] ?? null, 'success', 'T-6 endpoint success');
chk($GLOBALS['JSON']['data']['changed'] ?? null, 1, 'T-6 reports one change');
chk(count($GLOBALS['wpdb']->audit), 1, 'T-6 exactly one audit row');
chk_true(strpos($GLOBALS['wpdb']->audit[0], 'rate_definition_edit') !== false, 'T-6 audit action correct');
chk_true(strpos($GLOBALS['wpdb']->audit[0], 'vac_per_main_coverage') !== false, 'T-6 audit field correct');
chk_true(strpos($GLOBALS['wpdb']->audit[0], '10.64') !== false && strpos($GLOBALS['wpdb']->audit[0], '11.14') !== false, 'T-6 audit old→new captured');
chk(MealsDB_Rate_Definitions::get('vac_per_main_coverage'), 11.14, 'T-6 value persisted via endpoint');

// ---------------------------------------------------------------------------
// T-7 — endpoint security: rejected before any write/audit
// ---------------------------------------------------------------------------
// bad nonce
reset_state();
$GLOBALS['NONCE_OK'] = false;
$_POST = ['nonce' => 'x', 'confirm' => '1', 'rates' => ['sdnb_side' => '9.99']];
MealsDB_Ajax_Rate_Definitions::save();
chk($GLOBALS['JSON']['type'] ?? null, 'error', 'T-7 bad nonce → error');
chk(count($GLOBALS['wpdb']->audit), 0, 'T-7 bad nonce → no audit');
chk_true(!array_key_exists('mealsdb_rate_definitions', $GLOBALS['TEST_OPTIONS']), 'T-7 bad nonce → no option write');

// missing capability
reset_state();
$GLOBALS['CAP_OK'] = false;
$_POST = ['nonce' => 'x', 'confirm' => '1', 'rates' => ['sdnb_side' => '9.99']];
MealsDB_Ajax_Rate_Definitions::save();
chk($GLOBALS['JSON']['type'] ?? null, 'error', 'T-7 no cap → error');
chk(count($GLOBALS['wpdb']->audit), 0, 'T-7 no cap → no audit');
chk_true(!array_key_exists('mealsdb_rate_definitions', $GLOBALS['TEST_OPTIONS']), 'T-7 no cap → no option write');

// rate limited
reset_state();
$GLOBALS['RL_OK'] = false;
$_POST = ['nonce' => 'x', 'confirm' => '1', 'rates' => ['sdnb_side' => '9.99']];
MealsDB_Ajax_Rate_Definitions::save();
chk($GLOBALS['JSON']['type'] ?? null, 'error', 'T-7 rate limited → error');
chk($GLOBALS['JSON']['status'] ?? null, 429, 'T-7 rate limited → 429');
chk(count($GLOBALS['wpdb']->audit), 0, 'T-7 rate limited → no audit');

// missing confirmation friction
reset_state();
$_POST = ['nonce' => 'x', 'rates' => ['sdnb_side' => '9.99']];
MealsDB_Ajax_Rate_Definitions::save();
chk($GLOBALS['JSON']['type'] ?? null, 'error', 'T-7 no confirm → error');
chk(count($GLOBALS['wpdb']->audit), 0, 'T-7 no confirm → no audit');
chk_true(!array_key_exists('mealsdb_rate_definitions', $GLOBALS['TEST_OPTIONS']), 'T-7 no confirm → no option write');

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
if (empty($failures)) {
    echo "Ran " . $passed . " checks: " . $passed . " passed, 0 failed\n";
    exit(0);
}
echo "Ran " . ($passed + count($failures)) . " checks: " . $passed . " passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "  FAIL: $f\n"; }
exit(1);
