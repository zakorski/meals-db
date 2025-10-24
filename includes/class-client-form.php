<?php
/**
 * Handles validation and saving of Meals DB client records and drafts.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Created as a work for hire for Meals and More.
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
        'first_name',
        'last_name',
        'client_email',
        'phone_primary',
        'address_postal',
        'customer_type',
        'birth_date',
        'address_city',
        'address_province',
        'service_center',
        'service_zone',
        'service_course',
        'per_sdnb_req',
        'rate',
        'delivery_day',
        'delivery_area_name',
        'delivery_area_zone',
        'ordering_frequency',
        'ordering_contact_method',
        'delivery_frequency',
        'open_date',
        'required_start_date',
        'service_commence_date',
        'expected_termination_date',
        'initial_termination_date',
        'recent_renewal_date',
        'vet_health_card',
        'delivery_initials',
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
    ];

    /**
     * Fields that require AES-256 encryption.
     */
    private static $encrypted_fields = [
        'individual_id',
        'requisition_id',
        'diet_concerns',
        'client_comments',
    ];

    /**
     * Validate submitted form data.
     *
     * @param array $data
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validate(array $data): array {
        $errors = [];

        $unknown_keys = [];
        $sanitized = self::sanitize_payload($data, $unknown_keys);

        if (!empty($unknown_keys)) {
            $errors[] = 'Unknown form fields detected: ' . implode(', ', $unknown_keys);
        }

        // Postal Code
        if (!preg_match('/^[A-Z]\d[A-Z] ?\d[A-Z]\d$/i', $sanitized['address_postal'] ?? '')) {
            $errors[] = 'Postal code must be in A1A 1A1 format.';
        }

        // Phone
        if (!preg_match('/^\(\d{3}\)-\d{3}-\d{4}$/', $sanitized['phone_primary'] ?? '')) {
            $errors[] = 'Phone number must be in (###)-###-#### format.';
        }

        // Email
        if (!filter_var($sanitized['client_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid client email address.';
        }

        // Required dropdowns
        $required_dropdowns = [
            'customer_type', 'address_city', 'address_province',
            'service_center', 'service_zone', 'service_course',
            'per_sdnb_req', 'rate', 'delivery_day',
            'delivery_area_name', 'delivery_area_zone',
            'ordering_frequency', 'ordering_contact_method', 'delivery_frequency'
        ];

        foreach ($required_dropdowns as $field) {
            if (empty($sanitized[$field])) {
                $errors[] = "Field '{$field}' is required.";
            }
        }

        // Unique field checks
        $conflicts = self::check_unique_fields($sanitized);
        if (!empty($conflicts)) {
            $errors = array_merge($errors, $conflicts);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Save client data to meals_clients table.
     * 
     * @param array $data
     * @return bool
     */
    public static function save(array $data): bool {
        $conn = MealsDB_DB::get_connection();
        if (!$conn) return false;

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

        $encrypted = $sanitized;

        // Encrypt sensitive fields
        foreach (self::$encrypted_fields as $field) {
            if (!empty($encrypted[$field])) {
                $encrypted[$field] = MealsDB_Encryption::encrypt($encrypted[$field]);
            }
        }

        // Format date fields (assume already validated)
        $date_fields = ['birth_date', 'open_date', 'required_start_date', 'service_commence_date', 'expected_termination_date', 'initial_termination_date', 'recent_renewal_date'];
        foreach ($date_fields as $field) {
            if (isset($encrypted[$field])) {
                $encrypted[$field] = date('Y-m-d', strtotime($encrypted[$field]));
            }
        }

        // Insert statement (simplified, auto-field mapping can be done later)
        $columns = array_keys($encrypted);
        $placeholders = array_fill(0, count($columns), '?');
        $types = str_repeat('s', count($columns));
        $values = array_values($encrypted);

        $sql = "INSERT INTO meals_clients (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('[MealsDB] Save failed: ' . $conn->error);
            return false;
        }

        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    /**
     * Save a draft of the form submission.
     *
     * @param array $data
     */
    public static function save_draft(array $data): void {
        $conn = MealsDB_DB::get_connection();
        if (!$conn) return;

        $json = json_encode($data);
        $user_id = get_current_user_id();

        $stmt = $conn->prepare("INSERT INTO meals_drafts (data, created_by) VALUES (?, ?)");
        $stmt->bind_param("si", $json, $user_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Check for duplicate unique fields across clients.
     *
     * @param array $data
     * @return array List of friendly error messages
     */
    private static function check_unique_fields(array $data): array {
        $conn = MealsDB_DB::get_connection();
        if (!$conn) return [];

        $errors = [];

        foreach (self::$unique_fields as $field) {
            if (!empty($data[$field])) {
                $value = $field === 'individual_id' || $field === 'requisition_id'
                    ? MealsDB_Encryption::encrypt($data[$field]) // must encrypt to match DB
                    : $data[$field];

                $stmt = $conn->prepare("SELECT id FROM meals_clients WHERE $field = ? LIMIT 1");
                $stmt->bind_param("s", $value);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' already exists in another client.';
                }

                $stmt->close();
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
    private static function sanitize_payload(array $data, array &$unknown_keys = []): array {
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

            $sanitized[$column] = self::sanitize_value($column, $data[$column]);
        }

        return $sanitized;
    }

    /**
     * Sanitize a single value for storage.
     *
     * @param string $column
     * @param mixed  $value
     * @return string
     */
    private static function sanitize_value(string $column, $value): string {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        if (!is_scalar($value)) {
            $value = '';
        }

        $value = (string) $value;

        switch ($column) {
            case 'client_email':
                if (function_exists('sanitize_email')) {
                    $value = sanitize_email($value);
                } else {
                    $value = trim(filter_var($value, FILTER_SANITIZE_EMAIL));
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
}
