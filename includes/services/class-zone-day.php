<?php
/**
 * MealsDB_Zone_Day — the single zone→delivery-day implementation
 * (spec 2026-07-11: the zone delivery schedule is the SOLE source of truth
 * for client delivery days).
 *
 * Every writer and deriving reader of meals_clients.delivery_day routes
 * through this class: the client-form save (derives, ignoring posted
 * values), the settings-save propagation, the resync backfill, and
 * MealsDB_Derived_Value_Check::expected_delivery_day(). One lookup means
 * the vocabulary can never fork again (the old fork — form-side 'WED AM'
 * vs consumer-side 'wednesday' — silently dropped clients off every
 * packer/driver slip).
 *
 * Canonical stored format: LOWERCASE full English day name ('wednesday'),
 * which is what the slip SQL (LOWER(delivery_day) = …), the occurrence
 * mapper, and MealsDB_Date_Calculator::DAY_OFFSET already expect.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Zone_Day {

    public const SCHEDULE_OPTION = 'mealsdb_zone_delivery_schedule';

    /**
     * Delivery day for a zone, in canonical stored form (lowercase full
     * day name), or null when it cannot be resolved: blank/unknown zone,
     * missing/empty schedule, or a malformed entry. Null is SKIP
     * semantics for callers — never a fatal, never a guess.
     */
    public static function day_for_zone(?string $area_name): ?string {
        if ($area_name === null) {
            return null;
        }
        $key = trim($area_name);
        if ($key === '') {
            return null;
        }
        $schedule = self::schedule();
        if (!isset($schedule[$key])) {
            return null;
        }
        return strtolower($schedule[$key]['day']);
    }

    /**
     * The validated zone schedule: only well-formed entries (array config
     * with a non-empty day). Day keeps its stored case ('Wednesday') for
     * display; use day_for_zone() for the canonical stored form.
     *
     * @return array<string, array{day: string, label: string}>
     */
    public static function schedule(): array {
        $raw = function_exists('get_option') ? get_option(self::SCHEDULE_OPTION, []) : [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $zone => $config) {
            // Operator-set option — don't trust its shape (same defense as
            // the old backfill): skip malformed rows instead of warning.
            if (!is_array($config)) {
                continue;
            }
            $day = trim((string) ($config['day'] ?? ''));
            if ($day === '') {
                continue;
            }
            $out[(string) $zone] = [
                'day'   => $day,
                'label' => (string) ($config['label'] ?? ''),
            ];
        }
        return $out;
    }
}
