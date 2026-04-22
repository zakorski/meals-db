<?php
/**
 * Task type: call_client — replaces the old call-log-manager.
 *
 * Spawned weekly by the "Weekly Phone Call List" rule using the
 * clients_due_to_reorder query strategy. Outcome controls whether a
 * follow-up call gets spawned (voicemail + callback requested → +1 day).
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Type_Call_Client {

    public const TYPE_ID = 'call_client';

    public static function register(): void {
        if (!class_exists('MealsDB_Task_Registry')) {
            return;
        }

        MealsDB_Task_Registry::register(self::TYPE_ID, [
            'label'         => __('Call Client', 'meals-db'),
            'description'   => __('Call the client to confirm their next order.', 'meals-db'),
            'assignee_role' => 'phone',
            'urgency'       => MealsDB_Task_Engine::URGENCY_ROUTINE,
            'form_schema'   => [
                ['name' => 'client_name', 'type' => 'text', 'label' => __('Client', 'meals-db'), 'readonly' => true],
                ['name' => 'phone',       'type' => 'text', 'label' => __('Phone', 'meals-db'), 'readonly' => true],
                ['name' => 'next_order_date',    'type' => 'date', 'label' => __('Next order date (target)', 'meals-db'), 'readonly' => true],
                ['name' => 'notes',       'type' => 'textarea', 'label' => __('Notes', 'meals-db')],
                ['name' => 'outcome',     'type' => 'select', 'label' => __('Outcome', 'meals-db'), 'required' => true,
                 'options' => [
                     'order_placed' => __('Order placed', 'meals-db'),
                     'voicemail'    => __('Left voicemail', 'meals-db'),
                     'no_answer'    => __('No answer', 'meals-db'),
                     'declined'     => __('Declined this week', 'meals-db'),
                     'other'        => __('Other', 'meals-db'),
                 ]],
                ['name' => 'callback_requested', 'type' => 'yesno', 'label' => __('Callback requested?', 'meals-db'),
                 'show_when' => ['field' => 'outcome', 'equals' => 'voicemail']],
            ],
            'on_complete'   => [self::class, 'handle_complete'],
        ]);
    }

    /**
     * Completion callback. If outcome is voicemail + callback requested,
     * spawn a follow-up call for +1 day.
     */
    public static function handle_complete(array $task, array $form_data, int $completed_by): void {
        $outcome = isset($form_data['outcome']) ? (string) $form_data['outcome'] : '';

        if ($outcome !== 'voicemail') {
            return;
        }

        $callback = $form_data['callback_requested'] ?? null;
        $wants_callback = in_array($callback, ['yes', true, 1, '1'], true);
        if (!$wants_callback) {
            return;
        }

        $tomorrow = gmdate('Y-m-d', strtotime('+1 day'));
        $existing_tags = is_array($task['tags'] ?? null) ? $task['tags'] : [];

        $engine = new MealsDB_Task_Engine();
        $engine->create_task([
            'task_type'           => self::TYPE_ID,
            'payload'             => is_array($task['payload'] ?? null) ? $task['payload'] : [],
            'next_run_date'       => $tomorrow,
            'parent_task_id'      => (int) $task['task_id'],
            'related_entity_type' => $task['related_entity_type'] ?? 'client',
            'related_entity_id'   => isset($task['related_entity_id']) ? (int) $task['related_entity_id'] : null,
            'assignee_role'       => 'phone',
            'urgency'             => MealsDB_Task_Engine::URGENCY_FOLLOW_UP,
            'tags'                => array_values(array_unique(array_merge($existing_tags, ['callback']))),
        ]);
    }

    /**
     * Query strategy: clients due to reorder within the next N days.
     *
     * Returns rows with wp_user_id, first_name, last_name, client_phone_1,
     * next_order_date, ordering_contact_method, client_id. The task
     * rules engine will substitute these into the payload template.
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function clients_due_to_reorder_strategy(array $rule, array $params): array {
        global $wpdb;

        $days_window = isset($params['days_window']) ? max(1, (int) $params['days_window']) : 7;
        $contact_method = isset($params['contact_method']) ? (string) $params['contact_method'] : '';

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $sql = "SELECT client_id, wp_user_id, first_name, last_name, client_phone_1,
                       next_order_date, ordering_contact_method
                FROM `{$clients_table}`
                WHERE active = 1
                  AND wp_user_id > 0
                  AND next_order_date IS NOT NULL
                  AND next_order_date >= CURDATE()
                  AND next_order_date <= DATE_ADD(CURDATE(), INTERVAL %d DAY)";

        $args = [$days_window];

        if ($contact_method !== '') {
            $sql .= " AND LOWER(ordering_contact_method) = LOWER(%s)";
            $args[] = $contact_method;
        }

        $sql .= ' ORDER BY next_order_date ASC, last_name ASC';

        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        // Tag each row with the related-entity hint used by spawn_from_rule.
        return array_map(static function($row) {
            $row['__related_entity_type'] = 'client';
            $row['__related_entity_id']   = (int) ($row['client_id'] ?? 0);
            return $row;
        }, $rows);
    }
}
