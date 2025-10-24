<?php
/**
 * Installer responsible for preparing the Meals DB schema.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Created as a work for hire for Meals and More.
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
                first_name VARCHAR(191) NOT NULL,
                last_name VARCHAR(191) NOT NULL,
                client_email VARCHAR(191) NOT NULL,
                phone_primary VARCHAR(25) NOT NULL,
                address_postal VARCHAR(20) NOT NULL,
                customer_type VARCHAR(100) NOT NULL,
                address_city VARCHAR(191) NULL,
                address_province VARCHAR(191) NULL,
                service_center VARCHAR(191) NULL,
                service_zone VARCHAR(191) NULL,
                service_course VARCHAR(191) NULL,
                per_sdnb_req VARCHAR(191) NULL,
                rate VARCHAR(191) NULL,
                delivery_day VARCHAR(191) NULL,
                delivery_area_name VARCHAR(191) NULL,
                delivery_area_zone VARCHAR(191) NULL,
                ordering_frequency VARCHAR(191) NULL,
                ordering_contact_method VARCHAR(191) NULL,
                delivery_frequency VARCHAR(191) NULL,
                diet_concerns TEXT NULL,
                client_comments TEXT NULL,
                birth_date DATE NULL,
                open_date DATE NULL,
                required_start_date DATE NULL,
                service_commence_date DATE NULL,
                expected_termination_date DATE NULL,
                initial_termination_date DATE NULL,
                recent_renewal_date DATE NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_individual_id (individual_id),
                UNIQUE KEY unique_requisition_id (requisition_id),
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
    }
}
