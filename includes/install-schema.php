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
        if (!MealsDB_Config::is_db_configured()) {
            error_log('[MealsDB Installer] External DB credentials are not configured. Set MEALSDB_* env vars or define the constants before activating the plugin.');
            return;
        }

        $conn = MealsDB_DB::get_connection();

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
        // Migration: service_name_course → meal_type completed in v1.x
        self::migrate_rate_to_client_rates($conn);
        self::drop_defunct_transaction_tables($conn);
    }

    /**
     * Seed meals_client_rates from existing meals_clients.rate column.
     *
     * On first run: copies each client's flat rate into the new rates table as a
     * "Standard" default rate, back-fills default_rate_id on meals_clients, then
     * drops the now-redundant rate column.
     */
    private static function migrate_rate_to_client_rates(mysqli $conn): void {
        $rates_table   = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES);
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $escaped_rates   = str_replace('`', '``', $rates_table);
        $escaped_clients = str_replace('`', '``', $clients_table);

        // Check if meals_client_rates table is empty
        $count_result = $conn->query(sprintf("SELECT COUNT(*) AS cnt FROM `%s`", $escaped_rates));
        if (!$count_result) {
            error_log(sprintf('[MealsDB Installer] Could not check meals_client_rates count: %s', $conn->error));
            return;
        }
        $count_row = $count_result->fetch_assoc();
        if ((int) ($count_row['cnt'] ?? 0) > 0) {
            // Already seeded — nothing to do
            return;
        }

        // Check if meals_clients.rate column still exists
        $safe_clients = $conn->real_escape_string($clients_table);
        $col_check = $conn->query(sprintf(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND COLUMN_NAME = 'rate'",
            $safe_clients
        ));
        if (!$col_check || $col_check->num_rows === 0) {
            // rate column already removed — migration previously completed
            return;
        }

        // Seed rates from existing flat rate values
        $insert_sql = sprintf(
            "INSERT INTO `%s` (client_id, label, rate, is_default, created_at) SELECT client_id, 'Standard', rate, 1, NOW() FROM `%s` WHERE rate > 0",
            $escaped_rates,
            $escaped_clients
        );
        if (!$conn->query($insert_sql)) {
            error_log(sprintf('[MealsDB Installer] Failed to seed meals_client_rates: %s', $conn->error));
            return;
        }
        $seeded = $conn->affected_rows;
        error_log(sprintf('[MealsDB Installer] Seeded %d rows into meals_client_rates', $seeded));

        // Back-fill default_rate_id on meals_clients
        $backfill_sql = sprintf(
            "UPDATE `%s` c INNER JOIN `%s` r ON r.client_id = c.client_id AND r.is_default = 1 SET c.default_rate_id = r.rate_id",
            $escaped_clients,
            $escaped_rates
        );
        if (!$conn->query($backfill_sql)) {
            error_log(sprintf('[MealsDB Installer] Failed to back-fill default_rate_id: %s', $conn->error));
            return;
        }
        error_log(sprintf('[MealsDB Installer] Back-filled default_rate_id for %d clients', $conn->affected_rows));

        // Drop the now-redundant rate column
        $drop_sql = sprintf("ALTER TABLE `%s` DROP COLUMN rate", $escaped_clients);
        if (!$conn->query($drop_sql)) {
            error_log(sprintf('[MealsDB Installer] Failed to drop meals_clients.rate column: %s', $conn->error));
        } else {
            error_log('[MealsDB Installer] Dropped meals_clients.rate column');
        }
    }

    /**
     * Drop defunct transaction tables that are no longer part of the schema.
     *
     * Order data now lives exclusively in WooCommerce HPOS tables.
     */
    private static function drop_defunct_transaction_tables(mysqli $conn): void {
        $tables_to_drop = [
            'meals_transaction_items',
            'meals_transactions',
        ];

        foreach ($tables_to_drop as $base_name) {
            $table_name = MealsDB_DB::get_table_name($base_name);
            $escaped    = str_replace('`', '``', $table_name);
            $sql        = sprintf("DROP TABLE IF EXISTS `%s`", $escaped);

            if (!$conn->query($sql)) {
                error_log(sprintf('[MealsDB Installer] Failed to drop defunct table %s: %s', $table_name, $conn->error));
            } else {
                error_log(sprintf('[MealsDB Installer] Dropped defunct table: %s', $table_name));
            }
        }
    }
}
