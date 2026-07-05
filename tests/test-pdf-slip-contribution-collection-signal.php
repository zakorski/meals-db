<?php
/**
 * U06-slips-3: the monthly client contribution is collected at the door on the
 * delivery of the ORDER that carries the contribution fee line — not on a
 * calendar "first delivery of month" guess.
 *
 * MealsDB_Order_Fees::apply_to_order bills the client_contribution fee product
 * (rendered as SKU CONT) onto EXACTLY ONE order per client per billing month
 * (an atomic claim). MealsDB_Slip_PDF_Generator::order_carries_contribution()
 * is the authoritative "collect the contribution on this delivery" signal: it
 * returns true iff the delivered order's line items include that product. This
 * cannot over-collect (only one order carries the line) or under-collect (that
 * order gets delivered), and makes billed == collected.
 *
 * This replaced is_first_delivery_of_month(), which read the contribution_applied
 * summary flag — set at ORDER time, before any delivery — and so was already 1
 * by the time the first slip generated, silently suppressing door collection.
 *
 * Run: php tests/test-pdf-slip-contribution-collection-signal.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

$ref = new ReflectionMethod('MealsDB_Slip_PDF_Generator', 'order_carries_contribution');
$ref->setAccessible(true);

$CONTRIB_ID = 5675;
$call = static function (array $order, int $contrib_id) use ($ref) {
    return $ref->invoke(null, $order, $contrib_id);
};

$failures = 0;
$check = static function (bool $cond, string $label) use (&$failures) {
    if ($cond) {
        echo "  PASS: {$label}\n";
    } else {
        echo "  FAIL: {$label}\n";
        $failures++;
    }
};

// The order carries the contribution fee line -> collect.
$with_contrib = ['items' => [
    ['wc_product_id' => 101,         'quantity' => 5, 'order_item_name' => 'Meal'],
    ['wc_product_id' => $CONTRIB_ID, 'quantity' => 1, 'order_item_name' => 'Client Contribution'],
]];
$check($call($with_contrib, $CONTRIB_ID) === true, 'order carrying the CONT line -> collect the contribution');

// Meals only, no contribution line -> do NOT collect (any delivery). This is
// the LB-4 no-over-collection guarantee, now structural.
$meals_only = ['items' => [['wc_product_id' => 101, 'quantity' => 5, 'order_item_name' => 'Meal']]];
$check($call($meals_only, $CONTRIB_ID) === false, 'order without the CONT line -> never collect');

// Contribution product id unknown (0) -> never collect (fail safe).
$check($call($with_contrib, 0) === false, 'unknown contribution product id -> not collected');

// Missing / empty items -> not collected.
$check($call(['items' => []], $CONTRIB_ID) === false, 'empty items -> not collected');
$check($call([], $CONTRIB_ID) === false, 'missing items key -> not collected');

if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s).\n";
    exit(1);
}
echo "OK: all contribution-collection-signal cases passed.\n";
