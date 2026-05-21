# Phase R2: Task Workflows — Call Log, PO Lifecycle, Physical Counts

## Prerequisite

Phase R1 (Task Engine Core) must be merged and tested before starting R2. R2 builds specific workflows on top of the generic engine and assumes all R1 infrastructure is available.

## Goal

Wire up the actual workflows the business needs:

1. **Call list** — replicates the old call-log-manager
2. **Scheduled client re-order reminders** — driven by `next_order_date`
3. **PO placement** — "Place PO" task on recurring schedule
4. **PO arrival confirmation** — "Did PO #X arrive?" follow-up after placement
5. **Physical count reconciliation** — "Did physical count arrive?" + per-SKU adjustment form

Also extends the engine with features needed by these workflows: `next_order_date` / `next_delivery_date` on client records, QuickOrder integration for editing those dates, and repeat-group form fields for the physical count workflow.

---

## Part A: Client date fields

### Schema additions to `meals_clients`

Add two new columns via `includes/class-schema.php`:

```php
'next_order_date'    => 'DATE NULL',
'next_delivery_date' => 'DATE NULL',
```

Both nullable. `ordering_frequency` and `delivery_frequency` already exist as INT NULL columns — they store **days between orders/deliveries** (confirmed: the allocation engine already uses `delivery_frequency` as days).

### Sync and form wiring

**Client form** (`includes/class-client-form.php` + `views/partials/client-form-fields.php`):
Add two date inputs under the ordering/delivery section. Save via the existing sync layer.

**WP usermeta sync** (`includes/class-sync.php`):
These fields sync bidirectionally with wp_usermeta keys `next_order_date` and `next_delivery_date`.

**Initial population** (one-time backfill):
Create `includes/services/class-backfill-next-dates.php` that walks clients with `last_order_date` populated in wp_usermeta and computes:
```
next_order_date = last_order_date + ordering_frequency days
next_delivery_date = last_delivery_date + delivery_frequency days
```

Only populates where the field is currently NULL. Wire via a button on the Settings tab.

### QuickOrder integration

**File:** `includes/class-quick-order-ui.php` + `assets/js/quick-order.js`

On the QuickOrder form, display two editable fields in a prominent panel:

```
Next Order Date:    [2026-04-29] (Normally: 2026-05-06)
Next Delivery Date: [2026-05-02] (Normally: 2026-05-09)
```

The "Normally" text shows the rule-default — what these dates would be if today's order followed the standard frequency. The user can edit the editable fields, or click "Reset to normal" to revert.

**File:** `includes/class-quick-order-ajax.php`

When `create_order()` completes successfully, update the client record:

```php
// Get the frequencies
$ordering_frequency  = (int) $client['ordering_frequency']  ?: 7;  // default weekly
$delivery_frequency  = (int) $client['delivery_frequency']  ?: 7;

// User-supplied values from the form (or rule-default if untouched)
$submitted_next_order    = $_POST['next_order_date']    ?? null;
$submitted_next_delivery = $_POST['next_delivery_date'] ?? null;

// Save the user-confirmed values to the client record
if ($submitted_next_order) {
    // Update meals_clients.next_order_date = $submitted_next_order
}
if ($submitted_next_delivery) {
    // Update meals_clients.next_delivery_date = $submitted_next_delivery
}
```

**Important behavior for the "rule resumes from new anchor" rule (Interpretation A):**

The client record's `next_order_date` becomes the anchor for the NEXT cycle. When the user places an order NEXT time (on the date stored in `next_order_date`), the form will show:
```
Next Order Date: [<that date> + ordering_frequency days]
```

This is the default. If the user accepts it, the cycle resumes from the new anchor. If they override again, the anchor shifts again. There's no "original cadence" memory — `ordering_frequency` just tells you how far apart orders are, not when they should happen.

---

## Part B: Repeat-group form fields

R1's form schema only supports scalar fields. The physical count workflow needs per-SKU adjustments — a variable-length list of items, each with multiple fields.

### Extend the form schema

Add a new field type `repeat_group`:

```json
{
  "name": "sku_adjustments",
  "type": "repeat_group",
  "label": "SKU Adjustments",
  "items_from": "payload.expected_items",
  "fields": [
    {"name": "sku", "type": "text", "label": "SKU", "readonly": true},
    {"name": "product_name", "type": "text", "label": "Product", "readonly": true},
    {"name": "expected", "type": "number", "label": "Expected", "readonly": true},
    {"name": "actual", "type": "number", "label": "Actual", "required": true},
    {"name": "reason", "type": "select", "label": "Reason (if differs)", 
     "options": ["damaged", "not_received", "backordered", "overshipped", "other"],
     "show_when": {"field": "expected", "not_equals_field": "actual"}}
  ]
}
```

