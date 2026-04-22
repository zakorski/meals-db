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
        $rules = new MealsDB_Task_Rules();
        $count = $rules->run_cron_pass();
        error_log(sprintf('[MealsDB Task Engine] Nightly sync created %d tasks.', $count));
    }
}
