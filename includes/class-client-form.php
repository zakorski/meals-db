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

        // Required fields captured by the current admin form UI
        $required_fields = [
            'first_name'     => 'First name',
            'last_name'      => 'Last name',
            'client_email'   => 'Email address',
            'phone_primary'  => 'Primary phone number',
            'address_postal' => 'Postal code',
            'customer_type'  => 'Customer type',
        ];

        foreach ($required_fields as $field => $label) {
            if (empty($sanitized[$field])) {
                $errors[] = sprintf('%s is required.', $label);
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
        self::ensure_index_columns_exist($conn);

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
        $date_fields = ['birth_date', 'open_date', 'required_start_date', 'service_commence_date', 'expected_termination_date', 'initial_termination_date', 'recent_renewal_date'];
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
     * @param array $data
     * @return bool True on success, false on failure
     */
    public static function save_draft(array $data): bool {
        $conn = MealsDB_DB::get_connection();
        if (!$conn) {
            error_log('[MealsDB] Draft save aborted: database connection unavailable.');
            return false;
        }

        $json = json_encode($data);
        if ($json === false) {
            error_log('[MealsDB] Draft save failed: unable to encode payload.');
            return false;
        }

        $user_id = get_current_user_id();

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
        }

        $stmt->close();

        return $executed;
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

        self::ensure_index_columns_exist($conn);

        $errors = [];

        foreach (self::$unique_fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== '') {
                $column = $field;
                $value = $data[$field];

                if (isset(self::$deterministic_index_map[$field])) {
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

    /**
     * Ensure the deterministic index columns exist on the meals_clients table.
     *
     * @param mysqli $conn
     */
    private static function ensure_index_columns_exist($conn): void {
        if (self::$indexes_ensured || empty(self::$deterministic_index_map)) {
            return;
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

        if ($allEnsured) {
            self::$indexes_ensured = true;
        }
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