The `items_from` path points into the task's payload — the renderer resolves `payload.expected_items` as an array and renders one row per item, with the child fields per row. Row data is merged with the child field schema to pre-fill readonly fields.

Form submission collects these into an array under the field name:
```json
{
  "sku_adjustments": [
    {"sku": "CD-001", "actual": 12, "reason": null},
    {"sku": "CD-002", "actual": 10, "reason": "damaged"}
  ]
}
```

Also extend `show_when` to support `not_equals_field` — compare against another field in the same row. This is the only compound condition R2 needs.

### Implementation

Update `assets/js/task-form.js` to handle repeat groups. Server-side, `MealsDB_Task_Engine::validate_form_data()` needs to validate each row's fields against the child schema.

---

## Part C: Task type — `call_client`

**File:** `includes/task-types/class-task-type-call-client.php`

```php
MealsDB_Task_Registry::register('call_client', [
    'label'         => 'Call Client',
    'assignee_role' => 'phone',
    'form_schema'   => [
        ['name' => 'client_name', 'type' => 'text', 'label' => 'Client', 'readonly' => true],
        ['name' => 'phone',       'type' => 'text', 'label' => 'Phone', 'readonly' => true],
        ['name' => 'notes',       'type' => 'textarea', 'label' => 'Notes'],
        ['name' => 'outcome',     'type' => 'select', 'label' => 'Outcome', 'required' => true,
         'options' => ['order_placed', 'voicemail', 'no_answer', 'declined', 'other']],
        ['name' => 'callback_requested', 'type' => 'yesno', 'label' => 'Callback requested?',
         'show_when' => ['field' => 'outcome', 'equals' => 'voicemail']],
    ],
    'on_complete'   => [self::class, 'handle_complete'],
]);

public static function handle_complete(array $task, array $form_data, int $completed_by): void {
    $outcome = $form_data['outcome'] ?? '';
    
    // If order was placed, the QuickOrder flow will have updated the client's
    // next_order_date independently. Nothing to do here.
    
    // If voicemail + callback requested, spawn a follow-up call for tomorrow
    if ($outcome === 'voicemail' && !empty($form_data['callback_requested'])) {
        $engine = new MealsDB_Task_Engine();
        $engine->create_task([
            'task_type'    => 'call_client',
            'payload'      => $task['payload'],  // carry forward client data
            'next_run_date' => gmdate('Y-m-d', strtotime('+1 day')),
            'parent_task_id' => $task['task_id'],
            'related_entity_type' => 'client',
            'related_entity_id' => $task['related_entity_id'],
            'assignee_role' => 'phone',
            'urgency' => 'follow_up',
            'tags' => array_merge($task['tags'] ?? [], ['callback']),
        ]);
    }
}
```

### Query strategy: `clients_due_to_reorder`

Add to the strategy registry (from R1):

```php
MealsDB_Task_Rules::register_strategy('clients_due_to_reorder', function(array $params): array {
    $days_window = (int) ($params['days_window'] ?? 7);
    $contact_method = $params['contact_method'] ?? null;
    
    $sql = "SELECT client_id, wp_user_id, first_name, last_name, client_phone_1,
                   next_order_date, ordering_contact_method
            FROM meals_clients
            WHERE active = 1
              AND wp_user_id > 0
              AND next_order_date IS NOT NULL
              AND next_order_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
              AND next_order_date >= CURDATE()";
    
    $bind_params = [$days_window];
    
    if ($contact_method) {
        $sql .= " AND LOWER(ordering_contact_method) = LOWER(?)";
        $bind_params[] = $contact_method;
    }
    
    // Execute and return rows (with decrypted first_name/last_name)
});
```

### Seed rule on install

```php
// Every Wednesday 6am — generate call list for clients due to reorder this week
MealsDB_Task_Rules::create([
    'name' => 'Weekly Phone Call List',
    'task_type' => 'call_client',
    'spawn_type' => 'query',
    'recurrence' => ['type' => 'weekly', 'interval' => 1, 'days_of_week' => ['wednesday'], 'time' => '06:00'],
    'query_criteria' => ['strategy' => 'clients_due_to_reorder', 'params' => [
        'days_window' => 7,
        'contact_method' => 'phone',
    ]],
    'payload_template' => [
        'client_id'   => '{{wp_user_id}}',
        'client_name' => '{{first_name}} {{last_name}}',
        'phone'       => '{{client_phone_1}}',
    ],
    'assignee_role' => 'phone',
    'tags' => ['weekly_calls'],
]);
```

