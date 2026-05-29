<?php
/**
 * Hook firing logger — now a THIN FACADE over MealsDB_Event_Log
 * (directive STR-LOG, Option A). Public surface unchanged (record /
 * count_in_window / count_by_outcome / last_fire / trailing_window_counts
 * + the OUTCOME_* constants), so the writer call sites in
 * class-allocation-hooks.php and class-sync.php did not have to change.
 *
 * Writes go to the `meals_event_log` trunk with category='hook'; the old
 * `meals_hook_log` table is retired.
 *
 * Outcome mapping (directive §"facade"): the trunk only has
 * succeeded/degraded, but the daily report still needs the three-way
 * processed/skipped/errored breakdown. We preserve it by pairing the
 * trunk outcome with severity as a discriminator (and stash the original
 * hook outcome in context for the dashboard):
 *
 *   hook processed → outcome=succeeded, severity=info
 *   hook skipped   → outcome=succeeded, severity=debug   (intentional no-op)
 *   hook errored   → outcome=degraded,  severity=warning (caught/swallowed)
 *
 * count_by_outcome() reconstructs the three counts from that pairing.
 * Because the facade controls every hook write, the pairing is consistent
 * — do not log a category='hook' row through MealsDB_Event_Log directly
 * with a different severity, or the breakdown will misclassify it.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Hook_Logger {

    public const OUTCOME_PROCESSED = 'processed';
    public const OUTCOME_SKIPPED   = 'skipped';
    public const OUTCOME_ERRORED   = 'errored';

    /**
     * Record that a hook fired.
     */
    public static function record(
        string $hook_name,
        ?string $target_type = null,
        ?int $target_id = null,
        array $context = [],
        string $outcome = self::OUTCOME_PROCESSED,
        ?string $error_message = null
    ): void {
        if (!in_array($outcome, [self::OUTCOME_PROCESSED, self::OUTCOME_SKIPPED, self::OUTCOME_ERRORED], true)) {
            $outcome = self::OUTCOME_PROCESSED;
        }

        // Preserve the original hook outcome for the dashboard / debugging;
        // count_by_outcome() reconstructs counts from severity+outcome.
        $context['hook_outcome'] = $outcome;

        MealsDB_Event_Log::record([
            'category'      => 'hook',
            'event'         => $hook_name,
            'subsystem'     => 'wc_hooks',
            'outcome'       => self::trunk_outcome($outcome),
            'severity'      => self::trunk_severity($outcome),
            'message'       => $error_message,
            'context'       => $context,
            'entity_type'   => $target_type,
            'entity_id'     => $target_id,
        ]);
    }

    /**
     * Count hook fires within a UTC window.
     */
    public static function count_in_window(string $hook_name, string $start_utc, string $end_utc): int {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
        $sql   = $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE category = %s AND event = %s AND occurred_at >= %s AND occurred_at < %s",
            'hook',
            $hook_name,
            $start_utc,
            $end_utc
        );
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Count hook fires grouped by the (reconstructed) legacy outcome.
     *
     * @return array<string, int> Keys: processed, skipped, errored.
     */
    public static function count_by_outcome(string $hook_name, string $start_utc, string $end_utc): array {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
        // Reconstruct the three-way breakdown from the outcome+severity
        // pairing the facade writes (see class docblock).
        $sql = $wpdb->prepare(
            "SELECT
                SUM(outcome = %s) AS errored,
                SUM(outcome = %s AND severity = %s) AS skipped,
                SUM(outcome = %s AND severity <> %s) AS processed
             FROM `{$table}`
             WHERE category = %s AND event = %s AND occurred_at >= %s AND occurred_at < %s",
            MealsDB_Event_Log::OUTCOME_DEGRADED,
            MealsDB_Event_Log::OUTCOME_SUCCEEDED,
            'debug',
            MealsDB_Event_Log::OUTCOME_SUCCEEDED,
            'debug',
            'hook',
            $hook_name,
            $start_utc,
            $end_utc
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        return [
            self::OUTCOME_PROCESSED => (int) ($row['processed'] ?? 0),
            self::OUTCOME_SKIPPED   => (int) ($row['skipped'] ?? 0),
            self::OUTCOME_ERRORED   => (int) ($row['errored'] ?? 0),
        ];
    }

    /**
     * Most recent fire timestamp (UTC) for a hook, or null.
     */
    public static function last_fire(string $hook_name): ?string {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
        $sql   = $wpdb->prepare(
            "SELECT occurred_at FROM `{$table}` WHERE category = %s AND event = %s ORDER BY occurred_at DESC LIMIT 1",
            'hook',
            $hook_name
        );
        $value = $wpdb->get_var($sql);
        return is_string($value) ? $value : null;
    }

    /**
     * Trailing-N-day daily counts for a hook, ending at $end_utc-1.
     *
     * @return array{daily: array<int, int>, average: float}
     */
    public static function trailing_window_counts(string $hook_name, string $end_utc, int $days): array {
        global $wpdb;

        $days  = max(1, min(90, $days));
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);

        $start_unix = strtotime($end_utc . ' UTC') - ($days * 86400);
        $start_utc  = gmdate('Y-m-d H:i:s', $start_unix);

        $sql = $wpdb->prepare(
            "SELECT DATE(occurred_at) AS d, COUNT(*) AS c FROM `{$table}` WHERE category = %s AND event = %s AND occurred_at >= %s AND occurred_at < %s GROUP BY DATE(occurred_at)",
            'hook',
            $hook_name,
            $start_utc,
            $end_utc
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $by_day = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $by_day[(string) $row['d']] = (int) $row['c'];
            }
        }

        $daily = [];
        for ($i = $days; $i >= 1; $i--) {
            $day = gmdate('Y-m-d', strtotime($end_utc . ' UTC') - ($i * 86400));
            $daily[] = $by_day[$day] ?? 0;
        }
        $sum = array_sum($daily);
        $avg = $days > 0 ? $sum / $days : 0.0;

        return ['daily' => $daily, 'average' => $avg];
    }

    /**
     * Legacy hook outcome → trunk outcome.
     */
    private static function trunk_outcome(string $hook_outcome): string {
        // errored was caught and swallowed by the hook handler → degraded.
        // processed/skipped are both non-error → succeeded.
        return $hook_outcome === self::OUTCOME_ERRORED
            ? MealsDB_Event_Log::OUTCOME_DEGRADED
            : MealsDB_Event_Log::OUTCOME_SUCCEEDED;
    }

    /**
     * Legacy hook outcome → trunk severity (the breakdown discriminator).
     */
    private static function trunk_severity(string $hook_outcome): string {
        switch ($hook_outcome) {
            case self::OUTCOME_ERRORED:
                return 'warning';
            case self::OUTCOME_SKIPPED:
                return 'debug';
            case self::OUTCOME_PROCESSED:
            default:
                return 'info';
        }
    }
}
