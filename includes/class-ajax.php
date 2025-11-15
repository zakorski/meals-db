<?php
/**
 * Handles AJAX endpoints for Meals DB.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

if (!class_exists('MealsDB_Clients_Repository')) {
    require_once __DIR__ . '/services/class-clients-repository.php';
}

class MealsDB_Ajax {

    /**
     * Register all AJAX actions.
     */
    public static function init() {
        add_action('wp_ajax_mealsdb_sync_field', [__CLASS__, 'sync_field']);
        add_action('wp_ajax_mealsdb_toggle_ignore', [__CLASS__, 'toggle_ignore']);
        add_action('wp_ajax_mealsdb_save_draft', [__CLASS__, 'save_draft']);
        add_action('wp_ajax_mealsdb_delete_draft', [__CLASS__, 'delete_draft']);
        add_action('wp_ajax_mealsdb_check_updates', [__CLASS__, 'check_updates']);
        add_action('wp_ajax_mealsdb_run_update', [__CLASS__, 'run_update']);
        add_action('wp_ajax_mealsdb_update_database', [__CLASS__, 'update_database']);
        add_action('wp_ajax_mealsdb_generate_initials', [__CLASS__, 'generate_initials']);
        add_action('wp_ajax_mealsdb_validate_initials', [__CLASS__, 'validate_initials']);
        add_action('wp_ajax_mealsdb_link_client', [__CLASS__, 'link_client']);
        add_action('wp_ajax_mealsdb_link_client_to_wp_user', [__CLASS__, 'link_client_to_wp_user']);
        add_action('wp_ajax_mealsdb_activate_client', [__CLASS__, 'activate_client']);
        add_action('wp_ajax_mealsdb_deactivate_client', [__CLASS__, 'deactivate_client']);
        add_action('wp_ajax_mealsdb_delete_client', [__CLASS__, 'delete_client']);
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
     * Link a Meals DB client record to a WordPress user.
     */
    public static function link_client() {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $client_id = intval($_POST['client_id'] ?? 0);
        $wp_user_id = intval($_POST['wp_user_id'] ?? 0);

        if ($client_id <= 0 || $wp_user_id <= 0) {
            wp_send_json_error(['message' => __('Invalid client or WordPress user.', 'meals-db')]);
        }

        $result = MealsDB_Sync::link_client_to_wordpress_user($client_id, $wp_user_id);

        if (is_wp_error($result)) {
            $message = $result->get_error_message();
            if ($message === '') {
                $message = __('Failed to link client.', 'meals-db');
            }

            wp_send_json_error(['message' => $message]);
        }

        wp_send_json_success([
            'message' => __('Client linked successfully.', 'meals-db'),
        ]);
    }

    /**
     * Link a Meals DB client record directly to a WordPress user ID.
     */
    public static function link_client_to_wp_user() {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $client_id = intval($_POST['client_id'] ?? 0);
        $wp_user_id = isset($_POST['wp_user_id']) ? intval($_POST['wp_user_id']) : null;

        if ($client_id <= 0 || $wp_user_id === null || $wp_user_id < 0) {
            wp_send_json_error(['message' => __('Invalid client or WordPress user.', 'meals-db')]);
        }

        $conn = MealsDB_DB::get_connection();

        if (!$conn) {
            wp_send_json_error(['message' => __('Database connection failed.', 'meals-db')]);
        }

        $repository = new MealsDB_Clients_Repository($conn);

        $client_row = $repository->get_client_by_id($client_id);
        if (!$client_row) {
            wp_send_json_error(['message' => __('Client not found.', 'meals-db')]);
        }

        $existing_wp_user_id = null;
        $raw_existing = $client_row['wordpress_user_id'] ?? null;
        if ($raw_existing !== null && $raw_existing !== '') {
            $existing_wp_user_id = (int) $raw_existing;
        }

        if (!$repository->update_client($client_id, ['wordpress_user_id' => $wp_user_id])) {
            wp_send_json_error(['message' => __('Failed to update Meals DB.', 'meals-db')]);
        }

        MealsDB_Logger::log(
            'link_client_to_wp_user',
            $client_id,
            'wordpress_user_id',
            $existing_wp_user_id !== null ? (string) $existing_wp_user_id : null,
            (string) $wp_user_id
        );

        wp_send_json(['success' => true]);
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

    /**
     * Check Git repository for available updates.
     */
    public static function check_updates() {
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
    public static function run_update() {
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
    public static function update_database() {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $result = MealsDB_Updates::run_database_maintenance();

        wp_send_json_success($result);
    }

    /**
     * Generate a unique initials code for a client.
     */
    public static function generate_initials() {
        check_ajax_referer('mealsdb_generate_initials', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json(['success' => false, 'message' => 'Unauthorized']);
        }

        $code = MealsDB_Initials::generate();

        if (empty($code)) {
            wp_send_json(['success' => false, 'message' => 'Unable to generate initials.']);
        }

        wp_send_json([
            'success' => true,
            'code'    => $code,
        ]);
    }

    /**
     * Validate an initials code for a client.
     */
    public static function validate_initials() {
        check_ajax_referer('mealsdb_validate_initials', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json(['success' => false, 'message' => 'Unauthorized']);
        }

        $code = sanitize_text_field($_POST['code'] ?? '');
        $client_id_raw = $_POST['client_id'] ?? null;
        $client_id = null;

        if ($client_id_raw !== null && $client_id_raw !== '') {
            $client_id = intval($client_id_raw);

            if ($client_id <= 0) {
                $client_id = null;
            }
        }

        $validation = MealsDB_Initials::validate_code($code, $client_id);

        if (!is_array($validation)) {
            wp_send_json(['success' => false, 'message' => 'Unable to validate initials.']);
        }

        if (!empty($validation['valid'])) {
            wp_send_json(['success' => true]);
        }

        $message = isset($validation['message']) ? $validation['message'] : __('Invalid initials.', 'meals-db');

        wp_send_json([
            'success' => false,
            'message' => $message,
        ]);
    }

    /**
     * Activate a client record.
     */
    public static function activate_client() {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        self::ensure_client_permissions();

        $client_id = self::get_requested_client_id();

        if (!MealsDB_Clients::activate_client($client_id)) {
            wp_send_json_error(['message' => __('Unable to activate the client.', 'meals-db')]);
        }

        wp_send_json_success([
            'message' => __('Client activated successfully.', 'meals-db'),
            'active'  => 1,
        ]);
    }

    /**
     * Deactivate a client record.
     */
    public static function deactivate_client() {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        self::ensure_client_permissions();

        $client_id = self::get_requested_client_id();

        if (!MealsDB_Clients::deactivate_client($client_id)) {
            wp_send_json_error(['message' => __('Unable to deactivate the client.', 'meals-db')]);
        }

        wp_send_json_success([
            'message' => __('Client deactivated successfully.', 'meals-db'),
            'active'  => 0,
        ]);
    }

    /**
     * Permanently delete a client record.
     */
    public static function delete_client() {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        self::ensure_client_permissions();

        $client_id = self::get_requested_client_id();

        if (!MealsDB_Clients::delete_client($client_id)) {
            wp_send_json_error(['message' => __('Unable to delete the client.', 'meals-db')]);
        }

        wp_send_json_success([
            'message' => __('Client deleted successfully.', 'meals-db'),
        ]);
    }

    /**
     * Ensure the current user has permission to perform AJAX actions.
     */
    private static function ensure_client_permissions() {
        if (current_user_can('manage_options')) {
            return;
        }

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'meals-db'),
            ]);
        }
    }

    /**
     * Retrieve and validate the requested client ID from the request.
     *
     * @return int
     */
    private static function get_requested_client_id() {
        $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;

        if ($client_id <= 0) {
            wp_send_json_error(['message' => __('Invalid client.', 'meals-db')]);
        }

        return $client_id;
    }
}
