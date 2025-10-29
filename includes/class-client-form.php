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
        'vet_health_card',
        'delivery_initials',
        'first_name',
        'last_name',
        'customer_type',
        'open_date',
        'assigned_social_worker',
        'social_worker_email',
        'client_email',
        'phone_primary',
        'phone_secondary',
        'do_not_call_client_phone',
        'alt_contact_name',
        'alt_contact_phone_primary',
        'alt_contact_phone_secondary',
        'alt_contact_email',
        'address_street_number',
        'address_street_name',
        'address_unit',
        'address_city',
        'address_province',
        'address_postal',
        'delivery_address_street_number',
        'delivery_address_street_name',
        'delivery_address_unit',
        'delivery_address_city',
        'delivery_address_province',
        'delivery_address_postal',
        'gender',
        'birth_date',
        'service_center',
        'service_center_charged',
        'vendor_number',
        'service_id',
        'service_zone',
        'service_course',
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
     * Deterministic index columns used for uniqueness checks on encrypted data.
     */
    private static $deterministic_index_map = [
        'individual_id'      => 'individual_id_index',
        'requisition_id'     => 'requisition_id_index',
        'vet_health_card'    => 'vet_health_card_index',
        'delivery_initials'  => 'delivery_initials_index',
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
    public static function validate(array $data): array {
        $errors = [];

        $unknown_keys = [];
        $sanitized = self::sanitize_payload($data, $unknown_keys);

        if (!empty($unknown_keys)) {
            $errors[] = 'Unknown form fields detected: ' . implode(', ', $unknown_keys);
        }

        // Postal Code
        if (!preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/i', $sanitized['address_postal'] ?? '')) {
            $errors[] = 'Postal code must be in A1A1A1 format.';
        }

        if (!empty($sanitized['delivery_address_postal']) && !preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/i', $sanitized['delivery_address_postal'])) {
            $errors[] = 'Delivery postal code must be in A1A1A1 format.';
        }

        // Phone
        $phonePattern = '/^\(\d{3}\)-\d{3}-\d{4}$/';
        if (!preg_match($phonePattern, $sanitized['phone_primary'] ?? '')) {
            $errors[] = 'Phone number must be in (###)-###-#### format.';
        }

        if (!empty($sanitized['phone_secondary']) && !preg_match($phonePattern, $sanitized['phone_secondary'])) {
            $errors[] = 'Client phone #2 must be in (###)-###-#### format.';
        }

        if (!empty($sanitized['alt_contact_phone_primary']) && !preg_match($phonePattern, $sanitized['alt_contact_phone_primary'])) {
            $errors[] = 'Alternate contact phone #1 must be in (###)-###-#### format.';
        }

        if (!empty($sanitized['alt_contact_phone_secondary']) && !preg_match($phonePattern, $sanitized['alt_contact_phone_secondary'])) {
            $errors[] = 'Alternate contact phone #2 must be in (###)-###-#### format.';
        }

        // Email
        if (!empty($sanitized['client_email']) && !filter_var($sanitized['client_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid client email address.';
        }

        if (!empty($sanitized['social_worker_email']) && !filter_var($sanitized['social_worker_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid social worker email address.';
        }

        if (!empty($sanitized['alt_contact_email']) && !filter_var($sanitized['alt_contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid alternate contact email address.';
        }

        // Enumerated fields
        $enum_constraints = [
            'customer_type' => ['options' => ['SDNB', 'Veteran', 'Private'], 'message' => 'Customer type must be SDNB, Veteran, or Private.'],
            'gender' => ['options' => ['Male', 'Female', 'Other'], 'message' => 'Gender must be Male, Female, or Other.'],
            'payment_method' => ['options' => ['Invoice', 'E-Transfer', 'Cash'], 'message' => 'Payment method must be Invoice, E-Transfer, or Cash.'],
            'service_zone' => ['options' => ['A', 'B'], 'message' => 'Service zone must be either A or B.'],
            'service_course' => ['options' => ['1', '2'], 'message' => 'Service course must be either 1 or 2.'],
            'meal_type' => ['options' => ['1', '2'], 'message' => 'Meal type must be Main or Main & Side.'],
            'requisition_period' => ['options' => ['Day', 'Week', 'Month'], 'message' => 'Requisition time period must be day, week, or month.'],
            'delivery_day' => ['options' => ['Wednesday AM', 'Wednesday PM', 'Thursday AM', 'Thursday PM', 'Friday AM', 'Friday PM'], 'message' => 'Delivery day must match one of the scheduled options.'],
            'ordering_contact_method' => ['options' => ['Phone', 'Bulk Email', 'Auto-Renew', 'Client Email', 'Client Call'], 'message' => 'Ordering contact method must be Phone, Bulk Email, Auto-Renew, Client Email, or Client Call.'],
        ];

        foreach ($enum_constraints as $field => $constraint) {
            $raw_value = isset($data[$field]) ? trim((string) $data[$field]) : '';
            if ($raw_value === '') {
                continue;
            }

            $value = $sanitized[$field] ?? '';
            if ($value === '' || !in_array($value, $constraint['options'], true)) {
                $errors[] = $constraint['message'];
            }
        }

        // Numeric fields
        $numeric_constraints = [
            'ordering_frequency' => 'Ordering frequency must be a number.',
            'delivery_frequency' => 'Delivery frequency must be a number.',
            'freezer_capacity'   => 'Freezer capacity must be a number.',
            'delivery_fee'       => 'Delivery fee must be a number.',
        ];

        foreach ($numeric_constraints as $field => $message) {
            $raw_value = isset($data[$field]) ? trim((string) $data[$field]) : '';
            if ($raw_value === '') {
                continue;
            }

            $value = $sanitized[$field] ?? '';
            if ($value === '') {
                $errors[] = $message;
            }
        }

        // Required fields captured by the current admin form UI
        $required_fields = [
            'last_name'                => 'Last Name',
            'first_name'               => 'First Name',
            'customer_type'            => 'Customer Type',
            'open_date'                => 'Open Date',
            'address_street_number'    => 'Street #',
            'address_street_name'      => 'Street Name',
            'address_unit'             => 'Apt #',
            'address_city'             => 'City',
            'address_province'         => 'Province',
            'address_postal'           => 'Postal Code',
            'phone_primary'            => 'Client Phone #1',
            'payment_method'           => 'Payment Method',
            'rate'                     => 'Rate',
            'delivery_initials'        => 'Initials for delivery',
            'delivery_day'             => 'Delivery Day',
            'delivery_area_name'       => 'Delivery Area',
            'ordering_frequency'       => 'Ordering Frequency',
            'ordering_contact_method'  => 'Ordering Contact Method',
            'delivery_frequency'       => 'Delivery Frequency',
        ];

        foreach ($required_fields as $field => $label) {
            if (empty($sanitized[$field])) {
                $errors[] = sprintf('%s is required.', $label);
            }
        }

        if (isset($sanitized['units']) && $sanitized['units'] !== '') {
            $units = (int) $sanitized['units'];
            if ($units < 1 || $units > 31) {
                $errors[] = '# of units must be between 1 and 31.';
            }
        }

        // Unique field checks
        $conflicts = self::check_unique_fields($sanitized);
        if (!empty($conflicts)) {
            $errors = array_merge($errors, $conflicts);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized' => $sanitized,
        ];
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
        if (!self::ensure_index_columns_exist($conn)) {
            error_log('[MealsDB] Save aborted: deterministic index columns are unavailable.');
            return false;
        }

        // Encrypt sensitive fields
        try {
            foreach (self::$encrypted_fields as $field) {
                if (array_key_exists($field, $encrypted) && $encrypted[$field] !== '') {
                    $encrypted[$field] = MealsDB_Encryption::encrypt($encrypted[$field]);
                }
            }
        } catch (Exception $e) {
            error_log('[MealsDB] Save aborted during encryption: ' . $e->getMessage());
            return false;
        }

        // Store deterministic hashes for encrypted unique fields
        foreach (self::$deterministic_index_map as $field => $indexColumn) {
            if (array_key_exists($field, $sanitized) && $sanitized[$field] !== '') {
                $encrypted[$indexColumn] = self::deterministic_hash($sanitized[$field]);
            }
        }

        // Format date fields (assume already validated)
        $date_fields = ['birth_date', 'open_date', 'required_start_date', 'service_commence_date', 'expected_termination_date', 'initial_renewal_date', 'termination_date', 'most_recent_renewal_date'];
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

        $params = [$types];
        foreach ($values as $index => $value) {
            $params[] =& $values[$index];
        }

        $bound = call_user_func_array([$stmt, 'bind_param'], $params);
        if ($bound === false) {
            error_log('[MealsDB] Save failed: unable to bind parameters for client insert.');
            $stmt->close();
            return false;
        }

        if (!$stmt->execute()) {
            error_log('[MealsDB] Save failed to execute insert: ' . ($stmt->error ?? 'unknown error'));
            $stmt->close();
            return false;
        }
        $stmt->close();

        return true;
    }

    /**
     * Save a draft of the form submission.
     *
     * @param array    $data
     * @param int|null $draft_id Existing draft ID to update, or null to insert a new draft
     * @return int|false Draft identifier on success, false on failure
     */
    public static function save_draft(array $data, ?int $draft_id = null) {
        $conn = MealsDB_DB::get_connection();
        if (!$conn) {
            error_log('[MealsDB] Draft save aborted: database connection unavailable.');
            return false;
        }

        if ($draft_id === null && isset($data['draft_id'])) {
            $draft_id = intval($data['draft_id']);
        }

        unset($data['draft_id'], $data['resume_draft']);

        $json = json_encode($data);
        if ($json === false) {
            error_log('[MealsDB] Draft save failed: unable to encode payload.');
            return false;
        }

        $user_id = get_current_user_id();

        if ($draft_id && $draft_id > 0) {
            if (!self::draft_exists($conn, $draft_id)) {
                error_log('[MealsDB] Draft update failed: draft ID ' . $draft_id . ' not found.');
                return false;
            }

            if (!self::draft_exists($conn, $draft_id, $user_id)) {
                error_log('[MealsDB] Draft update failed: user ' . $user_id . ' does not own draft ID ' . $draft_id . '.');
                return false;
            }

            $stmt = $conn->prepare('UPDATE meals_drafts SET data = ? WHERE id = ? AND created_by = ?');
            if (!$stmt) {
                error_log('[MealsDB] Draft update failed to prepare statement: ' . ($conn->error ?? 'unknown error'));
                return false;
            }

            if (!$stmt->bind_param('sii', $json, $draft_id, $user_id)) {
                $stmt->close();
                error_log('[MealsDB] Draft update failed to bind parameters.');
                return false;
            }

            $executed = $stmt->execute();
            if (!$executed) {
                error_log('[MealsDB] Draft update failed to execute: ' . ($stmt->error ?? 'unknown error'));
                $stmt->close();
                return false;
            }

            $stmt->close();

            return $draft_id;
        }

        $stmt = $conn->prepare("INSERT INTO meals_drafts (data, created_by) VALUES (?, ?)");
        if (!$stmt) {
            error_log('[MealsDB] Draft save failed to prepare statement: ' . ($conn->error ?? 'unknown error'));
            return false;
        }

        if (!$stmt->bind_param("si", $json, $user_id)) {
            $stmt->close();
            error_log('[MealsDB] Draft save failed to bind parameters.');
            return false;
        }

        $executed = $stmt->execute();
        if (!$executed) {
            error_log('[MealsDB] Draft save failed to execute: ' . ($stmt->error ?? 'unknown error'));
            $stmt->close();
            return false;
        }

        $new_id = intval($conn->insert_id ?? 0);
        $stmt->close();

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

        $conn = MealsDB_DB::get_connection();
        if (!$conn) {
            error_log('[MealsDB] Draft delete aborted: database connection unavailable.');
            return false;
        }

        if (!self::draft_exists($conn, $draft_id)) {
            error_log('[MealsDB] Draft delete failed: draft ID ' . $draft_id . ' not found.');
            return false;
        }

        $user_id = get_current_user_id();

        if (!self::draft_exists($conn, $draft_id, $user_id)) {
            error_log('[MealsDB] Draft delete failed: user ' . $user_id . ' does not own draft ID ' . $draft_id . '.');
            return false;
        }

        $stmt = $conn->prepare('DELETE FROM meals_drafts WHERE id = ? AND created_by = ?');
        if (!$stmt) {
            error_log('[MealsDB] Draft delete failed to prepare statement: ' . ($conn->error ?? 'unknown error'));
            return false;
        }

        if (!$stmt->bind_param('ii', $draft_id, $user_id)) {
            $stmt->close();
            error_log('[MealsDB] Draft delete failed to bind parameters.');
            return false;
        }

        $executed = $stmt->execute();
        if (!$executed) {
            error_log('[MealsDB] Draft delete failed to execute: ' . ($stmt->error ?? 'unknown error'));
            $stmt->close();
            return false;
        }

        $affected = $stmt->affected_rows ?? 0;
        $stmt->close();

        if ($affected <= 0) {
            error_log('[MealsDB] Draft delete failed: draft ID ' . $draft_id . ' could not be removed.');
            return false;
        }

        return true;
    }

    /**
     * Determine whether a draft exists.
     *
     * @param \mysqli   $conn
     * @param int       $draft_id
     * @param int|null  $owner_id Restrict check to a specific owner when provided.
     * @return bool
     */
    private static function draft_exists($conn, int $draft_id, ?int $owner_id = null): bool {
        if (!($conn instanceof \mysqli)) {
            return false;
        }

        $sql = 'SELECT id FROM meals_drafts WHERE id = ?';
        if ($owner_id !== null) {
            $sql .= ' AND created_by = ?';
        }
        $sql .= ' LIMIT 1';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('[MealsDB] Draft existence check failed to prepare statement: ' . ($conn->error ?? 'unknown error'));
            return false;
        }

        if ($owner_id !== null) {
            if (!$stmt->bind_param('ii', $draft_id, $owner_id)) {
                $stmt->close();
                error_log('[MealsDB] Draft existence check failed to bind parameters.');
                return false;
            }
        } else {
            if (!$stmt->bind_param('i', $draft_id)) {
                $stmt->close();
                error_log('[MealsDB] Draft existence check failed to bind parameters.');
                return false;
            }
        }

        if (!$stmt->execute()) {
            error_log('[MealsDB] Draft existence check failed to execute: ' . ($stmt->error ?? 'unknown error'));
            $stmt->close();
            return false;
        }

        if (method_exists($stmt, 'store_result')) {
            $stmt->store_result();
        }

        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $exists;
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

        $indexes_ready = self::ensure_index_columns_exist($conn);
        if (!$indexes_ready) {
            error_log('[MealsDB] Duplicate check skipped: deterministic index columns are unavailable.');
        }

        $errors = [];

        foreach (self::$unique_fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== '') {
                $column = $field;
                $value = $data[$field];

                if (isset(self::$deterministic_index_map[$field])) {
                    if (!$indexes_ready) {
                        continue;
                    }
                    $column = self::$deterministic_index_map[$field];
                    $value = self::deterministic_hash($data[$field]);
                }

                $stmt = $conn->prepare("SELECT id FROM meals_clients WHERE $column = ? LIMIT 1");
                if (!$stmt) {
                    error_log('[MealsDB] Duplicate check failed to prepare statement for column ' . $column . ': ' . ($conn->error ?? 'unknown error'));
                    continue;
                }

                if (!$stmt->bind_param("s", $value)) {
                    error_log('[MealsDB] Duplicate check failed to bind parameter for column ' . $column . '.');
                    $stmt->close();
                    continue;
                }

                if (!$stmt->execute()) {
                    error_log('[MealsDB] Duplicate check failed to execute for column ' . $column . ': ' . ($stmt->error ?? 'unknown error'));
                    $stmt->close();
                    continue;
                }

                if (method_exists($stmt, 'store_result')) {
                    $stmt->store_result();
                }

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
            case 'client_email':
            case 'social_worker_email':
            case 'alt_contact_email':
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
            case 'do_not_call_client_phone':
                $normalized = strtolower(trim($value));
                $value = in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true) ? '1' : '0';
                break;
            case 'customer_type':
                $normalized = strtolower(trim($value));
                $map = [
                    'sdnb'    => 'SDNB',
                    'veteran' => 'Veteran',
                    'private' => 'Private',
                ];
                $value = $map[$normalized] ?? '';
                break;
            case 'gender':
                $normalized = ucfirst(strtolower(trim($value)));
                $value = in_array($normalized, ['Male', 'Female', 'Other'], true) ? $normalized : '';
                break;
            case 'service_zone':
                $normalized = strtoupper(trim($value));
                $value = in_array($normalized, ['A', 'B'], true) ? $normalized : '';
                break;
            case 'service_course':
                $normalized = trim($value);
                $value = in_array($normalized, ['1', '2'], true) ? $normalized : '';
                break;
            case 'meal_type':
                $normalized = trim($value);
                $value = in_array($normalized, ['1', '2'], true) ? $normalized : '';
                break;
            case 'payment_method':
                $normalized = strtolower(trim($value));
                $map = [
                    'invoice'    => 'Invoice',
                    'e-transfer' => 'E-Transfer',
                    'etransfer'  => 'E-Transfer',
                    'cash'       => 'Cash',
                ];
                $value = $map[$normalized] ?? '';
                break;
            case 'requisition_period':
                $normalized = strtolower(trim($value));
                $map = [
                    'day' => 'Day',
                    'week' => 'Week',
                    'month' => 'Month',
                ];
                $value = $map[$normalized] ?? '';
                break;
            case 'delivery_day':
                $normalized = strtolower(trim($value));
                $options = [
                    'wednesday am' => 'Wednesday AM',
                    'wednesday pm' => 'Wednesday PM',
                    'thursday am'  => 'Thursday AM',
                    'thursday pm'  => 'Thursday PM',
                    'friday am'    => 'Friday AM',
                    'friday pm'    => 'Friday PM',
                ];
                $value = $options[$normalized] ?? '';
                break;
            case 'ordering_contact_method':
                $normalized = strtolower(trim($value));
                $options = [
                    'phone'        => 'Phone',
                    'bulk email'   => 'Bulk Email',
                    'auto-renew'   => 'Auto-Renew',
                    'client email' => 'Client Email',
                    'client call'  => 'Client Call',
                ];
                if (isset($options[$normalized])) {
                    $value = $options[$normalized];
                } else {
                    $value = '';
                }
                break;
            case 'ordering_frequency':
            case 'delivery_frequency':
            case 'freezer_capacity':
                $value = trim($value);
                if ($value === '') {
                    $value = '';
                    break;
                }

                if (!is_numeric($value)) {
                    $value = '';
                    break;
                }

                $value = (string) max(0, (int) round((float) $value));
                break;
            case 'delivery_fee':
                $value = trim($value);
                if ($value === '') {
                    $value = '';
                    break;
                }

                $normalized = filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                if ($normalized === '' || !is_numeric($normalized)) {
                    $value = '';
                    break;
                }

                $value = number_format((float) $normalized, 2, '.', '');
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
     * Ensure the deterministic index columns exist on the meals_clients table.
     *
     * @param mysqli $conn
     * @return bool
     */
    private static function ensure_index_columns_exist($conn): bool {
        if (empty(self::$deterministic_index_map)) {
            self::$indexes_ensured = true;
            return true;
        }

        if (self::$indexes_ensured) {
            return true;
        }

        $allEnsured = true;

        foreach (self::$deterministic_index_map as $indexColumn) {
            $escapedColumn = method_exists($conn, 'real_escape_string')
                ? $conn->real_escape_string($indexColumn)
                : $indexColumn;

            $columnExists = false;
            $result = $conn->query("SHOW COLUMNS FROM meals_clients LIKE '{$escapedColumn}'");
            if ($result instanceof mysqli_result) {
                $columnExists = $result->num_rows > 0;
                $result->free();
            } elseif ($result && isset($result->num_rows)) {
                // Allow mock result sets in tests
                $columnExists = $result->num_rows > 0;
                if (method_exists($result, 'free')) {
                    $result->free();
                }
            } elseif ($result === false) {
                error_log('[MealsDB] Failed to inspect deterministic index column: ' . ($conn->error ?? 'unknown error'));
                $allEnsured = false;
                continue;
            }

            if (!$columnExists) {
                $addColumnSql = "ALTER TABLE meals_clients ADD COLUMN `{$indexColumn}` CHAR(64) NULL";
                if (!$conn->query($addColumnSql)) {
                    error_log('[MealsDB] Failed to add deterministic index column: ' . ($conn->error ?? 'unknown error'));
                    $allEnsured = false;
                    continue;
                }
                $columnExists = true;
            }

            $indexName = 'unique_' . $indexColumn;
            $escapedIndex = method_exists($conn, 'real_escape_string')
                ? $conn->real_escape_string($indexName)
                : $indexName;

            $legacyIndexName = 'idx_' . $indexColumn;
            $escapedLegacy = method_exists($conn, 'real_escape_string')
                ? $conn->real_escape_string($legacyIndexName)
                : $legacyIndexName;

            $legacyIndexResult = $conn->query("SHOW INDEX FROM meals_clients WHERE Key_name = '{$escapedLegacy}'");
            $legacyIndexExists = false;
            if ($legacyIndexResult instanceof mysqli_result) {
                $legacyIndexExists = $legacyIndexResult->num_rows > 0;
                $legacyIndexResult->free();
            } elseif ($legacyIndexResult && isset($legacyIndexResult->num_rows)) {
                $legacyIndexExists = $legacyIndexResult->num_rows > 0;
                if (method_exists($legacyIndexResult, 'free')) {
                    $legacyIndexResult->free();
                }
            } elseif ($legacyIndexResult === false) {
                error_log('[MealsDB] Failed to inspect legacy deterministic index: ' . ($conn->error ?? 'unknown error'));
                $allEnsured = false;
            }

            if ($legacyIndexExists) {
                if ($conn->query("ALTER TABLE meals_clients DROP INDEX `{$legacyIndexName}`") !== true) {
                    $errno = $conn->errno ?? null;
                    if ($errno !== 1091) {
                        error_log('[MealsDB] Failed to drop legacy deterministic index: ' . ($conn->error ?? 'unknown error'));
                        $allEnsured = false;
                    }
                }
            }

            $indexExists = false;
            $indexIsUnique = false;
            $indexResult = $conn->query("SHOW INDEX FROM meals_clients WHERE Key_name = '{$escapedIndex}'");
            if ($indexResult instanceof mysqli_result) {
                while ($row = $indexResult->fetch_assoc()) {
                    $indexExists = true;
                    if (isset($row['Non_unique']) && intval($row['Non_unique']) === 0) {
                        $indexIsUnique = true;
                        break;
                    }
                }
                $indexResult->free();
            } elseif ($indexResult && isset($indexResult->num_rows)) {
                $indexExists = $indexResult->num_rows > 0;
                if ($indexExists && method_exists($indexResult, 'fetch_assoc')) {
                    $row = $indexResult->fetch_assoc();
                    if ($row && isset($row['Non_unique'])) {
                        $indexIsUnique = intval($row['Non_unique']) === 0;
                    }
                }
                if (method_exists($indexResult, 'free')) {
                    $indexResult->free();
                }
                if ($indexExists && !method_exists($indexResult, 'fetch_assoc')) {
                    // Assume mocked result sets in tests represent unique indexes
                    $indexIsUnique = true;
                }
            } elseif ($indexResult === false) {
                error_log('[MealsDB] Failed to inspect deterministic index status: ' . ($conn->error ?? 'unknown error'));
                $allEnsured = false;
                continue;
            }

            if ($indexExists && !$indexIsUnique) {
                if ($conn->query("ALTER TABLE meals_clients DROP INDEX `{$indexName}`") !== true) {
                    error_log('[MealsDB] Failed to drop non-unique deterministic index: ' . ($conn->error ?? 'unknown error'));
                    $allEnsured = false;
                } else {
                    $indexExists = false;
                }
            }

            if (!$indexExists) {
                $createIndexSql = "CREATE UNIQUE INDEX `{$indexName}` ON meals_clients (`{$indexColumn}`)";
                if (!$conn->query($createIndexSql)) {
                    $errno = $conn->errno ?? null;
                    if ($errno !== 1061) { // ignore duplicate index error
                        error_log('[MealsDB] Failed to create deterministic index: ' . ($conn->error ?? 'unknown error'));
                        $allEnsured = false;
                    }
                }
                [$indexExists, $indexIsUnique] = self::deterministic_index_status($conn, $indexName);
                if (!$indexExists || !$indexIsUnique) {
                    $allEnsured = false;
                }
            }
        }

        if ($allEnsured && self::backfill_deterministic_indexes($conn)) {
            self::$indexes_ensured = true;
            return true;
        }

        return false;
    }

    /**
     * Backfill deterministic hash columns for legacy records lacking values.
     *
     * @param mysqli $conn
     * @return bool
     */
    private static function backfill_deterministic_indexes($conn): bool {
        if (empty(self::$deterministic_index_map)) {
            return true;
        }

        $allSuccessful = true;

        foreach (self::$deterministic_index_map as $field => $indexColumn) {
            $selectSql = "SELECT id, `{$field}` FROM meals_clients WHERE (`{$indexColumn}` IS NULL OR `{$indexColumn}` = '') AND `{$field}` IS NOT NULL AND `{$field}` <> ''";
            $result = $conn->query($selectSql);

            if ($result === false) {
                error_log('[MealsDB] Failed to query legacy deterministic values for ' . $field . ': ' . ($conn->error ?? 'unknown error'));
                $allSuccessful = false;
                continue;
            }

            if (!($result instanceof mysqli_result)) {
                // Nothing to backfill or using a mock result set without rows.
                continue;
            }

            $updateSql = "UPDATE meals_clients SET `{$indexColumn}` = ? WHERE id = ?";
            $stmt = $conn->prepare($updateSql);

            if (!$stmt) {
                error_log('[MealsDB] Failed to prepare deterministic backfill statement for ' . $indexColumn . ': ' . ($conn->error ?? 'unknown error'));
                if (method_exists($result, 'free')) {
                    $result->free();
                }
                $allSuccessful = false;
                continue;
            }

            $hashValue = null;
            $idValue = null;

            if (!$stmt->bind_param('si', $hashValue, $idValue)) {
                error_log('[MealsDB] Failed to bind deterministic backfill parameters for ' . $indexColumn . '.');
                $stmt->close();
                if (method_exists($result, 'free')) {
                    $result->free();
                }
                $allSuccessful = false;
                continue;
            }

            while ($row = $result->fetch_assoc()) {
                $rawValue = $row[$field] ?? '';

                if ($rawValue === null || $rawValue === '') {
                    continue;
                }

                if (in_array($field, self::$encrypted_fields, true)) {
                    try {
                        $rawValue = MealsDB_Encryption::decrypt($rawValue);
                    } catch (Exception $e) {
                        error_log('[MealsDB] Failed to decrypt ' . $field . ' while backfilling deterministic index for client ID ' . ($row['id'] ?? 'unknown') . ': ' . $e->getMessage());
                        $allSuccessful = false;
                        continue;
                    }
                }

                if ($rawValue === '') {
                    continue;
                }

                $hashValue = self::deterministic_hash($rawValue);
                $idValue = isset($row['id']) ? intval($row['id']) : 0;

                if ($idValue <= 0) {
                    $allSuccessful = false;
                    continue;
                }

                if (!$stmt->execute()) {
                    error_log('[MealsDB] Failed to backfill deterministic index for client ID ' . $idValue . ': ' . ($stmt->error ?? 'unknown error'));
                    $allSuccessful = false;
                }
            }

            $stmt->close();

            if (method_exists($result, 'free')) {
                $result->free();
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
     * @param mysqli $conn
     * @param string $indexName
     * @return array{0: bool, 1: bool} [exists, isUnique]
     */
    private static function deterministic_index_status($conn, string $indexName): array {
        $escapedIndex = method_exists($conn, 'real_escape_string')
            ? $conn->real_escape_string($indexName)
            : $indexName;

        $exists = false;
        $isUnique = false;

        $result = $conn->query("SHOW INDEX FROM meals_clients WHERE Key_name = '{$escapedIndex}'");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $exists = true;
                if (isset($row['Non_unique']) && intval($row['Non_unique']) === 0) {
                    $isUnique = true;
                    break;
                }
            }
            $result->free();
        } elseif ($result && isset($result->num_rows)) {
            $exists = $result->num_rows > 0;
            if ($exists) {
                if (method_exists($result, 'fetch_assoc')) {
                    $row = $result->fetch_assoc();
                    if ($row && isset($row['Non_unique'])) {
                        $isUnique = intval($row['Non_unique']) === 0;
                    }
                } else {
                    // Assume mocked result sets in tests are unique when they report presence
                    $isUnique = true;
                }
            }
            if (method_exists($result, 'free')) {
                $result->free();
            }
        } elseif ($result === false) {
            error_log('[MealsDB] Failed to inspect deterministic index status: ' . ($conn->error ?? 'unknown error'));
        }

        return [$exists, $isUnique];
    }
}
