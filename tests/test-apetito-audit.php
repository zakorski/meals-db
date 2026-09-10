<?php
/**
 * K12: the audit diff is pure — given a stored row and freshly parsed values,
 * it reports only the fields that drifted.
 *
 * Run: php tests/test-apetito-audit.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');
if (!function_exists('__')) { function __($t,$d='default'){return $t;} }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$fail=[]; $pass=0;
function ok($l,$e,$a){global $fail,$pass; if($e===$a){$pass++;return;} $fail[]="FAIL: $l\n  expected: ".var_export($e,true)."\n  actual:   ".var_export($a,true);}

// case_size drift only
$stored = ['case_size'=>12,'allergen_flags'=>['Eggs','Milk'],'dietary_tags'=>['Vegan']];
$parsed = ['portions_per_case'=>24,'allergens'=>['Eggs','Milk'],'diet_tags'=>['Vegan']];
$d = MealsDB_Apetito_Audit::diff($stored, $parsed);
ok('one drift', 1, count($d));
ok('case_size drift', 'case_size', $d[0]['field']);
ok('stored value', 12, $d[0]['stored']);
ok('live value', 24, $d[0]['apetito']);

// no drift
$d2 = MealsDB_Apetito_Audit::diff(
    ['case_size'=>12,'allergen_flags'=>['Eggs'],'dietary_tags'=>[]],
    ['portions_per_case'=>12,'allergens'=>['Eggs'],'diet_tags'=>[]]
);
ok('no drift', 0, count($d2));

// allergen set drift (order-insensitive)
$d3 = MealsDB_Apetito_Audit::diff(
    ['case_size'=>12,'allergen_flags'=>['Milk','Eggs'],'dietary_tags'=>[]],
    ['portions_per_case'=>12,'allergens'=>['Eggs'],'diet_tags'=>[]]
);
ok('allergen drift detected', 'allergen_flags', $d3[0]['field']);

if ($fail){echo implode("\n",$fail)."\n";echo "FAILED ({$pass} passed)\n";exit(1);}
echo "OK ({$pass} passed)\n";
