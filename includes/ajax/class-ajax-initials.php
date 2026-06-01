<?php
/**
 * AJAX handlers for Meals DB initials endpoints.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

/**
 * Handles AJAX requests for initials generation and validation.
 */
class MealsDB_Ajax_Initials {

    /**
     * Register the AJAX actions for initials events.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_generate_initials', [self::class, 'generate_initials']);
        add_action('wp_ajax_mealsdb_validate_initials', [self::class, 'validate_initials']);
    }

    /**
     * Generate a unique initials code for a client.
     *
     * Accepts optional client data (name, address) for better generation.
     */
    public static function generate_initials(): void {
        check_ajax_referer('mealsdb_generate_initials', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json(['success' => false, 'message' => 'Unauthorized']);
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('client_modify')) {
            wp_send_json(['success' => false, 'message' => __('Rate limit exceeded. Please try again later.', 'meals-db')]);
        }

        // Optional name to seed name-based candidate patterns.
        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));

        // Use the name-seeded generator when a name is present, otherwise the
        // plain random generator. Both enforce global uniqueness.
        if (!empty($first_name) || !empty($last_name)) {
            $code = MealsDB_Initials_Validator::generate($first_name, $last_name);
        } else {
            $code = MealsDB_Initials::generate();
        }

        if (empty($code) || $code === false) {
            // Generator exhausted its 100 attempts. Surface a specific,
            // actionable message (rendered on-page by client-initials.js, not a
            // popup) so the operator can immediately retry — and so a genuinely
            // near-full namespace gets noticed.
            wp_send_json([
                'success' => false,
                'message' => __('Could not find an unused initials code after 100 attempts. Please click Generate again to retry.', 'meals-db'),
            ]);
        }

        wp_send_json([
            'success' => true,
            'code'    => $code,
        ]);
    }

    /**
     * Validate an initials code for a client.
     *
     * Delivery initials are globally unique; any code already used by another
     * client is rejected.
     */
    public static function validate_initials(): void {
        check_ajax_referer('mealsdb_validate_initials', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json(['success' => false, 'message' => 'Unauthorized']);
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('client_modify')) {
            wp_send_json(['success' => false, 'message' => __('Rate limit exceeded. Please try again later.', 'meals-db')]);
        }

        $code = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
        // Initials are exactly 3 characters; cap aggressively so a hostile
        // caller can't push huge strings into LIKE-style downstream
        // queries or into the validate-by-fragment branch.
        if (strlen($code) > 8) {
            $code = substr($code, 0, 8);
        }
        $client_id_raw = $_POST['client_id'] ?? null;
        $client_id = null;

        if ($client_id_raw !== null && $client_id_raw !== '') {
            $client_id = intval($client_id_raw);

            if ($client_id <= 0) {
                $client_id = null;
            }
        }

        // Delivery initials are globally unique — a duplicate is simply invalid.
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
}
