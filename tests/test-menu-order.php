<?php
/**
 * Tests for MealsDB_Admin_UI::order_submenu_items() — the canonical
 * submenu ordering for the Meals DB menu (admin UI consolidation spec
 * 2026-07-16, PR 3). Registration priorities across six page classes are
 * left alone; a late admin_menu hook sorts $submenu['mealsdb'] into the
 * spec's order instead. Unknown slugs sort after all known ones, keeping
 * their relative order (so a future page never vanishes).
 *
 * Run with: php tests/test-menu-order.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// --- WP stub (class-admin-ui.php parse only; nothing else called) --------
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

/** Build a minimal WP submenu entry: [0]=title, [1]=cap, [2]=slug. */
function entry(string $slug): array {
    return [$slug . ' title', 'manage_woocommerce', $slug];
}

// ---------------------------------------------------------------------------
// Registration order (today's reality) sorts into the spec's menu order.
// ---------------------------------------------------------------------------
$registered = [
    entry('mealsdb'),
    entry('meals-db-staff'),
    entry('mealsdb_quick_order'),
    entry('mealsdb-clients'),
    entry('mealsdb-tasks'),
    entry('mealsdb-purchase-orders'),
    entry('mealsdb-settings'),
    entry('mealsdb-reports'),
    entry('mealsdb-data-ops'),
    entry('mealsdb_cron_status'),
    entry('mealsdb_event_log'),
    entry('mealsdb-invoices'),
    entry('mealsdb-packing-slips'),
    entry('mealsdb-migration'),
    entry('mealsdb_rate_definitions'),
];
$sorted = MealsDB_Admin_UI::order_submenu_items($registered);
assert_equal(
    [
        'mealsdb',
        'mealsdb_quick_order',
        'mealsdb-clients',
        'mealsdb-tasks',
        'mealsdb-packing-slips',
        'mealsdb-purchase-orders',
        'mealsdb-invoices',
        'mealsdb-reports',
        'meals-db-staff',
        'mealsdb_cron_status',
        'mealsdb_event_log',
        'mealsdb-settings',
        'mealsdb_rate_definitions',
        'mealsdb-data-ops',
        'mealsdb-migration',
    ],
    array_column($sorted, 2),
    'full registration set sorts into spec order'
);

// ---------------------------------------------------------------------------
// Unknown slugs land after all known ones, preserving relative order.
// ---------------------------------------------------------------------------
$with_unknown = [
    entry('future-page-b'),
    entry('mealsdb-tasks'),
    entry('future-page-a'),
    entry('mealsdb'),
];
$sorted = MealsDB_Admin_UI::order_submenu_items($with_unknown);
assert_equal(
    ['mealsdb', 'mealsdb-tasks', 'future-page-b', 'future-page-a'],
    array_column($sorted, 2),
    'unknown slugs: after known, original relative order kept'
);

// ---------------------------------------------------------------------------
// Entries survive untouched (only order changes) and missing slug index is
// tolerated.
// ---------------------------------------------------------------------------
$odd = [['Only title', 'read'], entry('mealsdb')];
$sorted = MealsDB_Admin_UI::order_submenu_items($odd);
assert_equal('mealsdb', $sorted[0][2] ?? '', 'slugless entry sorts after known entries');
assert_equal(['Only title', 'read'], $sorted[1], 'slugless entry passes through untouched');

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
