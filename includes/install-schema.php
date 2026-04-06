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
     * Creates the Meals DB tables in the WordPress database using $wpdb.
     */
    public static function install(): void {
        global $wpdb;

        $schemas = MealsDB_Schema::get_canonical_schema();
        foreach ($schemas as $schema) {
            $sql = MealsDB_Schema::generate_create_table_sql($wpdb, $schema, false);

            $wpdb->query($sql);
            if ($wpdb->last_error) {
                error_log(sprintf('[MealsDB Installer] Failed creating %s: %s', $schema['table'], $wpdb->last_error));
            }
        }

        $sync_result = MealsDB_Schema_Sync::run_full_sync();
        if (is_wp_error($sync_result)) {
            error_log('[MealsDB Installer] Schema sync failed: ' . $sync_result->get_error_message());
        }

        // Run one-time migrations
        self::migrate_rate_to_client_rates();
        self::drop_defunct_transaction_tables();
    }

    /**
     * Seed meals_client_rates from existing meals_clients.rate column.
     */
    private static function migrate_rate_to_client_rates(): void {
        global $wpdb;

        $rates_table   = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES);
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // Check if meals_client_rates table is empty
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$rates_table}`");
        if ($count > 0) {
            return;
        }

        // Check if meals_clients.rate column still exists
        $col_check = $wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'rate'",
            $clients_table
        ));
        if (!$col_check) {
            return;
        }

        // Seed rates from existing flat rate values
        $insert_sql = "INSERT INTO `{$rates_table}` (client_id, label, rate, is_default, created_at) SELECT client_id, 'Standard', rate, 1, NOW() FROM `{$clients_table}` WHERE rate > 0";
        $wpdb->query($insert_sql);
        $seeded = $wpdb->rows_affected;
        error_log(sprintf('[MealsDB Installer] Seeded %d rows into meals_client_rates', $seeded));

        // Back-fill default_rate_id on meals_clients
        $backfill_sql = "UPDATE `{$clients_table}` c INNER JOIN `{$rates_table}` r ON r.client_id = c.client_id AND r.is_default = 1 SET c.default_rate_id = r.rate_id";
        $wpdb->query($backfill_sql);
        error_log(sprintf('[MealsDB Installer] Back-filled default_rate_id for %d clients', $wpdb->rows_affected));

        // Drop the now-redundant rate column
        $drop_sql = "ALTER TABLE `{$clients_table}` DROP COLUMN rate";
        if ($wpdb->query($drop_sql) === false) {
            error_log(sprintf('[MealsDB Installer] Failed to drop meals_clients.rate column: %s', $wpdb->last_error));
        } else {
            error_log('[MealsDB Installer] Dropped meals_clients.rate column');
        }
    }

    /**
     * Drop defunct transaction tables that are no longer part of the schema.
     */
    private static function drop_defunct_transaction_tables(): void {
        global $wpdb;

        $tables_to_drop = [
            'meals_transaction_items',
            'meals_transactions',
        ];

        $prefix = $wpdb->prefix;

        foreach ($tables_to_drop as $base_name) {
            $table_name = $prefix . $base_name;
            $escaped    = str_replace('`', '``', $table_name);
            $sql        = sprintf("DROP TABLE IF EXISTS `%s`", $escaped);

            if ($wpdb->query($sql) === false) {
                error_log(sprintf('[MealsDB Installer] Failed to drop defunct table %s: %s', $table_name, $wpdb->last_error));
            } else {
                error_log(sprintf('[MealsDB Installer] Dropped defunct table: %s', $table_name));
            }
        }
    }
}
