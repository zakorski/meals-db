<?php
/**
 * Tests for MealsDB_Zone_Day — the single zone→delivery-day lookup
 * (spec 2026-07-11: zone schedule is the sole source of truth).
 *
 * Run: php tests/test-zone-day.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// get_option stub: tests control the schedule via $GLOBALS['zd_schedule'].
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        if ($key === 'mealsdb_zone_delivery_schedule') {
            return $GLOBALS['zd_schedule'] ?? $default;
        }
        return $default;
    }
}
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

$failures = []; $passed = 0;
function zd_check(string $label, $actual, $expected): void {
    global $failures, $passed;
    if ($actual === $expected) { $passed++; return; }
    $failures[] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
}

$GLOBALS['zd_schedule'] = [
    'Zone 1' => ['day' => 'Wednesday', 'label' => 'Wednesday morning - Moncton Downtown'],
    'Zone 5' => ['day' => 'Friday',    'label' => 'Friday - Dieppe / Riverview'],
    'Broken' => 'not-an-array',
    'NoDay'  => ['label' => 'day key missing'],
    'Blank'  => ['day' => '', 'label' => 'blank day'],
];

// Happy path: lowercase full day name.
zd_check('known zone', MealsDB_Zone_Day::day_for_zone('Zone 1'), 'wednesday');
zd_check('second zone', MealsDB_Zone_Day::day_for_zone('Zone 5'), 'friday');
// Whitespace tolerated on the lookup key.
zd_check('trimmed key', MealsDB_Zone_Day::day_for_zone('  Zone 1  '), 'wednesday');
// Null/blank/unknown → null (skip semantics, never a fatal).
zd_check('null zone', MealsDB_Zone_Day::day_for_zone(null), null);
zd_check('blank zone', MealsDB_Zone_Day::day_for_zone('   '), null);
zd_check('unknown zone', MealsDB_Zone_Day::day_for_zone('Zone 99'), null);
// Corrupt entries → null, no warning.
zd_check('non-array config', MealsDB_Zone_Day::day_for_zone('Broken'), null);
zd_check('missing day', MealsDB_Zone_Day::day_for_zone('NoDay'), null);
zd_check('blank day', MealsDB_Zone_Day::day_for_zone('Blank'), null);

// schedule(): only well-formed entries, day preserved in original case for display.
$sched = MealsDB_Zone_Day::schedule();
zd_check('schedule keys', array_keys($sched), ['Zone 1', 'Zone 5']);
zd_check('schedule day case', $sched['Zone 1']['day'], 'Wednesday');
zd_check('schedule label', $sched['Zone 5']['label'], 'Friday - Dieppe / Riverview');

// Empty/absent option → everything null/empty.
$GLOBALS['zd_schedule'] = [];
zd_check('empty schedule lookup', MealsDB_Zone_Day::day_for_zone('Zone 1'), null);
zd_check('empty schedule map', MealsDB_Zone_Day::schedule(), []);

if (!class_exists('MealsDB_Logger', false)) {
    class MealsDB_Logger {
        public static array $logged = [];
        public static function log(string $action, int $target_id, string $field, ?string $old, ?string $new, string $source = 'mealsdb') {
            self::$logged[] = compact('action', 'target_id', 'field', 'old', 'new');
        }
        public static function error(string $m): void {}
    }
}
if (!class_exists('MealsDB_Event_Log', false)) {
    class MealsDB_Event_Log {
        public static array $events = [];
        public static function record(array $e): void { self::$events[] = $e; }
    }
}
if (!class_exists('MealsDB_DB', false)) {
    class MealsDB_DB { public static function get_table_name(string $t): string { return 'wp_' . $t; } }
}
if (!class_exists('MealsDB_Tables', false)) {
    class MealsDB_Tables { public const CLIENTS = 'meals_clients'; }
}

/**
 * wpdb stub: records queries; get_results returns canned rows per call;
 * query() returns a canned rows_affected per matching UPDATE.
 */
class ZdWpdb {
    public array $queries = [];
    public array $results_queue = [];
    public int $rows_affected = 0;
    public int $affected_per_update = 0;
    public function prepare($sql, ...$args) {
        foreach ($args as $a) {
            $sql = preg_replace('/%[ds]/', is_int($a) ? (string) $a : "'" . $a . "'", $sql, 1);
        }
        return $sql;
    }
    public function query($sql) {
        $this->queries[] = $sql;
        $this->rows_affected = $this->affected_per_update;
        return $this->affected_per_update;
    }
    public function get_results($sql, $output = null) {
        $this->queries[] = $sql;
        return array_shift($this->results_queue) ?? [];
    }
    public function get_var($sql) {
        $this->queries[] = $sql;
        return array_shift($this->results_queue) ?? 0;
    }
}

