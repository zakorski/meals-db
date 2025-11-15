<?php
/**
 * AJAX handlers for Meals DB synchronization endpoints.
 *
 * @package MealsDB
 */

/**
 * Handles AJAX requests related to synchronization operations.
 */
class MealsDB_Ajax_Sync {

    /**
     * Register the AJAX actions for synchronization events.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_sync_field', [self::class, 'sync_field']);
        add_action('wp_ajax_mealsdb_toggle_ignore', [self::class, 'toggle_ignore']);
        add_action('wp_ajax_mealsdb_check_updates', [self::class, 'check_updates']);
        add_action('wp_ajax_mealsdb_run_update', [self::class, 'run_update']);
        add_action('wp_ajax_mealsdb_update_database', [self::class, 'update_database']);
    }

    /**
     * Sync one field from Meals DB to WooCommerce.
     */
    public static function sync_field(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $woo_user_id = intval($_POST['woo_user_id'] ?? 0);
        $field = sanitize_text_field($_POST['field'] ?? '');
        $value = sanitize_text_field($_POST['value'] ?? '');

        if (!$woo_user_id || !$field) {
            wp_send_json_error(['message' => 'Missing data.']);
        }

        $result = MealsDB_Sync::push_to_woocommerce($woo_user_id, $field, $value);

        if (is_wp_error($result)) {
            $message = $result->get_error_message();
            if (empty($message)) {
                $message = 'Failed to sync field.';
            }

            wp_send_json_error(['message' => $message]);
        }

        wp_send_json_success(['message' => 'Synced successfully.']);
    }

    /**
     * Toggle a mismatch to be ignored or re-enabled.
     */
    public static function toggle_ignore(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $field_name = sanitize_text_field($_POST['field'] ?? '');
        $source = sanitize_text_field($_POST['source'] ?? '');
        $target = sanitize_text_field($_POST['target'] ?? '');
        $set_ignored = filter_var($_POST['ignored'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $user_id = get_current_user_id();
        $conn = MealsDB_DB::get_connection();

        if (!$conn) {
            wp_send_json_error(['message' => 'Database connection failed.']);
        }

        if ($set_ignored) {
            $stmt = $conn->prepare(
                "INSERT INTO meals_ignored_conflicts (field_name, source_value, target_value, ignored_by) VALUES (?, ?, ?, ?)"
            );
            if (!$stmt) {
                error_log('[MealsDB AJAX] Failed to prepare insert for ignored conflict: ' . ($conn->error ?? 'unknown error'));
                wp_send_json_error(['message' => 'Failed to update ignore status.']);
            }

            if (!$stmt->bind_param('sssi', $field_name, $source, $target, $user_id)) {
                $stmt->close();
                error_log('[MealsDB AJAX] Failed binding parameters for ignored conflict insert.');
                wp_send_json_error(['message' => 'Failed to update ignore status.']);
            }
        } else {
            $stmt = $conn->prepare(
                'DELETE FROM meals_ignored_conflicts WHERE field_name = ? AND source_value = ? AND target_value = ?'
            );
            if (!$stmt) {
                error_log('[MealsDB AJAX] Failed to prepare delete for ignored conflict: ' . ($conn->error ?? 'unknown error'));
                wp_send_json_error(['message' => 'Failed to update ignore status.']);
            }

            if (!$stmt->bind_param('sss', $field_name, $source, $target)) {
                $stmt->close();
                error_log('[MealsDB AJAX] Failed binding parameters for ignored conflict delete.');
                wp_send_json_error(['message' => 'Failed to update ignore status.']);
            }
        }

        if (!$stmt->execute()) {
            $stmt->close();
            error_log('[MealsDB AJAX] Failed executing ignore toggle statement: ' . ($stmt->error ?? 'unknown error'));
            wp_send_json_error(['message' => 'Failed to update ignore status.']);
        }

        $stmt->close();

        wp_send_json_success(['message' => $set_ignored ? 'Ignored' : 'Unignored']);
    }

    /**
     * Check Git repository for available updates.
     */
    public static function check_updates(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $result = MealsDB_Updates::check_for_updates();

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'stderr'  => is_array($data) && isset($data['stderr']) ? $data['stderr'] : '',
            ]);
        }

        wp_send_json_success($result);
    }

    /**
     * Pull the latest changes from the Git repository.
     */
    public static function run_update(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $result = MealsDB_Updates::pull_updates();

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'stderr'  => is_array($data) && isset($data['stderr']) ? $data['stderr'] : '',
            ]);
        }

        wp_send_json_success($result);
    }

    /**
     * Run database maintenance to ensure the schema matches the latest version.
     */
    public static function update_database(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $result = MealsDB_Updates::run_database_maintenance();

        wp_send_json_success($result);
    }
}
