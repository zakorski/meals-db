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
        $value = is_numeric($dollars) ? (float) $dollars : 0.0;
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