// ------------------------------------------------------------------
// propagate_schedule_change()
// ------------------------------------------------------------------
$GLOBALS['zd_schedule'] = []; // propagate takes schedules as args, not the option
$wpdb_stub = new ZdWpdb();
$wpdb_stub->affected_per_update = 3;
$GLOBALS['wpdb'] = $wpdb_stub;
MealsDB_Logger::$logged = [];
MealsDB_Event_Log::$events = [];

$old = [
    'Zone 1' => ['day' => 'Wednesday', 'label' => 'a'],
    'Zone 2' => ['day' => 'Wednesday', 'label' => 'b'],
    'Gone'   => ['day' => 'Friday',    'label' => 'c'],
];
$new = [
    'Zone 1' => ['day' => 'Thursday',  'label' => 'a'],  // changed
    'Zone 2' => ['day' => 'Wednesday', 'label' => 'b2'], // label-only change: no propagation
];
$wpdb_stub->results_queue = [2]; // get_var: 2 active clients still reference 'Gone'
$stats = MealsDB_Zone_Day::propagate_schedule_change($old, $new);

zd_check('propagate: changed zones', $stats['changed_zones'], ['Zone 1']);
zd_check('propagate: rows updated', $stats['clients_updated'], 3);
zd_check('propagate: dropped zones', $stats['dropped_zones'], ['Gone' => 2]);
$has_update = false;
foreach ($wpdb_stub->queries as $q) {
    if (strpos($q, 'UPDATE') !== false && strpos($q, "'thursday'") !== false && strpos($q, "'Zone 1'") !== false) {
        $has_update = true;
    }
}
zd_check('propagate: UPDATE uses lowercase day + zone key', $has_update, true);
zd_check('propagate: audit-logged', count(MealsDB_Logger::$logged), 1);
zd_check('propagate: dropped zone degraded event', MealsDB_Event_Log::$events[0]['outcome'] ?? '', 'degraded');

// No changes → no writes, no events.
$wpdb_stub->queries = [];
MealsDB_Event_Log::$events = [];
$stats = MealsDB_Zone_Day::propagate_schedule_change($new, $new);
zd_check('propagate no-op: zones', $stats['changed_zones'], []);
zd_check('propagate no-op: no queries', $wpdb_stub->queries, []);
zd_check('propagate no-op: no events', MealsDB_Event_Log::$events, []);

// ------------------------------------------------------------------
// resync_all()
// ------------------------------------------------------------------
$GLOBALS['zd_schedule'] = [
    'Zone 1' => ['day' => 'Wednesday', 'label' => 'a'],
    'Zone 5' => ['day' => 'Friday',    'label' => 'b'],
];
$wpdb_stub = new ZdWpdb();
$wpdb_stub->affected_per_update = 4; // per-zone UPDATE reports 4 corrected rows
$GLOBALS['wpdb'] = $wpdb_stub;
MealsDB_Event_Log::$events = [];
// One get_results call: the orphan SELECT.
$wpdb_stub->results_queue = [
    [
        ['client_id' => 7, 'first_name' => 'A', 'last_name' => 'B', 'delivery_area_name' => 'Old Zone'],
    ],
];
$out = MealsDB_Zone_Day::resync_all();

zd_check('resync: updated', $out['updated'], 8); // 2 zones × 4
zd_check('resync: orphan count', count($out['orphans']), 1);
zd_check('resync: orphan zone value', $out['orphans'][0]['delivery_area_name'], 'Old Zone');
zd_check('resync: orphan degraded event', MealsDB_Event_Log::$events[0]['event'] ?? '', 'delivery_day.orphaned_clients');
$zone_updates = 0;
foreach ($wpdb_stub->queries as $q) {
    if (strpos($q, 'UPDATE') !== false) { $zone_updates++; }
}
zd_check('resync: one UPDATE per zone', $zone_updates, 2);

// Empty schedule → refuses (null), no queries.
$GLOBALS['zd_schedule'] = [];
$wpdb_stub->queries = [];
zd_check('resync: empty schedule refuses', MealsDB_Zone_Day::resync_all(), null);
zd_check('resync: empty schedule no queries', $wpdb_stub->queries, []);

if (empty($failures)) {
    echo "PASS — {$passed} checks\n";
    exit(0);
}
echo "FAIL\n" . implode("\n", $failures) . "\n";
exit(1);
