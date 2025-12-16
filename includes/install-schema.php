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

        if (!MealsDB_DB::is_mysqli($conn)) {
            error_log('[MealsDB Installer] Unable to establish database connection.');
            return;
        }

        $schemas = MealsDB_Schema::get_canonical_schema();
        foreach ($schemas as $schema) {
            $sql = MealsDB_Schema::generate_create_table_sql($conn, $schema, false);

            if (!$conn->query($sql)) {
                error_log(sprintf('[MealsDB Installer] Failed creating %s: %s', $schema['table'], $conn->error));
            }
        }

        $sync_result = MealsDB_Schema_Sync::run_full_sync();
        if (is_wp_error($sync_result)) {
            error_log('[MealsDB Installer] Schema sync failed: ' . $sync_result->get_error_message());
        }
    }
}
