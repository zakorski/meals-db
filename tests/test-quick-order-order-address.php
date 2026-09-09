<?php
/**
 * DIRECTIVE hst-rate-source ITEM 2: Quick Order orders must carry the client's
 * address so WooCommerce resolves tax at the right province instead of the
 * store base (CA:NS). apply_client_address_to_order() is the pure, testable
 * unit; it sets billing/shipping from a DB-side client row and returns the
 * billing province that will drive tax ('' when none resolvable).
 *
 * Run: php tests/test-quick-order-order-address.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');

// Minimal WC_Order stub that records every set_*() call.
if (!class_exists('WC_Order')) {
    class WC_Order {
        public array $set = [];
        public function __call($name, $args) {
            if (strpos($name, 'set_') === 0) { $this->set[substr($name, 4)] = $args[0] ?? null; }
            return null;
        }
        public function get_id() { return 999; }
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

$failures = []; $passed = 0;
function addr_eq($label, $expected, $actual): void {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label,
        var_export($expected, true), var_export($actual, true));
}

$m = new ReflectionMethod('MealsDB_Quick_Order_Ajax', 'apply_client_address_to_order');
$m->setAccessible(true);

// A Moncton NB client with a distinct delivery address.
$client = [
    'first_name' => 'Jane', 'last_name' => 'Doe', 'client_email' => 'jane@example.com',
    'street_name' => '10 Main St', 'city' => 'Moncton', 'province' => 'NB', 'postal_code' => 'E1C 1A1',
    'delivery_street_name' => '11 Side St', 'delivery_city' => 'Dieppe',
    'delivery_province' => 'NB', 'delivery_postal_code' => 'E1A 2B2',
];
$order = new WC_Order();
$province = $m->invoke(null, $order, $client);
addr_eq('returns billing province', 'NB', $province);
addr_eq('billing state set', 'NB', $order->set['billing_state'] ?? null);
addr_eq('billing country set', 'CA', $order->set['billing_country'] ?? null);
addr_eq('billing city set', 'Moncton', $order->set['billing_city'] ?? null);
addr_eq('shipping state from delivery', 'NB', $order->set['shipping_state'] ?? null);
addr_eq('shipping city from delivery', 'Dieppe', $order->set['shipping_city'] ?? null);

// Shipping falls back to billing when delivery fields are absent.
$client2 = ['first_name' => 'A', 'last_name' => 'B', 'street_name' => '1 X', 'city' => 'Moncton',
    'province' => 'NB', 'postal_code' => 'E1C 1A1'];
$order2 = new WC_Order();
$m->invoke(null, $order2, $client2);
addr_eq('shipping state falls back to billing', 'NB', $order2->set['shipping_state'] ?? null);

// No province anywhere → returns '' and does NOT set a country (no CA:NS fallback).
$order3 = new WC_Order();
$province3 = $m->invoke(null, $order3, ['first_name' => 'A', 'last_name' => 'B']);
addr_eq('no province returns empty', '', $province3);
addr_eq('no billing country set when province unknown', null, $order3->set['billing_country'] ?? null);

// Null client → '' and no setters called.
$order4 = new WC_Order();
addr_eq('null client returns empty', '', $m->invoke(null, $order4, null));
addr_eq('null client sets nothing', [], $order4->set);

// ---------------------------------------------------------------------------
// DIRECTIVE K11 — zone on the address + empty-string fallback.
// ---------------------------------------------------------------------------

// K11 Test 1: the delivery area (zone) lands on BOTH billing and shipping
// address_2. The zone is what staff scan in the Ship-to column.
$client_zone = [
    'first_name' => 'Kimberley', 'last_name' => 'Donald',
    'street_name' => '101 Archibald St', 'city' => 'Moncton', 'province' => 'NB', 'postal_code' => 'E1C 9J7',
    'delivery_area_name' => 'Zone 1',
];
$order_zone = new WC_Order();
$m->invoke(null, $order_zone, $client_zone);
addr_eq('K11-1 billing address_2 is the zone', 'Zone 1', $order_zone->set['billing_address_2'] ?? null);
addr_eq('K11-1 shipping address_2 is the zone', 'Zone 1', $order_zone->set['shipping_address_2'] ?? null);

// K11 Test 2: empty-string ('' not NULL) delivery fields fall back to billing.
// This is the common single-address client and the reported bug.
$client_empty = [
    'first_name' => 'A', 'last_name' => 'B',
    'street_name' => '1 Main St', 'city' => 'Moncton', 'province' => 'NB', 'postal_code' => 'E1C 1A1',
    'delivery_street_name' => '', 'delivery_city' => '', 'delivery_postal_code' => '', 'delivery_province' => '',
];
$order_empty = new WC_Order();
$m->invoke(null, $order_empty, $client_empty);
addr_eq('K11-2 empty delivery street falls back to billing', '1 Main St', $order_empty->set['shipping_address_1'] ?? null);
addr_eq('K11-2 empty delivery city falls back to billing', 'Moncton', $order_empty->set['shipping_city'] ?? null);
addr_eq('K11-2 empty delivery postcode falls back to billing', 'E1C 1A1', $order_empty->set['shipping_postcode'] ?? null);

// K11 Test 3: NULL (absent) delivery fields still fall back (guards existing behaviour).
$client_null = [
    'first_name' => 'A', 'last_name' => 'B',
    'street_name' => '2 Elm St', 'city' => 'Dieppe', 'province' => 'NB', 'postal_code' => 'E1A 3C3',
];
$order_null = new WC_Order();
$m->invoke(null, $order_null, $client_null);
addr_eq('K11-3 null delivery street falls back to billing', '2 Elm St', $order_null->set['shipping_address_1'] ?? null);
addr_eq('K11-3 null delivery city falls back to billing', 'Dieppe', $order_null->set['shipping_city'] ?? null);

// K11 Test 4: a real delivery address still wins over billing when both populated.
$order_win = new WC_Order();
$m->invoke(null, $order_win, $client); // $client has distinct delivery fields
addr_eq('K11-4 real delivery street wins', '11 Side St', $order_win->set['shipping_address_1'] ?? null);
addr_eq('K11-4 real delivery city wins', 'Dieppe', $order_win->set['shipping_city'] ?? null);

// K11 Test 5: blank delivery_area_name → address_2 is '' (no fatal, no literal 'null').
$client_blank_zone = [
    'first_name' => 'A', 'last_name' => 'B',
    'street_name' => '3 Oak St', 'city' => 'Moncton', 'province' => 'NB', 'postal_code' => 'E1C 1A1',
];
$order_blank_zone = new WC_Order();
$m->invoke(null, $order_blank_zone, $client_blank_zone);
addr_eq('K11-5 blank zone billing address_2 is empty string', '', $order_blank_zone->set['billing_address_2'] ?? null);
addr_eq('K11-5 blank zone shipping address_2 is empty string', '', $order_blank_zone->set['shipping_address_2'] ?? null);

if ($failures) { echo implode("\n", $failures) . "\n"; echo "FAILED ({$passed} passed)\n"; exit(1); }
echo "OK ({$passed} passed)\n";
