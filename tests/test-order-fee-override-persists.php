<?php
/**
 * Characterization test for the per-client fee override (audit-2026-08 H13 /
 * directive LB-2).
 *
 * MealsDB_Order_Fees::add_fee_product() keeps the fee PRODUCT shape (5675/4122)
 * but overrides the line subtotal/total to the per-client amount via
 * $order->add_product(..., ['subtotal'=>$amt,'total'=>$amt]). apply_to_order()
 * then calls $order->calculate_totals() + save(). The open question (H13): does
 * calculate_totals() PRESERVE the overridden _line_subtotal, or silently
 * re-derive it from the product's catalog price on live WooCommerce 10.x?
 *
 * Reconciliation sums _line_subtotal (MealsDB_WC_Order_Query::get_total_paid_
 * for_product), so if the override regressed, government billing would silently
 * use the catalog price. This test writes a real order with an override that
 * DIFFERS from catalog, recalculates + saves, reloads, and asserts the line
 * subtotal read back is the per-client amount — not the catalog price.
 *
 * REQUIRES LIVE WOOCOMMERCE (wc_create_order / wc_get_product + a real fee
 * product). It skips cleanly where WC is unavailable (e.g. the dev CLI), so it
 * is a no-op locally and a real assertion on staging / CI. Run there:
 *   php tests/test-order-fee-override-persists.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

function skip(string $why): void {
    echo "  [skip] H13 fee-override characterization: {$why}\n";
    echo "Ran 0 checks: 0 passed, 0 failed (skipped)\n";
    exit(0);
}

if (!function_exists('wc_get_product') || !function_exists('wc_create_order') || !function_exists('wc_get_order') || !class_exists('WC_Order')) {
    skip('needs live WooCommerce (wc_create_order / wc_get_product / WC_Order)');
}
if (!class_exists('MealsDB_Order_Fees') || !class_exists('MealsDB_Operational_Constants')) {
    skip('plugin classes unavailable');
}

$fee_pid = MealsDB_Operational_Constants::PRODUCT_ID_CLIENT_CONTRIBUTION; // 5675
$product = wc_get_product($fee_pid);
if (!$product instanceof WC_Product) {
    skip("fee product {$fee_pid} not present in this store");
}

$failures = []; $passed = 0;
function chk($cond, string $l): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $l;
}

$catalog    = (float) $product->get_price();
// A per-client amount deliberately DIFFERENT from the catalog price, so a
// silent re-derivation to catalog is unambiguously detectable.
$per_client = round($catalog + 11.11, 2);

$order = wc_create_order();
$cleanup_id = (int) $order->get_id();

try {
    // Exercise the real override path.
    $rm = new ReflectionMethod('MealsDB_Order_Fees', 'add_fee_product');
    $rm->setAccessible(true);
    $added = $rm->invoke(null, $order, $fee_pid, $per_client);
    chk($added === true, 'add_fee_product returned true');

    // Mirror apply_to_order(): recalc + persist, then reload from the store.
    $order->calculate_totals();
    $order->save();

    $reloaded = wc_get_order($cleanup_id);
    $line_subtotal = null;
    foreach ($reloaded->get_items() as $item) {
        if ((int) $item->get_product_id() === $fee_pid) {
            $line_subtotal = (float) $item->get_subtotal(); // _line_subtotal
            break;
        }
    }
    chk($line_subtotal !== null, 'fee line item found after reload');
    chk(
        $line_subtotal !== null && abs($line_subtotal - $per_client) < 0.005,
        sprintf(
            'LB-2: reloaded _line_subtotal (%.2f) equals the per-client override (%.2f), not the catalog price (%.2f)',
            (float) $line_subtotal, $per_client, $catalog
        )
    );
} finally {
    // Don't leave a scratch order in the store.
    $scratch = wc_get_order($cleanup_id);
    if ($scratch) { $scratch->delete(true); }
}

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