### Bulk end-of-week sweep

On the tasks list view, the "Bulk skip" action with filter `{assignee_role: phone, tag: weekly_calls, status: [pending, deferred]}` is the end-of-day Friday cleanup. Make sure this filter is easy to apply — perhaps a "Close remaining phone tasks for this week" button on the tasks page for the phone role.

---

## Part D: PO placement workflow

### Table: `meals_purchase_orders`

New table for tracking POs as first-class entities.

```sql
CREATE TABLE meals_purchase_orders (
    po_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    po_number       VARCHAR(50) NOT NULL,       -- operator-assigned
    placed_date     DATE NULL,
    expected_arrival DATE NULL,
    status          ENUM('planned', 'placed', 'arrived', 'counted', 'reconciled', 'cancelled') NOT NULL DEFAULT 'planned',
    items           JSON NULL,                  -- [{sku, product_name, quantity_ordered}, ...]
    notes           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (po_id),
    UNIQUE KEY uniq_po_number (po_number),
    INDEX idx_status (status),
    INDEX idx_expected_arrival (expected_arrival)
);
```

Add table constant to `MealsDB_Tables`:
```php
public const PURCHASE_ORDERS = 'meals_purchase_orders';
```

### Task type: `place_po`

```php
MealsDB_Task_Registry::register('place_po', [
    'label'         => 'Place Purchase Order',
    'assignee_role' => 'admin',
    'form_schema'   => [
        ['name' => 'po_number', 'type' => 'text', 'label' => 'PO Number', 'required' => true],
        ['name' => 'placed_date', 'type' => 'date', 'label' => 'Placed Date', 'required' => true],
        ['name' => 'expected_arrival', 'type' => 'date', 'label' => 'Expected Arrival', 'required' => true],
        ['name' => 'items_csv', 'type' => 'textarea', 'label' => 'Items (SKU,name,qty per line)', 'required' => true],
        ['name' => 'notes', 'type' => 'textarea', 'label' => 'Notes'],
    ],
    'on_complete'   => [self::class, 'handle_complete'],
]);

public static function handle_complete(array $task, array $form_data, int $completed_by): void {
    // Parse items CSV into structured array
    $items = [];
    foreach (explode("\n", $form_data['items_csv']) as $line) {
        $parts = str_getcsv($line);
        if (count($parts) >= 3) {
            $items[] = ['sku' => trim($parts[0]), 'product_name' => trim($parts[1]), 'quantity_ordered' => (int) $parts[2]];
        }
    }
    
    // Create PO record
    $po_id = MealsDB_Purchase_Orders::create([
        'po_number' => $form_data['po_number'],
        'placed_date' => $form_data['placed_date'],
        'expected_arrival' => $form_data['expected_arrival'],
        'status' => 'placed',
        'items' => $items,
        'notes' => $form_data['notes'] ?? null,
    ]);
    
    // Spawn "Confirm arrival" task for the expected arrival date
    $engine = new MealsDB_Task_Engine();
    $engine->create_task([
        'task_type' => 'confirm_po_arrival',
        'payload' => [
            'po_number' => $form_data['po_number'],
            'expected_arrival' => $form_data['expected_arrival'],
            'items' => $items,
        ],
        'next_run_date' => $form_data['expected_arrival'],
        'parent_task_id' => $task['task_id'],
        'related_entity_type' => 'po',
        'related_entity_id' => $po_id,
        'assignee_role' => 'warehouse',
        'urgency' => 'routine',
    ]);
}
```

### Seed rule on install

```php
// Every 4th Tuesday — place Appetito PO
MealsDB_Task_Rules::create([
    'name' => 'Appetito Purchase Order',
    'task_type' => 'place_po',
    'spawn_type' => 'fixed',
    'recurrence' => ['type' => 'monthly_weekday', 'interval' => 1, 'nth' => 4, 'day_of_week' => 'tuesday', 'time' => '08:00'],
    'payload_template' => ['supplier' => 'Appetito'],
    'assignee_role' => 'admin',
    'tags' => ['appetito_po'],
]);
```

The 4-week cadence in the old system maps to "every 1st Tuesday of the month" OR "every 4 weeks from X anchor date." Pick whichever matches the business's actual pattern (we should confirm with the client).

**Note:** The full Appetito purchase order calculation (seasonal demand projection from Phase O) can be linked from this task — add a "Generate PO suggestion" button on the task detail page that loads the purchase order report pre-filled with the current trailing window. The operator reviews the numbers and pastes them into the task form.

---

## Part E: PO arrival confirmation

