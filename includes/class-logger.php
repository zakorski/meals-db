<?php
/**
 * Audit logger for Meals DB plugin actions.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Logger {

    /**
     * Log an error message to the error log with a Meals DB prefix.
     */
    public static function error(string $message): void {
        error_log('[MealsDB] ' . $message);
    }

    /**
     * Logs a change or action to the audit trail.
     *
     * @param string $action Action name (e.g. sync_override)
     * @param int $target_id ID of the client affected
     * @param string $field Field that was changed
     * @param string|null $old Previous value
     * @param string|null $new New value
     * @param string $source Source of change (woo, mealsdb, etc.)
     */
    public static function log(string $action, int $target_id, string $field, ?string $old, ?string $new, string $source = 'mealsdb') {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::AUDIT_LOG);
        $user_id = get_current_user_id();

        $sql = $wpdb->prepare(
            "INSERT INTO `{$table}` (user_id, action, target_id, field_changed, old_value, new_value, source) VALUES (%d, %s, %d, %s, %s, %s, %s)",
            $user_id,
            $action,
            $target_id,
            $field,
            $old,
            $new,
            $source
        );

        $result = $wpdb->query($sql);
        if ($result === false) {
            error_log('[MealsDB Logger] Insert failed: ' . $wpdb->last_error);
        }
    }

    /**
     * Optional helper: get recent logs (for display/export).
     *
     * @param int $limit
     * @return array
     */
    public static function get_recent_logs(int $limit = 50): array {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::AUDIT_LOG);
        $sql = $wpdb->prepare(
            "SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d",
            $limit
        );

        $results = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($results)) {
            error_log('[MealsDB Logger] Failed to execute recent logs query: ' . ($wpdb->last_error ?: 'unknown error'));
            return [];
        }

        return $results;
    }
}
