<?php
/**
 * K12: duplicate guard blocks a code that already exists (keyed on SKU) and
 * names the existing product. Message building is pure; tested directly.
 *
 * Run: php tests/test-apetito-duplicate-guard.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');
if (!function_exists('__')) { function __($t,$d='default'){return $t;} }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$fail=[]; $pass=0;
function ok($l,$e,$a){global $fail,$pass; if($e===$a){$pass++;return;} $fail[]="FAIL: $l\n  expected: ".var_export($e,true)."\n  actual:   ".var_export($a,true);}
function contains($l,$needle,$hay){global $fail,$pass; if(strpos((string)$hay,$needle)!==false){$pass++;return;} $fail[]="FAIL: $l\n  '$needle' not in: $hay";}

// Test 8 — existing product (2725) => blocked, message names it
$existing = ['wc_product_id'=>2725, 'product_name'=>'Spaghetti Bolognese #12111', 'status'=>'publish'];
$msg = MealsDB_Apetito_Product_Creator::duplicate_message($existing, '12111', 'Spaghetti Bolognese');
contains('msg names product id', '2725', $msg);
contains('msg names title', 'Spaghetti Bolognese', $msg);
contains('msg names code', '12111', $msg);

// Test 9 — no match => allowed (null existing => empty message)
ok('no dup => empty message', '', MealsDB_Apetito_Product_Creator::duplicate_message(null, '12212', 'Chicken'));

if ($fail){echo implode("\n",$fail)."\n";echo "FAILED ({$pass} passed)\n";exit(1);}
echo "OK ({$pass} passed)\n";