### Task type: `confirm_po_arrival`

```php
MealsDB_Task_Registry::register('confirm_po_arrival', [
    'label'         => 'Confirm PO Arrival',
    'assignee_role' => 'warehouse',
    'form_schema'   => [
        ['name' => 'po_number', 'type' => 'text', 'label' => 'PO Number', 'readonly' => true],
        ['name' => 'expected_arrival', 'type' => 'date', 'label' => 'Expected', 'readonly' => true],
        ['name' => 'arrived', 'type' => 'yesno', 'label' => 'Did it arrive?', 'required' => true],
        ['name' => 'arrival_date', 'type' => 'date', 'label' => 'Actual arrival date',
         'show_when' => ['field' => 'arrived', 'equals' => 'yes']],
        ['name' => 'complete_order', 'type' => 'yesno', 'label' => 'Was everything ordered received?',
         'show_when' => ['field' => 'arrived', 'equals' => 'yes']],
        ['name' => 'notes', 'type' => 'textarea', 'label' => 'Notes'],
    ],
    'on_complete'   => [self::class, 'handle_complete'],
]);

public static function handle_complete(array $task, array $form_data, int $completed_by): void {
    $arrived = $form_data['arrived'] ?? 'no';
    $po_id = $task['related_entity_id'];
    
    if ($arrived === 'no') {
        // Auto-defer by 1 day — actually, this should be handled via the "defer" action
        // on the task, not by completing it. Document in UI: if it didn't arrive yet,
        // click "Defer to tomorrow" instead of completing with 'no'.
        // But if they did complete with 'no', treat it as deferred:
        $engine = new MealsDB_Task_Engine();
        $engine->defer_task($task['task_id'], gmdate('Y-m-d', strtotime('+1 day')), 
            'Auto-deferred: PO did not arrive');
        return;
    }
    
    // Update PO status to 'arrived'
    MealsDB_Purchase_Orders::update($po_id, [
        'status' => 'arrived',
        'arrival_date' => $form_data['arrival_date'],
    ]);
    
    // Adjust inventory with ordered quantities (preliminary — physical count will reconcile)
    $po = MealsDB_Purchase_Orders::get($po_id);
    foreach ($po['items'] as $item) {
        $product = wc_get_product_id_by_sku($item['sku']);
        if ($product) {
            $wc_product = wc_get_product($product);
            $current = (int) $wc_product->get_stock_quantity();
            $wc_product->set_stock_quantity($current + $item['quantity_ordered']);
            $wc_product->save();
        }
    }
    
    // Spawn physical count task for +7 days
    $engine = new MealsDB_Task_Engine();
    $engine->create_task([
        'task_type' => 'physical_count',
        'payload' => [
            'po_number' => $task['payload']['po_number'],
            'po_id' => $po_id,
            'expected_items' => $po['items'],  // will drive the repeat_group
        ],
        'next_run_date' => gmdate('Y-m-d', strtotime($form_data['arrival_date'] . ' +7 days')),
        'parent_task_id' => $task['task_id'],
        'related_entity_type' => 'po',
        'related_entity_id' => $po_id,
        'assignee_role' => 'warehouse',
    ]);
}
```

---

## Part F: Physical count reconciliation

### Task type: `physical_count`

Uses the repeat_group field type from Part B.

