<?php
/**
 * Tests for MealsDB_Admin_UI::retired_tab_target() — the legacy-URL map for
 * main-page tabs retired by the admin UI consolidation (spec 2026-07-16,
 * PR 2: slips + po). Bookmarks and muscle memory must keep working:
 *   - ?page=mealsdb&tab=slips → the Packing Slips page
 *   - ?page=mealsdb&tab=po    → the po_admin tab (until PR 3 moves it)
 *   - live tabs / other pages → null (no redirect)
 *
 * Run with: php tests/test-retired-tab-redirects.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// --- WP stubs ----------------------------------------------------------
function admin_url(string $path = '') {
    return 'https://example.test/wp-admin/' . $path;
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
// Retired tabs redirect.
// ---------------------------------------------------------------------------
assert_equal(
    'https://example.test/wp-admin/admin.php?page=mealsdb-packing-slips',
    MealsDB_Admin_UI::retired_tab_target('mealsdb', 'slips'),
    'slips => Packing Slips page'
);
assert_equal(
    'https://example.test/wp-admin/admin.php?page=mealsdb&tab=po_admin',
    MealsDB_Admin_UI::retired_tab_target('mealsdb', 'po'),
    'po => po_admin tab'
);

// ---------------------------------------------------------------------------
// Live tabs and foreign pages are untouched.
// ---------------------------------------------------------------------------
assert_equal(null, MealsDB_Admin_UI::retired_tab_target('mealsdb', 'po_admin'), 'live tab po_admin => no redirect');
assert_equal(null, MealsDB_Admin_UI::retired_tab_target('mealsdb', 'tasks'), 'live tab tasks => no redirect');
assert_equal(null, MealsDB_Admin_UI::retired_tab_target('mealsdb', ''), 'no tab => no redirect');
assert_equal(null, MealsDB_Admin_UI::retired_tab_target('mealsdb-reports', 'slips'), 'other page => no redirect');

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
