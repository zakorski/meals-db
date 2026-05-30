<?php
/**
 * Log retention cron — prunes the `meals_event_log` trunk (directive
 * STR-LOG) so it never grows unbounded (the MAJ-2 lesson). Runs at 04:30
 * site time, 30 min after the daily report, so report queries see the
 * full window before pruning begins.
 *
 * Pruning is by SEVERITY + AGE, with two hard guards (directive
 * §"disciplines" #5):
 *   - NEVER prune an outcome='running' row (the daily report's hang
 *     detection needs it to stay visible until it resolves).
 *   - NEVER prune an unresolved 'degraded'/'failed' row until it has aged
 *     past the long window — a swallowed problem must survive long enough
 *     to be noticed, regardless of its severity band.
 *
 * meals_audit_log is NOT touched here — it is a compliance artifact with
 * its own (long, legally-mandated) retention. See CLAUDE.md §6.
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
     * Retention windows in days, by severity band. debug/info churn fast
     * (hook fires, ~10K/month) so keep ~3 months for trend reporting;
     * warnings a little longer; errors/critical a year. degraded/failed
     * rows ALWAYS use the long window regardless of their severity.
     */
    private const SHORT_DAYS  = 90;   // debug, info
    private const MEDIUM_DAYS = 180;  // notice, warning
    private const LONG_DAYS   = 365;  // error, critical — and all degraded/failed

    /**
     * Hard cap on rows deleted per pass. Without this, a backlog
     * accumulated during an outage could be deleted in a single statement
     * large enough to lock the table for seconds — stretching across a
     * checkout request that fires a hook.
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
            $deleted = 0;

            // Per-band prune of RESOLVED, non-running rows. degraded/failed
            // are excluded here and handled by the long-window pass below.
            $deleted += self::prune_band(['debug', 'info'], self::SHORT_DAYS);
            $deleted += self::prune_band(['notice', 'warning'], self::MEDIUM_DAYS);
            $deleted += self::prune_band(['error', 'critical'], self::LONG_DAYS);

            // Aged degraded/failed (any severity), excluding running.
            $deleted += self::prune_unresolved(self::LONG_DAYS);

            // Allocation errors (directive MAJ-2). Low-volume,
            // investigation-relevant — match the LONG band (1yr), pruned by
            // last_seen_at so a still-recurring spillover (old first_seen,
            // recent last_seen) survives; only errors that stopped recurring
            // (resolved-by-absence) age out.
            $deleted_alloc = self::prune_allocation_errors(self::LONG_DAYS);

            MealsDB_Job_Logger::finish($log_id, [
                'records_processed'        => $deleted + $deleted_alloc,
                'event_log_deleted'        => $deleted,
                'allocation_errors_deleted' => $deleted_alloc,
            ]);
        } catch (\Throwable $e) {
            MealsDB_Job_Logger::fail($log_id, $e->getMessage());
            // Re-throw so WP-Cron flags the failure.
            throw $e;
        }
    }

    /**
     * Delete rows in the given severity band older than $days, but only
     * those whose outcome is neither 'running' (never prune) nor
     * 'degraded'/'failed' (handled separately on the long window).
     *
     * @param string[] $severities
     */
    private static function prune_band(array $severities, int $days): int {
        global $wpdb;

        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        $sev_placeholders = implode(',', array_fill(0, count($severities), '%s'));
        $sql = $wpdb->prepare(
            "DELETE FROM `{$table}`
             WHERE occurred_at < %s
               AND severity IN ($sev_placeholders)
               AND outcome NOT IN (%s, %s, %s)
             LIMIT %d",
            array_merge(
                [$cutoff],
                $severities,
                [
                    MealsDB_Event_Log::OUTCOME_RUNNING,
                    MealsDB_Event_Log::OUTCOME_DEGRADED,
                    MealsDB_Event_Log::OUTCOME_FAILED,
                    self::MAX_ROWS_PER_PASS,
                ]
            )
        );
        $result = $wpdb->query($sql);
        return $result === false ? 0 : (int) $result;
    }

    /**
     * Delete aged 'degraded'/'failed' rows (any severity) older than
     * $days. 'running' is excluded — it is never pruned.
     */
    private static function prune_unresolved(int $days): int {
        global $wpdb;

        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        $sql = $wpdb->prepare(
            "DELETE FROM `{$table}`
             WHERE occurred_at < %s
               AND outcome IN (%s, %s)
             LIMIT %d",
            $cutoff,
            MealsDB_Event_Log::OUTCOME_DEGRADED,
            MealsDB_Event_Log::OUTCOME_FAILED,
            self::MAX_ROWS_PER_PASS
        );
        $result = $wpdb->query($sql);
        return $result === false ? 0 : (int) $result;
    }

    /**
     * Prune aged allocation_errors (directive MAJ-2). Unlike the event-log
     * trunk this table has no severity band — it is uniformly low-volume and
     * investigation-relevant, so it uses the long window.
     *
     * Pruned by last_seen_at, NEVER first_seen_at: a long-running recurring
     * spillover has an OLD first_seen but a RECENT last_seen and is still
     * active — keep it. Only an error that STOPPED recurring (its last_seen
     * has aged past the window — resolved-by-absence) is deleted.
     *
     * Legacy rows written before the MAJ-2 schema bump may have NULL
     * timestamps; COALESCE down to created_at then a floor so they compare
     * meaningfully and eventually age out rather than living forever.
     *
     * Bounded by the same LIMIT as the trunk passes so a backlog can't lock
     * the table for seconds during a checkout-triggered hook.
     */
    private static function prune_allocation_errors(int $days): int {
        global $wpdb;

        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::ALLOCATION_ERRORS);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        $sql = $wpdb->prepare(
            "DELETE FROM `{$table}`
             WHERE COALESCE(last_seen_at, created_at, '1970-01-01 00:00:00') < %s
             LIMIT %d",
            $cutoff,
            self::MAX_ROWS_PER_PASS
        );
        $result = $wpdb->query($sql);
        return $result === false ? 0 : (int) $result;
    }
}
