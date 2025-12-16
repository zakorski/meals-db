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

        $transactions_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::TRANSACTIONS));
        $drafts_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::DRAFTS));
        $ignored_conflicts_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::IGNORED_CONFLICTS));
        $audit_log_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::AUDIT_LOG));
        $staff_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::STAFF));

        $tables = [
            MealsDB_Tables::DRAFTS => "CREATE TABLE IF NOT EXISTS `{$drafts_table}` (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                data LONGTEXT NOT NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_created_by (created_by)
            ) ENGINE=InnoDB $charset_sql;",
            MealsDB_Tables::IGNORED_CONFLICTS => "CREATE TABLE IF NOT EXISTS `{$ignored_conflicts_table}` (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                field_name VARCHAR(191) NOT NULL,
                source_value TEXT NULL,
                target_value TEXT NULL,
                ignored_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_field_name (field_name),
                KEY idx_ignored_by (ignored_by)
            ) ENGINE=InnoDB $charset_sql;",
            MealsDB_Tables::AUDIT_LOG => "CREATE TABLE IF NOT EXISTS `{$audit_log_table}` (
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
            MealsDB_Tables::TRANSACTIONS => "CREATE TABLE IF NOT EXISTS `{$transactions_table}` (
                transaction_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_id INT UNSIGNED NOT NULL,
                wp_order_id BIGINT UNSIGNED NULL,
                wp_order_item_id BIGINT UNSIGNED NULL,
                order_date DATE NOT NULL,
                delivery_date DATE NOT NULL,
                subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                taxes DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                metadata JSON NULL,
                status ENUM('Ordered','Delivered','Cancelled') NOT NULL DEFAULT 'Ordered',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_client_id (client_id)
            ) ENGINE=InnoDB $charset_sql;",
            MealsDB_Tables::STAFF => "CREATE TABLE IF NOT EXISTS `{$staff_table}` (
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

        self::create_meals_products_table();
    }

    /**
     * Create the meals_transactions table in the external Meals DB.
     */
    private static function create_table_transactions($conn): void {
        if (!MealsDB_DB::is_mysqli($conn)) {
            error_log('[MealsDB Installer] Unable to establish database connection while creating ' . MealsDB_Tables::TRANSACTIONS . '.');
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

        $transactions_table = MealsDB_DB::get_table_name(MealsDB_Tables::TRANSACTIONS);
        $clients_table      = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $transactions_table = str_replace('`', '``', $transactions_table);
        $clients_table      = str_replace('`', '``', $clients_table);

        $sql = "CREATE TABLE IF NOT EXISTS `{$transactions_table}` (
            transaction_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            wp_order_id BIGINT UNSIGNED NULL,
            wp_order_item_id BIGINT UNSIGNED NULL,
            order_date DATE NOT NULL,
            delivery_date DATE NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            taxes DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            metadata JSON NULL,
            status ENUM('Ordered','Delivered','Cancelled') NOT NULL DEFAULT 'Ordered',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_client_id (client_id),
            CONSTRAINT fk_meals_transactions_client FOREIGN KEY (client_id) REFERENCES `{$clients_table}`(client_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB {$charset_sql};";

        if (!$conn->query($sql)) {
            error_log('[MealsDB Installer] Failed creating ' . MealsDB_Tables::TRANSACTIONS . ' table: ' . $conn->error);
        }
    }

    /**
     * Ensure the status column exists on meals_transactions.
     */
    private static function alter_transactions_add_status($conn): void {
        if (!MealsDB_DB::is_mysqli($conn)) {
            error_log('[MealsDB Installer] Unable to establish database connection while altering ' . MealsDB_Tables::TRANSACTIONS . '.');
            return;
        }

        $transactions_table = MealsDB_DB::get_table_name(MealsDB_Tables::TRANSACTIONS);
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
            error_log('[MealsDB Installer] Failed inspecting ' . MealsDB_Tables::TRANSACTIONS . '.status column: ' . $conn->error);
            return;
        }

        if ($column_exists) {
            return;
        }

        $alter_sql = "ALTER TABLE `{$transactions_table}` ADD COLUMN `{$column_name}` ENUM('Ordered','Delivered','Cancelled') NOT NULL DEFAULT 'Ordered'";

        if (!$conn->query($alter_sql)) {
            error_log('[MealsDB Installer] Failed adding ' . MealsDB_Tables::TRANSACTIONS . '.status column: ' . $conn->error);
        }
    }

    /**
     * Create the meals_transaction_items table in the external Meals DB.
     */
    private static function create_table_transaction_items($conn): void {
        if (!MealsDB_DB::is_mysqli($conn)) {
            error_log('[MealsDB Installer] Unable to establish database connection while creating ' . MealsDB_Tables::TRANSACTION_ITEMS . '.');
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

        $transaction_items_table = MealsDB_DB::get_table_name(MealsDB_Tables::TRANSACTION_ITEMS);
        $transactions_table      = MealsDB_DB::get_table_name(MealsDB_Tables::TRANSACTIONS);
        $products_table          = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);

        $transaction_items_table = str_replace('`', '``', $transaction_items_table);
        $transactions_table      = str_replace('`', '``', $transactions_table);
        $products_table          = str_replace('`', '``', $products_table);

        $sql = "CREATE TABLE IF NOT EXISTS `{$transaction_items_table}` (
            transaction_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            line_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            line_taxes DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            KEY idx_transaction_id (transaction_id),
            KEY idx_product_id (product_id),
            CONSTRAINT fk_meals_transaction_items_transaction FOREIGN KEY (transaction_id)
                REFERENCES `{$transactions_table}`(transaction_id) ON DELETE CASCADE,
            CONSTRAINT fk_meals_transaction_items_product FOREIGN KEY (product_id)
                REFERENCES `{$products_table}`(product_id) ON DELETE CASCADE
        ) ENGINE=InnoDB {$charset_sql};";

        if (!$conn->query($sql)) {
            error_log('[MealsDB Installer] Failed creating ' . MealsDB_Tables::TRANSACTION_ITEMS . ' table: ' . $conn->error);
        }
    }

    /**
     * Apply schema updates required for the meals_clients table.
     */
    private static function upgrade_meals_clients_table($conn): void {
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

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
            error_log('[MealsDB Installer] Failed inspecting ' . MealsDB_Tables::CLIENTS . '.active column: ' . $conn->error);
            return;
        }

        if (!$columnExists) {
            $alterSql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` TINYINT(1) NOT NULL DEFAULT 1";

            if (!$conn->query($alterSql)) {
                error_log('[MealsDB Installer] Failed adding ' . MealsDB_Tables::CLIENTS . '.active column: ' . $conn->error);
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
            error_log('[MealsDB Installer] Failed inspecting ' . MealsDB_Tables::CLIENTS . '.client_type column: ' . $conn->error);
            return;
        }

        if ($clientTypeExists && $clientTypeIncludesStaff) {
            $migrationSql = "UPDATE `{$tableName}` SET `{$clientTypeColumn}` = 'Private' WHERE `{$clientTypeColumn}` = 'Staff'";
            if (!$conn->query($migrationSql)) {
                error_log('[MealsDB Installer] Failed migrating Staff client types: ' . $conn->error);
            }

            $modifySql = "ALTER TABLE `{$tableName}` MODIFY COLUMN `{$clientTypeColumn}` ENUM('Private','SDNB','Veteran') NOT NULL";
            if (!$conn->query($modifySql)) {
                error_log('[MealsDB Installer] Failed updating ' . MealsDB_Tables::CLIENTS . '.client_type enum: ' . $conn->error);
            }
        }
    }

    /**
     * Create or migrate the meals_products table in the external Meals DB.
     */
    private static function create_meals_products_table(): void {
        $conn = MealsDB_DB::get_connection();

        if (!MealsDB_DB::is_mysqli($conn)) {
            error_log('[MealsDB Installer] Unable to establish database connection while creating ' . MealsDB_Tables::PRODUCTS . '.');
            return;
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
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
            error_log('[MealsDB Installer] Failed creating ' . MealsDB_Tables::PRODUCTS . ' table: ' . $conn->error);
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
            error_log('[MealsDB Installer] Failed inspecting ' . MealsDB_Tables::PRODUCTS . ' columns: ' . $conn->error);
            return;
        }

        foreach ($required_columns as $column => $definition) {
            if (!array_key_exists(strtolower($column), $existing_columns)) {
                $alter_sql = "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}";

                if (!$conn->query($alter_sql)) {
                    error_log(sprintf('[MealsDB Installer] Failed adding %s.%s column: %s', MealsDB_Tables::PRODUCTS, $column, $conn->error));
                }
            }
        }

        if (isset($existing_columns['product_type']) && isset($existing_columns['product_type']['Type'])) {
            $product_type = strtolower((string) $existing_columns['product_type']['Type']);

            if (strpos($product_type, "enum('meal','side')") === false) {
                $modify_sql = "ALTER TABLE `{$table}` MODIFY COLUMN product_type ENUM('meal','side') NOT NULL DEFAULT 'meal'";

                if (!$conn->query($modify_sql)) {
                    error_log('[MealsDB Installer] Failed modifying ' . MealsDB_Tables::PRODUCTS . '.product_type enum: ' . $conn->error);
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
