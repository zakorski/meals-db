<?php
/**
 * Test for MealsDB_Migration_Consolidated phase 8 (Backfill Delivery Day).
 *
 *   - phase 8 is registered in phases()
 *   - dry run counts blanks, writes nothing
 *   - real run UPDATEs blank delivery_day from the zone schedule
 *   - no schedule => clean no-op complete
 *
 * Run: php tests/test-consolidated-delivery-day.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

$GLOBALS['__opt'] = [];
if (!function_exists('get_option')) {
    function get_option($n, $d = false) { return array_key_exists($n, $GLOBALS['__opt']) ? $GLOBALS['__opt'][$n] : $d; }
}
if (!function_exists('__')) { function __($t, $d = 'default') { return $t; } }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// Fake wpdb capturing UPDATEs and answering blank-counts.
class DDWpdb {
    public $prefix = 'wp_';
    public int $rows_affected = 0;
    public array $updates = [];
    public int $blank_count = 3;
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++; return $m[0] === '%s' ? "'".$v."'" : (string)(int)$v;
        }, $q);
    }
    public function get_var($q) {
        if (stripos($q, 'COUNT(*)') !== false) { return (string) $this->blank_count; }
        return null;
    }
    public function query($q) {
        if (stripos($q, 'UPDATE') !== false) { $this->updates[] = $q; $this->rows_affected = 2; return 2; }
        return 0;
    }
}

$failures = []; $passed = 0;
function check($c, $l) { global $failures, $passed; if ($c) { $passed++; } else { $failures[] = $l; } }

// phase registered
$phases = MealsDB_Migration_Consolidated::phases();
check(isset($phases[8]) && $phases[8]['method'] === 'run_phase_delivery_day', 'phase 8 registered as delivery_day');

// schedule present
$GLOBALS['__opt']['mealsdb_zone_delivery_schedule'] = [
    'Moncton' => ['day' => 'Tuesday'],
    'Sussex'  => ['day' => 'Thursday'],
];

// dry run: counts, no UPDATE
$GLOBALS['wpdb'] = new DDWpdb();
$r = MealsDB_Migration_Consolidated::run_phase_delivery_day(0, true);
check(empty($GLOBALS['wpdb']->updates), 'dry run performs no UPDATE');
check(($r['stats']['would_update'] ?? null) === 6, 'dry run counts blanks across zones (3+3)');
check($r['complete'] === true, 'dry run completes in one pass');

// real run: UPDATEs each zone
$GLOBALS['wpdb'] = new DDWpdb();
$r = MealsDB_Migration_Consolidated::run_phase_delivery_day(0, false);
check(count($GLOBALS['wpdb']->updates) === 2, 'real run issues one UPDATE per zone');
check(strpos($GLOBALS['wpdb']->updates[0], "delivery_day IS NULL OR delivery_day = ''") !== false, 'real run only fills blanks');
check(($r['stats']['updated'] ?? null) === 4, 'real run reports rows updated (2+2)');

// no schedule: clean no-op
$GLOBALS['__opt']['mealsdb_zone_delivery_schedule'] = [];
$GLOBALS['wpdb'] = new DDWpdb();
$r = MealsDB_Migration_Consolidated::run_phase_delivery_day(0, false);
check($r['complete'] === true && empty($GLOBALS['wpdb']->updates), 'no schedule => no-op complete');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
