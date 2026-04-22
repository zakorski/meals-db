<?php
/**
 * Task Rules — schedule rule CRUD and cron-pass logic.
 *
 * Handles recurrence computation, spawning tasks from rule templates, and
 * propagating rule edits to already-spawned tasks.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Task_Rules {

    public const SPAWN_FIXED = 'fixed';
    public const SPAWN_QUERY = 'query';

    /**
     * Query strategy callables registered at plugin init.
     *
     * @var array<string, callable>
     */
    private static $strategies = [];

    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * @var MealsDB_Task_Engine
     */
    private $engine;

    public function __construct($wpdb = null, ?MealsDB_Task_Engine $engine = null) {
        if ($wpdb === null) {
            global $wpdb;
        }
        $this->wpdb   = $wpdb;
        $this->engine = $engine ?: new MealsDB_Task_Engine($wpdb);
    }

    /**
     * Register a named query strategy. Strategies receive the rule row and
     * a params array, and return an array of rows (each row spawns one
     * task).
     *
     * @param string   $name
     * @param callable $callback function(array $rule, array $params): array<int, array<string, mixed>>
     */
    public static function register_strategy(string $name, callable $callback): void {
        self::$strategies[$name] = $callback;
    }

    /**
     * @return callable|null
     */
    public static function get_strategy(string $name) {
        return self::$strategies[$name] ?? null;
    }

    /**
     * Clear all registered strategies. Intended for tests only.
     */
    public static function reset_strategies(): void {
        self::$strategies = [];
    }

    /**
     * Create a new schedule rule.
     *
     * @param array<string, mixed> $args
     * @return int rule_id, or 0 on failure
     */
    public function create_rule(array $args): int {
        $name       = isset($args['name']) ? (string) $args['name'] : '';
        $task_type  = isset($args['task_type']) ? (string) $args['task_type'] : '';
        $spawn_type = isset($args['spawn_type']) ? (string) $args['spawn_type'] : self::SPAWN_FIXED;
        $recurrence = isset($args['recurrence']) && is_array($args['recurrence']) ? $args['recurrence'] : null;
        $payload    = isset($args['payload_template']) && is_array($args['payload_template']) ? $args['payload_template'] : null;

        if ($name === '' || $task_type === '' || $recurrence === null || $payload === null) {
            error_log('[MealsDB Task Rules] create_rule: missing required fields.');
            return 0;
        }
        if (!in_array($spawn_type, [self::SPAWN_FIXED, self::SPAWN_QUERY], true)) {
            $spawn_type = self::SPAWN_FIXED;
        }

        $query_criteria = isset($args['query_criteria']) && is_array($args['query_criteria']) ? $args['query_criteria'] : null;
        $tags = isset($args['tags']) && is_array($args['tags']) ? array_values($args['tags']) : null;
        $assignee_role = isset($args['assignee_role']) ? (string) $args['assignee_role'] : null;
        $is_active = array_key_exists('is_active', $args) ? (int) ((bool) $args['is_active']) : 1;

        $next_run_at = null;
        $first_run = $this->compute_next_run($recurrence, self::now());
        if ($first_run instanceof DateTimeImmutable) {
            $next_run_at = $first_run->format('Y-m-d H:i:s');
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::SCHEDULE_RULES);

        $row = [
            'name'             => $name,
            'task_type'        => $task_type,
            'spawn_type'       => $spawn_type,
            'recurrence'       => MealsDB_Task_Engine::encode_json($recurrence),
            'query_criteria'   => $query_criteria !== null ? MealsDB_Task_Engine::encode_json($query_criteria) : null,
            'payload_template' => MealsDB_Task_Engine::encode_json($payload),
            'tags'             => $tags !== null ? MealsDB_Task_Engine::encode_json($tags) : null,
            'assignee_role'    => $assignee_role,
            'is_active'        => $is_active,
            'next_run_at'      => $next_run_at,
        ];

        $result = $this->wpdb->insert($table, $row);
        if ($result === false) {
            error_log('[MealsDB Task Rules] create_rule insert failed: ' . $this->wpdb->last_error);
            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Get a single rule by id.
     *
     * @return array<string, mixed>|null
     */
    public function get_rule(int $rule_id): ?array {
        if ($rule_id <= 0) {
            return null;
        }
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::SCHEDULE_RULES);
        $sql = $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE rule_id = %d", $rule_id);
        $row = $this->wpdb->get_row($sql, ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return self::hydrate_rule_row($row);
    }

    /**
     * List all rules, optionally filtered by active status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_rules(?bool $only_active = null): array {
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::SCHEDULE_RULES);
        $sql   = "SELECT * FROM `{$table}`";
        $args  = [];
        if ($only_active === true) {
            $sql .= ' WHERE is_active = %d';
            $args[] = 1;
        } elseif ($only_active === false) {
            $sql .= ' WHERE is_active = %d';
            $args[] = 0;
        }
        $sql .= ' ORDER BY task_type ASC, name ASC';
        $prepared = empty($args) ? $sql : $this->wpdb->prepare($sql, $args);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        return array_map([self::class, 'hydrate_rule_row'], $rows);
    }

    /**
     * Update an existing rule. When $propagate is true, the payload_template,
     * assignee_role, tags, and urgency inherited by non-terminal tasks
     * spawned from this rule get updated in place.
     */
    public function update_rule(int $rule_id, array $updates, bool $propagate = false): bool {
        $existing = $this->get_rule($rule_id);
        if ($existing === null) {
            return false;
        }

        $row = [];
        $scalar_fields = ['name', 'task_type', 'spawn_type', 'assignee_role'];
        foreach ($scalar_fields as $field) {
            if (array_key_exists($field, $updates)) {
                $row[$field] = $updates[$field] !== null ? (string) $updates[$field] : null;
            }
        }
        $json_fields = ['recurrence', 'query_criteria', 'payload_template', 'tags'];
        foreach ($json_fields as $field) {
            if (array_key_exists($field, $updates)) {
                $row[$field] = $updates[$field] === null
                    ? null
                    : MealsDB_Task_Engine::encode_json($updates[$field]);
            }
        }
        if (array_key_exists('is_active', $updates)) {
            $row['is_active'] = (int) ((bool) $updates['is_active']);
        }

        // If recurrence changed, recompute next_run_at from now.
        if (array_key_exists('recurrence', $updates)) {
            $recurrence = is_array($updates['recurrence']) ? $updates['recurrence'] : $existing['recurrence'];
            $next = $this->compute_next_run($recurrence, self::now());
            $row['next_run_at'] = $next instanceof DateTimeImmutable ? $next->format('Y-m-d H:i:s') : null;
        }

        if (empty($row)) {
            return true;
        }

        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::SCHEDULE_RULES);
        $result = $this->wpdb->update($table, $row, ['rule_id' => $rule_id]);
        if ($result === false) {
            error_log('[MealsDB Task Rules] update_rule failed: ' . $this->wpdb->last_error);
            return false;
        }

        if ($propagate) {
            $this->propagate_to_open_tasks($rule_id, $updates);
        }

        return true;
    }

    /**
     * Delete a rule. Child tasks' source_rule_id goes to NULL via FK.
     */
    public function delete_rule(int $rule_id): bool {
        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::SCHEDULE_RULES);
        $result = $this->wpdb->delete($table, ['rule_id' => $rule_id]);
        return $result !== false;
    }

    /**
     * Propagate a rule-edit to non-terminal tasks spawned from the rule.
     */
    public function propagate_to_open_tasks(int $rule_id, array $updates): int {
        $tasks_table = MealsDB_DB::get_table_name(MealsDB_Tables::TASKS);

        $terminal = MealsDB_Task_Engine::TERMINAL_STATUSES;
        $placeholders = implode(',', array_fill(0, count($terminal), '%s'));
        $sql = "SELECT task_id, payload FROM `{$tasks_table}` WHERE source_rule_id = %d AND status NOT IN ({$placeholders})";
        $args = array_merge([$rule_id], $terminal);
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $args), ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $patch = [];
            if (array_key_exists('assignee_role', $updates)) {
                $patch['assignee_role'] = $updates['assignee_role'] !== null ? (string) $updates['assignee_role'] : null;
            }
            if (array_key_exists('tags', $updates)) {
                $patch['tags'] = is_array($updates['tags'])
                    ? MealsDB_Task_Engine::encode_json(array_values($updates['tags']))
                    : null;
            }

            if (array_key_exists('payload_template', $updates) && is_array($updates['payload_template'])) {
                $existing_payload = json_decode((string) $row['payload'], true);
                if (!is_array($existing_payload)) {
                    $existing_payload = [];
                }
                // Template keys overwrite existing payload keys.
                $merged = array_merge($existing_payload, $updates['payload_template']);
                $patch['payload'] = MealsDB_Task_Engine::encode_json($merged);
            }

            if (empty($patch)) {
                continue;
            }

            $result = $this->wpdb->update($tasks_table, $patch, ['task_id' => (int) $row['task_id']]);
            if ($result !== false) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Run the cron pass — spawn any due rules' tasks.
     *
     * @return int number of tasks created
     */
    public function run_cron_pass(?DateTimeImmutable $now = null): int {
        if ($now === null) {
            $now = self::now();
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::SCHEDULE_RULES);
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE is_active = 1 AND (next_run_at IS NOT NULL AND next_run_at <= %s) ORDER BY next_run_at ASC",
            $now->format('Y-m-d H:i:s')
        );
        $rules = $this->wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rules) || empty($rules)) {
            return 0;
        }

        $created = 0;
        foreach ($rules as $raw) {
            $rule = self::hydrate_rule_row($raw);
            $created += $this->spawn_from_rule($rule);

            // Advance next_run_at past the run point, then record last_run_at.
            $after = new DateTimeImmutable($rule['next_run_at'], new DateTimeZone('UTC'));
            $next  = $this->compute_next_run($rule['recurrence'], $after);
            $update = [
                'last_run_at' => $now->format('Y-m-d H:i:s'),
                'next_run_at' => $next instanceof DateTimeImmutable ? $next->format('Y-m-d H:i:s') : null,
            ];
            $this->wpdb->update($table, $update, ['rule_id' => (int) $rule['rule_id']]);
        }

        return $created;
    }

    /**
     * Force-run a single rule immediately (used by admin "Run Now" button).
     *
     * @return int number of tasks created
     */
    public function run_rule_now(int $rule_id): int {
        $rule = $this->get_rule($rule_id);
        if ($rule === null) {
            return 0;
        }

        $created = $this->spawn_from_rule($rule);

        $now = self::now();
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::SCHEDULE_RULES);
        $this->wpdb->update($table, ['last_run_at' => $now->format('Y-m-d H:i:s')], ['rule_id' => $rule_id]);

        return $created;
    }

    /**
     * Spawn one or more tasks from a rule row.
     *
     * @param array<string, mixed> $rule (hydrated)
     * @return int number of tasks created
     */
    public function spawn_from_rule(array $rule): int {
        $task_type = (string) ($rule['task_type'] ?? '');
        if ($task_type === '') {
            return 0;
        }

        $template = is_array($rule['payload_template']) ? $rule['payload_template'] : [];
        $tags     = is_array($rule['tags'] ?? null) ? $rule['tags'] : null;
        $role     = $rule['assignee_role'] !== null ? (string) $rule['assignee_role'] : null;

        $next_run_date = isset($rule['next_run_at'])
            ? substr((string) $rule['next_run_at'], 0, 10)
            : gmdate('Y-m-d');

        $created = 0;

        if ($rule['spawn_type'] === self::SPAWN_FIXED) {
            $task_id = $this->engine->create_task([
                'task_type'      => $task_type,
                'payload'        => $template,
                'next_run_date'  => $next_run_date,
                'source_rule_id' => (int) $rule['rule_id'],
                'assignee_role'  => $role,
                'tags'           => $tags,
            ]);
            if ($task_id > 0) {
                $created++;
            }
            return $created;
        }

        // Query spawn.
        $criteria = is_array($rule['query_criteria'] ?? null) ? $rule['query_criteria'] : [];
        $strategy_name = isset($criteria['strategy']) ? (string) $criteria['strategy'] : '';
        $params = isset($criteria['params']) && is_array($criteria['params']) ? $criteria['params'] : [];

        $callback = self::get_strategy($strategy_name);
        if ($strategy_name === '' || !is_callable($callback)) {
            error_log(sprintf('[MealsDB Task Rules] Rule %d: unknown strategy "%s"', $rule['rule_id'], $strategy_name));
            return 0;
        }

        $rows = call_user_func($callback, $rule, $params);
        if (!is_array($rows)) {
            return 0;
        }

        foreach ($rows as $data_row) {
            if (!is_array($data_row)) {
                continue;
            }
            $payload = self::apply_placeholders($template, $data_row);

            // Per-row overrides for related entity, assignee, etc.
            $task_id = $this->engine->create_task([
                'task_type'           => $task_type,
                'payload'             => $payload,
                'next_run_date'       => $next_run_date,
                'source_rule_id'      => (int) $rule['rule_id'],
                'assignee_role'       => $role,
                'tags'                => $tags,
                'related_entity_type' => isset($data_row['__related_entity_type']) ? (string) $data_row['__related_entity_type'] : null,
                'related_entity_id'   => isset($data_row['__related_entity_id']) ? (int) $data_row['__related_entity_id'] : null,
            ]);
            if ($task_id > 0) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Apply {{field}} placeholder substitution recursively to a payload
     * template using values from a data row.
     *
     * @param mixed                $template
     * @param array<string, mixed> $data_row
     * @return mixed
     */
    public static function apply_placeholders($template, array $data_row) {
        if (is_string($template)) {
            return preg_replace_callback(
                '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
                static function ($m) use ($data_row) {
                    $key = $m[1];
                    if (!array_key_exists($key, $data_row)) {
                        return '';
                    }
                    $value = $data_row[$key];
                    return is_scalar($value) ? (string) $value : '';
                },
                $template
            );
        }
        if (is_array($template)) {
            $out = [];
            foreach ($template as $k => $v) {
                $out[$k] = self::apply_placeholders($v, $data_row);
            }
            return $out;
        }
        return $template;
    }

    /**
     * Compute the next occurrence date for a recurrence pattern.
     *
     * Returns a DateTimeImmutable in UTC, or null when the pattern is
     * malformed.
     *
     * @param array<string, mixed> $recurrence
     */
    public function compute_next_run(array $recurrence, DateTimeImmutable $after): ?DateTimeImmutable {
        $type = isset($recurrence['type']) ? (string) $recurrence['type'] : '';
        $time = isset($recurrence['time']) && is_string($recurrence['time']) ? $recurrence['time'] : '00:00';
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            $hour = 0; $min = 0;
        } else {
            $hour = min(23, (int) $m[1]);
            $min  = min(59, (int) $m[2]);
        }

        $tz = self::site_timezone();
        // Normalize comparison anchor to site timezone.
        $after_local = $after->setTimezone($tz);

        $candidate = null;

        switch ($type) {
            case 'daily':
                $interval = max(1, (int) ($recurrence['interval'] ?? 1));
                $candidate = $after_local->setTime($hour, $min, 0);
                if ($candidate <= $after_local) {
                    $candidate = $candidate->modify(sprintf('+%d day', $interval));
                }
                break;

            case 'weekly':
                $interval = max(1, (int) ($recurrence['interval'] ?? 1));
                $days = isset($recurrence['days_of_week']) && is_array($recurrence['days_of_week'])
                    ? array_values(array_filter(array_map([self::class, 'dow_to_index'], $recurrence['days_of_week']), static fn($v) => $v !== null))
                    : [];
                if (empty($days)) {
                    return null;
                }
                // Search up to (interval*7 + 7) days ahead to find the next matching weekday.
                $search_start = $after_local->setTime($hour, $min, 0);
                for ($i = 0; $i <= ($interval * 7 + 7); $i++) {
                    $candidate_try = $search_start->modify(sprintf('+%d day', $i));
                    $dow = (int) $candidate_try->format('w');
                    if (!in_array($dow, $days, true)) {
                        continue;
                    }
                    if ($candidate_try > $after_local) {
                        $candidate = $candidate_try;
                        break;
                    }
                }
                break;

            case 'monthly_day':
                $interval = max(1, (int) ($recurrence['interval'] ?? 1));
                $day = max(1, min(31, (int) ($recurrence['day'] ?? 1)));
                $cursor = $after_local->modify('first day of this month')->setTime($hour, $min, 0);
                for ($i = 0; $i < 48; $i++) {
                    $year = (int) $cursor->format('Y');
                    $month = (int) $cursor->format('n');
                    $days_in_month = (int) $cursor->format('t');
                    $target_day = min($day, $days_in_month);
                    try {
                        $candidate_try = $cursor->setDate($year, $month, $target_day)->setTime($hour, $min, 0);
                    } catch (Throwable $e) {
                        $candidate_try = null;
                    }
                    if ($candidate_try instanceof DateTimeImmutable && $candidate_try > $after_local) {
                        $candidate = $candidate_try;
                        break;
                    }
                    $cursor = $cursor->modify(sprintf('+%d month', $interval));
                }
                break;

            case 'monthly_weekday':
                $interval = max(1, (int) ($recurrence['interval'] ?? 1));
                $nth = max(1, min(5, (int) ($recurrence['nth'] ?? 1)));
                $dow = self::dow_to_index($recurrence['day_of_week'] ?? '');
                if ($dow === null) {
                    return null;
                }
                $dow_name = self::dow_index_to_name($dow);
                $cursor = $after_local->modify('first day of this month')->setTime($hour, $min, 0);
                for ($i = 0; $i < 48; $i++) {
                    $first_of_month = $cursor->modify('first day of this month')->setTime($hour, $min, 0);
                    $spec = sprintf('first %s of %s', $dow_name, $first_of_month->format('F Y'));
                    try {
                        $first_dow = new DateTimeImmutable($spec, $tz);
                        $first_dow = $first_dow->setTime($hour, $min, 0);
                    } catch (Throwable $e) {
                        break;
                    }
                    $candidate_try = $first_dow->modify(sprintf('+%d weeks', $nth - 1));
                    if ((int) $candidate_try->format('n') !== (int) $first_of_month->format('n')) {
                        // Month doesn't have a 5th occurrence of this weekday — skip.
                        $cursor = $cursor->modify(sprintf('+%d month', $interval));
                        continue;
                    }
                    if ($candidate_try > $after_local) {
                        $candidate = $candidate_try;
                        break;
                    }
                    $cursor = $cursor->modify(sprintf('+%d month', $interval));
                }
                break;

            case 'interval_days':
                $interval = max(1, (int) ($recurrence['interval'] ?? 1));
                $start_str = isset($recurrence['start_date']) ? (string) $recurrence['start_date'] : '';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_str)) {
                    return null;
                }
                try {
                    $start = (new DateTimeImmutable($start_str, $tz))->setTime($hour, $min, 0);
                } catch (Throwable $e) {
                    return null;
                }
                if ($start > $after_local) {
                    $candidate = $start;
                    break;
                }
                $diff_days = (int) $after_local->diff($start)->days;
                $cycles = (int) floor($diff_days / $interval) + 1;
                $candidate = $start->modify(sprintf('+%d day', $cycles * $interval));
                while ($candidate <= $after_local) {
                    $candidate = $candidate->modify(sprintf('+%d day', $interval));
                }
                break;

            default:
                return null;
        }

        if (!$candidate instanceof DateTimeImmutable) {
            return null;
        }

        return $candidate->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Hydrate a rule row — decode JSON columns and cast ints.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function hydrate_rule_row(array $row): array {
        $row['rule_id']   = (int) ($row['rule_id'] ?? 0);
        $row['is_active'] = (int) ($row['is_active'] ?? 0);

        foreach (['recurrence', 'query_criteria', 'payload_template', 'tags'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $decoded = json_decode($row[$field], true);
                $row[$field] = $decoded !== null ? $decoded : null;
            }
        }

        return $row;
    }

    /**
     * Current time as DateTimeImmutable in UTC.
     */
    public static function now(): DateTimeImmutable {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Resolve the site timezone, falling back to UTC when WordPress
     * helpers aren't available (test harness).
     */
    public static function site_timezone(): DateTimeZone {
        if (function_exists('wp_timezone')) {
            $tz = wp_timezone();
            if ($tz instanceof DateTimeZone) {
                return $tz;
            }
        }
        $tz_string = function_exists('get_option') ? (string) get_option('timezone_string') : '';
        if ($tz_string !== '') {
            try {
                return new DateTimeZone($tz_string);
            } catch (Throwable $e) {
                // fall through
            }
        }
        return new DateTimeZone('UTC');
    }

    /**
     * Convert a day-of-week name to its numeric index (0=Sunday..6=Saturday).
     */
    public static function dow_to_index($name): ?int {
        if (!is_string($name)) {
            return null;
        }
        $map = [
            'sunday'    => 0,
            'monday'    => 1,
            'tuesday'   => 2,
            'wednesday' => 3,
            'thursday'  => 4,
            'friday'    => 5,
            'saturday'  => 6,
        ];
        $key = strtolower(trim($name));
        return $map[$key] ?? null;
    }

    public static function dow_index_to_name(int $index): string {
        $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return $names[$index] ?? 'Sunday';
    }
}
