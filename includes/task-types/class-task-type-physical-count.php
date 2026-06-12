<?php
/**
 * Task type: physical_count — reconcile PO arrival with a warehouse count.
 *
 * Uses the repeat_group form field to present per-SKU rows pre-populated
 * from the parent PO's items list. The "actual_count" the operator enters
 * reconciles the earlier inventory bump done at arrival time — any
 * delta is applied as a further adjustment.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Type_Physical_Count {

    public const TYPE_ID = 'physical_count';

    public static function register(): void {
        if (!class_exists('MealsDB_Task_Registry')) {
            return;
        }

        MealsDB_Task_Registry::register(self::TYPE_ID, [
            'label'         => __('Physical Count Reconciliation', 'meals-db'),
            'description'   => __('Confirm received counts and reconcile the purchase order.', 'meals-db'),
            'assignee_role' => 'warehouse',
            'urgency'       => MealsDB_Task_Engine::URGENCY_ROUTINE,
            'form_schema'   => [
                ['name' => 'po_number',      'type' => 'text', 'label' => __('PO Number', 'meals-db'), 'readonly' => true],
                ['name' => 'count_received', 'type' => 'yesno', 'required' => true,
                 'label' => __('Has the physical count been received from the warehouse?', 'meals-db')],
                ['name'       => 'sku_adjustments',
                 'type'       => 'repeat_group',
                 'label'      => __('SKU Adjustments', 'meals-db'),
                 'items_from' => 'payload.expected_items',
                 'show_when'  => ['field' => 'count_received', 'equals' => 'yes'],
                 'fields'     => [
                     ['name' => 'sku',              'type' => 'text',   'label' => __('SKU', 'meals-db'), 'readonly' => true],
                     ['name' => 'product_name',     'type' => 'text',   'label' => __('Product', 'meals-db'), 'readonly' => true],
                     ['name' => 'quantity_ordered', 'type' => 'number', 'label' => __('Ordered', 'meals-db'), 'readonly' => true],
                     ['name' => 'actual_count',     'type' => 'number', 'label' => __('Actual', 'meals-db'), 'required' => true, 'min' => 0],
                     ['name' => 'reason',           'type' => 'select', 'label' => __('Reason (if differs)', 'meals-db'),
                      'options' => ['', 'damaged', 'not_received', 'backordered', 'overshipped', 'other'],
                      'show_when' => ['field' => 'quantity_ordered', 'not_equals_field' => 'actual_count']],
                     ['name' => 'reason_notes',     'type' => 'text',   'label' => __('Details', 'meals-db'),
                      'show_when' => ['field' => 'reason', 'equals' => 'other']],
                 ]],
                ['name' => 'overall_notes',  'type' => 'textarea', 'label' => __('Overall notes', 'meals-db')],
            ],
            'on_complete'   => [self::class, 'handle_complete'],
        ]);
    }

    public static function handle_complete(array $task, array $form_data, int $completed_by): void {
        $count_received = isset($form_data['count_received']) ? (string) $form_data['count_received'] : 'no';
        if ($count_received !== 'yes') {
            $engine = new MealsDB_Task_Engine();
            $engine->defer_task(
                (int) $task['task_id'],
                gmdate('Y-m-d', strtotime('+1 day')),
                'Auto-deferred: physical count not yet received.',
                true // allow_from_terminal: reverse the just-committed completion
            );
            return;
        }

        $po_id = isset($task['related_entity_id']) ? (int) $task['related_entity_id'] : 0;
        if ($po_id <= 0) {
            error_log(sprintf('[MealsDB Task physical_count] task %d has no related PO.', (int) $task['task_id']));
            return;
        }

        $adjustments = isset($form_data['sku_adjustments']) && is_array($form_data['sku_adjustments'])
            ? $form_data['sku_adjustments']
            : [];

        self::apply_adjustments($po_id, $adjustments);

        $po_service = new MealsDB_Purchase_Orders();
        $po_service->update($po_id, [
            'status'        => MealsDB_Purchase_Orders::STATUS_RECONCILED,
            'reconciled_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Apply per-SKU count deltas to WC stock. The PO's ordered quantities
     * have already been added at arrival time; this method only adjusts
     * for the delta between ordered and actual.
     *
     * @param array<int, array<string, mixed>> $adjustments
     */
    public static function apply_adjustments(int $po_id, array $adjustments): void {
        if (!function_exists('wc_get_product_id_by_sku') || !function_exists('wc_get_product')) {
            error_log('[MealsDB Task physical_count] WooCommerce not available; skipping stock adjustments.');
            return;
        }

        // Source the ORDERED quantity (and the valid SKU set) from the stored PO,
        // NOT the form. quantity_ordered is a readonly display field — trusting
        // the submitted value lets a tampered request apply an arbitrary stock
        // delta. Only actual_count is taken from the operator.
        $ordered_by_sku = [];
        $po = (new MealsDB_Purchase_Orders())->get($po_id);
        foreach ((array) ($po['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $isku = isset($item['sku']) ? (string) $item['sku'] : '';
            if ($isku !== '') {
                $ordered_by_sku[$isku] = isset($item['quantity_ordered']) ? (int) $item['quantity_ordered'] : 0;
            }
        }

        foreach ($adjustments as $adj) {
            if (!is_array($adj)) {
                continue;
            }
            $sku = isset($adj['sku']) ? (string) $adj['sku'] : '';
            // Reject any SKU not actually on this PO — never trust a form-only sku.
            if ($sku === '' || !array_key_exists($sku, $ordered_by_sku)) {
                continue;
            }
            $ordered = $ordered_by_sku[$sku]; // server-sourced
            $actual  = isset($adj['actual_count']) ? (int) $adj['actual_count'] : $ordered;
            $diff    = $actual - $ordered;

            if ($diff === 0) {
                continue;
            }

            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                error_log(sprintf('[MealsDB Task physical_count] Unknown SKU "%s"; skipping.', $sku));
                continue;
            }
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $current = (int) $product->get_stock_quantity();
            $new_total = $current + $diff;
            $product->set_stock_quantity($new_total);
            $product->save();

            if (class_exists('MealsDB_Logger')) {
                $reason = isset($adj['reason']) ? (string) $adj['reason'] : '';
                $notes  = isset($adj['reason_notes']) ? (string) $adj['reason_notes'] : '';
                MealsDB_Logger::log(
                    'inventory_discrepancy',
                    (int) $product_id,
                    'stock_quantity',
                    (string) $current,
                    (string) $new_total,
                    sprintf('mealsdb:po=%d:sku=%s:ordered=%d:actual=%d:reason=%s:notes=%s',
                        $po_id, $sku, $ordered, $actual, $reason, $notes)
                );
            }
        }
    }
}
