<?php
/**
 * Tests for MealsDB_Admin_UI::retired_tab_target() v2 — the args-preserving
 * legacy-URL map (admin UI consolidation spec 2026-07-16 §6, PR 3). Every
 * old ?page=mealsdb&tab=… URL maps to its dedicated page with extra query
 * args (client_id, po_id, task_id, paged, filters) preserved. The v1
 * (string,string) signature from PR 2 could not express arg preservation —
 * this is the redesign PR 2's review called for.
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

$base = 'https://example.test/wp-admin/admin.php?page=';

// ---------------------------------------------------------------------------
// Simple rows (no extra args).
// ---------------------------------------------------------------------------
assert_equal($base . 'mealsdb-clients&tab=sync',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'sync']), 'sync');
assert_equal($base . 'mealsdb-clients&tab=add',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'add']), 'add');
assert_equal($base . 'mealsdb-clients&tab=add',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'drafts']), 'drafts => add');
assert_equal($base . 'mealsdb-clients&tab=sync&view=ignored',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'ignored']), 'ignored => sync sub-view');
assert_equal($base . 'mealsdb-packing-slips',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'slips']), 'slips');
assert_equal($base . 'mealsdb-purchase-orders',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'po']), 'po => PO page (PR 3 target)');
assert_equal($base . 'mealsdb-tasks',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'tasks']), 'tasks');
assert_equal($base . 'mealsdb-settings',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'settings']), 'settings');

// ---------------------------------------------------------------------------
// Args-passthrough (extras keep their order; forced args appended).
// ---------------------------------------------------------------------------
assert_equal($base . 'mealsdb-clients&action=edit&client_id=42&tab=list',
    MealsDB_Admin_UI::retired_tab_target(
        ['page' => 'mealsdb', 'tab' => 'clients', 'action' => 'edit', 'client_id' => '42']
    ), 'clients edit link preserves action + client_id');
assert_equal($base . 'mealsdb-clients&paged=3&search=smith&type_preset=sdnb&tab=list',
    MealsDB_Admin_UI::retired_tab_target(
        ['page' => 'mealsdb', 'tab' => 'clients', 'paged' => '3', 'search' => 'smith', 'type_preset' => 'sdnb']
    ), 'clients list filters preserved');
assert_equal($base . 'mealsdb-purchase-orders&po_id=7',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'po_admin', 'po_id' => '7']),
    'po_admin detail preserves po_id');
assert_equal($base . 'mealsdb-tasks&action=detail&task_id=9',
    MealsDB_Admin_UI::retired_tab_target(
        ['page' => 'mealsdb', 'tab' => 'tasks', 'action' => 'detail', 'task_id' => '9']
    ), 'task detail preserves action + task_id');

// ---------------------------------------------------------------------------
// Values are urlencoded; array-typed args are dropped, not fataled.
// ---------------------------------------------------------------------------
assert_equal($base . 'mealsdb-clients&search=o%27brien%20%26%20co&tab=list',
    MealsDB_Admin_UI::retired_tab_target(
        ['page' => 'mealsdb', 'tab' => 'clients', 'search' => "o'brien & co"]
    ), 'arg values urlencoded');
assert_equal($base . 'mealsdb-tasks&task_id=9',
    MealsDB_Admin_UI::retired_tab_target(
        ['page' => 'mealsdb', 'tab' => 'tasks', 'ids' => ['1', '2'], 'task_id' => '9']
    ), 'array-typed args dropped');

// ---------------------------------------------------------------------------
// Non-matches.
// ---------------------------------------------------------------------------
assert_equal(null, MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb']), 'no tab => Home renders, no redirect');
assert_equal(null, MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'bogus']), 'unknown tab => no redirect');
assert_equal(null, MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb-reports', 'tab' => 'clients']), 'other page => no redirect');
assert_equal(null, MealsDB_Admin_UI::retired_tab_target(['page' => ['mealsdb'], 'tab' => 'sync']), 'array page => no redirect');
assert_equal($base . 'mealsdb-clients&tab=sync',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'SYNC']), 'tab is case-normalized');

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
