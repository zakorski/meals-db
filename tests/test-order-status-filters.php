<?php
/**
 * BC-7 regression tests — failed/refunded/checkout-draft orders must not be
 * treated as live (they were printing delivery slips and inflating POs because
 * the default exclude-status list admitted them).
 *
 * The two order-pull entry points (get_orders_for_users,
 * get_orders_with_items_for_users) default $exclude_statuses; the slip and PO
 * paths use that default. The fix adds wc-failed, wc-refunded, and
 * wc-checkout-draft to it. (wc-pending stays INCLUDED — a meal may be cooked
 * before payment clears; that is the documented status-quo behaviour.)
 *
 * Run: php tests/test-order-status-filters.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}

function default_exclude_statuses(string $method): array {
    $rm = new ReflectionMethod(MealsDB_WC_Order_Query::class, $method);
    foreach ($rm->getParameters() as $p) {
        if ($p->getName() === 'exclude_statuses' && $p->isDefaultValueAvailable()) {
            return (array) $p->getDefaultValue();
        }
    }
    return [];
}

foreach (['get_orders_for_users', 'get_orders_with_items_for_users'] as $method) {
    $defaults = default_exclude_statuses($method);

    chk(in_array('wc-failed', $defaults, true), true,    "$method: excludes wc-failed");
    chk(in_array('wc-refunded', $defaults, true), true,  "$method: excludes wc-refunded");
    chk(in_array('wc-checkout-draft', $defaults, true), true, "$method: excludes wc-checkout-draft");

    // The pre-existing exclusions must remain.
    chk(in_array('wc-cancelled', $defaults, true), true, "$method: still excludes wc-cancelled");
    chk(in_array('wc-trash', $defaults, true), true,     "$method: still excludes wc-trash");

    // Pending stays IN (counted) per the documented decision.
    chk(in_array('wc-pending', $defaults, true), false,  "$method: wc-pending remains counted (not excluded)");
}

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
