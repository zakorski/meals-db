<?php
/**
 * AJAX handlers for Meals DB client management endpoints.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

/**
 * Handles AJAX requests for client management.
 */
class MealsDB_Ajax_Clients {

    /**
     * Register the AJAX actions for client management events.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_link_client', [self::class, 'link_client']);
        add_action('wp_ajax_mealsdb_link_client_to_wp_user', [self::class, 'link_client_to_wp_user']);
        add_action('wp_ajax_mealsdb_activate_client', [self::class, 'activate_client']);
        add_action('wp_ajax_mealsdb_deactivate_client', [self::class, 'deactivate_client']);
        add_action('wp_ajax_mealsdb_delete_client', [self::class, 'delete_client']);
    }

    /**
     * Link a Meals DB client record to a WordPress user.
     */
    public static function link_client(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        self::enforce_rate_limit();

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
     *
     * COLUMN NAME NOTE: The DB column on meals_clients is `wp_user_id`,
     * NOT `wordpress_user_id` (which is the form-side vocabulary, and
     * also the canonical name on meals_staff — they're different
     * tables). A previous version used `wordpress_user_id` here; the
     * repository's filter_to_known_columns silently dropped it, the
     * read returned null, and the handler reported success while
     * doing nothing. See CLAUDE.md "Form-side vs DB-side column
     * names".
     */
    public static function link_client_to_wp_user(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        self::enforce_rate_limit();

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
        $raw_existing = $client_row['wp_user_id'] ?? null;
        if ($raw_existing !== null && $raw_existing !== '') {
            $existing_wp_user_id = (int) $raw_existing;
        }

        if (!$repository->update_client($client_id, ['wp_user_id' => $wp_user_id])) {
            wp_send_json_error(['message' => __('Failed to update Meals DB.', 'meals-db')]);
        }

        MealsDB_Logger::log(
            'link_client_to_wp_user',
            $client_id,
            'wp_user_id',
            $existing_wp_user_id !== null ? (string) $existing_wp_user_id : null,
            (string) $wp_user_id
        );

        wp_send_json(['success' => true]);
    }

    /**
     * Activate a client record.
     */
    public static function activate_client(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        self::ensure_client_permissions();
        self::enforce_rate_limit();

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
    public static function deactivate_client(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        self::ensure_client_permissions();
        self::enforce_rate_limit();

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
    public static function delete_client(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        self::ensure_client_permissions();
        self::enforce_rate_limit();

        $client_id = self::get_requested_client_id();

        if (!MealsDB_Clients::delete_client($client_id)) {
            wp_send_json_error(['message' => __('Unable to delete the client.', 'meals-db')]);
        }

        wp_send_json_success([
            'message' => __('Client deleted successfully.', 'meals-db'),
        ]);
    }

    /**
     * Enforce the per-user rate limit for client write operations.
     */
    private static function enforce_rate_limit(): void {
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('client_modify')) {
            wp_send_json_error(
                ['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')],
                429
            );
        }
    }

    /**
     * Ensure the current user has permission to perform AJAX actions.
     */
    private static function ensure_client_permissions(): void {
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
     */
    private static function get_requested_client_id(): int {
        $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;

        if ($client_id <= 0) {
            wp_send_json_error(['message' => __('Invalid client.', 'meals-db')]);
        }

        return $client_id;
    }
}
