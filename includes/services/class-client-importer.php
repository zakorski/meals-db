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
        'clients_created' => 0,
        'clients_updated' => 0,
        'clients_skipped' => 0,
        'with_initials' => 0,
        'need_initials' => 0,
        'with_emails' => 0,
        'encrypted_requisition_ids' => 0,
    ];
    private $errors = [];
    private $used_initials = [];
    private $log_file = null;
    private $log_enabled = true;

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
        28 => 'meal_type',
        29 => 'required_start_date',
        30 => 'service_commence_date',
        31 => 'expected_termination_date',
        32 => 'initial_renewal_termination_date',
        33 => 'most_recent_renewal_termination_date',
        34 => 'notes_to_service_provider',
        35 => 'units',
        36 => 'vet_health_card',
        37 => null,  // Previously meal_type, now unmapped (meal_type moved to column 28)
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
        $this->init_log_file();
    }

    /**
     * Get the import ID for this import session
     */
    public function get_import_id() {
        return $this->import_id;
    }

    /**
     * Initialize log file for detailed import logging
     */
    private function init_log_file() {
        if (!$this->log_enabled) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/mealsdb-import-logs/';

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // Add .htaccess to protect log files
            file_put_contents($log_dir . '.htaccess', "deny from all\n");
        }

        $log_filename = $this->import_id . '_' . date('Y-m-d_H-i-s') . '.log';
        $this->log_file = $log_dir . $log_filename;

        // Store log file path in transient
        set_transient('mealsdb_import_log_' . $this->import_id, $this->log_file, DAY_IN_SECONDS);

        // Write log header
        $this->write_log("=================================================================");
        $this->write_log("MEALS DB CLIENT IMPORT LOG");
        $this->write_log("Import ID: " . $this->import_id);
        $this->write_log("Started: " . date('Y-m-d H:i:s'));
        $this->write_log("Dry Run: " . ($this->dry_run ? 'YES' : 'NO'));
        $this->write_log("=================================================================\n");
    }

    /**
     * Write message to log file
     */
    private function write_log($message, $indent = 0) {
        if (!$this->log_enabled || !$this->log_file) {
            return;
        }

        $prefix = str_repeat('  ', $indent);
        $timestamp = date('[Y-m-d H:i:s]');
        $log_line = $timestamp . ' ' . $prefix . $message . "\n";

        file_put_contents($this->log_file, $log_line, FILE_APPEND);
    }

    /**
     * Get log file path for an import
     */
    public static function get_log_file($import_id) {
        return get_transient('mealsdb_import_log_' . $import_id);
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
            $this->write_log("✗ ERROR: " . $e->getMessage());
            return [
                'success' => false,
                'message' => sprintf(__('Error reading CSV: %s', 'meals-db'), $e->getMessage()),
                'import_id' => $this->import_id,
            ];
        }

        if (empty($rows)) {
            $this->write_log("✗ ERROR: CSV file is empty");
            return [
                'success' => false,
                'message' => __('CSV file is empty.', 'meals-db'),
                'import_id' => $this->import_id,
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

            $this->write_log("\n" . str_repeat('-', 60));
            $this->write_log("PROCESSING ROW #" . $row_number);
            $this->write_log(str_repeat('-', 60));

            try {
                $this->import_client($row, $row_number);
                $this->stats['success']++;
                $this->write_log("✓ ROW #" . $row_number . " IMPORTED SUCCESSFULLY");
            } catch (Exception $e) {
                $this->stats['errors']++;
                $error_msg = sprintf(__('Row %d: %s', 'meals-db'), $row_number, $e->getMessage());
                $this->errors[] = $error_msg;
                $this->write_log("✗ ROW #" . $row_number . " FAILED: " . $e->getMessage());
            }
        }

        // Write final summary to log
        $this->write_log("\n" . str_repeat('=', 60));
        $this->write_log("IMPORT SUMMARY");
        $this->write_log(str_repeat('=', 60));
        $this->write_log("Total Rows: " . $this->stats['total']);
        $this->write_log("Successful: " . $this->stats['success']);
        $this->write_log("Errors: " . $this->stats['errors']);
        $this->write_log("WP Users Created: " . $this->stats['wp_users_created']);
        $this->write_log("WP Users Existing: " . $this->stats['wp_users_existing']);
        $this->write_log("Clients Created: " . $this->stats['clients_created']);
        $this->write_log("Clients Updated: " . $this->stats['clients_updated']);
        $this->write_log("Clients Skipped: " . $this->stats['clients_skipped']);
        $this->write_log("Completed: " . date('Y-m-d H:i:s'));
        $this->write_log(str_repeat('=', 60));

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

        // Skip header row (row 1)
        fgetcsv($handle);

        // Read all data rows (starting from row 2)
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
        $this->write_log("Mapping CSV columns to database fields...", 1);
        $data = $this->map_csv_to_data($csv_row, $row_number);

        // Log client identification
        $client_name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
        $this->write_log("Client: " . ($client_name ?: '[NO NAME]'), 1);
        $this->write_log("Client Type: " . ($data['client_type'] ?? '[MISSING]'), 1);

        // Validate required fields
        if (empty($data['first_name']) || empty($data['last_name'])) {
            $this->write_log("✗ VALIDATION FAILED: Missing first or last name", 1);
            throw new Exception(__('Missing first or last name', 'meals-db'));
        }

        if (empty($data['client_type'])) {
            $this->write_log("✗ VALIDATION FAILED: Missing client type", 1);
            throw new Exception(__('Missing client type', 'meals-db'));
        }

        $this->write_log("✓ Required fields validated", 1);

        // Check if client already exists BEFORE validating initials
        $this->write_log("Checking if client already exists...", 1);
        $existing_client_id = $this->check_existing_client($data);

        if ($existing_client_id) {
            $this->write_log("✓ Client already exists (ID: " . $existing_client_id . ") - skipping initials validation", 1);
            // For existing clients, use their current initials if not provided in CSV
            if (empty($data['delivery_initials'])) {
                $this->write_log("Retrieving existing client's initials...", 2);
                $existing_initials = $this->get_existing_client_initials($existing_client_id);
                if ($existing_initials) {
                    $data['delivery_initials'] = $existing_initials;
                    $this->write_log("Using existing client's initials: " . $existing_initials, 2);
                }
            }
        } else {
            $this->write_log("✓ New client - validating delivery initials...", 1);

            // Generate initials if needed
            if (empty($data['delivery_initials'])) {
                $this->write_log("Generating delivery initials...", 2);
                $data['delivery_initials'] = $this->generate_initials(
                    $data['first_name'],
                    $data['last_name'],
                    $data
                );
                $this->write_log("Generated initials: " . $data['delivery_initials'], 2);
            } else {
                $this->write_log("Using provided initials: " . $data['delivery_initials'], 2);
                // Store original CSV initials for potential conflict logging
                $original_initials = $data['delivery_initials'];

                // Validate existing initials using new address-based validator
                $validation = MealsDB_Initials_Validator::validate(
                    $data['delivery_initials'],
                    $data,
                    null
                );

                if (!$validation['valid']) {
                    $this->write_log("✗ VALIDATION FAILED: " . $validation['error'], 2);

                    // Check if this is an initials conflict (not other validation errors)
                    // Conflicts typically contain "already in use" in the error message
                    if (stripos($validation['error'], 'already in use') !== false) {
                        $this->write_log("⚠ Initials conflict detected: " . $validation['error'], 2);
                        $client_name = trim($data['first_name'] . ' ' . $data['last_name']);
                        $this->write_log("Auto-generating alternative initials for " . $client_name . "...", 2);

                        // Generate new initials
                        $generated_initials = $this->generate_initials(
                            $data['first_name'],
                            $data['last_name'],
                            $data
                        );

                        // Check if generation was successful (XXX indicates failure)
                        if ($generated_initials === 'XXX') {
                            $this->write_log("✗ Failed to auto-generate valid initials", 2);
                            throw new Exception($validation['error']);
                        }

                        $this->write_log("Generated new initials: " . $generated_initials, 2);

                        // Validate the newly generated initials
                        $new_validation = MealsDB_Initials_Validator::validate(
                            $generated_initials,
                            $data,
                            null
                        );

                        if (!$new_validation['valid']) {
                            $this->write_log("✗ Auto-generated initials also failed validation: " . $new_validation['error'], 2);
                            throw new Exception($validation['error']); // Throw original error
                        }

                        $this->write_log("✓ New initials validated successfully", 2);

                        // Use the new initials
                        $data['delivery_initials'] = $generated_initials;

                        // Log the change for manual review
                        $this->write_log("⚠ INITIALS AUTO-CHANGED: CSV specified '" . $original_initials . "' but assigned '" . $generated_initials . "' due to conflict", 2);

                        if (!empty($new_validation['shared'])) {
                            $sharing_names = array_map(function($client) {
                                return trim($client['first_name'] . ' ' . $client['last_name']);
                            }, $new_validation['sharing_with']);
                            $this->write_log("✓ Initials shared with " . implode(', ', $sharing_names) . " at same address", 2);
                        }
                    } else {
                        // Other validation errors (not conflicts) - throw immediately
                        throw new Exception($validation['error']);
                    }
                } else {
                    // Validation passed on first try
                    if (!empty($validation['shared'])) {
                        $sharing_names = array_map(function($client) {
                            return trim($client['first_name'] . ' ' . $client['last_name']);
                        }, $validation['sharing_with']);
                        $this->write_log("✓ Initials shared with " . implode(', ', $sharing_names) . " at same address", 2);
                    }
                }
            }

            // Track used initials for current import batch
            $this->used_initials[] = $data['delivery_initials'];
            $this->write_log("✓ Delivery initials validated and reserved", 2);
        }

        // Determine what would happen (for both dry run and real run)
        if ($this->dry_run) {
            $this->write_log("DRY RUN MODE: Simulating database operations for statistics", 1);

            // Simulate WordPress user creation/linking
            $wp_user_simulation = $this->simulate_wp_user_creation($data);
            $this->write_log("Would " . ($wp_user_simulation['status'] === 'existing' ? 'link to existing' : 'create new') . " WordPress user", 2);

            // Simulate client creation/update
            $client_simulation = $this->simulate_client_creation($data, $wp_user_simulation);
            $this->write_log("Would " . $client_simulation['operation'] . " client record", 2);

            // Update statistics based on simulation
            if ($wp_user_simulation['status'] === 'existing') {
                $this->stats['wp_users_existing']++;
            } else {
                $this->stats['wp_users_created']++;
            }

            if ($client_simulation['operation'] === 'created') {
                $this->stats['clients_created']++;
            } elseif ($client_simulation['operation'] === 'updated') {
                $this->stats['clients_updated']++;
            } else {
                $this->stats['clients_skipped']++;
            }

            $this->write_log("✓ DRY RUN: Would import " . $data['first_name'] . ' ' . $data['last_name'] . ' (' . $data['delivery_initials'] . ')', 1);
            return; // Don't actually import in dry run mode
        }

        // Create WordPress user or link to existing one
        $this->write_log("Creating/checking WordPress user...", 1);
        $wp_user_result = $this->create_wp_user($data);
        if (!$wp_user_result || !isset($wp_user_result['user_id'])) {
            $this->write_log("✗ Failed to create/link WordPress user", 1);
            throw new Exception(__('Failed to create/link WordPress user', 'meals-db'));
        }

        $wp_user_id = $wp_user_result['user_id'];
        $wp_user_status = $wp_user_result['status'];

        // Log the WordPress user status
        if ($wp_user_status === 'existing') {
            $this->write_log("✓ WordPress user exists - linked to existing user ID: " . $wp_user_id, 1);
        } elseif ($wp_user_status === 'generated') {
            $this->write_log("✓ Created new WordPress user ID: " . $wp_user_id . " with generated email", 1);
        } else {
            $this->write_log("✓ Created new WordPress user ID: " . $wp_user_id, 1);
        }

        // Create or update client record
        $this->write_log("Creating or updating client record in database...", 1);
        $result = $this->create_or_update_client($data, $wp_user_id, $row_number);
        if (!$result) {
            $this->write_log("✗ Failed to create/update client record", 1);

            // Rollback: Only delete the WP user if we just created it (not if we linked to existing)
            if ($wp_user_status !== 'existing') {
                wp_delete_user($wp_user_id);
                $this->write_log("Rolled back WordPress user creation (deleted user ID: " . $wp_user_id . ")", 2);
            } else {
                $this->write_log("WordPress user was existing - not deleting", 2);
            }

            throw new Exception(__('Failed to create/update client record', 'meals-db'));
        }

        $client_id = $result['client_id'];
        $operation = $result['operation'];

        if ($operation === 'updated') {
            $this->stats['clients_updated']++;
            $this->write_log("✓ Client record updated (ID: " . $client_id . ") - filled " . $result['fields_updated'] . " NULL fields", 2);
            $this->write_log("✓ Meals DB client updated for: " . $data['first_name'] . ' ' . $data['last_name'] . ' (' . $data['delivery_initials'] . ')', 1);
        } elseif ($operation === 'skipped') {
            $this->stats['clients_skipped']++;
            $this->write_log("✓ Client record skipped (ID: " . $client_id . ") - no NULL fields to update", 2);
            $this->write_log("✓ Meals DB client already complete for: " . $data['first_name'] . ' ' . $data['last_name'] . ' (' . $data['delivery_initials'] . ')', 1);
        } else {
            $this->stats['clients_created']++;
            $this->write_log("✓ Client record created with ID: " . $client_id, 2);
            $this->write_log("✓ Meals DB client created for: " . $data['first_name'] . ' ' . $data['last_name'] . ' (' . $data['delivery_initials'] . ')', 1);
        }
    }

    /**
     * Map CSV row to data array
     */
    private function map_csv_to_data($row, $row_number = null) {
        $data = [];
        $mapped_count = 0;
        $empty_count = 0;
        $transformed_count = 0;

        foreach ($this->column_mapping as $csv_index => $db_field) {
            // Skip null field mappings (removed columns)
            if ($db_field === null) {
                continue;
            }

            $value = isset($row[$csv_index]) ? trim($row[$csv_index]) : '';

            // Skip empty values
            if ($value === '') {
                $empty_count++;
                continue;
            }

            // Transform data
            $original_value = $value;
            $value = $this->transform_value($db_field, $value);

            if ($original_value !== $value) {
                $transformed_count++;
            }

            $data[$db_field] = $value;
            $mapped_count++;
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

        // Log mapping summary
        if ($row_number !== null) {
            $this->write_log("Field mapping summary:", 2);
            $this->write_log("- Mapped fields: " . $mapped_count, 3);
            $this->write_log("- Empty fields: " . $empty_count, 3);
            $this->write_log("- Transformed fields: " . $transformed_count, 3);

            // Log all mapped fields with their values
            $this->write_log("Mapped field details:", 2);
            foreach ($data as $field => $value) {
                // Truncate long values for readability
                $display_value = strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
                // Mask encrypted fields
                if (in_array($field, $this->encrypted_fields)) {
                    $display_value = '[ENCRYPTED - ' . strlen($value) . ' chars]';
                }
                $this->write_log(sprintf("%-30s = %s", $field, $display_value), 3);
            }
        }

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
        $original_value = $value;
        $value = strtolower(trim($value));

        $mapping = [
            'sdnb' => 'SDNB',
            'sdnbr' => 'SDNB',
            'sdnb rural' => 'SDNB',
            'vet' => 'Veteran',
            'vets' => 'Veteran',
            'veteran' => 'Veteran',
            'veterans' => 'Veteran',
            'private' => 'Private',
        ];

        $result = $mapping[$value] ?? 'Private';

        // Log transformation if value was not found in mapping
        if (!isset($mapping[$value]) && $original_value !== '') {
            $this->write_log("⚠ Client type transformation: '" . $original_value .
                           "' (normalized: '" . $value . "') not in mapping, defaulting to 'Private'", 3);
        }

        return $result;
    }

    /**
     * Generate initials from first and last name
     *
     * @param string $first_name Client's first name.
     * @param string $last_name Client's last name.
     * @param array $client_data Client data including address fields (new parameter for address-based validation).
     * @return string Generated 3-letter initials.
     */
    public function generate_initials($first_name, $last_name, $client_data = []) {
        // Use the new centralized validator which handles address-based duplicate checking
        $generated = MealsDB_Initials_Validator::generate($first_name, $last_name, $client_data);

        if ($generated === false) {
            // Fallback if generation fails
            error_log('[MealsDB] Failed to generate initials for ' . $first_name . ' ' . $last_name);
            return 'XXX'; // Return a placeholder that will fail validation
        }

        return $generated;
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
     * Create WordPress user or link to existing one
     *
     * Implements shared WordPress account architecture:
     * - Multiple Meals DB clients can share the same WordPress user
     * - Checks for existing user by EMAIL first (regardless of real or generated email)
     * - Creates new user only if email doesn't exist
     *
     * @return array ['user_id' => int, 'status' => string]
     *               status: 'created', 'existing', or 'generated'
     */
    private function create_wp_user($data) {
        $has_email = !empty($data['client_email']) && is_email($data['client_email']);
        $email = null;
        $is_generated_email = false;

        // Determine email to use (real or generated)
        if ($has_email) {
            // Use real email from client data
            $email = $data['client_email'];
            $this->write_log("Client has email: " . $email, 2);
        } else {
            // Generate email using delivery initials
            $delivery_initials = $data['delivery_initials'] ?? '';
            if (empty($delivery_initials)) {
                throw new Exception(__('Cannot create WordPress user: no email and no delivery initials', 'meals-db'));
            }

            $email = strtolower($delivery_initials) . '@mealsdb.local';
            $is_generated_email = true;
            $this->write_log("Client has no email - generating: " . $email, 2);
        }

        // ALWAYS check if WordPress user already exists with this email
        // (regardless of whether it's a real email or generated email)
        $existing_user = get_user_by('email', $email);

        if ($existing_user) {
            // Email exists - LINK to existing WordPress user
            $this->write_log("✓ WordPress user exists - linking to existing user ID: " . $existing_user->ID, 2);
            $this->stats['wp_users_existing']++;
            return [
                'user_id' => $existing_user->ID,
                'status' => 'existing'
            ];
        }

        // Email doesn't exist - CREATE new WordPress user
        $this->write_log("Creating new WordPress user with email: " . $email, 2);

        // Generate appropriate username based on email type
        if ($is_generated_email) {
            $username = $this->generate_unique_username_from_initials($data['delivery_initials']);
        } else {
            $username = $this->generate_unique_username($data['first_name'], $data['last_name']);
        }

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

        $this->write_log("✓ WordPress user created with ID: " . $user_id . " (username: " . $username . ")", 2);
        $this->stats['wp_users_created']++;

        // Return status indicating whether email was generated or real
        return [
            'user_id' => $user_id,
            'status' => $is_generated_email ? 'generated' : 'created'
        ];
    }

    /**
     * Simulate WordPress user creation to determine statistics without making changes
     * Used in dry run mode to calculate accurate statistics
     *
     * @param array $data Client data
     * @return array ['status' => string, 'would_create' => bool]
     */
    private function simulate_wp_user_creation($data) {
        $has_email = !empty($data['client_email']) && is_email($data['client_email']);
        $email = null;

        // Determine email to use (real or generated)
        if ($has_email) {
            $email = $data['client_email'];
        } else {
            $delivery_initials = $data['delivery_initials'] ?? '';
            if (empty($delivery_initials)) {
                throw new Exception(__('Cannot create WordPress user: no email and no delivery initials', 'meals-db'));
            }
            $email = strtolower($delivery_initials) . '@mealsdb.local';
        }

        // Check if WordPress user already exists with this email
        $existing_user = get_user_by('email', $email);

        if ($existing_user) {
            return [
                'status' => 'existing',
                'would_create' => false,
                'user_id' => $existing_user->ID
            ];
        }

        return [
            'status' => 'created',
            'would_create' => true,
            'user_id' => null
        ];
    }

    /**
     * Simulate client creation to determine statistics without making changes
     * Used in dry run mode to calculate accurate statistics
     *
     * @param array $data Client data
     * @param array $wp_user_simulation Result from simulate_wp_user_creation
     * @return array ['operation' => string] (created, updated, or skipped)
     */
    private function simulate_client_creation($data, $wp_user_simulation) {
        // If the WordPress user exists, check if there's already a client record
        if ($wp_user_simulation['status'] === 'existing' && $wp_user_simulation['user_id']) {
            $conn = MealsDB_DB::get_connection();
            if (!MealsDB_DB::is_mysqli($conn)) {
                throw new Exception(__('Database connection failed', 'meals-db'));
            }

            $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
            $sql = sprintf(
                "SELECT * FROM `%s` WHERE wp_user_id = ?",
                str_replace('`', '``', $table)
            );

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception(sprintf(__('Failed to prepare statement: %s', 'meals-db'), $conn->error));
            }

            $wp_user_id = $wp_user_simulation['user_id'];
            $stmt->bind_param('i', $wp_user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $existing_client = $result->fetch_object();
            $stmt->close();

            if ($existing_client) {
                // Client exists - would update or skip
                // Check if there are any NULL fields that would be updated
                $field_map = $this->get_field_map();
                $has_null_fields = false;

                foreach ($field_map as $source => $target) {
                    $current_value = $existing_client->$target ?? null;
                    $new_value = $data[$source] ?? null;

                    if ((is_null($current_value) || $current_value === '') && !empty($new_value)) {
                        $has_null_fields = true;
                        break;
                    }
                }

                return [
                    'operation' => $has_null_fields ? 'updated' : 'skipped'
                ];
            }
        }

        // No existing client - would create new
        return [
            'operation' => 'created'
        ];
    }

    /**
     * Generate unique WordPress username from first and last name
     *
     * @param string $first_name First name
     * @param string $last_name Last name
     * @return string Unique username
     */
    private function generate_unique_username($first_name, $last_name) {
        $base = strtolower(
            sanitize_user($first_name) . '.' .
            sanitize_user($last_name)
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
     * Generate unique WordPress username from delivery initials
     *
     * @param string $initials Delivery initials
     * @return string Unique username
     */
    private function generate_unique_username_from_initials($initials) {
        $base = strtolower(sanitize_user($initials));

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
     * Create or update client record
     */
    private function create_or_update_client($data, $wp_user_id, $row_number = null) {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            throw new Exception(__('Database connection failed', 'meals-db'));
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // Check if client already exists for this WordPress user
        $sql = sprintf(
            "SELECT * FROM `%s` WHERE wp_user_id = ?",
            str_replace('`', '``', $table)
        );

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception(sprintf(__('Failed to prepare statement: %s', 'meals-db'), $conn->error));
        }

        $stmt->bind_param('i', $wp_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing_client = $result->fetch_object();
        $stmt->close();

        if ($existing_client) {
            // Client exists - update with NULL-fill logic
            $this->write_log("Client already exists for wp_user_id " . $wp_user_id . " - updating NULL fields only", 2);
            return $this->update_client($existing_client, $data, $table, $row_number);
        } else {
            // Client doesn't exist - create new
            $this->write_log("Client doesn't exist for wp_user_id " . $wp_user_id . " - creating new record", 2);
            $client_id = $this->create_client($data, $wp_user_id, $table, $row_number);
            return [
                'client_id' => $client_id,
                'operation' => 'created',
                'fields_updated' => 0
            ];
        }
    }

    /**
     * Update existing client with NULL-fill logic (only update empty fields)
     */
    private function update_client($existing_client, $data, $table, $row_number = null) {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            throw new Exception(__('Database connection failed', 'meals-db'));
        }

        // Get field mapping
        $field_map = $this->get_field_map();

        // Build update data (only fields that are currently NULL or empty)
        $update_data = [];
        foreach ($field_map as $source => $target) {
            $current_value = $existing_client->$target ?? null;
            $new_value = $data[$source] ?? null;

            // Only update if current value is NULL/empty AND new value is not empty
            if ((is_null($current_value) || $current_value === '') && !empty($new_value)) {
                $update_data[$target] = $new_value;
            }
        }

        // Update deterministic indexes for fields being updated
        foreach ($this->deterministic_index_map as $field => $index_column) {
            if (isset($update_data[$field]) && !empty($update_data[$field])) {
                $update_data[$index_column] = $this->deterministic_hash($update_data[$field]);
            }
        }

        if (empty($update_data)) {
            $this->write_log("No NULL fields to update - client already has all data", 3);
            return [
                'client_id' => $existing_client->client_id,
                'operation' => 'skipped',
                'fields_updated' => 0
            ];
        }

        if ($row_number !== null) {
            $this->write_log("Updating " . count($update_data) . " NULL fields", 3);
        }

        // Build UPDATE query
        $set_clauses = [];
        foreach (array_keys($update_data) as $column) {
            $set_clauses[] = "`$column` = ?";
        }

        $sql = sprintf(
            "UPDATE `%s` SET %s WHERE client_id = ?",
            str_replace('`', '``', $table),
            implode(', ', $set_clauses)
        );

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception(sprintf(__('Failed to prepare update statement: %s', 'meals-db'), $conn->error));
        }

        // Bind parameters
        $types = '';
        $values = [];
        foreach ($update_data as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $value;
        }

        // Add client_id parameter
        $types .= 'i';
        $values[] = $existing_client->client_id;

        $bind_params = array_merge([$types], $values);
        $refs = [];
        foreach ($bind_params as $key => $value) {
            $refs[$key] = &$bind_params[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $refs);

        // Execute
        if (!$stmt->execute()) {
            throw new Exception(sprintf(__('Failed to update client: %s', 'meals-db'), $stmt->error));
        }

        $stmt->close();

        return [
            'client_id' => $existing_client->client_id,
            'operation' => 'updated',
            'fields_updated' => count($update_data)
        ];
    }

    /**
     * Get field mapping for database operations
     */
    private function get_field_map() {
        return [
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
            'individual_id' => 'individual_id',
            'requisition_id' => 'requisition_id',
            'diet_concerns' => 'diet_concerns',
            'customer_comments' => 'customer_comments',
        ];
    }

    /**
     * Create new client record
     */
    private function create_client($data, $wp_user_id, $table, $row_number = null) {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            throw new Exception(__('Database connection failed', 'meals-db'));
        }

        // Prepare data for insertion
        $insert_data = [
            'wp_user_id' => $wp_user_id,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'client_type' => $data['client_type'],
            'active' => 1,
        ];

        // Map all fields using centralized field map
        $field_map = $this->get_field_map();

        $fields_added = 0;
        foreach ($field_map as $source => $target) {
            if (isset($data[$source]) && $data[$source] !== '') {
                $insert_data[$target] = $data[$source];
                $fields_added++;
            }
        }

        if ($row_number !== null) {
            $this->write_log("Database field mapping:", 2);
            $this->write_log("- Fields prepared for insert: " . $fields_added, 3);
        }

        // Generate deterministic indexes for uniqueness checks on unique fields
        foreach ($this->deterministic_index_map as $field => $index_column) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $insert_data[$index_column] = $this->deterministic_hash($data[$field]);
            }
        }

        // Build INSERT query
        $columns = array_keys($insert_data);
        $placeholders = array_fill(0, count($columns), '?');

        if ($row_number !== null) {
            $this->write_log("Preparing database INSERT:", 2);
            $this->write_log("- Total columns to insert: " . count($columns), 3);
            $this->write_log("- Column list: " . implode(', ', $columns), 3);
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

    /**
     * Check if a client already exists based on email
     *
     * @param array $data Client data
     * @return int|null Client ID if exists, null otherwise
     */
    private function check_existing_client($data) {
        // Determine email (real or generated)
        $has_email = !empty($data['client_email']) && is_email($data['client_email']);
        $email = null;

        if ($has_email) {
            $email = $data['client_email'];
        } else {
            // Generate email from initials if available
            $delivery_initials = $data['delivery_initials'] ?? '';
            if (!empty($delivery_initials)) {
                $email = strtolower($delivery_initials) . '@mealsdb.local';
            } else {
                // Cannot determine email - client doesn't exist
                return null;
            }
        }

        // Check if WordPress user exists with this email
        $existing_user = get_user_by('email', $email);
        if (!$existing_user) {
            return null;
        }

        // Check if client record exists for this WordPress user
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return null;
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $sql = sprintf(
            "SELECT client_id FROM `%s` WHERE wp_user_id = ?",
            str_replace('`', '``', $table)
        );

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $wp_user_id = $existing_user->ID;
        $stmt->bind_param('i', $wp_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $client = $result->fetch_object();
        $stmt->close();

        return $client ? (int) $client->client_id : null;
    }

    /**
     * Get existing client's delivery initials
     *
     * @param int $client_id Client ID
     * @return string|null Delivery initials or null
     */
    private function get_existing_client_initials($client_id) {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return null;
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $sql = sprintf(
            "SELECT delivery_initials FROM `%s` WHERE client_id = ?",
            str_replace('`', '``', $table)
        );

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $client = $result->fetch_object();
        $stmt->close();

        return $client ? $client->delivery_initials : null;
    }
}
