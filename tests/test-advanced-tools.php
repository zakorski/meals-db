<?php
/**
 * Tests for MealsDB_Advanced_Tools — the settings-driven visibility toggle
 * for rare/destructive admin pages (admin UI consolidation spec 2026-07-16,
 * PR 1). Covers:
 *   - is_enabled() fail-safe semantics: default is HIDDEN — only an
 *     explicit truthy stored value shows the tools
 *   - maybe_hide_governed_menu_items() removes exactly the three governed
 *     submenu entries when disabled, and nothing when enabled
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
function add_action($hook, $cb, $priority = 10, $args = 1) { return true; }
$GLOBALS['removed_submenus'] = [];
function remove_submenu_page($parent, $slug) {
    $GLOBALS['removed_submenus'][] = [$parent, $slug];
    return true;
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

$GLOBALS['test_options'] = ['mealsdb_settings' => ['show_advanced_tools' => '1']];
assert_equal(true, MealsDB_Advanced_Tools::is_enabled(), "explicit '1' => enabled");

// ---------------------------------------------------------------------------
// maybe_hide_governed_menu_items()
// ---------------------------------------------------------------------------
$GLOBALS['test_options'] = [];
$GLOBALS['removed_submenus'] = [];
MealsDB_Advanced_Tools::maybe_hide_governed_menu_items();
assert_equal(
    [
        ['mealsdb', 'mealsdb_rate_definitions'],
        ['mealsdb', 'mealsdb-data-ops'],
        ['mealsdb', 'mealsdb-migration'],
    ],
    $GLOBALS['removed_submenus'],
    'disabled => the three governed submenu entries removed from mealsdb'
);

$GLOBALS['test_options'] = ['mealsdb_settings' => ['show_advanced_tools' => '1']];
$GLOBALS['removed_submenus'] = [];
MealsDB_Advanced_Tools::maybe_hide_governed_menu_items();
assert_equal([], $GLOBALS['removed_submenus'], 'enabled => nothing removed');

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
