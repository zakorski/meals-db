# Phase R1: Task Engine — Core Infrastructure

## Goal

Build a generic, extensible task/workflow engine that serves as the foundation for the call log manager, PO placement reminders, arrival confirmations, physical count reconciliation, and any future workflow needs. This phase ships only the engine itself — a minimal proof-of-concept task type — but no real workflows yet. Phase R2 will wire up the actual workflows on top of this foundation.

## Why this exists

The call log manager from the old Enzebra plugin (`call-log-manager.php`) was a single-purpose tool. Rather than rebuilding it as a one-off, we're building it as one workflow on top of a general task engine, because the same infrastructure will serve several other needs: scheduled PO placement, arrival confirmations ("Did PO #447 arrive?"), physical count reconciliation a week after delivery, and anything else the operators need to track over time.

The engine pattern follows the allocation engine precedent that's already established — event-driven via hooks, nightly cron for state maintenance, persistent state in dedicated tables.

---

## Architecture

### Two new external DB tables

**`meals_schedule_rules`** — recurring patterns that spawn tasks.

```sql
CREATE TABLE meals_schedule_rules (
    rule_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name             VARCHAR(255) NOT NULL,
    task_type        VARCHAR(100) NOT NULL,
    spawn_type       ENUM('fixed', 'query') NOT NULL DEFAULT 'fixed',
    recurrence       JSON NOT NULL,           -- cron-like pattern definition
    query_criteria   JSON NULL,               -- for spawn_type='query' only
    payload_template JSON NOT NULL,           -- template for spawned task payloads
    tags             JSON NULL,               -- inherited by spawned tasks
    assignee_role    VARCHAR(50) NULL,        -- inherited by spawned tasks
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    next_run_at      DATETIME NULL,
    last_run_at      DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rule_id),
    INDEX idx_active_next_run (is_active, next_run_at),
    INDEX idx_task_type (task_type)
);
```

**`meals_tasks`** — individual work items.

```sql
CREATE TABLE meals_tasks (
    task_id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_type            VARCHAR(100) NOT NULL,
    status               ENUM('pending','in_progress','deferred','completed','skipped','abandoned') NOT NULL DEFAULT 'pending',
    next_run_date        DATE NOT NULL,
    payload              JSON NOT NULL,
    source_rule_id       BIGINT UNSIGNED NULL,
    parent_task_id       BIGINT UNSIGNED NULL,
    related_entity_type  VARCHAR(50) NULL,    -- 'wc_order', 'client', 'po', etc.
    related_entity_id    BIGINT UNSIGNED NULL,
    assignee_role        VARCHAR(50) NULL,    -- 'phone', 'warehouse', 'admin'
    urgency              ENUM('routine','follow_up','escalated') NOT NULL DEFAULT 'routine',
    tags                 JSON NULL,
    deferral_count       INT NOT NULL DEFAULT 0,
    completed_at         DATETIME NULL,
    completed_by         BIGINT UNSIGNED NULL,   -- wp_user_id
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id),
    INDEX idx_status_date (status, next_run_date),
    INDEX idx_assignee_role (assignee_role),
    INDEX idx_related (related_entity_type, related_entity_id),
    INDEX idx_source_rule (source_rule_id),
    INDEX idx_parent (parent_task_id),
    CONSTRAINT fk_task_source_rule FOREIGN KEY (source_rule_id) 
        REFERENCES meals_schedule_rules(rule_id) ON DELETE SET NULL,
    CONSTRAINT fk_task_parent FOREIGN KEY (parent_task_id) 
        REFERENCES meals_tasks(task_id) ON DELETE SET NULL
);
```

Schema registration goes in `includes/class-schema.php` following the existing pattern used by `CLIENT_ALLOCATIONS` / `DELIVERY_ALLOCATIONS`. Add constants to `includes/class-tables.php`:

```php
public const SCHEDULE_RULES = 'meals_schedule_rules';
public const TASKS          = 'meals_tasks';
```

---

## Service classes

### `includes/services/class-task-registry.php`

In-memory registry of task type definitions. Task type modules register themselves at plugin init.

```php
class MealsDB_Task_Registry {
    private static array $types = [];
    
    public static function register(string $type_id, array $definition): void;
    public static function get(string $type_id): ?array;
    public static function get_all(): array;
    public static function has(string $type_id): bool;
}
```

