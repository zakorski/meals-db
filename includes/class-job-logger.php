<?php
/**
 * Job execution logger — now a THIN FACADE over MealsDB_Event_Log
 * (directive STR-LOG, Option A). The public surface is byte-for-byte the
 * same as before the collapse (start / finish / fail / heartbeat /
 * last_success / recent_runs / latest_in_window / _reset_started_cache),
 * so the existing writer call sites in class-allocation-hooks.php,
 * class-daily-report.php, class-log-retention.php, class-sync.php and
 * class-task-cron.php did not have to change.
 *
 * Writes go to the `meals_event_log` trunk with category='job'; the old
 * `meals_job_log` table is retired. The reader methods re-express the old
 * row shape (job_name / status / error_message) from the trunk columns
 * (event / outcome / message) so MealsDB_Daily_Report and the Cron Status
 * page keep rendering identically.
 *
 * Outcome mapping (trunk → legacy status): succeeded→success,
 * failed→failure, degraded→degraded (NEW — a finished-but-swallowed run),
 * running→running.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Job_Logger {

    /**
     * Record the start of a job run. Returns log_id, or 0 on failure.
     */
    public static function start(string $job_name, array $context = []): int {
        return MealsDB_Event_Log::start_job($job_name, $context);
    }

    /**
     * Record successful completion. Callers that detected and swallowed a
     * problem should NOT use this — record outcome='degraded' explicitly
     * via MealsDB_Event_Log so the silent-success class stays greppable.
     */
    public static function finish(int $log_id, array $stats = []): void {
        MealsDB_Event_Log::finish_job($log_id, $stats, MealsDB_Event_Log::OUTCOME_SUCCEEDED);
    }

    /**
     * Record a failed completion (also surfaces on the PHP error log).
     */
    public static function fail(int $log_id, string $error_message, array $stats = []): void {
        MealsDB_Event_Log::fail_job($log_id, $error_message, $stats);
    }

    /**
     * Update record counters mid-batch without changing outcome.
     */
    public static function heartbeat(int $log_id, array $stats): void {
        MealsDB_Event_Log::heartbeat($log_id, $stats);
    }

    /**
     * Most recent SUCCESSFUL run timestamp (UTC) for a job, or null.
     */
    public static function last_success(string $job_name): ?string {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
        $sql   = $wpdb->prepare(
            "SELECT completed_at FROM `{$table}` WHERE category = %s AND event = %s AND outcome = %s ORDER BY completed_at DESC LIMIT 1",
            'job',
            $job_name,
            MealsDB_Event_Log::OUTCOME_SUCCEEDED
        );
        $value = $wpdb->get_var($sql);
        return is_string($value) ? $value : null;
    }

    /**
     * Recent runs for a job (most recent first), mapped to the legacy
     * job_log row shape the readers expect.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent_runs(string $job_name, int $limit = 7): array {
        global $wpdb;

        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 500) {
            $limit = 500;
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
        $sql   = $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE category = %s AND event = %s ORDER BY occurred_at DESC, log_id DESC LIMIT %d",
            'job',
            $job_name,
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        return array_map([self::class, 'map_trunk_to_job_row'], $rows);
    }

    /**
     * Single most recent run (any outcome) since $since_utc.
     *
     * @return array<string, mixed>|null
     */
    public static function latest_in_window(string $job_name, string $since_utc): ?array {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
        $sql   = $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE category = %s AND event = %s AND started_at >= %s ORDER BY started_at DESC LIMIT 1",
            'job',
            $job_name,
            $since_utc
        );
        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? self::map_trunk_to_job_row($row) : null;
    }

    /**
     * Map a trunk row to the legacy job_log row shape. Keeps the readers
     * (Daily Report, Cron Status) untouched: they still read job_name,
     * status, error_message, records_*.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function map_trunk_to_job_row(array $row): array {
        $row['job_name']      = $row['event'] ?? '';
        $row['status']        = self::outcome_to_status((string) ($row['outcome'] ?? ''));
        $row['error_message'] = $row['message'] ?? null;
        return $row;
    }

    /**
     * Translate a trunk outcome to the legacy status vocabulary.
     */
    private static function outcome_to_status(string $outcome): string {
        switch ($outcome) {
            case MealsDB_Event_Log::OUTCOME_SUCCEEDED:
                return 'success';
            case MealsDB_Event_Log::OUTCOME_FAILED:
                return 'failure';
            case MealsDB_Event_Log::OUTCOME_RUNNING:
                return 'running';
            case MealsDB_Event_Log::OUTCOME_DEGRADED:
                return 'degraded';
            default:
                return $outcome;
        }
    }

    /**
     * Test/diagnostic helper: clear the in-memory started-at cache.
     */
    public static function _reset_started_cache(): void {
        MealsDB_Event_Log::_reset_started_cache();
    }
}
