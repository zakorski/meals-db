<?php
/**
 * Log retention cron — prunes meals_hook_log and meals_job_log so
 * neither table grows unbounded. Runs at 04:30 site time (30 min
 * after the daily report) so report queries see the full window
 * before pruning begins.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Log_Retention {

    public const HOOK     = 'mealsdb_log_retention';
    public const JOB_NAME = 'log_retention';

    /**
     * Retention windows in days. Hook log churns fast (10K rows/month
     * typical) so keep ~3 months for trend reporting. Job log is tiny
     * (a handful of rows per day) so keep a year for "did this fire
     * monthly" investigations.
     */
    private const HOOK_LOG_DAYS = 90;
    private const JOB_LOG_DAYS  = 365;

    /**
     * Hard cap on rows deleted per pass. Without this, a backlog
     * accumulated during an outage could be deleted in a single
     * statement large enough to lock the table for seconds —
     * stretching across a checkout request that fires a hook.
     */
    private const MAX_ROWS_PER_PASS = 5000;

    public static function register_hooks(): void {
        add_action(self::HOOK, [self::class, 'run']);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(strtotime('tomorrow 04:30:00'), 'daily', self::HOOK);
        }
    }

    public static function run(): void {
        $log_id = MealsDB_Job_Logger::start(self::JOB_NAME);
        try {
            $hook_deleted = self::prune_table(
                MealsDB_Tables::HOOK_LOG,
                'fired_at',
                self::HOOK_LOG_DAYS
            );
            $job_deleted = self::prune_table(
                MealsDB_Tables::JOB_LOG,
                'started_at',
                self::JOB_LOG_DAYS,
                ['running'] // never prune a row that's still 'running'
            );

            MealsDB_Job_Logger::finish($log_id, [
                'records_processed' => $hook_deleted + $job_deleted,
                'hook_log_deleted'  => $hook_deleted,
                'job_log_deleted'   => $job_deleted,
            ]);
        } catch (\Throwable $e) {
            MealsDB_Job_Logger::fail($log_id, $e->getMessage());
            // Re-throw so WP-Cron flags the failure.
            throw $e;
        }
    }

    /**
     * @param string   $table_const           Constant from MealsDB_Tables.
     * @param string   $timestamp_column      Column used to determine age.
     * @param int      $days                  Keep rows newer than this.
     * @param string[] $exclude_running_status If set, exclude these
     *                                        statuses from the delete
     *                                        (only used on the job log).
     */
    private static function prune_table(
        string $table_const,
        string $timestamp_column,
        int $days,
        array $exclude_running_status = []
    ): int {
        global $wpdb;

        $table  = MealsDB_DB::get_table_name($table_const);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        if (!empty($exclude_running_status)) {
            // The job log can have a row still in 'running' state
            // because the job is genuinely long-running. Don't prune
            // those; the daily report's hang-detection needs them.
            $placeholders = implode(',', array_fill(0, count($exclude_running_status), '%s'));
            $sql = $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE `{$timestamp_column}` < %s AND status NOT IN ($placeholders) LIMIT %d",
                array_merge([$cutoff], $exclude_running_status, [self::MAX_ROWS_PER_PASS])
            );
        } else {
            $sql = $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE `{$timestamp_column}` < %s LIMIT %d",
                $cutoff,
                self::MAX_ROWS_PER_PASS
            );
        }

        $result = $wpdb->query($sql);
        return $result === false ? 0 : (int) $result;
    }
}
