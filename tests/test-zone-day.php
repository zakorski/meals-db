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

if (empty($failures)) {
    echo "PASS — {$passed} checks\n";
    exit(0);
}
echo "FAIL\n" . implode("\n", $failures) . "\n";
exit(1);
