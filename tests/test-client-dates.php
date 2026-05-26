<?php
/**
 * Tests for MealsDB_Client_Dates (advance_on_order, mark_delivered).
 * Run: php tests/test-client-dates.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

$GLOBALS['__usermeta'] = [];
if (!function_exists('update_user_meta')) {
    function update_user_meta($uid, $k, $v) { $GLOBALS['__usermeta'][$uid][$k] = $v; return true; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

class CDWpdb {
    public $prefix = 'wp_';
    public $client;
    public array $updated = [];
    public function __construct(array $c) { $this->client = $c; }
    public function prepare($q, ...$a) { if (count($a)===1 && is_array($a[0])) $a=$a[0]; $i=0; return preg_replace_callback('/%[ds]/', function($m) use (&$i,$a){ $v=$a[$i]??''; $i++; return $m[0]==='%s'?"'".$v."'":(string)(int)$v; }, $q); }
    public function get_row($q, $o = null) { return $this->client; }
    public function update($t, $data, $where) { $this->updated = $data; return 1; }
}

$failures = []; $passed = 0;
function ok($c, $l) { global $failures, $passed; if ($c) { $passed++; } else { $failures[] = $l; } }

// advance_on_order: weekly Thursday client orders Tuesday 2026-05-19
$GLOBALS['wpdb'] = new CDWpdb(['client_id' => 5, 'ordering_frequency' => 1, 'delivery_day' => 'Thursday']);
$r = MealsDB_Client_Dates::advance_on_order(700, '2026-05-19');
ok($r === true, 'advance_on_order returns true');
ok(($GLOBALS['__usermeta'][700]['last_order_date'] ?? '') === '2026-05-19', 'last_order_date usermeta set');
ok(($GLOBALS['wpdb']->updated['next_order_date'] ?? '') === '2026-05-28', 'next_order_date snapped to Thu of order week');

// advance_on_order: no frequency => no recompute
$GLOBALS['wpdb'] = new CDWpdb(['client_id' => 6, 'ordering_frequency' => 0, 'delivery_day' => 'Thursday']);
$r = MealsDB_Client_Dates::advance_on_order(701, '2026-05-19');
ok($r === false, 'no frequency => false');

// advance_on_order: not a tracked client
$GLOBALS["wpdb"] = new CDWpdb([]); $GLOBALS["wpdb"]->client = null;
ok(MealsDB_Client_Dates::advance_on_order(702, '2026-05-19') === false, 'untracked client => false');

// mark_delivered: biweekly Thursday, delivered Thursday 2026-05-21
$GLOBALS['wpdb'] = new CDWpdb(['client_id' => 8, 'delivery_frequency' => 2, 'delivery_day' => 'Thursday']);
$r = MealsDB_Client_Dates::mark_delivered(8, '2026-05-21');
ok($r === true, 'mark_delivered returns true');
ok(($GLOBALS['wpdb']->updated['last_delivery_date'] ?? '') === '2026-05-21', 'last_delivery_date set');
ok(($GLOBALS['wpdb']->updated['next_delivery_date'] ?? '') === '2026-06-04', 'next_delivery_date = +2wk Thu');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
