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

        // Get optional client data for address-based generation
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $client_data = self::get_client_data_from_request();

        // Use new validator if we have client data, otherwise fallback to legacy
        if (!empty($first_name) || !empty($last_name) || !empty($client_data)) {
            $code = MealsDB_Initials_Validator::generate($first_name, $last_name, $client_data);
        } else {
            $code = MealsDB_Initials::generate();
        }

        if (empty($code) || $code === false) {
            wp_send_json(['success' => false, 'message' => 'Unable to generate initials.']);
        }

        wp_send_json([
            'success' => true,
            'code'    => $code,
        ]);
    }

    /**
     * Validate an initials code for a client.
     *
     * Accepts optional address data for address-based duplicate checking.
     */
    public static function validate_initials(): void {
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

        // Get client address data for address-based validation
        $client_data = self::get_client_data_from_request();

        // Use new validator with address data
        $validation = MealsDB_Initials::validate_code($code, $client_id, $client_data);

        if (!is_array($validation)) {
            wp_send_json(['success' => false, 'message' => 'Unable to validate initials.']);
        }

        if (!empty($validation['valid'])) {
            $response = ['success' => true];

            // Include sharing info if initials are shared
            if (!empty($validation['shared'])) {
                $response['shared'] = true;
                $response['message'] = $validation['message'];
            }

            wp_send_json($response);
        }

        $message = isset($validation['message']) ? $validation['message'] : __('Invalid initials.', 'meals-db');

        wp_send_json([
            'success' => false,
            'message' => $message,
        ]);
    }

    /**
     * Extract client address data from POST request.
     *
     * @return array Client data with address fields.
     */
    private static function get_client_data_from_request(): array {
        $client_data = array();

        // Primary address fields (form field names)
        $address_fields = array(
            'address_street_number',
            'address_street_name',
            'address_unit',
            'address_city',
            'address_postal_code',
            'delivery_address_street_number',
            'delivery_address_street_name',
            'delivery_address_unit',
            'delivery_address_city',
            'delivery_address_postal_code',
        );

        foreach ($address_fields as $field) {
            if (isset($_POST[$field])) {
                $client_data[$field] = sanitize_text_field($_POST[$field]);
            }
        }

        return $client_data;
    }
}
