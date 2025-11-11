<?php
/**
 * Installer responsible for preparing the Meals DB schema.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Installer {

    /**
     * Run the schema installation/upgrade routine.
     *
     * Creates the external Meals DB tables required by the plugin while
     * ensuring the shared database connection class is not redeclared.
     */
    public static function install(): void {
        if (!class_exists('MealsDB_DB')) {
            // The calling code is expected to load the DB class beforehand, but
            // guard against missing includes to avoid fatal errors.
            require_once __DIR__ . '/class-db.php';
        }

        if (!self::ensure_wp_config_constants()) {
            error_log('[MealsDB Installer] Configuration constants were missing. Placeholder entries were added to wp-config.php when possible. Update them with the correct values and reactivate the plugin.');
            return;
        }

        $conn = MealsDB_DB::get_connection();

        if (!$conn instanceof mysqli) {
            error_log('[MealsDB Installer] Unable to establish database connection.');
            return;
        }

        $charset_sql = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $charset = $conn->character_set_name();
        $collation = method_exists($conn, 'get_charset') ? $conn->get_charset() : null;

        if (!empty($charset)) {
            $collation_name = 'utf8mb4_unicode_ci';

            if (is_object($collation) && property_exists($collation, 'collation') && !empty($collation->collation)) {
                $collation_name = $collation->collation;
            }

            $charset_sql = sprintf('DEFAULT CHARSET=%s COLLATE=%s', $charset, $collation_name);
        }

        $tables = [
            'meals_drafts' => "CREATE TABLE IF NOT EXISTS meals_drafts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                data LONGTEXT NOT NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_created_by (created_by)
            ) ENGINE=InnoDB $charset_sql;",
            'meals_ignored_conflicts' => "CREATE TABLE IF NOT EXISTS meals_ignored_conflicts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                field_name VARCHAR(191) NOT NULL,
                source_value TEXT NULL,
                target_value TEXT NULL,
                ignored_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_field_name (field_name),
                KEY idx_ignored_by (ignored_by)
            ) ENGINE=InnoDB $charset_sql;",
            'meals_audit_log' => "CREATE TABLE IF NOT EXISTS meals_audit_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NULL,
                action VARCHAR(100) NOT NULL,
                target_id BIGINT UNSIGNED NULL,
                field_changed VARCHAR(191) NULL,
                old_value TEXT NULL,
                new_value TEXT NULL,
                source VARCHAR(100) NOT NULL DEFAULT 'mealsdb',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_user_id (user_id),
                KEY idx_target_id (target_id)
            ) ENGINE=InnoDB $charset_sql;",
        ];

        foreach ($tables as $table => $sql) {
            if (!$conn->query($sql)) {
                error_log(sprintf('[MealsDB Installer] Failed creating %s: %s', $table, $conn->error));
            }
        }

        self::create_meals_clients_table();
    }

    /**
     * Ensure required configuration constants are present in wp-config.php.
     */
    private static function ensure_wp_config_constants(): bool {
        $required = self::get_required_constants();
        $missing = [];

        foreach ($required as $constant => $comment) {
            if (!defined($constant)) {
                $missing[$constant] = $comment;
                continue;
            }

            $value = constant($constant);

            if ($value === null || $value === '') {
                $missing[$constant] = $comment;
            }
        }

        if (empty($missing)) {
            return true;
        }

        $wpConfigPath = self::locate_wp_config_path();

        if ($wpConfigPath === null) {
            error_log('[MealsDB Installer] Unable to locate wp-config.php to append Meals DB constants.');
            return false;
        }

        if (!is_writable($wpConfigPath)) {
            error_log('[MealsDB Installer] wp-config.php is not writable; cannot append Meals DB constants.');
            return false;
        }

        $configContents = file_get_contents($wpConfigPath);

        if ($configContents === false) {
            error_log('[MealsDB Installer] Unable to read wp-config.php while preparing Meals DB constants.');
            return false;
        }

        foreach (array_keys($missing) as $constant) {
            if (strpos($configContents, "define('{$constant}'") !== false || strpos($configContents, "define(\"{$constant}\"") !== false) {
                unset($missing[$constant]);
            }
        }

        if (!empty($missing)) {
            $lines = [
                '',
                '// Meals Database configuration constants added during plugin activation.',
                '// Update each value with the correct credentials before using the plugin.',
            ];

            foreach ($missing as $constant => $comment) {
                $lines[] = "if (!defined('{$constant}')) {";
                $lines[] = "    define('{$constant}', ''); // TODO: {$comment}";
                $lines[] = '}';
            }

            $lines[] = '';

            $block = implode(PHP_EOL, $lines);

            if (file_put_contents($wpConfigPath, $block, FILE_APPEND | LOCK_EX) === false) {
                error_log('[MealsDB Installer] Failed writing Meals DB constants to wp-config.php.');
                return false;
            }
        }

        foreach (array_keys($missing) as $constant) {
            if (!defined($constant)) {
                define($constant, '');
            }
        }

        return self::are_constants_configured();
    }

    /**
     * Mapping of required configuration constants to human-readable descriptions.
     */
    private static function get_required_constants(): array {
        return [
            'MEALS_DB_HOST' => 'Set to the Meals database host.',
            'MEALS_DB_USER' => 'Set to the Meals database username.',
            'MEALS_DB_PASS' => 'Set to the Meals database password.',
            'MEALS_DB_NAME' => 'Set to the Meals database name.',
            'MEALS_DB_KEY'  => 'Provide the base64-encoded encryption key.',
        ];
    }

    /**
     * Locate wp-config.php within the current installation.
     */
    private static function locate_wp_config_path(): ?string {
        $paths = [];

        if (defined('ABSPATH')) {
            $paths[] = rtrim(ABSPATH, '/\\') . '/wp-config.php';
            $paths[] = rtrim(dirname(ABSPATH), '/\\') . '/wp-config.php';
        }

        $paths = array_unique(array_filter($paths));

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Confirm that all Meals DB constants are defined and populated.
     */
    private static function are_constants_configured(): bool {
        foreach (array_keys(self::get_required_constants()) as $constant) {
            if (!defined($constant)) {
                return false;
            }

            $value = constant($constant);

            if ($value === null || $value === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Create or upgrade the meals_clients table using dbDelta.
     */
    private static function create_meals_clients_table(): void {
        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            error_log('[MealsDB Installer] Unable to access the WordPress database connection while creating meals_clients.');
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name      = $wpdb->prefix . 'meals_clients';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            client_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT(20) UNSIGNED NOT NULL,
            client_type ENUM('Private','SDNB','Veteran','Staff') NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            client_email VARCHAR(255) NULL,
            client_phone_1 VARCHAR(20) NULL,
            client_phone_2 VARCHAR(20) NULL,
            alternate_contact_name VARCHAR(255) NULL,
            alternate_contact_phone_1 VARCHAR(20) NULL,
            alternate_contact_phone_2 VARCHAR(20) NULL,
            alternate_contact_email VARCHAR(255) NULL,
            do_not_call_client_phone BOOLEAN NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) NULL,
            open_date DATE NULL,
            birth_date DATE NULL,
            gender VARCHAR(10) NULL,
            assigned_worker_name VARCHAR(255) NULL,
            assigned_worker_email VARCHAR(255) NULL,
            vendor_number VARCHAR(50) NULL,
            service_center_charged VARCHAR(255) NULL,
            service_id VARCHAR(50) NULL,
            requisition_id VARCHAR(50) NULL,
            requisition_period VARCHAR(50) NULL,
            meal_type VARCHAR(50) NULL,
            service_name_zone VARCHAR(10) NULL,
            service_name_course VARCHAR(10) NULL,
            service_commence_date DATE NULL,
            expected_termination_date DATE NULL,
            initial_renewal_termination_date DATE NULL,
            most_recent_renewal_termination_date DATE NULL,
            notes_to_service_provider TEXT NULL,
            client_contribution DECIMAL(10,2) NULL,
            vet_health_id_card VARCHAR(50) NULL,
            rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            required_start_date DATE NULL,
            delivery_day VARCHAR(50) NULL,
            delivery_area_name VARCHAR(255) NULL,
            delivery_area_zone VARCHAR(50) NULL,
            ordering_contact_method VARCHAR(50) NULL,
            ordering_frequency INT NULL,
            delivery_frequency INT NULL,
            freezer_capacity VARCHAR(50) NULL,
            delivery_fee DECIMAL(10,2) NULL,
            diet_concerns TEXT NULL,
            customer_comments TEXT NULL,
            initials_for_delivery VARCHAR(10) NULL,
            street_number VARCHAR(20) NULL,
            street_name VARCHAR(255) NULL,
            apartment_number VARCHAR(20) NULL,
            city VARCHAR(255) NULL,
            province VARCHAR(10) NULL,
            postal_code VARCHAR(10) NULL,
            delivery_street_number VARCHAR(20) NULL,
            delivery_street_name VARCHAR(255) NULL,
            delivery_apartment_number VARCHAR(20) NULL,
            delivery_city VARCHAR(255) NULL,
            delivery_province VARCHAR(10) NULL,
            delivery_postal_code VARCHAR(10) NULL,
            PRIMARY KEY  (client_id),
            KEY client_type (client_type),
            KEY wp_user_id (wp_user_id)
        ) {$charset_collate};";

        dbDelta($sql);
    }
}
