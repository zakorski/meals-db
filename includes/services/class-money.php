<?php
/**
 * Integer-cents money helper.
 *
 * Float arithmetic on dollar amounts accumulates rounding error: summing
 * many round($value, 2) floats can drift by pennies on large invoices,
 * which matters for HST / government submissions. This helper normalises
 * money to integer cents at boundaries and exposes the few operations
 * the invoice generator actually needs. All rounding is half-up on the
 * absolute value, so signed amounts round the same way on either side
 * of zero.
 *
 * Usage pattern: convert to_cents() as soon as you enter a math block,
 * operate in integers, and call format() only when emitting text for a
 * CSV / PDF / JSON surface.
 *
 * Author: Fishhorn Design
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Money {

    /**
     * Convert a dollars float to integer cents using half-up rounding.
     *
     * Strings are accepted too (rate values often live in the DB as
     * DECIMAL(10,2) and arrive as strings), so callers don't need to
     * cast before calling.
     */
    public static function to_cents($dollars): int {
        if (!is_numeric($dollars)) {
            // Non-numeric silently converts to 0 — previously this
            // masked caller bugs where a string like "abc" leaked
            // through. Log at debug level so developers see the trace
            // without the production log filling up on typical zero
            // fields.
            error_log('[MealsDB Money] to_cents: non-numeric input coerced to 0: ' . var_export($dollars, true));
            return 0;
        }

        // Fast-path for amounts that fit in a float without precision
        // loss. Float can exactly represent integers up to 2^53 ≈
        // 9e15 cents (~$9e13 dollars) — far above any realistic
        // invoice. At $999,999.99 / 99_999_999 cents we're nowhere
        // near the float-precision boundary.
        $value = (float) $dollars;

        // Extreme amounts: beyond 1e13 dollars the float multiplication
        // loses precision in the last digit. Nothing in this plugin
        // realistically reaches that, but if a caller ever does, fall
        // back to bcmul so the integer conversion stays exact. Guard
        // on function_exists because bcmath isn't always compiled in.
        if (abs($value) > 1e13 && function_exists('bcmul')) {
            $bc = bcmul((string) $dollars, '100', 0);
            return (int) $bc;
        }

        $cents = $value * 100;
        // round() on positive half values is half-up in PHP by default,
        // but be explicit so behaviour doesn't drift if callers pass
        // negatives (contributions are sometimes modelled as credits).
        if ($cents >= 0) {
            return (int) floor($cents + 0.5);
        }
        return -1 * (int) floor((-$cents) + 0.5);
    }

    /**
     * Format integer cents as a plain "%.2f" string — no currency symbol,
     * no thousands separator. Matches the government CSV expectation.
     */
    public static function format(int $cents): string {
        $sign = $cents < 0 ? '-' : '';
        $abs  = abs($cents);
        $whole = intdiv($abs, 100);
        $frac  = $abs % 100;
        return $sign . $whole . '.' . str_pad((string) $frac, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Multiply a unit count by a per-unit dollar rate and return integer cents.
     *
     * Done in one conversion to avoid repeated float→cents rounding
     * across a line.
     */
    public static function multiply(float $units, $rate_per_unit): int {
        $rate = is_numeric($rate_per_unit) ? (float) $rate_per_unit : 0.0;
        return self::to_cents($units * $rate);
    }

    /**
     * Apply a percentage multiplier (e.g. 0.15 for 15% HST) to an
     * integer-cents amount and return integer cents, rounded half-up.
     */
    public static function percent_of(int $cents, $multiplier): int {
        $mult  = is_numeric($multiplier) ? (float) $multiplier : 0.0;

        // Overflow guard: cents * mult can exceed PHP_INT_MAX for
        // absurd inputs. Realistic invoices never reach this —
        // $INT_MAX / 100 / typical-mult is still billions of dollars
        // — but a typo (10.0 instead of 0.10 on a giant amount) would
        // otherwise produce a silently-wrong wrap-around result.
        if ($mult !== 0.0 && $cents !== 0) {
            $abs_mult = abs($mult);
            if ($abs_mult > 0 && abs($cents) > PHP_INT_MAX / max($abs_mult, 1.0)) {
                error_log(sprintf(
                    '[MealsDB Money] percent_of: overflow risk, cents=%d mult=%s. Returning 0 instead of wrapping.',
                    $cents,
                    var_export($multiplier, true)
                ));
                return 0;
            }
        }

        $value = $cents * $mult;
        if ($value >= 0) {
            return (int) floor($value + 0.5);
        }
        return -1 * (int) floor((-$value) + 0.5);
    }

    /**
     * Sum an array of integer cent values. Harmless but makes call
     * sites read more clearly than array_sum().
     */
    public static function sum(array $cents_list): int {
        $total = 0;
        foreach ($cents_list as $c) {
            $total += (int) $c;
        }
        return $total;
    }
}
