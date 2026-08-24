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

if ($failures) { echo implode("\n", $failures) . "\n"; echo "FAILED ({$passed} passed)\n"; exit(1); }
echo "OK ({$passed} passed)\n";
