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

        // Run one-time migrations
        self::migrate_service_name_course_to_meal_type($conn);
    }

    /**
     * Migrate data from service_name_course to meal_type and remove the old column.
     * This is a one-time migration to consolidate duplicate fields.
     */
    private static function migrate_service_name_course_to_meal_type(mysqli $conn): void {
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $escaped_table = str_replace('`', '``', $table);

        // Check if service_name_course column still exists
        $check_sql = sprintf(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND COLUMN_NAME = 'service_name_course'",
            $conn->real_escape_string($table)
        );

        $result = $conn->query($check_sql);
        if (!$result || $result->num_rows === 0) {
            // Column already removed, migration complete
            return;
        }

        // Copy data from service_name_course to meal_type where meal_type is NULL or empty
        $update_sql = sprintf(
            "UPDATE `%s` SET meal_type = service_name_course WHERE (meal_type IS NULL OR meal_type = '') AND service_name_course IS NOT NULL AND service_name_course != ''",
            $escaped_table
        );

        if (!$conn->query($update_sql)) {
            error_log(sprintf('[MealsDB Installer] Failed to migrate service_name_course data: %s', $conn->error));
            return;
        }

        $rows_updated = $conn->affected_rows;
        if ($rows_updated > 0) {
            error_log(sprintf('[MealsDB Installer] Migrated %d rows from service_name_course to meal_type', $rows_updated));
        }

        // Drop the service_name_course column
        $drop_sql = sprintf("ALTER TABLE `%s` DROP COLUMN service_name_course", $escaped_table);

        if (!$conn->query($drop_sql)) {
            error_log(sprintf('[MealsDB Installer] Failed to drop service_name_course column: %s', $conn->error));
        } else {
            error_log('[MealsDB Installer] Successfully dropped service_name_course column');
        }
    }
}
