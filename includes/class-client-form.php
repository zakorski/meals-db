<?php
defined('ABSPATH') || exit;

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default') {
        return $text;
    }
}

/**
 * Handles validation and saving of Meals DB client records and drafts.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Client_Form {

    /**
     * List of fields that must be unique.
     */
    private static $unique_fields = [
        'individual_id',
        'requisition_id',
        'vet_health_card',
        'delivery_initials',
    ];

    /**
     * Database columns that are allowed to be persisted from the client form.
     */
    private static $db_columns = [
        'individual_id',
        'requisition_id',
        'vet_health_card',
        'delivery_initials',
        'first_name',
        'last_name',
        'client_type',
        'open_date',
        'assigned_social_worker',
        'social_worker_email',
        'client_email',
        'wordpress_user_id',
        'phone_primary',
        'phone_secondary',
        'do_not_call_client_phone',
        'alt_contact_name',
        'alt_contact_phone_primary',
        'alt_contact_phone_secondary',
        'alt_contact_email',
        'address_street_name',
        'address_city',
        'address_province',
        'address_postal',
        'delivery_address_street_name',
        'delivery_address_city',
        'delivery_address_province',
        'delivery_address_postal',
        'gender',
        'birth_date',
        'service_center_charged',
        'vendor_number',
        'service_id',
        'service_zone',
        'per_sdnb_req',
        'payment_method',
        'rate',
        'client_contribution',
        'delivery_fee',
        'delivery_day',
        'delivery_area_name',
        'delivery_area_zone',
        'ordering_frequency',
        'ordering_contact_method',
        'delivery_frequency',
        'next_order_date',
        'next_delivery_date',
        'freezer_capacity',
        'meal_type',
        'requisition_period',
        'required_start_date',
        'service_commence_date',
        'expected_termination_date',
        'initial_renewal_date',
        'termination_date',
        'most_recent_renewal_date',
        'units',
        'allowance_mains',
        'allowance_sides',
        'diet_concerns',
        'client_comments',
    ];

    /**
     * Keys that should be stripped from transport (non-persisted) data.
     */
    private static $transport_only_keys = [
        'mealsdb_nonce_field',
        '_wp_http_referer',
        'nonce',
        'action',
        'submit',
        'resume_draft',
        'draft_id',
        'client_id',
    ];

    /**
     * Fields that require AES-256 encryption.
     *
     * Mirrors MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS. Kept as a local
     * property so existing references continue to work; initialized lazily
     * below so both sources remain in sync.
     */
    private static $encrypted_fields = [
        'individual_id',
        'requisition_id',
        'vet_health_card',
        'diet_concerns',
        'client_comments',
    ];

    /**
     * Deterministic index columns used for uniqueness checks on encrypted data.
     *
     * delivery_initials is included for back-compat — the column is
     * actually a 3-character plaintext VARCHAR (see schema) so every
     * uniqueness lookup currently queries the plaintext column directly.
     * The hash column (delivery_initials_index) is populated as a
     * defensive shadow in case the column is ever moved to encrypted
     * storage.
     */
    private static $deterministic_index_map = [
        'individual_id'      => 'individual_id_index',
        'requisition_id'     => 'requisition_id_index',
        'vet_health_card'    => 'vet_health_card_index',
        'delivery_initials'  => 'delivery_initials_index',
    ];

    /**
     * Default values for non-nullable columns when inserting new clients.
     */
    private static $insert_required_defaults = [
        'client_email'   => '',
        'phone_primary'  => '',
        'address_postal' => '',
    ];

    /**
     * Human-readable labels for common form fields.
     */
    private static $field_labels = [
        'individual_id'                  => 'Individual ID',
        'requisition_id'                 => 'Requisition ID',
        'vet_health_card'                => 'Veteran Health Identification Card #',
        'delivery_initials'              => 'Initials for delivery',
        'first_name'                     => 'First Name',
        'last_name'                      => 'Last Name',
        'client_type'                  => 'Client Type',
        'open_date'                      => 'Open Date',
        'assigned_social_worker'         => 'Social Worker Name',
        'social_worker_email'            => 'Social Worker Email Address',
        'client_email'                   => 'Client Email',
        'wordpress_user_id'              => 'WordPress User ID',
        'phone_primary'                  => 'Client Phone #1',
        'phone_secondary'                => 'Client Phone #2',
        'do_not_call_client_phone'       => "Do Not Call Client's Phone",
        'alt_contact_name'               => 'Alternate Contact Name',
        'alt_contact_phone_primary'      => 'Alternate Contact Phone #1',
        'alt_contact_phone_secondary'    => 'Alternate Contact Phone #2',
        'alt_contact_email'              => 'Alternate Contact Email',
        'address_street_name'            => 'Address',
        'address_city'                   => 'City',
        'address_province'               => 'Province',
        'address_postal'                 => 'Postal Code',
        'delivery_address_street_name'   => 'Delivery Address',
        'delivery_address_city'          => 'Delivery City',
        'delivery_address_province'      => 'Delivery Province',
        'delivery_address_postal'        => 'Delivery Postal Code',
        'gender'                         => 'Gender',
        'birth_date'                     => 'Date of Birth',
        'service_center_charged'         => 'Service Centre Charged',
        'vendor_number'                  => 'Vendor Number',
        'service_id'                     => 'Service ID',
        'service_zone'                   => 'Service Zone',
        'per_sdnb_req'                   => 'Per SDNB Requirement',
        'payment_method'                 => 'Payment Method',
        'rate'                           => 'Rate',
        'client_contribution'            => 'Client Contributions',
        'delivery_fee'                   => 'Delivery Fee',
        'delivery_day'                   => 'Delivery Day',
        'delivery_area_name'             => 'Delivery Area Name',
        'delivery_area_zone'             => 'Delivery Area Zone',
        'ordering_frequency'             => 'Ordering Frequency',
        'ordering_contact_method'        => 'Ordering Contact Method',
        'delivery_frequency'             => 'Delivery Frequency',
        'next_order_date'                => 'Next Order Date',
        'next_delivery_date'             => 'Next Delivery Date',
        'freezer_capacity'               => 'Freezer Capacity',
        'meal_type'                      => 'Meal Type',
        'requisition_period'             => 'Requisition Period',
        'required_start_date'            => 'Required Start Date',
        'service_commence_date'          => 'Service Commence Date',
        'expected_termination_date'      => 'Expected Termination Date',
        'initial_renewal_date'           => 'Initial Renewal Date',
        'termination_date'               => 'Termination Date',
        'most_recent_renewal_date'       => 'Most Recent Renewal Date',
        'units'                          => '# of Units',
        'allowance_mains'                => 'Mains Allowance',
        'allowance_sides'                => 'Sides Allowance',
        'diet_concerns'                  => 'Diet Concerns',
        'client_comments'                => 'Client Comments',
    ];

    /**
     * Track whether we've attempted to ensure the deterministic index columns exist.
     *
     * @var bool
     */
    private static $indexes_ensured = false;

    /**
     * Validate submitted form data.
     *
     * @param array $data
     * @return array ['valid' => bool, 'errors' => array, 'sanitized' => array]
     */
    public static function validate(array $data, ?int $ignore_client_id = null): array {
        $errors = [];
        $error_details = [
            'missing_required' => [],
            'invalid_format'   => [],
            'unknown_fields'   => [],
            'duplicates'       => [],
        ];

        $unknown_keys = [];
        $sanitized = self::sanitize_payload($data, $unknown_keys);

        if (!empty($unknown_keys)) {
            $message = 'Unknown form fields detected: ' . implode(', ', $unknown_keys);
            $errors[] = $message;
            $error_details['unknown_fields'][] = $message;
        }

        $record_format_error = function (string $field, string $message) use (&$errors, &$error_details): void {
            $errors[] = $message;
            $label = self::get_field_label($field);
            if (!isset($error_details['invalid_format'][$field])) {
                $error_details['invalid_format'][$field] = [
                    'field'    => $field,
                    'label'    => $label,
                    'messages' => [],
                ];
            }
            if (!in_array($message, $error_details['invalid_format'][$field]['messages'], true)) {
                $error_details['invalid_format'][$field]['messages'][] = $message;
            }
        };

        $record_required_error = function (string $field) use (&$errors, &$error_details): void {
            if (isset($error_details['missing_required'][$field])) {
                return;
            }

            $label = self::get_field_label($field);
            $message = sprintf('%s is required.', $label);
            $errors[] = $message;
            $error_details['missing_required'][$field] = [
                'field'   => $field,
                'label'   => $label,
                'message' => $message,
            ];
        };

        // Postal Code
        $postal_pattern = '/^[A-Z]\d[A-Z]\d[A-Z]\d$/';

        $normalize_postal = static function ($value): string {
            $normalized = strtoupper((string) $value);
            $normalized = preg_replace('/[^A-Z0-9]/', '', $normalized ?? '');

            return substr($normalized, 0, 6);
        };

        $sanitized['address_postal'] = $normalize_postal($sanitized['address_postal'] ?? '');
        if ($sanitized['address_postal'] !== '' && !preg_match($postal_pattern, $sanitized['address_postal'])) {
            $record_format_error('address_postal', 'Postal code must be in A1A1A1 format.');
        }

        $sanitized['delivery_address_postal'] = $normalize_postal($sanitized['delivery_address_postal'] ?? '');
        if ($sanitized['delivery_address_postal'] !== '' && !preg_match($postal_pattern, $sanitized['delivery_address_postal'])) {
            $record_format_error('delivery_address_postal', 'Delivery postal code must be in A1A1A1 format.');
        }

        // Phone
        $phonePattern = '/^\(\d{3}\)-\d{3}-\d{4}$/';
        if (!empty($sanitized['phone_primary']) && !preg_match($phonePattern, $sanitized['phone_primary'])) {
            $record_format_error('phone_primary', 'Phone number must be in (###)-###-#### format.');
        }

        if (!empty($sanitized['phone_secondary']) && !preg_match($phonePattern, $sanitized['phone_secondary'])) {
            $record_format_error('phone_secondary', 'Client phone #2 must be in (###)-###-#### format.');
        }

        if (!empty($sanitized['alt_contact_phone_primary']) && !preg_match($phonePattern, $sanitized['alt_contact_phone_primary'])) {
            $record_format_error('alt_contact_phone_primary', 'Alternate contact phone #1 must be in (###)-###-#### format.');
        }

        if (!empty($sanitized['alt_contact_phone_secondary']) && !preg_match($phonePattern, $sanitized['alt_contact_phone_secondary'])) {
            $record_format_error('alt_contact_phone_secondary', 'Alternate contact phone #2 must be in (###)-###-#### format.');
        }

        // Email
        if (!empty($sanitized['client_email']) && !filter_var($sanitized['client_email'], FILTER_VALIDATE_EMAIL)) {
            $record_format_error('client_email', 'Invalid client email address.');
        }

        if (!empty($sanitized['social_worker_email']) && !filter_var($sanitized['social_worker_email'], FILTER_VALIDATE_EMAIL)) {
            $record_format_error('social_worker_email', 'Invalid social worker email address.');
        }

        if (!empty($sanitized['alt_contact_email']) && !filter_var($sanitized['alt_contact_email'], FILTER_VALIDATE_EMAIL)) {
            $record_format_error('alt_contact_email', 'Invalid alternate contact email address.');
        }

        // Required fields based on client type configuration.
        $client_type = strtoupper(trim($sanitized['client_type'] ?? ''));

        if ($client_type === 'STAFF') {
            $record_format_error('client_type', __('Staff clients are managed via the Staff Directory.', 'meals-db'));
        }

        $required_fields = self::get_required_fields_for_type($client_type);

        $initials_value = strtoupper(trim((string) ($sanitized['delivery_initials'] ?? '')));
        $requires_delivery_initials = true;

        if ($initials_value === '') {
            if ($requires_delivery_initials) {
                $record_required_error('delivery_initials');
            } else {
                $sanitized['delivery_initials'] = null;
            }
        } else {
            // Use new validator with address data for address-based duplicate checking
            $validation = MealsDB_Initials::validate_code($initials_value, $ignore_client_id, $sanitized);
            if (empty($validation['valid'])) {
                $message = $validation['message'] ?? __('Invalid initials for delivery.', 'meals-db');
                $record_format_error('delivery_initials', $message);
            } else {
                $sanitized['delivery_initials'] = $initials_value;
                // Log if initials are being shared at the same address
                if (!empty($validation['shared'])) {
                    error_log('[MealsDB] Delivery initials ' . $initials_value . ' are shared at the same address: ' . $validation['message']);
                }
            }
        }

        foreach ($required_fields as $field) {
            if (empty($sanitized[$field] ?? '')) {
                $record_required_error($field);
            }
        }

        if (($sanitized['wordpress_user_id'] ?? '') !== '') {
            $wp_id_value = $sanitized['wordpress_user_id'];
            if (!ctype_digit($wp_id_value) || (int) $wp_id_value <= 0) {
                $record_format_error('wordpress_user_id', 'WordPress User ID must be a positive integer.');
            }
        }

        if (isset($sanitized['units']) && $sanitized['units'] !== '') {
            $units = (int) $sanitized['units'];
            if ($units < 1 || $units > 31) {
                $record_format_error('units', '# of units must be between 1 and 31.');
            }
        }

        $enum_validations = self::get_enum_validation_rules();

        foreach ($enum_validations as $field => $rules) {
            if (!array_key_exists($field, $sanitized)) {
                continue;
            }

            $value = $sanitized[$field];
            if ($value === '' || empty($rules['allowed'])) {
                continue;
            }

            $normalized = $value;
            if (($rules['normalize'] ?? '') === 'upper') {
                $normalized = strtoupper($value);
            } elseif (($rules['normalize'] ?? '') === 'lower') {
                $normalized = strtolower($value);
            }

            if (!in_array($normalized, $rules['allowed'], true)) {
                $record_format_error($field, $rules['message']);
            }
        }

        $numeric_fields = [
            'ordering_frequency' => 'Ordering frequency must be a number.',
            'delivery_frequency' => 'Delivery frequency must be a number.',
            'freezer_capacity'   => 'Freezer capacity must be a number.',
            'delivery_fee'       => 'Delivery fee must be a number.',
        ];

        foreach ($numeric_fields as $field => $message) {
            if (!array_key_exists($field, $sanitized)) {
                continue;
            }

            $value = $sanitized[$field];
            if ($value === '') {
                continue;
            }

            if (!is_numeric($value)) {
                $record_format_error($field, $message);
            }
        }

        // Financial field range validation
        $financial_fields = [
            'rate' => ['min' => 0, 'max' => 10000, 'message' => 'Rate must be between $0 and $10,000.'],
            'client_contribution' => ['min' => 0, 'max' => 1000, 'message' => 'Client contribution must be between $0 and $1,000.'],
            'delivery_fee' => ['min' => 0, 'max' => 100, 'message' => 'Delivery fee must be between $0 and $100.'],
        ];

        foreach ($financial_fields as $field => $rules) {
            if (!array_key_exists($field, $sanitized)) {
                continue;
            }

            $value = $sanitized[$field];
            if ($value === '') {
                continue;
            }

            if (!is_numeric($value)) {
                continue; // Already handled by numeric validation
            }

            $numeric_value = floatval($value);
            if ($numeric_value < $rules['min'] || $numeric_value > $rules['max']) {
                $record_format_error($field, $rules['message']);
            }
        }

        // Input length validation
        $max_lengths = [
            'first_name' => 100,
            'last_name' => 100,
            'client_email' => 255,
            'diet_concerns' => 5000,
            'client_comments' => 5000,
            'delivery_address' => 500,
            'delivery_city' => 100,
            'delivery_postal' => 20,
            'individual_id' => 50,
            'requisition_id' => 50,
        ];

        foreach ($max_lengths as $field => $max) {
            if (!array_key_exists($field, $sanitized)) {
                continue;
            }

            $value = $sanitized[$field];
            if ($value === '') {
                continue;
            }

            if (strlen($value) > $max) {
                $field_label = self::get_field_label($field);
                $record_format_error($field, sprintf('%s must be less than %d characters.', $field_label, $max));
            }
        }

        // Unique field checks
        $conflicts = self::check_unique_fields($sanitized, $ignore_client_id);
        if (!empty($conflicts)) {
            $error_details['duplicates'] = $conflicts;
            $errors = array_merge($errors, $conflicts);
        }

        $summary_parts = [];
        if (!empty($error_details['missing_required'])) {
            $labels = array_map(static function ($detail) {
                return $detail['label'] ?? '';
            }, $error_details['missing_required']);
            $labels = array_filter(array_unique($labels));
            if (!empty($labels)) {
                $summary_parts[] = sprintf(
                    'Missing required fields: %s.',
                    self::human_join(array_values($labels))
                );
            }
        }

        if (!empty($error_details['invalid_format'])) {
            $labels = array_map(static function ($detail) {
                return $detail['label'] ?? '';
            }, $error_details['invalid_format']);
            $labels = array_filter(array_unique($labels));
            if (!empty($labels)) {
                $summary_parts[] = sprintf(
                    'Formatting issues detected in: %s.',
                    self::human_join(array_values($labels))
                );
            }
        }

        $error_summary = trim(implode(' ', array_filter($summary_parts)));

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized' => $sanitized,
            'error_summary' => $error_summary,
            'error_details' => $error_details,
        ];
    }

    /**
     * Determine the required fields for the supplied client type.
     */
    private static function get_required_fields_for_type(string $client_type): array {
        $client_type = strtoupper(trim($client_type));

        $base_required = ['client_type'];

        $type_specific = [
            'PRIVATE' => [
                'first_name',
                'last_name',
                'phone_primary',
                'address_street_name',
                'address_city',
                'address_province',
                'address_postal',
                'delivery_day',
                'payment_method',
            ],
            'SDNB' => [
                'first_name',
                'last_name',
                'phone_primary',
                'vendor_number',
                'service_center_charged',
                'service_id',
                'requisition_period',
                'rate',
                'payment_method',
            ],
            'VETERAN' => [
                'first_name',
                'last_name',
                'phone_primary',
                'requisition_period',
                'vet_health_card',
                'payment_method',
            ],
        ];

        if ($client_type !== '' && isset($type_specific[$client_type])) {
            $required = array_merge($base_required, $type_specific[$client_type]);
        } else {
            // Fallback ensures the most common name fields remain required even for unknown types.
            $required = array_merge($base_required, ['first_name', 'last_name']);
        }

        return array_values(array_unique($required));
    }

    /**
     * Retrieve a human-readable label for a field.
     */
    private static function get_field_label(string $field, ?string $fallback = null): string {
        if (isset(self::$field_labels[$field])) {
            return self::$field_labels[$field];
        }

        if ($fallback !== null) {
            return $fallback;
        }

        $normalized = str_replace('_', ' ', $field);

        return ucwords($normalized);
    }

    /**
     * Produce a grammatically correct list (with commas and "and").
     */
    private static function human_join(array $items): string {
        $items = array_values(array_filter($items, static function ($value) {
            return $value !== null && $value !== '';
        }));

        $count = count($items);
        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return (string) $items[0];
        }

        if ($count === 2) {
            return $items[0] . ' and ' . $items[1];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ', and ' . $last;
    }

    /**
     * Prepare sanitized defaults for re-populating the admin form.
     *
     * @param array $data
     * @return array
     */
    public static function prepare_form_defaults(array $data): array {
        $unknown_keys = [];

        return self::sanitize_payload($data, $unknown_keys);
    }

    /**
     * Reduce an arbitrary form payload (e.g. parse_str output) to the
     * known DB columns + sanitised values, preserving array-typed fields
     * for round-trip display in resumed drafts.
     *
     * Used by the drafts AJAX endpoint to defend against parse_str's
     * willingness to populate the target with attacker-named keys.
     */
    public static function filter_to_known_fields(array $data): array {
        $unknown_keys = [];

        return self::sanitize_payload($data, $unknown_keys, true);
    }

    /**
     * Bound the longest user-supplied fields BEFORE encryption.
     *
     * The full validate() pass includes the same length checks, but
     * save() / update() accept already-validated data — and they also
     * accept data that HASN'T been validated (draft-resume paths,
     * callers that sanitize but skip validate). An unvalidated 10MB
     * diet_concerns would otherwise get AES-CBC'd before any reject
     * happens. The bounds here match the validate() table.
     */
    private static function payload_within_length_bounds(array $row): bool {
        $max_lengths = [
            'first_name'        => 100,
            'last_name'         => 100,
            'client_email'      => 255,
            'diet_concerns'     => 5000,
            'client_comments'   => 5000,
            'customer_comments' => 5000, // DB-side alias
            'individual_id'     => 50,
            'requisition_id'    => 50,
        ];
        foreach ($max_lengths as $field => $max) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $value = $row[$field];
            if (!is_scalar($value)) {
                continue;
            }
            if (strlen((string) $value) > $max) {
                return false;
            }
        }
        return true;
    }

    /**
     * Defence-in-depth capability gate for client writes.
     *
     * views/add-client.php and views/edit-client.php already call
     * MealsDB_Permissions::enforce() + check_admin_referer() before
     * reaching save()/update(). This guard exists so a future caller
     * (WP-CLI command, REST endpoint, import script) that reaches
     * these methods without going through the view layer can't write
     * to meals_clients without the plugin's required capability.
     *
     * Returns true when the permission layer or WP functions aren't
     * loaded (bootstrap, test fixtures) so unit tests exercising
     * the form logic directly still work.
     */
    private static function is_authorized_to_modify_clients(): bool {
        if (!class_exists('MealsDB_Permissions')
            || !function_exists('is_user_logged_in')
            || !function_exists('current_user_can')) {
            return true;
        }

        if (MealsDB_Permissions::can_access_plugin()) {
            return true;
        }

        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        error_log(sprintf('[MealsDB Client_Form] Unauthorized client write attempt by user_id=%d', $user_id));

        return false;
    }

    /**
     * Save client data to meals_clients table.
     *
     * @param array $data
     * @return bool
     */
    public static function save(array $data): bool {
        if (!self::is_authorized_to_modify_clients()) {
            return false;
        }

        global $wpdb;
        if (!$wpdb) return false;

        $unknown_keys = [];
        $sanitized = self::sanitize_payload($data, $unknown_keys);

        if (!empty($unknown_keys)) {
            error_log('[MealsDB] Save aborted due to unknown fields: ' . implode(', ', $unknown_keys));
            return false;
        }

        if (empty($sanitized)) {
            error_log('[MealsDB] Save aborted: no valid data provided.');
            return false;
        }

        // Fast-fail on oversized inputs BEFORE encryption. A hostile
        // or sloppy caller that hands over a 10MB diet_concerns or
        // customer_comments would otherwise burn the worker's AES-CBC
        // cycles on the huge payload before any validation rejects
        // it. Reject early with the same bound the full validate()
        // pass uses.
        if (!self::payload_within_length_bounds($sanitized)) {
            error_log('[MealsDB] Save aborted: input exceeds length bounds before encryption.');
            return false;
        }

        if (array_key_exists('wordpress_user_id', $sanitized) && $sanitized['wordpress_user_id'] === '') {
            unset($sanitized['wordpress_user_id']);
        }

        // Map form field names to database column names
        $sanitized = self::map_form_to_db($sanitized);

        $sanitized = self::apply_insert_defaults($sanitized);

        $encrypted = $sanitized;
        if (!self::ensure_index_columns_exist()) {
            error_log('[MealsDB] Save aborted: deterministic index columns are unavailable.');
            return false;
        }

        // Store deterministic hashes for unique fields
        foreach (self::$deterministic_index_map as $field => $indexColumn) {
            if (array_key_exists($field, $sanitized) && $sanitized[$field] !== '') {
                $encrypted[$indexColumn] = self::deterministic_hash($sanitized[$field]);
            }
        }

        // Format date fields (assume already validated) - using database column names after mapping
        $date_fields = ['birth_date', 'open_date', 'required_start_date', 'service_commence_date', 'expected_termination_date', 'initial_renewal_termination_date', 'termination_date', 'most_recent_renewal_termination_date'];
        foreach ($date_fields as $field) {
            if (!empty($encrypted[$field])) {
                $timestamp = strtotime($encrypted[$field]);
                if ($timestamp) {
                    $encrypted[$field] = date('Y-m-d', $timestamp);
                }
            } elseif (isset($encrypted[$field])) {
                unset($encrypted[$field]);
            }
        }

        if (isset($encrypted['units']) && $encrypted['units'] === '') {
            unset($encrypted['units']);
        }

        try {
            $encrypted = MealsDB_Encryption::encrypt_columns($encrypted);
        } catch (\Throwable $e) {
            error_log('[MealsDB] Save aborted: encryption failure (' . $e->getMessage() . ').');
            return false;
        }

        $repository = new MealsDB_Clients_Repository();

        return $repository->create_client($encrypted);
    }

    /**
     * Update an existing client record.
     *
     * @param int   $client_id
     * @param array $data
     * @return bool
     */
    public static function update(int $client_id, array $data): bool {
        if (!self::is_authorized_to_modify_clients()) {
            return false;
        }

        if ($client_id <= 0) {
            return false;
        }

        global $wpdb;
        if (!$wpdb) {
            return false;
        }

        $unknown_keys = [];
        $sanitized = self::sanitize_payload($data, $unknown_keys);

        if (!empty($unknown_keys)) {
            error_log('[MealsDB] Update aborted due to unknown fields: ' . implode(', ', $unknown_keys));
            return false;
        }

        if (empty($sanitized)) {
            error_log('[MealsDB] Update aborted: no valid data provided.');
            return false;
        }

        // Length-bound before encryption — see save() for rationale.
        if (!self::payload_within_length_bounds($sanitized)) {
            error_log('[MealsDB] Update aborted: input exceeds length bounds before encryption.');
            return false;
        }

        if (array_key_exists('wordpress_user_id', $sanitized) && $sanitized['wordpress_user_id'] === '') {
            $sanitized['wordpress_user_id'] = null;
        }

        // Map form field names to database column names
        $sanitized = self::map_form_to_db($sanitized);

        $encrypted = $sanitized;
        if (!self::ensure_index_columns_exist()) {
            error_log('[MealsDB] Update aborted: deterministic index columns are unavailable.');
            return false;
        }

        foreach (self::$deterministic_index_map as $field => $indexColumn) {
            if (array_key_exists($field, $sanitized)) {
                if ($sanitized[$field] !== '') {
                    $encrypted[$indexColumn] = self::deterministic_hash($sanitized[$field]);
                } else {
                    $encrypted[$indexColumn] = null;
                }
            }
        }

        // Using database column names after mapping
        $date_fields = ['birth_date', 'open_date', 'required_start_date', 'service_commence_date', 'expected_termination_date', 'initial_renewal_termination_date', 'termination_date', 'most_recent_renewal_termination_date'];
        foreach ($date_fields as $field) {
            if (array_key_exists($field, $encrypted)) {
                if (!empty($encrypted[$field])) {
                    $timestamp = strtotime((string) $encrypted[$field]);
                    if ($timestamp) {
                        $encrypted[$field] = date('Y-m-d', $timestamp);
                    }
                } elseif ($encrypted[$field] === '' || $encrypted[$field] === null) {
                    $encrypted[$field] = null;
                }
            }
        }

        if (array_key_exists('units', $encrypted) && $encrypted['units'] === '') {
            $encrypted['units'] = null;
        }

        try {
            $encrypted = MealsDB_Encryption::encrypt_columns($encrypted);
        } catch (\Throwable $e) {
            error_log('[MealsDB] Update aborted: encryption failure (' . $e->getMessage() . ').');
            return false;
        }

        $columns = array_keys($encrypted);
        if (empty($columns)) {
            return false;
        }

        $repository = new MealsDB_Clients_Repository();

        return $repository->update_client($client_id, $encrypted);
    }

    /**
     * Load an existing client record for editing.
     *
     * @param int $client_id
     * @return array|null
     */
    public static function load_client(int $client_id): ?array {
        if ($client_id <= 0) {
            return null;
        }

        global $wpdb;
        if (!$wpdb) {
            return null;
        }

        $repository = new MealsDB_Clients_Repository();
        $record = $repository->get_client_by_id($client_id);

        if (empty($record)) {
            return null;
        }

        foreach (self::$deterministic_index_map as $indexColumn) {
            if (array_key_exists($indexColumn, $record)) {
                unset($record[$indexColumn]);
            }
        }

        // Decrypt encrypted columns BEFORE mapping DB column names to form
        // field names. Use the canonical DB-column list (note: form-side
        // `client_comments` is `customer_comments` in the DB).
        // safe_decrypt returns the original value on failure (legacy plaintext).
        foreach (MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS as $column) {
            if (!empty($record[$column]) && is_string($record[$column])) {
                $record[$column] = MealsDB_Encryption::safe_decrypt($record[$column]);
            }
        }

        // Map database column names to form field names
        $db_to_form_map = [
            'wp_user_id' => 'wordpress_user_id',
            'client_phone_1' => 'phone_primary',
            'client_phone_2' => 'phone_secondary',
            'assigned_worker_name' => 'assigned_social_worker',
            'assigned_worker_email' => 'social_worker_email',
            'street_name' => 'address_street_name',
            'city' => 'address_city',
            'province' => 'address_province',
            'postal_code' => 'address_postal',
            'delivery_street_name' => 'delivery_address_street_name',
            'delivery_city' => 'delivery_address_city',
            'delivery_province' => 'delivery_address_province',
            'delivery_postal_code' => 'delivery_address_postal',
            'alternate_contact_name' => 'alt_contact_name',
            'alternate_contact_phone_1' => 'alt_contact_phone_primary',
            'alternate_contact_phone_2' => 'alt_contact_phone_secondary',
            'alternate_contact_email' => 'alt_contact_email',
            'service_name_zone' => 'service_zone',
            'initial_renewal_termination_date' => 'initial_renewal_date',
            'most_recent_renewal_termination_date' => 'most_recent_renewal_date',
            'notes_to_service_provider' => 'per_sdnb_req',
            'customer_comments' => 'client_comments',
        ];

        // Apply the mapping
        foreach ($db_to_form_map as $db_col => $form_field) {
            if (array_key_exists($db_col, $record)) {
                $record[$form_field] = $record[$db_col];
                unset($record[$db_col]);
            }
        }

        return $record;
    }

    /**
     * Map form field names to database column names.
     *
     * @param array $data Data with form field names
     * @return array Data with database column names
     */
    private static function map_form_to_db(array $data): array {
        $form_to_db_map = [
            'wordpress_user_id' => 'wp_user_id',
            'phone_primary' => 'client_phone_1',
            'phone_secondary' => 'client_phone_2',
            'assigned_social_worker' => 'assigned_worker_name',
            'social_worker_email' => 'assigned_worker_email',
            'address_street_name' => 'street_name',
            'address_city' => 'city',
            'address_province' => 'province',
            'address_postal' => 'postal_code',
            'delivery_address_street_name' => 'delivery_street_name',
            'delivery_address_city' => 'delivery_city',
            'delivery_address_province' => 'delivery_province',
            'delivery_address_postal' => 'delivery_postal_code',
            'alt_contact_name' => 'alternate_contact_name',
            'alt_contact_phone_primary' => 'alternate_contact_phone_1',
            'alt_contact_phone_secondary' => 'alternate_contact_phone_2',
            'alt_contact_email' => 'alternate_contact_email',
            'service_zone' => 'service_name_zone',
            'initial_renewal_date' => 'initial_renewal_termination_date',
            'most_recent_renewal_date' => 'most_recent_renewal_termination_date',
            'per_sdnb_req' => 'notes_to_service_provider',
            'client_comments' => 'customer_comments',
        ];

        $mapped = [];
        foreach ($data as $key => $value) {
            if (isset($form_to_db_map[$key])) {
                $mapped[$form_to_db_map[$key]] = $value;
            } else {
                $mapped[$key] = $value;
            }
        }

        return $mapped;
    }

    /**
     * Save a draft of the form submission.
     *
     * @param array    $data
     * @param int|null $draft_id Existing draft ID to update, or null to insert a new draft
     * @return int|false Draft identifier on success, false on failure
     */
    public static function save_draft(array $data, ?int $draft_id = null) {
        global $wpdb;
        if (!$wpdb) {
            error_log('[MealsDB] Draft save aborted: database connection unavailable.');
            return false;
        }

        $drafts_table = MealsDB_DB::get_table_name(MealsDB_Tables::DRAFTS);

        if ($draft_id === null && isset($data['draft_id'])) {
            $draft_id = intval($data['draft_id']);
        }

        unset($data['draft_id'], $data['resume_draft']);

        $json = self::encode_draft_payload($data);
        if ($json === false) {
            error_log('[MealsDB] Draft save failed: unable to encode payload.');
            return false;
        }

        $user_id = get_current_user_id();

        if ($draft_id && $draft_id > 0) {
            if (!self::draft_exists($draft_id)) {
                error_log('[MealsDB] Draft update failed: draft ID ' . $draft_id . ' not found.');
                return false;
            }

            if (!self::draft_exists($draft_id, $user_id)) {
                error_log('[MealsDB] Draft update failed: user ' . $user_id . ' does not own draft ID ' . $draft_id . '.');
                return false;
            }

            $sql = $wpdb->prepare(
                "UPDATE `{$drafts_table}` SET data = %s WHERE id = %d AND created_by = %d",
                $json,
                $draft_id,
                $user_id
            );

            $result = $wpdb->query($sql);
            if ($result === false) {
                error_log('[MealsDB] Draft update failed to execute: ' . $wpdb->last_error);
                return false;
            }

            return $draft_id;
        }

        $sql = $wpdb->prepare(
            "INSERT INTO `{$drafts_table}` (data, created_by) VALUES (%s, %d)",
            $json,
            $user_id
        );

        $result = $wpdb->query($sql);
        if ($result === false) {
            error_log('[MealsDB] Draft save failed to execute: ' . $wpdb->last_error);
            return false;
        }

        $new_id = intval($wpdb->insert_id ?? 0);

        if ($new_id <= 0) {
            error_log('[MealsDB] Draft save failed: unable to determine inserted ID.');
            return false;
        }

        return $new_id;
    }

    /**
     * Delete a draft from storage.
     *
     * @param int $draft_id
     * @return bool
     */
    public static function delete_draft(int $draft_id): bool {
        if ($draft_id <= 0) {
            return false;
        }

        global $wpdb;
        if (!$wpdb) {
            error_log('[MealsDB] Draft delete aborted: database connection unavailable.');
            return false;
        }

        $drafts_table = MealsDB_DB::get_table_name(MealsDB_Tables::DRAFTS);

        if (!self::draft_exists($draft_id)) {
            error_log('[MealsDB] Draft delete failed: draft ID ' . $draft_id . ' not found.');
            return false;
        }

        $user_id = get_current_user_id();

        if (!self::draft_exists($draft_id, $user_id)) {
            error_log('[MealsDB] Draft delete failed: user ' . $user_id . ' does not own draft ID ' . $draft_id . '.');
            return false;
        }

        $sql = $wpdb->prepare(
            "DELETE FROM `{$drafts_table}` WHERE id = %d AND created_by = %d",
            $draft_id,
            $user_id
        );

        $result = $wpdb->query($sql);
        if ($result === false) {
            error_log('[MealsDB] Draft delete failed to execute: ' . $wpdb->last_error);
            return false;
        }

        $affected = $wpdb->rows_affected;

        if ($affected <= 0) {
            error_log('[MealsDB] Draft delete failed: draft ID ' . $draft_id . ' could not be removed.');
            return false;
        }

        return true;
    }

    /**
     * Encode a draft payload for storage.
     *
     * Drafts contain the same PII columns the live record encrypts, so
     * encrypt the JSON envelope under the same key. Falls back to plain
     * JSON when encryption is unavailable (e.g. test fixtures without a
     * configured key) so saving still works in those environments.
     *
     * @return string|false
     */
    private static function encode_draft_payload(array $data) {
        $json = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
        if (!is_string($json)) {
            return false;
        }
        if (!class_exists('MealsDB_Encryption')) {
            return $json;
        }
        try {
            return MealsDB_Encryption::encrypt($json);
        } catch (\Throwable $e) {
            error_log('[MealsDB] Draft encrypt fallback to plaintext: ' . $e->getMessage());
            return $json;
        }
    }

    /**
     * Decode a stored draft payload, supporting:
     *   - new format (encrypted base64),
     *   - legacy plaintext JSON written before encryption was added.
     *
     * Returns null if neither path produces a JSON object.
     */
    public static function decode_draft_payload(string $stored): ?array {
        if ($stored === '') {
            return null;
        }

        // Cheap shape check: legacy drafts are JSON objects/arrays so the
        // first non-whitespace character is { or [. Try that path first
        // to avoid invoking the encryption layer (and its legacy-decrypt
        // warning) on every read of a plaintext-era draft.
        $trimmed = ltrim($stored);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (class_exists('MealsDB_Encryption')) {
            $decrypted = MealsDB_Encryption::safe_decrypt($stored);
            if (is_string($decrypted) && $decrypted !== '' && $decrypted !== $stored) {
                $decoded = json_decode($decrypted, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    /**
     * Determine whether a draft exists.
     *
     * @param int       $draft_id
     * @param int|null  $owner_id Restrict check to a specific owner when provided.
     * @return bool
     */
    private static function draft_exists(int $draft_id, ?int $owner_id = null): bool {
        global $wpdb;
        if (!$wpdb) {
            return false;
        }

        $drafts_table = MealsDB_DB::get_table_name(MealsDB_Tables::DRAFTS);

        if ($owner_id !== null) {
            $sql = $wpdb->prepare(
                "SELECT id FROM `{$drafts_table}` WHERE id = %d AND created_by = %d LIMIT 1",
                $draft_id,
                $owner_id
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT id FROM `{$drafts_table}` WHERE id = %d LIMIT 1",
                $draft_id
            );
        }

        $row = $wpdb->get_var($sql);

        return $row !== null;
    }

    /**
     * Check for duplicate unique fields across clients.
     *
     * @param array $data
     * @return array List of friendly error messages
     */
    private static function check_unique_fields(array $data, ?int $exclude_id = null): array {
        global $wpdb;
        if (!$wpdb) return [];

        $indexes_ready = self::ensure_index_columns_exist();
        if (!$indexes_ready) {
            error_log('[MealsDB] Duplicate check skipped: deterministic index columns are unavailable.');
        }

        $errors = [];
        $repository = new MealsDB_Clients_Repository();

        foreach (self::$unique_fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            if ($value === '' || $value === null) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }

            $column = $field;
            $value_for_query = $value;

            if (isset(self::$deterministic_index_map[$field])) {
                if (!$indexes_ready) {
                    continue;
                }
                $column = self::$deterministic_index_map[$field];
                $value_for_query = self::deterministic_hash((string) $value);
            }

            $exists = $repository->column_value_exists($column, $value_for_query, $exclude_id);

            if ($exists) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' already exists in another client.';
            }
        }

        return $errors;
    }

    /**
     * Remove transport-only keys, sanitize values, and filter to known DB columns.
     *
     * @param array $data
     * @param array $unknown_keys
     * @return array
     */
    private static function sanitize_payload(array $data, array &$unknown_keys = [], bool $preserveArrays = false): array {
        if (function_exists('wp_unslash')) {
            $data = wp_unslash($data);
        }

        foreach (self::$transport_only_keys as $transport_key) {
            if (array_key_exists($transport_key, $data)) {
                unset($data[$transport_key]);
            }
        }

        $unknown_keys = array_values(array_diff(array_keys($data), self::$db_columns));

        $sanitized = [];
        foreach (self::$db_columns as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $sanitized[$column] = self::sanitize_value($column, $data[$column], $preserveArrays);
        }

        return $sanitized;
    }

    /**
     * Ensure required insert columns are present with safe defaults.
     */
    private static function apply_insert_defaults(array $values): array {
        foreach (self::$insert_required_defaults as $column => $default) {
            if (!array_key_exists($column, $values) || $values[$column] === null) {
                $values[$column] = $default;
            }
        }

        return $values;
    }

    /**
     * Sanitize a single value for storage.
     *
     * @param string $column
     * @param mixed  $value
     * @param bool   $preserveArrays Whether to keep array structures for transport data.
     * @return mixed
     */
    private static function sanitize_value(string $column, $value, bool $preserveArrays = false) {
        if (is_array($value)) {
            if ($preserveArrays) {
                $sanitized = [];
                foreach ($value as $key => $item) {
                    $sanitized[$key] = self::sanitize_value($column, $item, true);
                }

                return $sanitized;
            }

            $flattened = [];
            foreach ($value as $item) {
                $flattened[] = self::sanitize_scalar_value($column, $item);
            }

            return implode(',', $flattened);
        }

        return self::sanitize_scalar_value($column, $value);
    }

    /**
     * Sanitize a scalar value for storage.
     *
     * @param string $column
     * @param mixed  $value
     * @return string
     */
    private static function sanitize_scalar_value(string $column, $value): string {
        if (!is_scalar($value)) {
            $value = '';
        }

        $value = (string) $value;

        switch ($column) {
            case 'requisition_period':
                $value = trim($value);
                // Normalize plural forms to singular for database storage
                $normalized = strtoupper($value);
                if ($normalized === 'DAILY') {
                    $value = 'day';
                } elseif ($normalized === 'WEEKLY') {
                    $value = 'week';
                } elseif ($normalized === 'MONTHLY') {
                    $value = 'month';
                } else {
                    $value = strtolower($value);
                }
                break;
            case 'meal_type':
                $value = trim($value);
                // Normalize 'meal' to 'main' for database storage
                $normalized = strtolower($value);
                if ($normalized === 'meal') {
                    $value = 'main';
                } else {
                    $value = $normalized;
                }
                break;
            case 'service_zone':
                $value = trim($value);
                // Strip "zone " prefix if present (handles "zone A" -> "A")
                $value = preg_replace('/^zone\s*/i', '', $value);
                $value = strtoupper($value);
                break;
            case 'client_email':
            case 'social_worker_email':
            case 'alt_contact_email':
                if (function_exists('sanitize_email')) {
                    $value = sanitize_email($value);
                } else {
                    $value = trim(filter_var($value, FILTER_SANITIZE_EMAIL));
                }
                break;
            case 'wordpress_user_id':
                $value = trim($value);
                if ($value === '') {
                    break;
                }

                $digits = preg_replace('/[^0-9]/', '', $value);
                $digits = ltrim($digits, '0');
                $value = $digits === '' ? '' : $digits;
                break;
            case 'diet_concerns':
            case 'client_comments':
                if (function_exists('sanitize_textarea_field')) {
                    $value = sanitize_textarea_field($value);
                } else {
                    $value = trim($value);
                }
                break;
            case 'do_not_call_client_phone':
                $normalized = strtolower(trim($value));
                $value = in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true) ? '1' : '0';
                break;
            case 'units':
                $value = trim($value);
                if ($value === '') {
                    break;
                }

                $units = (int) $value;
                if ($units < 0) {
                    $units = 0;
                }
                if ($units > 31) {
                    $units = 31;
                }
                $value = (string) $units;
                break;
            default:
                if (function_exists('sanitize_text_field')) {
                    $value = sanitize_text_field($value);
                } else {
                    $value = trim($value);
                }
                break;
        }

        return $value;
    }

    /**
     * Retrieve the list of allowed options for a given enumerated field.
     */
    public static function get_allowed_options(string $field): array {
        $rules = self::get_enum_validation_rules();

        if (!isset($rules[$field]['allowed']) || !is_array($rules[$field]['allowed'])) {
            return [];
        }

        return array_values(array_unique(array_map('strval', $rules[$field]['allowed'])));
    }

    /**
     * Build the validation configuration for enumerated fields.
     */
    private static function get_enum_validation_rules(): array {
        $delivery_day_allowed = [
            'WED AM',
            'WED PM',
            'THURS AM',
            'THURS PM',
            'FRI AM',
        ];

        if (function_exists('apply_filters')) {
            $filtered_days = apply_filters('mealsdb_allowed_delivery_days', $delivery_day_allowed);
            if (is_array($filtered_days) && !empty($filtered_days)) {
                $delivery_day_allowed = $filtered_days;
            }
        }

        $delivery_day_allowed = array_values(array_filter(array_map(static function ($value) {
            return strtoupper(trim((string) $value));
        }, $delivery_day_allowed)));

        $contact_method_allowed = [
            'AUTO-RENEW',
            'BULK EMAIL',
            'PHONE',
        ];

        if (function_exists('apply_filters')) {
            $filtered_methods = apply_filters('mealsdb_allowed_contact_methods', $contact_method_allowed);
            if (is_array($filtered_methods) && !empty($filtered_methods)) {
                $contact_method_allowed = $filtered_methods;
            }
        }

        $contact_method_allowed = array_values(array_filter(array_map(static function ($value) {
            return strtoupper(trim((string) $value));
        }, $contact_method_allowed)));

        return [
            'gender' => [
                'allowed'   => ['MALE', 'FEMALE', 'OTHER'],
                'normalize' => 'upper',
                'message'   => 'Gender must be Male, Female, or Other.',
            ],
            'service_zone' => [
                'allowed'   => ['A', 'B', 'ZONE A', 'ZONE B'],
                'normalize' => 'upper',
                'message'   => 'Service zone must be either A or B.',
            ],
            'meal_type' => [
                'allowed'   => ['MAIN', 'MAIN+SIDE', 'MEAL'],
                'normalize' => 'upper',
                'message'   => 'Meal type must be either Main or Main+Side.',
            ],
            'requisition_period' => [
                'allowed'   => ['DAY', 'DAILY', 'WEEK', 'WEEKLY', 'MONTH', 'MONTHLY'],
                'normalize' => 'upper',
                'message'   => 'Requisition period must be Day/Daily, Week/Weekly, or Month/Monthly.',
            ],
            'delivery_day' => [
                'allowed'   => $delivery_day_allowed,
                'normalize' => 'upper',
                'message'   => 'Delivery day must match one of the scheduled options.',
            ],
            'ordering_contact_method' => [
                'allowed'   => $contact_method_allowed,
                'normalize' => 'upper',
                'message'   => 'Ordering contact method must be a supported option.',
            ],
        ];
    }

    /**
     * Ensure the deterministic index columns exist on the meals_clients table.
     *
     * @return bool
     */
    private static function ensure_index_columns_exist(): bool {
        if (empty(self::$deterministic_index_map)) {
            self::$indexes_ensured = true;
            return true;
        }

        if (self::$indexes_ensured) {
            return true;
        }

        global $wpdb;
        if (!$wpdb) {
            return false;
        }

        $allEnsured = true;
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        foreach (self::$deterministic_index_map as $indexColumn) {
            // INFORMATION_SCHEMA with both identifiers bound as %s. The
            // previous SHOW COLUMNS … LIKE '{$escaped}' path used
            // _real_escape(), which doesn't neutralise LIKE wildcards,
            // and interpolated the result into a raw SQL string.
            $columnExists = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND COLUMN_NAME = %s
                 LIMIT 1",
                $clients_table,
                $indexColumn
            ));

            if (!$columnExists && $wpdb->last_error !== '') {
                error_log('[MealsDB] Failed to inspect deterministic index column: ' . $wpdb->last_error);
                $allEnsured = false;
                continue;
            }

            if (!$columnExists) {
                $addColumnSql = "ALTER TABLE `{$clients_table}` ADD COLUMN `{$indexColumn}` CHAR(64) NULL";
                if ($wpdb->query($addColumnSql) === false) {
                    error_log('[MealsDB] Failed to add deterministic index column: ' . $wpdb->last_error);
                    $allEnsured = false;
                    continue;
                }
                $columnExists = true;
            }

            $indexName = 'unique_' . $indexColumn;
            $escapedIndex = $wpdb->_real_escape($indexName);

            $legacyIndexName = 'idx_' . $indexColumn;
            $escapedLegacy = $wpdb->_real_escape($legacyIndexName);

            $legacyIndexRows = $wpdb->get_results("SHOW INDEX FROM `{$clients_table}` WHERE Key_name = '{$escapedLegacy}'", ARRAY_A);
            $legacyIndexExists = is_array($legacyIndexRows) && count($legacyIndexRows) > 0;

            if ($legacyIndexExists) {
                $dropResult = $wpdb->query("ALTER TABLE `{$clients_table}` DROP INDEX `{$legacyIndexName}`");
                if ($dropResult === false) {
                    // Check if the error is "index doesn't exist" (errno 1091) by inspecting last_error
                    if (strpos($wpdb->last_error, '1091') === false) {
                        error_log('[MealsDB] Failed to drop legacy deterministic index: ' . $wpdb->last_error);
                        $allEnsured = false;
                    }
                }
            }

            $indexExists = false;
            $indexIsUnique = false;
            $indexRows = $wpdb->get_results("SHOW INDEX FROM `{$clients_table}` WHERE Key_name = '{$escapedIndex}'", ARRAY_A);
            if (is_array($indexRows)) {
                foreach ($indexRows as $row) {
                    $indexExists = true;
                    if (isset($row['Non_unique']) && intval($row['Non_unique']) === 0) {
                        $indexIsUnique = true;
                        break;
                    }
                }
            } else {
                error_log('[MealsDB] Failed to inspect deterministic index status: ' . $wpdb->last_error);
                $allEnsured = false;
                continue;
            }

            if ($indexExists && !$indexIsUnique) {
                if ($wpdb->query("ALTER TABLE `{$clients_table}` DROP INDEX `{$indexName}`") === false) {
                    error_log('[MealsDB] Failed to drop non-unique deterministic index: ' . $wpdb->last_error);
                    $allEnsured = false;
                } else {
                    $indexExists = false;
                }
            }

            if (!$indexExists) {
                $createIndexSql = "CREATE UNIQUE INDEX `{$indexName}` ON `{$clients_table}` (`{$indexColumn}`)";
                if ($wpdb->query($createIndexSql) === false) {
                    // ignore duplicate index error (1061)
                    if (strpos($wpdb->last_error, '1061') === false) {
                        error_log('[MealsDB] Failed to create deterministic index: ' . $wpdb->last_error);
                        $allEnsured = false;
                    }
                }
                [$indexExists, $indexIsUnique] = self::deterministic_index_status($indexName);
                if (!$indexExists || !$indexIsUnique) {
                    $allEnsured = false;
                }
            }
        }

        if ($allEnsured && self::backfill_deterministic_indexes()) {
            self::$indexes_ensured = true;
            return true;
        }

        return false;
    }

    /**
     * Backfill deterministic hash columns for legacy records lacking values.
     *
     * @return bool
     */
    /**
     * Populate empty deterministic index columns by hashing existing data.
     *
     * Runs once per request after ensure_index_columns_exist() detects
     * a newly added index column. The normal save()/update() paths
     * populate indexes from plaintext input; this backfill exists for
     * the edge case where an index column is added to a table that
     * already has data.
     *
     * BUG HISTORY: A previous implementation had two compounding bugs:
     *   1. Used WHERE id = %d, but the primary key on meals_clients is
     *      client_id. Every UPDATE failed with "Unknown column 'id'".
     *   2. Hashed ciphertext for encrypted columns. Future uniqueness
     *      queries (check_unique_fields) hash the plaintext input from
     *      the form, so a ciphertext-hash index can never match. The
     *      backfill was effectively dead — never executed against real
     *      data because the schema created index columns at install
     *      time. But any future schema change adding a new index
     *      column would have hit both bugs simultaneously.
     *
     * The fix decrypts encrypted columns before hashing and uses
     * client_id as the WHERE column. Decrypt failures are skipped and
     * logged rather than written with a wrong hash.
     *
     * @return bool True if all backfills succeeded. False if any row
     *              failed (corrupted ciphertext, query error, etc.).
     *              Failed rows are logged via MealsDB_Logger::error.
     */
    private static function backfill_deterministic_indexes(): bool {
        if (empty(self::$deterministic_index_map)) {
            return true;
        }

        global $wpdb;
        if (!$wpdb) {
            return false;
        }

        $allSuccessful = true;
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $encrypted_columns = class_exists('MealsDB_Encryption')
            ? MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS
            : [];

        foreach (self::$deterministic_index_map as $field => $indexColumn) {
            $selectSql = "SELECT client_id, `{$field}` FROM `{$clients_table}` WHERE (`{$indexColumn}` IS NULL OR `{$indexColumn}` = '') AND `{$field}` IS NOT NULL AND `{$field}` <> ''";
            $rows = $wpdb->get_results($selectSql, ARRAY_A);

            if (!is_array($rows)) {
                if (class_exists('MealsDB_Logger')) {
                    MealsDB_Logger::error('[MealsDB Index Backfill] SELECT failed for ' . $field . ': ' . $wpdb->last_error);
                }
                $allSuccessful = false;
                continue;
            }

            $is_encrypted = in_array($field, $encrypted_columns, true);

            foreach ($rows as $row) {
                $rawValue = $row[$field] ?? '';
                $client_id = isset($row['client_id']) ? (int) $row['client_id'] : 0;

                if ($rawValue === null || $rawValue === '' || $client_id <= 0) {
                    if ($client_id <= 0) {
                        $allSuccessful = false;
                    }
                    continue;
                }

                if ($is_encrypted) {
                    // CRITICAL: SELECT returns ciphertext for encrypted
                    // columns. We must decrypt before hashing so the
                    // index aligns with check_unique_fields() which
                    // hashes the plaintext form input.
                    if (!class_exists('MealsDB_Encryption')) {
                        $allSuccessful = false;
                        continue;
                    }
                    $plaintext = MealsDB_Encryption::safe_decrypt((string) $rawValue);
                    // safe_decrypt returns the input unchanged on
                    // failure. If we got the source value back the
                    // decrypt didn't help — better to leave the
                    // index empty than write a wrong hash.
                    if ($plaintext === $rawValue) {
                        if (class_exists('MealsDB_Logger')) {
                            MealsDB_Logger::error('[MealsDB Index Backfill] Could not decrypt ' . $field . ' for client_id=' . $client_id . '; skipped');
                        }
                        $allSuccessful = false;
                        continue;
                    }
                    $hashValue = self::deterministic_hash($plaintext);
                } else {
                    $hashValue = self::deterministic_hash((string) $rawValue);
                }

                $updateResult = $wpdb->update(
                    $clients_table,
                    [$indexColumn => $hashValue],
                    ['client_id' => $client_id],
                    ['%s'],
                    ['%d']
                );

                if ($updateResult === false) {
                    if (class_exists('MealsDB_Logger')) {
                        MealsDB_Logger::error('[MealsDB Index Backfill] UPDATE failed for ' . $field . ' / client_id=' . $client_id . ': ' . $wpdb->last_error);
                    }
                    $allSuccessful = false;
                }
            }
        }

        return $allSuccessful;
    }

    /**
     * Generate a deterministic hash for comparison of encrypted fields.
     *
     * @param string $value
     * @return string
     */
    private static function deterministic_hash(string $value): string {
        return hash('sha256', strtolower(trim($value)));
    }

    /**
     * Inspect the status of a deterministic index.
     *
     * @param string $indexName
     * @return array{0: bool, 1: bool} [exists, isUnique]
     */
    private static function deterministic_index_status(string $indexName): array {
        global $wpdb;
        if (!$wpdb) {
            return [false, false];
        }

        $escapedIndex = $wpdb->_real_escape($indexName);

        $exists = false;
        $isUnique = false;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $rows = $wpdb->get_results("SHOW INDEX FROM `{$clients_table}` WHERE Key_name = '{$escapedIndex}'", ARRAY_A);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $exists = true;
                if (isset($row['Non_unique']) && intval($row['Non_unique']) === 0) {
                    $isUnique = true;
                    break;
                }
            }
        } else {
            error_log('[MealsDB] Failed to inspect deterministic index status: ' . $wpdb->last_error);
        }

        return [$exists, $isUnique];
    }
}
