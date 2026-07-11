<?php
/**
 * Task type: po_confirm_arrival — "Did PO #X arrive?" for WORKFLOW POs
 * (spec 2026-07-10 task-integration §3a). Spawned by MealsDB_PO_Task_Bridge
 * when a PO is approved; due on the expected-arrival date.
 *
 * Either side actuates: answering yes calls the same guarded
 * MealsDB_Purchase_Orders::mark_received() the PO page uses, so whichever
 * side acts second is a graceful no-op — the double-bump protection lives
 * in the service's status-guarded transition, not here.
 *
 * A "no" answer auto-defers the task by 1 day (legacy pattern preserved).
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Type_PO_Confirm_Arrival {

    public const TYPE_ID = 'po_confirm_arrival';

    public static function register(): void {
        if (!class_exists('MealsDB_Task_Registry')) {
            return;
        }

        MealsDB_Task_Registry::register(self::TYPE_ID, [
            'label'         => __('Confirm PO Arrival', 'meals-db'),
            'description'   => __('Confirm that an approved purchase order has arrived (adds it to inventory).', 'meals-db'),
            'assignee_role' => 'warehouse',
            'urgency'       => MealsDB_Task_Engine::URGENCY_ROUTINE,
            'form_schema'   => [
                ['name' => 'po_number',        'type' => 'text', 'label' => __('PO Number', 'meals-db'), 'readonly' => true],
                ['name' => 'supplier',         'type' => 'text', 'label' => __('Supplier', 'meals-db'), 'readonly' => true],
                ['name' => 'expected_arrival', 'type' => 'date', 'label' => __('Expected', 'meals-db'), 'readonly' => true],
                ['name' => 'arrived',          'type' => 'yesno', 'label' => __('Did it arrive?', 'meals-db'), 'required' => true],
                ['name' => 'arrival_date',     'type' => 'date', 'label' => __('Actual arrival date', 'meals-db'),
                 'show_when' => ['field' => 'arrived', 'equals' => 'yes']],
                ['name' => 'notes',            'type' => 'textarea', 'label' => __('Notes', 'meals-db')],
            ],
            'on_complete'   => [self::class, 'handle_complete'],
        ]);
    }

    public static function handle_complete(array $task, array $form_data, int $completed_by): void {
        $arrived = isset($form_data['arrived']) ? (string) $form_data['arrived'] : 'no';
        $po_id   = isset($task['related_entity_id']) ? (int) $task['related_entity_id'] : 0;

        if ($arrived !== 'yes') {
            // Permissive UX (legacy pattern): finishing the form with
            // arrived=no auto-defers by 1 day instead of losing the task
            // in 'completed' limbo.
            $engine = new MealsDB_Task_Engine();
            $engine->defer_task(
                (int) $task['task_id'],
                gmdate('Y-m-d', strtotime('+1 day')),
                'Auto-deferred: PO did not arrive on expected date.',
                true // allow_from_terminal: reverse the just-committed completion
            );
            return;
        }

        if ($po_id <= 0) {
            self::degrade($task, 'po_task.confirm_no_entity', 'Task has no related PO.');
            return;
        }

        $service      = new MealsDB_Purchase_Orders();
        $arrival_date = isset($form_data['arrival_date']) ? (string) $form_data['arrival_date'] : null;

        $result = $service->mark_received($po_id, $arrival_date);
        if ($result === true) {
            return;
        }

        // The transition was refused. If the PO is already past 'placed', the
        // PO page (or another task) acted first — that is SUCCESS for this
        // task's purpose, not an error. Anything else is a stale/broken task.
        $po     = $service->get($po_id);
        $status = is_array($po) ? (string) ($po['status'] ?? '') : '';
        if (in_array($status, [MealsDB_Purchase_Orders::STATUS_ARRIVED, MealsDB_Purchase_Orders::STATUS_RECONCILED], true)) {
            return; // already received — nothing to do, no degraded noise
        }

        $message = is_wp_error($result) ? $result->get_error_message() : 'mark_received refused';
        self::degrade($task, 'po_task.stale_confirm', sprintf('PO %d status "%s": %s', $po_id, $status, $message));
    }

    /**
     * The engine has already committed this task as completed (callbacks fire
     * post-commit) — surfacing the problem on the Event Log dashboard is the
     * operator's signal to finish the work on the PO page.
     */
    private static function degrade(array $task, string $event, string $message): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Task po_confirm_arrival] ' . $message);
        }
        if (class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'task',
                'subsystem' => 'po_task_types',
                'event'     => $event,
                'outcome'   => 'degraded',
                'message'   => $message,
                'context'   => ['task_id' => (int) ($task['task_id'] ?? 0)],
            ]);
        }
    }
}
