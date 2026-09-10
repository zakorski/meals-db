<?php
/**
 * K12: the Creator's meals_products payload must (a) carry the parsed Apetito
 * fields and (b) PRESERVE the category-derived product_type/taxable that
 * class-product-display-sync sets — never reset them to meal/0.
 *
 * Run: php tests/test-apetito-creator-merge.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');
if (!function_exists('__')) { function __($t,$d='default'){return $t;} }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$fail=[]; $pass=0;
function ok($l,$e,$a){global $fail,$pass; if($e===$a){$pass++;return;} $fail[]="FAIL: $l\n  expected: ".var_export($e,true)."\n  actual:   ".var_export($a,true);}

// Existing row as display-sync would leave it for a taxable side:
$existing = ['product_type'=>'side','taxable'=>1,'main_ingredient'=>'','dietary_tags'=>[],'allergen_flags'=>[],'case_size'=>1,'unit_cost'=>'0.00'];
$parsed = ['subcategory'=>'Poultry and more than forty characters of stuff here','allergens'=>['Eggs','Milk'],'diet_tags'=>['Vegan'],'portions_per_case'=>12];

$payload = MealsDB_Apetito_Product_Creator::build_meals_products_payload($existing, $parsed);

ok('preserves product_type', 'side', $payload['product_type']);
ok('preserves taxable', 1, $payload['taxable']);
ok('case_size from portions', 12, $payload['case_size']);
ok('allergens carried', ['Eggs','Milk'], $payload['allergen_flags']);
ok('diet carried', ['Vegan'], $payload['dietary_tags']);
ok('main_ingredient truncated to 40', 40, strlen($payload['main_ingredient']));

// Meal with no side category: existing product_type stays 'meal' (by absence).
$existing_meal = ['product_type'=>'meal','taxable'=>0,'main_ingredient'=>'','dietary_tags'=>[],'allergen_flags'=>[],'case_size'=>1,'unit_cost'=>'0.00'];
$p2 = MealsDB_Apetito_Product_Creator::build_meals_products_payload($existing_meal, ['subcategory'=>'Beef','allergens'=>[],'diet_tags'=>[],'portions_per_case'=>6]);
ok('meal stays meal', 'meal', $p2['product_type']);
ok('meal stays untaxed', 0, $p2['taxable']);
ok('case_size 6', 6, $p2['case_size']);

if ($fail){echo implode("\n",$fail)."\n";echo "FAILED ({$pass} passed)\n";exit(1);}
echo "OK ({$pass} passed)\n";
