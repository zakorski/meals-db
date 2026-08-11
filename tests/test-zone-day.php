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
    public array $updates = [];
    public array $results_queue = [];
    public function prepare($sql, ...$args) {
        foreach ($args as $a) {
            $sql = preg_replace('/%[ds]/', is_int($a) ? (string) $a : "'" . $a . "'", $sql, 1);
        }
        return $sql;
    }
    public function query($sql) {
        $this->queries[] = $sql;
        return 0;
    }
    public function get_results($sql, $output = null) {
        $this->queries[] = $sql;
        return array_shift($this->results_queue) ?? [];
    }
    public function get_var($sql) {
        $this->queries[] = $sql;
        return array_shift($this->results_queue) ?? 0;
    }
    public function update($table, $data, $where, $format = null, $where_format = null) {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
        return 1;
    }
}

if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = false) {
        return $GLOBALS['zd_meta'][$user_id][$key] ?? '';
    }
}
// Lowercase full weekday name of a Y-m-d (UTC), for asserting the fix moved
// next_delivery_date onto the zone's day.
function zd_weekday(string $ymd): string {
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $ymd)) { return ''; }
    return strtolower((new DateTimeImmutable($ymd, new DateTimeZone('UTC')))->format('l'));
}

// ------------------------------------------------------------------
// propagate_schedule_change() — per-client: corrects delivery_day AND
// recomputes next_delivery_date so the stored date can't keep the old weekday.
// ------------------------------------------------------------------
$GLOBALS['zd_schedule'] = []; // propagate takes schedules as args, not the option
$GLOBALS['zd_meta'] = [];
$wpdb_stub = new ZdWpdb();
$GLOBALS['wpdb'] = $wpdb_stub;
MealsDB_Logger::$logged = [];
MealsDB_Event_Log::$events = [];

$old = [
    'Zone 1' => ['day' => 'Wednesday', 'label' => 'a'],
    'Zone 2' => ['day' => 'Wednesday', 'label' => 'b'],
    'Gone'   => ['day' => 'Friday',    'label' => 'c'],
];
$new = [
    'Zone 1' => ['day' => 'Thursday',  'label' => 'a'],  // changed Wed -> Thu
    'Zone 2' => ['day' => 'Wednesday', 'label' => 'b2'], // label-only change: no propagation
];
// Zone 1 client whose day is being changed; stored date is on a non-Thursday.
$prop_client = ['client_id' => 7, 'wp_user_id' => 0, 'delivery_frequency' => 1,
                'delivery_day' => 'wednesday', 'next_delivery_date' => '2099-08-19'];
// Call order: get_results(Zone 1 clients) -> get_var(COUNT for dropped 'Gone').
$wpdb_stub->results_queue = [
    [$prop_client],
    2, // 2 active clients still reference 'Gone'
];
$stats = MealsDB_Zone_Day::propagate_schedule_change($old, $new);

zd_check('propagate: changed zones', $stats['changed_zones'], ['Zone 1']);
zd_check('propagate: clients updated', $stats['clients_updated'], 1);
zd_check('propagate: dates recomputed', $stats['dates_recomputed'], 1);
zd_check('propagate: dropped zones', $stats['dropped_zones'], ['Gone' => 2]);
$prop_data = null;
foreach ($wpdb_stub->updates as $u) {
    if (($u['where']['client_id'] ?? null) === 7) { $prop_data = $u['data']; }
}
zd_check('propagate: delivery_day written lowercase', $prop_data['delivery_day'] ?? null, 'thursday');
zd_check('propagate: next_delivery_date moved to Thursday', zd_weekday($prop_data['next_delivery_date'] ?? ''), 'thursday');
zd_check('propagate: next_delivery_date via Date_Calculator',
    $prop_data['next_delivery_date'] ?? '',
    MealsDB_Date_Calculator::snap_to_delivery_day('2099-08-19', 'thursday'));
zd_check('propagate: audit-logged', count(MealsDB_Logger::$logged), 1);
zd_check('propagate: dropped zone degraded event', MealsDB_Event_Log::$events[0]['outcome'] ?? '', 'degraded');

// No changes → no writes, no events.
$wpdb_stub->updates = [];
$wpdb_stub->queries = [];
MealsDB_Event_Log::$events = [];
$stats = MealsDB_Zone_Day::propagate_schedule_change($new, $new);
zd_check('propagate no-op: zones', $stats['changed_zones'], []);
zd_check('propagate no-op: no updates', $wpdb_stub->updates, []);
zd_check('propagate no-op: no events', MealsDB_Event_Log::$events, []);

