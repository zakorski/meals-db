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

    /**
     * Apply a schedule edit to client rows: any zone whose DAY changed
     * updates every active client in that zone (delivery_day is a synced
     * cache — spec 2026-07-11). Zones present in $old but dropped from
     * $new are counted and reported as a degraded event when active
     * clients still reference them (they become orphans until the
     * operator re-zones them).
     *
     * @param array<string, mixed> $old_schedule Option value before the save.
     * @param array<string, mixed> $new_schedule Option value after the save.
     * @return array{changed_zones: string[], clients_updated: int, dropped_zones: array<string, int>}
     */
    public static function propagate_schedule_change(array $old_schedule, array $new_schedule): array {
        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $norm = static function ($config): ?string {
            if (!is_array($config)) {
                return null;
            }
            $day = strtolower(trim((string) ($config['day'] ?? '')));
            return $day === '' ? null : $day;
        };

        $stats = ['changed_zones' => [], 'clients_updated' => 0, 'dropped_zones' => []];

        foreach ($new_schedule as $zone => $config) {
            $new_day = $norm($config);
            if ($new_day === null) {
                continue;
            }
            $old_day = $norm($old_schedule[(string) $zone] ?? null);
            if ($new_day === $old_day) {
                continue; // day unchanged (label-only edits don't touch clients)
            }
            $affected = (int) $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET delivery_day = %s
                 WHERE delivery_area_name = %s AND active = 1",
                $new_day,
                (string) $zone
            ));
            $stats['changed_zones'][]   = (string) $zone;
            $stats['clients_updated']  += max(0, $affected);
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log(
                    'zone_day_propagated',
                    0,
                    'delivery_day',
                    (string) ($old_day ?? ''),
                    sprintf('%s (%s, %d clients)', $new_day, $zone, max(0, $affected))
                );
            }
        }

        foreach ($old_schedule as $zone => $config) {
            if (array_key_exists((string) $zone, $new_schedule)) {
                continue;
            }
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE delivery_area_name = %s AND active = 1",
                (string) $zone
            ));
            if ($count > 0) {
                $stats['dropped_zones'][(string) $zone] = $count;
            }
        }

        if (!empty($stats['dropped_zones']) && class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'sync',
                'subsystem' => 'zone_day',
                'event'     => 'zone_schedule.zone_dropped',
                'outcome'   => 'degraded',
                'message'   => 'Zone(s) removed from the delivery schedule while active clients still reference them.',
                'context'   => ['dropped_zones' => $stats['dropped_zones']],
            ]);
        }

        return $stats;
    }

    /**
     * One-time (re-runnable) resync: overwrite every active client's
     * delivery_day from their zone's schedule day, and report ORPHANS —
     * active clients whose delivery_area_name resolves to no zone. Orphans
     * are the clients at risk of silently falling off packer/driver slips;
     * they are surfaced in the return value AND as one degraded event.
     *
     * Returns null when no schedule is configured (refuses rather than
     * blanking 890 rows).
     *
     * @return array{updated: int, orphans: array<int, array<string, mixed>>}|null
     */
    public static function resync_all(): ?array {
        $schedule = self::schedule();
        if (empty($schedule)) {
            return null;
        }

        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // Orphans first: active clients whose zone is not a schedule key.
        // Names are fine to return to this manage_options-only caller
        // (first/last name are not in ENCRYPTED_CLIENT_COLUMNS); the EVENT
        // context carries ids only (PII-lean by construction).
        $placeholders = implode(',', array_fill(0, count($schedule), '%s'));
        $output_type = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $orphans = $wpdb->get_results($wpdb->prepare(
            "SELECT client_id, first_name, last_name, delivery_area_name
             FROM `{$table}`
             WHERE active = 1
               AND (delivery_area_name IS NULL OR delivery_area_name = ''
                    OR delivery_area_name NOT IN ({$placeholders}))
             ORDER BY delivery_area_name, last_name",
            ...array_keys($schedule)
        ), $output_type);
        $orphans = is_array($orphans) ? $orphans : [];

        // One UPDATE per zone: only rows whose cached day is wrong.
        $updated = 0;
        foreach ($schedule as $zone => $config) {
            $day = strtolower($config['day']);
            $affected = (int) $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET delivery_day = %s
                 WHERE delivery_area_name = %s AND active = 1
                   AND (delivery_day IS NULL OR delivery_day <> %s)",
                $day,
                (string) $zone,
                $day
            ));
            $updated += max(0, $affected);
        }

        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log(
                'delivery_day_resync',
                0,
                'delivery_day',
                null,
                sprintf('%d updated, %d orphans', $updated, count($orphans))
            );
        }
        if (!empty($orphans) && class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'sync',
                'subsystem' => 'zone_day',
                'event'     => 'delivery_day.orphaned_clients',
                'outcome'   => 'degraded',
                'message'   => sprintf('%d active client(s) reference no configured delivery zone and will not appear on slips.', count($orphans)),
                'context'   => ['client_ids' => array_map(static fn($o) => (int) $o['client_id'], $orphans)],
            ]);
        }

        return ['updated' => $updated, 'orphans' => $orphans];
    }
}
