<?php
/**
 * AJAX endpoints for the task engine.
 *
 * Nonce-protected (mealsdb_nonce) and capability-gated: task transitions
 * require at least edit_posts; rule editing requires manage_options.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Ajax_Tasks {

    public static function init(): void {
        add_action('wp_ajax_mealsdb_tasks_query',      [self::class, 'query_tasks']);
        add_action('wp_ajax_mealsdb_tasks_get',        [self::class, 'get_task']);
        add_action('wp_ajax_mealsdb_tasks_complete',   [self::class, 'complete_task']);
        add_action('wp_ajax_mealsdb_tasks_defer',      [self::class, 'defer_task']);
        add_action('wp_ajax_mealsdb_tasks_skip',       [self::class, 'skip_task']);
        add_action('wp_ajax_mealsdb_tasks_bulk_skip',  [self::class, 'bulk_skip']);
        add_action('wp_ajax_mealsdb_tasks_bulk_defer', [self::class, 'bulk_defer']);
        add_action('wp_ajax_mealsdb_tasks_start',      [self::class, 'start_task']);
        add_action('wp_ajax_mealsdb_tasks_create',     [self::class, 'create_task']);

        add_action('wp_ajax_mealsdb_rules_query',      [self::class, 'query_rules']);
        add_action('wp_ajax_mealsdb_rules_create',     [self::class, 'create_rule']);
        add_action('wp_ajax_mealsdb_rules_update',     [self::class, 'update_rule']);
        add_action('wp_ajax_mealsdb_rules_delete',     [self::class, 'delete_rule']);
        add_action('wp_ajax_mealsdb_rules_run_now',    [self::class, 'run_rule_now']);
    }

    /**
     * Capability gate for task transitions (open/complete/defer/skip).
     */
    private static function require_task_caps(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'meals-db')], 403);
        }
        // Bucket covers all 15 task endpoints (reads + mutations) at
        // 100/hour — enough headroom for operator polling, tight
        // enough that a buggy loop can't flood. Directive 16 Pass A
        // flagged this whole file as missing rate-limit coverage.
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('task_modify')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }
    }

    /**
     * Capability gate for rule edits (create/update/delete/run-now).
     */
    private static function require_rule_caps(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'meals-db')], 403);
        }
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('task_modify')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }
    }

    // ---------------------------------------------------------------------
    // Task endpoints
    // ---------------------------------------------------------------------

    public static function query_tasks(): void {
        self::require_task_caps();

        $filters = self::read_task_filters();
        $engine = new MealsDB_Task_Engine();
        $rows = $engine->query_tasks($filters);

        wp_send_json_success(['tasks' => $rows]);
    }

    public static function get_task(): void {
        self::require_task_caps();

        $task_id = isset($_REQUEST['task_id']) ? (int) $_REQUEST['task_id'] : 0;
        $engine = new MealsDB_Task_Engine();
        $task = $engine->get_task($task_id);
        if ($task === null) {
            wp_send_json_error(['message' => __('Task not found.', 'meals-db')], 404);
        }

        $definition = MealsDB_Task_Registry::get($task['task_type']);

        wp_send_json_success([
            'task'       => $task,
            'definition' => $definition,
        ]);
    }

    public static function complete_task(): void {
        self::require_task_caps();

        $task_id = isset($_REQUEST['task_id']) ? (int) $_REQUEST['task_id'] : 0;
        $form_data = self::read_json_param('form_data');
        $user_id = get_current_user_id();

        $engine = new MealsDB_Task_Engine();
        $ok = $engine->complete_task($task_id, is_array($form_data) ? $form_data : [], (int) $user_id);
        if (!$ok) {
            wp_send_json_error(['message' => __('Failed to complete task. Check the form fields.', 'meals-db')], 400);
        }

        wp_send_json_success(['task' => $engine->get_task($task_id)]);
    }

    public static function defer_task(): void {
        self::require_task_caps();

        $task_id = isset($_REQUEST['task_id']) ? (int) $_REQUEST['task_id'] : 0;
        $new_date = isset($_REQUEST['new_date']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['new_date'])) : '';
        $reason = isset($_REQUEST['reason']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['reason'])) : null;

        if ($new_date === '') {
            // Default: defer by 1 day from the task's current due date.
            $engine = new MealsDB_Task_Engine();
            $task = $engine->get_task($task_id);
            if ($task === null) {
                wp_send_json_error(['message' => __('Task not found.', 'meals-db')], 404);
            }
            $new_date = self::default_defer_date($task);
            if ($new_date === null) {
                // Unparseable due date — fall back to one day from now.
                $new_date = gmdate('Y-m-d', strtotime('+1 day'));
            }
        }

        $engine = new MealsDB_Task_Engine();
        $ok = $engine->defer_task($task_id, $new_date, $reason);
        if (!$ok) {
            wp_send_json_error(['message' => __('Failed to defer task.', 'meals-db')], 400);
        }
        wp_send_json_success(['task' => $engine->get_task($task_id)]);
    }

    public static function skip_task(): void {
        self::require_task_caps();

        $task_id = isset($_REQUEST['task_id']) ? (int) $_REQUEST['task_id'] : 0;
        $reason = isset($_REQUEST['reason']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['reason'])) : null;

        $engine = new MealsDB_Task_Engine();
        $ok = $engine->skip_task($task_id, $reason);
        if (!$ok) {
            wp_send_json_error(['message' => __('Failed to skip task.', 'meals-db')], 400);
        }
        wp_send_json_success(['task' => $engine->get_task($task_id)]);
    }

    public static function bulk_skip(): void {
        self::require_task_caps();

        $task_ids = self::read_int_array_param('task_ids');
        $reason = isset($_REQUEST['reason']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['reason'])) : null;

        $engine = new MealsDB_Task_Engine();
        $count = 0;
        foreach ($task_ids as $id) {
            if ($engine->skip_task($id, $reason)) {
                $count++;
            }
        }
        wp_send_json_success(['skipped' => $count]);
    }

    public static function bulk_defer(): void {
        self::require_task_caps();

        $task_ids = self::read_int_array_param('task_ids');
        $new_date = isset($_REQUEST['new_date']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['new_date'])) : '';
        $reason = isset($_REQUEST['reason']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['reason'])) : null;

        $engine = new MealsDB_Task_Engine();
        $count = 0;
        foreach ($task_ids as $id) {
            $target_date = $new_date;
            if ($target_date === '') {
                $task = $engine->get_task($id);
                if ($task === null) {
                    continue;
                }
                $target_date = self::default_defer_date($task);
                if ($target_date === null) {
                    // Unparseable due date — skip this task in the bulk sweep.
                    continue;
                }
            }
            if ($engine->defer_task($id, $target_date, $reason)) {
                $count++;
            }
        }
        wp_send_json_success(['deferred' => $count]);
    }

    public static function start_task(): void {
        self::require_task_caps();

        $task_id = isset($_REQUEST['task_id']) ? (int) $_REQUEST['task_id'] : 0;
        $engine = new MealsDB_Task_Engine();
        $ok = $engine->start_task($task_id, (int) get_current_user_id());
        if (!$ok) {
            wp_send_json_error(['message' => __('Failed to start task.', 'meals-db')], 400);
        }
        wp_send_json_success(['task' => $engine->get_task($task_id)]);
    }

    public static function create_task(): void {
        self::require_task_caps();

        $args = [
            'task_type'         => isset($_REQUEST['task_type']) ? sanitize_key(wp_unslash((string) $_REQUEST['task_type'])) : '',
            'next_run_date'     => isset($_REQUEST['next_run_date']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['next_run_date'])) : gmdate('Y-m-d'),
            'payload'           => self::read_json_param('payload') ?: [],
            'tags'              => self::read_json_param('tags') ?: null,
            'assignee_role'     => isset($_REQUEST['assignee_role']) ? sanitize_key(wp_unslash((string) $_REQUEST['assignee_role'])) : null,
            'urgency'           => isset($_REQUEST['urgency']) ? sanitize_key(wp_unslash((string) $_REQUEST['urgency'])) : null,
            'related_entity_type' => isset($_REQUEST['related_entity_type']) ? sanitize_key(wp_unslash((string) $_REQUEST['related_entity_type'])) : null,
            'related_entity_id' => isset($_REQUEST['related_entity_id']) ? (int) $_REQUEST['related_entity_id'] : null,
        ];

        $engine = new MealsDB_Task_Engine();
        $task_id = $engine->create_task($args);
        if ($task_id <= 0) {
            wp_send_json_error(['message' => __('Failed to create task.', 'meals-db')], 400);
        }
        wp_send_json_success(['task_id' => $task_id, 'task' => $engine->get_task($task_id)]);
    }

    // ---------------------------------------------------------------------
    // Rule endpoints
    // ---------------------------------------------------------------------

    public static function query_rules(): void {
        self::require_task_caps();
        $rules = new MealsDB_Task_Rules();
        $rows = $rules->list_rules();
        wp_send_json_success(['rules' => $rows]);
    }

    public static function create_rule(): void {
        self::require_rule_caps();

        $args = [
            'name'             => isset($_REQUEST['name']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['name'])) : '',
            'task_type'        => isset($_REQUEST['task_type']) ? sanitize_key(wp_unslash((string) $_REQUEST['task_type'])) : '',
            'spawn_type'       => isset($_REQUEST['spawn_type']) ? sanitize_key(wp_unslash((string) $_REQUEST['spawn_type'])) : 'fixed',
            'recurrence'       => self::read_json_param('recurrence') ?: [],
            'query_criteria'   => self::read_json_param('query_criteria'),
            'payload_template' => self::read_json_param('payload_template') ?: [],
            'tags'             => self::read_json_param('tags'),
            'assignee_role'    => isset($_REQUEST['assignee_role']) ? sanitize_key(wp_unslash((string) $_REQUEST['assignee_role'])) : null,
            'is_active'        => isset($_REQUEST['is_active']) ? (bool) $_REQUEST['is_active'] : true,
        ];

        $rules = new MealsDB_Task_Rules();
        $rule_id = $rules->create_rule($args);
        if ($rule_id <= 0) {
            wp_send_json_error(['message' => __('Failed to create rule. Check recurrence / payload template.', 'meals-db')], 400);
        }
        wp_send_json_success(['rule_id' => $rule_id, 'rule' => $rules->get_rule($rule_id)]);
    }

    public static function update_rule(): void {
        self::require_rule_caps();

        $rule_id = isset($_REQUEST['rule_id']) ? (int) $_REQUEST['rule_id'] : 0;
        $propagate = !empty($_REQUEST['propagate']);

        $updates = [];
        if (isset($_REQUEST['name'])) {
            $updates['name'] = sanitize_text_field(wp_unslash((string) $_REQUEST['name']));
        }
        if (isset($_REQUEST['task_type'])) {
            $updates['task_type'] = sanitize_key(wp_unslash((string) $_REQUEST['task_type']));
        }
        if (isset($_REQUEST['spawn_type'])) {
            $updates['spawn_type'] = sanitize_key(wp_unslash((string) $_REQUEST['spawn_type']));
        }
        if (isset($_REQUEST['recurrence'])) {
            $updates['recurrence'] = self::read_json_param('recurrence');
        }
        if (isset($_REQUEST['query_criteria'])) {
            $updates['query_criteria'] = self::read_json_param('query_criteria');
        }
        if (isset($_REQUEST['payload_template'])) {
            $updates['payload_template'] = self::read_json_param('payload_template');
        }
        if (isset($_REQUEST['tags'])) {
            $updates['tags'] = self::read_json_param('tags');
        }
        if (isset($_REQUEST['assignee_role'])) {
            $updates['assignee_role'] = sanitize_key(wp_unslash((string) $_REQUEST['assignee_role']));
        }
        if (isset($_REQUEST['is_active'])) {
            $updates['is_active'] = (bool) $_REQUEST['is_active'];
        }

        $rules = new MealsDB_Task_Rules();
        $ok = $rules->update_rule($rule_id, $updates, $propagate);
        if (!$ok) {
            wp_send_json_error(['message' => __('Failed to update rule.', 'meals-db')], 400);
        }
        wp_send_json_success(['rule' => $rules->get_rule($rule_id)]);
    }

    public static function delete_rule(): void {
        self::require_rule_caps();

        $rule_id = isset($_REQUEST['rule_id']) ? (int) $_REQUEST['rule_id'] : 0;
        $rules = new MealsDB_Task_Rules();
        $ok = $rules->delete_rule($rule_id);
        if (!$ok) {
            wp_send_json_error(['message' => __('Failed to delete rule.', 'meals-db')], 400);
        }
        wp_send_json_success(['deleted' => $rule_id]);
    }

    public static function run_rule_now(): void {
        self::require_rule_caps();

        $rule_id = isset($_REQUEST['rule_id']) ? (int) $_REQUEST['rule_id'] : 0;
        $rules = new MealsDB_Task_Rules();
        $count = $rules->run_rule_now($rule_id);
        wp_send_json_success(['created' => $count]);
    }

    // ---------------------------------------------------------------------
    // Input helpers
    // ---------------------------------------------------------------------

    /**
     * Default defer target: the task's current due date (next_run_date) + 1 day,
     * or null when next_run_date can't be parsed. Shared by defer_task (which
     * then falls back to "tomorrow") and bulk_defer (which skips the task) —
     * only the null-handling differs, so it stays at the call sites
     * (U17-tasks-core-6). The successful +1-day computation is identical in both.
     */
    private static function default_defer_date(array $task): ?string {
        try {
            return (new DateTimeImmutable($task['next_run_date']))->modify('+1 day')->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function read_task_filters(): array {
        $filters = [];

        if (isset($_REQUEST['status'])) {
            $status = $_REQUEST['status'];
            if (is_array($status)) {
                $filters['status'] = array_map(static fn($s) => sanitize_key(wp_unslash((string) $s)), $status);
            } else {
                $filters['status'] = [sanitize_key(wp_unslash((string) $status))];
            }
        }
        if (isset($_REQUEST['assignee_role']) && $_REQUEST['assignee_role'] !== '') {
            $filters['assignee_role'] = sanitize_key(wp_unslash((string) $_REQUEST['assignee_role']));
        }
        if (isset($_REQUEST['task_type']) && $_REQUEST['task_type'] !== '') {
            $filters['task_type'] = sanitize_key(wp_unslash((string) $_REQUEST['task_type']));
        }
        if (isset($_REQUEST['urgency']) && $_REQUEST['urgency'] !== '') {
            $filters['urgency'] = sanitize_key(wp_unslash((string) $_REQUEST['urgency']));
        }
        if (isset($_REQUEST['next_run_date_before'])) {
            $filters['next_run_date_before'] = sanitize_text_field(wp_unslash((string) $_REQUEST['next_run_date_before']));
        }
        if (isset($_REQUEST['next_run_date_after'])) {
            $filters['next_run_date_after'] = sanitize_text_field(wp_unslash((string) $_REQUEST['next_run_date_after']));
        }
        if (isset($_REQUEST['tags'])) {
            $tags = $_REQUEST['tags'];
            if (is_string($tags)) {
                $decoded = json_decode(wp_unslash($tags), true);
                $tags = is_array($decoded) ? $decoded : explode(',', $tags);
            }
            if (is_array($tags)) {
                $filters['tags'] = array_map(static fn($t) => sanitize_text_field((string) $t), $tags);
            }
        }
        if (isset($_REQUEST['limit'])) {
            $filters['limit'] = (int) $_REQUEST['limit'];
        }
        if (isset($_REQUEST['offset'])) {
            $filters['offset'] = (int) $_REQUEST['offset'];
        }

        return $filters;
    }

    /**
     * Read a JSON-encoded POST/REQUEST param, or an already-parsed array.
     *
     * @return mixed
     */
    private static function read_json_param(string $key) {
        if (!isset($_REQUEST[$key])) {
            return null;
        }
        $raw = $_REQUEST[$key];
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode(wp_unslash($raw), true);
            if ($decoded !== null) {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * Read an array of ints from REQUEST — accepts either an array or a
     * comma-separated string.
     *
     * @return int[]
     */
    private static function read_int_array_param(string $key): array {
        if (!isset($_REQUEST[$key])) {
            return [];
        }
        $raw = $_REQUEST[$key];
        if (is_string($raw)) {
            $decoded = json_decode(wp_unslash($raw), true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = array_filter(array_map('trim', explode(',', $raw)), static fn($s) => $s !== '');
            }
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $val) {
            $id = (int) $val;
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return $out;
    }
}
