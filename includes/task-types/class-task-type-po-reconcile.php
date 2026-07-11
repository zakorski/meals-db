<?php
/**
 * Task type: po_reconcile — record what actually arrived for a WORKFLOW PO
 * (spec 2026-07-10 task-integration §3b). Spawned by MealsDB_PO_Task_Bridge
 * when a PO is marked received; due +7 days later.
 *
 * Counts are in CASES (matching the PO page's +/- reconcile UI). The handler
 * routes every row through MealsDB_Purchase_Orders::edit_reconcile_row() and
 * then complete_reconcile() — the SAME validated, audited path the PO page
 * uses, so ordered quantities are server-sourced and the note-per-adjusted-row
 * rule is enforced by the service regardless of what the form claimed.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Type_PO_Reconcile {

    public const TYPE_ID = 'po_reconcile';

    public static function register(): void {
        if (!class_exists('MealsDB_Task_Registry')) {
            return;
        }

        MealsDB_Task_Registry::register(self::TYPE_ID, [
            'label'         => __('Reconcile PO', 'meals-db'),
            'description'   => __('Record the actually-received case counts for a purchase order; stock is corrected for any differences.', 'meals-db'),
            'assignee_role' => 'warehouse',
            'urgency'       => MealsDB_Task_Engine::URGENCY_ROUTINE,
            'form_schema'   => [
                ['name' => 'po_number',      'type' => 'text', 'label' => __('PO Number', 'meals-db'), 'readonly' => true],
                ['name' => 'count_received', 'type' => 'yesno', 'required' => true,
                 'label' => __('Have you counted the delivery?', 'meals-db')],
                ['name'       => 'sku_rows',
                 'type'       => 'repeat_group',
                 'label'      => __('Received counts (cases)', 'meals-db'),
                 'items_from' => 'payload.rows',
                 'show_when'  => ['field' => 'count_received', 'equals' => 'yes'],
                 'fields'     => [
                     ['name' => 'sku',            'type' => 'text',   'label' => __('SKU', 'meals-db'), 'readonly' => true],
                     ['name' => 'product_name',   'type' => 'text',   'label' => __('Product', 'meals-db'), 'readonly' => true],
                     ['name' => 'ordered_cases',  'type' => 'number', 'label' => __('Ordered (cases)', 'meals-db'), 'readonly' => true],
                     ['name' => 'received_cases', 'type' => 'number', 'label' => __('Received (cases)', 'meals-db'), 'required' => true, 'min' => 0],
                     ['name' => 'note',           'type' => 'text',   'label' => __('Why does it differ?', 'meals-db'), 'required' => true,
                      'show_when' => ['field' => 'received_cases', 'not_equals_field' => 'ordered_cases']],
                 ]],
                ['name' => 'overall_notes',  'type' => 'textarea', 'label' => __('Overall notes', 'meals-db')],
            ],
            'on_complete'   => [self::class, 'handle_complete'],
        ]);
    }

    public static function handle_complete(array $task, array $form_data, int $completed_by): void {
        $counted = isset($form_data['count_received']) ? (string) $form_data['count_received'] : 'no';
        $po_id   = isset($task['related_entity_id']) ? (int) $task['related_entity_id'] : 0;

        if ($counted !== 'yes') {
            $engine = new MealsDB_Task_Engine();
            $engine->defer_task(
                (int) $task['task_id'],
                gmdate('Y-m-d', strtotime('+1 day')),
                'Auto-deferred: delivery not counted yet.',
                true
            );
            return;
        }

        if ($po_id <= 0) {
            self::degrade($task, 'po_task.reconcile_no_entity', 'Task has no related PO.');
            return;
        }

        $service = new MealsDB_Purchase_Orders();
        $po      = $service->get($po_id);
        $status  = is_array($po) ? (string) ($po['status'] ?? '') : '';
        if ($status === MealsDB_Purchase_Orders::STATUS_RECONCILED) {
            return; // PO page acted first — done, no degraded noise
        }

        // Persist every submitted row into the reconcile session. The service
        // re-derives ordered counts and deltas from ITS payload — the form's
        // readonly ordered_cases is display-only and never trusted.
        $rows = isset($form_data['sku_rows']) && is_array($form_data['sku_rows']) ? $form_data['sku_rows'] : [];
        $row_errors = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku      = isset($row['sku']) ? (string) $row['sku'] : '';
            $received = isset($row['received_cases']) ? (int) $row['received_cases'] : 0;
            $note     = isset($row['note']) ? (string) $row['note'] : '';
            if ($sku === '') {
                continue;
            }
            $result = $service->edit_reconcile_row($po_id, $sku, $received, $note);
            if (is_wp_error($result)) {
                $row_errors[] = $sku . ': ' . $result->get_error_message();
            }
        }

        $result = $service->complete_reconcile($po_id);
        if ($result === true) {
            if (!empty($row_errors)) {
                // Completed, but some rows were refused (e.g. tampered SKU) —
                // surface them; the applied deltas are already audited.
                self::degrade($task, 'po_task.reconcile_partial', implode(' | ', $row_errors));
            }
            return;
        }

        // Refused. Already reconciled (race with the PO page) is success.
        $po     = $service->get($po_id);
        $status = is_array($po) ? (string) ($po['status'] ?? '') : '';
        if ($status === MealsDB_Purchase_Orders::STATUS_RECONCILED) {
            return;
        }

        $message = is_wp_error($result) ? $result->get_error_message() : 'complete_reconcile refused';
        if (!empty($row_errors)) {
            $message .= ' | rows: ' . implode(' | ', $row_errors);
        }
        self::degrade($task, 'po_task.reconcile_failed', sprintf('PO %d status "%s": %s', $po_id, $status, $message));
    }

    /** Post-commit failure surface — see po_confirm_arrival for rationale. */
    private static function degrade(array $task, string $event, string $message): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Task po_reconcile] ' . $message);
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
