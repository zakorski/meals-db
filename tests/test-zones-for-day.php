<?php
/**
 * Tests for MealsDB_Zone_Day::zones_for_day() — the pure selector behind
 * the Home page's "Today's deliveries" widget (admin UI consolidation
 * spec 2026-07-16 §2, PR 4). Given the validated schedule() shape and a
 * weekday name, returns the zones delivering that day, preserving
 * schedule order, comparing case-insensitively, and skipping malformed
 * rows instead of warning (same defensive stance as schedule()).
 *
 * Run with: php tests/test-zones-for-day.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// --- assertion helpers (test-hook-logger.php convention) ----------------
$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s",
        $label, var_export($expected, true), var_export($actual, true));
}

$schedule = [
    'Moncton North' => ['day' => 'Wednesday', 'label' => 'North run'],
    'Riverview'     => ['day' => 'Thursday',  'label' => 'River run'],
    'Moncton East'  => ['day' => 'Wednesday', 'label' => 'East run'],
    'Sussex'        => ['day' => 'Friday',    'label' => ''],
];

// ---------------------------------------------------------------------------
// Matching zones, schedule order preserved.
// ---------------------------------------------------------------------------
assert_equal(
    [
        'Moncton North' => ['day' => 'Wednesday', 'label' => 'North run'],
        'Moncton East'  => ['day' => 'Wednesday', 'label' => 'East run'],
    ],
    MealsDB_Zone_Day::zones_for_day($schedule, 'Wednesday'),
    'two Wednesday zones, schedule order preserved'
);

// ---------------------------------------------------------------------------
// Case-insensitive on both sides.
// ---------------------------------------------------------------------------
assert_equal(
    ['Riverview' => ['day' => 'Thursday', 'label' => 'River run']],
    MealsDB_Zone_Day::zones_for_day($schedule, 'thursday'),
    'lowercase needle matches stored case'
);
assert_equal(
    ['Sussex' => ['day' => 'Friday', 'label' => '']],
    MealsDB_Zone_Day::zones_for_day(
        ['Sussex' => ['day' => 'FRIDAY', 'label' => '']],
        'Friday'
    ),
    'uppercase stored day matches'
);

// ---------------------------------------------------------------------------
// No match / empty inputs.
// ---------------------------------------------------------------------------
assert_equal([], MealsDB_Zone_Day::zones_for_day($schedule, 'Sunday'), 'no zones on Sunday');
assert_equal([], MealsDB_Zone_Day::zones_for_day($schedule, ''), 'empty day => nothing');
assert_equal([], MealsDB_Zone_Day::zones_for_day([], 'Wednesday'), 'empty schedule => nothing');

// ---------------------------------------------------------------------------
// Malformed rows are skipped, not warned about.
// ---------------------------------------------------------------------------
assert_equal(
    ['Good' => ['day' => 'Monday', 'label' => 'ok']],
    MealsDB_Zone_Day::zones_for_day(
        [
            'NotArray' => 'garbage',
            'NoDay'    => ['label' => 'no day key'],
            'Good'     => ['day' => 'Monday', 'label' => 'ok'],
        ],
        'Monday'
    ),
    'malformed rows skipped'
);

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
