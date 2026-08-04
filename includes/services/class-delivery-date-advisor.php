<?php
/**
 * MealsDB_Delivery_Date_Advisor — shared soft validation for the manual
 * per-order delivery-date override (_delivery_date meta).
 *
 * Directive: DIRECTIVE-manual-delivery-date-override.md, Section C.
 * The operator decision is SOFT-WARN, DON'T BLOCK: edge cases (a Saturday
 * one-off, a backdated correction) are the point of the override, so this
 * class only ever returns advisory strings — it never vetoes a save.
 * sanitize_ymd() is the one hard gate, and it rejects only malformed
 * input, never unusual-but-real dates.
 *
 * The valid-day set is the CLIENT'S day, not a global one: delivery_day
 * is zone-synced (MealsDB_Zone_Day is the sole source of truth), so
 * reading the column IS the per-zone schedule lookup. The Mon–Fri check
 * is a fallback for callers with no client context only.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Delivery_Date_Advisor {

    /**
     * Hard-validate a raw posted delivery date. Returns the Y-m-d string
     * when it is a real calendar date, '' otherwise ('' = "no override").
     *
     * @param mixed $raw Raw request value.
     */
    public static function sanitize_ymd($raw): string {
        if (!is_string($raw)) {
            return '';
        }
        $raw = trim($raw);
        if ($raw === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return '';
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }
        return $raw;
    }

    /**
     * Advisory warning for a chosen delivery date, or '' when nothing is
     * off. Never blocks: callers surface the string and save anyway.
     *
     *   - Past date (before $today) → warn.
     *   - $expected_day known (client's delivery_day, any case): a
     *     different weekday → warn, naming both days.
     *   - $expected_day unknown: Saturday/Sunday → warn (no delivery
     *     runs on weekends; zone schedule is Mon–Fri).
     *
     * @param string      $ymd          Sanitized Y-m-d date.
     * @param string|null $expected_day Client's delivery day (full English
     *                                  name) or null when unknown.
     * @param string|null $today        Y-m-d "today" override for tests;
     *                                  defaults to the site-timezone today
     *                                  (a human judgment about "the past",
     *                                  so site tz beats UTC here).
     */
    public static function warning_for(string $ymd, ?string $expected_day = null, ?string $today = null): string {
        if (self::sanitize_ymd($ymd) === '') {
            return ''; // malformed input is rejected upstream, not warned about
        }

        if ($today === null) {
            $today = function_exists('current_time') ? current_time('Y-m-d') : gmdate('Y-m-d');
        }

        $weekday = (new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone('UTC')))->format('l');

        $parts = [];
        if ($ymd < $today) { // Y-m-d compares correctly lexically
            $parts[] = sprintf(
                __('%s is in the past.', 'meals-db'),
                $ymd
            );
        }

        $expected = $expected_day !== null ? strtolower(trim($expected_day)) : '';
        if ($expected !== '') {
            if (strtolower($weekday) !== $expected) {
                $parts[] = sprintf(
                    /* translators: 1: date, 2: chosen weekday, 3: client's delivery day */
                    __('%1$s is a %2$s — this client\'s deliveries run on %3$s.', 'meals-db'),
                    $ymd,
                    $weekday,
                    ucfirst($expected)
                );
            }
        } elseif ($weekday === 'Saturday' || $weekday === 'Sunday') {
            $parts[] = sprintf(
                /* translators: 1: date, 2: weekday */
                __('%1$s is a %2$s — no delivery runs that day.', 'meals-db'),
                $ymd,
                $weekday
            );
        }

        if (empty($parts)) {
            return '';
        }
        return sprintf(
            /* translators: %s: one or more warning sentences */
            __('Heads up: %s Saving anyway is allowed.', 'meals-db'),
            implode(' ', $parts)
        );
    }

    /**
     * Decide what a save of the order-edit delivery-date field should do
     * (directive Section B.6):
     *
     *   - 'set'   — a valid, CHANGED Y-m-d was posted: write it.
     *   - 'clear' — the field was emptied and a value is stored: delete
     *               the meta so the order reverts to the computed
     *               occurrence.
     *   - 'noop'  — field absent from the request, value unchanged, or
     *               malformed input (which must never clobber a stored
     *               override).
     *
     * @param string|null $posted   Raw posted value; null = field absent.
     * @param string      $existing Currently stored meta value ('' = none).
     * @return array{action: string, value: string}
     */
    public static function resolve_action(?string $posted, string $existing): array {
        if ($posted === null) {
            return ['action' => 'noop', 'value' => ''];
        }
        if (trim($posted) === '') {
            return $existing !== ''
                ? ['action' => 'clear', 'value' => '']
                : ['action' => 'noop', 'value' => ''];
        }
        $ymd = self::sanitize_ymd($posted);
        if ($ymd === '' || $ymd === $existing) {
            return ['action' => 'noop', 'value' => ''];
        }
        return ['action' => 'set', 'value' => $ymd];
    }

    /**
     * The client's expected delivery day for a WP user, lowercase full
     * name, or null when unknown. Reads the canonical zone-synced
     * delivery_day column; falls back to the zone schedule lookup when
     * the column is blank (a not-yet-resynced row). LIMIT 1 is the same
     * dual-program compromise the QO path makes (MAJ-1) — acceptable for
     * an advisory warning.
     */
    public static function expected_day_for_wp_user(int $wp_user_id): ?string {
        if ($wp_user_id <= 0) {
            return null;
        }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return null;
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT delivery_day, delivery_area_name FROM `{$table}`
                 WHERE wp_user_id = %d AND active = 1
                 LIMIT 1",
                $wp_user_id
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }

        $day = strtolower(trim((string) ($row['delivery_day'] ?? '')));
        if ($day !== '') {
            return $day;
        }
        return MealsDB_Zone_Day::day_for_zone($row['delivery_area_name'] ?? null);
    }
}
