<?php
/**
 * Installer responsible for preparing the Meals DB schema.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Installer {

    const MEALSDB_PRODUCTS_SCHEMA_VERSION = 1;

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

        if (!MealsDB_DB::is_mysqli($conn)) {
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

        self::create_table_transactions($conn);
        self::alter_transactions_add_status($conn);
        self::create_table_transaction_items($conn);

        self::upgrade_meals_clients_table($conn);

        self::create_meals_clients_table();

        self::create_meals_products_table();
    }

    /**
     * Create the mealsdb_transactions table in the external Meals DB.
     */
    private static function create_table_transactions($conn): void {
        if (!MealsDB_DB::is_mysqli($conn)) {
            error_log('[MealsDB Installer] Unable to establish database connection while creating mealsdb_transactions.');
            return;
        }

        $charset_sql = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $charset   = $conn->character_set_name();
        $collation = method_exists($conn, 'get_charset') ? $conn->get_charset() : null;

        if (!empty($charset)) {
            $collation_name = 'utf8mb4_unicode_ci';

            if (is_object($collation) && property_exists($collation, 'collation') && !empty($collation->collation)) {
                $collation_name = $collation->collation;
            }

            $charset_sql = sprintf('DEFAULT CHARSET=%s COLLATE=%s', $charset, $collation_name);
        }

        $transactions_table = MealsDB_DB::get_table_name('mealsdb_transactions');
        $clients_table      = MealsDB_DB::get_table_name('mealsdb_clients');

        $transactions_table = str_replace('`', '``', $transactions_table);
        $clients_table      = str_replace('`', '``', $clients_table);

        $sql = "CREATE TABLE IF NOT EXISTS `{$transactions_table}` (
            transaction_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            order_date DATE NOT NULL,
            delivery_date DATE NOT NULL,
            status ENUM('Ordered','Delivered','Cancelled') NOT NULL DEFAULT 'Ordered',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_client_id (client_id),
            CONSTRAINT fk_mealsdb_transactions_client FOREIGN KEY (client_id) REFERENCES `{$clients_table}`(client_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB {$charset_sql};";

        if (!$conn->query($sql)) {
            error_log('[MealsDB Installer] Failed creating mealsdb_transactions table: ' . $conn->error);
        }
    }

    /**
     * Ensure the status column exists on mealsdb_transactions.
     */
    private static function alter_transactions_add_status($conn): void {
        if (!MealsDB_DB::is_mysqli($conn)) {
            error_log('[MealsDB Installer] Unable to establish database connection while altering mealsdb_transactions.');
            return;
        }

        $transactions_table = MealsDB_DB::get_table_name('mealsdb_transactions');
        $transactions_table = str_replace('`', '``', $transactions_table);

        $column_name    = 'status';
        $escaped_column = str_replace('`', '``', $column_name);

        $column_exists = false;
        $column_sql    = "SHOW COLUMNS FROM `{$transactions_table}` LIKE '{$escaped_column}'";
        $column_result = $conn->query($column_sql);

        if (MealsDB_DB::is_mysqli_result($column_result)) {
            $column_exists = $column_result->num_rows > 0;
            $column_result->free();
        } elseif ($column_result && isset($column_result->num_rows)) {
            $column_exists = $column_result->num_rows > 0;
            if (method_exists($column_result, 'free')) {
                $column_result->free();
            }
        } elseif ($column_result === false) {
            error_log('[MealsDB Installer] Failed inspecting mealsdb_transactions.status column: ' . $conn->error);
            return;
        }

        if ($column_exists) {
            return;
        }

        $alter_sql = "ALTER TABLE `{$transactions_table}` ADD COLUMN `{$column_name}` ENUM('Ordered','Delivered','Cancelled') NOT NULL DEFAULT 'Ordered'";

        if (!$conn->query($alter_sql)) {
            error_log('[MealsDB Installer] Failed adding mealsdb_transactions.status column: ' . $conn->error);
        }
    }

    /**
     * Create the mealsdb_transaction_items table in the external Meals DB.
     */
    private static function create_table_transaction_items($conn): void {
        if (!MealsDB_DB::is_mysqli($conn)) {
            error_log('[MealsDB Installer] Unable to establish database connection while creating mealsdb_transaction_items.');
            return;
        }

        $charset_sql = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $charset   = $conn->character_set_name();
        $collation = method_exists($conn, 'get_charset') ? $conn->get_charset() : null;

        if (!empty($charset)) {
            $collation_name = 'utf8mb4_unicode_ci';

            if (is_object($collation) && property_exists($collation, 'collation') && !empty($collation->collation)) {
                $collation_name = $collation->collation;
            }

            $charset_sql = sprintf('DEFAULT CHARSET=%s COLLATE=%s', $charset, $collation_name);
        }

        $transaction_items_table = MealsDB_DB::get_table_name('mealsdb_transaction_items');
        $transactions_table      = MealsDB_DB::get_table_name('mealsdb_transactions');
        $products_table          = MealsDB_DB::get_table_name('mealsdb_products');

        $transaction_items_table = str_replace('`', '``', $transaction_items_table);
        $transactions_table      = str_replace('`', '``', $transactions_table);
        $products_table          = str_replace('`', '``', $products_table);

        $sql = "CREATE TABLE IF NOT EXISTS `{$transaction_items_table}` (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            KEY idx_transaction_id (transaction_id),
            KEY idx_item_id (item_id),
            CONSTRAINT fk_mealsdb_transaction_items_transaction FOREIGN KEY (transaction_id)
                REFERENCES `{$transactions_table}`(transaction_id) ON DELETE CASCADE,
            CONSTRAINT fk_mealsdb_transaction_items_product FOREIGN KEY (item_id)
                REFERENCES `{$products_table}`(product_id) ON DELETE CASCADE
        ) ENGINE=InnoDB {$charset_sql};";

        if (!$conn->query($sql)) {
            error_log('[MealsDB Installer] Failed creating mealsdb_transaction_items table: ' . $conn->error);
        }
    }

    /**
     * Apply schema updates required for the meals_clients table.
     */
    private static function upgrade_meals_clients_table($conn): void {
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

        if (MealsDB_DB::is_mysqli_result($result)) {
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

        if (!$columnExists) {
            $alterSql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` TINYINT(1) NOT NULL DEFAULT 1";

            if (!$conn->query($alterSql)) {
                error_log('[MealsDB Installer] Failed adding meals_clients.active column: ' . $conn->error);
            }
        }

        $clientTypeColumn    = 'client_type';
        $escapedClientColumn = method_exists($conn, 'real_escape_string')
            ? $conn->real_escape_string($clientTypeColumn)
            : $clientTypeColumn;
        $escapedClientColumn = str_replace('`', '``', $escapedClientColumn);

        $clientTypeSql    = "SHOW COLUMNS FROM `{$tableName}` LIKE '{$escapedClientColumn}'";
        $clientTypeResult = $conn->query($clientTypeSql);
        $clientTypeExists = false;
        $clientTypeIncludesStaff = false;

        if (MealsDB_DB::is_mysqli_result($clientTypeResult)) {
            $clientTypeExists = $clientTypeResult->num_rows > 0;
            if ($clientTypeExists) {
                $row = $clientTypeResult->fetch_assoc();
                if (isset($row['Type']) && stripos((string) $row['Type'], "'staff'") !== false) {
                    $clientTypeIncludesStaff = true;
                }
            }
            $clientTypeResult->free();
        } elseif ($clientTypeResult && isset($clientTypeResult->num_rows)) {
            $clientTypeExists = $clientTypeResult->num_rows > 0;
            if ($clientTypeExists && method_exists($clientTypeResult, 'fetch_assoc')) {
                $row = $clientTypeResult->fetch_assoc();
                if (isset($row['Type']) && stripos((string) $row['Type'], "'staff'") !== false) {
                    $clientTypeIncludesStaff = true;
                }
            }
            if (method_exists($clientTypeResult, 'free')) {
                $clientTypeResult->free();
            }
        } elseif ($clientTypeResult === false) {
            error_log('[MealsDB Installer] Failed inspecting meals_clients.client_type column: ' . $conn->error);
            return;
        }

        if ($clientTypeExists && $clientTypeIncludesStaff) {
            $migrationSql = "UPDATE `{$tableName}` SET `{$clientTypeColumn}` = 'Private' WHERE `{$clientTypeColumn}` = 'Staff'";
            if (!$conn->query($migrationSql)) {
                error_log('[MealsDB Installer] Failed migrating Staff client types: ' . $conn->error);
            }

            $modifySql = "ALTER TABLE `{$tableName}` MODIFY COLUMN `{$clientTypeColumn}` ENUM('Private','SDNB','Veteran') NOT NULL";
            if (!$conn->query($modifySql)) {
                error_log('[MealsDB Installer] Failed updating meals_clients.client_type enum: ' . $conn->error);
            }
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
            client_type ENUM('Private','SDNB','Veteran') NOT NULL,
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

    /**
     * Create or migrate the meals_products table in the external Meals DB.
     */
    private static function create_meals_products_table(): void {
        $conn = MealsDB_DB::get_connection();

        if (!MealsDB_DB::is_mysqli($conn)) {
            error_log('[MealsDB Installer] Unable to establish database connection while creating meals_products.');
            return;
        }

        $table = MealsDB_DB::get_table_name('meals_products');
        $table = str_replace('`', '``', $table);
        $version_option = 'mealsdb_products_schema_version';
        $target_version = self::MEALSDB_PRODUCTS_SCHEMA_VERSION;

        $create_sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wc_product_id INT NOT NULL UNIQUE,
    product_type ENUM('meal','side') NOT NULL DEFAULT 'meal',
    taxable TINYINT(1) NOT NULL DEFAULT 0,
    main_ingredient VARCHAR(40) NOT NULL,
    dietary_tags JSON NULL,
    allergen_flags JSON NULL,
    case_size INT NOT NULL DEFAULT 1,
    unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP 
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        if (!$conn->query($create_sql)) {
            error_log('[MealsDB Installer] Failed creating meals_products table: ' . $conn->error);
            return;
        }

        $required_columns = [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'wc_product_id' => 'INT NOT NULL UNIQUE',
            "product_type" => "ENUM('meal','side') NOT NULL DEFAULT 'meal'",
            'taxable' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'main_ingredient' => 'VARCHAR(40) NOT NULL',
            'dietary_tags' => 'JSON NULL',
            'allergen_flags' => 'JSON NULL',
            'case_size' => 'INT NOT NULL DEFAULT 1',
            'unit_cost' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'last_updated' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ];

        $existing_columns = [];
        $columns_result = $conn->query("SHOW COLUMNS FROM `{$table}`");

        if (MealsDB_DB::is_mysqli_result($columns_result)) {
            while ($row = $columns_result->fetch_assoc()) {
                if (isset($row['Field'])) {
                    $existing_columns[strtolower($row['Field'])] = $row;
                }
            }
            $columns_result->free();
        } elseif ($columns_result === false) {
            error_log('[MealsDB Installer] Failed inspecting meals_products columns: ' . $conn->error);
            return;
        }

        foreach ($required_columns as $column => $definition) {
            if (!array_key_exists(strtolower($column), $existing_columns)) {
                $alter_sql = "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}";

                if (!$conn->query($alter_sql)) {
                    error_log(sprintf('[MealsDB Installer] Failed adding meals_products.%s column: %s', $column, $conn->error));
                }
            }
        }

        if (isset($existing_columns['product_type']) && isset($existing_columns['product_type']['Type'])) {
            $product_type = strtolower((string) $existing_columns['product_type']['Type']);

            if (strpos($product_type, "enum('meal','side')") === false) {
                $modify_sql = "ALTER TABLE `{$table}` MODIFY COLUMN product_type ENUM('meal','side') NOT NULL DEFAULT 'meal'";

                if (!$conn->query($modify_sql)) {
                    error_log('[MealsDB Installer] Failed modifying meals_products.product_type enum: ' . $conn->error);
                }
            }
        }

        $current_version = get_option($version_option, 0);

        if ((int) $current_version !== (int) $target_version) {
            if (get_option($version_option, null) === null) {
                add_option($version_option, $target_version);
            } else {
                update_option($version_option, $target_version);
            }
        }
    }
}
