<?php
/**
 * Unit tests for MealsDB_Money — the integer-cents money core.
 *
 * MealsDB_Money is the single source of truth for money arithmetic across the
 * plugin (invoice generator, order fees, collection calculator, reports), but
 * had no dedicated test — it was only exercised indirectly. This covers each
 * method and its documented contract:
 *
 *   - to_cents():   half-up rounding to integer cents; symmetric on sign;
 *                   numeric strings accepted; non-numeric coerces to 0; the
 *                   >1e13 bcmath fall-back (only when bcmath is compiled in).
 *   - format():     "%.2f"-style, no symbol / no thousands separator, sign-aware.
 *   - multiply():   units x per-unit rate in one conversion; non-numeric rate 0.
 *   - percent_of(): percentage of a cents amount, half-up; non-numeric 0; the
 *                   overflow guard returns 0 instead of wrapping.
 *
 * Half-up boundary cases use values that are EXACTLY representable in binary
 * float (multiples of 1/8, 1/2), so the expected result is unambiguous and the
 * assertion genuinely pins the rounding rule rather than echoing float noise.
 *
 * Run: php tests/test-money.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = [];
$passed   = 0;
function eq($actual, $expected, string $label): void {
    global $failures, $passed;
    if ($actual === $expected) {
        $passed++;
        return;
    }
    $failures[] = sprintf(
        "%s\n  expected: %s\n  actual:   %s",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

// ---------------------------------------------------------------------------
// to_cents(): dollars -> integer cents, half-up.
// ---------------------------------------------------------------------------
eq(MealsDB_Money::to_cents(0),        0,    'to_cents: 0');
eq(MealsDB_Money::to_cents(1),        100,  'to_cents: 1.00 dollar -> 100');
eq(MealsDB_Money::to_cents(10.24),    1024, 'to_cents: 10.24 -> 1024');
eq(MealsDB_Money::to_cents(0.05),     5,    'to_cents: 0.05 -> 5');
eq(MealsDB_Money::to_cents(0.99),     99,   'to_cents: 0.99 -> 99');

// Numeric strings (rates arrive as DECIMAL(10,2) strings from the DB).
eq(MealsDB_Money::to_cents('15.47'),  1547, 'to_cents: "15.47" string -> 1547');
eq(MealsDB_Money::to_cents('0.00'),   0,    'to_cents: "0.00" string -> 0');
eq(MealsDB_Money::to_cents('-5.00'),  -500, 'to_cents: "-5.00" string -> -500');

// Half-up at the half-cent boundary (exactly representable inputs):
//   0.125 dollars = 12.5 cents -> 13 ; 0.375 = 37.5 -> 38.
eq(MealsDB_Money::to_cents(0.125),  13, 'to_cents: 12.5c rounds half-up to 13');
eq(MealsDB_Money::to_cents(0.375),  38, 'to_cents: 37.5c rounds half-up to 38');
// Just below the boundary rounds down.
eq(MealsDB_Money::to_cents(0.004),  0,  'to_cents: 0.4c rounds down to 0');

// Sign symmetry: the magnitude rounds the same way on either side of zero.
eq(MealsDB_Money::to_cents(-0.125), -13,   'to_cents: -12.5c -> -13 (symmetric half-up)');
eq(MealsDB_Money::to_cents(-10.24), -1024, 'to_cents: -10.24 -> -1024');
eq(MealsDB_Money::to_cents(-1),     -100,  'to_cents: -1.00 -> -100');

// Non-numeric input coerces to 0 (logs a debug breadcrumb; return is what
// matters). NB: to_cents() error_logs here — that stderr line is expected.
eq(MealsDB_Money::to_cents('abc'), 0, 'to_cents: "abc" -> 0');
eq(MealsDB_Money::to_cents(''),    0, 'to_cents: "" -> 0');
eq(MealsDB_Money::to_cents(null),  0, 'to_cents: null -> 0');
eq(MealsDB_Money::to_cents('12x'), 0, 'to_cents: "12x" (not numeric) -> 0');

// >1e13-dollar bcmath fall-back: exact past float's 2^53-cent integer range.
// Only assert when bcmath is compiled in (the branch is guarded on bcmul).
if (function_exists('bcmul')) {
    // 1e14 dollars = 1e16 cents > 2^53 (~9.007e15); float multiply would lose
    // the last digits, bcmul must stay exact.
    eq(MealsDB_Money::to_cents('100000000000000'), 10000000000000000,
        'to_cents: bcmath branch exact beyond 2^53 cents');
} else {
    echo "  [skip] to_cents bcmath-branch exactness (bcmath not compiled in this env)\n";
}

// ---------------------------------------------------------------------------
// format(): integer cents -> "%.2f" string (no symbol, no thousands sep).
// ---------------------------------------------------------------------------
eq(MealsDB_Money::format(0),       '0.00',     'format: 0 -> 0.00');
eq(MealsDB_Money::format(5),       '0.05',     'format: 5 -> 0.05 (zero-padded frac)');
eq(MealsDB_Money::format(99),      '0.99',     'format: 99 -> 0.99');
eq(MealsDB_Money::format(100),     '1.00',     'format: 100 -> 1.00');
eq(MealsDB_Money::format(1024),    '10.24',    'format: 1024 -> 10.24');
eq(MealsDB_Money::format(1000000), '10000.00', 'format: 1000000 -> 10000.00 (no thousands sep)');
eq(MealsDB_Money::format(-5),      '-0.05',    'format: -5 -> -0.05');
eq(MealsDB_Money::format(-1024),   '-10.24',   'format: -1024 -> -10.24');
eq(MealsDB_Money::format(-100),    '-1.00',    'format: -100 -> -1.00');

// Round-trip: a DECIMAL string that survives to_cents() -> format() unchanged.
eq(MealsDB_Money::format(MealsDB_Money::to_cents('15.47')), '15.47',
    'round-trip: to_cents then format preserves 15.47');
eq(MealsDB_Money::format(MealsDB_Money::to_cents('-0.05')), '-0.05',
    'round-trip: negative preserved');

// ---------------------------------------------------------------------------
// multiply(): units x per-unit rate -> integer cents (one rounding).
// ---------------------------------------------------------------------------
eq(MealsDB_Money::multiply(5, 4.25),   2125, 'multiply: 5 x 4.25 -> 2125');
eq(MealsDB_Money::multiply(3, '5.00'), 1500, 'multiply: 3 x "5.00" (string rate) -> 1500');
eq(MealsDB_Money::multiply(7, 4.25),   2975, 'multiply: 7 x 4.25 -> 2975');
eq(MealsDB_Money::multiply(1, 0.01),   1,    'multiply: 1 x 0.01 -> 1 cent');
eq(MealsDB_Money::multiply(0, 9.5),    0,    'multiply: 0 units -> 0');
eq(MealsDB_Money::multiply(3, -4.25),  -1275,'multiply: 3 x -4.25 -> -1275');
// Non-numeric rate is treated as 0 (no throw, no wrong number).
eq(MealsDB_Money::multiply(2, 'abc'),  0,    'multiply: non-numeric rate -> 0');
eq(MealsDB_Money::multiply(2, null),   0,    'multiply: null rate -> 0');

// ---------------------------------------------------------------------------
// percent_of(): percentage of a cents amount, half-up.
// ---------------------------------------------------------------------------
// 15% HST of $20.00 (2000c) = $3.00 (300c).
eq(MealsDB_Money::percent_of(2000, 0.15), 300, 'percent_of: 15% of 2000 -> 300');
eq(MealsDB_Money::percent_of(0, 0.15),    0,   'percent_of: 15% of 0 -> 0');
eq(MealsDB_Money::percent_of(2000, 0),    0,   'percent_of: 0% -> 0');

// Half-up with an exactly-representable multiplier (0.5):
//   1c -> 0.5 -> 1 ; 3c -> 1.5 -> 2 ; 5c -> 2.5 -> 3.
eq(MealsDB_Money::percent_of(1, 0.5), 1, 'percent_of: 0.5c rounds half-up to 1');
eq(MealsDB_Money::percent_of(3, 0.5), 2, 'percent_of: 1.5c rounds half-up to 2');
eq(MealsDB_Money::percent_of(5, 0.5), 3, 'percent_of: 2.5c rounds half-up to 3');

// Sign symmetry.
eq(MealsDB_Money::percent_of(-2000, 0.15), -300, 'percent_of: 15% of -2000 -> -300');

// Non-numeric multiplier -> 0.
eq(MealsDB_Money::percent_of(2000, 'abc'), 0, 'percent_of: non-numeric multiplier -> 0');

// A realistic large amount still computes (guard must NOT trip here):
//   $10,000,000 = 1e9 cents, 15% = $1,500,000 = 150000000c.
eq(MealsDB_Money::percent_of(1000000000, 0.15), 150000000,
    'percent_of: 15% of 1e9 cents -> 150000000 (no false overflow)');

// Overflow guard: cents x mult would exceed PHP_INT_MAX -> returns 0 (logs),
// never a wrapped-around wrong value. PHP_INT_MAX x 2.0 overflows.
eq(MealsDB_Money::percent_of(PHP_INT_MAX, 2.0), 0,
    'percent_of: overflow guard returns 0 instead of wrapping');

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
$total = $passed + count($failures);
echo "Ran {$total} checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) {
    echo "FAIL: {$f}\n";
}
exit(empty($failures) ? 0 : 1);
