<?php
/**
 * MealsDB_Ledger_Charge_Calculator (K17 ITEM 2): the per-order client-side
 * charge. SDNB/Veteran = fee lines only (contribution + delivery fee — the
 * meals belong to the program charge). Private = the order total reflecting
 * audit-adjusted quantities (order-line unit prices x effective qty + added
 * items). Pure: all prices are injected, so no float and no WC needed.
 *
 * Run: php tests/test-ledger-charge-calculator.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function cc(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = 'FAIL: ' . $label; }
}

$Calc = 'MealsDB_Ledger_Charge_Calculator';

// A row with two delivery items; item 1 edited down to 4.
$row = [
    'items' => [
        ['item_key' => 1, 'qty' => 5],
        ['item_key' => 2, 'qty' => 3],
    ],
    'edited_items' => [1 => 4],
    'added_items'  => [['product_id' => 200, 'qty' => 2]],
];
$prices = [
    'contribution_cents' => 4000,
    'delivery_fee_cents' => 1000,
    'line_unit_cents'    => [1 => 1140, 2 => 500],  // $11.40 main, $5.00 side
    'added_unit_cents'   => [200 => 500],           // added side $5.00
];

// 1. SDNB charge is fee-lines-only: contribution + delivery fee, meals excluded.
cc($Calc::client_charge_cents('SDNB', $row, $prices) === 5000, '1: SDNB charge = contribution + delivery fee (meals excluded)');

// 3. Veteran with a delivery fee and NO contribution → the fee alone.
$vet_prices = ['contribution_cents' => 0, 'delivery_fee_cents' => 1000];
cc($Calc::client_charge_cents('Veteran', $row, $vet_prices) === 1000, '3: Veteran charge = delivery fee alone');
cc($Calc::client_charge_cents('Veteran', $row, ['contribution_cents' => 0, 'delivery_fee_cents' => 0]) === 0,
    '3: Veteran with no fees → zero charge');

// 2. Private = order total at audit-adjusted quantities.
//    item1 edited 5->4 @ 1140 = 4560; item2 unchanged 3 @ 500 = 1500;
//    added 2 @ 500 = 1000. Total = 7060.
cc($Calc::client_charge_cents('Private', $row, $prices) === 7060, '2: Private charge reflects audit-adjusted quantities + added items');

// 2b. Private with no edits = plain order total (5*1140 + 3*500 = 7200), no added.
$plain = ['items' => [['item_key' => 1, 'qty' => 5], ['item_key' => 2, 'qty' => 3]], 'edited_items' => [], 'added_items' => []];
cc($Calc::client_charge_cents('Private', $plain, $prices) === 7200, '2b: Private unedited = order total');

// 2c. An edit to zero removes the line from the total.
$zeroed = ['items' => [['item_key' => 1, 'qty' => 5]], 'edited_items' => [1 => 0], 'added_items' => []];
cc($Calc::client_charge_cents('Private', $zeroed, $prices) === 0, '2c: a line edited to 0 contributes 0');

// Unknown/empty client type → no charge.
cc($Calc::client_charge_cents('', $row, $prices) === 0, 'unknown client type → 0 charge');

// Case-insensitive client type.
cc($Calc::client_charge_cents('sdnb', $row, $prices) === 5000, 'client type match is case-insensitive');

// 12. No float arithmetic in the calculator source.
$src = file_get_contents(__DIR__ . '/../includes/services/class-ledger-charge-calculator.php');
cc(!preg_match('/\(float\)|floatval|\(double\)/', (string) $src), '12: no float casts in the calculator');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
