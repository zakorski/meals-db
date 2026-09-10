<?php
/**
 * K12: the placeholder attachment is created once and reused. The sideload
 * itself needs WP media funcs (staging), so here we test the REUSE branch:
 * when the option points at an existing attachment, get_or_create returns it
 * with no new attachment created.
 *
 * Run: php tests/test-apetito-placeholder.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');
$GLOBALS['__opt'] = [];
$GLOBALS['__inserts'] = 0;
if (!function_exists('get_option')) { function get_option($k,$d=false){return $GLOBALS['__opt'][$k]??$d;} }
if (!function_exists('update_option')) { function update_option($k,$v){$GLOBALS['__opt'][$k]=$v;return true;} }
if (!function_exists('wp_attachment_is_image')) { function wp_attachment_is_image($id){return $id>0;} }
if (!function_exists('get_post')) { function get_post($id){return $id>0?(object)['ID'=>$id]:null;} }
if (!function_exists('__')) { function __($t,$d='default'){return $t;} }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$fail=[]; $pass=0;
function ok($l,$e,$a){global $fail,$pass; if($e===$a){$pass++;return;} $fail[]="FAIL: $l\n  expected: ".var_export($e,true)."\n  actual:   ".var_export($a,true);}

// Option already set to an existing attachment => reuse, no insert.
$GLOBALS['__opt']['mealsdb_apetito_placeholder_id'] = 555;
ok('reuses existing attachment', 555, MealsDB_Apetito_Placeholder::get_or_create());
ok('no new attachment inserted', 0, $GLOBALS['__inserts']);

if ($fail){echo implode("\n",$fail)."\n";echo "FAILED ({$pass} passed)\n";exit(1);}
echo "OK ({$pass} passed)\n";
