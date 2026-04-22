<?php
/**
 * Task type: confirm_po_arrival — "Did PO #X arrive?" follow-up.
 *
 * Operator confirms arrival, WC inventory bumps by ordered quantities,
 * and a physical_count task is spawned for +7 days after arrival.
 * A "no" answer auto-defers the task by 1 day.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Type_Confirm_PO_Arrival {

    public const TYPE_ID = 'confirm_po_arrival';

    public static function register(): void {
        if (!class_exists('MealsDB_Task_Registry')) {
            return;
        }

        MealsDB_Task_Registry::register(self::TYPE_ID, [
            'label'         => __('Confirm PO Arrival', 'meals-db'),
            'description'   => __('Confirm that an expected purchase order has arrived.', 'meals-db'),
            'assignee_role' => 'warehouse',
            'urgency'       => MealsDB_Task_Engine::URGENCY_ROUTINE,
            'form_schema'   => [
                ['name' => 'po_number',        'type' => 'text', 'label' => __('PO Number', 'meals-db'), 'readonly' => true],
                ['name' => 'supplier',         'type' => 'text', 'label' => __('Supplier', 'meals-db'), 'readonly' => true],
                ['name' => 'expected_arrival', 'type' => 'date', 'label' => __('Expected', 'meals-db'), 'readonly' => true],
                ['name' => 'arrived',          'type' => 'yesno', 'label' => __('Did it arrive?', 'meals-db'), 'required' => true],
                ['name' => 'arrival_date',     'type' => 'date', 'label' => __('Actual arrival date', 'meals-db'),
                 'show_when' => ['field' => 'arrived', 'equals' => 'yes']],
                ['name' => 'complete_order',   'type' => 'yesno', 'label' => __('Was everything ordered received?', 'meals-db'),
                 'show_when' => ['field' => 'arrived', 'equals' => 'yes']],
                ['name' => 'notes',            'type' => 'textarea', 'label' => __('Notes', 'meals-db')],
            ],
            'on_complete'   => [self::class, 'handle_complete'],
        ]);
    }

    public static function handle_complete(array $task, array $form_data, int $completed_by): void {
        $arrived = isset($form_data['arrived']) ? (string) $form_data['arrived'] : 'no';
        $po_id = isset($task['related_entity_id']) ? (int) $task['related_entity_id'] : 0;

        if ($arrived !== 'yes') {
            // Documented UX: the operator should normally click "Defer" when a
            // PO hasn't arrived yet. But if they finish the form with
            // arrived=no, be permissive and auto-defer by 1 day so the task
            // reappears tomorrow instead of being lost in 'completed' limbo.
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
            error_log(sprintf('[MealsDB Task confirm_po_arrival] task %d has no related PO.', (int) $task['task_id']));
            return;
        }

        $po_service = new MealsDB_Purchase_Orders();
        $po = $po_service->get($po_id);
        if ($po === null) {
            error_log(sprintf('[MealsDB Task confirm_po_arrival] task %d: PO %d not found.', (int) $task['task_id'], $po_id));
            return;
        }

        $arrival_date = isset($form_data['arrival_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $form_data['arrival_date'])
            ? (string) $form_data['arrival_date']
            : gmdate('Y-m-d');

        $po_service->update($po_id, [
            'status'       => MealsDB_Purchase_Orders::STATUS_ARRIVED,
            'arrival_date' => $arrival_date,
        ]);

        // Adjust WC inventory with ordered quantities — preliminary count,
        // the physical_count task will reconcile any discrepancies. Route
        // through WC's setter so stock-change webhooks / logs still fire.
        self::apply_inventory_bump($po['items'] ?? []);

        // Spawn physical_count task for 7 days after actual arrival.
        $count_date = gmdate('Y-m-d', strtotime($arrival_date . ' +7 days'));
        $engine = new MealsDB_Task_Engine();
        $engine->create_task([
            'task_type'           => MealsDB_Task_Type_Physical_Count::TYPE_ID,
            'payload'             => [
                'po_number'      => (string) ($task['payload']['po_number'] ?? $po['po_number']),
                'po_id'          => $po_id,
                'supplier'       => (string) ($task['payload']['supplier'] ?? ($po['supplier'] ?? '')),
                'arrival_date'   => $arrival_date,
                'expected_items' => $po['items'] ?? [],
            ],
            'next_run_date'       => $count_date,
            'parent_task_id'      => (int) $task['task_id'],
            'related_entity_type' => 'po',
            'related_entity_id'   => $po_id,
            'assignee_role'       => 'warehouse',
            'urgency'             => MealsDB_Task_Engine::URGENCY_ROUTINE,
        ]);
    }

    /**
     * Bump WC stock for each item by quantity_ordered. Silently skips items
     * with unknown SKUs, logging each miss.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public static function apply_inventory_bump(array $items): void {
        if (!function_exists('wc_get_product_id_by_sku') || !function_exists('wc_get_product')) {
            error_log('[MealsDB Task confirm_po_arrival] WooCommerce not available; skipping inventory bump.');
            return;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sku = isset($item['sku']) ? (string) $item['sku'] : '';
            $qty = isset($item['quantity_ordered']) ? (int) $item['quantity_ordered'] : 0;
            if ($sku === '' || $qty === 0) {
                continue;
            }

            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                error_log(sprintf('[MealsDB Task confirm_po_arrival] Unknown SKU "%s"; skipping.', $sku));
                continue;
            }
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $current = (int) $product->get_stock_quantity();
            $new_total = $current + $qty;
            $product->set_stock_quantity($new_total);
            $product->save();

            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log(
                    'po_inventory_bump',
                    (int) $product_id,
                    'stock_quantity',
                    (string) $current,
                    (string) $new_total,
                    'mealsdb'
                );
            }
        }
    }
}