// ------------------------------------------------------------------
// resync_all() — per-client sweep: corrects delivery_day AND recomputes
// next_delivery_date, INCLUDING clients whose day is already correct but whose
// stored date drifted onto the wrong weekday (the 12C.1 remediation case).
// ------------------------------------------------------------------
$GLOBALS['zd_schedule'] = [
    'Zone 1' => ['day' => 'Wednesday', 'label' => 'a'],
    'Zone 5' => ['day' => 'Friday',    'label' => 'b'],
];
$GLOBALS['zd_meta'] = [555 => ['last_delivery_date' => '2099-08-05']];
$wpdb_stub = new ZdWpdb();
$GLOBALS['wpdb'] = $wpdb_stub;
MealsDB_Event_Log::$events = [];

$already_wed = MealsDB_Date_Calculator::snap_to_delivery_day('2099-08-21', 'wednesday'); // a real Wednesday
$zone1_clients = [
    // C1: wrong day AND wrong-weekday date -> both corrected.
    ['client_id' => 7,  'wp_user_id' => 0,   'delivery_frequency' => 1, 'delivery_day' => 'friday',    'next_delivery_date' => '2099-08-21'],
    // C2: day already correct, stored date on the wrong weekday -> date only (the Marjorie case).
    ['client_id' => 8,  'wp_user_id' => 0,   'delivery_frequency' => 1, 'delivery_day' => 'wednesday', 'next_delivery_date' => '2099-08-21'],
    // C3: fully correct -> skipped, no write.
    ['client_id' => 9,  'wp_user_id' => 0,   'delivery_frequency' => 1, 'delivery_day' => 'wednesday', 'next_delivery_date' => $already_wed],
    // C4: correct day, empty stored date -> seeded from last_delivery_date usermeta.
    ['client_id' => 10, 'wp_user_id' => 555, 'delivery_frequency' => 1, 'delivery_day' => 'wednesday', 'next_delivery_date' => ''],
];
// Call order: get_results(orphans) -> get_results(Zone 1) -> get_results(Zone 5) -> get_var(COUNT).
$wpdb_stub->results_queue = [
    [ ['client_id' => 99, 'first_name' => 'A', 'last_name' => 'B', 'delivery_area_name' => 'Old Zone'] ],
    $zone1_clients,
    [],
    10,
];
$out = MealsDB_Zone_Day::resync_all();

zd_check('resync: updated (days changed)', $out['updated'], 1);       // only C1's day changed
zd_check('resync: dates recomputed', $out['dates_recomputed'], 3);   // C1, C2, C4
zd_check('resync: already_correct', $out['already_correct'], 9);     // 10 - 1
zd_check('resync: orphan count', count($out['orphans']), 1);
zd_check('resync: orphan zone value', $out['orphans'][0]['delivery_area_name'], 'Old Zone');
zd_check('resync: orphan degraded event', MealsDB_Event_Log::$events[0]['event'] ?? '', 'delivery_day.orphaned_clients');

$byid = [];
foreach ($wpdb_stub->updates as $u) { $byid[$u['where']['client_id']] = $u['data']; }

// C1: day corrected, and next_delivery_date snapped onto a Wednesday.
zd_check('resync C1: delivery_day corrected', $byid[7]['delivery_day'] ?? null, 'wednesday');
zd_check('resync C1: next_delivery on Wednesday', zd_weekday($byid[7]['next_delivery_date'] ?? ''), 'wednesday');
zd_check('resync C1: next_delivery via Date_Calculator',
    $byid[7]['next_delivery_date'] ?? '',
    MealsDB_Date_Calculator::snap_to_delivery_day('2099-08-21', 'wednesday'));
// C2 (Marjorie): day already correct, so NO delivery_day in the patch — only the date.
zd_check('resync C2: delivery_day untouched', isset($byid[8]['delivery_day']), false);
zd_check('resync C2: drifted date fixed to Wednesday', zd_weekday($byid[8]['next_delivery_date'] ?? ''), 'wednesday');
// C3: already correct on both axes -> no write at all.
zd_check('resync C3: fully correct skipped', isset($byid[9]), false);
// C4: empty stored date seeded from last_delivery_date usermeta, on a Wednesday.
zd_check('resync C4: seeded on Wednesday', zd_weekday($byid[10]['next_delivery_date'] ?? ''), 'wednesday');
zd_check('resync C4: seeded via Date_Calculator',
    $byid[10]['next_delivery_date'] ?? '',
    MealsDB_Date_Calculator::next_date('2099-08-05', 1, 'wednesday'));

// Empty schedule → refuses (null), no queries.
$GLOBALS['zd_schedule'] = [];
$wpdb_stub->queries = [];
$wpdb_stub->updates = [];
zd_check('resync: empty schedule refuses', MealsDB_Zone_Day::resync_all(), null);
zd_check('resync: empty schedule no queries', $wpdb_stub->queries, []);

if (empty($failures)) {
    echo "PASS — {$passed} checks\n";
    exit(0);
}
echo "FAIL\n" . implode("\n", $failures) . "\n";
exit(1);
