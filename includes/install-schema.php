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
        $conn = MealsDB_DB::get_connection();

        if (!MealsDB_Config::is_db_configured()) {
            error_log('[MealsDB Installer] External DB credentials are not configured. Set MEALSDB_* env vars or define the constants before activating the plugin.');
            return;
        }

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
            'meals_transactions' => "CREATE TABLE IF NOT EXISTS meals_transactions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_id INT UNSIGNED NOT NULL,
                wp_order_id BIGINT UNSIGNED NOT NULL,
                order_date DATE NOT NULL,
                subtotal DECIMAL(10,2),
                total DECIMAL(10,2),
                metadata JSON,
                created_at DATETIME,
                updated_at DATETIME
            ) ENGINE=InnoDB $charset_sql;",
            'meals_staff' => "CREATE TABLE IF NOT EXISTS meals_staff (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                wordpress_user_id BIGINT UNSIGNED NULL,
                first_name VARCHAR(191) NOT NULL,
                last_name VARCHAR(191) NOT NULL,
                email VARCHAR(191) NOT NULL,
                phone VARCHAR(50) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB $charset_sql;",
        ];

        foreach ($tables as $table => $sql) {
            if (!$conn->query($sql)) {
                error_log(sprintf('[MealsDB Installer] Failed creating %s: %s', $table, $conn->error));
            }
        }

        self::upgrade_meals_clients_table($conn);

        self::create_meals_clients_table();
    }

    /**
     * Apply schema updates required for the meals_clients table.
     */
    private static function upgrade_meals_clients_table(mysqli $conn): void {
        $table = MealsDB_DB::get_table_name('meals_clients');

        $tableName = method_exists($conn, 'real_escape_string')
            ? $conn->real_escape_string($table)
            : $table;
        $tableName = str_replace('`', '``', $tableName);

        $columnName = 'active';
        $escapedColumn = method_exists($conn, 'real_escape_string')
            ? $conn->real_escape_string($columnName)
            : $columnName;
        $escapedColumn = str_replace('`', '``', $escapedColumn);

        $columnExists = false;
        $columnSql    = "SHOW COLUMNS FROM `{$tableName}` LIKE '{$escapedColumn}'";
        $result       = $conn->query($columnSql);

        if ($result instanceof mysqli_result) {
            $columnExists = $result->num_rows > 0;
            $result->free();
        } elseif ($result && isset($result->num_rows)) {
            $columnExists = $result->num_rows > 0;
            if (method_exists($result, 'free')) {
                $result->free();
            }
        } elseif ($result === false) {
            error_log('[MealsDB Installer] Failed inspecting meals_clients.active column: ' . $conn->error);
            return;
        }

        if ($columnExists) {
            return;
        }

        $alterSql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` TINYINT(1) NOT NULL DEFAULT 1";

        if (!$conn->query($alterSql)) {
            error_log('[MealsDB Installer] Failed adding meals_clients.active column: ' . $conn->error);
        }
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
            active TINYINT(1) NOT NULL DEFAULT 1,
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
            initials_delivery VARCHAR(3) NOT NULL DEFAULT '',
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
            UNIQUE KEY initials_delivery_unique (initials_delivery),
            KEY wp_user_id (wp_user_id)
        ) {$charset_collate};";

        dbDelta($sql);
    }
}
