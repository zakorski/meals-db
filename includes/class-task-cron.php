<?php
/**
 * Task Engine — Nightly cron registration.
 *
 * Runs the schedule-rules pass every day at 02:00 site time (an hour
 * before the allocation engine's 03:00 pass).
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Cron {

    public const HOOK = 'mealsdb_nightly_task_sync';

    /**
     * Transient key for the best-effort overlap lock (directive MAJ-7,
     * Layer 2). Defense-in-depth only — the spawn_key unique index (Layer 1)
     * is the real idempotency guarantee; this just stops two concurrent
     * passes doing redundant work and makes an unexpected overlap visible.
     */
    private const LOCK_KEY = 'mealsdb_task_spawn_running';

    /**
     * Register cron hook and schedule the nightly event.
     */
    public static function init(): void {
        add_action(self::HOOK, [self::class, 'nightly_sync']);

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(strtotime('tomorrow 02:00:00'), 'daily', self::HOOK);
        }
    }

    /**
     * The daily sync — run the rules-to-tasks spawn pass.
     */
    public static function nightly_sync(): void {
        // Layer 2 overlap guard (directive MAJ-7). WP-Cron is request-driven,
        // so a manual re-trigger while the scheduled pass runs (or two
        // overlapping cron fires) CAN run two passes at once. The spawn_key
        // unique index already makes that safe for correctness; this lock just
        // avoids the redundant work and surfaces an unexpected overlap as a
        // degraded trunk event. It is best-effort (a transient is not a hard
        // mutex), which is exactly why Layer 1 is the primary fix.
        if (function_exists('get_transient') && get_transient(self::LOCK_KEY)) {
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'  => 'warning',
                    'category'  => 'job',
                    'subsystem' => 'task_cron',
                    'event'     => 'nightly_sync.overlap_skipped',
                    'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                    'message'   => 'Task spawn pass skipped: another pass is already running.',
                ]);
            }
            return;
        }

        // 15-minute TTL is a safety valve: a crashed pass can't wedge the lock
        // forever (the finally below clears it on the normal path).
        if (function_exists('set_transient') && defined('MINUTE_IN_SECONDS')) {
            set_transient(self::LOCK_KEY, 1, 15 * MINUTE_IN_SECONDS);
        }

        $log_id = class_exists('MealsDB_Job_Logger')
            ? MealsDB_Job_Logger::start('task_cron')
            : 0;

        try {
            $rules = new MealsDB_Task_Rules();
            $count = $rules->run_cron_pass();
            error_log(sprintf('[MealsDB Task Engine] Nightly sync created %d tasks.', $count));

            if ($log_id > 0) {
                MealsDB_Job_Logger::finish($log_id, [
                    'records_processed' => $count,
                    'records_updated'   => $count,
                    'tasks_created'     => $count,
                ]);
            }
        } catch (\Throwable $e) {
            if ($log_id > 0) {
                MealsDB_Job_Logger::fail($log_id, $e->getMessage());
            }
            // Re-throw so WP-Cron sees the failure on its native ledger.
            throw $e;
        } finally {
            if (function_exists('delete_transient')) {
                delete_transient(self::LOCK_KEY);
            }
        }
    }
}
