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
        6 => 'address_street_number',
        7 => 'address_street_name',
        8 => 'address_street_type',  // Will be concatenated with street_name
        9 => 'address_unit',
        10 => 'address_city',
        11 => 'address_province',
        12 => 'address_postal',

        // Contact Info
        13 => 'phone_primary',
        14 => 'phone_secondary',
        15 => 'alternate_contact_name',
        16 => 'alternate_contact_phone_1',
        17 => 'alternate_contact_phone_2',
        18 => 'do_not_call_client_phone',

        // Personal Info
        19 => 'individual_id',  // ENCRYPTED - SDNB client identifier
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
        36 => 'vet_health_card',
        37 => 'meal_type',
        38 => 'requisition_period',
        39 => 'rate',
        40 => 'client_contribution',

        // Contact
        41 => 'client_email',
        42 => 'alternate_contact_email',

        // Delivery Info
        43 => 'delivery_initials',
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
        54 => 'delivery_address_street_number',
        55 => 'delivery_address_street_name',
        56 => 'delivery_address_street_type',  // Will be concatenated with street_name
        57 => 'delivery_address_unit',
        58 => 'delivery_address_city',
        59 => 'delivery_address_province',
        60 => 'delivery_address_postal',
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

    /**
     * Deterministic index columns used for uniqueness checks on encrypted data
     */
    private $deterministic_index_map = [
        'individual_id'      => 'individual_id_index',
        'requisition_id'     => 'requisition_id_index',
        'vet_health_card'    => 'vet_health_card_index',
        'delivery_initials'  => 'delivery_initials_index',
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
                    if (!empty($data['delivery_initials'])) {
                        $this->stats['with_initials']++;
                    } else {
                        $this->stats['need_initials']++;
                    }

                    if (!empty($data['client_email'])) {
                        $this->stats['with_emails']++;
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

        if (empty($data['client_type'])) {
            throw new Exception(__('Missing client type', 'meals-db'));
        }

        // Generate initials if needed
        if (empty($data['delivery_initials'])) {
            $data['delivery_initials'] = $this->generate_initials(
                $data['first_name'],
                $data['last_name'],
                $this->used_initials
            );
        } else {
            // Validate existing initials
            if (in_array($data['delivery_initials'], $this->used_initials)) {
                throw new Exception(sprintf(
                    __('Duplicate delivery initials: %s', 'meals-db'),
                    $data['delivery_initials']
                ));
            }
        }

        // Add to used initials
        $this->used_initials[] = $data['delivery_initials'];

        if ($this->dry_run) {
            return; // Don't actually import in dry run mode
        }

        // Create WordPress user
        $wp_user_id = $this->create_wp_user($data);
        if (!$wp_user_id) {
            throw new Exception(__('Failed to create WordPress user', 'meals-db'));
        }

        // Debug: Log the do_not_call_client_phone value before insert
        if (isset($data['do_not_call_client_phone'])) {
            error_log(sprintf(
                'Row %d: do_not_call_client_phone value = "%s" (type: %s)',
                $row_number,
                $data['do_not_call_client_phone'],
                gettype($data['do_not_call_client_phone'])
            ));
        } else {
            error_log(sprintf('Row %d: do_not_call_client_phone is NOT SET in $data array', $row_number));
        }

        // Debug: Log social worker fields
        error_log(sprintf('Row %d: assigned_worker_name = "%s" (isset: %s)',
            $row_number,
            $data['assigned_worker_name'] ?? 'NOT SET',
            isset($data['assigned_worker_name']) ? 'yes' : 'no'
        ));
        error_log(sprintf('Row %d: assigned_worker_email = "%s" (isset: %s)',
            $row_number,
            $data['assigned_worker_email'] ?? 'NOT SET',
            isset($data['assigned_worker_email']) ? 'yes' : 'no'
        ));

        // Debug: Log the raw CSV value at column 18
        if (isset($csv_row[18])) {
            error_log(sprintf('Row %d: CSV column 18 raw value = "%s"', $row_number, $csv_row[18]));
        } else {
            error_log(sprintf('Row %d: CSV column 18 does NOT exist', $row_number));
        }

        // Debug: Log CSV columns 4 and 5 (social worker fields)
        error_log(sprintf('Row %d: CSV column 4 (assigned_worker_name) = "%s"',
            $row_number, $csv_row[4] ?? 'NOT SET'));
        error_log(sprintf('Row %d: CSV column 5 (assigned_worker_email) = "%s"',
            $row_number, $csv_row[5] ?? 'NOT SET'));


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
            // Skip null field mappings (removed columns)
            if ($db_field === null) {
                continue;
            }

            $value = isset($row[$csv_index]) ? trim($row[$csv_index]) : '';

            // Debug: Log column 18 specifically
            if ($csv_index === 18) {
                error_log(sprintf('map_csv_to_data: column 18 -> field "%s", value="%s", isset=%s',
                    $db_field, $value, isset($row[$csv_index]) ? 'yes' : 'no'));
            }

            // Skip empty values
            if ($value === '') {
                if ($csv_index === 18) {
                    error_log('map_csv_to_data: column 18 is empty, skipping transformation');
                }
                continue;
            }

            // Transform data
            $value = $this->transform_value($db_field, $value);

            if ($csv_index === 18) {
                error_log(sprintf('map_csv_to_data: column 18 after transform = "%s" (type: %s)',
                    $value, gettype($value)));
            }

            $data[$db_field] = $value;
        }

        // Concatenate street type with street name
        if (!empty($data['address_street_type']) && !empty($data['address_street_name'])) {
            $data['address_street_name'] = trim($data['address_street_name'] . ' ' . $data['address_street_type']);
        }
        unset($data['address_street_type']);

        // Concatenate delivery street type with delivery street name
        if (!empty($data['delivery_address_street_type']) && !empty($data['delivery_address_street_name'])) {
            $data['delivery_address_street_name'] = trim($data['delivery_address_street_name'] . ' ' . $data['delivery_address_street_type']);
        }
        unset($data['delivery_address_street_type']);

        return $data;
    }

    /**
     * Transform value based on field type
     */
    private function transform_value($field, $value) {
        // Handle do not call (MUST be before phone check since field name contains 'phone')
        if ($field === 'do_not_call_client_phone') {
            // Normalize: remove control characters, lowercase, trim, collapse whitespace
            $normalized = preg_replace('/[\p{C}]/u', '', $value); // Remove control/non-printable chars
            $normalized = preg_replace('/\s+/', ' ', strtolower(trim($normalized)));

            // Check for various true-ish values
            if (in_array($normalized, ['call alternate', '1', 'yes', 'true', 'y', 'on'], true)) {
                return 1;
            }
            return 0;
        }

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
     * Transform date to MySQL YYYY-MM-DD format
     * Handles: YYYY/MM/DD, YYYY-MM-DD, M-D-YYYY, M/D/YYYY, etc.
     */
    private function transform_date($value) {
        if (empty($value)) return null;

        // Try to parse the date
        $timestamp = strtotime($value);

        // If strtotime couldn't parse it, try manual parsing
        if ($timestamp === false) {
            // Try YYYY/MM/DD or YYYY-MM-DD format
            if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $value, $matches)) {
                return sprintf('%04d-%02d-%02d', $matches[1], $matches[2], $matches[3]);
            }

            // Try M/D/YYYY or M-D-YYYY format
            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $matches)) {
                return sprintf('%04d-%02d-%02d', $matches[3], $matches[1], $matches[2]);
            }

            // Return original value if we can't parse it
            return $value;
        }

        // Convert to MySQL date format
        return date('Y-m-d', $timestamp);
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
        $sql = sprintf("SELECT delivery_initials FROM `%s` WHERE delivery_initials IS NOT NULL", str_replace('`', '``', $table));

        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $this->used_initials[] = $row['delivery_initials'];
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
            'client_type' => $data['client_type'],
            'active' => 1,
        ];

        // Map all fields
        $field_map = [
            'client_type' => 'client_type',
            'client_email' => 'client_email',
            'phone_primary' => 'client_phone_1',
            'phone_secondary' => 'client_phone_2',
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
            'units' => 'units',
            'client_contribution' => 'client_contribution',
            'vet_health_card' => 'vet_health_card',
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
            'delivery_initials' => 'delivery_initials',
            'address_street_number' => 'street_number',
            'address_street_name' => 'street_name',
            'address_unit' => 'apartment_number',
            'address_city' => 'city',
            'address_province' => 'province',
            'address_postal' => 'postal_code',
            'delivery_address_street_number' => 'delivery_street_number',
            'delivery_address_street_name' => 'delivery_street_name',
            'delivery_address_unit' => 'delivery_apartment_number',
            'delivery_address_city' => 'delivery_city',
            'delivery_address_province' => 'delivery_province',
            'delivery_address_postal' => 'delivery_postal_code',
        ];

        foreach ($field_map as $source => $target) {
            if (isset($data[$source]) && $data[$source] !== '') {
                $insert_data[$target] = $data[$source];
            }
        }

        // Debug: Log what's in insert_data for social worker fields
        error_log(sprintf('insert_data[assigned_worker_name] = "%s" (isset: %s)',
            $insert_data['assigned_worker_name'] ?? 'NOT SET',
            isset($insert_data['assigned_worker_name']) ? 'yes' : 'no'
        ));
        error_log(sprintf('insert_data[assigned_worker_email] = "%s" (isset: %s)',
            $insert_data['assigned_worker_email'] ?? 'NOT SET',
            isset($insert_data['assigned_worker_email']) ? 'yes' : 'no'
        ));


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

        // Generate deterministic indexes for uniqueness checks on encrypted/unique fields
        foreach ($this->deterministic_index_map as $field => $index_column) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $insert_data[$index_column] = $this->deterministic_hash($data[$field]);
            }
        }

        // Build INSERT query
        $columns = array_keys($insert_data);
        $placeholders = array_fill(0, count($columns), '?');

        // Debug: Log the columns being inserted
        error_log('INSERT columns: ' . implode(', ', $columns));
        if (in_array('assigned_worker_name', $columns)) {
            error_log('✓ assigned_worker_name column is in INSERT');
        } else {
            error_log('✗ assigned_worker_name column is MISSING from INSERT');
        }

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

    /**
     * Generate a deterministic hash for uniqueness checks
     *
     * @param string $value The value to hash
     * @return string SHA-256 hash
     */
    private function deterministic_hash($value) {
        return hash('sha256', strtolower(trim($value)));
    }
}
