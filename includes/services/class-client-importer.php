<?php
/**
 * Handles CSV import of clients into Meals DB.
 *
 * @package MealsDB
 */

class MealsDB_Client_Importer {

    private $dry_run = false;
    private $import_id = null;
    private $stats = [
        'total' => 0,
        'success' => 0,
        'skipped' => 0,
        'errors' => 0,
        'wp_users_created' => 0,
        'wp_users_existing' => 0,
        'with_initials' => 0,
        'need_initials' => 0,
        'with_emails' => 0,
        'encrypted_individual_ids' => 0,
        'encrypted_requisition_ids' => 0,
    ];
    private $errors = [];
    private $used_initials = [];

    /**
     * Column mapping from CSV to database
     */
    private $column_mapping = [
        // Basic Info
        0 => 'last_name',
        1 => 'first_name',
        2 => 'client_type',
        3 => 'open_date',
        4 => 'assigned_worker_name',
        5 => 'assigned_worker_email',

        // Primary Address (columns 6-12)
        6 => 'street_number',
        7 => 'street_name',
        8 => 'street_type',
        9 => 'apartment_number',
        10 => 'city',
        11 => 'province',
        12 => 'postal_code',

        // Contact Info
        13 => 'client_phone_1',
        14 => 'client_phone_2',
        15 => 'alternate_contact_name',
        16 => 'alternate_contact_phone_1',
        17 => 'alternate_contact_phone_2',
        18 => 'do_not_call_client_phone',

        // Personal Info
        19 => 'individual_id',  // ENCRYPTED
        20 => 'birth_date',
        21 => 'gender',

        // Service Info
        22 => 'service_center_charged',
        23 => 'vendor_number',
        24 => 'service_id',
        25 => 'requisition_id',  // ENCRYPTED
        26 => 'payment_method',
        27 => 'service_name_zone',
        28 => 'service_name_course',
        29 => 'required_start_date',
        30 => 'service_commence_date',
        31 => 'expected_termination_date',
        32 => 'initial_renewal_termination_date',
        33 => 'most_recent_renewal_termination_date',
        34 => 'notes_to_service_provider',
        35 => 'units',
        36 => 'vet_health_id_card',
        37 => 'meal_type',
        38 => 'requisition_period',
        39 => 'rate',
        40 => 'client_contribution',

        // Contact
        41 => 'client_email',
        42 => 'alternate_contact_email',

        // Delivery Info
        43 => 'initials_delivery',
        44 => 'delivery_day',
        45 => 'delivery_area_name',
        46 => 'delivery_area_zone',
        47 => 'ordering_frequency',
        48 => 'ordering_contact_method',
        49 => 'delivery_frequency',
        50 => 'freezer_capacity',
        51 => 'delivery_fee',
        52 => 'diet_concerns',  // ENCRYPTED
        53 => 'customer_comments',  // ENCRYPTED

        // Alternate Delivery Address (columns 54-60)
        54 => 'delivery_street_number',
        55 => 'delivery_street_name',
        56 => 'delivery_street_type',
        57 => 'delivery_apartment_number',
        58 => 'delivery_city',
        59 => 'delivery_province',
        60 => 'delivery_postal_code',
    ];

    /**
     * Fields that require encryption
     */
    private $encrypted_fields = [
        'individual_id',
        'requisition_id',
        'diet_concerns',
        'customer_comments',
    ];

    public function __construct($dry_run = false) {
        $this->dry_run = $dry_run;
        $this->import_id = uniqid('import_');
    }

