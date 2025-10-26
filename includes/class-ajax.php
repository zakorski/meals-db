<?php
/**
 * Handles AJAX endpoints for Meals DB.
 * 
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Created as a work for hire for Meals and More.
 */

class MealsDB_Ajax {

    /**
     * Register all AJAX actions.
     */
    public static function init() {
        add_action('wp_ajax_mealsdb_sync_field', [__CLASS__, 'sync_field']);
        add_action('wp_ajax_mealsdb_toggle_ignore', [__CLASS__, 'toggle_ignore']);
        add_action('wp_ajax_mealsdb_save_draft', [__CLASS__, 'save_draft']);
        add_action('wp_ajax_mealsdb_delete_draft', [__CLASS__, 'delete_draft']);
    }

    /**
     * Sync one field from Meals DB to WooCommerce.
     */
    public static function sync_field() {
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
    public static function toggle_ignore() {
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
            // Insert as ignored
            $stmt = $conn->prepare("INSERT INTO meals_ignored_conflicts (field_name, source_value, target_value, ignored_by) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                error_log('[MealsDB AJAX] Failed to prepare insert for ignored conflict: ' . ($conn->error ?? 'unknown error'));
                wp_send_json_error(['message' => 'Failed to update ignore status.']);
            }

            if (!$stmt->bind_param("sssi", $field_name, $source, $target, $user_id)) {
                $stmt->close();
                error_log('[MealsDB AJAX] Failed binding parameters for ignored conflict insert.');
                wp_send_json_error(['message' => 'Failed to update ignore status.']);
            }
        } else {
            // Remove from ignored
            $stmt = $conn->prepare("DELETE FROM meals_ignored_conflicts WHERE field_name = ? AND source_value = ? AND target_value = ?");
            if (!$stmt) {
                error_log('[MealsDB AJAX] Failed to prepare delete for ignored conflict: ' . ($conn->error ?? 'unknown error'));
                wp_send_json_error(['message' => 'Failed to update ignore status.']);
            }

            if (!$stmt->bind_param("sss", $field_name, $source, $target)) {
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
     * Save incomplete submission to drafts.
     */
    public static function save_draft() {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $payload = $_POST['form_data'] ?? '';
        parse_str($payload, $form);

        if (empty($form)) {
            wp_send_json_error(['message' => 'Invalid form data.']);
        }

        $draft_id = isset($form['draft_id']) ? intval($form['draft_id']) : null;

        if ($draft_id !== null) {
            unset($form['draft_id']);
        }

        if (isset($form['resume_draft'])) {
            unset($form['resume_draft']);
        }

        $saved_id = MealsDB_Client_Form::save_draft($form, $draft_id);

        if ($saved_id === false) {
            wp_send_json_error(['message' => 'Failed to save draft.']);
        }

        wp_send_json_success([
            'message'   => 'Saved to drafts.',
            'draft_id'  => intval($saved_id),
        ]);
    }

    /**
     * Delete a saved draft.
     */
    public static function delete_draft() {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $draft_id = intval($_POST['id'] ?? 0);

        if ($draft_id <= 0) {
            wp_send_json_error(['message' => 'Invalid draft ID.']);
        }

        if (!MealsDB_Client_Form::delete_draft($draft_id)) {
            wp_send_json_error(['message' => 'Failed to delete draft.']);
        }

        wp_send_json_success(['message' => 'Draft deleted.']);
    }
}
