<?php
/**
 * Synchronize external Meals DB schemas with installer definitions.
 */

class MealsDB_Schema_Sync {

    /**
     * Run the full schema sync for all supported tables.
     *
     * @return array<string, mixed>|WP_Error Summary of sync actions or WP_Error on connection failure.
     */
    public static function run_full_sync() {
        $conn = MealsDB_DB::get_connection();
        if (!$conn instanceof mysqli) {
            return new WP_Error('db_error', 'Unable to connect to external Meals DB.');
        }

        $schemas = MealsDB_Schema::get_canonical_schema();
        $results = [
            'tables_created'    => [],
            'columns_added'     => [],
            'column_mismatches' => [],
            'errors'            => [],
        ];

        foreach ($schemas as $schema) {
            $table_name = MealsDB_DB::get_table_name($schema['table']);
            $escaped_table = str_replace('`', '``', $table_name);

            try {
                $exists = self::table_exists($conn, $table_name);
            } catch (Throwable $exception) {
                $results['errors'][] = [
                    'table'  => $table_name,
                    'column' => null,
                    'error'  => $exception->getMessage(),
                ];
                continue;
            }

            if (!$exists) {
                $create_sql = MealsDB_Schema::generate_create_table_sql($conn, $schema);
                if ($conn->query($create_sql) !== false) {
                    $results['tables_created'][] = $table_name;
                } else {
                    $results['errors'][] = [
                        'table'  => $table_name,
                        'column' => null,
                        'error'  => $conn->error,
                    ];
                    // Table creation failed; continue to next table without column checks
                    continue;
                }
            }

            $existing_columns = [];
            try {
                $existing_columns = self::fetch_existing_columns($conn, $table_name);
            } catch (Throwable $exception) {
                $results['errors'][] = [
                    'table'  => $table_name,
                    'column' => null,
                    'error'  => $exception->getMessage(),
                ];
                continue;
            }

            foreach ($schema['columns'] as $column => $definition) {
                if (!isset($existing_columns[$column])) {
                    $alter_sql = sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $escaped_table, $column, $definition);

                    try {
                        if ($conn->query($alter_sql) !== false) {
                            $results['columns_added'][] = [
                                'table'  => $table_name,
                                'column' => $column,
                            ];
                        } else {
                            $results['errors'][] = [
                                'table'  => $table_name,
                                'column' => $column,
                                'error'  => $conn->error,
                            ];
                        }
                    } catch (Throwable $exception) {
                        $results['errors'][] = [
                            'table'  => $table_name,
                            'column' => $column,
                            'error'  => $exception->getMessage(),
                        ];
                    }

                    continue;
                }

                if (!self::column_matches_definition($definition, $existing_columns[$column])) {
                    $results['column_mismatches'][] = [
                        'table'    => $table_name,
                        'column'   => $column,
                        'expected' => $definition,
                        'actual'   => $existing_columns[$column],
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Determine if the target table exists.
     */
    private static function table_exists(mysqli $conn, string $table): bool {
        $safe_table = $conn->real_escape_string($table);
        $sql        = "SHOW TABLES LIKE '{$safe_table}'";

        $result = $conn->query($sql);
        if ($result === false) {
            throw new RuntimeException($conn->error);
        }

        return $result && $result->num_rows > 0;
    }

    /**
     * Fetch column metadata for a table using INFORMATION_SCHEMA.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fetch_existing_columns(mysqli $conn, string $table): array {
        $safe_table = $conn->real_escape_string($table);
        $sql        = "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$safe_table}'";

        $result = $conn->query($sql);
        if ($result === false) {
            throw new RuntimeException($conn->error);
        }

        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[$row['COLUMN_NAME']] = [
                'column_type'   => $row['COLUMN_TYPE'] ?? '',
                'is_nullable'   => $row['IS_NULLABLE'] ?? '',
                'column_default'=> $row['COLUMN_DEFAULT'] ?? null,
                'extra'         => $row['EXTRA'] ?? '',
            ];
        }

        return $columns;
    }

    /**
     * Compare a live column to the canonical definition.
     */
    private static function column_matches_definition(string $expected_definition, array $actual_column): bool {
        $expected = self::normalize_expected_definition($expected_definition);
        $actual   = [
            'type'           => self::normalize_column_type((string) ($actual_column['column_type'] ?? '')),
            'nullable'       => strtoupper((string) ($actual_column['is_nullable'] ?? '')) === 'YES',
            'default'        => self::normalize_default_value($actual_column['column_default'] ?? null),
            'auto_increment' => stripos((string) ($actual_column['extra'] ?? ''), 'auto_increment') !== false,
        ];

        return $expected['type'] === $actual['type']
            && $expected['nullable'] === $actual['nullable']
            && $expected['default'] === $actual['default']
            && $expected['auto_increment'] === $actual['auto_increment'];
    }

    /**
     * Normalize the expected column definition into comparable parts.
     *
     * @return array<string, mixed>
     */
    private static function normalize_expected_definition(string $definition): array {
        $trimmed = trim($definition);
        $lower   = strtolower($trimmed);

        $keywords     = [' not null', ' null', ' default', ' auto_increment', ' on update', ' unique', ' primary', ' comment'];
        $cut_position = strlen($trimmed);
        foreach ($keywords as $keyword) {
            $pos = stripos($lower, $keyword);
            if ($pos !== false && $pos < $cut_position) {
                $cut_position = $pos;
            }
        }

        $type           = self::normalize_column_type(substr($trimmed, 0, $cut_position));
        $nullable       = stripos($lower, 'not null') === false;
        $auto_increment = stripos($lower, 'auto_increment') !== false;
        $default        = null;

        $default_position = stripos($lower, 'default');
        if ($default_position !== false) {
            $default_value = trim(substr($trimmed, $default_position + strlen('default')));
            $space_pos     = strpos($default_value, ' ');
            if ($space_pos !== false) {
                $default_value = substr($default_value, 0, $space_pos);
            }
            $default = self::normalize_default_value($default_value);
        }

        return [
            'type'           => $type,
            'nullable'       => $nullable,
            'default'        => $default,
            'auto_increment' => $auto_increment,
        ];
    }

    /**
     * Normalize a column type string for comparison.
     */
    private static function normalize_column_type(string $type): string {
        $normalized = strtolower(trim(preg_replace('/\\s+/', ' ', $type)));
        $normalized = preg_replace('/bigint\\(\\d+\\)/', 'bigint', $normalized);
        $normalized = preg_replace('/int\\(\\d+\\)/', 'int', $normalized);
        $normalized = preg_replace('/tinyint\\(\\d+\\)/', 'tinyint', $normalized);

        return $normalized ?? '';
    }

    /**
     * Normalize default values for comparison, stripping quotes and uppercasing known functions.
     */
    private static function normalize_default_value($value): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value, "'\"");
        if (strcasecmp($value, 'null') === 0) {
            return null;
        }

        $upper = strtoupper($value);
        if ($upper === 'CURRENT_TIMESTAMP' || $upper === 'CURRENT_TIMESTAMP()') {
            return 'CURRENT_TIMESTAMP';
        }

        return $value;
    }
}
