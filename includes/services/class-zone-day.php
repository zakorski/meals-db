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
     * Zones delivering on a given weekday. $schedule is the validated
     * schedule() shape; $day is a full weekday name, compared
     * case-insensitively. Preserves schedule order and skips malformed
     * rows (same defensive stance as schedule() — the option is
     * operator-set). Matched configs are returned verbatim (day keeps its
     * stored case, like schedule()). Pure, for unit tests and the Home
     * page's "Today's deliveries" widget (spec 2026-07-16 §2).
     *
     * @param array<string, array{day: string, label: string}> $schedule
     * @return array<string, array{day: string, label: string}>
     */
    public static function zones_for_day(array $schedule, string $day): array {
        $needle = strtolower(trim($day));
        if ($needle === '') {
            return [];
        }
        $out = [];
        foreach ($schedule as $zone => $config) {
            if (!is_array($config)) {
                continue;
            }
            if (strtolower(trim((string) ($config['day'] ?? ''))) === $needle) {
                $out[(string) $zone] = $config;
            }
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

        $stats = ['changed_zones' => [], 'clients_updated' => 0, 'dates_recomputed' => 0, 'dropped_zones' => []];

        foreach ($new_schedule as $zone => $config) {
            $new_day = $norm($config);
            if ($new_day === null) {
                continue;
            }
            $old_day = $norm($old_schedule[(string) $zone] ?? null);
            if ($new_day === $old_day) {
                continue; // day unchanged (label-only edits don't touch clients)
            }
            // Per-client: correct delivery_day AND recompute next_delivery_date
            // so the stored date can't keep pointing at the old weekday
            // (directive: next-delivery-date-resync-fix).
            $result = self::apply_zone_day($wpdb, $table, (string) $zone, $new_day);
            $stats['changed_zones'][]    = (string) $zone;
            $stats['clients_updated']   += $result['updated'];
            $stats['dates_recomputed']  += $result['dates_recomputed'];
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log(
                    'zone_day_propagated',
                    0,
                    'delivery_day',
                    (string) ($old_day ?? ''),
                    sprintf('%s (%s, %d clients, %d dates recomputed)', $new_day, $zone, $result['updated'], $result['dates_recomputed'])
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
     * @return array{updated: int, already_correct: int, orphans: array<int, array<string, mixed>>}|null
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
        $orphans = $wpdb->get_results($wpdb->prepare(
            "SELECT client_id, first_name, last_name, delivery_area_name
             FROM `{$table}`
             WHERE active = 1
               AND (delivery_area_name IS NULL OR delivery_area_name = ''
                    OR delivery_area_name NOT IN ({$placeholders}))
             ORDER BY delivery_area_name, last_name",
            ...array_keys($schedule)
        ), ARRAY_A);
        $orphans = is_array($orphans) ? $orphans : [];

        // Per-zone, per-client resync: correct the cached delivery_day AND
        // recompute the stored next_delivery_date whenever either has drifted
        // off the zone's day (directive: next-delivery-date-resync-fix). We
        // scan EVERY in-zone client, not just those with a wrong delivery_day,
        // so this also remediates clients whose delivery_day was already
        // corrected by a prior resync but whose next_delivery_date still points
        // at the old weekday (the concrete 12C.1 case: a Friday for a Wednesday
        // client). Per-client because next_delivery_date depends on each
        // client's own frequency/anchor and can't be done in one bulk UPDATE.
        $updated = 0;
        $dates_recomputed = 0;
        foreach ($schedule as $zone => $config) {
            $day    = strtolower($config['day']);
            $result = self::apply_zone_day($wpdb, $table, (string) $zone, $day);
            $updated          += $result['updated'];
            $dates_recomputed += $result['dates_recomputed'];
        }

        // Spec §D response contract: callers (settings.js, AJAX handler) expect
        // an already_correct count so the operator can distinguish "nothing to do"
        // from "nothing was corrected because there are no schedule clients."
        // Reuses $placeholders already built for the orphan query above.
        $in_schedule_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE active = 1
               AND delivery_area_name IN ({$placeholders})",
            ...array_keys($schedule)
        ));
        $already_correct = max(0, $in_schedule_count - $updated);

        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log(
                'delivery_day_resync',
                0,
                'delivery_day',
                null,
                sprintf('%d updated, %d dates recomputed, %d already correct, %d orphans', $updated, $dates_recomputed, $already_correct, count($orphans))
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

        return ['updated' => $updated, 'dates_recomputed' => $dates_recomputed, 'already_correct' => $already_correct, 'orphans' => $orphans];
    }

    /**
     * Resync one zone's active clients to $new_day (lowercase full weekday
     * name): correct delivery_day and recompute next_delivery_date wherever
     * either has drifted. Per-client (not a bulk UPDATE) because
     * next_delivery_date depends on each client's own frequency/anchor.
     *
     * @return array{updated: int, dates_recomputed: int}
     */
    private static function apply_zone_day($wpdb, string $table, string $zone, string $new_day): array {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT client_id, wp_user_id, delivery_frequency, delivery_day, next_delivery_date
             FROM `{$table}`
             WHERE delivery_area_name = %s AND active = 1",
            $zone
        ), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];

        $updated          = 0;
        $dates_recomputed = 0;
        foreach ($rows as $row) {
            $patch = self::plan_client_day_update($row, $new_day);
            if ($patch === null) {
                continue; // already correct on both axes
            }
            // Skip on a DB error for this row rather than aborting the whole
            // sweep — one bad row shouldn't strand every other client.
            if ($wpdb->update($table, $patch, ['client_id' => (int) $row['client_id']]) === false) {
                continue;
            }
            if (isset($patch['delivery_day'])) {
                $updated++;
            }
            if (isset($patch['next_delivery_date'])) {
                $dates_recomputed++;
            }
        }

        return ['updated' => $updated, 'dates_recomputed' => $dates_recomputed];
    }

    /**
     * Build the meals_clients update patch for one client row given the zone's
     * canonical $new_day (lowercase full weekday name). Returns null when the
     * row is already correct on BOTH delivery_day and next_delivery_date, so
     * the caller can skip the write.
     *
     * @param array<string, mixed> $row client_id, wp_user_id, delivery_frequency, delivery_day, next_delivery_date
     * @return array<string, string>|null
     */
    private static function plan_client_day_update(array $row, string $new_day): ?array {
        $current_day = strtolower(trim((string) ($row['delivery_day'] ?? '')));
        $day_wrong   = ($current_day !== $new_day);

        // Substring the stored value (0..10) so a DATE or DATETIME column both
        // reduce to Y-m-d before comparison.
        $stored_ymd = substr((string) ($row['next_delivery_date'] ?? ''), 0, 10);
        $next       = self::compute_next_delivery_date($row, $new_day);
        $date_wrong = ($next !== null && $next !== $stored_ymd);

        if (!$day_wrong && !$date_wrong) {
            return null;
        }

        $patch = [];
        if ($day_wrong) {
            $patch['delivery_day'] = $new_day;
        }
        if ($date_wrong) {
            $patch['next_delivery_date'] = $next;
        }
        return $patch;
    }

    /**
     * Compute the next_delivery_date a client SHOULD carry for the zone's
     * $new_day, using ONLY the canonical MealsDB_Date_Calculator (no date math
     * is reimplemented here). Two cases, in priority order:
     *
     *   1. A stored next_delivery_date already exists — this is the drift
     *      remediation case (the 12C.1 defect). Keep that occurrence but move
     *      it to the zone's weekday WITHIN its own Sun..Sat week
     *      (snap_to_delivery_day) — the minimal, faithful correction. Only if
     *      moving the weekday lands the date in the past do we roll forward one
     *      cadence cycle so it stays a valid upcoming date. If the stored date
     *      is already on the right weekday it is returned unchanged (even if in
     *      the past — advancing a correct-weekday past date is out of scope for
     *      a weekday-drift resync).
     *   2. No stored occurrence to correct — seed one the way the backfill does
     *      (MealsDB_Migration_Consolidated::run_phase_next_dates): project the
     *      client's last_delivery_date forward by delivery_frequency weeks,
     *      snapped to $new_day. The anchor lives in WP usermeta (written only by
     *      mark_delivered), so it is frequently absent.
     *
     * Returns null when neither a stored date nor a usable anchor exists — the
     * caller then leaves next_delivery_date untouched (never nulls a value it
     * can't replace).
     *
     * @param array<string, mixed> $row
     */
    private static function compute_next_delivery_date(array $row, string $new_day): ?string {
        if (!class_exists('MealsDB_Date_Calculator')) {
            return null;
        }
        $freq       = (int) ($row['delivery_frequency'] ?? 0);
        $stored_ymd = substr((string) ($row['next_delivery_date'] ?? ''), 0, 10);

        // Case 1 — correct the weekday of the existing occurrence in place.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $stored_ymd)) {
            $snapped = MealsDB_Date_Calculator::snap_to_delivery_day($stored_ymd, $new_day);
            if ($snapped !== null) {
                if ($snapped !== $stored_ymd && $snapped < gmdate('Y-m-d')) {
                    // We moved the weekday and it landed in the past — advance
                    // to the next cadence occurrence of that weekday. A missing
                    // frequency degrades to a one-week step (freq 0 can't drive
                    // next_date), which still yields a valid future date.
                    $forward = MealsDB_Date_Calculator::next_date($snapped, $freq > 0 ? $freq : 1, $new_day);
                    if ($forward !== null) {
                        return $forward;
                    }
                }
                return $snapped;
            }
        }

        // Case 2 — no stored occurrence: seed from last_delivery_date usermeta.
        $wp_user_id = (int) ($row['wp_user_id'] ?? 0);
        if ($wp_user_id > 0 && $freq > 0 && function_exists('get_user_meta')) {
            $last = substr((string) get_user_meta($wp_user_id, 'last_delivery_date', true), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $last)) {
                $next = MealsDB_Date_Calculator::next_date($last, $freq, $new_day);
                if ($next !== null) {
                    return $next;
                }
            }
        }

        return null;
    }
}
