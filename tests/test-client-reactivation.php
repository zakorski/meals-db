<?php
/**
 * Client reactivation month-qualifier (v561 ITEM 4b).
 * Run with: php tests/test-client-reactivation.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __($t, $d = 'default') { return $t; } }

$failures = []; $passed = 0;
function rk($got, $exp, $label) { global $failures, $passed; if ($got === $exp) { $passed++; } else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); } }

// order_month_qualifies(order_ymd, today_ymd): true iff order month == today's
// month or the immediately preceding month.
rk(MealsDB_Clients::order_month_qualifies('2026-08-15', '2026-08-20'), true,  'same month qualifies');
rk(MealsDB_Clients::order_month_qualifies('2026-07-31', '2026-08-01'), true,  'previous month qualifies');
rk(MealsDB_Clients::order_month_qualifies('2026-12-15', '2027-01-10'), true,  'prev month across year boundary');
rk(MealsDB_Clients::order_month_qualifies('2026-06-30', '2026-08-01'), false, 'two months back does NOT qualify');
rk(MealsDB_Clients::order_month_qualifies('2026-09-01', '2026-08-20'), false, 'future month does NOT qualify');
rk(MealsDB_Clients::order_month_qualifies('not-a-date', '2026-08-20'), false, 'garbage does NOT qualify');

echo "\n" . $passed . " passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
