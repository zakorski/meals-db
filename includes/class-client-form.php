<?php
defined('ABSPATH') || exit;

// NOTE: This file is gated by defined('ABSPATH') above and autoloaded
// after plugins_loaded fires, by which point WP's __() is defined. A
// previous version had a function_exists('__') fallback here that
// would have shadowed WP's __() if it ever loaded first — and once
// declared, that no-op fallback would win permanently (WP's __() is
// itself function_exists-guarded), silently returning English source
// strings for the rest of the request. The fallback was unnecessary
// in WP runtime and was a load-order footgun. Removed.

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
        'client_contribution',
        'use_legacy_billing',
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
     * Deterministic index columns that still carry a hard DB-level UNIQUE
     * constraint.
     *
     * Directive GUI-SAVE-INDEX (operator decision): individual_id_index and
     * requisition_id_index are DELIBERATELY excluded. A person legitimately
     * enrolled in two programs (audit MAJ-1 — a dual SDNB/Veteran recipient,
     * or a government client buying extra meals personally) shares a single
     * government identifier, so a hard UNIQUE constraint would block a real,
     * rare-but-valid case. Worse, on migrated data that already contains such
     * duplicates, CREATE UNIQUE INDEX fails (MySQL errno 1062) — which used to
     * fail-CLOSED and abort EVERY client save (create AND edit), the actual
     * cause of the "Database error occurred." on staging. Those two columns are
     * dedup-WARNED at data entry instead (see collect_unique_field_warnings):
     * the hash COLUMN is still populated for fast lookup; only the DB-level
     * constraint is dropped. vet_health_card / delivery_initials keep theirs.
     */
    private static $hard_unique_index_columns = [
        'vet_health_card_index',
        'delivery_initials_index',
    ];

    /**
     * Unique fields enforced as a non-blocking WARNING rather than a hard error.
     *
     * Directive GUI-SAVE-INDEX Part B: a matching individual_id / requisition_id
     * is surfaced to the operator (naming the other client) but the save is
     * allowed to proceed — the dual-program case is legitimate. Mirrors the
     * warn-not-block posture of the WP-user "already linked to client #N" check.
     */
    private static $warn_only_unique_fields = [
        'individual_id',
        'requisition_id',
    ];

    /**
     * Default values for required columns when inserting new clients.
     *
     * These MUST be DB-side column names. apply_insert_defaults() runs in
     * save() *after* map_form_to_db() (which renames phone_primary ->
     * client_phone_1, address_postal -> postal_code), so keying this map on
     * the form-side names was a real bug (directive GUI-F3F5): the form-side
     * keys were always absent post-mapping, so the defaults were injected as
     * PHANTOM columns (`phone_primary`, `address_postal`) that do not exist in
     * meals_clients. Every GUI create then failed at $wpdb->insert while edit
     * — which never calls apply_insert_defaults — worked. Keep these DB-side.
     */
    private static $insert_required_defaults = [
        'client_email'   => '',
        'client_phone_1' => '',
        'postal_code'    => '',
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

        // Province — must be a 2-letter Canadian code (sanitize already
        // normalises known full names to their code; anything left that is not
        // a recognised code is rejected here with a named field error instead
        // of overflowing VARCHAR(10) at insert. Directive GUI-F3F5.
        foreach (['address_province', 'delivery_address_province'] as $province_field) {
            if (!empty($sanitized[$province_field]) && !self::is_valid_province_code($sanitized[$province_field])) {
                $record_format_error(
                    $province_field,
                    sprintf('%s must be a 2-letter province code (e.g. NB).', self::get_field_label($province_field))
                );
            }
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

        if ($initials_value === '') {
            // Delivery initials are always required (directive GUI-INITIALS);
            // there is no longer a conditional "not required" path.
            $record_required_error('delivery_initials');
        } else {
            // Delivery initials are globally unique (directive GUI-INITIALS):
            // validate_code() rejects any code already used by another client.
            // There is no same-address sharing case to handle anymore.
            $validation = MealsDB_Initials::validate_code($initials_value, $ignore_client_id, $sanitized);
            if (empty($validation['valid'])) {
                $message = $validation['message'] ?? __('Invalid initials for delivery.', 'meals-db');
                $record_format_error('delivery_initials', $message);
            } else {
                $sanitized['delivery_initials'] = $initials_value;
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

        // Zone is the sole source of truth for delivery_day (spec
        // 2026-07-11): the zone itself must resolve. A blank zone is
        // handled by the existing required-field logic per client type;
        // a NON-blank zone that matches no schedule key would silently
        // drop the client off every packer/driver slip, so it is a hard
        // field error here.
        if (isset($sanitized['delivery_area_name']) && trim((string) $sanitized['delivery_area_name']) !== ''
            && class_exists('MealsDB_Zone_Day')
            && MealsDB_Zone_Day::day_for_zone((string) $sanitized['delivery_area_name']) === null) {
            $record_format_error('delivery_area_name', 'Delivery area does not match any configured delivery zone.');
        }

        $numeric_fields = [
            'ordering_frequency' => 'Ordering frequency must be a number.',
            'delivery_frequency' => 'Delivery frequency must be a number.',
            'freezer_capacity'   => 'Freezer capacity must be a number.',
            'delivery_fee'       => 'Delivery fee must be a number.',
            // client_contribution was missing here, so the range check below
            // (which does `if (!is_numeric($value)) continue;`) silently
            // skipped a non-numeric value like "$25.00"/"25,00". It then reached
            // the DECIMAL(10,2) insert and either failed with a generic
            // repository DB error (strict SQL) or coerced to 0.00 (non-strict) —
            // a per-client BILLING input becoming zero with no operator-visible
            // error. Reject non-numeric contribution up front.
            'client_contribution' => 'Client contribution must be a number.',
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
            // Address + contact fields. The previous keys
            // ('delivery_address'/'delivery_city'/'delivery_postal') matched NO
            // form field — sanitize_payload() only emits the form-side names in
            // self::$db_columns — so these VARCHAR(255) columns had ZERO length
            // validation and an over-long paste failed only at $wpdb->insert
            // ("Data too long") or truncated silently under non-strict SQL: the
            // exact GUI-F3F5 failure class this table's length checks exist to
            // prevent. Keys are now the real form-side names and widths mirror
            // class-schema.php exactly (street/city 255, postal 10). The main
            // address street/city and the two remaining email fields were never
            // guarded at all; added here.
            'address_street_name' => 255,
            'address_city' => 255,
            'delivery_address_street_name' => 255,
            'delivery_address_city' => 255,
            'delivery_address_postal' => 10,
            'alt_contact_name' => 255,
            'social_worker_email' => 255,
            'alt_contact_email' => 255,
            'individual_id' => 50,
            'requisition_id' => 50,
            // Phone + province were previously unmapped, so an over-long
            // value slipped past validation and only failed at $wpdb->insert
            // with a generic "Database error occurred." (directive GUI-F3F5).
            // The phone *format* rule below also catches over-long phones, but
            // listing them here gives a length-attributed message for the
            // length case and matches the column widths exactly.
            'phone_primary' => 20,
            'phone_secondary' => 20,
            'alt_contact_phone_primary' => 20,
            'alt_contact_phone_secondary' => 20,
            'address_province' => 10,
            'delivery_address_province' => 10,
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

        // Unique field checks. check_unique_fields() now returns HARD conflicts
        // only (vet_health_card / delivery_initials). individual_id and
        // requisition_id duplicates are non-blocking WARNINGS per directive
        // GUI-SAVE-INDEX Part B (dual-program enrollments legitimately share a
        // government ID), collected separately and surfaced without failing
        // validation.
        $conflicts = self::check_unique_fields($sanitized, $ignore_client_id);
        if (!empty($conflicts)) {
            $error_details['duplicates'] = $conflicts;
            $errors = array_merge($errors, $conflicts);
        }

        $warnings = self::collect_unique_field_warnings($sanitized, $ignore_client_id);

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
            'warnings' => $warnings,
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
            // delivery_day is zone-derived server-side (spec 2026-07-11);
            // delivery_area_name is what the operator must supply — it drives
            // the derivation.  delivery_day must NOT appear in this list.
            'PRIVATE' => [
                'first_name',
                'last_name',
                'phone_primary',
                'address_street_name',
                'address_city',
                'address_province',
                'address_postal',
                'delivery_area_name',
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
            // Phone / province length caps. Callers today (save()/update())
            // run this on sanitize_payload() output — form-side keys only —
            // BEFORE map_form_to_db(), so in practice only the form-side names
            // below ever match. The DB-side aliases are kept as cheap defence
            // for a future caller that reaches here with already-mapped data,
            // so an over-long value is fast-failed before it overflows its
            // column at $wpdb->insert (directive GUI-F3F5: province
            // "New Brunswick" overflowed VARCHAR(10); an unclamped phone
            // overflows VARCHAR(20)).
            'phone_primary'             => 20,
            'phone_secondary'           => 20,
            'alt_contact_phone_primary' => 20,
            'alt_contact_phone_secondary' => 20,
            'client_phone_1'            => 20,
            'client_phone_2'            => 20,
            'alternate_contact_phone_1' => 20,
            'alternate_contact_phone_2' => 20,
            'address_province'          => 10,
            'delivery_address_province' => 10,
            'province'                  => 10,
            'delivery_province'         => 10,
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

        // GUI-F3F5-v2 (was the ROOT cause of "Database error occurred." on
        // create): meals_clients.wp_user_id is BIGINT UNSIGNED NOT NULL with no
        // default — every client links to an existing WordPress user. The old
        // code silently UNSET a blank wordpress_user_id here, omitting the
        // column from the INSERT, which MySQL then rejected as a raw DB error
        // (the operator only saw "Database error occurred." and a stranded
        // draft). Validate the linkage authoritatively at save time — never
        // trusting the form's Validate button — and turn a missing / malformed
        // / nonexistent user into a named field error instead. create() and
        // update() now enforce this identically (removes the prior
        // unset-vs-null divergence).
        $wp_user_error = self::validate_wp_user_link($sanitized['wordpress_user_id'] ?? null);
        if ($wp_user_error !== '') {
            self::$last_save_error = $wp_user_error;
            error_log('[MealsDB] Save aborted: ' . $wp_user_error);
            return false;
        }

        // Map form field names to database column names
        $sanitized = self::map_form_to_db($sanitized);

        // delivery_day is a zone-derived cache — NEVER trust the posted
        // value (spec 2026-07-11). Derive from the selected zone; with a
        // blank zone, drop the key entirely rather than persist a stale
        // posted day. validate() has already rejected unresolvable
        // non-blank zones, but this path re-guards for callers that skip
        // the form flow (defense-in-depth, Pattern 1).
        $sanitized = self::apply_zone_delivery_day($sanitized);
        if ($sanitized === null) {
            self::$last_save_error = __('Delivery area does not match any configured delivery zone.', 'meals-db');
            error_log('[MealsDB] Save aborted: unresolvable delivery zone.');
            return false;
        }

        $sanitized = self::apply_insert_defaults($sanitized);

        $encrypted = $sanitized;
        if (!self::ensure_index_columns_exist()) {
            // Part C: this is now reached ONLY when a hash COLUMN is structurally
            // unavailable — a genuine failure, distinct from the (non-fatal)
            // unique-constraint case. Give the operator a clear, attributed
            // message instead of the bare "Database error occurred.", and make
            // the abort visible on the Event Log.
            error_log('[MealsDB] Save aborted: deterministic index columns are unavailable.');
            self::$last_save_error = __('Could not save: a database index column could not be prepared — please contact support.', 'meals-db');
            self::record_index_save_abort('save.index_columns_unavailable', 'Client create aborted: deterministic index columns could not be ensured.');
            return false;
        }

        // Store deterministic hashes for unique fields
        foreach (self::$deterministic_index_map as $field => $indexColumn) {
            if (array_key_exists($field, $sanitized) && $sanitized[$field] !== '') {
                $encrypted[$indexColumn] = self::deterministic_hash($sanitized[$field]);
            }
        }

        // Format date fields (assume already validated) - using database column names after mapping
        // next_order_date / next_delivery_date are DATE NULL columns in the
        // schema and are whitelisted in $db_columns, so a form submission (or a
        // direct save/update caller) can carry them — include them in the
        // normalization list so they get the same strtotime() + empty-string
        // handling as every other date column instead of an unnormalized value
        // (e.g. '' -> a strict-mode DATE insert failure). Today the client
        // add/edit UI does not render them (Quick Order manages them via
        // MealsDB_Client_Dates), but the whitelist makes them reachable.
        $date_fields = ['birth_date', 'open_date', 'required_start_date', 'service_commence_date', 'expected_termination_date', 'initial_renewal_termination_date', 'termination_date', 'most_recent_renewal_termination_date', 'next_order_date', 'next_delivery_date'];
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

        self::$last_save_error = '';

        $repository = new MealsDB_Clients_Repository();

        $created = $repository->create_client($encrypted);
        if (!$created) {
            // Translate the failing DB column (if the repository identified one)
            // into a field-attributed, non-leaky message so the Add-New view can
            // tell the operator WHICH field to fix instead of "Database error
            // occurred." The raw $wpdb error stays in the log only. GUI-F3F5.
            self::$last_save_error = self::describe_write_failure(
                MealsDB_Clients_Repository::last_failed_column()
            );
        }

        return $created;
    }

    /**
     * Most recent save() failure message, field-attributed where possible.
     *
     * Empty string when the last save succeeded or no message was produced.
     * Consumed by views/add-client.php. Directive GUI-F3F5 STEP 3.
     *
     * @var string
     */
    private static $last_save_error = '';

    /**
     * Return the field-attributed message for the most recent failed save().
     */
    public static function last_save_error(): string {
        return self::$last_save_error;
    }

    /**
     * Build a user-facing, non-leaky message for a failed client insert.
     *
     * Maps the offending DB column to its form-field label so the operator
     * knows which field to correct. Falls back to a generic message when the
     * column is unknown. Never includes the raw $wpdb error. GUI-F3F5 STEP 3.
     */
    private static function describe_write_failure(?string $db_column): string {
        if ($db_column === null || $db_column === '') {
            return __('Database error occurred while saving the client.', 'meals-db');
        }

        // DB column -> form field, so we can reuse the existing field labels.
        $db_to_form = [
            'client_phone_1'            => 'phone_primary',
            'client_phone_2'            => 'phone_secondary',
            'alternate_contact_phone_1' => 'alt_contact_phone_primary',
            'alternate_contact_phone_2' => 'alt_contact_phone_secondary',
            'province'                  => 'address_province',
            'delivery_province'         => 'delivery_address_province',
            'postal_code'               => 'address_postal',
            'delivery_postal_code'      => 'delivery_address_postal',
            'customer_comments'         => 'client_comments',
        ];

        $form_field = $db_to_form[$db_column] ?? $db_column;
        $label = self::get_field_label($form_field, $form_field);

        return sprintf(
            /* translators: %s: the form field that caused the save to fail. */
            __('Could not save: the value for "%s" is invalid or too long. Please check that field and try again.', 'meals-db'),
            $label
        );
    }

    /**
     * Validate that a (form-side) wordpress_user_id links to a real WP user.
     *
     * Returns '' when the value is a positive integer naming an existing
     * WordPress user; otherwise a user-facing, field-attributed message
     * explaining what to fix. This is the authoritative server-side gate for
     * the create/update WP-user requirement (directive GUI-F3F5-v2 STEP 3):
     * it runs at save time and does NOT trust the form's Validate button, so a
     * blank, malformed, or syntactically-valid-but-nonexistent ID submitted
     * directly can never reach the NOT NULL insert as a raw DB failure.
     *
     * The get_userdata() existence check is guarded with function_exists so the
     * form can load in non-WP contexts (WP-CLI, test fixtures); where the WP
     * user API is unavailable the integer shape is still enforced.
     *
     * @param mixed $value Raw form-side value (string from $_POST, or null).
     */
    private static function validate_wp_user_link($value): string {
        $raw = is_string($value) ? trim($value) : (is_scalar($value) ? trim((string) $value) : '');
        $label = self::get_field_label('wordpress_user_id', 'WordPress User ID');

        if ($raw === '' || !ctype_digit($raw) || (int) $raw <= 0) {
            return sprintf(
                /* translators: %s: the WordPress User ID field label. */
                __('Could not save: %s is required and must link to an existing WordPress user. Use "Validate" to confirm the ID before saving.', 'meals-db'),
                $label
            );
        }

        $uid = (int) $raw;

        if (function_exists('get_userdata')) {
            $user = get_userdata($uid);
            // Accept a real WP_User, or any object exposing a positive ID (test
            // fixtures stub get_userdata without the full WP_User class). A
            // false/null return means no such user.
            $exists = ($user instanceof WP_User)
                || (is_object($user) && isset($user->ID) && (int) $user->ID > 0);
            if (!$exists) {
                return sprintf(
                    /* translators: %d: the WordPress User ID that was not found. */
                    __('Could not save: no WordPress user has ID %d. Use "Validate" to confirm the ID before saving.', 'meals-db'),
                    $uid
                );
            }
        }

        return '';
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

        // GUI-F3F5-v2: enforce the WP-user linkage on update exactly as create
        // does. The old code set a blank wordpress_user_id to null here — both
        // wrong for a NOT NULL column and divergent from create's unset. A
        // client must always link to a real WP user, so a blank/invalid/
        // nonexistent value is a named field error, not a silent null. Only
        // gate when the field is actually present in the submission: a partial
        // update that doesn't touch wordpress_user_id must not be forced to
        // resupply it (the existing row already carries a valid link).
        if (array_key_exists('wordpress_user_id', $sanitized)) {
            $wp_user_error = self::validate_wp_user_link($sanitized['wordpress_user_id']);
            if ($wp_user_error !== '') {
                self::$last_save_error = $wp_user_error;
                error_log('[MealsDB] Update aborted: ' . $wp_user_error);
                return false;
            }
        }

        // Map form field names to database column names
        $sanitized = self::map_form_to_db($sanitized);

        // delivery_day is a zone-derived cache — NEVER trust the posted
        // value (spec 2026-07-11). Derive from the selected zone; with a
        // blank zone, drop the key entirely rather than persist a stale
        // posted day. validate() has already rejected unresolvable
        // non-blank zones, but this path re-guards for callers that skip
        // the form flow (defense-in-depth, Pattern 1).
        $sanitized = self::apply_zone_delivery_day($sanitized);
        if ($sanitized === null) {
            self::$last_save_error = __('Delivery area does not match any configured delivery zone.', 'meals-db');
            error_log('[MealsDB] Update aborted: unresolvable delivery zone.');
            return false;
        }

        $encrypted = $sanitized;
        if (!self::ensure_index_columns_exist()) {
            // Part C: genuine structural failure only (a hash column could not
            // be created), not the non-fatal unique-constraint case. Attributed
            // message + Event-Log entry rather than a bare DB error.
            error_log('[MealsDB] Update aborted: deterministic index columns are unavailable.');
            self::$last_save_error = __('Could not save: a database index column could not be prepared — please contact support.', 'meals-db');
            self::record_index_save_abort('update.index_columns_unavailable', 'Client edit aborted: deterministic index columns could not be ensured.');
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
        // next_order_date / next_delivery_date are DATE NULL columns in the
        // schema and are whitelisted in $db_columns, so a form submission (or a
        // direct save/update caller) can carry them — include them in the
        // normalization list so they get the same strtotime() + empty-string
        // handling as every other date column instead of an unnormalized value
        // (e.g. '' -> a strict-mode DATE insert failure). Today the client
        // add/edit UI does not render them (Quick Order manages them via
        // MealsDB_Client_Dates), but the whitelist makes them reachable.
        $date_fields = ['birth_date', 'open_date', 'required_start_date', 'service_commence_date', 'expected_termination_date', 'initial_renewal_termination_date', 'termination_date', 'most_recent_renewal_termination_date', 'next_order_date', 'next_delivery_date'];
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

        self::$last_save_error = '';

        $repository = new MealsDB_Clients_Repository();

        $updated = $repository->update_client($client_id, $encrypted);
        if (!$updated) {
            // Field-attributed message where the repository named the offending
            // column (directive GUI-F3F5 STEP 3), so the edit view can tell the
            // operator which field to fix instead of "Database error occurred."
            self::$last_save_error = self::describe_write_failure(
                MealsDB_Clients_Repository::last_failed_column()
            );
        }

        return $updated;
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
     * Overwrite delivery_day from the zone (or strip it). Returns null
     * when a NON-blank zone cannot be resolved — the caller must abort.
     *
     * - zone present + resolvable  → delivery_day := lowercase day
     * - zone present + unresolvable → null (abort signal)
     * - zone blank                  → delivery_day key removed (never
     *                                 write a day the zone didn't produce)
     * - zone key absent from payload (partial update) → delivery_day key
     *   removed too: a delivery_day submitted without its zone has no
     *   authority.
     *
     * @param array<string, mixed> $sanitized DB-side payload.
     * @return array<string, mixed>|null
     */
    private static function apply_zone_delivery_day(array $sanitized): ?array {
        unset($sanitized['delivery_day']);
        if (!array_key_exists('delivery_area_name', $sanitized)) {
            return $sanitized;
        }
        $zone = trim((string) $sanitized['delivery_area_name']);
        if ($zone === '') {
            return $sanitized;
        }
        if (!class_exists('MealsDB_Zone_Day')) {
            return $sanitized; // degraded: cannot derive, but don't block the save on a missing class
        }
        $day = MealsDB_Zone_Day::day_for_zone($zone);
        if ($day === null) {
            return null;
        }
        $sanitized['delivery_day'] = $day;
        return $sanitized;
    }

    /**
     * Save a draft of the form submission.
     *
     * @param array    $data
     * @param int|null $draft_id Existing draft ID to update, or null to insert a new draft
     * @return int|false Draft identifier on success, false on failure
     */
    public static function save_draft(array $data, ?int $draft_id = null) {
        // Service-layer capability re-check (defense-in-depth Layer 3): drafts persist the
        // same encrypted PII payload as save()/update(), so a future ungated caller
        // (WP-CLI/REST/import) must not be able to write one without the plugin capability.
        if (!self::is_authorized_to_modify_clients()) {
            return false;
        }

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
            // U01-client-form-11: one lookup answers both "does it exist?" and
            // "is it owned by this user?" (previously two draft_exists() SELECTs
            // whose only purpose was distinguishing the two log messages). The
            // UPDATE below is still scoped by `created_by = %d`, so this SELECT
            // only decides the wording. A NULL created_by is treated as
            // not-owned, matching the UPDATE's `created_by = %d` semantics
            // (NULL never equals an int).
            $owner_row = $wpdb->get_row(
                $wpdb->prepare("SELECT created_by FROM `{$drafts_table}` WHERE id = %d LIMIT 1", $draft_id),
                ARRAY_A
            );
            if ($owner_row === null) {
                error_log('[MealsDB] Draft update failed: draft ID ' . $draft_id . ' not found.');
                return false;
            }
            if ($owner_row['created_by'] === null || (int) $owner_row['created_by'] !== $user_id) {
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
        // Service-layer capability re-check (defense-in-depth Layer 3): matches save()/update()
        // so a future ungated caller cannot destroy a client's draft PII payload without the
        // plugin capability. Ownership (created_by) is still enforced separately in SQL below.
        if (!self::is_authorized_to_modify_clients()) {
            return false;
        }

        if ($draft_id <= 0) {
            return false;
        }

        global $wpdb;
        if (!$wpdb) {
            error_log('[MealsDB] Draft delete aborted: database connection unavailable.');
            return false;
        }

        $drafts_table = MealsDB_DB::get_table_name(MealsDB_Tables::DRAFTS);

        $user_id = get_current_user_id();

        // U01-client-form-11: single lookup for both "does it exist?" and "is
        // it owned by this user?" (previously two draft_exists() SELECTs whose
        // only purpose was distinguishing the two log messages). The DELETE
        // below is still scoped by `created_by = %d`, so this SELECT only
        // decides the wording. A NULL created_by counts as not-owned, matching
        // the DELETE's `created_by = %d` semantics (NULL never equals an int).
        $owner_row = $wpdb->get_row(
            $wpdb->prepare("SELECT created_by FROM `{$drafts_table}` WHERE id = %d LIMIT 1", $draft_id),
            ARRAY_A
        );
        if ($owner_row === null) {
            error_log('[MealsDB] Draft delete failed: draft ID ' . $draft_id . ' not found.');
            return false;
        }
        if ($owner_row['created_by'] === null || (int) $owner_row['created_by'] !== $user_id) {
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
     * Drafts contain the same PII columns the live record encrypts
     * (individual_id, requisition_id, vet_health_card, names, addresses), so
     * the JSON envelope is encrypted under the same key.
     *
     * QW-2 — fail CLOSED, never open. Previously this fell back to storing the
     * PLAINTEXT JSON when encryption was unavailable or threw, which meant a
     * missing/misconfigured key silently persisted full government PII as
     * cleartext in meals_drafts. That is inconsistent with the three live
     * client-write paths, which all abort rather than write plaintext PII. The
     * right trade for a PII system is: a draft that can't be encrypted is NOT
     * saved (and the caller surfaces "Failed to save draft."). Losing an
     * unsaved draft beats writing government IDs in cleartext.
     *
     * NOTE: the WRITE path now refuses plaintext, but decode_draft_payload()
     * still READS legacy plaintext drafts — only writing cleartext is forbidden.
     *
     * @return string|false
     */
    private static function encode_draft_payload(array $data) {
        if (!class_exists('MealsDB_Encryption')) {
            // No encryption layer at all → refuse to persist PII as plaintext.
            // (Kept here, not in the shared helper: inside MealsDB_Encryption
            // this guard is meaningless — we'd already be in the class.)
            error_log('[MealsDB] Draft not saved: encryption unavailable; refusing to store PII as plaintext.');
            return false;
        }
        // Delegate the encode + fail-closed discipline to the shared helper
        // (directive INV-DRAFT-1 Step 2). Behaviour is unchanged: encrypted
        // payload on success, false (never plaintext) on failure.
        return MealsDB_Encryption::encode_payload($data);
    }

    /**
     * Decode a stored draft payload, supporting:
     *   - new format (encrypted base64),
     *   - legacy plaintext JSON written before encryption was added.
     *
     * Returns null if neither path produces a JSON object.
     */
    public static function decode_draft_payload(string $stored): ?array {
        // Delegate to the shared helper (directive INV-DRAFT-1 Step 2). It
        // performs the same cheap legacy-plaintext shape check first, then the
        // encrypted path — behaviour is unchanged for client-form drafts. The
        // class_exists guard is dropped here intentionally: if the encryption
        // class were absent the shared helper would simply fall through its
        // plaintext branch (and a draft that needs decryption can't be read
        // without it anyway).
        return MealsDB_Encryption::decode_payload($stored);
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

            // individual_id / requisition_id are warn-not-block (directive
            // GUI-SAVE-INDEX Part B): a duplicate is a legitimate dual-program
            // enrollment, surfaced via collect_unique_field_warnings() rather
            // than blocked here. Skip them so they never become a hard error.
            if (in_array($field, self::$warn_only_unique_fields, true)) {
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

            if ($exists === null) {
                // U09-clients-repo-14: the uniqueness lookup could not run (DB
                // error). These *_index / delivery_initials columns have no
                // backing DB UNIQUE constraint, so proceeding would fail OPEN and
                // could admit a duplicate. Fail CLOSED: block the save with a
                // clear "try again" message instead of a misleading "already
                // exists".
                $label = ucfirst(str_replace('_', ' ', $field));
                $errors[] = sprintf(
                    /* translators: %s: field label (e.g. "Vet health card") */
                    __('Could not verify that %s is unique (a database error occurred). Please try again.', 'meals-db'),
                    $label
                );
            } elseif ($exists === true) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' already exists in another client.';
            }
        }

        return $errors;
    }

    /**
     * Collect non-blocking duplicate WARNINGS for the warn-only unique fields
     * (individual_id / requisition_id).
     *
     * Directive GUI-SAVE-INDEX Part B: a person enrolled in two programs
     * (audit MAJ-1) legitimately shares a government identifier, so a match is
     * surfaced — naming the other client so the operator can confirm a
     * deliberate dual-program enrollment — but it NEVER blocks the save. This
     * mirrors the WP-user "already linked to client #N" posture. The lookup
     * uses the deterministic hash COLUMN, which works with or without a DB-level
     * UNIQUE constraint (those two columns deliberately carry none).
     *
     * @param array    $data       Sanitized, DB-vocabulary form data.
     * @param int|null $exclude_id Client being edited (excluded from the match).
     * @return string[] Human-readable warnings (empty when no collision).
     */
    private static function collect_unique_field_warnings(array $data, ?int $exclude_id = null): array {
        global $wpdb;
        if (!$wpdb) {
            return [];
        }

        // The hash columns must exist for the lookup; ensure_index_columns_exist
        // returns true as long as they do (a missing DB-level unique constraint
        // is no longer fatal — Part A). If even the columns can't be ensured,
        // skip the warning silently rather than blocking data entry.
        if (!self::ensure_index_columns_exist()) {
            return [];
        }

        $warnings = [];
        $repository = new MealsDB_Clients_Repository();

        foreach (self::$warn_only_unique_fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '' || $value === null) {
                continue;
            }

            $indexColumn = self::$deterministic_index_map[$field] ?? null;
            if ($indexColumn === null) {
                continue;
            }

            $hash = self::deterministic_hash((string) $value);
            $other_id = $repository->find_client_id_by_column($indexColumn, $hash, $exclude_id);

            if ($other_id !== null) {
                $label = self::get_field_label($field);
                $warnings[] = sprintf(
                    /* translators: 1: field label (e.g. "Individual ID"); 2: the other client's ID. */
                    __('Heads up: another client already has this %1$s — client #%2$d. This is allowed for a deliberate dual-program enrollment (one person in two programs); double-check this is intentional.', 'meals-db'),
                    $label,
                    (int) $other_id
                );
            }
        }

        return $warnings;
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
            case 'address_province':
            case 'delivery_address_province':
                // Province is a VARCHAR(10) holding a 2-letter code. Operators
                // typing a full name ("New Brunswick") overflowed the column
                // and the create failed with a generic DB error (directive
                // GUI-F3F5). Normalise here — in sanitize, which both
                // validate() and save() funnel through — so the same value is
                // checked and stored: map known full names to their code,
                // otherwise uppercase/trim. An unmappable value is left as-is
                // for validate() to reject with a named field error; the
                // length bound still fail-safes save() against an overflow.
                $value = self::normalize_province_code($value);
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

                // Only CANONICALISE a value that is already purely digits (strip
                // leading zeros). Previously we stripped EVERY non-digit
                // character, which silently rewrote a typo instead of rejecting
                // it: 'user#7' -> '7', '12.5' -> '125'. validate() /
                // validate_wp_user_link() then confirmed the WRONG (but real) WP
                // user existed and mis-linked the client — and wp_user_id drives
                // the order->client allocation routing (MAJ-1). Leave a
                // non-numeric value verbatim so validate()'s ctype_digit check
                // rejects it with a named "must be a positive integer" error.
                if (ctype_digit($value)) {
                    $value = ltrim($value, '0');
                }
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
            // use_legacy_billing drives the SDNB invoice-pipeline split
            // (1 = legacy zone CSV, 0 = new portal). The form renders it as
            // a "New Portal" checkbox posting '0' when checked, with a
            // hidden '1' fallback for the unchecked state — so unlike a
            // bare checkbox, the key is always present when the SDNB row is
            // active and absent (column untouched) for other client types.
            case 'use_legacy_billing':
                $normalized = strtolower(trim($value));
                $value = in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true) ? '1' : '0';
                break;
            case 'units':
                $value = trim($value);
                if ($value === '') {
                    break;
                }

                // Normalise to an integer STRING but do NOT clamp to the 1-31
                // range here. sanitize runs before validate(), so clamping
                // (e.g. 45 -> 31) silently rewrote an out-of-range entry and
                // made validate()'s "must be between 1 and 31" range check
                // unreachable — the operator's 45 was stored as 31 with no
                // error. Leave the out-of-range value for validate() to reject
                // by name.
                $value = (string) (int) $value;
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
     * Canadian province / territory 2-letter codes (the only valid contents of
     * the VARCHAR(10) province columns).
     */
    private static $province_codes = [
        'AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT',
    ];

    /**
     * Normalise a province input to its 2-letter code.
     *
     * Maps a known full name (or common variant) to its code and uppercases
     * a value that is already a code. An unrecognised value is returned
     * trimmed/uppercased so validate() can reject it with a named error and
     * the length bound can still catch an overflow. See directive GUI-F3F5.
     */
    private static function normalize_province_code(string $value): string {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $upper = strtoupper($trimmed);

        if (in_array($upper, self::$province_codes, true)) {
            return $upper;
        }

        // Collapse internal whitespace so "NEW  BRUNSWICK" matches.
        $key = preg_replace('/\s+/', ' ', $upper);

        $name_to_code = [
            'NEWFOUNDLAND AND LABRADOR' => 'NL',
            'NEWFOUNDLAND'              => 'NL',
            'LABRADOR'                  => 'NL',
            'PRINCE EDWARD ISLAND'      => 'PE',
            'NOVA SCOTIA'               => 'NS',
            'NEW BRUNSWICK'             => 'NB',
            'QUEBEC'                    => 'QC',
            'QUÉBEC'                    => 'QC',
            'ONTARIO'                   => 'ON',
            'MANITOBA'                  => 'MB',
            'SASKATCHEWAN'              => 'SK',
            'ALBERTA'                   => 'AB',
            'BRITISH COLUMBIA'          => 'BC',
            'YUKON'                     => 'YT',
            'NORTHWEST TERRITORIES'     => 'NT',
            'NUNAVUT'                   => 'NU',
        ];

        if (isset($name_to_code[$key])) {
            return $name_to_code[$key];
        }

        return $upper;
    }

    /**
     * Whether a value is a recognised Canadian province / territory code.
     */
    private static function is_valid_province_code(string $value): bool {
        return in_array(strtoupper(trim($value)), self::$province_codes, true);
    }

    /**
     * Public passthrough to the canonical province normaliser.
     *
     * MealsDB_WP_User_Mapper (the Pull-Data path, directive GUI-F3F5-v2) needs
     * to normalise a WP user's billing_state to the same 2-letter code the
     * form stores, and the rule must not drift from validate()/save(). Rather
     * than reimplement the mapping there, expose the single source of truth
     * (normalize_province_code) so both callers stay in sync (STR-1 lesson).
     */
    public static function to_province_code(string $value): string {
        return self::normalize_province_code($value);
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
        // delivery_day is no longer validated here — it is a zone-derived cache
        // (spec 2026-07-11). The old WED AM / THURS AM / FRI AM vocabulary is
        // deleted. Zone membership is checked separately in validate() via
        // MealsDB_Zone_Day::day_for_zone(), and the actual delivery_day value is
        // written by apply_zone_delivery_day() in save()/update(). A posted
        // delivery_day value (e.g. from a cached form) is silently discarded —
        // it must not block a save.
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

        // These enum rules run in validate() against sanitize_payload() output,
        // where sanitize_scalar_value() has already canonicalised the input
        // (service_zone strips a "zone " prefix, meal_type maps "meal"->"main",
        // requisition_period maps DAILY/WEEKLY/MONTHLY to day/week/month). The
        // allowed lists below therefore carry only the canonical post-sanitize
        // forms; the plural / prefixed spellings are accepted upstream in
        // sanitize, never matched here.
        return [
            'gender' => [
                'allowed'   => ['MALE', 'FEMALE', 'OTHER'],
                'normalize' => 'upper',
                'message'   => 'Gender must be Male, Female, or Other.',
            ],
            'service_zone' => [
                'allowed'   => ['A', 'B'],
                'normalize' => 'upper',
                'message'   => 'Service zone must be either A or B.',
            ],
            'meal_type' => [
                'allowed'   => ['MAIN', 'MAIN+SIDE'],
                'normalize' => 'upper',
                'message'   => 'Meal type must be either Main or Main+Side.',
            ],
            'requisition_period' => [
                'allowed'   => ['DAY', 'WEEK', 'MONTH'],
                'normalize' => 'upper',
                'message'   => 'Requisition period must be Day/Daily, Week/Weekly, or Month/Monthly.',
            ],
            'ordering_contact_method' => [
                'allowed'   => $contact_method_allowed,
                'normalize' => 'upper',
                'message'   => 'Ordering contact method must be a supported option.',
            ],
        ];
    }

    /**
     * Ensure the deterministic index COLUMNS exist on the meals_clients table.
     *
     * Directive GUI-SAVE-INDEX Part A — robustness posture. The deterministic
     * index exists for DEDUP DETECTION, not as a hard gate on every write. So
     * this method now draws a sharp line:
     *
     *   - The hash COLUMNS must exist (save()/update() write the hash VALUE into
     *     them; the dedup lookup reads them). A column that genuinely cannot be
     *     created is the ONLY condition that returns false and aborts a save.
     *   - The DB-level UNIQUE *constraint* is best-effort. An inability to build
     *     it (duplicate data → MySQL errno 1062), drop a stale one, or rebuild a
     *     non-unique one is logged as a degraded Event-Log warning and the save
     *     PROCEEDS. The dedup *check* (find-by-index) works on the column with or
     *     without the constraint; only the hard guarantee is absent.
     *
     * Previously a failed CREATE UNIQUE INDEX set $allEnsured = false and this
     * returned false, fail-CLOSING every client save — the staging root cause,
     * where individual_id_index / requisition_id_index carried genuine migrated
     * duplicates (a dual-program person) so the unique index could never build.
     *
     * @return bool True when the hash columns are usable (save may proceed);
     *              false only when a hash column is structurally unavailable.
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

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // HARD requirement: every hash COLUMN must exist. This is the only
        // failure that aborts a save.
        $columns_ok = true;

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
                $columns_ok = false;
                continue;
            }

            if (!$columnExists) {
                $addColumnSql = "ALTER TABLE `{$clients_table}` ADD COLUMN `{$indexColumn}` CHAR(64) NULL";
                if ($wpdb->query($addColumnSql) === false) {
                    error_log('[MealsDB] Failed to add deterministic index column: ' . $wpdb->last_error);
                    $columns_ok = false;
                    continue;
                }
            }

            // BEST-EFFORT from here: the UNIQUE *constraint* is a data-integrity
            // nicety, never a gate on data entry (Part A). Index-management
            // problems degrade to a logged warning; the save still runs.
            if (in_array($indexColumn, self::$hard_unique_index_columns, true)) {
                self::ensure_unique_index_constraint($clients_table, $indexColumn);
            } else {
                // Part B: individual_id_index / requisition_id_index must NOT
                // carry a hard UNIQUE constraint (dual-program enrollments
                // legitimately collide). Retire any stray one so a duplicate can
                // be inserted; duplicates are caught by an entry-time warning.
                self::drop_index_if_present($clients_table, $indexColumn);
            }
        }

        if (!$columns_ok) {
            // A real, structural problem the save cannot work around. The
            // save()/update() call sites translate this into an attributed
            // message and emit the failed Event-Log entry (Part C).
            return false;
        }

        // Populate any empty hash columns from existing rows. Best-effort: a
        // failure here is a data-quality issue (logged inside), not a reason to
        // block the current save.
        self::backfill_deterministic_indexes();

        self::$indexes_ensured = true;
        return true;
    }

    /**
     * Best-effort establishment of a UNIQUE constraint on a deterministic index
     * column. Directive GUI-SAVE-INDEX Part A: NEVER aborts a save — any DDL
     * failure (errno 1062 duplicate data, drop/rebuild errors) is recorded as a
     * degraded Event-Log warning and the save proceeds without the constraint.
     */
    private static function ensure_unique_index_constraint(string $clients_table, string $indexColumn): void {
        global $wpdb;

        $indexName = 'unique_' . $indexColumn;

        // Retire any legacy non-unique index left over from earlier schema.
        $legacyIndexName = 'idx_' . $indexColumn;
        $escapedLegacy = $wpdb->_real_escape($legacyIndexName);
        $legacyIndexRows = $wpdb->get_results("SHOW INDEX FROM `{$clients_table}` WHERE Key_name = '{$escapedLegacy}'", ARRAY_A);
        if (is_array($legacyIndexRows) && count($legacyIndexRows) > 0) {
            if ($wpdb->query("ALTER TABLE `{$clients_table}` DROP INDEX `{$legacyIndexName}`") === false
                && !self::ddl_error_is_benign($wpdb->last_error, 'check that column/key exists', '1091')) {
                self::record_index_event(
                    'index.legacy_drop_failed',
                    'Could not drop legacy deterministic index ' . $legacyIndexName . '; continuing.',
                    $indexColumn
                );
            }
        }

        [$indexExists, $indexIsUnique] = self::deterministic_index_status($indexName);

        if ($indexExists && !$indexIsUnique) {
            if ($wpdb->query("ALTER TABLE `{$clients_table}` DROP INDEX `{$indexName}`") === false) {
                // Leave the existing (non-unique) index in place; the save still
                // proceeds and the hash column is still populated.
                self::record_index_event(
                    'index.nonunique_drop_failed',
                    'Could not drop non-unique deterministic index ' . $indexName . '; continuing without rebuild.',
                    $indexColumn
                );
                return;
            }
            $indexExists = false;
        }

        if (!$indexExists) {
            $createIndexSql = "CREATE UNIQUE INDEX `{$indexName}` ON `{$clients_table}` (`{$indexColumn}`)";
            if ($wpdb->query($createIndexSql) === false && !self::ddl_error_is_benign($wpdb->last_error, 'Duplicate key name', '1061')) {
                // errno 1062 (duplicate data) is the staging case: genuine
                // duplicate values among migrated clients. Per Part A this is a
                // WARNING, not a save-killer — the hash column still populates
                // and the dedup lookup works without the constraint.
                self::record_index_event(
                    'index.unique_build_failed',
                    'Could not establish UNIQUE index ' . $indexName . '; continuing without the DB constraint.',
                    $indexColumn
                );
            }
        }
    }

    /**
     * Drop a deterministic index (unique_* and the legacy idx_* shadow) from a
     * column that must NOT carry a hard UNIQUE constraint. Directive
     * GUI-SAVE-INDEX Part B — applies to individual_id_index /
     * requisition_id_index so a legitimate dual-program duplicate can be
     * inserted. Best-effort: a failed drop degrades to a logged warning.
     */
    private static function drop_index_if_present(string $clients_table, string $indexColumn): void {
        global $wpdb;

        foreach (['unique_' . $indexColumn, 'idx_' . $indexColumn] as $indexName) {
            $escaped = $wpdb->_real_escape($indexName);
            $rows = $wpdb->get_results("SHOW INDEX FROM `{$clients_table}` WHERE Key_name = '{$escaped}'", ARRAY_A);
            if (is_array($rows) && count($rows) > 0) {
                if ($wpdb->query("ALTER TABLE `{$clients_table}` DROP INDEX `{$indexName}`") === false
                    && !self::ddl_error_is_benign($wpdb->last_error, 'check that column/key exists', '1091')) {
                    self::record_index_event(
                        'index.constraint_drop_failed',
                        'Could not drop unwanted unique index ' . $indexName . ' (allow-and-warn column); continuing.',
                        $indexColumn
                    );
                }
            }
        }
    }

    /**
     * Decide whether a failed DDL statement's $wpdb->last_error describes a
     * BENIGN race we should tolerate silently (index already exists on CREATE;
     * index missing on DROP) rather than log as a degraded Event-Log entry.
     *
     * $wpdb->last_error carries mysqli_error()'s message TEXT only — the numeric
     * errno ('1061' / '1091') never appears in it — so the old
     * `strpos($wpdb->last_error, '1061') === false` guards could never match and
     * fired the record_index_event() call for the benign cases too. Match on the
     * driver's message wording (like class-task-engine.php::is_duplicate_key_error
     * does for 1062), keeping the errno digits as a belt-and-suspenders in case a
     * future driver ever surfaces them.
     */
    private static function ddl_error_is_benign(string $last_error, string $wording, string $errno): bool {
        if ($last_error === '') {
            return false;
        }
        return stripos($last_error, $wording) !== false
            || strpos($last_error, $errno) !== false;
    }

    /**
     * Emit a degraded Event-Log entry for a deterministic-index management
     * problem. Directive GUI-SAVE-INDEX Part C: index-constraint degradations
     * must be visible on the Event Log, not just debug.log. Fail-safe — never
     * throws (MealsDB_Event_Log::record swallows its own write failures).
     */
    private static function record_index_event(string $event, string $message, string $indexColumn): void {
        error_log('[MealsDB] ' . $message . ' (' . $indexColumn . '): ' . (isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb']->last_error : ''));

        if (!class_exists('MealsDB_Event_Log')) {
            return;
        }

        MealsDB_Event_Log::record([
            'severity'  => 'warning',
            'category'  => 'clients',
            'subsystem' => 'client_form_index',
            'event'     => $event,
            'outcome'   => 'degraded',
            'message'   => $message,
            'context'   => ['column' => $indexColumn],
        ]);
    }

    /**
     * Emit a failed Event-Log entry when a client save genuinely aborts because
     * the deterministic index COLUMNS could not be ensured. Directive
     * GUI-SAVE-INDEX Part C — a real abort (distinct from the degraded
     * constraint path) should surface on the Event Log, not only debug.log.
     * Fail-safe — never throws.
     */
    private static function record_index_save_abort(string $event, string $message): void {
        if (!class_exists('MealsDB_Event_Log')) {
            return;
        }

        MealsDB_Event_Log::record([
            'severity'  => 'error',
            'category'  => 'clients',
            'subsystem' => 'client_form_index',
            'event'     => $event,
            'outcome'   => 'failed',
            'message'   => $message,
        ]);
    }

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
        // Single source of truth: route through MealsDB_Encryption::create_index
        // so the form's index writes/queries use the SAME version (v1 bare-SHA256
        // or v2 keyed-HMAC, gated by index_format_is_v2) as the consolidated
        // importer and the harden migrator. Keeping a private copy of the hash
        // here would silently diverge the moment the keyed-index flag flips
        // (STR-10a) — exactly the dual-maintenance trap CLAUDE.md warns about.
        if (class_exists('MealsDB_Encryption')) {
            return MealsDB_Encryption::create_index($value);
        }
        // Fallback only for contexts where the encryption class isn't loaded
        // (the v2 path can't be active without it, so v1 is the correct shape).
        return hash('sha256', strtolower(trim($value)));
    }

    /**
     * Expose the deterministic index map (source column => `*_index` column)
     * so the encryption harden migrator can recompute every index from the
     * same authoritative mapping the form writes through. Read-only copy.
     *
     * @return array<string, string>
     */
    public static function deterministic_index_map(): array {
        return self::$deterministic_index_map;
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
