<?php
/**
 * Tests for directive K21 ITEM 6: the service centre -> delivery_area_zone
 * lookup is ONE shared, case-insensitive helper used by the migration.
 *
 * The migration used to hold a local case-SENSITIVE map
 * (['Moncton' => 'M', 'Sussex' => 'S', 'veterans' => null]), so a stored
 * 'moncton' missed and the zone came out NULL. That produced 19
 * derive_zone.unmatched events after the 2026-09-07 rebuild and blanked 15
 * SDNB legacy clients' zones (wrong invoice scope, wrong rural/urban rate).
 *
 * Part 1 unit-tests the helper. Part 2 drives the REAL phase-1 migration
 * (dry run, mocked $wpdb) and asserts on the derive_zone.unmatched trunk
 * event it emits, so the test proves the migration path uses the helper — not
 * just that the helper exists. Part 2's 'moncton' case fails against
 * v1.0.591 (K21 Test 17).
 *
 * Run: php tests/test-service-centre-zone-k21.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('current_user_can')) { function current_user_can($cap) { return true; } }
// K23: phase 1 now writes the Extra Mural skip line to the migration log (an option).
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $f = 0, $d = 512) { return json_encode($v, $f, $d); } }

// Any PHP warning/notice raised inside the migration file is a failure: an
// undefined variable there is how the Directive 7 $group regression hid.
$GLOBALS['migration_warnings'] = [];
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (strpos($file, 'class-migration-consolidated.php') !== false) {
        $GLOBALS['migration_warnings'][] = "$msg (line $line)";
        return true;
    }
    return false;
});

$failures = []; $passed = 0;
function chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $label;
}

$C = 'MealsDB_Operational_Constants';

// ---------------------------------------------------------------------------
// Part 1 — the helper
// ---------------------------------------------------------------------------
$cases = [
    'Moncton'      => 'M',
    'moncton'      => 'M',   // the production miss
    'MONCTON'      => 'M',
    '  Moncton  '  => 'M',
    "\tmoncton\n"  => 'M',
    'Sussex'       => 'S',
    'sussex'       => 'S',
    ' SUSSEX '     => 'S',
    'veterans'     => null,  // known: VAC has no SDNB zone
    'Veterans'     => null,
    ''             => null,
    'Halifax'      => null,  // unknown
    'Moncton East' => null,  // no prefix/fuzzy matching
];
foreach ($cases as $in => $expected) {
    $got = $C::zone_for_service_centre($in);
    chk($got === $expected, sprintf('zone_for_service_centre(%s) === %s (got %s)',
        var_export($in, true), var_export($expected, true), var_export($got, true)));
}
chk($C::zone_for_service_centre(null) === null, 'zone_for_service_centre(null) === null');

// is_known_service_centre separates "deliberately no zone" from "unknown".
chk($C::is_known_service_centre('Veterans') === true, "'Veterans' is known (null zone by design)");
chk($C::is_known_service_centre(' moncton ') === true, "' moncton ' is known");
chk($C::is_known_service_centre('Halifax') === false, "'Halifax' is unknown");
chk($C::is_known_service_centre('') === false, "blank is unknown");
chk($C::is_known_service_centre(null) === false, "null is unknown");

// Every zone the map produces must be one the billing layer understands.
chk($C::is_rural_zone($C::zone_for_service_centre('sussex')) === true, 'sussex -> rural zone');
chk($C::is_rural_zone($C::zone_for_service_centre('moncton')) === false, 'moncton -> urban zone');

// ---------------------------------------------------------------------------
// Part 2 — the real phase-1 migration uses the helper
// ---------------------------------------------------------------------------
// One SDNB candidate user whose service_centre_charged is fed per run. The dry
// run emits derive_zone.unmatched (before its short-circuit) when a non-Veteran
// client's zone resolves to NULL; the mock captures event-log inserts.
class ZoneK21Wpdb {
    public $usermeta = 'wp_usermeta';
    public $users    = 'wp_users';
    public $prefix   = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $service_centre = '';
    public $customer_group = 'sdnb';
    /** @var array<int, array<string,mixed>> */
    public $events = [];

    public function prepare($q, ...$a) { return $q; }
    public function get_var($q) {
        if (strpos($q, 'COUNT(DISTINCT user_id)') !== false) { return 1; }
        return null; // idempotency lookup: no existing client row
    }
    public function get_col($q) { return [42]; }
    public function get_results($q, $o = null) {
        return [
            ['meta_key' => 'customer_group',         'meta_value' => $this->customer_group],
            ['meta_key' => 'first_name',             'meta_value' => 'Test'],
            ['meta_key' => 'last_name',              'meta_value' => 'Client'],
            ['meta_key' => 'billing_address_2',      'meta_value' => 'Zone 1'],
            ['meta_key' => 'service_centre_charged', 'meta_value' => $this->service_centre],
        ];
    }
    public function get_row($q, $o = null) {
        return ['ID' => 42, 'display_name' => 'Test Client', 'user_email' => 't@example.com'];
    }
    public function insert($table, $data, $formats = null) {
        if (isset($data['event'])) { $this->events[] = $data; }
        return 1;
    }
    public function query($q) { return 0; }
}

$db = new ZoneK21Wpdb();
$GLOBALS['wpdb'] = $db;

/** Run phase 1 (dry) for one service centre value; return the unmatched events. */
$run = static function (string $service_centre, string $customer_group = 'sdnb') use ($db): array {
    $db->service_centre = $service_centre;
    $db->customer_group = $customer_group;
    $db->events = [];
    $res = MealsDB_Migration_Consolidated::run_phase_create_clients(0, true, []);
    if (!is_array($res) || isset($res['error'])) {
        return ['__error' => $res];
    }
    return array_values(array_filter($db->events, static function ($e) {
        return ($e['event'] ?? '') === 'derive_zone.unmatched';
    }));
};

foreach (['Moncton', 'moncton', ' MONCTON ', 'Sussex', 'sussex'] as $sc) {
    $ev = $run($sc);
    chk(!isset($ev['__error']), "phase 1 dry run ran for '$sc'");
    chk(count($ev) === 0, "migration: SDNB service centre '$sc' resolves to a zone (no derive_zone.unmatched)");
}

// Sanity: the harness DOES detect a miss, so the zero-event assertions above
// are meaningful rather than vacuous.
$ev = $run('Halifax');
chk(count($ev) === 1, "migration: unknown service centre 'Halifax' still emits derive_zone.unmatched");

// Veterans are excluded from the unmatched event by design.
$ev = $run('veterans', 'veterans');
chk(count($ev) === 0, "migration: Veteran with 'veterans' service centre emits no derive_zone.unmatched");

// No PHP warnings from the migration (regression: undefined $group, which
// silently dropped service_name_zone='rural'). 'Extra Mural' is skipped
// since K23 but must still raise no warning on the way out.
$run('Moncton', 'sdnb rural');
$run('Moncton', 'Extra Mural');
chk($GLOBALS['migration_warnings'] === [], 'migration phase 1 raises no PHP warnings: '
    . implode('; ', array_unique($GLOBALS['migration_warnings'])));

// The local map must not come back.
$src = (string) file_get_contents(dirname(__DIR__) . '/includes/services/class-migration-consolidated.php');
chk(strpos($src, '$zone_map') === false, 'migration no longer defines a local $zone_map');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
