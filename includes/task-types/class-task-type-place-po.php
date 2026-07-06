<?php
/**
 * Task type: place_po — recurring "place a purchase order" work item.
 *
 * Operator fills in PO number, items, dates. On complete, creates a row in
 * meals_purchase_orders and spawns a follow-up confirm_po_arrival task
 * scheduled for the expected arrival date.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Type_Place_PO {

    public const TYPE_ID = 'place_po';

    public static function register(): void {
        if (!class_exists('MealsDB_Task_Registry')) {
            return;
        }

        MealsDB_Task_Registry::register(self::TYPE_ID, [
            'label'         => __('Place Purchase Order', 'meals-db'),
            'description'   => __('Place a recurring purchase order.', 'meals-db'),
            'assignee_role' => 'admin',
            'urgency'       => MealsDB_Task_Engine::URGENCY_ROUTINE,
            'form_schema'   => [
                ['name' => 'supplier',         'type' => 'text',     'label' => __('Supplier', 'meals-db'), 'readonly' => true],
                ['name' => 'po_number',        'type' => 'text',     'label' => __('PO Number', 'meals-db'), 'required' => true],
                ['name' => 'placed_date',      'type' => 'date',     'label' => __('Placed Date', 'meals-db'), 'required' => true],
                ['name' => 'expected_arrival', 'type' => 'date',     'label' => __('Expected Arrival', 'meals-db'), 'required' => true],
                ['name' => 'items_csv',        'type' => 'textarea', 'label' => __('Items — one per line: SKU, name, qty', 'meals-db'), 'required' => true,
                 'description' => __('Example: CD-001, Chicken dinner, 48', 'meals-db')],
                ['name' => 'notes',            'type' => 'textarea', 'label' => __('Notes', 'meals-db')],
            ],
            'on_complete'   => [self::class, 'handle_complete'],
        ]);
    }

    /**
     * Parse the CSV textarea into a list of [sku, product_name, quantity_ordered].
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parse_items_csv(string $csv): array {
        $items = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if (!is_array($lines)) {
            return [];
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Pass explicit delimiter/enclosure/escape. PHP 8.4 deprecates
            // relying on the default proprietary $escape for str_getcsv(); an
            // empty escape ('') also stops backslashes in a SKU/name from being
            // mangled by the legacy escaping.
            $parts = str_getcsv($line, ',', '"', '');
            if (count($parts) < 3) {
                continue;
            }
            $sku = trim((string) $parts[0]);
            $name = trim((string) $parts[1]);
            $qty = (int) preg_replace('/[^0-9\-]/', '', (string) $parts[2]);
            if ($sku === '' || $qty <= 0) {
                continue;
            }
            $items[] = [
                'sku'              => $sku,
                'product_name'     => $name,
                'quantity_ordered' => $qty,
            ];
        }
        return $items;
    }

    /**
     * On-complete: create PO, spawn follow-up arrival confirmation.
     */
    public static function handle_complete(array $task, array $form_data, int $completed_by): void {
        $items_csv = isset($form_data['items_csv']) ? (string) $form_data['items_csv'] : '';
        $items = self::parse_items_csv($items_csv);

        if (empty($items)) {
            error_log(sprintf('[MealsDB Task place_po] task %d completed but no items parsed from CSV.', (int) $task['task_id']));
        }

        $supplier = '';
        $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
        if (!empty($payload['supplier'])) {
            $supplier = (string) $payload['supplier'];
        }

        $po_service = new MealsDB_Purchase_Orders();
        $po_id = $po_service->create([
            'po_number'        => (string) ($form_data['po_number'] ?? ''),
            'supplier'         => $supplier,
            'placed_date'      => (string) ($form_data['placed_date'] ?? ''),
            'expected_arrival' => (string) ($form_data['expected_arrival'] ?? ''),
            'status'           => MealsDB_Purchase_Orders::STATUS_PLACED,
            'items'            => $items,
            'notes'            => isset($form_data['notes']) ? (string) $form_data['notes'] : null,
        ]);

        if ($po_id <= 0) {
            error_log(sprintf('[MealsDB Task place_po] task %d: PO create failed (duplicate po_number?).', (int) $task['task_id']));
            return;
        }

        $engine = new MealsDB_Task_Engine();
        $engine->create_task([
            'task_type'           => MealsDB_Task_Type_Confirm_PO_Arrival::TYPE_ID,
            'payload'             => [
                'po_number'        => (string) ($form_data['po_number'] ?? ''),
                'expected_arrival' => (string) ($form_data['expected_arrival'] ?? ''),
                'supplier'         => $supplier,
                'items'            => $items,
            ],
            'next_run_date'       => (string) ($form_data['expected_arrival'] ?? gmdate('Y-m-d')),
            'parent_task_id'      => (int) $task['task_id'],
            'related_entity_type' => 'po',
            'related_entity_id'   => $po_id,
            'assignee_role'       => 'warehouse',
            'urgency'             => MealsDB_Task_Engine::URGENCY_ROUTINE,
        ]);
    }
}