```php
MealsDB_Task_Registry::register('physical_count', [
    'label'         => 'Physical Count Reconciliation',
    'assignee_role' => 'warehouse',
    'form_schema'   => [
        ['name' => 'po_number', 'type' => 'text', 'label' => 'PO Number', 'readonly' => true],
        ['name' => 'count_received', 'type' => 'yesno', 
         'label' => 'Has the physical count been received from the warehouse?', 'required' => true],
        ['name' => 'sku_adjustments', 'type' => 'repeat_group', 'label' => 'SKU Adjustments',
         'items_from' => 'payload.expected_items',
         'show_when' => ['field' => 'count_received', 'equals' => 'yes'],
         'fields' => [
             ['name' => 'sku', 'type' => 'text', 'label' => 'SKU', 'readonly' => true],
             ['name' => 'product_name', 'type' => 'text', 'label' => 'Product', 'readonly' => true],
             ['name' => 'quantity_ordered', 'type' => 'number', 'label' => 'Ordered', 'readonly' => true],
             ['name' => 'actual_count', 'type' => 'number', 'label' => 'Actual Count', 'required' => true],
             ['name' => 'reason', 'type' => 'select', 'label' => 'Reason (if differs)',
              'options' => ['', 'damaged', 'not_received', 'backordered', 'overshipped', 'other'],
              'show_when' => ['field' => 'quantity_ordered', 'not_equals_field' => 'actual_count']],
             ['name' => 'reason_notes', 'type' => 'text', 'label' => 'Details',
              'show_when' => ['field' => 'reason', 'equals' => 'other']],
         ]],
        ['name' => 'overall_notes', 'type' => 'textarea', 'label' => 'Overall notes'],
    ],
    'on_complete'   => [self::class, 'handle_complete'],
]);

public static function handle_complete(array $task, array $form_data, int $completed_by): void {
    if (($form_data['count_received'] ?? 'no') === 'no') {
        // Auto-defer by 1 day
        $engine = new MealsDB_Task_Engine();
        $engine->defer_task($task['task_id'], gmdate('Y-m-d', strtotime('+1 day')), 
            'Auto-deferred: physical count not yet received');
        return;
    }
    
    $po_id = $task['related_entity_id'];
    $adjustments = $form_data['sku_adjustments'] ?? [];
    
    // For each SKU, adjust WC stock to match actual count
    foreach ($adjustments as $adj) {
        $ordered = (int) $adj['quantity_ordered'];
        $actual = (int) $adj['actual_count'];
        $diff = $actual - $ordered;
        
        if ($diff !== 0) {
            $product_id = wc_get_product_id_by_sku($adj['sku']);
            if ($product_id) {
                $wc_product = wc_get_product($product_id);
                $current = (int) $wc_product->get_stock_quantity();
                $wc_product->set_stock_quantity($current + $diff);
                $wc_product->save();
                
                // Log discrepancy
                MealsDB_Logger::log('inventory_discrepancy', [
                    'po_id' => $po_id,
                    'sku' => $adj['sku'],
                    'ordered' => $ordered,
                    'actual' => $actual,
                    'reason' => $adj['reason'] ?? null,
                    'notes' => $adj['reason_notes'] ?? null,
                ]);
            }
        }
    }
    
    // Mark PO as reconciled
    MealsDB_Purchase_Orders::update($po_id, [
        'status' => 'reconciled',
        'reconciled_at' => current_time('mysql'),
    ]);
}
```

---

## Part G: Purchase order management

### Service class

`includes/services/class-purchase-orders.php`

Basic CRUD on the `meals_purchase_orders` table:
- `create(array $data): int`
- `get(int $po_id): ?array`
- `update(int $po_id, array $updates): bool`
- `query(array $filters): array`
- `get_by_po_number(string $po_number): ?array`

### Admin UI

Add a "Purchase Orders" tab showing the PO lifecycle:
- List view with filters by status, date range
- Detail view showing PO items, linked tasks (place, arrival, count), status history
- Links from related tasks to the PO detail page

This is essentially a read-only view — POs are created and updated via the task lifecycle, not directly.

---

## Part H: Dashboard widget

Add a simple "Today's Tasks" widget to the main dashboard:
- Count of pending tasks due today, grouped by assignee role
- Link to the full tasks tab filtered appropriately
- Visual indicator if anything is overdue

File: `views/partials/dashboard-tasks-widget.php`, included from `views/dashboard.php`.

---

## Part I: Email notifications (optional)

Behind a feature flag (`mealsdb_enable_task_emails` option, default off):
- Daily morning email to each role with today's task list
- Immediate email when a task is escalated

Don't build this in R2 unless the operators explicitly want it. Leaving as a stub with the flag makes it easy to add later.

---

## Tests

Per-workflow integration tests:
- `test-task-workflow-call-client-lifecycle.php` — rule spawns → task completes → next_order_date updates
- `test-task-workflow-po-chain.php` — place → arrival → count with full chain
- `test-task-workflow-next-order-date-anchoring.php` — manual override sticks as new anchor
- `test-repeat-group-validation.php` — repeat_group form validation

---

## Key constraints

- All new workflows use the R1 engine. No direct task/rule manipulation outside the engine.
- PO inventory adjustments go through WC's `set_stock_quantity()` — not direct SQL — so stock change webhooks and logs fire correctly.
- `next_order_date` / `next_delivery_date` on clients are the single source of truth. The call list query reads from them, QuickOrder displays and updates them, order completion recomputes them using the respective frequency in days.
- Repeat-group form fields are the only R2 extension to the form schema. No further field-type additions.
- The phone operator's end-of-week "bulk skip remaining calls" is a primary use case and must be easy to execute.
- Follow the allocation engine pattern for event-driven updates. PO arrival confirmation updates WC inventory; physical count reconciles it. No separate "inventory engine" — WC is authoritative.
- All PO-related changes logged to audit log with sufficient detail to reconstruct history.
