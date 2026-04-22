<?php
/**
 * Generic reminder task type — proof-of-concept for the task engine.
 *
 * Exists purely to validate the whole task/rule/cron chain end-to-end.
 * Real workflows (call_client, place_po, confirm_po_arrival, etc.) ship in
 * Phase R2 on top of this foundation.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Type_Generic_Reminder {

    public const TYPE_ID = 'generic_reminder';

    /**
     * Register this type with the shared registry.
     */
    public static function register(): void {
        if (!class_exists('MealsDB_Task_Registry')) {
            return;
        }

        MealsDB_Task_Registry::register(self::TYPE_ID, [
            'label'         => __('Reminder', 'meals-db'),
            'description'   => __('A generic reminder task.', 'meals-db'),
            'assignee_role' => 'admin',
            'urgency'       => MealsDB_Task_Engine::URGENCY_ROUTINE,
            'form_schema'   => [
                [
                    'name'     => 'description',
                    'type'     => 'textarea',
                    'label'    => __('Description', 'meals-db'),
                    'required' => true,
                    'readonly' => true,
                ],
                [
                    'name'     => 'done',
                    'type'     => 'yesno',
                    'label'    => __('Completed?', 'meals-db'),
                    'required' => true,
                ],
                [
                    'name'     => 'notes',
                    'type'     => 'textarea',
                    'label'    => __('Notes', 'meals-db'),
                    'required' => false,
                ],
            ],
            'on_complete'   => [self::class, 'handle_complete'],
        ]);
    }

    /**
     * Completion callback — no downstream behaviour for the POC, just a log
     * line so operators can verify the callback actually fires.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $form_data
     */
    public static function handle_complete(array $task, array $form_data, int $completed_by): void {
        error_log(sprintf(
            '[MealsDB Task] Generic reminder %d completed with done=%s',
            (int) ($task['task_id'] ?? 0),
            isset($form_data['done']) ? (string) $form_data['done'] : 'unknown'
        ));
    }
}
