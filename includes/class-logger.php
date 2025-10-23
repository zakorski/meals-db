<?php
/**
 * Audit logger for Meals DB plugin actions.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Created as a work for hire for Meals and More.
 */

class MealsDB_Logger {

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
        $conn = MealsDB_DB::get_connection();

        if (!$conn) {
            error_log('[MealsDB Logger] DB connection failed.');
            return;
        }

        $user_id = get_current_user_id();

        $stmt = $conn->prepare("
            INSERT INTO meals_audit_log (
                user_id, action, target_id, field_changed, old_value, new_value, source
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        if ($stmt === false) {
            error_log('[MealsDB Logger] Prepare failed: ' . $conn->error);
            return;
        }

        $stmt->bind_param(
            "isissss",
            $user_id,
            $action,
            $target_id,
            $field,
            $old,
            $new,
            $source
        );

        $stmt->execute();
        $stmt->close();
    }

    /**
     * Optional helper: get recent logs (for display/export).
     *
     * @param int $limit
     * @return array
     */
    public static function get_recent_logs(int $limit = 50): array {
        $conn = MealsDB_DB::get_connection();

        if (!$conn) {
            return [];
        }

        $sql = "SELECT * FROM meals_audit_log ORDER BY created_at DESC LIMIT ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $limit);
        $stmt->execute();

        $result = $stmt->get_result();
        $logs = [];

        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }

        $stmt->close();
        return $logs;
    }
}
