<?php
/**
 * DIRECTIVE K7 ITEM 2 test — only current_stock is merged onto product payloads.
 *
 * The former available_stock (current − committed-on-unfulfilled) was REMOVED:
 * operators do not close out orders, so committed never drained and the derived
 * figure was misleading (e.g. −530 for a product with 46 in stock). The raw
 * _stock figure is kept; a product that does not manage stock carries null so the
 * UI shows "not tracked". Out-of-stock colour now keys on current_stock.
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

// Stub $wpdb: prepare() is a pass-through; get_results() returns the stock rows.
// K7: the committed-quantities query is gone, so a committed_qty query arriving
// here would be a regression — fail loudly if one is ever issued again.
class MealsDB_Test_Stock_Wpdb {
    public $prefix   = 'wp_';
    public $postmeta = 'wp_postmeta';
    public $stock_rows = [];
    public $committed_query_seen = false;
    public function prepare($sql, $args = null) { return $sql; }
    public function get_results($sql, $output = null) {
        if (strpos($sql, 'committed_qty') !== false) { $this->committed_query_seen = true; return []; }
        if (strpos($sql, '_stock') !== false)        { return $this->stock_rows; }
        return [];
    }
}

$wpdb = new MealsDB_Test_Stock_Wpdb();
// product 10: 40 in stock; product 11: 0 in stock (out); product 13: 5 in stock;
// product 12: NOT managed (no _stock row) -> null.
$wpdb->stock_rows = [
    ['product_id' => 10, 'stock' => '40'],
    ['product_id' => 11, 'stock' => '0'],
    ['product_id' => 13, 'stock' => '5'],
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

chk($by_id[10]['current_stock'], 40, 'p10 current = 40');
chk($by_id[11]['current_stock'], 0, 'p11 current = 0 (out of stock)');
chk($by_id[13]['current_stock'], 5, 'p13 current = 5');
chk($by_id[12]['current_stock'], null, 'p12 current null (unmanaged)');

// available_stock must no longer be produced.
chk(array_key_exists('available_stock', $by_id[10]), false, 'p10 has NO available_stock key (removed)');
chk(array_key_exists('available_stock', $by_id[12]), false, 'p12 has NO available_stock key (removed)');

// The committed-quantities query must never be issued.
chk($wpdb->committed_query_seen, false, 'no committed-quantities query is issued (get_committed_quantities removed)');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