    /**
     * Validate CSV file before import
     */
    public function validate_csv($file_path) {
        if (!file_exists($file_path)) {
            return [
                'valid' => false,
                'message' => __('CSV file not found.', 'meals-db'),
            ];
        }

        // Check file size (max 5MB)
        $file_size = filesize($file_path);
        if ($file_size > 5 * 1024 * 1024) {
            return [
                'valid' => false,
                'message' => __('File too large. Maximum size is 5MB.', 'meals-db'),
            ];
        }

        // Try to read CSV
        try {
            $rows = $this->read_csv($file_path);

            if (empty($rows)) {
                return [
                    'valid' => false,
                    'message' => __('CSV file appears to be empty.', 'meals-db'),
                ];
            }

            // Collect statistics
            $preview_rows = array_slice($rows, 0, 5);
            $preview_data = [];

            foreach ($preview_rows as $row) {
                $data = $this->map_csv_to_data($row);
                if (!empty($data['first_name']) && !empty($data['last_name'])) {
                    $preview_data[] = $data;

                    // Count stats
                    if (!empty($data['initials_delivery'])) {
                        $this->stats['with_initials']++;
                    } else {
                        $this->stats['need_initials']++;
                    }

                    if (!empty($data['client_email'])) {
                        $this->stats['with_emails']++;
                    }

                    if (!empty($data['individual_id'])) {
                        $this->stats['encrypted_individual_ids']++;
                    }

                    if (!empty($data['requisition_id'])) {
                        $this->stats['encrypted_requisition_ids']++;
                    }
                }
            }

            return [
                'valid' => true,
                'total_rows' => count($rows),
                'preview' => $preview_data,
                'stats' => [
                    'with_initials' => $this->stats['with_initials'],
                    'need_initials' => $this->stats['need_initials'],
                    'with_emails' => $this->stats['with_emails'],
                    'encrypted_individual_ids' => $this->stats['encrypted_individual_ids'],
                    'encrypted_requisition_ids' => $this->stats['encrypted_requisition_ids'],
                ],
            ];
        } catch (Exception $e) {
            return [
                'valid' => false,
                'message' => sprintf(__('Error reading CSV: %s', 'meals-db'), $e->getMessage()),
            ];
        }
    }

    /**
     * Import clients from CSV file
     */
    public function import_from_csv($file_path, $dry_run = false) {
        $this->dry_run = $dry_run;

        if (!file_exists($file_path)) {
            return [
                'success' => false,
                'message' => __('CSV file not found.', 'meals-db'),
            ];
        }

        // Load all existing initials to check for duplicates
        $this->load_existing_initials();

        // Read CSV
        try {
            $rows = $this->read_csv($file_path);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => sprintf(__('Error reading CSV: %s', 'meals-db'), $e->getMessage()),
            ];
        }

        if (empty($rows)) {
            return [
                'success' => false,
                'message' => __('CSV file is empty.', 'meals-db'),
            ];
        }

        // Reset stats
        $this->stats = array_merge($this->stats, [
            'total' => count($rows),
            'success' => 0,
            'errors' => 0,
        ]);

        // Process each client
        foreach ($rows as $index => $row) {
            $row_number = $index + 1;

            // Update progress
            $this->update_progress($row_number, count($rows));

            try {
                $this->import_client($row, $row_number);
                $this->stats['success']++;
            } catch (Exception $e) {
                $this->stats['errors']++;
                $this->errors[] = sprintf(__('Row %d: %s', 'meals-db'), $row_number, $e->getMessage());
            }
        }

        // Store errors in transient
        if (!empty($this->errors)) {
            set_transient('mealsdb_import_errors_' . $this->import_id, $this->errors, HOUR_IN_SECONDS);
        }

