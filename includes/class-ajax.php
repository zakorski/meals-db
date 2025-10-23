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

        MealsDB_Sync::push_to_woocommerce($woo_user_id, $field, $value);

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
            $stmt->bind_param("sssi", $field_name, $source, $target, $user_id);
        } else {
            // Remove from ignored
            $stmt = $conn->prepare("DELETE FROM meals_ignored_conflicts WHERE field_name = ? AND source_value = ? AND target_value = ?");
            $stmt->bind_param("sss", $field_name, $source, $target);
        }

        $stmt->execute();
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

        MealsDB_Client_Form::save_draft($form);

        wp_send_json_success(['message' => 'Saved to drafts.']);
    }
}
