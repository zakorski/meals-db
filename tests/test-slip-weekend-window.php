<?php
/**
 * DIRECTIVE 4 test — the weekend creation-time window.
 *
 * MealsDB_Ajax_Slip_Batch::weekend_window($created_at) returns
 * [created_start, created_end] where created_start is the batch's own created_at
 * (exclusive lower bound in the fetch) and created_end is 23:59:59 on the Sunday
 * IMMEDIATELY FOLLOWING it — the same Sunday if the batch was created on a
 * Sunday. Anchoring on created_at makes the window self-adjusting and gapless
 * whether the batch was generated Thursday evening or Friday afternoon.
 *
 * Reference calendar (from the directive): 2026-09-04 = Friday, -05 = Saturday,
 * -06 = Sunday, -07 = Monday.
 *
 * Run: php tests/test-slip-weekend-window.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}

$rm = new ReflectionMethod('MealsDB_Ajax_Slip_Batch', 'weekend_window');
$rm->setAccessible(true);
$win = static fn(string $created) => $rm->invokeArgs(null, [$created]);

// Friday afternoon → the coming Sunday (the 6th).
chk($win('2026-09-04 14:30:00'), ['2026-09-04 14:30:00', '2026-09-06 23:59:59'], 'Friday → Sunday 06');

// Thursday evening → same Sunday (the 6th): gapless with a Friday run.
chk($win('2026-09-03 20:00:00'), ['2026-09-03 20:00:00', '2026-09-06 23:59:59'], 'Thursday → Sunday 06');

// Saturday → the very next day (Sunday 06).
chk($win('2026-09-05 09:00:00'), ['2026-09-05 09:00:00', '2026-09-06 23:59:59'], 'Saturday → Sunday 06');

// Sunday → that same Sunday (immediately following = same day).
chk($win('2026-09-06 08:00:00'), ['2026-09-06 08:00:00', '2026-09-06 23:59:59'], 'Sunday → same Sunday');

// Garbage in → empty window (caller refuses to generate).
chk($win('not-a-date'), ['', ''], 'invalid created_at → empty window');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
