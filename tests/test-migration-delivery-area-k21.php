<?php
/**
 * Tests for directive K21 ITEM 4 in the migration: delivery_area_name is read
 * from shipping_address_2, falling back to billing_address_2 only when
 * shipping is blank — the same source the K21 sync compares against — in
 * both phase 1 (create clients) and the addresses phase.
 *
 * Before this change both read billing_address_2 alone, so a rebuild wrote a
 * zone the nightly then flagged as a conflict for any user whose two keys
 * differ.
 *
 * Run: php tests/test-migration-delivery-area-k21.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('current_user_can')) { function current_user_can($cap) { return true; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $f = 0, $d = 512) { return json_encode($v, $f, $d); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 0; } }

$failures = []; $passed = 0;
function chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $label;
}

$M = 'MealsDB_Migration_Consolidated';

// ---------------------------------------------------------------------------
// The helper
// ---------------------------------------------------------------------------
chk($M::delivery_area_from_meta(['shipping_address_2' => 'Zone 3', 'billing_address_2' => 'Zone 1']) === 'Zone 3',
    'shipping_address_2 wins when both are set (the Gloria Cudahy case)');
chk($M::delivery_area_from_meta(['shipping_address_2' => '', 'billing_address_2' => 'Zone 1']) === 'Zone 1',
    'billing_address_2 used only when shipping is blank');
chk($M::delivery_area_from_meta(['shipping_address_2' => '   ', 'billing_address_2' => 'Zone 1']) === 'Zone 1',
    'whitespace-only shipping counts as blank');
chk($M::delivery_area_from_meta(['billing_address_2' => 'Zone 1']) === 'Zone 1', 'absent shipping key falls back');
chk($M::delivery_area_from_meta(['shipping_address_2' => 'Zone 3']) === 'Zone 3', 'absent billing key is fine');
chk($M::delivery_area_from_meta([]) === null && $M::delivery_area_from_meta(['shipping_address_2' => '', 'billing_address_2' => '']) === null,
    'both blank -> null');
chk($M::delivery_area_from_meta(['shipping_address_2' => ' Zone 3 ']) === ' Zone 3 ', 'value returned raw (not normalised)');
$field = MealsDB_Sync_Registry::get('delivery_area_name');
chk($field['wp']['key'] === 'shipping_address_2' && $field['wp']['consistency'] === 'billing_address_2',
    'the helper and the sync read the same registry keys');

// ---------------------------------------------------------------------------
// A fake wpdb good enough for both phases. prepare() substitutes values so
// the SQL the phases build (and embed) is inspectable.
// ---------------------------------------------------------------------------
class wpdb {
    public $prefix = 'wp_'; public $usermeta = 'wp_usermeta'; public $users = 'wp_users';
    public $last_error = ''; public $insert_id = 0;
    public array $user_meta = [];   // uid => [key => value]
    public array $clients = [];     // addresses phase rows
    public array $events = [];
    public array $updates = [];
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[sdf]/', static function ($m) use (&$i, $a) {
            $v = $a[$i++] ?? '';
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (0 + $v);
        }, $q);
    }
    public function get_var($q) {
        if (strpos($q, 'COUNT(DISTINCT user_id)') !== false) { return (string) count($this->user_meta); }
        if (strpos($q, 'COUNT(*)') !== false) { return (string) count($this->clients); }
        return null; // phase 1 idempotency: no existing client row
    }
    public function get_col($q) {
        if (strpos($q, 'INFORMATION_SCHEMA') !== false) { return []; }
        return array_keys($this->user_meta);
    }
    public function get_results($q, $o = null) {
        if (strpos($q, 'meta_key IN') !== false) {
            $out = [];
            foreach ($this->user_meta as $uid => $meta) {
                foreach ($meta as $k => $v) { $out[] = ['user_id' => $uid, 'meta_key' => $k, 'meta_value' => $v]; }
            }
            return $out;
        }
        if (preg_match('/WHERE user_id = (\d+)/', $q, $m)) {
            $out = [];
            foreach ($this->user_meta[(int) $m[1]] ?? [] as $k => $v) { $out[] = ['meta_key' => $k, 'meta_value' => $v]; }
            return $out;
        }
        if (strpos($q, 'FROM `wp_meals_clients`') !== false) { return $this->clients; }
        return [];
    }
    public function get_row($q, $o = null) {
        return ['ID' => 1, 'display_name' => 'Test Client', 'user_email' => 't@example.com'];
    }
    public function insert($t, $d, $f = null) { if (isset($d['event'])) { $this->events[] = $d['event']; } return 1; }
    public function query($q) { if (stripos(trim($q), 'UPDATE') === 0) { $this->updates[] = $q; } return 1; }
}

// ---------------------------------------------------------------------------
// Phase 1 (dry run emits derive_area.missing when the area resolves empty)
// ---------------------------------------------------------------------------
$db = new wpdb();
$GLOBALS['wpdb'] = $db;
$base = ['customer_group' => 'sdnb', 'first_name' => 'T', 'last_name' => 'C', 'service_centre_charged' => 'Moncton'];

$db->user_meta = [42 => $base + ['shipping_address_2' => 'Zone 3', 'billing_address_2' => '']];
$M::run_phase_create_clients(0, true, []);
chk(!in_array('derive_area.missing', $db->events, true), 'phase 1: area taken from shipping_address_2 when billing is blank (old code: missing)');

$db->events = [];
$db->user_meta = [42 => $base + ['billing_address_2' => 'Zone 1']];
$M::run_phase_create_clients(0, true, []);
chk(!in_array('derive_area.missing', $db->events, true), 'phase 1: falls back to billing_address_2');

$db->events = [];
$db->user_meta = [42 => $base + ['shipping_address_2' => '', 'billing_address_2' => '']];
$M::run_phase_create_clients(0, true, []);
chk(in_array('derive_area.missing', $db->events, true), 'phase 1: both blank still raises derive_area.missing');

// ---------------------------------------------------------------------------
// Addresses phase (live), fill-only
// ---------------------------------------------------------------------------
$db = new wpdb();
$GLOBALS['wpdb'] = $db;
$db->clients = [
    ['client_id' => 1, 'wp_user_id' => 10, 'delivery_area_name' => '', 'street_name' => 'A', 'delivery_street_name' => 'B', 'default_rate_id' => 5],
    ['client_id' => 2, 'wp_user_id' => 11, 'delivery_area_name' => 'Zone 1', 'street_name' => 'A', 'delivery_street_name' => 'B', 'default_rate_id' => 5],
    ['client_id' => 3, 'wp_user_id' => 12, 'delivery_area_name' => '', 'street_name' => 'A', 'delivery_street_name' => 'B', 'default_rate_id' => 5],
];
$db->user_meta = [
    10 => ['billing_address_1' => 'A', 'shipping_address_1' => 'B', 'shipping_address_2' => 'Zone 3', 'billing_address_2' => 'Zone 1'],
    11 => ['billing_address_1' => 'A', 'shipping_address_1' => 'B', 'shipping_address_2' => 'Zone 3', 'billing_address_2' => 'Zone 3'],
    12 => ['billing_address_1' => 'A', 'shipping_address_1' => 'B', 'billing_address_2' => 'Zone 2'],
];
$stats = $M::run_phase_addresses(0, false, []);
$by_client = [];
foreach ($db->updates as $sql) {
    if (preg_match('/WHERE client_id = (\d+)/', $sql, $m)) { $by_client[(int) $m[1]] = $sql; }
}
chk(isset($by_client[1]) && strpos($by_client[1], "delivery_area_name = 'Zone 3'") !== false,
    'addresses phase: blank area filled from shipping_address_2, not billing');
chk(!isset($by_client[2]), 'addresses phase: populated area left alone (fill-only)');
chk(isset($by_client[3]) && strpos($by_client[3], "delivery_area_name = 'Zone 2'") !== false,
    'addresses phase: billing fallback when shipping absent');
chk(($stats['stats']['zones_fixed'] ?? 0) === 2, 'addresses phase: zones_fixed counts both fills');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
