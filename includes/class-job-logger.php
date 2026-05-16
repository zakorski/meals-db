<?php
/**
 * Job execution logger for Meals DB scheduled cron jobs.
 *
 * Writes start/finish/fail rows to meals_job_log so the daily report
 * and the Cron Status admin page can answer "did this job run?" and
 * "did it succeed?" without relying on PHP error_log alone.
 *
 * Timestamps are stored in UTC (gmdate). Display layers convert to
 * site timezone at render time.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Job_Logger {

    /**
     * Cache the per-row started_at timestamp so finish() / fail() can
     * compute duration without a SELECT. Keyed by log_id.
     *
     * @var array<int, int>
     */
    private static $started_unix = [];

    /**
     * Record the start of a job run.
     *
     * @param string $job_name  Short identifier (e.g. 'wp_to_mealsdb_sync').
     * @param array  $context   Free-form JSON-encodable context for debug.
     * @return int log_id, or 0 on insert failure.
     */
    public static function start(string $job_name, array $context = []): int {
        global $wpdb;

        $table   = MealsDB_DB::get_table_name(MealsDB_Tables::JOB_LOG);
        $now     = time();
        $started = gmdate('Y-m-d H:i:s', $now);

        $data = [
            'job_name'   => $job_name,
            'started_at' => $started,
            'status'     => 'running',
            'context'    => self::encode_context($context),
        ];

        $result = $wpdb->insert($table, $data, ['%s', '%s', '%s', '%s']);
        if ($result === false) {
            MealsDB_Logger::error('[Job Logger] start() insert failed: ' . $wpdb->last_error);
            return 0;
        }

        $log_id = (int) $wpdb->insert_id;
        self::$started_unix[$log_id] = $now;
        return $log_id;
    }

    /**
     * Record successful completion.
     *
     * @param int   $log_id From start().
     * @param array $stats  May contain records_processed / records_updated /
     *                      records_skipped / records_errored and/or
     *                      additional fields that get folded into context.
     */
    public static function finish(int $log_id, array $stats = []): void {
        if ($log_id <= 0) {
            return;
        }
        self::update_row($log_id, 'success', null, $stats);
    }

    /**
     * Record a failed completion. Also emits the failure to error_log
     * (via MealsDB_Logger::error) so sysadmins watching the PHP log see
     * it immediately, in addition to the new row in meals_job_log.
     *
     * @param int    $log_id        From start().
     * @param string $error_message Sanitized error text. Logger will
     *                              redact emails / blob-shaped strings.
     * @param array  $stats         Same shape as finish().
     */
    public static function fail(int $log_id, string $error_message, array $stats = []): void {
        if ($log_id <= 0) {
            MealsDB_Logger::error('[Job Logger] fail() called with invalid log_id: ' . $error_message);
            return;
        }
        self::update_row($log_id, 'failure', $error_message, $stats);

        // Surface the failure on the PHP error log as well. The logger
        // already redacts emails and blob-shaped strings, so passing
        // exception messages straight through is safe.
        MealsDB_Logger::error(sprintf('[Job Logger] Job failed (log_id=%d): %s', $log_id, $error_message));
    }

    /**
     * Update records_processed mid-batch without changing status.
     * Allows the daily report to detect a job that started but hung
     * (status='running' with old started_at, but heartbeat shows
     * progress versus none).
     */
    public static function heartbeat(int $log_id, array $stats): void {
        if ($log_id <= 0) {
            return;
        }
        global $wpdb;

        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::JOB_LOG);
        $update = self::stats_to_columns($stats);
        if (empty($update)) {
            return;
        }

        $formats = array_fill(0, count($update), '%d');
        $wpdb->update($table, $update, ['log_id' => $log_id], $formats, ['%d']);
    }

    /**
     * Get the most recent successful run timestamp (UTC) for a job.
     */
    public static function last_success(string $job_name): ?string {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::JOB_LOG);
        $sql   = $wpdb->prepare(
            "SELECT completed_at FROM `{$table}` WHERE job_name = %s AND status = %s ORDER BY completed_at DESC LIMIT 1",
            $job_name,
            'success'
        );
        $value = $wpdb->get_var($sql);
        return is_string($value) ? $value : null;
    }

    /**
     * Get recent runs for a job (most recent first).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent_runs(string $job_name, int $limit = 7): array {
        global $wpdb;

        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 500) {
            // Sanity cap so an attacker-controlled $limit can't blow
            // up memory on a future REST endpoint that forwards user
            // input to this method.
            $limit = 500;
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::JOB_LOG);
        $sql   = $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE job_name = %s ORDER BY started_at DESC LIMIT %d",
            $job_name,
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Get the single most recent run (any status) within a UTC window.
     *
     * @return array<string, mixed>|null
     */
    public static function latest_in_window(string $job_name, string $since_utc): ?array {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::JOB_LOG);
        $sql   = $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE job_name = %s AND started_at >= %s ORDER BY started_at DESC LIMIT 1",
            $job_name,
            $since_utc
        );
        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Update helper used by finish() and fail().
     */
    private static function update_row(int $log_id, string $status, ?string $error_message, array $stats): void {
        global $wpdb;

        $table     = MealsDB_DB::get_table_name(MealsDB_Tables::JOB_LOG);
        $now       = time();
        $completed = gmdate('Y-m-d H:i:s', $now);
        $started   = self::$started_unix[$log_id] ?? null;
        $duration  = $started !== null ? max(0, $now - $started) : null;

        $update  = ['status' => $status, 'completed_at' => $completed];
        $formats = ['%s', '%s'];

        if ($duration !== null) {
            $update['duration_seconds'] = $duration;
            $formats[] = '%d';
        }

        foreach (self::stats_to_columns($stats) as $column => $value) {
            $update[$column] = $value;
            $formats[] = '%d';
        }

        if ($error_message !== null) {
            $update['error_message'] = MealsDB_Logger::sanitize_for_log($error_message);
            $formats[] = '%s';
        }

        // Context updates are merged in via stats_to_columns(); if
        // additional non-counter stats were passed, persist them in
        // the context JSON column instead of losing them.
        $extra_context = self::extract_extra_context($stats);
        if ($extra_context !== null) {
            $update['context'] = self::encode_context($extra_context, $log_id);
            $formats[] = '%s';
        }

        $wpdb->update($table, $update, ['log_id' => $log_id], $formats, ['%d']);
        unset(self::$started_unix[$log_id]);
    }

    /**
     * Map known stat keys to their column names. Unknown keys are
     * filtered out here and routed to extract_extra_context() so they
     * land in the JSON context blob rather than getting silently
     * dropped.
     *
     * @return array<string, int>
     */
    private static function stats_to_columns(array $stats): array {
        $allowed = [
            'records_processed' => true,
            'records_updated'   => true,
            'records_skipped'   => true,
            'records_errored'   => true,
        ];

        $columns = [];
        foreach ($stats as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            $columns[$key] = max(0, (int) $value);
        }
        return $columns;
    }

    /**
     * Pull non-counter stats out of the $stats array so they can be
     * merged into the context column.
     *
     * @return array<string, mixed>|null
     */
    private static function extract_extra_context(array $stats): ?array {
        $allowed = [
            'records_processed' => true,
            'records_updated'   => true,
            'records_skipped'   => true,
            'records_errored'   => true,
        ];

        $extra = [];
        foreach ($stats as $key => $value) {
            if (isset($allowed[$key])) {
                continue;
            }
            $extra[$key] = $value;
        }
        return empty($extra) ? null : $extra;
    }

    /**
     * JSON-encode the context blob with size + depth limits. If a
     * log_id is supplied, merge with the existing row's context so
     * heartbeat() / finish() can append without clobbering what
     * start() recorded.
     */
    private static function encode_context(array $context, int $merge_with_log_id = 0): string {
        if ($merge_with_log_id > 0) {
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::JOB_LOG);
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
        }

        // depth=10 is generous for runtime context; flags omit the
        // pretty-print overhead and pass through unicode unescaped so
        // the column stays human-readable when reviewed in phpMyAdmin.
        $encoded = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 10);
        if (!is_string($encoded)) {
            return '{}';
        }

        // Hard cap so a runaway context (e.g. an exception trace
        // accidentally passed in) can't write a megabyte per row.
        if (strlen($encoded) > 16384) {
            $encoded = wp_json_encode([
                'truncated' => true,
                'orig_size' => strlen($encoded),
            ]);
            if (!is_string($encoded)) {
                return '{}';
            }
        }
        return $encoded;
    }

    /**
     * Test/diagnostic helper: clear the in-memory started-at cache.
     * Only intended for the tests/ directory.
     */
    public static function _reset_started_cache(): void {
        self::$started_unix = [];
    }
}
