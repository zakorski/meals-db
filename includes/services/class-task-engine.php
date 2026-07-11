<?php
/**
 * Task Engine — Core CRUD and lifecycle service.
 *
 * Persists tasks to meals_tasks and logs every status transition to
 * meals_audit_log. All callbacks registered through MealsDB_Task_Registry
 * fire after the status change is committed so a callback failure cannot
 * roll back the transition.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Engine {

    public const STATUS_PENDING     = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DEFERRED    = 'deferred';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_SKIPPED     = 'skipped';
    public const STATUS_ABANDONED   = 'abandoned';

    public const URGENCY_ROUTINE   = 'routine';
    public const URGENCY_FOLLOW_UP = 'follow_up';
    public const URGENCY_ESCALATED = 'escalated';

    /**
     * Terminal statuses — can't be transitioned out of.
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
        self::STATUS_ABANDONED,
    ];

    /**
     * @var wpdb
     */
    private $wpdb;

    public function __construct($wpdb = null) {
        if ($wpdb === null) {
            global $wpdb;
        }
        $this->wpdb = $wpdb;
    }

    /**
     * Create a new task.
     *
     * @param array<string, mixed> $args
     * @return int task_id, or 0 on failure
     */
    public function create_task(array $args): int {
        $task_type = isset($args['task_type']) ? (string) $args['task_type'] : '';
        if ($task_type === '') {
            error_log('[MealsDB Task Engine] create_task: missing task_type.');
            return 0;
        }

        $payload = isset($args['payload']) && is_array($args['payload']) ? $args['payload'] : [];

        // If the type is registered, validate the payload against its schema,
        // but ONLY when this call looks like it's providing user-facing form
        // data. Rules spawning tasks pre-populate payload from a template
        // that is NOT expected to pass form-schema validation (the operator
        // fills the form in later when completing the task), so we don't
        // block creation on missing required fields.
        if (!empty($args['validate_payload']) && MealsDB_Task_Registry::has($task_type)) {
            $errors = MealsDB_Task_Registry::validate_form_data($task_type, $payload);
            if (!empty($errors)) {
                error_log('[MealsDB Task Engine] create_task validation failed: ' . implode('; ', $errors));
                return 0;
            }
        }

        $next_run_date = isset($args['next_run_date']) ? (string) $args['next_run_date'] : gmdate('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $next_run_date)) {
            error_log('[MealsDB Task Engine] create_task: invalid next_run_date ' . $next_run_date);
            return 0;
        }

        $definition = MealsDB_Task_Registry::get($task_type);

        $assignee_role = $args['assignee_role']
            ?? ($definition['assignee_role'] ?? null);
        $urgency = $args['urgency']
            ?? ($definition['urgency'] ?? self::URGENCY_ROUTINE);

        if (!in_array($urgency, [self::URGENCY_ROUTINE, self::URGENCY_FOLLOW_UP, self::URGENCY_ESCALATED], true)) {
            $urgency = self::URGENCY_ROUTINE;
        }

        $tags = isset($args['tags']) && is_array($args['tags']) ? array_values($args['tags']) : null;

        // Spawn idempotency key (directive MAJ-7). Only rule-spawned tasks
        // pass one; manually-created tasks leave it NULL and are NEVER
        // deduped (the unique index ignores NULLs). A non-empty key makes a
        // duplicate spawn — from an overlapping/re-triggered cron pass, or a
        // crash between spawn and the rule's next_run_at advance — a no-op
        // rejected by the DB rather than a duplicate row.
        $spawn_key = isset($args['spawn_key']) && $args['spawn_key'] !== ''
            ? (string) $args['spawn_key']
            : null;

        $row = [
            'task_type'           => $task_type,
            'status'              => self::STATUS_PENDING,
            'next_run_date'       => $next_run_date,
            'payload'             => self::encode_json($payload),
            'source_rule_id'      => isset($args['source_rule_id']) ? (int) $args['source_rule_id'] : null,
            'parent_task_id'      => isset($args['parent_task_id']) ? (int) $args['parent_task_id'] : null,
            'related_entity_type' => isset($args['related_entity_type']) ? (string) $args['related_entity_type'] : null,
            'related_entity_id'   => isset($args['related_entity_id']) ? (int) $args['related_entity_id'] : null,
            'assignee_role'       => $assignee_role !== null ? (string) $assignee_role : null,
            'urgency'             => $urgency,
            'tags'                => $tags !== null ? self::encode_json($tags) : null,
            'deferral_count'      => 0,
            'spawn_key'           => $spawn_key,
        ];

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::TASKS);

        $result = $this->wpdb->insert($table, $row);
        if ($result === false) {
            // A duplicate spawn_key is the unique index doing its job: the
            // task was already spawned for this (rule, entity, date, type).
            // Treat it as an idempotent success (skip, return 0) — NOT a
            // failure, and crucially NOT an error_log line the cron would
            // count against the run. Any OTHER insert failure is a real error.
            if ($spawn_key !== null && self::is_duplicate_key_error($this->wpdb->last_error)) {
                return 0;
            }
            error_log('[MealsDB Task Engine] create_task insert failed: ' . $this->wpdb->last_error);
            return 0;
        }

        $task_id = (int) $this->wpdb->insert_id;

        $this->log_transition($task_id, null, self::STATUS_PENDING, [
            'task_type'      => $task_type,
            'source_rule_id' => $row['source_rule_id'],
            'parent_task_id' => $row['parent_task_id'],
        ]);

        return $task_id;
    }

    /**
     * Fetch a task by id.
     *
     * @return array<string, mixed>|null
     */
    public function get_task(int $task_id): ?array {
        if ($task_id <= 0) {
            return null;
        }
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::TASKS);
        $sql = $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE task_id = %d", $task_id);
        $row = $this->wpdb->get_row($sql, ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return self::hydrate_row($row);
    }

    /**
     * Query tasks.
     *
     * Supported filters:
     *   status                  : string|string[]
     *   assignee_role           : string|string[]
     *   task_type               : string|string[]
     *   related_entity_type     : string
     *   related_entity_id       : int
     *   next_run_date_before    : YYYY-MM-DD
     *   next_run_date_after     : YYYY-MM-DD
     *   tags                    : string[] (matches any)
     *   urgency                 : string|string[]
     *   source_rule_id          : int
     *   order_by                : whitelisted column (default: urgency DESC, next_run_date ASC)
     *   limit                   : int (default: 500)
     *   offset                  : int (default: 0)
     *
     * @return array<int, array<string, mixed>>
     */
    public function query_tasks(array $filters): array {
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::TASKS);

        $where = [];
        $args = [];

        if (!empty($filters['status'])) {
            $statuses = (array) $filters['status'];
            $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $where[] = "status IN ({$placeholders})";
            foreach ($statuses as $s) { $args[] = (string) $s; }
        }
        if (!empty($filters['assignee_role'])) {
            $roles = (array) $filters['assignee_role'];
            $placeholders = implode(',', array_fill(0, count($roles), '%s'));
            $where[] = "assignee_role IN ({$placeholders})";
            foreach ($roles as $r) { $args[] = (string) $r; }
        }
        if (!empty($filters['task_type'])) {
            $types = (array) $filters['task_type'];
            $placeholders = implode(',', array_fill(0, count($types), '%s'));
            $where[] = "task_type IN ({$placeholders})";
            foreach ($types as $t) { $args[] = (string) $t; }
        }
        if (!empty($filters['urgency'])) {
            $urgencies = (array) $filters['urgency'];
            $placeholders = implode(',', array_fill(0, count($urgencies), '%s'));
            $where[] = "urgency IN ({$placeholders})";
            foreach ($urgencies as $u) { $args[] = (string) $u; }
        }
        if (!empty($filters['related_entity_type'])) {
            $where[] = 'related_entity_type = %s';
            $args[] = (string) $filters['related_entity_type'];
        }
        if (!empty($filters['related_entity_id'])) {
            $where[] = 'related_entity_id = %d';
            $args[] = (int) $filters['related_entity_id'];
        }
        if (!empty($filters['source_rule_id'])) {
            $where[] = 'source_rule_id = %d';
            $args[] = (int) $filters['source_rule_id'];
        }
        if (!empty($filters['next_run_date_before'])) {
            $where[] = 'next_run_date <= %s';
            $args[] = (string) $filters['next_run_date_before'];
        }
        if (!empty($filters['next_run_date_after'])) {
            $where[] = 'next_run_date >= %s';
            $args[] = (string) $filters['next_run_date_after'];
        }
        if (!empty($filters['tags']) && is_array($filters['tags'])) {
            $tag_conditions = [];
            foreach ($filters['tags'] as $tag) {
                $tag_conditions[] = 'JSON_CONTAINS(tags, %s)';
                $args[] = wp_json_encode((string) $tag);
            }
            if (!empty($tag_conditions)) {
                $where[] = '(' . implode(' OR ', $tag_conditions) . ')';
            }
        }

        $where_sql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

        // Safe order_by handling — whitelist the columns.
        $allowed_order_cols = ['next_run_date', 'urgency', 'created_at', 'updated_at', 'status', 'task_id'];
        $order_by = 'FIELD(urgency, \'escalated\', \'follow_up\', \'routine\'), next_run_date ASC, task_id ASC';
        if (!empty($filters['order_by']) && is_string($filters['order_by'])) {
            $parts = preg_split('/\s+/', trim($filters['order_by']));
            $col = $parts[0] ?? '';
            $dir = strtoupper($parts[1] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
            if (in_array($col, $allowed_order_cols, true)) {
                $order_by = sprintf('`%s` %s', $col, $dir);
            }
        }

        $limit  = isset($filters['limit']) ? max(1, min(1000, (int) $filters['limit'])) : 500;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $sql = "SELECT * FROM `{$table}` {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d";
        $args[] = $limit;
        $args[] = $offset;

        $prepared = empty($args) ? $sql : $this->wpdb->prepare($sql, $args);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        return array_map([self::class, 'hydrate_row'], $rows);
    }

    /**
     * Transition a task to 'completed'. Validates form_data against the
     * registered form_schema, writes it into payload, and invokes the
     * on_complete callback AFTER committing the row change.
     */
    public function complete_task(int $task_id, array $form_data, int $completed_by): bool {
        $task = $this->get_task($task_id);
        if ($task === null) {
            return false;
        }
        if (in_array($task['status'], self::TERMINAL_STATUSES, true)) {
            return false;
        }

        $errors = MealsDB_Task_Registry::validate_form_data($task['task_type'], $form_data);
        if (!empty($errors)) {
            error_log('[MealsDB Task Engine] complete_task validation failed for task ' . $task_id . ': ' . implode('; ', $errors));
            return false;
        }

        // Merge form data into the payload (form responses override template data).
        $payload = array_merge(
            is_array($task['payload']) ? $task['payload'] : [],
            $form_data
        );

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::TASKS);
        $result = $this->wpdb->update(
            $table,
            [
                'status'       => self::STATUS_COMPLETED,
                'payload'      => self::encode_json($payload),
                'completed_at' => gmdate('Y-m-d H:i:s'),
                'completed_by' => $completed_by > 0 ? $completed_by : null,
            ],
            ['task_id' => $task_id]
        );

        if ($result === false) {
            error_log('[MealsDB Task Engine] complete_task update failed: ' . $this->wpdb->last_error);
            return false;
        }

        $this->log_transition($task_id, $task['status'], self::STATUS_COMPLETED, [
            'completed_by' => $completed_by,
        ]);

        // Fire the on_complete callback after the row has been updated.
        $definition = MealsDB_Task_Registry::get($task['task_type']);
        if (is_array($definition) && !empty($definition['on_complete']) && is_callable($definition['on_complete'])) {
            $fresh = $this->get_task($task_id);
            if ($fresh !== null) {
                try {
                    call_user_func($definition['on_complete'], $fresh, $form_data, $completed_by);
                } catch (Throwable $e) {
                    error_log('[MealsDB Task Engine] on_complete callback threw for task ' . $task_id . ': ' . $e->getMessage());
                }
            }
        }

        return true;
    }

    /**
     * Defer a task — move the next_run_date forward, increment the deferral
     * count, and set status='deferred'.
     *
     * `$allow_from_terminal=true` lets an on_complete callback reverse its
     * own terminal transition: e.g. po_confirm_arrival deciding post-hoc
     * that an arrived='no' answer should have been a defer. Keep the door
     * narrow — outside that one pattern, defer should refuse terminal tasks.
     */
    public function defer_task(int $task_id, string $new_date, ?string $reason = null, bool $allow_from_terminal = false): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
            error_log('[MealsDB Task Engine] defer_task: invalid date ' . $new_date);
            return false;
        }

        $task = $this->get_task($task_id);
        if ($task === null) {
            return false;
        }
        if (in_array($task['status'], self::TERMINAL_STATUSES, true) && !$allow_from_terminal) {
            return false;
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::TASKS);
        $update_row = [
            'status'         => self::STATUS_DEFERRED,
            'next_run_date'  => $new_date,
            'deferral_count' => (int) $task['deferral_count'] + 1,
        ];
        // When reversing a terminal transition, also clear the completion
        // markers so the task accurately reflects "not yet done".
        if (in_array($task['status'], self::TERMINAL_STATUSES, true)) {
            $update_row['completed_at'] = null;
            $update_row['completed_by'] = null;
        }
        $result = $this->wpdb->update($table, $update_row, ['task_id' => $task_id]);

        if ($result === false) {
            error_log('[MealsDB Task Engine] defer_task update failed: ' . $this->wpdb->last_error);
            return false;
        }

        $this->log_transition($task_id, $task['status'], self::STATUS_DEFERRED, [
            'new_date' => $new_date,
            'reason'   => $reason,
        ]);

        $definition = MealsDB_Task_Registry::get($task['task_type']);
        if (is_array($definition) && !empty($definition['on_defer']) && is_callable($definition['on_defer'])) {
            try {
                call_user_func($definition['on_defer'], $this->get_task($task_id), $new_date, $reason);
            } catch (Throwable $e) {
                error_log('[MealsDB Task Engine] on_defer callback threw for task ' . $task_id . ': ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Transition a task to 'skipped'. Fires on_skip callback.
     */
    public function skip_task(int $task_id, ?string $reason = null): bool {
        $task = $this->get_task($task_id);
        if ($task === null || in_array($task['status'], self::TERMINAL_STATUSES, true)) {
            return false;
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::TASKS);
        $result = $this->wpdb->update(
            $table,
            ['status' => self::STATUS_SKIPPED],
            ['task_id' => $task_id]
        );

        if ($result === false) {
            error_log('[MealsDB Task Engine] skip_task update failed: ' . $this->wpdb->last_error);
            return false;
        }

        $this->log_transition($task_id, $task['status'], self::STATUS_SKIPPED, [
            'reason' => $reason,
        ]);

        $definition = MealsDB_Task_Registry::get($task['task_type']);
        if (is_array($definition) && !empty($definition['on_skip']) && is_callable($definition['on_skip'])) {
            try {
                call_user_func($definition['on_skip'], $this->get_task($task_id), $reason);
            } catch (Throwable $e) {
                error_log('[MealsDB Task Engine] on_skip callback threw for task ' . $task_id . ': ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Bulk skip — apply skip to every task matching a filter set.
     *
     * @return int number of tasks skipped
     */
    public function bulk_skip(array $filters, ?string $reason = null): int {
        // Force the filter to only non-terminal statuses unless the caller
        // explicitly opts in; we never want bulk_skip to re-skip completed
        // work.
        if (empty($filters['status'])) {
            $filters['status'] = [self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_DEFERRED];
        }
        $filters['limit'] = isset($filters['limit']) ? (int) $filters['limit'] : 1000;

        $tasks = $this->query_tasks($filters);
        $count = 0;
        foreach ($tasks as $task) {
            if ($this->skip_task((int) $task['task_id'], $reason)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Mark a task as in_progress — called when the assignee opens it.
     */
    public function start_task(int $task_id, int $user_id): bool {
        $task = $this->get_task($task_id);
        if ($task === null || in_array($task['status'], self::TERMINAL_STATUSES, true)) {
            return false;
        }
        if ($task['status'] === self::STATUS_IN_PROGRESS) {
            return true;
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::TASKS);
        $result = $this->wpdb->update(
            $table,
            ['status' => self::STATUS_IN_PROGRESS],
            ['task_id' => $task_id]
        );

        if ($result === false) {
            return false;
        }

        $this->log_transition($task_id, $task['status'], self::STATUS_IN_PROGRESS, [
            'user_id' => $user_id,
        ]);

        return true;
    }

    /**
     * Merge updates into a task's payload JSON without changing status.
     *
     * @param array<string, mixed> $payload_updates
     */
    public function update_task_payload(int $task_id, array $payload_updates): bool {
        $task = $this->get_task($task_id);
        if ($task === null) {
            return false;
        }

        $merged = array_merge(
            is_array($task['payload']) ? $task['payload'] : [],
            $payload_updates
        );

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::TASKS);
        $result = $this->wpdb->update(
            $table,
            ['payload' => self::encode_json($merged)],
            ['task_id' => $task_id]
        );

        return $result !== false;
    }

    /**
     * Hydrate a raw DB row into the structured shape consumers expect.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function hydrate_row(array $row): array {
        $row['task_id']        = (int) ($row['task_id'] ?? 0);
        $row['source_rule_id'] = isset($row['source_rule_id']) ? (int) $row['source_rule_id'] : null;
        $row['parent_task_id'] = isset($row['parent_task_id']) ? (int) $row['parent_task_id'] : null;
        $row['related_entity_id'] = isset($row['related_entity_id']) ? (int) $row['related_entity_id'] : null;
        $row['completed_by']   = isset($row['completed_by']) ? (int) $row['completed_by'] : null;
        $row['deferral_count'] = (int) ($row['deferral_count'] ?? 0);

        if (isset($row['payload']) && is_string($row['payload'])) {
            $decoded = json_decode($row['payload'], true);
            $row['payload'] = is_array($decoded) ? $decoded : [];
        } elseif (!isset($row['payload']) || !is_array($row['payload'])) {
            $row['payload'] = [];
        }

        if (isset($row['tags']) && is_string($row['tags'])) {
            $decoded = json_decode($row['tags'], true);
            $row['tags'] = is_array($decoded) ? $decoded : null;
        }

        return $row;
    }

    /**
     * Detect a MySQL duplicate-key error from a $wpdb->last_error string.
     *
     * Used by create_task to recognise a rejected duplicate spawn_key
     * (directive MAJ-7) and treat it as an idempotent no-op. Matches on the
     * driver's "Duplicate entry" wording (error 1062) — the same signal both
     * mysqli and the PDO-backed wpdb surface — so we don't misclassify an
     * unrelated insert failure as a dedup hit.
     */
    private static function is_duplicate_key_error(?string $error): bool {
        if ($error === null || $error === '') {
            return false;
        }
        return stripos($error, 'Duplicate entry') !== false
            || stripos($error, '1062') !== false;
    }

    /**
     * Encode a value as JSON for a JSON column.
     *
     * @param mixed $value
     */
    public static function encode_json($value): string {
        if (function_exists('wp_json_encode')) {
            return (string) wp_json_encode($value);
        }
        $out = json_encode($value);
        return $out === false ? '{}' : $out;
    }

    /**
     * Log a task status transition to meals_audit_log.
     *
     * @param int|null             $from_status
     * @param string               $to_status
     * @param array<string, mixed> $context
     */
    private function log_transition(int $task_id, ?string $from_status, string $to_status, array $context = []): void {
        if (!class_exists('MealsDB_Logger')) {
            return;
        }

        $payload = [
            'from'    => $from_status,
            'to'      => $to_status,
            'context' => $context,
        ];

        MealsDB_Logger::log(
            'task_transition',
            $task_id,
            'status',
            $from_status,
            (string) wp_json_encode($payload),
            'mealsdb'
        );
    }
}
