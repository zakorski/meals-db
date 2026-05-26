<?php
/**
 * Tests for MealsDB_Date_Calculator — week-multiplier + Sunday-week snap.
 * Run: php tests/test-date-calculator.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function eq($a, $e, $l) { global $failures, $passed; if ($a === $e) { $passed++; } else { $failures[] = "$l: expected ".var_export($e,true)." got ".var_export($a,true); } }

// weekly, no snap
eq(MealsDB_Date_Calculator::next_date('2026-05-21', 1, null), '2026-05-28', 'weekly no-snap +7');
// biweekly Thursday, last on Thursday => +14 lands on Thursday
eq(MealsDB_Date_Calculator::next_date('2026-05-21', 2, 'Thursday'), '2026-06-04', 'biweekly Thu aligned');
// weekly Thursday, last delivered Friday => snap back to Thu of that week
eq(MealsDB_Date_Calculator::next_date('2026-05-22', 1, 'Thursday'), '2026-05-28', 'weekly Fri->Thu snap-back');
// weekly Thursday, last on Tuesday => snap forward to Thu same week
eq(MealsDB_Date_Calculator::next_date('2026-05-19', 1, 'Thursday'), '2026-05-28', 'weekly Tue->Thu snap-forward');
// every 3 weeks, Monday
eq(MealsDB_Date_Calculator::next_date('2026-05-04', 3, 'Monday'), '2026-05-25', 'triweekly Mon');
// invalid frequency
eq(MealsDB_Date_Calculator::next_date('2026-05-21', 0, 'Thursday'), null, 'zero freq => null');
// invalid date
eq(MealsDB_Date_Calculator::next_date('not-a-date', 1, 'Thursday'), null, 'bad date => null');
// unknown day => no snap, just +7
eq(MealsDB_Date_Calculator::next_date('2026-05-21', 1, 'Funday'), '2026-05-28', 'unknown day => no snap');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
