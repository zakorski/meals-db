<?php
/**
 * Force rebuild logic for the external Meals DB schema.
 */

defined('ABSPATH') || exit;

class MealsDB_Schema_Rebuild {

    /**
     * Execute a destructive rebuild of the external Meals DB tables.
     *
     * @param string $confirmation Raw confirmation string from the admin UI.
     *
     * @return array<string, mixed>|WP_Error Structured result data or WP_Error on precondition failure.
     */
    public static function run(string $confirmation) {
        // Defence-in-depth: drops every plugin table. Refuse outright if
        // the caller doesn't have manage_options, regardless of how the
        // helper was reached.
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'You do not have permission to rebuild the schema.');
        }

        $normalized_confirmation = strtoupper(trim($confirmation));
        if ($normalized_confirmation !== 'REBUILD') {
            return new WP_Error('confirmation_required', 'Force rebuild aborted: confirmation text did not match.');
        }

        global $wpdb;

        if (!$wpdb) {
            return new WP_Error('db_error', 'Unable to connect to external Meals DB.');
        }

        // Audit-log the destructive op so compromise / mistake is traceable.
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log(
                'force_rebuild',
                0,
                'schema',
                null,
                'requested by user_id=' . get_current_user_id()
            );
        }

        $schemas = MealsDB_Schema::get_canonical_schema();
        $create_order = self::determine_create_order($schemas);
        $drop_order   = array_reverse($create_order);

        $results = [
            'dropped'       => [],
            'drop_errors'   => [],
            'created'       => [],
            'create_errors' => [],
        ];

        foreach ($drop_order as $table_key) {
            $table_name = MealsDB_DB::get_table_name($table_key);
            $escaped_table = str_replace('`', '``', $table_name);
            $sql = sprintf('DROP TABLE IF EXISTS `%s`', $escaped_table);

            try {
                $query_result = $wpdb->query($sql);
                if ($query_result !== false) {
                    $results['dropped'][] = $table_name;
                } else {
                    $results['drop_errors'][] = [
                        'table' => $table_name,
                        'error' => $wpdb->last_error,
                    ];
                    // Bail on first drop error rather than continuing into
                    // a half-broken schema state. DDL is non-transactional
                    // in MySQL so we can't roll back what already dropped.
                    break;
                }
            } catch (Throwable $exception) {
                $results['drop_errors'][] = [
                    'table' => $table_name,
                    'error' => $exception->getMessage(),
                ];
                break;
            }
        }

        foreach ($create_order as $table_key) {
            if (!isset($schemas[$table_key])) {
                continue;
            }

            $table_name  = MealsDB_DB::get_table_name($table_key);
            $create_sql  = MealsDB_Schema::generate_create_table_sql($wpdb, $schemas[$table_key], false);

            try {
                $query_result = $wpdb->query($create_sql);
                if ($query_result !== false) {
                    $results['created'][] = $table_name;
                } else {
                    $results['create_errors'][] = [
                        'table' => $table_name,
                        'error' => $wpdb->last_error,
                    ];
                }
            } catch (Throwable $exception) {
                $results['create_errors'][] = [
                    'table' => $table_name,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Determine a safe creation order based on foreign key dependencies.
     *
     * @param array<string, array<string, mixed>> $schemas
     *
     * @return string[] Ordered list of table keys for creation.
     */
    private static function determine_create_order(array $schemas): array {
        $dependencies = [];

        foreach ($schemas as $table_key => $schema) {
            $references = [];

            if (!empty($schema['foreign_keys']) && is_array($schema['foreign_keys'])) {
                foreach ($schema['foreign_keys'] as $foreign_key) {
                    $referenced_table = $foreign_key['referenced_table'] ?? '';
                    if (isset($schemas[$referenced_table])) {
                        $references[] = $referenced_table;
                    }
                }
            }

            $dependencies[$table_key] = array_values(array_unique($references));
        }

        $ordered   = [];
        $remaining = $dependencies;

        while (!empty($remaining)) {
            $progress_made = false;

            foreach ($remaining as $table_key => $requires) {
                $unmet_dependencies = array_diff($requires, $ordered);
                if (!empty($unmet_dependencies)) {
                    continue;
                }

                $ordered[] = $table_key;
                unset($remaining[$table_key]);
                $progress_made = true;
            }

            if (!$progress_made) {
                // Fallback: append remaining tables in their current order to avoid infinite loop.
                $ordered = array_merge($ordered, array_keys($remaining));
                break;
            }
        }

        return $ordered;
    }
}
