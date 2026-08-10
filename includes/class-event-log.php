<?php
/**
 * Central operational event-log trunk (directive STR-LOG, Option A).
 *
 * This is the single write path for OPERATIONAL events — job runs, hook
 * fires, swallowed exceptions, degraded outcomes. It collapses the former
 * MealsDB_Job_Logger + MealsDB_Hook_Logger tables into one
 * `meals_event_log` table. Those two classes are now thin facades over
 * this one (their public signatures are unchanged, so the ~53 existing
 * writer call sites did not have to change).
 *
 * HARD BOUNDARY: this is NOT the audit log. MealsDB_Logger::log() →
 * meals_audit_log remains a separate, append-only compliance artifact
 * with long retention and PII fingerprinting. Rule of thumb: an
 * attempt/outcome → trunk (this class); a committed change to a data
 * record → audit log. See CLAUDE.md §6.
 *
 * Inherited disciplines (regressing any is a bug — directive §"disciplines"):
 *   1. PII scrub at WRITE time (message via sanitize_for_log, context via
 *      a recursive scrubber). Never at display time.
 *   2. UTC everywhere (gmdate).
 *   3. Context cap: 16KB serialized, depth 10.
 *   4. Fail-safe writes: record() wraps its own INSERT in try/catch; on
 *      failure it falls back to error_log() and returns. A logging
 *      failure must NEVER escalate into the failure of the thing being
 *      logged (checkout, cron, AJAX).
 *   5. Retention-aware (handled by MealsDB_Log_Retention against this
 *      table; the `running` outcome is never pruned — hang detection).
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Event_Log {

    /** Outcome vocabulary. `degraded` = "continued, but swallowed a problem." */
    public const OUTCOME_SUCCEEDED = 'succeeded';
    public const OUTCOME_FAILED    = 'failed';
    public const OUTCOME_DEGRADED  = 'degraded';
    public const OUTCOME_RUNNING   = 'running';

    private const OUTCOMES = [
        self::OUTCOME_SUCCEEDED,
        self::OUTCOME_FAILED,
        self::OUTCOME_DEGRADED,
        self::OUTCOME_RUNNING,
    ];

    private const SEVERITIES = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];

    private const CONTEXT_MAX_BYTES = 16384;
    private const CONTEXT_MAX_DEPTH = 10;

    /**
     * Cache the per-row start time so finish_job() / fail_job() can compute
     * duration without a SELECT. Keyed by log_id → unix seconds.
     *
     * @var array<int, int>
     */
    private static $started_unix = [];

    // ---------------------------------------------------------------------
    //  Write path
    // ---------------------------------------------------------------------

    /**
     * Record one event into the trunk.
     *
     * Recognised keys: severity, category, subsystem, event, outcome,
     * message, context (array), entity_type, entity_id, correlation_id,
     * user_id, and the job-lifecycle columns started_at / completed_at /
     * duration_seconds / records_processed / records_updated /
     * records_skipped / records_errored.
     *
     * @return int log_id, or 0 on failure (never throws).
     */
    public static function record(array $e): int {
        try {
            global $wpdb;

            if (!class_exists('MealsDB_DB') || !is_object($wpdb)) {
                return 0;
            }

            $table = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);

            $category = isset($e['category']) ? substr((string) $e['category'], 0, 50) : 'general';
            $event    = isset($e['event']) ? substr((string) $e['event'], 0, 150) : 'unspecified';

            $severity = isset($e['severity']) && in_array($e['severity'], self::SEVERITIES, true)
                ? (string) $e['severity']
                : 'info';
            // Outcome: an ABSENT outcome defaults to succeeded (most info events
            // don't set one). But a PRESENT-but-unrecognised outcome is a caller
            // typo (e.g. 'fail') — default it to DEGRADED so it surfaces on the
            // dashboard/digest (failed|degraded), rather than silently 'succeeded'
            // which would hide a real problem.
            if (!isset($e['outcome']) || $e['outcome'] === '') {
                $outcome = self::OUTCOME_SUCCEEDED;
            } elseif (in_array($e['outcome'], self::OUTCOMES, true)) {
                $outcome = (string) $e['outcome'];
            } else {
                $outcome = self::OUTCOME_DEGRADED;
            }

            // Discipline 2: UTC. occurred_at defaults to now; callers may
            // override (e.g. backfills) but everything is gmdate-shaped.
            $occurred_at = isset($e['occurred_at']) && $e['occurred_at'] !== ''
                ? (string) $e['occurred_at']
                : gmdate('Y-m-d H:i:s');

            $data    = [
                'occurred_at' => $occurred_at,
                'severity'    => $severity,
                'category'    => $category,
                'event'       => $event,
                'outcome'     => $outcome,
            ];
            $formats = ['%s', '%s', '%s', '%s', '%s'];

            if (isset($e['subsystem']) && $e['subsystem'] !== '') {
                $data['subsystem'] = substr((string) $e['subsystem'], 0, 100);
                $formats[] = '%s';
            }

            // Discipline 1: PII scrub the human message at write time.
            if (isset($e['message']) && $e['message'] !== '') {
                $data['message'] = class_exists('MealsDB_Logger')
                    ? MealsDB_Logger::sanitize_for_log((string) $e['message'])
                    : (string) $e['message'];
                $formats[] = '%s';
            }

            // Discipline 1 + 3: scrub + cap the structured context.
            if (isset($e['context']) && is_array($e['context']) && !empty($e['context'])) {
                $data['context'] = self::encode_context($e['context']);
                $formats[] = '%s';
            }

            if (isset($e['entity_type']) && $e['entity_type'] !== '') {
                $data['entity_type'] = substr((string) $e['entity_type'], 0, 30);
                $formats[] = '%s';
            }
            if (isset($e['entity_id']) && (int) $e['entity_id'] > 0) {
                $data['entity_id'] = (int) $e['entity_id'];
                $formats[] = '%d';
            }
            if (isset($e['correlation_id']) && $e['correlation_id'] !== '') {
                $data['correlation_id'] = substr((string) $e['correlation_id'], 0, 40);
                $formats[] = '%s';
            }

            // user_id: explicit value wins; else current user when available.
            if (array_key_exists('user_id', $e)) {
                if ((int) $e['user_id'] > 0) {
                    $data['user_id'] = (int) $e['user_id'];
                    $formats[] = '%d';
                }
            } elseif (function_exists('get_current_user_id')) {
                $uid = (int) get_current_user_id();
                if ($uid > 0) {
                    $data['user_id'] = $uid;
                    $formats[] = '%d';
                }
            }

            // Job-lifecycle columns (string dates / unsigned ints).
            foreach (['started_at' => '%s', 'completed_at' => '%s'] as $col => $fmt) {
                if (isset($e[$col]) && $e[$col] !== '') {
                    $data[$col] = (string) $e[$col];
                    $formats[]  = $fmt;
                }
            }
            foreach (['duration_seconds', 'records_processed', 'records_updated', 'records_skipped', 'records_errored'] as $col) {
                if (isset($e[$col]) && $e[$col] !== null) {
                    $data[$col] = max(0, (int) $e[$col]);
                    $formats[]  = '%d';
                }
            }

            // Discipline 4: fail-safe. A failed INSERT is logged and
            // swallowed — it must not break the thing being logged.
            $result = $wpdb->insert($table, $data, $formats);
            if ($result === false) {
                self::fallback_error('insert failed for ' . $category . '/' . $event . ': ' . $wpdb->last_error);
                return 0;
            }
            return (int) $wpdb->insert_id;
        } catch (\Throwable $t) {
            self::fallback_error('record() threw: ' . $t->getMessage());
            return 0;
        }
    }

    // ---------------------------------------------------------------------
    //  Job-lifecycle helpers (map onto the same table)
    // ---------------------------------------------------------------------

    /**
     * Begin a job run. INSERTs a category='job', outcome='running' row and
     * returns its log_id so finish_job()/fail_job() can UPDATE it.
     */
    public static function start_job(string $event, array $context = [], array $extra = []): int {
        $now = time();
        $row = array_merge([
            'category'   => 'job',
            'event'      => $event,
            'outcome'    => self::OUTCOME_RUNNING,
            'severity'   => 'info',
            'occurred_at'=> gmdate('Y-m-d H:i:s', $now),
            'started_at' => gmdate('Y-m-d H:i:s', $now),
            'context'    => $context,
        ], $extra);

        $log_id = self::record($row);
        if ($log_id > 0) {
            self::$started_unix[$log_id] = $now;
        }
        return $log_id;
    }

    /**
     * Mark a job row complete. $outcome lets a caller record 'degraded'
     * (finished but swallowed a problem) instead of a clean 'succeeded'.
     */
    public static function finish_job(int $log_id, array $stats = [], string $outcome = self::OUTCOME_SUCCEEDED): void {
        if ($log_id <= 0) {
            return;
        }
        if (!in_array($outcome, [self::OUTCOME_SUCCEEDED, self::OUTCOME_DEGRADED], true)) {
            $outcome = self::OUTCOME_SUCCEEDED;
        }
        self::update_job_row($log_id, $outcome, null, $stats, $outcome === self::OUTCOME_DEGRADED ? 'warning' : null);
    }

    /**
     * Mark a job row failed. Also surfaces the failure on the PHP error
     * log (scrubbed) so a sysadmin watching error_log sees it immediately.
     */
    public static function fail_job(int $log_id, string $error_message, array $stats = []): void {
        if ($log_id <= 0) {
            self::fallback_error('fail_job() called with invalid log_id: ' . $error_message);
            return;
        }
        self::update_job_row($log_id, self::OUTCOME_FAILED, $error_message, $stats, 'error');

        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error(sprintf('[Event Log] Job failed (log_id=%d): %s', $log_id, $error_message));
        }
    }

    /**
     * Update record_* counters mid-batch without changing outcome — lets
     * the daily report distinguish a hung job from one making progress.
     */
    public static function heartbeat(int $log_id, array $stats): void {
        if ($log_id <= 0) {
            return;
        }
        try {
            global $wpdb;
            $update = self::stats_to_columns($stats);
            if (empty($update)) {
                return;
            }
            $table   = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
            $formats = array_fill(0, count($update), '%d');
            $wpdb->update($table, $update, ['log_id' => $log_id], $formats, ['%d']);
        } catch (\Throwable $t) {
            self::fallback_error('heartbeat() threw: ' . $t->getMessage());
        }
    }

    /**
     * Shared UPDATE for finish_job()/fail_job().
     */
    private static function update_job_row(int $log_id, string $outcome, ?string $error_message, array $stats, ?string $severity): void {
        try {
            global $wpdb;

            $table     = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
            $now       = time();
            $completed = gmdate('Y-m-d H:i:s', $now);
            $started   = self::$started_unix[$log_id] ?? null;
            $duration  = $started !== null ? max(0, $now - $started) : null;

            $update  = ['outcome' => $outcome, 'completed_at' => $completed];
            $formats = ['%s', '%s'];

            if ($severity !== null) {
                $update['severity'] = $severity;
                $formats[] = '%s';
            }
            if ($duration !== null) {
                $update['duration_seconds'] = $duration;
                $formats[] = '%d';
            }
            foreach (self::stats_to_columns($stats) as $column => $value) {
                $update[$column] = $value;
                $formats[] = '%d';
            }
            if ($error_message !== null) {
                $update['message'] = class_exists('MealsDB_Logger')
                    ? MealsDB_Logger::sanitize_for_log($error_message)
                    : $error_message;
                $formats[] = '%s';
            }

            // Non-counter stats are folded into the context blob rather
            // than dropped (merging with the start() context).
            $extra = self::extract_extra_context($stats);
            if ($extra !== null) {
                $update['context'] = self::encode_context($extra, $log_id);
                $formats[] = '%s';
            }

            $wpdb->update($table, $update, ['log_id' => $log_id], $formats, ['%d']);
            unset(self::$started_unix[$log_id]);
        } catch (\Throwable $t) {
            self::fallback_error('update_job_row() threw: ' . $t->getMessage());
        }
    }

    // ---------------------------------------------------------------------
    //  Generic query helper (used by the dashboard + facade readers)
    // ---------------------------------------------------------------------

    /**
     * Run a filtered SELECT against the trunk. All values are bound via
     * $wpdb->prepare; column/keyword positions are hardcoded. Returns
     * ARRAY_A rows newest-first.
     *
     * Recognised filters: category, event, subsystem, severity, outcome
     * (string or string[]), entity_type, entity_id, correlation_id,
     * since (occurred_at >=), until (occurred_at <), search (LIKE on
     * event+message), limit, offset.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function query(array $filters = []): array {
        global $wpdb;

        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
        $where  = [];
        $params = [];

        $eq = static function (string $col, $val) use (&$where, &$params): void {
            $where[]  = "`{$col}` = %s";
            $params[] = (string) $val;
        };

        foreach (['category', 'event', 'subsystem', 'severity', 'entity_type', 'correlation_id'] as $col) {
            if (isset($filters[$col]) && $filters[$col] !== '') {
                $eq($col, $filters[$col]);
            }
        }
        if (isset($filters['entity_id']) && (int) $filters['entity_id'] > 0) {
            $where[]  = '`entity_id` = %d';
            $params[] = (int) $filters['entity_id'];
        }
        if (isset($filters['outcome']) && $filters['outcome'] !== '') {
            $outcomes = (array) $filters['outcome'];
            $outcomes = array_values(array_filter($outcomes, static function ($o) {
                return in_array($o, self::OUTCOMES, true);
            }));
            if (!empty($outcomes)) {
                $where[] = '`outcome` IN (' . implode(',', array_fill(0, count($outcomes), '%s')) . ')';
                foreach ($outcomes as $o) {
                    $params[] = $o;
                }
            }
        }
        if (isset($filters['since']) && $filters['since'] !== '') {
            $where[]  = '`occurred_at` >= %s';
            $params[] = (string) $filters['since'];
        }
        if (isset($filters['until']) && $filters['until'] !== '') {
            $where[]  = '`occurred_at` < %s';
            $params[] = (string) $filters['until'];
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $like     = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
            $where[]  = '(`event` LIKE %s OR `message` LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT * FROM `{$table}`";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY occurred_at DESC, log_id DESC';

        $limit  = isset($filters['limit']) ? max(1, min(1000, (int) $filters['limit'])) : 200;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        $sql   .= ' LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    // ---------------------------------------------------------------------
    //  Context encoding (disciplines 1 + 3)
    // ---------------------------------------------------------------------

    /**
     * Scrub PII, then JSON-encode the context with depth + size limits.
     * When $merge_with_log_id is set, merge with the row's existing
     * context so finish/heartbeat append rather than clobber.
     */
    private static function encode_context(array $context, int $merge_with_log_id = 0): string {
        $context = self::scrub_context($context, 0);

        if ($merge_with_log_id > 0) {
            try {
                global $wpdb;
                $table = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT context FROM `{$table}` WHERE log_id = %d",
                    $merge_with_log_id
                ));
                if (is_string($existing) && $existing !== '') {
                    $decoded = json_decode($existing, true);
                    if (is_array($decoded)) {
                        $context = array_merge($decoded, $context);
                    }
                }
            } catch (\Throwable $t) {
                // Merge is best-effort; fall through with the new context.
            }
        }

        $encoded = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, self::CONTEXT_MAX_DEPTH);
        if (!is_string($encoded)) {
            return '{}';
        }
        if (strlen($encoded) > self::CONTEXT_MAX_BYTES) {
            $encoded = wp_json_encode(['truncated' => true, 'orig_size' => strlen($encoded)]);
            if (!is_string($encoded)) {
                return '{}';
            }
        }
        return $encoded;
    }

    /**
     * Recursively scrub a context array: keys that name a sensitive field
     * (per the audit logger's SENSITIVE_FIELDS via redact, which we mirror
     * by name here) get their value fingerprinted, and every scalar string
     * value is passed through sanitize_for_log so emails / blobs embedded
     * in free text are redacted. Depth-bounded as a second guard against
     * runaway structures before the JSON depth cap.
     */
    private static function scrub_context($value, int $depth) {
        if ($depth > self::CONTEXT_MAX_DEPTH) {
            return '[max-depth]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && self::is_sensitive_key($k)) {
                    if (is_string($v) || is_numeric($v)) {
                        $sval = (string) $v;
                        $out[$k] = $sval === '' ? $sval : '[redacted:sha256=' . substr(hash('sha256', $sval), 0, 12) . ']';
                    } else {
                        // A sensitive key holding an array/object must NOT fall through to
                        // recursion: sanitize_for_log only catches emails/phones/9+digit IDs/blobs,
                        // not free-text PII (names, streets, postal codes, diet-note prose). Redact
                        // the whole value so nothing under a sensitive key reaches the trunk raw.
                        $out[$k] = '[redacted:non-scalar]';
                    }
                    continue;
                }
                $out[$k] = self::scrub_context($v, $depth + 1);
            }
            return $out;
        }
        if (is_string($value) && $value !== '' && class_exists('MealsDB_Logger')) {
            return MealsDB_Logger::sanitize_for_log($value);
        }
        return $value;
    }

    /**
     * Sensitive context keys — fingerprinted rather than stored raw. DERIVED
     * from MealsDB_Logger::sensitive_fields() (the single source) so a PII key
     * added there is scrubbed in the trunk too, plus the two generic short forms
     * ('email', 'phone') the trunk also guards. A central sink with raw PII is a
     * worse leak than the scattered status quo (audit synthesis T8).
     */
    private static function is_sensitive_key(string $key): bool {
        static $keys = null;
        if ($keys === null) {
            $base  = class_exists('MealsDB_Logger') ? MealsDB_Logger::sensitive_fields() : [];
            $keys  = [];
            foreach (array_merge($base, ['email', 'phone']) as $k) {
                $keys[strtolower((string) $k)] = true;
            }
        }
        return isset($keys[strtolower($key)]);
    }

    /**
     * @return array<string, int> Only the four record_* counters.
     */
    private static function stats_to_columns(array $stats): array {
        $allowed = ['records_processed', 'records_updated', 'records_skipped', 'records_errored'];
        $columns = [];
        foreach ($allowed as $key) {
            if (isset($stats[$key])) {
                $columns[$key] = max(0, (int) $stats[$key]);
            }
        }
        return $columns;
    }

    /**
     * @return array<string, mixed>|null Non-counter stats, or null.
     */
    private static function extract_extra_context(array $stats): ?array {
        $counters = ['records_processed' => true, 'records_updated' => true, 'records_skipped' => true, 'records_errored' => true];
        $extra = [];
        foreach ($stats as $k => $v) {
            if (!isset($counters[$k])) {
                $extra[$k] = $v;
            }
        }
        return empty($extra) ? null : $extra;
    }

    /**
     * Discipline 4 fallback: a logging failure drops a scrubbed line on
     * the PHP error log and returns. Never throws, never recurses.
     */
    private static function fallback_error(string $message): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[Event Log] ' . $message);
        } else {
            error_log('[MealsDB Event Log] ' . $message);
        }
    }

    /**
     * Test/diagnostic helper: clear the in-memory started-at cache.
     */
    public static function _reset_started_cache(): void {
        self::$started_unix = [];
    }
}
