<?php
/**
 * DIRECTIVE 1 (ITEM 2) test — the reopen entry point parses $_GET['reopen_order']
 * into a positive int and is isolated from the clone entry point.
 *
 * A parked draft is reopened via ?page=mealsdb_quick_order&reopen_order=N.
 * MealsDB_Quick_Order_UI::get_requested_reopen_order_id() must:
 *   - return the absint of reopen_order when present,
 *   - return 0 when absent or non-numeric,
 *   - NOT be confused by the clone_order param (the two are distinct paths).
 *
 * Run: php tests/test-quick-order-reopen-id.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

// Minimal WP shims used by the parser.
if (!function_exists('wp_unslash')) {
    function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
}
if (!function_exists('absint')) {
    function absint($v) { return abs((int) $v); }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}

// Absent → 0.
unset($_GET['reopen_order'], $_GET['clone_order']);
chk(MealsDB_Quick_Order_UI::get_requested_reopen_order_id(), 0, 'absent reopen_order → 0');

// Present numeric → absint.
$_GET['reopen_order'] = '4821';
chk(MealsDB_Quick_Order_UI::get_requested_reopen_order_id(), 4821, 'reopen_order=4821 → 4821');

// Negative/garbage → absint (never negative).
$_GET['reopen_order'] = '-5';
chk(MealsDB_Quick_Order_UI::get_requested_reopen_order_id(), 5, 'reopen_order=-5 → 5 (absint)');

$_GET['reopen_order'] = 'abc';
chk(MealsDB_Quick_Order_UI::get_requested_reopen_order_id(), 0, 'reopen_order=abc → 0');

// A clone_order param must NOT be read as a reopen id (distinct paths).
unset($_GET['reopen_order']);
$_GET['clone_order'] = '999';
chk(MealsDB_Quick_Order_UI::get_requested_reopen_order_id(), 0, 'clone_order does not leak into reopen id');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
