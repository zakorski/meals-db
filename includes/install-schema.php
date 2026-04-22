<?php
/**
 * Installer responsible for preparing the Meals DB schema.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

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

        // Seed the first task-engine rule so a freshly-installed site has
        // something to exercise the cron pass with. Safe to call on every
        // install — it no-ops when the seed rule already exists.
        self::seed_task_engine();
    }

    /**
     * Seed one sample task rule so the engine has something to run out of the box.
     */
    private static function seed_task_engine(): void {
        global $wpdb;

        $rules_table = MealsDB_DB::get_table_name(MealsDB_Tables::SCHEDULE_RULES);
        $seed_name = 'Weekly overdue task review';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT rule_id FROM `{$rules_table}` WHERE name = %s LIMIT 1",
            $seed_name
        ));
        if ($existing) {
            return;
        }

        if (!class_exists('MealsDB_Task_Rules') || !class_exists('MealsDB_Task_Type_Generic_Reminder')) {
            return;
        }

        $rules = new MealsDB_Task_Rules();
        $rules->create_rule([
            'name'             => $seed_name,
            'task_type'        => MealsDB_Task_Type_Generic_Reminder::TYPE_ID,
            'spawn_type'       => MealsDB_Task_Rules::SPAWN_FIXED,
            'recurrence'       => [
                'type'         => 'weekly',
                'interval'     => 1,
                'days_of_week' => ['monday'],
                'time'         => '08:00',
            ],
            'payload_template' => [
                'description' => 'Review overdue tasks for the week.',
            ],
            'assignee_role' => 'admin',
            'is_active'     => 1,
        ]);
    }

    /**
     * Seed meals_client_rates from existing meals_clients.rate column.
     *
     * Three-step migration:
     *   1. INSERT ... SELECT into meals_client_rates from the flat
     *      rate column on meals_clients.
     *   2. UPDATE meals_clients.default_rate_id from the row just
     *      inserted.
     *   3. DDL: ALTER TABLE ... DROP COLUMN rate.
     *
     * Steps 1 and 2 are DML and wrapped in a transaction. Step 3 is
     * DDL — MySQL implicitly commits before any DDL, so it cannot
     * participate in the transaction; the previous implementation
     * issued DROP COLUMN unconditionally, meaning a failed backfill
     * in step 2 would nonetheless lose the source data forever.
     *
     * The fix: if steps 1 and 2 don't both succeed, ROLLBACK and
     * return. The rate column is preserved so a later install()
     * run can retry from scratch. Step 3 only executes when the
     * backfill committed cleanly.
     */
    private static function migrate_rate_to_client_rates(): void {
        global $wpdb;

        $rates_table   = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES);
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // Check if meals_client_rates table is empty. Use LIMIT 1 —
        // COUNT(*) walks the full table and the existing query is
        // only asking "is anything here?".
        $has_rows = $wpdb->get_var("SELECT 1 FROM `{$rates_table}` LIMIT 1");
        if ($has_rows !== null) {
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

        $started = $wpdb->query('START TRANSACTION');
        if ($started === false) {
            error_log('[MealsDB Installer] rate migration aborted: START TRANSACTION failed.');
            return;
        }

        // Seed rates from existing flat rate values
        $insert_sql = "INSERT INTO `{$rates_table}` (client_id, label, rate, is_default, created_at) SELECT client_id, 'Standard', rate, 1, NOW() FROM `{$clients_table}` WHERE rate > 0";
        $insert_result = $wpdb->query($insert_sql);
        if ($insert_result === false) {
            error_log(sprintf('[MealsDB Installer] Seed INSERT failed, rolling back: %s', $wpdb->last_error));
            $wpdb->query('ROLLBACK');
            return;
        }
        $seeded = $wpdb->rows_affected;
        error_log(sprintf('[MealsDB Installer] Seeded %d rows into meals_client_rates', $seeded));

        // Back-fill default_rate_id on meals_clients
        $backfill_sql = "UPDATE `{$clients_table}` c INNER JOIN `{$rates_table}` r ON r.client_id = c.client_id AND r.is_default = 1 SET c.default_rate_id = r.rate_id";
        $backfill_result = $wpdb->query($backfill_sql);
        if ($backfill_result === false) {
            error_log(sprintf('[MealsDB Installer] default_rate_id backfill failed, rolling back: %s', $wpdb->last_error));
            $wpdb->query('ROLLBACK');
            return;
        }
        error_log(sprintf('[MealsDB Installer] Back-filled default_rate_id for %d clients', $wpdb->rows_affected));

        if ($wpdb->query('COMMIT') === false) {
            error_log(sprintf('[MealsDB Installer] COMMIT failed for rate migration: %s', $wpdb->last_error));
            $wpdb->query('ROLLBACK');
            return;
        }

        // Drop the now-redundant rate column. Runs AFTER the DML tx
        // has committed — DDL implicitly commits in MySQL, so it
        // couldn't join the transaction above, but it only fires
        // when steps 1 and 2 above are known-good.
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
