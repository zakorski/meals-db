<?php
/**
 * CSV User-Client Importer for Meals DB
 *
 * Imports 144-column CSV files containing both WordPress user data and Meals DB client data.
 * - Updates WordPress users (OVERWRITE mode)
 * - Creates/updates Meals DB clients (NULL-fill mode for existing clients)
 *
 * @package MealsDB
 */

class MealsDB_CSV_User_Client_Importer {

    private $dry_run = false;
    private $import_id = null;
    private $update_wp_users = true;
    private $update_clients = true;
    private $stats = [
        'total' => 0,
        'wp_users_updated' => 0,
        'wp_users_skipped' => 0,
        'clients_created' => 0,
        'clients_updated' => 0,
        'clients_skipped' => 0,
        'errors' => 0,
    ];
    private $errors = [];
    private $log_file = null;

    /**
     * Constructor
     */
    public function __construct($dry_run = false, $update_wp_users = true, $update_clients = true) {
        $this->dry_run = $dry_run;
        $this->update_wp_users = $update_wp_users;
        $this->update_clients = $update_clients;
        $this->import_id = uniqid('csv_import_');
        $this->init_log_file();
    }

    /**
     * Get import ID
     */
    public function get_import_id() {
        return $this->import_id;
    }

    /**
     * Initialize log file
     */
    private function init_log_file() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/mealsdb-logs/';

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            file_put_contents($log_dir . '.htaccess', "deny from all\n");
        }

        $log_filename = 'import-' . date('Y-m-d-H-i-s') . '-' . $this->import_id . '.log';
        $this->log_file = $log_dir . $log_filename;

        set_transient('mealsdb_csv_import_log_' . $this->import_id, $this->log_file, DAY_IN_SECONDS);

        $this->write_log("=================================================================");
        $this->write_log("MEALS DB CSV USER-CLIENT IMPORT LOG");
        $this->write_log("Import ID: " . $this->import_id);
        $this->write_log("Started: " . date('Y-m-d H:i:s'));
        $this->write_log("Dry Run: " . ($this->dry_run ? 'YES' : 'NO'));
        $this->write_log("Update WordPress Users: " . ($this->update_wp_users ? 'YES' : 'NO'));
        $this->write_log("Update Meals DB Clients: " . ($this->update_clients ? 'YES' : 'NO'));
        $this->write_log("=================================================================\n");
    }

    /**
     * Write to log file
     */
    private function write_log($message, $indent = 0) {
        if (!$this->log_file) {
            return;
        }

        $prefix = str_repeat('  ', $indent);
        $timestamp = date('[Y-m-d H:i:s]');
        $log_line = $timestamp . ' ' . $prefix . $message . "\n";

        file_put_contents($this->log_file, $log_line, FILE_APPEND);
    }

    /**
     * Get log file path
     */
    public static function get_log_file($import_id) {
        return get_transient('mealsdb_csv_import_log_' . $import_id);
    }

    /**
     * Validate CSV file
     */
    public function validate_csv($file_path) {
        if (!file_exists($file_path)) {
            return [
                'valid' => false,
                'message' => __('CSV file not found.', 'meals-db'),
            ];
        }

        $file_size = filesize($file_path);
        if ($file_size > 10 * 1024 * 1024) {
            return [
                'valid' => false,
                'message' => __('File too large. Maximum size is 10MB.', 'meals-db'),
            ];
        }

        try {
            $rows = $this->read_csv($file_path);

            if (empty($rows)) {
                return [
                    'valid' => false,
                    'message' => __('CSV file appears to be empty.', 'meals-db'),
                ];
            }

            // Preview first 5 rows
            $preview_rows = array_slice($rows, 0, 5);
            $preview_data = [];

            foreach ($preview_rows as $row) {
                $parsed = $this->parse_csv_row($row);
                if (!empty($parsed['first_name']) && !empty($parsed['last_name'])) {
                    $preview_data[] = [
                        'name' => $parsed['first_name'] . ' ' . $parsed['last_name'],
                        'email' => $parsed['user_email'] ?? '',
                        'type' => $parsed['customer_group'] ?? '',
                        'user_id' => $parsed['source_user_id'] ?? '',
                    ];
                }
            }

            return [
                'valid' => true,
                'total_rows' => count($rows),
                'preview' => $preview_data,
            ];
        } catch (Exception $e) {
            return [
                'valid' => false,
                'message' => sprintf(__('Error reading CSV: %s', 'meals-db'), $e->getMessage()),
            ];
        }
    }

    /**
     * Import from CSV file
     */
    public function import_from_csv($file_path) {
        if (!file_exists($file_path)) {
            return [
                'success' => false,
                'message' => __('CSV file not found.', 'meals-db'),
            ];
        }

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

        $this->stats['total'] = count($rows);
        $this->write_log("Processing " . count($rows) . " rows...\n");

        // Process each row
        foreach ($rows as $index => $row) {
            $row_number = $index + 1;

            $this->update_progress($row_number, count($rows));

            $this->write_log("\n" . str_repeat('-', 70));
            $this->write_log("PROCESSING ROW #" . $row_number);
            $this->write_log(str_repeat('-', 70));

            try {
                $this->process_row($row, $row_number);
                $this->write_log("✓ ROW #" . $row_number . " PROCESSED SUCCESSFULLY");
            } catch (Exception $e) {
                $this->stats['errors']++;
                $error_msg = sprintf(__('Row %d: %s', 'meals-db'), $row_number, $e->getMessage());
                $this->errors[] = $error_msg;
                $this->write_log("✗ ROW #" . $row_number . " FAILED: " . $e->getMessage());
            }
        }

        // Write summary
        $this->write_log("\n" . str_repeat('=', 70));
        $this->write_log("IMPORT SUMMARY");
        $this->write_log(str_repeat('=', 70));
        $this->write_log("Total Rows: " . $this->stats['total']);
        $this->write_log("WordPress Users Updated: " . $this->stats['wp_users_updated']);
        $this->write_log("WordPress Users Skipped: " . $this->stats['wp_users_skipped']);
        $this->write_log("Meals DB Clients Created: " . $this->stats['clients_created']);
        $this->write_log("Meals DB Clients Updated: " . $this->stats['clients_updated']);
        $this->write_log("Meals DB Clients Skipped: " . $this->stats['clients_skipped']);
        $this->write_log("Errors: " . $this->stats['errors']);
        $this->write_log("Completed: " . date('Y-m-d H:i:s'));
        $this->write_log(str_repeat('=', 70));

        if (!empty($this->errors)) {
            set_transient('mealsdb_csv_import_errors_' . $this->import_id, $this->errors, HOUR_IN_SECONDS);
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
     * Read CSV file
     */
    private function read_csv($file) {
        $handle = fopen($file, 'r');
        if (!$handle) {
            throw new Exception(__('Could not open CSV file.', 'meals-db'));
        }

        // Read and store header row for debugging
        $headers = fgetcsv($handle);
        $this->write_log("\n" . str_repeat('=', 70));
        $this->write_log("CSV HEADER ANALYSIS");
        $this->write_log(str_repeat('=', 70));
        $this->write_log("Total columns: " . count($headers));

        // Log first 50 columns with their indices
        $this->write_log("\nColumn mapping (first 50 columns):");
        for ($i = 0; $i < min(50, count($headers)); $i++) {
            $this->write_log(sprintf("  [%d] = %s", $i, $headers[$i]), 0);
        }

        // Read first data row for sample
        $first_row = fgetcsv($handle);
        if ($first_row) {
            $this->write_log("\nFirst row sample (first 20 values):");
            for ($i = 0; $i < min(20, count($first_row)); $i++) {
                $value = $first_row[$i];
                $display_value = strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
                $this->write_log(sprintf("  [%d] %s = %s", $i, $headers[$i] ?? 'unknown', $display_value), 0);
            }

            // Reset file pointer to start
            rewind($handle);
            fgetcsv($handle); // Skip header again
        }
        $this->write_log(str_repeat('=', 70) . "\n");

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (!empty($row[0]) || !empty($row[1])) {
                $rows[] = $row;
            }
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Process a single CSV row
     */
    private function process_row($csv_row, $row_number) {
        $data = $this->parse_csv_row($csv_row);

        // Validate required fields
        if (empty($data['source_user_id'])) {
            throw new Exception(__('Missing source_user_id', 'meals-db'));
        }

        if (empty($data['first_name']) || empty($data['last_name'])) {
            throw new Exception(__('Missing first or last name', 'meals-db'));
        }

        if (empty($data['user_email']) || !is_email($data['user_email'])) {
            throw new Exception(__('Missing or invalid email', 'meals-db'));
        }

        $this->write_log("Client: " . $data['first_name'] . ' ' . $data['last_name'], 1);
        $this->write_log("User ID: " . $data['source_user_id'], 1);
        $this->write_log("Email: " . $data['user_email'], 1);

        // Update WordPress user
        if ($this->update_wp_users) {
            $this->update_wordpress_user($data);
        }

        // Create/update Meals DB client
        if ($this->update_clients) {
            $this->create_or_update_client($data);
        }
    }

    /**
     * Parse CSV row into associative array
     */
    private function parse_csv_row($row) {
        $data = [];

        // Map all 144 columns (you'll need to adjust these indices based on your actual CSV)
        $data['source_user_id'] = $this->get_csv_value($row, 0);
        $data['user_login'] = $this->get_csv_value($row, 1);
        $data['user_email'] = $this->get_csv_value($row, 2);
        $data['user_nicename'] = $this->get_csv_value($row, 3);
        $data['display_name'] = $this->get_csv_value($row, 4);
        $data['first_name'] = $this->get_csv_value($row, 5);
        $data['last_name'] = $this->get_csv_value($row, 6);
        $data['nickname'] = $this->get_csv_value($row, 7);

        // Billing fields (WordPress meta)
        $data['billing_first_name'] = $this->get_csv_value($row, 8);
        $data['billing_last_name'] = $this->get_csv_value($row, 9);
        $data['billing_company'] = $this->get_csv_value($row, 10);
        $data['billing_address_1'] = $this->get_csv_value($row, 11);
        $data['billing_address_2'] = $this->get_csv_value($row, 12);
        $data['billing_city'] = $this->get_csv_value($row, 13);
        $data['billing_state'] = $this->get_csv_value($row, 14);
        $data['billing_postcode'] = $this->get_csv_value($row, 15);
        $data['billing_country'] = $this->get_csv_value($row, 16);
        $data['billing_email'] = $this->get_csv_value($row, 17);
        $data['billing_phone'] = $this->get_csv_value($row, 18);

        // Shipping fields (WordPress meta)
        $data['shipping_first_name'] = $this->get_csv_value($row, 19);
        $data['shipping_last_name'] = $this->get_csv_value($row, 20);
        $data['shipping_company'] = $this->get_csv_value($row, 21);
        $data['shipping_address_1'] = $this->get_csv_value($row, 22);
        $data['shipping_address_2'] = $this->get_csv_value($row, 23);
        $data['shipping_city'] = $this->get_csv_value($row, 24);
        $data['shipping_state'] = $this->get_csv_value($row, 25);
        $data['shipping_postcode'] = $this->get_csv_value($row, 26);
        $data['shipping_country'] = $this->get_csv_value($row, 27);
        $data['shipping_phone'] = $this->get_csv_value($row, 28);

        // Client-specific fields
        $data['customer_group'] = $this->get_csv_value($row, 29);
        $data['service_id'] = $this->get_csv_value($row, 30);
        $data['basic_cost'] = $this->get_csv_value($row, 31);
        $data['payment_method'] = $this->get_csv_value($row, 32);
        $data['ordering_frequency'] = $this->get_csv_value($row, 33);
        $data['delivery_frequency'] = $this->get_csv_value($row, 34);
        $data['freeze_capacity'] = $this->get_csv_value($row, 35);
        $data['dietary_needs'] = $this->get_csv_value($row, 36);
        $data['customer_comments'] = $this->get_csv_value($row, 37);
        $data['commence_date'] = $this->get_csv_value($row, 38);
        $data['service_termination_date'] = $this->get_csv_value($row, 39);

        return $data;
    }

    /**
     * Get CSV value safely
     */
    private function get_csv_value($row, $index) {
        return isset($row[$index]) ? trim($row[$index]) : '';
    }

    /**
     * Update WordPress user (OVERWRITE mode)
     */
    private function update_wordpress_user($data) {
        $user_id = intval($data['source_user_id']);

        $this->write_log("Updating WordPress user...", 1);

        if ($this->dry_run) {
            $this->write_log("DRY RUN: Would update WordPress user ID: " . $user_id, 2);
            $this->stats['wp_users_updated']++;
            return;
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            $this->write_log("✗ WordPress user ID " . $user_id . " not found", 2);
            $this->stats['wp_users_skipped']++;
            throw new Exception(__('WordPress user not found', 'meals-db'));
        }

        // Update core user fields
        $user_data = [
            'ID' => $user_id,
        ];

        if (!empty($data['user_email'])) {
            $user_data['user_email'] = $data['user_email'];
        }

        if (!empty($data['user_nicename'])) {
            $user_data['user_nicename'] = $data['user_nicename'];
        }

        if (!empty($data['display_name'])) {
            $user_data['display_name'] = $data['display_name'];
        }

        $result = wp_update_user($user_data);
        if (is_wp_error($result)) {
            throw new Exception($result->get_error_message());
        }

        // Update user meta (OVERWRITE all fields)
        $meta_fields = [
            'first_name',
            'last_name',
            'nickname',
            'billing_first_name',
            'billing_last_name',
            'billing_company',
            'billing_address_1',
            'billing_address_2',
            'billing_city',
            'billing_state',
            'billing_postcode',
            'billing_country',
            'billing_email',
            'billing_phone',
            'shipping_first_name',
            'shipping_last_name',
            'shipping_company',
            'shipping_address_1',
            'shipping_address_2',
            'shipping_city',
            'shipping_state',
            'shipping_postcode',
            'shipping_country',
            'shipping_phone',
        ];

        $updated_meta_count = 0;
        foreach ($meta_fields as $field) {
            if (isset($data[$field])) {
                update_user_meta($user_id, $field, $data[$field]);
                $updated_meta_count++;
            }
        }

        $this->write_log("✓ WordPress user updated (core + " . $updated_meta_count . " meta fields)", 2);
        $this->stats['wp_users_updated']++;
    }

    /**
     * Create or update Meals DB client
     */
    private function create_or_update_client($data) {
        global $wpdb;

        $table = $wpdb->prefix . 'meals_clients';
        $user_id = intval($data['source_user_id']);

        $this->write_log("Processing Meals DB client...", 1);

        // Check if client exists
        $existing_client = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE wp_user_id = %d",
            $user_id
        ));

        if ($existing_client) {
            $this->update_existing_client($existing_client, $data, $table);
        } else {
            $this->create_new_client($data, $table, $user_id);
        }
    }

    /**
     * Update existing client (NULL-fill only)
     */
    private function update_existing_client($existing_client, $data, $table) {
        global $wpdb;

        $this->write_log("Client exists - applying NULL-fill logic", 2);

        if ($this->dry_run) {
            $this->write_log("DRY RUN: Would update NULL fields for client ID: " . $existing_client->client_id, 3);
            $this->stats['clients_updated']++;
            return;
        }

        $update_data = [];
        $field_mapping = $this->get_client_field_mapping();

        foreach ($field_mapping as $csv_field => $db_field) {
            $current_value = $existing_client->$db_field ?? null;
            $csv_value = $data[$csv_field] ?? null;

            // Only update if current value is NULL or empty AND CSV has a value
            if ((is_null($current_value) || $current_value === '') && !empty($csv_value)) {
                $update_data[$db_field] = $this->transform_field_value($db_field, $csv_value);
            }
        }

        // Handle encrypted fields
        $this->process_encrypted_fields_for_update($data, $existing_client, $update_data);

        // Handle delivery initials if NULL
        if (empty($existing_client->delivery_initials)) {
            $this->write_log("Client has no delivery initials - generating...", 3);

            // Build client data array from existing client + any updates
            $client_data = array_merge((array)$existing_client, $update_data);
            $client_data['first_name'] = $data['first_name'];
            $client_data['last_name'] = $data['last_name'];

            $this->process_delivery_initials_for_update($data, $client_data, $update_data);
        }

        if (!empty($update_data)) {
            $result = $wpdb->update(
                $table,
                $update_data,
                ['client_id' => $existing_client->client_id],
                $this->get_field_types($update_data),
                ['%d']
            );

            if ($result === false) {
                throw new Exception(__('Failed to update client record', 'meals-db'));
            }

            $this->write_log("✓ Client updated - filled " . count($update_data) . " NULL fields", 3);
            $this->stats['clients_updated']++;
        } else {
            $this->write_log("No NULL fields to update", 3);
            $this->stats['clients_skipped']++;
        }
    }

    /**
     * Process delivery initials for existing client update
     */
    private function process_delivery_initials_for_update($data, $client_data, &$update_data) {
        // Check if CSV already has initials (from nickname field)
        $csv_initials = null;
        if (!empty($data['nickname'])) {
            $csv_initials = strtoupper(substr(trim($data['nickname']), 0, 3));
        }

        if ($csv_initials && preg_match('/^[A-Z]{3}$/', $csv_initials)) {
            // CSV has initials - validate them
            $this->write_log("Validating existing initials from CSV: " . $csv_initials, 4);

            $validation = MealsDB_Initials_Validator::validate(
                $csv_initials,
                $client_data,
                $client_data['client_id'] ?? null
            );

            if ($validation['valid']) {
                $update_data['delivery_initials'] = $csv_initials;
                $update_data['delivery_initials_index'] = MealsDB_Encryption::create_index($csv_initials);
                $this->write_log("✓ Initials validated: " . $csv_initials, 4);
            } else {
                // Initials from CSV are invalid - generate new ones
                $this->write_log("⚠ CSV initials invalid: " . $validation['error'], 4);
                $this->write_log("Generating new initials...", 4);
                $this->generate_unique_initials_for_update($client_data, $update_data);
            }
        } else {
            // No valid initials in CSV - generate new
            $this->write_log("No valid initials in CSV - generating new...", 4);
            $this->generate_unique_initials_for_update($client_data, $update_data);
        }
    }

    /**
     * Generate unique initials for existing client update
     */
    private function generate_unique_initials_for_update($client_data, &$update_data) {
        $generated = MealsDB_Initials_Validator::generate(
            $client_data['first_name'],
            $client_data['last_name'],
            $client_data
        );

        if ($generated === false) {
            $this->write_log("✗ Failed to generate unique initials", 4);
            throw new Exception(__('Unable to generate unique delivery initials', 'meals-db'));
        }

        $update_data['delivery_initials'] = $generated;
        $update_data['delivery_initials_index'] = MealsDB_Encryption::create_index($generated);
        $this->write_log("✓ Generated initials: " . $generated, 4);
    }

    /**
     * Create new client
     */
    private function create_new_client($data, $table, $user_id) {
        global $wpdb;

        $this->write_log("Client doesn't exist - creating new record", 2);

        if ($this->dry_run) {
            $this->write_log("DRY RUN: Would create client for user ID: " . $user_id, 3);
            $this->stats['clients_created']++;
            return;
        }

        $client_data = [
            'wp_user_id' => $user_id,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'client_type' => $this->map_client_type($data['customer_group'] ?? ''),
            'active' => 1,
        ];

        // Map all fields
        $field_mapping = $this->get_client_field_mapping();
        foreach ($field_mapping as $csv_field => $db_field) {
            if (!empty($data[$csv_field])) {
                $client_data[$db_field] = $this->transform_field_value($db_field, $data[$csv_field]);
            }
        }

        // Handle encrypted fields
        $this->process_encrypted_fields_for_create($data, $client_data);

        // Handle delivery initials - use existing validation system
        $this->process_delivery_initials($data, $client_data);

        $result = $wpdb->insert(
            $table,
            $client_data,
            $this->get_field_types($client_data)
        );

        if (!$result) {
            throw new Exception(__('Failed to create client record', 'meals-db'));
        }

        $this->write_log("✓ Client created with ID: " . $wpdb->insert_id, 3);
        $this->stats['clients_created']++;
    }

    /**
     * Get client field mapping
     */
    private function get_client_field_mapping() {
        return [
            'user_email' => 'client_email',
            'billing_phone' => 'client_phone_1',
            'shipping_phone' => 'client_phone_2',
            'billing_address_1' => 'street_name',
            'billing_address_2' => 'apartment_number',
            'billing_city' => 'city',
            'billing_state' => 'province',
            'billing_postcode' => 'postal_code',
            'shipping_address_1' => 'delivery_street_name',
            'shipping_address_2' => 'delivery_apartment_number',
            'shipping_city' => 'delivery_city',
            'shipping_state' => 'delivery_province',
            'shipping_postcode' => 'delivery_postal_code',
            'service_id' => 'service_id',
            'basic_cost' => 'rate',
            'payment_method' => 'payment_method',
            'ordering_frequency' => 'ordering_frequency',
            'delivery_frequency' => 'delivery_frequency',
            'freeze_capacity' => 'freezer_capacity',
            'commence_date' => 'service_commence_date',
            'service_termination_date' => 'expected_termination_date',
        ];
    }

    /**
     * Transform field value based on type
     */
    private function transform_field_value($field, $value) {
        // Dates
        if (strpos($field, '_date') !== false) {
            return $this->transform_date($value);
        }

        // Numbers
        if (in_array($field, ['rate', 'delivery_fee', 'client_contribution'])) {
            return floatval(str_replace(['$', ','], '', $value));
        }

        if (in_array($field, ['ordering_frequency', 'delivery_frequency', 'units'])) {
            return intval($value);
        }

        return $value;
    }

    /**
     * Transform date to MySQL format
     */
    private function transform_date($value) {
        if (empty($value)) return null;

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * Process encrypted fields for create
     */
    private function process_encrypted_fields_for_create($data, &$client_data) {
        $encryption = MealsDB_Encryption::get_instance();

        if (!empty($data['dietary_needs'])) {
            $client_data['diet_concerns'] = MealsDB_Encryption::encrypt($data['dietary_needs']);
        }

        if (!empty($data['customer_comments'])) {
            $client_data['customer_comments'] = MealsDB_Encryption::encrypt($data['customer_comments']);
        }
    }

    /**
     * Process encrypted fields for update (NULL-fill)
     */
    private function process_encrypted_fields_for_update($data, $existing_client, &$update_data) {
        $encryption = MealsDB_Encryption::get_instance();

        if (!empty($data['dietary_needs']) && empty($existing_client->diet_concerns)) {
            $update_data['diet_concerns'] = MealsDB_Encryption::encrypt($data['dietary_needs']);
        }

        if (!empty($data['customer_comments']) && empty($existing_client->customer_comments)) {
            $update_data['customer_comments'] = MealsDB_Encryption::encrypt($data['customer_comments']);
        }
    }

    /**
     * Process delivery initials using existing validation system
     */
    private function process_delivery_initials($data, &$client_data) {
        // Check if CSV already has initials (from nickname field)
        $csv_initials = null;
        if (!empty($data['nickname'])) {
            $csv_initials = strtoupper(substr(trim($data['nickname']), 0, 3));
        }

        if ($csv_initials && preg_match('/^[A-Z]{3}$/', $csv_initials)) {
            // CSV has initials - validate them
            $this->write_log("Validating existing initials from CSV: " . $csv_initials, 3);

            $validation = MealsDB_Initials_Validator::validate(
                $csv_initials,
                $client_data,
                null // No current client ID since this is a new client
            );

            if ($validation['valid']) {
                $client_data['delivery_initials'] = $csv_initials;
                $client_data['delivery_initials_index'] = MealsDB_Encryption::create_index($csv_initials);

                if (!empty($validation['shared'])) {
                    $sharing_names = array_map(function($client) {
                        return trim($client['first_name'] . ' ' . $client['last_name']);
                    }, $validation['sharing_with']);
                    $this->write_log("✓ Initials shared with " . implode(', ', $sharing_names) . " at same address", 3);
                } else {
                    $this->write_log("✓ Initials validated: " . $csv_initials, 3);
                }
            } else {
                // Initials from CSV are invalid - generate new ones
                $this->write_log("⚠ CSV initials invalid: " . $validation['error'], 3);
                $this->write_log("Generating new initials...", 3);
                $this->generate_unique_initials($client_data);
            }
        } else {
            // No valid initials in CSV - generate new
            $this->write_log("No valid initials in CSV - generating new...", 3);
            $this->generate_unique_initials($client_data);
        }
    }

    /**
     * Generate unique delivery initials using centralized validator
     */
    private function generate_unique_initials(&$client_data) {
        $generated = MealsDB_Initials_Validator::generate(
            $client_data['first_name'],
            $client_data['last_name'],
            $client_data
        );

        if ($generated === false) {
            $this->write_log("✗ Failed to generate unique initials", 3);
            throw new Exception(__('Unable to generate unique delivery initials', 'meals-db'));
        }

        $client_data['delivery_initials'] = $generated;
        $client_data['delivery_initials_index'] = MealsDB_Encryption::create_index($generated);
        $this->write_log("✓ Generated initials: " . $generated, 3);
    }

    /**
     * Map customer group to client type
     */
    private function map_client_type($customer_group) {
        $group = strtolower(trim($customer_group));

        if (strpos($group, 'sdnb') !== false) {
            return 'SDNB';
        } elseif (strpos($group, 'veteran') !== false || strpos($group, 'vet') !== false) {
            return 'Veteran';
        } else {
            return 'Private';
        }
    }

    /**
     * Get field types for wpdb
     */
    private function get_field_types($data) {
        $types = [];
        foreach ($data as $key => $value) {
            if (is_int($value)) {
                $types[] = '%d';
            } elseif (is_float($value)) {
                $types[] = '%f';
            } else {
                $types[] = '%s';
            }
        }
        return $types;
    }

    /**
     * Update progress
     */
    private function update_progress($current, $total) {
        $progress = [
            'current' => $current,
            'total' => $total,
            'percent' => round(($current / $total) * 100, 1),
        ];

        set_transient('mealsdb_csv_import_progress_' . $this->import_id, $progress, HOUR_IN_SECONDS);
    }

    /**
     * Get progress
     */
    public static function get_progress($import_id) {
        return get_transient('mealsdb_csv_import_progress_' . $import_id) ?: null;
    }

    /**
     * Get errors
     */
    public static function get_errors($import_id) {
        return get_transient('mealsdb_csv_import_errors_' . $import_id) ?: [];
    }
}
