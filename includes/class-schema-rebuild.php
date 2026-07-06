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

        // Rate-limit this catastrophically destructive op so a
        // compromised admin cookie or a mis-scripted "retry" loop
        // can't execute it repeatedly in quick succession. The
        // rebuild itself is heavy enough that one-per-30-minutes
        // imposes no burden on legitimate use.
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('schema_rebuild')) {
            return new WP_Error(
                'rate_limited',
                'Force rebuild rate limit exceeded. Wait before retrying.'
            );
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
        $create_order = self::determine_create_order();

        // Preserve the append-only audit log across a force rebuild
        // (U11-schema-1). run() audit-logs THIS destructive op above so a
        // compromise or mistake is traceable — but dropping meals_audit_log
        // would destroy that very row AND the entire compliance history the
        // log exists to keep, letting a sanctioned UI path wipe all audit
        // trail. Excluding it from the drop order keeps the log intact; the
        // CREATE loop below is a no-op for it because
        // generate_create_table_sql emits CREATE TABLE IF NOT EXISTS, so the
        // surviving table is simply not recreated. Every OTHER plugin table
        // is still dropped and rebuilt.
        $drop_order = array_values(array_diff(
            array_reverse($create_order),
            [MealsDB_Tables::AUDIT_LOG]
        ));

        $results = [
            'dropped'       => [],
            'drop_errors'   => [],
            'created'       => [],
            'create_errors' => [],
            // Tables deliberately left in place (not dropped) so the operator
            // sees the audit log was preserved, not silently skipped.
            'preserved'     => [MealsDB_DB::get_table_name(MealsDB_Tables::AUDIT_LOG)],
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

        // U11-schema-6: if the drop loop bailed on an error, the schema is in a
        // partial state — the tables before the failure were dropped, the failing
        // one and every table after it were NOT. generate_create_table_sql emits
        // CREATE TABLE IF NOT EXISTS, so blindly recreating every table would
        // no-op on the never-dropped ones yet still list them under 'created',
        // telling the operator a full rebuild succeeded when it didn't. When a
        // drop failed, recreate ONLY the tables actually dropped so 'created'
        // mirrors 'dropped' (and untouched tables keep their existing data rather
        // than being reported as freshly created). No drop error → recreate all.
        $drop_failed    = !empty($results['drop_errors']);
        $dropped_lookup = $drop_failed ? array_flip($results['dropped']) : [];

        foreach ($create_order as $table_key) {
            if (!isset($schemas[$table_key])) {
                continue;
            }

            $table_name  = MealsDB_DB::get_table_name($table_key);

            if ($drop_failed && !isset($dropped_lookup[$table_name])) {
                continue;
            }

            $create_sql  = MealsDB_Schema::generate_create_table_sql($schemas[$table_key]);

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
     * Determine table creation order.
     *
     * HISTORY: A previous version processed `foreign_keys` metadata
     * via topological sort to ensure child tables created after
     * parents. The metadata was never used to actually create FK
     * constraints (see class-schema.php STRUCT-3 cleanup), so the
     * sort had no input and produced a no-op ordering that happened
     * to coincide with MealsDB_Tables::all(). After the metadata
     * removal this method returns the natural order directly.
     *
     * @return string[] Ordered list of table keys for creation.
     */
    private static function determine_create_order(): array {
        return MealsDB_Tables::all();
    }
}
