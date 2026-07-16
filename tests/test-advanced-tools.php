<?php
/**
 * Tests for MealsDB_Advanced_Tools — the settings-driven visibility toggle
 * for rare/destructive admin pages (admin UI consolidation spec 2026-07-16,
 * PR 1). Covers:
 *   - is_enabled() fail-safe semantics: default is HIDDEN — only an
 *     explicit '1'/1/true stored value shows the tools
 *   - menu_parent(): 'mealsdb' (visible) when enabled, '' (registered but
 *     menu-less — the hidden-page pattern) when disabled
 *
 * Run with: php tests/test-advanced-tools.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// --- WP stubs ----------------------------------------------------------
$GLOBALS['test_options'] = [];
function get_option(string $name, $default = false) {
    return array_key_exists($name, $GLOBALS['test_options'])
        ? $GLOBALS['test_options'][$name]
        : $default;
}

// --- assertion helpers (test-hook-logger.php convention) ----------------
$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s",
        $label, var_export($expected, true), var_export($actual, true));
}

// ---------------------------------------------------------------------------
// is_enabled() — fail-safe is HIDDEN (opposite of shadow mode's fail-safe ON).
// ---------------------------------------------------------------------------
$GLOBALS['test_options'] = [];
assert_equal(false, MealsDB_Advanced_Tools::is_enabled(), 'missing option => disabled');

$GLOBALS['test_options'] = ['mealsdb_settings' => 'garbage'];
assert_equal(false, MealsDB_Advanced_Tools::is_enabled(), 'non-array option => disabled');

$GLOBALS['test_options'] = ['mealsdb_settings' => ['shadow_mode' => '1']];
assert_equal(false, MealsDB_Advanced_Tools::is_enabled(), 'key absent => disabled');

$GLOBALS['test_options'] = ['mealsdb_settings' => ['show_advanced_tools' => '0']];
assert_equal(false, MealsDB_Advanced_Tools::is_enabled(), "explicit '0' => disabled");

$GLOBALS['test_options'] = ['mealsdb_settings' => ['show_advanced_tools' => 'garbage']];
assert_equal(false, MealsDB_Advanced_Tools::is_enabled(), 'unrecognised value => disabled (strict on-check)');

$GLOBALS['test_options'] = ['mealsdb_settings' => ['show_advanced_tools' => '1']];
assert_equal(true, MealsDB_Advanced_Tools::is_enabled(), "explicit '1' => enabled");

$GLOBALS['test_options'] = ['mealsdb_settings' => ['show_advanced_tools' => 1]];
assert_equal(true, MealsDB_Advanced_Tools::is_enabled(), 'int 1 => enabled');

$GLOBALS['test_options'] = ['mealsdb_settings' => ['show_advanced_tools' => true]];
assert_equal(true, MealsDB_Advanced_Tools::is_enabled(), 'bool true => enabled');

// ---------------------------------------------------------------------------
// menu_parent()
// ---------------------------------------------------------------------------
$GLOBALS['test_options'] = [];
assert_equal('', MealsDB_Advanced_Tools::menu_parent(), 'disabled => hidden-page parent (empty string)');

$GLOBALS['test_options'] = ['mealsdb_settings' => ['show_advanced_tools' => '1']];
assert_equal('mealsdb', MealsDB_Advanced_Tools::menu_parent(), 'enabled => visible under mealsdb');

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
