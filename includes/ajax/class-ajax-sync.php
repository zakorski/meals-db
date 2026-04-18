<?php
/**
 * AJAX handlers for Meals DB synchronization endpoints.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

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
        add_action('wp_ajax_mealsdb_fetch_products', [self::class, 'fetch_products']);
    }

    /**
     * Sync one field from Meals DB to WooCommerce.
     */
    public static function sync_field(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('sync_operations')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }

        $woo_user_id = intval($_POST['woo_user_id'] ?? 0);
        $client_id = intval($_POST['client_id'] ?? 0);
        $field = sanitize_text_field(wp_unslash($_POST['field'] ?? ''));
        $value = sanitize_text_field(wp_unslash($_POST['value'] ?? ''));
        $direction = sanitize_key(wp_unslash($_POST['direction'] ?? 'meals_db'));

        if (!$field) {
            wp_send_json_error(['message' => 'Missing data.']);
        }

        switch ($direction) {
            case 'meals_db':
                if (!$woo_user_id) {
                    wp_send_json_error(['message' => 'Missing data.']);
                }

                $result = MealsDB_Sync::push_to_woocommerce($woo_user_id, $field, $value);
                break;
            case 'woocommerce':
                if (!$client_id) {
                    wp_send_json_error(['message' => 'Missing data.']);
                }

                $result = MealsDB_Sync::push_to_meals_db($client_id, $field, $value);
                break;
            default:
                wp_send_json_error(['message' => 'Invalid sync direction.']);
        }

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

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('sync_operations')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }

        $field_name  = sanitize_text_field(wp_unslash($_POST['field'] ?? ''));
        $source      = sanitize_text_field(wp_unslash($_POST['source'] ?? ''));
        $target      = sanitize_text_field(wp_unslash($_POST['target'] ?? ''));
        $set_ignored = MealsDB_Helpers::bool_flag($_POST['ignored'] ?? null, false);

        // Whitelist field_name against the canonical sync field set so the
        // ignore-conflicts table can't be poisoned with arbitrary
        // attacker-supplied "fields" via this endpoint.
        $allowed_fields = array_merge(
            MealsDB_Sync::get_wp_authoritative_fields(),
            MealsDB_Sync::get_mealsdb_authoritative_fields()
        );
        if ($field_name === '' || !in_array($field_name, $allowed_fields, true)) {
            wp_send_json_error(['message' => 'Unsupported sync field.']);
        }

        $user_id = get_current_user_id();
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::IGNORED_CONFLICTS);

        if ($set_ignored) {
            // Treat duplicate (field, source, target) tuples as a no-op so a
            // double-click doesn't accumulate identical rows that the
            // unignore branch would then have to delete in bulk.
            $result = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO `{$table}` (field_name, source_value, target_value, ignored_by) VALUES (%s, %s, %s, %d)",
                $field_name, $source, $target, $user_id
            ));
        } else {
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` WHERE field_name = %s AND source_value = %s AND target_value = %s",
                $field_name, $source, $target
            ));
        }

        if ($result === false) {
            error_log('[MealsDB AJAX] Failed executing ignore toggle: ' . $wpdb->last_error);
            wp_send_json_error(['message' => 'Failed to update ignore status.']);
        }

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

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('sync_operations')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }

        $result = MealsDB_Updates::check_for_updates();

        if (is_wp_error($result)) {
            self::handle_update_failure($result);
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

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('sync_operations')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }

        $result = MealsDB_Updates::pull_updates();

        if (is_wp_error($result)) {
            self::handle_update_failure($result);
        }

        wp_send_json_success($result);
    }

    /**
     * Log shell stderr / process detail server-side and return a
     * generic error message to the client. Git stderr can include
     * filesystem paths, remote URLs, and credentials; surfacing it
     * to the AJAX caller is information disclosure.
     */
    private static function handle_update_failure(\WP_Error $result): void {
        $data   = $result->get_error_data();
        $stderr = is_array($data) && isset($data['stderr']) ? trim((string) $data['stderr']) : '';
        if ($stderr !== '') {
            error_log('[MealsDB Updates] ' . $result->get_error_code() . ': ' . $stderr);
        }
        wp_send_json_error(['message' => $result->get_error_message()]);
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

    /**
     * Ensure plugin products exist for every WooCommerce product.
     */
    public static function fetch_products(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('sync_operations')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }

        $result = MealsDB_Updates::fetch_products_from_woocommerce();

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
            ]);
        }

        wp_send_json_success($result);
    }
}
