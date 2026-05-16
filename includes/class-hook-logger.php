<?php
/**
 * Hook firing logger for Meals DB.
 *
 * Records every fire of the WC/WP hooks this plugin cares about so
 * the daily report can answer "did the hook fire at all yesterday?"
 * separately from "did the plugin act on it?". A hook can fire and
 * still result in `outcome='skipped'` when the order isn't a Meals
 * client — distinguishing that case from "hook never fired" is the
 * specific value-add over the audit log.
 *
 * Performance: record() executes one INSERT and nothing else on the
 * hot path. It's called from order-creation hooks, profile_update,
 * etc. — anything more would taste real to a checkout flow.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Hook_Logger {

    public const OUTCOME_PROCESSED = 'processed';
    public const OUTCOME_SKIPPED   = 'skipped';
    public const OUTCOME_ERRORED   = 'errored';

    /**
     * Record that a hook fired.
     *
     * @param string      $hook_name     WP/WC hook name.
     * @param string|null $target_type   'order' | 'user' | etc. or null.
     * @param int|null    $target_id     Numeric ID (post or user), or null.
     * @param array       $context       JSON-encodable extra info.
     * @param string      $outcome       processed | skipped | errored.
     * @param string|null $error_message Sanitized error text when outcome=errored.
     */
    public static function record(
        string $hook_name,
        ?string $target_type = null,
        ?int $target_id = null,
        array $context = [],
        string $outcome = self::OUTCOME_PROCESSED,
        ?string $error_message = null
    ): void {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::HOOK_LOG);

        if (!in_array($outcome, [self::OUTCOME_PROCESSED, self::OUTCOME_SKIPPED, self::OUTCOME_ERRORED], true)) {
            $outcome = self::OUTCOME_PROCESSED;
        }

        $data    = [
            'hook_name' => $hook_name,
            'fired_at'  => gmdate('Y-m-d H:i:s'),
            'outcome'   => $outcome,
        ];
        $formats = ['%s', '%s', '%s'];

        if ($target_type !== null && $target_type !== '') {
            // Trim aggressively — the column is VARCHAR(20).
            $data['target_type'] = substr($target_type, 0, 20);
            $formats[] = '%s';
        }
        if ($target_id !== null && $target_id > 0) {
            $data['target_id'] = $target_id;
            $formats[] = '%d';
        }
        if (!empty($context)) {
            $encoded = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 6);
            if (is_string($encoded) && strlen($encoded) <= 4096) {
                $data['context'] = $encoded;
                $formats[] = '%s';
            }
        }
        if ($error_message !== null && $error_message !== '') {
            $data['error_message'] = MealsDB_Logger::sanitize_for_log($error_message);
            $formats[] = '%s';
        }

        // Single INSERT, no SELECTs, no follow-up writes. Failures here
        // must never bubble up — instrumentation breaking checkout is
        // a regression worse than the gap the instrumentation closes.
        $result = $wpdb->insert($table, $data, $formats);
        if ($result === false) {
            // Quietly drop a redacted line into error_log. Don't throw,
            // don't recurse into the audit log, don't retry.
            MealsDB_Logger::error('[Hook Logger] insert failed for ' . $hook_name . ': ' . $wpdb->last_error);
        }
    }

    /**
     * Count hook fires within a UTC window.
     */
    public static function count_in_window(string $hook_name, string $start_utc, string $end_utc): int {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::HOOK_LOG);
        $sql   = $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE hook_name = %s AND fired_at >= %s AND fired_at < %s",
            $hook_name,
            $start_utc,
            $end_utc
        );
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Count hook fires grouped by outcome, within a UTC window.
     *
     * @return array<string, int> Keys: processed, skipped, errored.
     */
    public static function count_by_outcome(string $hook_name, string $start_utc, string $end_utc): array {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::HOOK_LOG);
        $sql   = $wpdb->prepare(
            "SELECT outcome, COUNT(*) AS c FROM `{$table}` WHERE hook_name = %s AND fired_at >= %s AND fired_at < %s GROUP BY outcome",
            $hook_name,
            $start_utc,
            $end_utc
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        $out = [
            self::OUTCOME_PROCESSED => 0,
            self::OUTCOME_SKIPPED   => 0,
            self::OUTCOME_ERRORED   => 0,
        ];
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            $key = (string) ($row['outcome'] ?? '');
            if (isset($out[$key])) {
                $out[$key] = (int) ($row['c'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Most recent fire timestamp (UTC) for a hook, or null if never seen.
     */
    public static function last_fire(string $hook_name): ?string {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::HOOK_LOG);
        $sql   = $wpdb->prepare(
            "SELECT fired_at FROM `{$table}` WHERE hook_name = %s ORDER BY fired_at DESC LIMIT 1",
            $hook_name
        );
        $value = $wpdb->get_var($sql);
        return is_string($value) ? $value : null;
    }

    /**
     * Trailing-N-day daily averages for a hook, ending at $end_utc-1.
     * Returns N day counts and their average. Used for anomaly checks.
     *
     * @return array{daily: array<int, int>, average: float}
     */
    public static function trailing_window_counts(string $hook_name, string $end_utc, int $days): array {
        global $wpdb;

        $days = max(1, min(90, $days));
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::HOOK_LOG);

        $start_unix = strtotime($end_utc . ' UTC') - ($days * 86400);
        $start_utc  = gmdate('Y-m-d H:i:s', $start_unix);

        $sql = $wpdb->prepare(
            "SELECT DATE(fired_at) AS d, COUNT(*) AS c FROM `{$table}` WHERE hook_name = %s AND fired_at >= %s AND fired_at < %s GROUP BY DATE(fired_at)",
            $hook_name,
            $start_utc,
            $end_utc
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $by_day = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $by_day[(string) $row['d']] = (int) $row['c'];
            }
        }

        $daily = [];
        for ($i = $days; $i >= 1; $i--) {
            $day = gmdate('Y-m-d', strtotime($end_utc . ' UTC') - ($i * 86400));
            $daily[] = $by_day[$day] ?? 0;
        }
        $sum = array_sum($daily);
        $avg = $days > 0 ? $sum / $days : 0.0;

        return ['daily' => $daily, 'average' => $avg];
    }
}
