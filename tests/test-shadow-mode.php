<?php
/**
 * Tests for MealsDB_Shadow_Mode — the flag that suppresses legacy-visible
 * side effects during the parallel trial.
 *
 * Covers the fail-safe semantics (the load-bearing property): anything
 * other than an explicit, readable "off" must read as ON.
 *
 * Run: php tests/test-shadow-mode.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

// Mutable option store so we can simulate each settings state.
$GLOBALS['__opt'] = [];
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['__opt']) ? $GLOBALS['__opt'][$name] : $default;
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = [];
$passed = 0;
function check($cond, $label) {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = $label; }
}
function set_settings($v) { $GLOBALS['__opt']['mealsdb_settings'] = $v; }

// --- Fail-safe cases: must be ON --------------------------------------------
unset($GLOBALS['__opt']['mealsdb_settings']);                 // option missing
check(MealsDB_Shadow_Mode::is_enabled() === true, 'missing option => ON');

set_settings('not-an-array');                                  // corrupt
check(MealsDB_Shadow_Mode::is_enabled() === true, 'non-array option => ON');

set_settings([]);                                              // key absent
check(MealsDB_Shadow_Mode::is_enabled() === true, 'key absent => ON');

set_settings(['shadow_mode' => '1']);                          // explicit on
check(MealsDB_Shadow_Mode::is_enabled() === true, "'1' => ON");

set_settings(['shadow_mode' => 'unexpected']);                 // junk value
check(MealsDB_Shadow_Mode::is_enabled() === true, 'unexpected value => ON');

// --- Explicit OFF cases -----------------------------------------------------
set_settings(['shadow_mode' => '0']);
check(MealsDB_Shadow_Mode::is_enabled() === false, "'0' => OFF");

set_settings(['shadow_mode' => 0]);
check(MealsDB_Shadow_Mode::is_enabled() === false, 'int 0 => OFF');

set_settings(['shadow_mode' => false]);
check(MealsDB_Shadow_Mode::is_enabled() === false, 'false => OFF');

set_settings(['shadow_mode' => '']);
check(MealsDB_Shadow_Mode::is_enabled() === false, "empty string => OFF");

// --- writes_allowed is the inverse ------------------------------------------
set_settings(['shadow_mode' => '0']);
check(MealsDB_Shadow_Mode::writes_allowed() === true, 'writes_allowed true when OFF');
set_settings(['shadow_mode' => '1']);
check(MealsDB_Shadow_Mode::writes_allowed() === false, 'writes_allowed false when ON');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
