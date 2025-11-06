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
            'meals_clients' => "CREATE TABLE IF NOT EXISTS meals_clients (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                individual_id VARCHAR(255) NULL,
                requisition_id VARCHAR(255) NULL,
                vet_health_card VARCHAR(255) NULL,
                delivery_initials VARCHAR(50) NULL,
                individual_id_index CHAR(64) NULL,
                requisition_id_index CHAR(64) NULL,
                vet_health_card_index CHAR(64) NULL,
                delivery_initials_index CHAR(64) NULL,
                first_name VARCHAR(191) NOT NULL,
                last_name VARCHAR(191) NOT NULL,
                customer_type VARCHAR(100) NOT NULL,
                open_date DATE NULL,
                assigned_social_worker VARCHAR(191) NULL,
                social_worker_email VARCHAR(191) NULL,
                client_email VARCHAR(191) NOT NULL,
                phone_primary VARCHAR(25) NOT NULL,
                phone_secondary VARCHAR(25) NULL,
                do_not_call_client_phone TINYINT(1) NOT NULL DEFAULT 0,
                alt_contact_name VARCHAR(191) NULL,
                alt_contact_phone_primary VARCHAR(25) NULL,
                alt_contact_phone_secondary VARCHAR(25) NULL,
                alt_contact_email VARCHAR(191) NULL,
                address_street_number VARCHAR(50) NULL,
                address_street_name VARCHAR(191) NULL,
                address_unit VARCHAR(50) NULL,
                address_city VARCHAR(191) NULL,
                address_province VARCHAR(191) NULL,
                address_postal VARCHAR(20) NOT NULL,
                delivery_address_street_number VARCHAR(50) NULL,
                delivery_address_street_name VARCHAR(191) NULL,
                delivery_address_unit VARCHAR(50) NULL,
                delivery_address_city VARCHAR(191) NULL,
                delivery_address_province VARCHAR(191) NULL,
                delivery_address_postal VARCHAR(20) NULL,
                gender VARCHAR(50) NULL,
                birth_date DATE NULL,
                service_center VARCHAR(191) NULL,
                service_center_charged VARCHAR(191) NULL,
                vendor_number VARCHAR(191) NULL,
                service_id VARCHAR(191) NULL,
                service_zone VARCHAR(191) NULL,
                service_course VARCHAR(191) NULL,
                per_sdnb_req VARCHAR(191) NULL,
                payment_method VARCHAR(191) NULL,
                rate VARCHAR(191) NULL,
                client_contribution VARCHAR(191) NULL,
                delivery_fee VARCHAR(191) NULL,
                delivery_day VARCHAR(191) NULL,
                delivery_area_name VARCHAR(191) NULL,
                delivery_area_zone VARCHAR(191) NULL,
                ordering_frequency VARCHAR(191) NULL,
                ordering_contact_method VARCHAR(191) NULL,
                delivery_frequency VARCHAR(191) NULL,
                freezer_capacity VARCHAR(191) NULL,
                meal_type VARCHAR(191) NULL,
                requisition_period VARCHAR(191) NULL,
                service_commence_date DATE NULL,
                required_start_date DATE NULL,
                expected_termination_date DATE NULL,
                initial_renewal_date DATE NULL,
                termination_date DATE NULL,
                most_recent_renewal_date DATE NULL,
                units TINYINT UNSIGNED NULL,
                diet_concerns TEXT NULL,
                client_comments TEXT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_individual_id (individual_id),
                UNIQUE KEY unique_requisition_id (requisition_id),
                UNIQUE KEY unique_individual_id_index (individual_id_index),
                UNIQUE KEY unique_requisition_id_index (requisition_id_index),
                UNIQUE KEY unique_vet_health_card_index (vet_health_card_index),
                UNIQUE KEY unique_delivery_initials_index (delivery_initials_index),
                UNIQUE KEY unique_vet_health_card (vet_health_card),
                KEY idx_status (status)
            ) ENGINE=InnoDB $charset_sql;",
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

        self::rename_legacy_columns($conn);
        self::ensure_meals_clients_columns($conn);
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
     * Rename legacy meals_clients columns to their new equivalents when possible.
     */
    private static function rename_legacy_columns(mysqli $conn): void {
        $table = 'meals_clients';
        $renames = [
            'initial_termination_date' => ['initial_renewal_date', 'DATE NULL'],
            'recent_renewal_date'      => ['most_recent_renewal_date', 'DATE NULL'],
        ];

        foreach ($renames as $oldColumn => $renameData) {
            [$newColumn, $definition] = $renameData;

            $oldEscaped = $conn->real_escape_string($oldColumn);
            $newEscaped = $conn->real_escape_string($newColumn);

            $oldResult = $conn->query(sprintf("SHOW COLUMNS FROM `%s` LIKE '%s'", $table, $oldEscaped));
            $newResult = $conn->query(sprintf("SHOW COLUMNS FROM `%s` LIKE '%s'", $table, $newEscaped));

            $oldExists = ($oldResult && property_exists($oldResult, 'num_rows') && $oldResult->num_rows > 0);
            $newExists = ($newResult && property_exists($newResult, 'num_rows') && $newResult->num_rows > 0);

            if ($oldResult && method_exists($oldResult, 'free')) {
                $oldResult->free();
            }
            if ($newResult && method_exists($newResult, 'free')) {
                $newResult->free();
            }

            if (!$oldExists || $newExists) {
                continue;
            }

            $sql = sprintf(
                "ALTER TABLE `%s` CHANGE COLUMN `%s` `%s` %s",
                $table,
                $oldColumn,
                $newColumn,
                $definition
            );

            if ($conn->query($sql) !== true) {
                error_log(sprintf('[MealsDB Installer] Failed renaming column %s to %s: %s', $oldColumn, $newColumn, $conn->error));
            }
        }
    }

    /**
     * Ensure new meals_clients columns exist for upgraded installations.
     */
    private static function ensure_meals_clients_columns(mysqli $conn): void {
        $table = 'meals_clients';
        $columns = [
            'assigned_social_worker'       => 'VARCHAR(191) NULL',
            'social_worker_email'          => 'VARCHAR(191) NULL',
            'phone_secondary'              => 'VARCHAR(25) NULL',
            'do_not_call_client_phone'     => 'TINYINT(1) NOT NULL DEFAULT 0',
            'alt_contact_name'             => 'VARCHAR(191) NULL',
            'alt_contact_phone_primary'    => 'VARCHAR(25) NULL',
            'alt_contact_phone_secondary'  => 'VARCHAR(25) NULL',
            'alt_contact_email'            => 'VARCHAR(191) NULL',
            'address_street_number'        => 'VARCHAR(50) NULL',
            'address_street_name'          => 'VARCHAR(191) NULL',
            'address_unit'                 => 'VARCHAR(50) NULL',
            'delivery_address_street_number' => 'VARCHAR(50) NULL',
            'delivery_address_street_name' => 'VARCHAR(191) NULL',
            'delivery_address_unit'        => 'VARCHAR(50) NULL',
            'delivery_address_city'        => 'VARCHAR(191) NULL',
            'delivery_address_province'    => 'VARCHAR(191) NULL',
            'delivery_address_postal'      => 'VARCHAR(20) NULL',
            'gender'                       => 'VARCHAR(50) NULL',
            'service_center_charged'       => 'VARCHAR(191) NULL',
            'vendor_number'                => 'VARCHAR(191) NULL',
            'service_id'                   => 'VARCHAR(191) NULL',
            'payment_method'               => 'VARCHAR(191) NULL',
            'client_contribution'          => 'VARCHAR(191) NULL',
            'delivery_fee'                 => 'VARCHAR(191) NULL',
            'freezer_capacity'             => 'VARCHAR(191) NULL',
            'meal_type'                    => 'VARCHAR(191) NULL',
            'requisition_period'           => 'VARCHAR(191) NULL',
            'initial_renewal_date'         => 'DATE NULL',
            'termination_date'             => 'DATE NULL',
            'most_recent_renewal_date'     => 'DATE NULL',
            'units'                        => 'TINYINT UNSIGNED NULL',
        ];

        foreach ($columns as $column => $definition) {
            $escaped = $conn->real_escape_string($column);
            $result = $conn->query(sprintf("SHOW COLUMNS FROM `%s` LIKE '%s'", $table, $escaped));
            $exists = ($result && property_exists($result, 'num_rows') && $result->num_rows > 0);

            if ($result && method_exists($result, 'free')) {
                $result->free();
            }

            if ($exists) {
                continue;
            }

            $sql = sprintf("ALTER TABLE `%s` ADD COLUMN `%s` %s", $table, $column, $definition);
            if ($conn->query($sql) !== true) {
                error_log(sprintf('[MealsDB Installer] Failed adding column %s: %s', $column, $conn->error));
            }
        }
    }
}
