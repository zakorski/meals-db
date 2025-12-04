<?php
/**
 * Synchronize external Meals DB schemas with installer definitions.
 */

class MealsDB_Schema_Sync {

    /**
     * Run the full schema sync for supported tables.
     *
     * @return true|WP_Error
     */
    public static function run_full_sync() {
        $conn = MealsDB_DB::get_connection();
        if (!$conn instanceof mysqli) {
            return new WP_Error('db_error', 'Unable to connect to external Meals DB.');
        }

        $expected_clients_table = [
            'table'       => 'meals_clients',
            'columns'     => [
                'client_id'                     => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                'wp_user_id'                    => 'BIGINT(20) UNSIGNED NOT NULL',
                'client_type'                  => "ENUM('Private','SDNB','Veteran') NOT NULL",
                'first_name'                   => 'VARCHAR(100) NOT NULL',
                'last_name'                    => 'VARCHAR(100) NOT NULL',
                'client_email'                 => 'VARCHAR(255) NULL',
                'active'                       => 'TINYINT(1) NOT NULL DEFAULT 1',
                'client_phone_1'               => 'VARCHAR(20) NULL',
                'client_phone_2'               => 'VARCHAR(20) NULL',
                'alternate_contact_name'       => 'VARCHAR(255) NULL',
                'alternate_contact_phone_1'    => 'VARCHAR(20) NULL',
                'alternate_contact_phone_2'    => 'VARCHAR(20) NULL',
                'alternate_contact_email'      => 'VARCHAR(255) NULL',
                'do_not_call_client_phone'     => 'BOOLEAN NOT NULL DEFAULT 0',
                'payment_method'               => 'VARCHAR(50) NULL',
                'open_date'                    => 'DATE NULL',
                'birth_date'                   => 'DATE NULL',
                'gender'                       => 'VARCHAR(10) NULL',
                'assigned_worker_name'         => 'VARCHAR(255) NULL',
                'assigned_worker_email'        => 'VARCHAR(255) NULL',
                'vendor_number'                => 'VARCHAR(50) NULL',
                'service_center_charged'       => 'VARCHAR(255) NULL',
                'service_id'                   => 'VARCHAR(50) NULL',
                'requisition_id'               => 'VARCHAR(50) NULL',
                'requisition_period'           => 'VARCHAR(50) NULL',
                'meal_type'                    => 'VARCHAR(50) NULL',
                'service_name_zone'            => 'VARCHAR(10) NULL',
                'service_name_course'          => 'VARCHAR(10) NULL',
                'service_commence_date'        => 'DATE NULL',
                'expected_termination_date'    => 'DATE NULL',
                'initial_renewal_termination_date' => 'DATE NULL',
                'most_recent_renewal_termination_date' => 'DATE NULL',
                'notes_to_service_provider'    => 'TEXT NULL',
                'client_contribution'          => 'DECIMAL(10,2) NULL',
                'vet_health_id_card'           => 'VARCHAR(50) NULL',
                'rate'                         => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                'required_start_date'          => 'DATE NULL',
                'delivery_day'                 => 'VARCHAR(50) NULL',
                'delivery_area_name'           => 'VARCHAR(255) NULL',
                'delivery_area_zone'           => 'VARCHAR(50) NULL',
                'ordering_contact_method'      => 'VARCHAR(50) NULL',
                'ordering_frequency'           => 'INT NULL',
                'delivery_frequency'           => 'INT NULL',
                'freezer_capacity'             => 'VARCHAR(50) NULL',
                'delivery_fee'                 => 'DECIMAL(10,2) NULL',
                'diet_concerns'                => 'TEXT NULL',
                'customer_comments'            => 'TEXT NULL',
                'initials_for_delivery'        => 'VARCHAR(10) NULL',
                'initials_delivery'            => "VARCHAR(3) NOT NULL DEFAULT ''",
                'street_number'                => 'VARCHAR(20) NULL',
                'street_name'                  => 'VARCHAR(255) NULL',
                'apartment_number'             => 'VARCHAR(20) NULL',
                'city'                         => 'VARCHAR(255) NULL',
                'province'                     => 'VARCHAR(10) NULL',
                'postal_code'                  => 'VARCHAR(10) NULL',
                'delivery_street_number'       => 'VARCHAR(20) NULL',
                'delivery_street_name'         => 'VARCHAR(255) NULL',
                'delivery_apartment_number'    => 'VARCHAR(20) NULL',
                'delivery_city'                => 'VARCHAR(255) NULL',
                'delivery_province'            => 'VARCHAR(10) NULL',
                'delivery_postal_code'         => 'VARCHAR(10) NULL',
            ],
            'primary_key' => 'client_id',
            'indexes'     => [
                [
                    'name'    => 'client_type',
                    'type'    => 'INDEX',
                    'columns' => ['client_type'],
                ],
                [
                    'name'    => 'initials_delivery_unique',
                    'type'    => 'UNIQUE',
                    'columns' => ['initials_delivery'],
                ],
                [
                    'name'    => 'wp_user_id',
                    'type'    => 'INDEX',
                    'columns' => ['wp_user_id'],
                ],
            ],
        ];

        $result = self::ensure_table_exists($conn, $expected_clients_table);
        if (is_wp_error($result)) {
            return $result;
        }

        $result = self::ensure_columns($conn, $expected_clients_table);
        if (is_wp_error($result)) {
            return $result;
        }

        $result = self::ensure_primary_key($conn, $expected_clients_table);
        if (is_wp_error($result)) {
            return $result;
        }

        $result = self::ensure_indexes($conn, $expected_clients_table);
        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Ensure the table exists with the base schema.
     */
    private static function ensure_table_exists(mysqli $conn, array $schema) {
        $table          = MealsDB_DB::get_table_name($schema['table']);
        $escaped_table  = $conn->real_escape_string($table);
        $res            = $conn->query("SHOW TABLES LIKE '{$escaped_table}'");

        if ($res === false) {
            return new WP_Error('db_error', $conn->error);
        }

        if ($res && $res->num_rows > 0) {
            return true;
        }

        $cols = [];
        foreach ($schema['columns'] as $name => $definition) {
            $cols[] = sprintf('`%s` %s', $name, $definition);
        }
        $cols[] = sprintf('PRIMARY KEY (`%s`)', $schema['primary_key']);

        if (!empty($schema['indexes']) && is_array($schema['indexes'])) {
            foreach ($schema['indexes'] as $index) {
                $cols[] = self::build_index_definition($index);
            }
        }

        $ddl = sprintf('CREATE TABLE `%s` (%s) ENGINE=InnoDB;', $table, implode(',', $cols));

        $create_result = $conn->query($ddl);
        if ($create_result === false) {
            return new WP_Error('db_error', $conn->error);
        }

        return true;
    }

    /**
     * Ensure all columns exist on the target table.
     */
    private static function ensure_columns(mysqli $conn, array $schema) {
        $table = MealsDB_DB::get_table_name($schema['table']);

        $existing = [];
        $res      = $conn->query(sprintf('SHOW COLUMNS FROM `%s`', str_replace('`', '``', $table)));
        if ($res === false) {
            return new WP_Error('db_error', $conn->error);
        }

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $existing[$row['Field']] = true;
            }
        }

