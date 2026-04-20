<?php
/**
 * Canonical Meals DB schema definitions and helpers.
 */
defined('ABSPATH') || exit;

class MealsDB_Schema {
    /**
     * Return canonical schema definitions keyed by base table name.
     *
     * Foreign key definitions are kept as metadata only for reporting and dependency
     * awareness; sync routines never execute them directly.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_canonical_schema(): array {
        return [
            MealsDB_Tables::CLIENTS => [
                'table'       => MealsDB_Tables::CLIENTS,
                'engine'      => 'InnoDB',
                'columns'     => [
                    'client_id'                     => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'wp_user_id'                    => 'BIGINT UNSIGNED NOT NULL',
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
                    'individual_id'                => 'VARCHAR(500) NULL',
                    'individual_id_index'          => 'CHAR(64) NULL',
                    'assigned_worker_name'         => 'VARCHAR(255) NULL',
                    'assigned_worker_email'        => 'VARCHAR(255) NULL',
                    'vendor_number'                => 'VARCHAR(50) NULL',
                    'service_center_charged'       => 'VARCHAR(255) NULL',
                    'service_id'                   => 'VARCHAR(50) NULL',
                    'sdnb_service_request_id'      => 'VARCHAR(50) NULL',
                    'requisition_id'               => 'VARCHAR(500) NULL',
                    'requisition_id_index'         => 'CHAR(64) NULL',
                    'requisition_period'           => 'VARCHAR(50) NULL',
                    'meal_type'                    => 'VARCHAR(50) NULL',
                    'service_name_zone'            => 'VARCHAR(10) NULL',
                    'service_commence_date'        => 'DATE NULL',
                    'expected_termination_date'    => 'DATE NULL',
                    'termination_date'             => 'DATE NULL',
                    'initial_renewal_termination_date' => 'DATE NULL',
                    'most_recent_renewal_termination_date' => 'DATE NULL',
                    'notes_to_service_provider'    => 'TEXT NULL',
                    'units'                        => 'INT NULL',
                    'allowance_mains'              => 'INT NULL',
                    'allowance_sides'              => 'INT NULL',
                    'client_contribution'          => 'DECIMAL(10,2) NULL',
                    'vet_health_card'              => 'VARCHAR(50) NULL',
                    'vet_health_card_index'        => 'CHAR(64) NULL',
                    'use_legacy_billing'           => 'TINYINT(1) NOT NULL DEFAULT 1',
                    'default_rate_id'              => 'BIGINT UNSIGNED NULL',
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
                    'per_sdnb_req'                 => 'TEXT NULL',
                    'delivery_initials'            => "VARCHAR(3) NOT NULL DEFAULT ''",
                    'delivery_initials_index'      => 'CHAR(64) NULL',
                    'street_name'                  => 'VARCHAR(255) NULL',
                    'city'                         => 'VARCHAR(255) NULL',
                    'province'                     => 'VARCHAR(10) NULL',
                    'postal_code'                  => 'VARCHAR(10) NULL',
                    'delivery_street_name'         => 'VARCHAR(255) NULL',
                    'delivery_city'                => 'VARCHAR(255) NULL',
                    'delivery_province'            => 'VARCHAR(10) NULL',
                    'delivery_postal_code'         => 'VARCHAR(10) NULL',
                ],
                'primary_key' => ['client_id'],
                'indexes'     => [
                    [
                        'name'    => 'client_type',
                        'type'    => 'INDEX',
                        'columns' => ['client_type'],
                    ],
                    [
                        'name'    => 'delivery_initials_index',
                        'type'    => 'INDEX',
                        'columns' => ['delivery_initials'],
                    ],
                    [
                        'name'    => 'wp_user_id',
                        'type'    => 'INDEX',
                        'columns' => ['wp_user_id'],
                    ],
                ],
            ],
            MealsDB_Tables::PRODUCTS => [
                'table'   => MealsDB_Tables::PRODUCTS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'             => 'INT AUTO_INCREMENT',
                    'wc_product_id'  => 'INT NOT NULL',
                    'product_name'   => "VARCHAR(200) NOT NULL DEFAULT ''",
                    'price'          => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'image_url'      => 'VARCHAR(500) NULL',
                    'sku'            => 'VARCHAR(100) NULL',
                    'category_data'  => 'JSON NULL',
                    'is_published'   => 'TINYINT(1) NOT NULL DEFAULT 1',
                    'product_type'   => "ENUM('meal','side','fee','other') NOT NULL DEFAULT 'meal'",
                    'taxable'        => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'main_ingredient'=> "VARCHAR(40) NOT NULL DEFAULT ''",
                    'dietary_tags'   => 'JSON NULL',
                    'allergen_flags' => 'JSON NULL',
                    'case_size'      => 'INT NOT NULL DEFAULT 1',
                    'buffer'         => 'INT NOT NULL DEFAULT 0',
                    'unit_cost'      => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'last_updated'   => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
                'indexes'     => [
                    [
                        'name'    => 'wc_product_id',
                        'type'    => 'UNIQUE',
                        'columns' => ['wc_product_id'],
                    ],
                    [
                        'name'    => 'idx_is_published',
                        'type'    => 'INDEX',
                        'columns' => ['is_published'],
                    ],
                    [
                        'name'    => 'idx_product_name',
                        'type'    => 'INDEX',
                        'columns' => ['product_name'],
                    ],
                ],
            ],
            MealsDB_Tables::CLIENT_RATES => [
                'table'   => MealsDB_Tables::CLIENT_RATES,
                'engine'  => 'InnoDB',
                'columns' => [
                    'rate_id'        => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'client_id'      => 'BIGINT UNSIGNED NOT NULL',
                    'label'          => 'VARCHAR(100) NOT NULL',
                    'rate'           => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'is_default'     => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'effective_date' => 'DATE NULL',
                    'created_at'     => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['rate_id'],
                'indexes'     => [
                    [
                        'name'    => 'idx_client_id',
                        'type'    => 'INDEX',
                        'columns' => ['client_id'],
                    ],
                    [
                        'name'    => 'idx_is_default',
                        'type'    => 'INDEX',
                        'columns' => ['client_id', 'is_default'],
                    ],
                ],
                'foreign_keys' => [
                    [
                        'name'               => 'fk_client_rates_client',
                        'columns'            => ['client_id'],
                        'referenced_table'   => MealsDB_Tables::CLIENTS,
                        'referenced_columns' => ['client_id'],
                        'on_delete'          => 'CASCADE',
                    ],
                ],
            ],
            MealsDB_Tables::STAFF => [
                'table'   => MealsDB_Tables::STAFF,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'                => 'INT UNSIGNED AUTO_INCREMENT',
                    'wordpress_user_id' => 'BIGINT UNSIGNED NULL',
                    'first_name'        => 'VARCHAR(191) NOT NULL',
                    'last_name'         => 'VARCHAR(191) NOT NULL',
                    'email'             => 'VARCHAR(191) NOT NULL',
                    'phone'             => 'VARCHAR(50) NULL',
                    'created_at'        => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
                    'updated_at'        => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
            ],
            MealsDB_Tables::DRAFTS => [
                'table'   => MealsDB_Tables::DRAFTS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'         => 'INT UNSIGNED AUTO_INCREMENT',
                    'data'       => 'LONGTEXT NOT NULL',
                    'created_by' => 'BIGINT UNSIGNED NULL',
                    'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
                'indexes'     => [
                    [
                        'name'    => 'idx_created_by',
                        'type'    => 'INDEX',
                        'columns' => ['created_by'],
                    ],
                ],
            ],
            MealsDB_Tables::AUDIT_LOG => [
                'table'   => MealsDB_Tables::AUDIT_LOG,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'            => 'INT UNSIGNED AUTO_INCREMENT',
                    'user_id'       => 'BIGINT UNSIGNED NULL',
                    'action'        => 'VARCHAR(100) NOT NULL',
                    'target_id'     => 'BIGINT UNSIGNED NULL',
                    'field_changed' => 'VARCHAR(191) NULL',
                    'old_value'     => 'TEXT NULL',
                    'new_value'     => 'TEXT NULL',
                    'source'        => "VARCHAR(100) NOT NULL DEFAULT 'mealsdb'",
                    'created_at'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
                'indexes'     => [
                    [
                        'name'    => 'idx_user_id',
                        'type'    => 'INDEX',
                        'columns' => ['user_id'],
                    ],
                    [
                        'name'    => 'idx_target_id',
                        'type'    => 'INDEX',
                        'columns' => ['target_id'],
                    ],
                ],
            ],
            MealsDB_Tables::IGNORED_CONFLICTS => [
                'table'   => MealsDB_Tables::IGNORED_CONFLICTS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'           => 'INT UNSIGNED AUTO_INCREMENT',
                    'field_name'   => 'VARCHAR(191) NOT NULL',
                    'source_value' => 'TEXT NULL',
                    'target_value' => 'TEXT NULL',
                    'ignored_by'   => 'BIGINT UNSIGNED NULL',
                    'created_at'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
                'indexes'     => [
                    [
                        'name'    => 'idx_field_name',
                        'type'    => 'INDEX',
                        'columns' => ['field_name'],
                    ],
                    [
                        'name'    => 'idx_ignored_by',
                        'type'    => 'INDEX',
                        'columns' => ['ignored_by'],
                    ],
                ],
            ],
            MealsDB_Tables::CLIENT_ALLOCATIONS => [
                'table'   => MealsDB_Tables::CLIENT_ALLOCATIONS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'                => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'client_id'         => 'BIGINT UNSIGNED NOT NULL',
                    'billing_month'     => 'CHAR(7) NOT NULL',
                    'permitted_mains'   => 'INT NOT NULL DEFAULT 0',
                    'permitted_sides'   => 'INT NOT NULL DEFAULT 0',
                    'used_mains'        => 'INT NOT NULL DEFAULT 0',
                    'used_sides'        => 'INT NOT NULL DEFAULT 0',
                    'used_tax_sides'    => 'INT NOT NULL DEFAULT 0',
                    'used_nontax_sides' => 'INT NOT NULL DEFAULT 0',
                    'overage_mains'     => 'INT NOT NULL DEFAULT 0',
                    'overage_sides'     => 'INT NOT NULL DEFAULT 0',
                    'is_finalized'      => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'contribution_applied'  => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'contribution_order_id' => 'BIGINT UNSIGNED NULL',
                    'finalized_at'      => 'DATETIME NULL',
                    'created_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'updated_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
                'indexes' => [
                    [
                        'name'    => 'idx_client_month',
                        'type'    => 'UNIQUE',
                        'columns' => ['client_id', 'billing_month'],
                    ],
                    [
                        'name'    => 'idx_billing_month',
                        'type'    => 'INDEX',
                        'columns' => ['billing_month'],
                    ],
                ],
            ],
            MealsDB_Tables::DELIVERY_ALLOCATIONS => [
                'table'   => MealsDB_Tables::DELIVERY_ALLOCATIONS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'                => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'client_id'         => 'BIGINT UNSIGNED NOT NULL',
                    'wc_order_id'       => 'BIGINT UNSIGNED NOT NULL',
                    'order_date'        => 'DATE NOT NULL',
                    'delivery_date'     => 'DATE NOT NULL',
                    'billing_month'     => 'CHAR(7) NOT NULL',
                    'mains_count'       => 'INT NOT NULL DEFAULT 0',
                    'sides_count'       => 'INT NOT NULL DEFAULT 0',
                    'tax_sides_count'   => 'INT NOT NULL DEFAULT 0',
                    'nontax_sides_count'=> 'INT NOT NULL DEFAULT 0',
                    'coverage_start'    => 'DATE NOT NULL',
                    'coverage_end'      => 'DATE NOT NULL',
                    'created_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'updated_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
                'indexes' => [
                    [
                        'name'    => 'idx_client_billing',
                        'type'    => 'INDEX',
                        'columns' => ['client_id', 'billing_month'],
                    ],
                    [
                        'name'    => 'idx_order',
                        'type'    => 'INDEX',
                        'columns' => ['wc_order_id'],
                    ],
                    [
                        'name'    => 'idx_delivery_date',
                        'type'    => 'INDEX',
                        'columns' => ['delivery_date'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Retrieve the schema definition for a specific table key.
     */
    public static function get_table_schema(string $table): ?array {
        $schemas = self::get_canonical_schema();

        return $schemas[$table] ?? null;
    }

