<?php
/**
 * Canonical next-date calculator for client ordering / delivery cadence.
 *
 * One implementation, used everywhere a "next" date is computed so the
 * backfill, the live order-placement hook, and the delivery task all agree:
 *
 *   - MealsDB_Migration_Consolidated::run_phase_next_dates   (backfill)
 *   - the order-placement lifecycle hook                     (next_order_date)
 *   - the client_delivery task on_complete                   (next_delivery_date)
 *
 * CADENCE MODEL
 * -------------
 * ordering_frequency / delivery_frequency are stored as a WEEK MULTIPLIER,
 * not a day count: 1 = weekly, 2 = biweekly, 3 = every three weeks, etc.
 * So the interval in days is (frequency * 7). (Historic code treated the
 * value as a raw day count — that was wrong and is corrected here.)
 *
 * SNAP-TO-DELIVERY-DAY
 * --------------------
 * The frequency picks the WEEK; the client's delivery_day picks the DAY
 * within that week. Weeks are Sunday-anchored (Sunday 00:00 .. Saturday).
 * Algorithm:
 *   1. projected = last_date + (frequency * 7) days
 *   2. find the Sunday that starts projected's week
 *   3. result = that Sunday + offset(delivery_day)   (Sun=0 .. Sat=6)
 * Because (frequency * 7) preserves weekday, the projected date sits on the
 * same weekday as last_date; snapping within its fixed Sun..Sat week moves
 * to the delivery day in that same week — backward (Fri->Thu) or forward
 * (Tue->Thu) as needed, never spilling into an adjacent week. With
 * frequency >= 1 the result is always strictly in a later week than
 * last_date, so it cannot land on or before last_date.
 *
 * If delivery_day is blank/unknown, no snap is applied and the projected
 * date is returned as-is.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Date_Calculator {

    /** Day-of-week => offset from Sunday. */
    private const DAY_OFFSET = [
        'sunday'    => 0,
        'monday'    => 1,
        'tuesday'   => 2,
        'wednesday' => 3,
        'thursday'  => 4,
        'friday'    => 5,
        'saturday'  => 6,
    ];

    /**
     * Compute the next date from a last date, a week-multiplier frequency,
     * and an optional delivery day-of-week to snap to.
     *
     * @param string      $last_date    Y-m-d.
     * @param int         $frequency    Week multiplier (1 = weekly).
     * @param string|null $delivery_day Day name (any case) or null/'' to skip snap.
     * @return string|null Y-m-d, or null if inputs are unusable.
     */
    public static function next_date(string $last_date, int $frequency, ?string $delivery_day = null): ?string {
        if ($frequency <= 0) {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $last_date)) {
            return null;
        }
        try {
            $base = new DateTimeImmutable($last_date, new DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }

        // 1. Project forward by whole weeks.
        $projected = $base->modify('+' . ($frequency * 7) . ' days');

        // 2/3. Snap to the delivery day within projected's Sun..Sat week.
        $offset = self::day_offset($delivery_day);
        if ($offset === null) {
            return $projected->format('Y-m-d'); // no/unknown delivery day: no snap
        }

        // Sunday that starts projected's week. PHP 'w': Sun=0 .. Sat=6.
        $projected_dow = (int) $projected->format('w');
        $week_sunday   = $projected->modify('-' . $projected_dow . ' days');

        return $week_sunday->modify('+' . $offset . ' days')->format('Y-m-d');
    }

    /**
     * Normalise a day name to its Sunday-offset, or null if unrecognised.
     */
    private static function day_offset(?string $delivery_day): ?int {
        if ($delivery_day === null) {
            return null;
        }
        $key = strtolower(trim($delivery_day));
        return self::DAY_OFFSET[$key] ?? null;
    }
}
