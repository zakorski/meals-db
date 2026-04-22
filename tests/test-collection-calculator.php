<?php
/**
 * Tests for MealsDB_Collection_Calculator — the shared cash-collection
 * math used by driver and packing slips. Verifies the three private
 * branches (cash / non-cash with fee / prepaid) and the two government
 * branches (with / without first-delivery contribution).
 *
 * Run with: php tests/test-collection-calculator.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = [];
$passed = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}

// ---------------------------------------------------------------------------
// for_private — cash payment collects total + delivery fee
// ---------------------------------------------------------------------------
$cash = MealsDB_Collection_Calculator::for_private(48.21, 10.00, 'cash');
assert_equal(58.21, $cash['collect'], 'cash: collects total + delivery fee');
assert_equal(false, $cash['is_prepaid'], 'cash: is_prepaid=false');

// Zero delivery fee still works for cash.
$cash_no_fee = MealsDB_Collection_Calculator::for_private(25.00, 0.00, 'cash');
assert_equal(25.00, $cash_no_fee['collect'], 'cash with no delivery fee: collects order total');
assert_equal(false, $cash_no_fee['is_prepaid'], 'cash with no delivery fee: is_prepaid=false');

// ---------------------------------------------------------------------------
// for_private — non-cash with delivery fee collects only the fee
// ---------------------------------------------------------------------------
$stripe = MealsDB_Collection_Calculator::for_private(48.21, 10.00, 'stripe');
assert_equal(10.00, $stripe['collect'], 'stripe+fee: collects only delivery fee');
assert_equal(false, $stripe['is_prepaid'], 'stripe+fee: is_prepaid=false');

$bank = MealsDB_Collection_Calculator::for_private(30.00, 5.00, 'bank');
assert_equal(5.00, $bank['collect'], 'bank+fee: collects only delivery fee');
assert_equal(false, $bank['is_prepaid'], 'bank+fee: is_prepaid=false');

// ---------------------------------------------------------------------------
// for_private — non-cash with zero delivery fee is prepaid
// ---------------------------------------------------------------------------
$prepaid = MealsDB_Collection_Calculator::for_private(48.21, 0.00, 'stripe');
assert_equal(null, $prepaid['collect'], 'stripe no fee: nothing to collect');
assert_equal(true, $prepaid['is_prepaid'], 'stripe no fee: is_prepaid=true');

// Negative delivery fee is clamped to zero (defensive).
$neg_fee = MealsDB_Collection_Calculator::for_private(40.00, -5.00, 'stripe');
assert_equal(null, $neg_fee['collect'], 'negative fee clamped → prepaid');
assert_equal(true, $neg_fee['is_prepaid'], 'negative fee clamped → is_prepaid=true');

// ---------------------------------------------------------------------------
// for_government — first delivery with contribution
// ---------------------------------------------------------------------------
$gov_first = MealsDB_Collection_Calculator::for_government(10.00, 50.00, true);
assert_equal(60.00, $gov_first['collect'], 'gov first delivery: fee + contribution');
assert_equal(50.00, $gov_first['contribution_due'], 'gov first delivery: contribution_due set');
assert_equal(false, $gov_first['is_prepaid'], 'gov: never prepaid');

// ---------------------------------------------------------------------------
// for_government — non-first delivery: fee only
// ---------------------------------------------------------------------------
$gov_rest = MealsDB_Collection_Calculator::for_government(10.00, 50.00, false);
assert_equal(10.00, $gov_rest['collect'], 'gov non-first: fee only');
assert_equal(0.00, $gov_rest['contribution_due'], 'gov non-first: contribution_due=0');

// First delivery but contribution is 0: no contribution applied.
$gov_no_contrib = MealsDB_Collection_Calculator::for_government(10.00, 0.00, true);
assert_equal(10.00, $gov_no_contrib['collect'], 'gov first with 0 contribution: fee only');
assert_equal(0.00, $gov_no_contrib['contribution_due'], 'gov first with 0 contribution: contribution_due=0');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