Definition array shape:
```php
[
    'label'         => 'Did PO arrive?',              // human-readable
    'description'   => 'Confirm that a scheduled PO has been received.',
    'assignee_role' => 'warehouse',                   // default; can be overridden per task
    'urgency'       => 'routine',                     // default
    'form_schema'   => [
        ['name' => 'arrived', 'type' => 'yesno', 'label' => 'Did it arrive?', 'required' => true],
        ['name' => 'notes',   'type' => 'textarea', 'label' => 'Notes', 'required' => false],
        // Optional conditional field:
        ['name' => 'reason', 'type' => 'text', 'label' => 'Reason for no', 
         'show_when' => ['field' => 'arrived', 'equals' => 'no']],
    ],
    'on_complete'   => [MealsDB_Task_Type_ConfirmArrival::class, 'handle_complete'],
    'on_defer'      => null,  // optional callback when deferred
    'on_skip'       => null,  // optional callback when skipped
]
```

**Form field types to support in R1:**
- `text` — single-line text input
- `textarea` — multi-line text
- `number` — numeric input with optional min/max
- `date` — date picker
- `yesno` — radio buttons
- `select` — dropdown with options array
- `checkbox` — single boolean

Conditional display via `show_when` — single field equality only. Anything more complex is out of scope for R1.

**Repeat groups** (e.g., per-SKU adjustments for physical count) are NOT in R1. That's a Phase R2 concern for the physical count workflow specifically.

### `includes/services/class-task-engine.php`

Core CRUD and lifecycle operations. Uses mysqli via `MealsDB_DB::get_connection()`.

Methods:

```php
class MealsDB_Task_Engine {
    /**
     * Create a new task. If a type registry entry exists, validates payload against its form_schema.
     */
    public function create_task(array $args): int;
    // $args: task_type, payload, next_run_date, related_entity_type?, related_entity_id?, 
    //        parent_task_id?, source_rule_id?, assignee_role?, urgency?, tags?
    
    /**
     * Get a task by ID. Returns null if not found.
     */
    public function get_task(int $task_id): ?array;
    
    /**
     * Query tasks with filters.
     */
    public function query_tasks(array $filters): array;
    // Supported filters: status (array), assignee_role, task_type, 
    //   related_entity_type+id, next_run_date_before, next_run_date_after,
    //   tags (array — matches any), urgency, order_by, limit, offset
    
    /**
     * Transition a task to 'completed'. Validates form_data against schema.
     * Fires the type's on_complete callback AFTER committing the status change.
     */
    public function complete_task(int $task_id, array $form_data, int $completed_by): bool;
    
    /**
     * Defer a task — move next_run_date forward, increment deferral_count, keep status='deferred'.
     * Default: defer by 1 day.
     */
    public function defer_task(int $task_id, string $new_date, ?string $reason = null): bool;
    
    /**
     * Transition a task to 'skipped'. Fires on_skip callback.
     */
    public function skip_task(int $task_id, ?string $reason = null): bool;
    
    /**
     * Bulk skip — used by end-of-week sweeps.
     * Takes a filter array (same format as query_tasks) and skips all matching.
     */
    public function bulk_skip(array $filters, ?string $reason = null): int;
    
    /**
     * Transition a task to 'in_progress' when user opens it.
     * Optional — for tracking who's actively working on what.
     */
    public function start_task(int $task_id, int $user_id): bool;
    
    /**
     * Update a task's payload. Used when parent workflow needs to modify child task details.
     * Does NOT change status.
     */
    public function update_task_payload(int $task_id, array $payload_updates): bool;
}
```

All status transitions log to `meals_audit_log` (existing table) with action='task_transition', actor_id, target_id=task_id, and before/after state in the payload.

The `on_complete` callback receives `(array $task, array $form_data, int $completed_by)` and may return void. It runs **after** the status transition is committed (not in the same transaction), so a callback failure doesn't roll back the completion. Callbacks that spawn child tasks should do so via `MealsDB_Task_Engine::create_task()` — they get their own atomic operation.

### `includes/services/class-task-rules.php`

Rule CRUD and cron-pass logic.

