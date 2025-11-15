<?php
/**
 * AJAX handlers for client initials utilities.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Ajax_Initials {

    public function __construct() {
        add_action('wp_ajax_mealsdb_generate_initials', [$this, 'generate_initials']);
        add_action('wp_ajax_mealsdb_validate_initials', [$this, 'validate_initials']);
    }

    /**
     * Generate a unique initials code for a client.
     */
    public function generate_initials(): void {
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
    public function validate_initials(): void {
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
}
