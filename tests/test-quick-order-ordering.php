<?php
/**
 * DIRECTIVE 3 tests — category tab order and "All" product sort.
 *
 * ITEM 1: order_categories_for_tabs() keeps only categories in the configured
 * tab order, in that order, and drops anything not listed (e.g. the parent
 * 'main', represented by the derived Mains tab).
 *
 * ITEM 2: sort_products_for_all() orders by type-category position then product
 * name; dietary categories are not sort keys; a product with two type
 * categories uses the first in the configured order (deterministic).
 *
 * Both methods are pure (no DB): with WP absent, the option/filter resolution
 * falls back to the default constants.
 *
 * Run: php tests/test-quick-order-ordering.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}

$call = function (string $method, array $args) {
    $rm = new ReflectionMethod('MealsDB_Quick_Order_Products', $method);
    $rm->setAccessible(true);
    return $rm->invokeArgs(null, $args);
};

/* ---- ITEM 1: tab ordering ---- */
$cats = [
    ['id' => 1, 'name' => 'Diabetic',   'slug' => 'diabetic-main'],
    ['id' => 2, 'name' => 'Beef',       'slug' => 'beef-main'],
    ['id' => 3, 'name' => 'Main',       'slug' => 'main'],          // dropped (parent)
    ['id' => 4, 'name' => 'Soup',       'slug' => 'soup'],
    ['id' => 5, 'name' => 'Thickened',  'slug' => 'thickened'],
    ['id' => 6, 'name' => 'Bogus',      'slug' => 'not-a-real-cat'], // dropped
];
$ordered = $call('order_categories_for_tabs', [$cats]);
$slugs = array_map(static fn($c) => $c['slug'], $ordered);
chk($slugs, ['beef-main', 'soup', 'diabetic-main', 'thickened'], 'tab order: configured sequence, parent+unknown dropped');

/* ---- ITEM 2: All-view sort ---- */
$products = [
    ['product_id' => 1, 'name' => 'Zucchini Beef',  'category_slugs' => ['beef-main']],
    ['product_id' => 2, 'name' => 'Apple Beef',     'category_slugs' => ['beef-main']],
    ['product_id' => 3, 'name' => 'Chicken Pie',    'category_slugs' => ['chicken-turkey-main']],
    ['product_id' => 4, 'name' => 'Tomato Soup',    'category_slugs' => ['soup']],
    ['product_id' => 5, 'name' => 'Berry Dessert',  'category_slugs' => ['dessert']],
    ['product_id' => 6, 'name' => 'Pureed Peas',    'category_slugs' => ['pureed']],
    ['product_id' => 7, 'name' => 'Diet Only',      'category_slugs' => ['diabetic-main']], // no type cat -> last
    ['product_id' => 8, 'name' => 'Beef Veg Combo', 'category_slugs' => ['vegetarian-main', 'beef-main']], // ambiguous -> beef (first)
];
$sorted = $call('sort_products_for_all', [$products]);
$ids = array_map(static fn($p) => $p['product_id'], $sorted);
// beef block alpha (Apple=2, Beef Veg Combo=8, Zucchini=1) -> chicken(3) -> soup(4) -> dessert(5) -> pureed(6) -> diabetic-only last(7)
chk($ids, [2, 8, 1, 3, 4, 5, 6, 7], 'all sort: type position then name; ambiguous uses first type; dietary-only last');

// The scratch sort key must not leak into the payload.
chk(array_key_exists('__sort_pos', $sorted[0]), false, 'scratch __sort_pos stripped from payload');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