```php
class MealsDB_Task_Rules {
    /**
     * Create a new schedule rule.
     */
    public function create_rule(array $args): int;
    
    /**
     * Update an existing rule. If propagate=true, updates payload of 
     * non-completed tasks spawned from this rule.
     */
    public function update_rule(int $rule_id, array $updates, bool $propagate = false): bool;
    
    /**
     * Delete a rule. Spawned tasks get source_rule_id=NULL (via FK ON DELETE SET NULL)
     * and are preserved.
     */
    public function delete_rule(int $rule_id): bool;
    
    /**
     * Run the cron pass — evaluate all active rules whose next_run_at <= now.
     * For each due rule: spawn task(s), advance next_run_at, set last_run_at.
     * Returns count of tasks created.
     */
    public function run_cron_pass(?DateTimeImmutable $now = null): int;
    
    /**
     * Compute the next occurrence date for a rule's recurrence pattern.
     */
    public function compute_next_run(array $recurrence, DateTimeImmutable $after): ?DateTimeImmutable;
}
```

### Recurrence pattern format

The `recurrence` JSON column stores one of these shapes:

```json
// Daily: every N days at HH:MM
{"type": "daily", "interval": 1, "time": "06:00"}

// Weekly: every N weeks on specified days at HH:MM  
{"type": "weekly", "interval": 1, "days_of_week": ["wednesday"], "time": "06:00"}

// Monthly: every N months on the Nth weekday
{"type": "monthly_weekday", "interval": 1, "nth": 4, "day_of_week": "tuesday", "time": "08:00"}

// Monthly: every N months on day X
{"type": "monthly_day", "interval": 1, "day": 15, "time": "08:00"}

// Custom: every N days from a start date
{"type": "interval_days", "interval": 28, "start_date": "2026-01-06", "time": "08:00"}
```

The `compute_next_run()` method handles all of these. `time` is interpreted in the site timezone; the computed `next_run_at` is stored in UTC.

### Query criteria format (for spawn_type='query')

The `query_criteria` JSON column defines how the rule selects entities to spawn tasks for. For R1, keep it simple — the rule produces a SQL fragment via a named strategy:

```json
{"strategy": "clients_due_this_week", "params": {"contact_method": "phone"}}
```

The strategy name maps to a callable in a strategies registry. Phase R1 ships with one trivial strategy for the proof-of-concept task type; real strategies come in R2.

### Spawn execution

When the cron fires a rule:

**Fixed spawn:**
1. Clone `payload_template` to new task
2. Compute `next_run_date` from rule (typically = date portion of `next_run_at`)
3. Call `MealsDB_Task_Engine::create_task()` with template + inherited tags + inherited assignee_role
4. Log spawn in audit log with source_rule_id

**Query spawn:**
1. Resolve strategy name to callable
2. Callable returns array of rows (each row becomes a task)
3. For each row, merge row data into payload_template via placeholder substitution
4. Create task per row

Placeholder syntax in payload templates: `{{field_name}}` gets replaced with the corresponding value from the row. Example template:
```json
{
  "client_id": "{{wp_user_id}}",
  "client_name": "{{first_name}} {{last_name}}",
  "phone": "{{client_phone_1}}"
}
```

---

## Cron integration

### `includes/class-task-cron.php`

```php
class MealsDB_Task_Cron {
    public static function init(): void {
        add_action('mealsdb_nightly_task_sync', [self::class, 'nightly_sync']);
        if (!wp_next_scheduled('mealsdb_nightly_task_sync')) {
            // Run at 2:00 AM, an hour before the allocation engine cron at 3:00 AM
            wp_schedule_event(strtotime('tomorrow 02:00:00'), 'daily', 'mealsdb_nightly_task_sync');
        }
    }
    
    public static function nightly_sync(): void {
        $rules = new MealsDB_Task_Rules();
        $count = $rules->run_cron_pass();
        error_log(sprintf('[MealsDB Task Engine] Nightly sync created %d tasks.', $count));
    }
}
```

Register in the main plugin file alongside `MealsDB_Allocation_Hooks::init()`.

The rule-update propagation is **not** in the cron. It runs synchronously when a rule is saved through the admin UI (with an explicit "Apply to existing open tasks?" checkbox). This is cleaner than waiting overnight for edits to take effect.

---

## Admin UI

### New "Tasks" top-level tab in `class-admin-ui.php`

Add to the tabs array in `render_main_page()`:
```php
'tasks' => __('Tasks', 'meals-db'),
```