        return [
            'success' => true,
            'import_id' => $this->import_id,
            'stats' => $this->stats,
            'errors' => $this->errors,
            'dry_run' => $this->dry_run,
        ];
    }

    /**
     * Read CSV file and return data rows
     */
    private function read_csv($file) {
        $handle = fopen($file, 'r');
        if (!$handle) {
            throw new Exception(__('Could not open CSV file.', 'meals-db'));
        }

        // Skip first 8 rows (manual data)
        for ($i = 0; $i < 8; $i++) {
            fgetcsv($handle);
        }

        // Skip section header row (row 9)
        fgetcsv($handle);

        // Skip column header row (row 10)
        fgetcsv($handle);

        // Read all data rows
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            // Skip empty rows
            if (empty($row[0]) && empty($row[1])) {
                continue;
            }
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Import a single client
     */
    private function import_client($csv_row, $row_number) {
        // Extract data
        $data = $this->map_csv_to_data($csv_row);

        // Validate required fields
        if (empty($data['first_name']) || empty($data['last_name'])) {
            throw new Exception(__('Missing first or last name', 'meals-db'));
        }

        // Generate initials if needed
        if (empty($data['initials_delivery'])) {
            $data['initials_delivery'] = $this->generate_initials(
                $data['first_name'],
                $data['last_name'],
                $this->used_initials
            );
        } else {
            // Validate existing initials
            if (in_array($data['initials_delivery'], $this->used_initials)) {
                throw new Exception(sprintf(
                    __('Duplicate delivery initials: %s', 'meals-db'),
                    $data['initials_delivery']
                ));
            }
        }

        // Add to used initials
        $this->used_initials[] = $data['initials_delivery'];

        if ($this->dry_run) {
            return; // Don't actually import in dry run mode
        }

        // Create WordPress user
        $wp_user_id = $this->create_wp_user($data);
        if (!$wp_user_id) {
            throw new Exception(__('Failed to create WordPress user', 'meals-db'));
        }

        // Insert client record
        $client_id = $this->insert_client($data, $wp_user_id);
        if (!$client_id) {
            // Rollback: delete the WP user we just created
            wp_delete_user($wp_user_id);
            throw new Exception(__('Failed to insert client record', 'meals-db'));
        }
    }

    /**
     * Map CSV row to data array
     */
    private function map_csv_to_data($row) {
        $data = [];

        foreach ($this->column_mapping as $csv_index => $db_field) {
            $value = isset($row[$csv_index]) ? trim($row[$csv_index]) : '';

            // Skip empty values
            if ($value === '') {
                continue;
            }

            // Transform data
            $value = $this->transform_value($db_field, $value);

            $data[$db_field] = $value;
        }

        return $data;
    }

    /**
     * Transform value based on field type
     */
    private function transform_value($field, $value) {
        // Handle dates
        if (strpos($field, '_date') !== false || $field === 'open_date') {
            return $this->transform_date($value);
        }

        // Handle phone numbers
        if (strpos($field, 'phone') !== false) {
            return $this->transform_phone($value);
        }

        // Handle postal codes
        if (strpos($field, 'postal') !== false) {
            return $this->transform_postal($value);
        }

        // Handle client type
        if ($field === 'client_type') {
            return $this->transform_client_type($value);
        }

        // Handle do not call
        if ($field === 'do_not_call_client_phone') {
            return ($value === 'call alternate') ? 1 : 0;
        }

        // Handle numeric fields
        if (in_array($field, ['rate', 'client_contribution', 'delivery_fee'])) {
            return floatval(str_replace(['$', ','], '', $value));
        }

        if (in_array($field, ['ordering_frequency', 'delivery_frequency', 'units'])) {
            return intval($value);
        }

        return $value;
    }

    /**
     * Transform date from YYYY/MM/DD to YYYY-MM-DD
     */
    private function transform_date($value) {
        if (empty($value)) return null;
        return str_replace('/', '-', $value);
    }

    /**
     * Transform phone number
     */
    private function transform_phone($value) {
        if (empty($value)) return null;

        // Remove all non-digits
        $digits = preg_replace('/\D/', '', $value);

        // Format as (###)-###-####
        if (strlen($digits) === 10) {
            return '(' . substr($digits, 0, 3) . ')-' .
                   substr($digits, 3, 3) . '-' .
                   substr($digits, 6, 4);
        }

        return $value; // Return as-is if not 10 digits
    }

    /**
     * Transform postal code to A1A1A1 format
     */
    private function transform_postal($value) {
        if (empty($value)) return null;

        // Remove spaces and uppercase
        $postal = strtoupper(str_replace(' ', '', $value));

        // Validate Canadian postal code format
        if (preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $postal)) {
            return $postal;
        }

        return $value; // Return as-is if invalid
    }

    /**
     * Transform client type to standard format
     */
    private function transform_client_type($value) {
        $value = strtolower(trim($value));

        $mapping = [
            'sdnb' => 'SDNB',
            'sdnbr' => 'SDNB',
            'sdnb rural' => 'SDNB',
            'vet' => 'Veteran',
            'veteran' => 'Veteran',
            'private' => 'Private',
        ];

        return $mapping[$value] ?? 'Private';
    }

    /**
     * Generate initials from first and last name
     */
    public function generate_initials($first_name, $last_name, $used_initials = []) {
        $first = strtoupper(substr($first_name, 0, 1));
        $last = strtoupper($last_name);

        $banned_words = [
            'ASS', 'SEX', 'TIT', 'CUM', 'FAG', 'GAY', 'GOD', 'JES', 'NIG', 'WTF', 'XXX', 'KKK'
        ];

        // Strategy 1: First initial + first 2 of last name
        $initials = $first . substr($last, 0, 2);
        if (!in_array($initials, $banned_words) && !in_array($initials, $used_initials) && !MealsDB_Initials::exists_in_db($initials)) {
            return $initials;
        }

        // Strategy 2: First initial + last 2 of last name
        $initials = $first . substr($last, -2);
        if (!in_array($initials, $banned_words) && !in_array($initials, $used_initials) && !MealsDB_Initials::exists_in_db($initials)) {
            return $initials;
        }

        // Strategy 3: First 2 of first + first of last
        $initials = substr(strtoupper($first_name), 0, 2) . substr($last, 0, 1);
        if (!in_array($initials, $banned_words) && !in_array($initials, $used_initials) && !MealsDB_Initials::exists_in_db($initials)) {
            return $initials;
        }

        // Fallback: Use random generation
        return MealsDB_Initials::generate();
    }

    /**
     * Load all existing initials from database
     */
    private function load_existing_initials() {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return;
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $sql = sprintf("SELECT initials_delivery FROM `%s` WHERE initials_delivery IS NOT NULL", str_replace('`', '``', $table));

        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $this->used_initials[] = $row['initials_delivery'];
            }
            $result->free();
        }
    }

    /**
     * Create WordPress user
     */
    private function create_wp_user($data) {
        $username = $this->generate_username($data);
        $email = !empty($data['client_email']) ? $data['client_email'] :
                 $username . '@mealsandmore.local';

        // Check if user already exists
        $existing_user = get_user_by('login', $username);
        if ($existing_user) {
            $this->stats['wp_users_existing']++;
            return $existing_user->ID;
        }

        // Create user
        $user_id = wp_create_user(
            $username,
            wp_generate_password(20, true, true),
            $email
        );

        if (is_wp_error($user_id)) {
            throw new Exception(sprintf(__('WP user creation failed: %s', 'meals-db'), $user_id->get_error_message()));
        }

        // Set role to customer
        $user = new WP_User($user_id);
        $user->set_role('customer');

        // Update user meta
        update_user_meta($user_id, 'first_name', $data['first_name']);
        update_user_meta($user_id, 'last_name', $data['last_name']);

        $this->stats['wp_users_created']++;

        return $user_id;
    }

    /**
     * Generate WordPress username
     */
    private function generate_username($data) {
        $base = strtolower(
            sanitize_user($data['first_name']) . '.' .
            sanitize_user($data['last_name'])
        );

        // If username exists, append number
        $username = $base;
        $counter = 1;
        while (username_exists($username)) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Insert client into meals_clients table
     */
    private function insert_client($data, $wp_user_id) {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            throw new Exception(__('Database connection failed', 'meals-db'));
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // Prepare data for insertion
        $insert_data = [
            'wp_user_id' => $wp_user_id,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'client_type' => $data['client_type'] ?? 'Private',
            'active' => 1,
        ];

        // Map all fields
        $field_map = [
            'client_email' => 'client_email',
            'client_phone_1' => 'client_phone_1',
            'client_phone_2' => 'client_phone_2',
            'alternate_contact_name' => 'alternate_contact_name',
            'alternate_contact_phone_1' => 'alternate_contact_phone_1',
            'alternate_contact_phone_2' => 'alternate_contact_phone_2',
            'alternate_contact_email' => 'alternate_contact_email',
            'do_not_call_client_phone' => 'do_not_call_client_phone',
            'payment_method' => 'payment_method',
            'open_date' => 'open_date',
            'birth_date' => 'birth_date',
            'gender' => 'gender',
            'assigned_worker_name' => 'assigned_worker_name',
            'assigned_worker_email' => 'assigned_worker_email',
            'vendor_number' => 'vendor_number',
            'service_center_charged' => 'service_center_charged',
            'service_id' => 'service_id',
            'requisition_period' => 'requisition_period',
            'meal_type' => 'meal_type',
            'service_name_zone' => 'service_name_zone',
            'service_name_course' => 'service_name_course',
            'service_commence_date' => 'service_commence_date',
            'expected_termination_date' => 'expected_termination_date',
            'initial_renewal_termination_date' => 'initial_renewal_termination_date',
            'most_recent_renewal_termination_date' => 'most_recent_renewal_termination_date',
            'notes_to_service_provider' => 'notes_to_service_provider',
            'client_contribution' => 'client_contribution',
            'vet_health_id_card' => 'vet_health_id_card',
            'rate' => 'rate',
            'required_start_date' => 'required_start_date',
            'delivery_day' => 'delivery_day',
            'delivery_area_name' => 'delivery_area_name',
            'delivery_area_zone' => 'delivery_area_zone',
            'ordering_contact_method' => 'ordering_contact_method',
            'ordering_frequency' => 'ordering_frequency',
            'delivery_frequency' => 'delivery_frequency',
            'freezer_capacity' => 'freezer_capacity',
            'delivery_fee' => 'delivery_fee',
            'initials_delivery' => 'initials_delivery',
            'street_number' => 'street_number',
            'street_name' => 'street_name',
            'apartment_number' => 'apartment_number',
            'city' => 'city',
            'province' => 'province',
            'postal_code' => 'postal_code',
            'delivery_street_number' => 'delivery_street_number',
            'delivery_street_name' => 'delivery_street_name',
            'delivery_apartment_number' => 'delivery_apartment_number',
            'delivery_city' => 'delivery_city',
            'delivery_province' => 'delivery_province',
            'delivery_postal_code' => 'delivery_postal_code',
        ];

        foreach ($field_map as $source => $target) {
            if (isset($data[$source]) && $data[$source] !== '') {
                $insert_data[$target] = $data[$source];
            }
        }

        // Handle encrypted fields
        if (isset($data['individual_id']) && $data['individual_id'] !== '') {
            $insert_data['individual_id'] = MealsDB_Encryption::encrypt($data['individual_id']);
        }

        if (isset($data['requisition_id']) && $data['requisition_id'] !== '') {
            $insert_data['requisition_id'] = MealsDB_Encryption::encrypt($data['requisition_id']);
        }

        if (isset($data['diet_concerns']) && $data['diet_concerns'] !== '') {
            $insert_data['diet_concerns'] = MealsDB_Encryption::encrypt($data['diet_concerns']);
        }

        if (isset($data['customer_comments']) && $data['customer_comments'] !== '') {
            $insert_data['customer_comments'] = MealsDB_Encryption::encrypt($data['customer_comments']);
        }

        // Build INSERT query
        $columns = array_keys($insert_data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            "INSERT INTO `%s` (`%s`) VALUES (%s)",
            str_replace('`', '``', $table),
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception(sprintf(__('Failed to prepare statement: %s', 'meals-db'), $conn->error));
        }

        // Bind parameters
        $types = '';
        $values = [];
        foreach ($insert_data as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $value;
        }

        $bind_params = array_merge([$types], $values);
        $refs = [];
        foreach ($bind_params as $key => $value) {
            $refs[$key] = &$bind_params[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $refs);

        // Execute
        if (!$stmt->execute()) {
            throw new Exception(sprintf(__('Failed to insert client: %s', 'meals-db'), $stmt->error));
        }

        $client_id = $stmt->insert_id;
        $stmt->close();

        return $client_id;
    }

    /**
     * Update import progress
     */
    private function update_progress($current, $total) {
        $progress = [
            'current' => $current,
            'total' => $total,
            'percent' => round(($current / $total) * 100, 1),
        ];

        set_transient('mealsdb_import_progress_' . $this->import_id, $progress, HOUR_IN_SECONDS);
    }

    /**
     * Get import progress
     */
    public static function get_progress($import_id) {
        $progress = get_transient('mealsdb_import_progress_' . $import_id);
        if ($progress === false) {
            return null;
        }
        return $progress;
    }

    /**
     * Get import errors
     */
    public static function get_errors($import_id) {
        $errors = get_transient('mealsdb_import_errors_' . $import_id);
        if ($errors === false) {
            return [];
        }
        return $errors;
    }
}
