<?php
/**
 * DIRECTIVE 2 (ITEMS 1 & 2) test — stock figures merged onto product payloads.
 *
 * available_stock = current_stock − committed-on-unfulfilled. A product that
 * does not manage stock (no _stock row) carries null current/available so the
 * UI shows "not tracked" instead of a misleading 0. Colour/out-of-stock keys
 * on available, not current.
 *
 * inject_stock_figures() is exercised directly (private static, via reflection)
 * with a stubbed $wpdb so the merge logic is covered without a live DB.
 *
 * Run: php tests/test-quick-order-stock-figures.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

// Stub $wpdb: prepare() is a pass-through; get_results() returns canned rows
// keyed by which query is running (committed vs current-stock).
class MealsDB_Test_Stock_Wpdb {
    public $prefix   = 'wp_';
    public $postmeta = 'wp_postmeta';
    public $stock_rows = [];
    public $committed_rows = [];
    public function prepare($sql, $args = null) { return $sql; }
    public function get_results($sql, $output = null) {
        if (strpos($sql, 'committed_qty') !== false) { return $this->committed_rows; }
        if (strpos($sql, '_stock') !== false)        { return $this->stock_rows; }
        return [];
    }
}

$wpdb = new MealsDB_Test_Stock_Wpdb();
// product 10: 40 in stock, 12 committed  -> available 28
// product 11: 10 in stock, 10 committed  -> available 0  (out)
// product 13:  5 in stock,  0 committed  -> available 5
// product 12: NOT managed (no _stock row) -> null / null
$wpdb->stock_rows = [
    ['product_id' => 10, 'stock' => '40'],
    ['product_id' => 11, 'stock' => '10'],
    ['product_id' => 13, 'stock' => '5'],
];
$wpdb->committed_rows = [
    ['product_id' => 10, 'committed_qty' => '12'],
    ['product_id' => 11, 'committed_qty' => '10'],
];
$GLOBALS['wpdb'] = $wpdb;

$products = [
    ['product_id' => 10, 'name' => 'A'],
    ['product_id' => 11, 'name' => 'B'],
    ['product_id' => 12, 'name' => 'C'],
    ['product_id' => 13, 'name' => 'D'],
];

$rm = new ReflectionMethod('MealsDB_Quick_Order_Products', 'inject_stock_figures');
$rm->setAccessible(true);
$out = $rm->invokeArgs(null, [$products]);
$by_id = [];
foreach ($out as $p) { $by_id[$p['product_id']] = $p; }

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}

chk($by_id[10]['current_stock'], 40, 'p10 current');
chk($by_id[10]['available_stock'], 28, 'p10 available = 40 - 12');
chk($by_id[11]['available_stock'], 0, 'p11 available = 10 - 10 (out)');
chk($by_id[13]['available_stock'], 5, 'p13 available = 5 - 0 (no committed row)');
chk($by_id[12]['current_stock'], null, 'p12 current null (unmanaged)');
chk($by_id[12]['available_stock'], null, 'p12 available null (unmanaged)');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