Tab switch case:
```php
case 'tasks':
    $action = $_GET['action'] ?? '';
    if ($action === 'detail') {
        include MealsDB_Plugin::path('views/task-detail.php');
    } elseif ($action === 'rules') {
        include MealsDB_Plugin::path('views/task-rules.php');
    } else {
        include MealsDB_Plugin::path('views/tasks-list.php');
    }
    break;
```

### `views/tasks-list.php`

**Top controls:**
- Date view toggle: Today / This Week / All open
- Filter: Assignee role (all, phone, warehouse, admin)
- Filter: Task type (dropdown from registry)
- Filter: Tags (multi-select)
- Filter: Status (default: pending + deferred; checkboxes to include completed/skipped)
- "Bulk skip" button (requires at least one checkbox selected)
- Link to "Schedule Rules" (`?tab=tasks&action=rules`)

**Main list:**
Grouped by `assignee_role` (if "all" selected) or flat list. Each row:
- Checkbox (for bulk operations)
- Urgency indicator (color-coded dot)
- Task type label
- Related entity (clickable — e.g. "Jane Doe" links to client edit, "PO #447" links to wc_order)
- Due date (highlighted red if < today, yellow if today)
- Status badge
- Deferral count if > 0
- "Open" button → task detail page

**Default sort:** urgency DESC, next_run_date ASC.

**Bulk actions available:** Skip selected, Defer selected by 1 day.

### `views/task-detail.php`

- Task metadata display (type, related entity, due date, status, deferral history from audit log)
- Form rendered from the type's `form_schema`
- Action buttons: "Complete", "Defer to tomorrow", "Defer to date...", "Skip"
- Conditional fields hidden/shown via client-side JS evaluating `show_when`

The form renderer is a shared JS function (put in `assets/js/task-form.js`) that:
1. Takes the schema array and a container element
2. Builds HTML inputs per field
3. Wires up `show_when` conditional visibility
4. Handles AJAX submission to the task engine

### `views/task-rules.php`

List of schedule rules with columns: Name, Task Type, Recurrence (human-readable), Next Run, Last Run, Active (toggle), Actions (Edit / Delete).

"New Rule" button opens a form with:
- Name
- Task type (dropdown from registry)
- Spawn type (fixed/query)
- Recurrence builder (UI that generates the JSON — start with a simple textarea for R1, build a proper UI later)
- Payload template (JSON textarea for R1)
- Query criteria (if query spawn — strategy dropdown + params)
- Tags
- Assignee role

Edit form has an additional "Apply changes to X existing open tasks?" checkbox.

---

## AJAX endpoints

### `includes/ajax/class-ajax-tasks.php`

```php
class MealsDB_Ajax_Tasks {
    public static function init(): void {
        add_action('wp_ajax_mealsdb_tasks_query',         [self::class, 'query_tasks']);
        add_action('wp_ajax_mealsdb_tasks_complete',      [self::class, 'complete_task']);
        add_action('wp_ajax_mealsdb_tasks_defer',         [self::class, 'defer_task']);
        add_action('wp_ajax_mealsdb_tasks_skip',          [self::class, 'skip_task']);
        add_action('wp_ajax_mealsdb_tasks_bulk_skip',     [self::class, 'bulk_skip']);
        add_action('wp_ajax_mealsdb_tasks_bulk_defer',    [self::class, 'bulk_defer']);
        add_action('wp_ajax_mealsdb_tasks_start',         [self::class, 'start_task']);
        add_action('wp_ajax_mealsdb_rules_query',         [self::class, 'query_rules']);
        add_action('wp_ajax_mealsdb_rules_create',        [self::class, 'create_rule']);
        add_action('wp_ajax_mealsdb_rules_update',        [self::class, 'update_rule']);
        add_action('wp_ajax_mealsdb_rules_delete',        [self::class, 'delete_rule']);
        add_action('wp_ajax_mealsdb_rules_run_now',       [self::class, 'run_rule_now']); // Manual trigger for testing
    }
    // ... handler methods with nonce checks and capability gates
}
```

All handlers check nonce (`mealsdb_nonce`) and appropriate capability. Rule editing requires `manage_options`. Task transitions require at least `edit_posts` (or a custom capability if added).

---

## Proof-of-concept task type

