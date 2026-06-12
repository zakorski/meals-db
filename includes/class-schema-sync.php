<?php
/**
 * Synchronize Meals DB schemas with installer definitions.
 */

defined('ABSPATH') || exit;

class MealsDB_Schema_Sync {

    /**
     * Run the full schema sync for all supported tables.
     *
     * @return array<string, mixed>|WP_Error Summary of sync actions or WP_Error on connection failure.
     */
    public static function run_full_sync() {
        // Service-layer capability re-check (defense in depth). This runs schema
        // DDL (CREATE TABLE / ALTER TABLE ADD COLUMN) and was reachable from the
        // Data Ops page at baseline capability — schema ops are manage_options
        // (mirrors MealsDB_Schema_Rebuild::run). A future non-view caller must
        // not reach the DDL without it.
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'You do not have permission to update the schema.');
        }

        global $wpdb;

        if (!$wpdb) {
            return new WP_Error('db_error', 'Unable to connect to the Meals DB database.');
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
                $exists = self::table_exists($wpdb, $table_name);
            } catch (Throwable $exception) {
                $results['errors'][] = [
                    'table'  => $table_name,
                    'column' => null,
                    'error'  => $exception->getMessage(),
                ];
                continue;
            }

            if (!$exists) {
                $create_sql = MealsDB_Schema::generate_create_table_sql($schema);
                $query_result = $wpdb->query($create_sql);
                if ($query_result !== false) {
                    $results['tables_created'][] = $table_name;
                } else {
                    $results['errors'][] = [
                        'table'  => $table_name,
                        'column' => null,
                        'error'  => $wpdb->last_error,
                    ];
                    // Table creation failed; continue to next table without column checks
                    continue;
                }
            }

            $existing_columns = [];
            try {
                $existing_columns = self::fetch_existing_columns($wpdb, $table_name);
            } catch (Throwable $exception) {
                $results['errors'][] = [
                    'table'  => $table_name,
                    'column' => null,
                    'error'  => $exception->getMessage(),
                ];
                continue;
            }

            $expected_primary = array_values((array) ($schema['primary_key'] ?? []));
            try {
                $actual_primary = self::fetch_primary_key_columns($wpdb, $table_name);
                if ($expected_primary !== $actual_primary) {
                    $results['column_mismatches'][] = [
                        'table'    => $table_name,
                        'column'   => 'PRIMARY KEY',
                        'expected' => implode(',', $expected_primary),
                        'actual'   => implode(',', $actual_primary),
                    ];
                }
            } catch (Throwable $exception) {
                $results['errors'][] = [
                    'table'  => $table_name,
                    'column' => 'PRIMARY KEY',
                    'error'  => $exception->getMessage(),
                ];
            }

            foreach ($schema['columns'] as $column => $definition) {
                $clean_definition  = self::sanitize_column_definition($definition);
                $needs_unique_index = self::definition_has_unique($definition);

                if (!isset($existing_columns[$column])) {
                    // Combine ADD COLUMN and its UNIQUE index (when the
                    // canonical definition declares one) into a single
                    // ALTER TABLE. Previously this was two statements,
                    // which meant two metadata round-trips and two
                    // separate DDL commits; InnoDB re-copies the table
                    // for each. One combined ALTER is a single copy.
                    $alter_parts = [sprintf('ADD COLUMN `%s` %s', $column, $clean_definition)];
                    if ($needs_unique_index) {
                        $index_name = sprintf('uniq_%s_%s', preg_replace('/[^a-z0-9_]/i', '_', $table_name), $column);
                        $alter_parts[] = sprintf(
                            'ADD UNIQUE KEY `%s` (`%s`)',
                            str_replace('`', '``', $index_name),
                            $column
                        );
                    }
                    $alter_sql = sprintf('ALTER TABLE `%s` %s', $escaped_table, implode(', ', $alter_parts));

                    try {
                        $query_result = $wpdb->query($alter_sql);
                        if ($query_result !== false) {
                            $results['columns_added'][] = [
                                'table'  => $table_name,
                                'column' => $column,
                            ];
                        } else {
                            $results['errors'][] = [
                                'table'  => $table_name,
                                'column' => $column,
                                'error'  => $wpdb->last_error,
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

                if (!self::column_matches_definition($clean_definition, $existing_columns[$column])) {
                    $results['column_mismatches'][] = [
                        'table'    => $table_name,
                        'column'   => $column,
                        'expected' => $clean_definition,
                        'actual'   => $existing_columns[$column],
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Determine if the target table exists.
     *
     * Per-request cache backed by a single SHOW TABLES query rather
     * than one SHOW TABLES LIKE %s per call. run_full_sync() iterates
     * every canonical table (currently 7+) and previously issued one
     * round-trip per check; on a schema upgrade that fires from
     * admin_init, the per-query latency dominated. One up-front list
     * walks the same metadata in a single query.
     *
     * The cache is keyed on the wpdb's host/dbname identity (so two
     * connections to different databases don't share state) and lazily
     * invalidates if SHOW TABLES errors — the next call retries
     * cleanly without poisoning the cache with a half-built set.
     */
    private static function table_exists($wpdb, string $table): bool {
        static $caches = [];

        $cache_key = (string) ($wpdb->dbhost ?? '') . '|' . (string) ($wpdb->dbname ?? '');

        if (!isset($caches[$cache_key])) {
            $rows = $wpdb->get_col('SHOW TABLES');
            if ($rows === null && $wpdb->last_error) {
                throw new RuntimeException($wpdb->last_error);
            }
            $set = [];
            if (is_array($rows)) {
                foreach ($rows as $name) {
                    $set[(string) $name] = true;
                }
            }
            $caches[$cache_key] = $set;
        }

        return isset($caches[$cache_key][$table]);
    }

    /**
     * Fetch column metadata for a table using INFORMATION_SCHEMA.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fetch_existing_columns($wpdb, string $table): array {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table
            ),
            ARRAY_A
        );

        if ($rows === null && $wpdb->last_error) {
            throw new RuntimeException($wpdb->last_error);
        }

        $columns = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $columns[$row['COLUMN_NAME']] = [
                    'column_type'   => $row['COLUMN_TYPE'] ?? '',
                    'is_nullable'   => $row['IS_NULLABLE'] ?? '',
                    'column_default'=> $row['COLUMN_DEFAULT'] ?? null,
                    'extra'         => $row['EXTRA'] ?? '',
                ];
            }
        }

        return $columns;
    }

    /**
     * Fetch the ordered list of primary key columns for a table.
     *
     * @return array<int, string>
     */
    private static function fetch_primary_key_columns($wpdb, string $table): array {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION",
                $table
            ),
            ARRAY_A
        );

        if ($rows === null && $wpdb->last_error) {
            throw new RuntimeException($wpdb->last_error);
        }

        $columns = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!empty($row['COLUMN_NAME'])) {
                    $columns[] = (string) $row['COLUMN_NAME'];
                }
            }
        }

        return $columns;
    }

    /**
     * Compare a live column to the canonical definition.
     */
    private static function column_matches_definition(string $expected_definition, array $actual_column): bool {
        $expected_definition = self::sanitize_column_definition($expected_definition);
        $expected            = self::normalize_expected_definition($expected_definition);
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
     * Detect whether a canonical column definition declares an inline
     * UNIQUE constraint (so a follow-up ADD UNIQUE KEY is needed after
     * sanitize_column_definition strips the inline directive).
     */
    private static function definition_has_unique(string $definition): bool {
        return (bool) preg_match('/\\bunique(\\s+key)?\\b/i', $definition);
    }

    /**
     * Strip constraint directives from a column definition so ALTER TABLE statements remain column-only.
     */
    private static function sanitize_column_definition(string $definition): string {
        $cleaned = preg_replace('/\s+/', ' ', trim($definition));

        $patterns = [
            '/\s+primary\s+key\b/i',
            '/\s+unique\s+key\b/i',
            '/\s+unique\b/i',
            '/\s+foreign\s+key\b/i',
            '/\s+constraint\s+`?[^\s`]+`?/i',
            '/\s+references\s+`?[^\s`]+`?\s*\([^)]*\)/i',
        ];

        $cleaned = preg_replace($patterns, '', $cleaned);

        return trim(preg_replace('/\s+/', ' ', (string) $cleaned));
    }

    /**
     * Normalize the expected column definition into comparable parts.
     *
     * @return array<string, mixed>
     */
    private static function normalize_expected_definition(string $definition): array {
        $trimmed = trim($definition);
        $lower   = strtolower($trimmed);

        // ENUM('a','b','default','c') would otherwise be cut at the
        // " default " inside the parens. Substitute the parenthesised
        // contents with placeholders so the keyword scan can't misfire.
        $masked = preg_replace_callback('/\(([^)]*)\)/', static function ($m) {
            return '(' . str_repeat('_', strlen($m[1])) . ')';
        }, $trimmed) ?? $trimmed;
        $masked_lower = strtolower($masked);

        $keywords     = [' not null', ' null', ' default', ' auto_increment', ' on update', ' unique', ' primary', ' comment'];
        $cut_position = strlen($trimmed);
        foreach ($keywords as $keyword) {
            $pos = stripos($masked_lower, $keyword);
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
     *
     * MySQL stores BOOLEAN as tinyint(1); the canonical schema uses the
     * BOOLEAN spelling. Without this fold the sync forever reports the
     * boolean columns as mismatches and tries to "fix" them on every run.
     */
    private static function normalize_column_type(string $type): string {
        $normalized = strtolower(trim(preg_replace('/\\s+/', ' ', $type)));
        $normalized = preg_replace('/bigint\\(\\d+\\)/', 'bigint', $normalized);
        $normalized = preg_replace('/int\\(\\d+\\)/', 'int', $normalized);
        $normalized = preg_replace('/tinyint\\(\\d+\\)/', 'tinyint', $normalized);

        if ($normalized === 'boolean' || $normalized === 'bool') {
            $normalized = 'tinyint';
        }

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