    /**
     * Generate a CREATE TABLE statement for a canonical schema.
     *
     * Foreign key definitions are treated as metadata and are excluded by default to keep
     * schema sync additive-only. Pass $include_foreign_keys=true only for routines that
     * explicitly manage constraint creation in a separate, safe pass.
     */
    public static function generate_create_table_sql($conn, array $schema, bool $include_foreign_keys = false): string {
        $table_name = MealsDB_DB::get_table_name($schema['table']);
        $table_name = str_replace('`', '``', $table_name);

        $parts = [];
        foreach ($schema['columns'] as $name => $definition) {
            $parts[] = sprintf('`%s` %s', $name, $definition);
        }

        if (!empty($schema['primary_key'])) {
            $primary_keys = array_map(static function ($column) {
                return str_replace('`', '``', (string) $column);
            }, (array) $schema['primary_key']);

            $parts[] = sprintf('PRIMARY KEY (`%s`)', implode('`,`', $primary_keys));
        }

        if (!empty($schema['indexes']) && is_array($schema['indexes'])) {
            foreach ($schema['indexes'] as $index) {
                $parts[] = self::build_index_definition($index);
            }
        }

        if ($include_foreign_keys && !empty($schema['foreign_keys']) && is_array($schema['foreign_keys'])) {
            foreach ($schema['foreign_keys'] as $foreign_key) {
                $parts[] = self::build_foreign_key_definition($conn, $foreign_key);
            }
        }

        $charset_sql = self::build_charset_collation_sql($conn);
        $engine      = $schema['engine'] ?? 'InnoDB';

        return sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (%s) ENGINE=%s %s;',
            $table_name,
            implode(',', $parts),
            $engine,
            $charset_sql
        );
    }

    /**
     * Fetch the primary key columns for a canonical table, if defined.
     *
     * @return string[]
     */
    public static function get_primary_key_columns(string $table): array {
        $schemas = self::get_canonical_schema();

        if (!isset($schemas[$table])) {
            return [];
        }

        $primary_keys = $schemas[$table]['primary_key'] ?? [];

        if (empty($primary_keys)) {
            return [];
        }

        return array_map(static function ($column): string {
            return (string) $column;
        }, (array) $primary_keys);
    }

    /**
     * Retrieve the singular primary key column for a canonical table.
     *
     * Returns null for tables without a defined primary key or with a composite key.
     */
    public static function get_primary_key_column(string $table): ?string {
        $primary_keys = self::get_primary_key_columns($table);

        if (count($primary_keys) !== 1) {
            return null;
        }

        return $primary_keys[0];
    }

    /**
     * Build consistent charset/collation SQL using the active connection.
     */
    public static function build_charset_collation_sql($conn = null): string {
        global $wpdb;

        $charset = $wpdb->charset ?: 'utf8mb4';
        $collate = $wpdb->collate ?: 'utf8mb4_unicode_ci';

        return sprintf('DEFAULT CHARSET=%s COLLATE=%s', $charset, $collate);
    }

    /**
     * Build an index definition snippet.
     */
    public static function build_index_definition(array $index): string {
        $type = strtoupper($index['type'] ?? 'INDEX');
        $name = $index['name'] ?? '';
        $cols = array_map(static function ($col) {
            return sprintf('`%s`', $col);
        }, $index['columns'] ?? []);

        $type_sql = $type === 'UNIQUE' ? 'UNIQUE KEY' : 'KEY';

        return sprintf('%s `%s` (%s)', $type_sql, $name, implode(',', $cols));
    }

    /**
     * Build a foreign key definition for CREATE TABLE statements.
     */
    private static function build_foreign_key_definition($conn, array $foreign_key): string {
        global $wpdb;

        $name       = $foreign_key['name'] ?? '';
        $columns    = array_map(static function ($column) {
            return sprintf('`%s`', $column);
        }, $foreign_key['columns'] ?? []);
        $ref_table  = MealsDB_DB::get_table_name($foreign_key['referenced_table'] ?? '');
        $ref_table  = str_replace('`', '``', $ref_table);
        $ref_cols   = array_map(static function ($column) {
            return sprintf('`%s`', $column);
        }, $foreign_key['referenced_columns'] ?? []);
        $on_delete  = !empty($foreign_key['on_delete']) ? ' ON DELETE ' . strtoupper((string) $foreign_key['on_delete']) : '';

        return sprintf(
            'CONSTRAINT `%s` FOREIGN KEY (%s) REFERENCES `%s`(%s)%s',
            // _real_escape() escapes string literals, not identifiers; a
            // backtick in the constraint name would break the DDL. Escape
            // backticks the identifier-correct way.
            str_replace('`', '``', $name),
            implode(',', $columns),
            $ref_table,
            implode(',', $ref_cols),
            $on_delete
        );
    }
}
