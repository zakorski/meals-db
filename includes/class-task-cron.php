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
        }
    }
}
