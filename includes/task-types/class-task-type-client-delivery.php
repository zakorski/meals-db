<?php
/**
 * Task type: client_delivery — "Deliver to <client>" for a due delivery.
 *
 * Spawned for each client whose next_delivery_date falls in the spawn
 * window (see the clients_due_for_delivery strategy). The single completion
 * action is "Mark as Delivered", which records the delivery and advances the
 * client's delivery cadence:
 *
 *   last_delivery_date <- the delivered date
 *   next_delivery_date <- recomputed (delivery_frequency weeks, snapped to
 *                         the client's delivery day) via the shared calculator
 *
 * This is the explicit delivery-completion signal the system otherwise
 * lacks — WooCommerce knows about orders, not about deliveries happening.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Type_Client_Delivery {

    public const TYPE_ID = 'client_delivery';

    public static function register(): void {
        if (!class_exists('MealsDB_Task_Registry')) {
            return;
        }

        MealsDB_Task_Registry::register(self::TYPE_ID, [
            'label'         => __('Client Delivery', 'meals-db'),
            'description'   => __('Deliver to the client and mark the delivery complete.', 'meals-db'),
            'assignee_role' => 'warehouse',
            'urgency'       => MealsDB_Task_Engine::URGENCY_ROUTINE,
            'form_schema'   => [
                ['name' => 'client_name',        'type' => 'text', 'label' => __('Client', 'meals-db'), 'readonly' => true],
                ['name' => 'delivery_day',       'type' => 'text', 'label' => __('Delivery day', 'meals-db'), 'readonly' => true],
                ['name' => 'next_delivery_date', 'type' => 'date', 'label' => __('Scheduled delivery date', 'meals-db'), 'readonly' => true],
                ['name' => 'delivered_date',     'type' => 'date', 'label' => __('Delivered on', 'meals-db'), 'required' => true],
                ['name' => 'notes',              'type' => 'textarea', 'label' => __('Notes', 'meals-db')],
            ],
            'on_complete'   => [self::class, 'handle_complete'],
        ]);
    }

    /**
     * "Mark as Delivered": advance the client's delivery dates from the
     * delivered date entered on the form (falls back to today).
     */
    public static function handle_complete(array $task, array $form_data, int $completed_by): void {
        $task_id   = (int) ($task['task_id'] ?? 0);
        $client_id = isset($task['related_entity_id']) ? (int) $task['related_entity_id'] : 0;
        if ($client_id <= 0) {
            // Completed with no client to advance — the cadence bookkeeping
            // can't run. Surface it rather than silently doing nothing
            // (audit-2026-08 B11).
            self::degrade($task_id, 'client_delivery.no_entity', 'Delivery task has no related client; cadence not advanced.');
            return;
        }

        $delivered = isset($form_data['delivered_date']) ? (string) $form_data['delivered_date'] : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivered)) {
            $delivered = gmdate('Y-m-d');
        }

        if (!class_exists('MealsDB_Client_Dates')) {
            self::degrade($task_id, 'client_delivery.dep_missing', 'MealsDB_Client_Dates unavailable; delivery cadence not advanced.');
            return;
        }

        // The delivery physically happened (the operator marked it), so we do
        // NOT reopen the task. But if the cadence advance fails, the client's
        // next_delivery_date never moves and no fresh delivery task will spawn —
        // surface it as degraded so an operator can advance the dates by hand
        // (audit-2026-08 B11). mark_delivered returns false on a bad/missing
        // client or an uncomputable next date.
        if (MealsDB_Client_Dates::mark_delivered($client_id, $delivered) === false) {
            self::degrade(
                $task_id,
                'client_delivery.cadence_not_advanced',
                sprintf('mark_delivered failed for client %d; next_delivery_date not advanced.', $client_id)
            );
        }
    }

    /**
     * The completion callback fires post-commit (the task is already
     * 'completed'). Because the delivery itself DID happen, the failure surface
     * is a degraded Event Log entry — the operator's signal to advance the
     * client's dates by hand — not a task reopen.
     */
    private static function degrade(int $task_id, string $event, string $message): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Task client_delivery] ' . $message);
        }
        if (class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'task',
                'subsystem' => 'client_delivery',
                'event'     => $event,
                'outcome'   => 'degraded',
                'message'   => $message,
                'context'   => ['task_id' => $task_id],
            ]);
        }
    }

    /**
     * Spawn strategy: clients whose next_delivery_date falls within the
     * window (default 7 days). Mirrors clients_due_to_reorder but keys on
     * next_delivery_date. One spawn row per due client.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function clients_due_for_delivery_strategy(array $rule, array $params): array {
        global $wpdb;

        $days_window = isset($params['days_window']) ? max(1, (int) $params['days_window']) : 7;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // UTC-bound due window (not DB-session CURDATE) — keeps the spawn window
        // on the same timezone as the rule clock and the stored dates.
        $today_utc = gmdate('Y-m-d');
        $end_utc   = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $days_window . ' days')->format('Y-m-d');

        $sql = "SELECT client_id, wp_user_id, first_name, last_name,
                       delivery_day, next_delivery_date
                FROM `{$clients_table}`
                WHERE active = 1
                  AND wp_user_id > 0
                  AND next_delivery_date IS NOT NULL
                  AND next_delivery_date >= %s
                  AND next_delivery_date <= %s
                ORDER BY next_delivery_date ASC, last_name ASC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $today_utc, $end_utc), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static function($row) {
            $row['__related_entity_type'] = 'client';
            $row['__related_entity_id']   = (int) ($row['client_id'] ?? 0);
            return $row;
        }, $rows);
    }
}