        foreach ($schema['columns'] as $name => $definition) {
            if (!isset($existing[$name])) {
                $sql = sprintf('ALTER TABLE `%s` ADD `%s` %s', $table, $name, $definition);
                if ($conn->query($sql) === false) {
                    return new WP_Error('db_error', $conn->error);
                }
            }
        }

        return true;
    }

    /**
     * Ensure the primary key exists.
     */
    private static function ensure_primary_key(mysqli $conn, array $schema) {
        $table  = MealsDB_DB::get_table_name($schema['table']);
        $pkname = $schema['primary_key'];

        $res = $conn->query(sprintf('SHOW KEYS FROM `%s` WHERE Key_name = "PRIMARY"', str_replace('`', '``', $table)));
        if ($res === false) {
            return new WP_Error('db_error', $conn->error);
        }

        if ($res && $res->num_rows > 0) {
            return true;
        }

        $alter = sprintf('ALTER TABLE `%s` ADD PRIMARY KEY (`%s`)', $table, $pkname);
        if ($conn->query($alter) === false) {
            return new WP_Error('db_error', $conn->error);
        }

        return true;
    }

    /**
     * Ensure secondary indexes exist.
     */
    private static function ensure_indexes(mysqli $conn, array $schema) {
        $table = MealsDB_DB::get_table_name($schema['table']);

        if (empty($schema['indexes']) || !is_array($schema['indexes'])) {
            return true;
        }

        $existing_indexes = [];
        $res              = $conn->query(sprintf('SHOW INDEX FROM `%s`', str_replace('`', '``', $table)));

        if ($res === false) {
            return new WP_Error('db_error', $conn->error);
        }

        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (!empty($row['Key_name'])) {
                    $existing_indexes[$row['Key_name']] = true;
                }
            }
        }

        foreach ($schema['indexes'] as $index) {
            if (empty($index['name']) || empty($index['columns'])) {
                continue;
            }

            if (isset($existing_indexes[$index['name']])) {
                continue;
            }

            $alter = sprintf('ALTER TABLE `%s` ADD %s', $table, self::build_index_definition($index));
            if ($conn->query($alter) === false) {
                return new WP_Error('db_error', $conn->error);
            }
        }

        return true;
    }

    /**
     * Build an index definition snippet.
     */
    private static function build_index_definition(array $index): string {
        $type = strtoupper($index['type'] ?? 'INDEX');
        $name = $index['name'] ?? '';
        $cols = array_map(static function ($col) {
            return sprintf('`%s`', $col);
        }, $index['columns'] ?? []);

        $type_sql = $type === 'UNIQUE' ? 'UNIQUE KEY' : 'KEY';

        return sprintf('%s `%s` (%s)', $type_sql, $name, implode(',', $cols));
    }
}