To validate the whole chain, ship R1 with one trivial task type: `generic_reminder`. This is a simple "reminder" task with just a notes field and yes/no "done?" confirmation. Not tied to any real workflow — exists purely to prove the engine works end-to-end.

**File:** `includes/task-types/class-task-type-generic-reminder.php`

```php
class MealsDB_Task_Type_Generic_Reminder {
    public static function register(): void {
        MealsDB_Task_Registry::register('generic_reminder', [
            'label'         => 'Reminder',
            'description'   => 'A generic reminder task.',
            'assignee_role' => 'admin',
            'form_schema'   => [
                ['name' => 'description', 'type' => 'textarea', 'label' => 'Description', 'required' => true, 'readonly' => true],
                ['name' => 'done',        'type' => 'yesno', 'label' => 'Completed?', 'required' => true],
                ['name' => 'notes',       'type' => 'textarea', 'label' => 'Notes', 'required' => false],
            ],
            'on_complete'   => [self::class, 'handle_complete'],
        ]);
    }
    
    public static function handle_complete(array $task, array $form_data, int $completed_by): void {
        // Generic reminder has no follow-up behavior — just log.
        error_log(sprintf('[MealsDB Task] Generic reminder %d completed with done=%s', 
            $task['task_id'], $form_data['done'] ?? 'unknown'));
    }
}
```

Registered at plugin init:
```php
add_action('plugins_loaded', function() {
    MealsDB_Task_Type_Generic_Reminder::register();
});
```

This lets you create reminder tasks through the admin UI, complete them, defer them, skip them — validating every engine code path without waiting for the real workflows in R2.

Also ship one trivial fixed-spawn rule as part of install-schema seed data: "Weekly Monday 8am reminder — Review overdue tasks." Payload template is a simple description field. Gives the engine something to do out of the box.

---

## Tests

Following the existing pattern in `tests/`:

- `test-task-engine-create-and-query.php` — CRUD sanity
- `test-task-engine-transitions.php` — state transition validity, audit log entries
- `test-task-engine-bulk-skip.php` — bulk operations
- `test-task-rules-fixed-spawn.php` — fixed spawn creates correct task
- `test-task-rules-query-spawn.php` — query spawn with mock strategy
- `test-task-rules-compute-next-run.php` — recurrence math for all pattern types
- `test-task-rules-update-propagation.php` — rule edit propagates to open tasks
- `test-task-registry.php` — registration, validation, unknown type handling

---

## Migration / install

Schema additions go through the standard install-schema flow. `mealsdb_maybe_upgrade_schema` in the main plugin file will pick up the new tables when the plugin version bumps. No manual migration of data needed — there's no existing task data to migrate.

Seed data on first install:
- One generic reminder rule (weekly) as POC
- Nothing else — R2 will add real rules as part of each workflow directive

---

## What is NOT in R1

Explicitly deferred to R2:
- Real task types: `call_client`, `place_po`, `confirm_po_arrival`, `physical_count`
- `next_order_date` / `next_delivery_date` columns on `meals_clients`
- QuickOrder integration to display/edit those dates
- Logic that updates `next_order_date` on order completion using `ordering_frequency` / `delivery_frequency`
- Query strategies for "clients due to reorder this week"
- Repeat-group form fields (needed by physical_count's per-SKU adjustments)
- PO lifecycle tracking (separate table for POs themselves)
- Dashboard widget summarizing today's tasks
- Email notifications for overdue tasks

R2 will also add an `escalate` action on tasks (move to urgency='escalated'), since that's specific to real workflows.

---

## Key constraints

- External DB via `MealsDB_DB::get_connection()` (mysqli) for both new tables
- Table names via `MealsDB_Tables::SCHEDULE_RULES` and `MealsDB_Tables::TASKS`
- All JSON columns use MySQL native JSON type, queried via `JSON_EXTRACT` where needed
- Timezone handling: `next_run_at` stored UTC in DB, converted to site timezone for display and for recurrence computation
- All status transitions logged to `meals_audit_log`
- Form field `show_when` supports only simple equality; no expressions, no compound conditions
- No repeat groups, no dynamic field addition — all workflows in R1 have static forms
- Follow the allocation engine pattern for event-driven code organization
- Follow the existing security posture: capability checks on every AJAX, nonce verification, no unsanitized input into queries
- CSV output (if any — probably none in R1) must go through `MealsDB_CSV` helper
