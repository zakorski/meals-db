<?php
/**
 * Installer responsible for preparing the Meals DB schema.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

/**
 * NOTE (X1-orphan-files-3): this file is named install-schema.php but defines
 * MealsDB_Installer. The autoloader derives filenames from the class slug
 * (MealsDB_Installer -> class-installer.php), which does not exist, so this
 * class is NOT autoloadable. Every caller MUST require_once this file before
 * use (see meals-db-main.php and includes/class-updates.php, which do). Do not
 * reference MealsDB_Installer expecting the autoloader to resolve it.
 */
class MealsDB_Installer {

    /**
     * Run the schema installation/upgrade routine.
     *
     * Creates the Meals DB tables in the WordPress database using $wpdb.
     */
    public static function install(): void {
        global $wpdb;

        // U11-schema-2: collect genuine failures so we can THROW at the end.
        // The caller mealsdb_maybe_upgrade_schema() only skips
        // update_option('mealsdb_db_version', ...) when install() throws (its
        // try/catch logs and lets the next admin_init retry); previously
        // install() returned void unconditionally, so a partially-built schema
        // got recorded as up-to-date and was never retried until the next
        // version bump (recon-01 known bug). Every step below is idempotent
        // (CREATE TABLE IF NOT EXISTS, existence-guarded column adds and
        // migrations), so we run the whole routine and throw ONCE at the end
        // rather than bailing mid-build.
        $failures = [];

        $schemas = MealsDB_Schema::get_canonical_schema();
        foreach ($schemas as $schema) {
            $sql = MealsDB_Schema::generate_create_table_sql($schema);

            $wpdb->query($sql);
            if ($wpdb->last_error) {
                error_log(sprintf('[MealsDB Installer] Failed creating %s: %s', $schema['table'], $wpdb->last_error));
                $failures[] = sprintf('create %s: %s', $schema['table'], $wpdb->last_error);
            }
        }

        $sync_result = MealsDB_Schema_Sync::run_full_sync();
        if (is_wp_error($sync_result)) {
            error_log('[MealsDB Installer] Schema sync failed: ' . $sync_result->get_error_message());
            $failures[] = 'schema sync: ' . $sync_result->get_error_message();
        } elseif (is_array($sync_result) && !empty($sync_result['errors'])) {
            // run_full_sync() continues past individual table-create / ADD
            // COLUMN failures and returns them in ['errors']; inspect that
            // array, not just is_wp_error() (the recommendation). NOTE:
            // ['column_mismatches'] is deliberately NOT treated as a failure
            // here — the additive sync can't MODIFY an existing column, so a
            // retry would never clear the mismatch; blocking the version bump
            // on it would loop forever. Mismatches are surfaced/logged inside
            // run_full_sync() and require an explicit ALTER migration.
            $error_count = count($sync_result['errors']);
            error_log(sprintf('[MealsDB Installer] Schema sync reported %d table/column error(s).', $error_count));
            $failures[] = sprintf('schema sync: %d table/column error(s)', $error_count);
        }

        // Run one-time migrations
        self::migrate_rate_to_client_rates();
        self::widen_vet_health_card_column();
        self::drop_defunct_transaction_tables();

        // Seed the first task-engine rule so a freshly-installed site has
        // something to exercise the cron pass with. Safe to call on every
        // install — it no-ops when the seed rule already exists.
        self::seed_task_engine();

        // U11-schema-2: a failed table/column create must NOT be recorded as
        // "schema up-to-date". Throwing makes mealsdb_maybe_upgrade_schema
        // skip the version bump (and retry next admin_init) and converts
        // run_database_maintenance()'s previously-silent success into a
        // surfaced error. Thrown AFTER the idempotent migrations/seed so a
        // retry starts from the furthest-along state.
        if (!empty($failures)) {
            throw new RuntimeException(
                '[MealsDB Installer] schema install/upgrade incomplete: ' . implode('; ', $failures)
            );
        }
    }

    /**
     * Seed the task-engine rules: POC reminder plus the R2 workflows
     * (weekly phone-call list, monthly Appetito PO). Idempotent — each
     * rule is keyed by name so a reinstall won't duplicate them.
     */
    private static function seed_task_engine(): void {
        global $wpdb;

        if (!class_exists('MealsDB_Task_Rules')) {
            return;
        }

        $rules_table = MealsDB_DB::get_table_name(MealsDB_Tables::SCHEDULE_RULES);
        $rules = new MealsDB_Task_Rules();

        $seeds = [];

        if (class_exists('MealsDB_Task_Type_Generic_Reminder')) {
            $seeds[] = [
                'name'             => 'Weekly overdue task review',
                'task_type'        => MealsDB_Task_Type_Generic_Reminder::TYPE_ID,
                'spawn_type'       => MealsDB_Task_Rules::SPAWN_FIXED,
                'recurrence'       => [
                    'type'         => 'weekly',
                    'interval'     => 1,
                    'days_of_week' => ['monday'],
                    'time'         => '08:00',
                ],
                'payload_template' => ['description' => 'Review overdue tasks for the week.'],
                'assignee_role'    => 'admin',
                'is_active'        => 1,
            ];
        }

        if (class_exists('MealsDB_Task_Type_Client_Delivery')) {
            $seeds[] = [
                'name'             => 'Weekly Delivery List',
                'task_type'        => MealsDB_Task_Type_Client_Delivery::TYPE_ID,
                'spawn_type'       => MealsDB_Task_Rules::SPAWN_QUERY,
                'recurrence'       => [
                    'type'         => 'weekly',
                    'interval'     => 1,
                    'days_of_week' => ['sunday'],
                    'time'         => '06:00',
                ],
                'query_criteria'   => [
                    'strategy' => 'clients_due_for_delivery',
                    'params'   => [
                        'days_window' => 7,
                    ],
                ],
                'payload_template' => [
                    'client_id'          => '{{wp_user_id}}',
                    'client_name'        => '{{first_name}} {{last_name}}',
                    'delivery_day'       => '{{delivery_day}}',
                    'next_delivery_date' => '{{next_delivery_date}}',
                ],
                'assignee_role'    => 'warehouse',
                'tags'             => ['weekly_deliveries'],
                'is_active'        => 1,
            ];
        }

        if (class_exists('MealsDB_Task_Type_Call_Client')) {
            $seeds[] = [
                'name'             => 'Weekly Phone Call List',
                'task_type'        => MealsDB_Task_Type_Call_Client::TYPE_ID,
                'spawn_type'       => MealsDB_Task_Rules::SPAWN_QUERY,
                'recurrence'       => [
                    'type'         => 'weekly',
                    'interval'     => 1,
                    'days_of_week' => ['wednesday'],
                    'time'         => '06:00',
                ],
                'query_criteria'   => [
                    'strategy' => 'clients_due_to_reorder',
                    'params'   => [
                        'days_window'    => 7,
                        'contact_method' => 'phone',
                    ],
                ],
                'payload_template' => [
                    'client_id'       => '{{wp_user_id}}',
                    'client_name'     => '{{first_name}} {{last_name}}',
                    'phone'           => '{{client_phone_1}}',
                    'next_order_date' => '{{next_order_date}}',
                ],
                'assignee_role'    => 'phone',
                'tags'             => ['weekly_calls'],
                'is_active'        => 1,
            ];
        }

        if (class_exists('MealsDB_Task_Type_Place_PO')) {
            $seeds[] = [
                'name'             => 'Appetito Purchase Order',
                'task_type'        => MealsDB_Task_Type_Place_PO::TYPE_ID,
                'spawn_type'       => MealsDB_Task_Rules::SPAWN_FIXED,
                'recurrence'       => [
                    'type'        => 'monthly_weekday',
                    'interval'    => 1,
                    'nth'         => 4,
                    'day_of_week' => 'tuesday',
                    'time'        => '08:00',
                ],
                'payload_template' => ['supplier' => 'Appetito'],
                'assignee_role'    => 'admin',
                'tags'             => ['appetito_po'],
                'is_active'        => 1,
            ];
        }

        // U11-schema-10: seed each rule at most ONCE per install, tracked by name
        // in a persisted ledger. install() runs on every version bump, so the old
        // per-name existence check re-created any seed rule the operator had
        // DELETED (resurrecting it with is_active=1 — the query-spawn rules then
        // immediately resumed spawning tasks against live clients). A per-seed
        // ledger makes a deliberate delete/rename STICK across upgrades while
        // still letting a genuinely NEW seed added in a future version deploy to
        // existing installs (its name won't be in the ledger yet). The existence
        // check is retained so pre-ledger installs (rules already present) are
        // recorded as done without duplicating.
        $seeds_done = function_exists('get_option') ? get_option('mealsdb_task_seeds_done', []) : [];
        if (!is_array($seeds_done)) {
            $seeds_done = [];
        }
        $ledger_changed = false;

        foreach ($seeds as $seed) {
            if (in_array($seed['name'], $seeds_done, true)) {
                // Already planted once — never recreate, so an operator's delete
                // or rename is permanent.
                continue;
            }

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT rule_id FROM `{$rules_table}` WHERE name = %s LIMIT 1",
                $seed['name']
            ));
            if (!$existing) {
                $rules->create_rule($seed);
            }

            // Record the seed as planted whether we created it now or found it
            // already present, so intact pre-ledger installs also mark done.
            $seeds_done[]   = $seed['name'];
            $ledger_changed = true;
        }

        if ($ledger_changed && function_exists('update_option')) {
            update_option('mealsdb_task_seeds_done', array_values(array_unique($seeds_done)), false);
        }
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
    /**
     * One-time: widen meals_clients.vet_health_card from VARCHAR(50) to VARCHAR(500).
     *
     * vet_health_card is AES-256 encrypted (base64(hmac.iv.ciphertext), ~90+ chars),
     * so VARCHAR(50) overflows for every value and blocked all Veteran creates. Its
     * sibling encrypted fields (individual_id, requisition_id) are already VARCHAR(500);
     * this brings vet_health_card in line. Idempotent: only ALTERs when the live column
     * is still narrower than 500. Safe to run on every install/upgrade.
     */
    private static function widen_vet_health_card_column(): void {
        global $wpdb;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // Read the live column type; only act if it's not already VARCHAR(500)+.
        $col = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT CHARACTER_MAXIMUM_LENGTH AS len
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = 'vet_health_card'",
                $clients_table
            ),
            ARRAY_A
        );

        // If we can't read it, or it's already wide enough, do nothing.
        if (!is_array($col) || !isset($col['len'])) {
            return;
        }
        if ((int) $col['len'] >= 500) {
            return;
        }

        $alter_sql = "ALTER TABLE `{$clients_table}` MODIFY COLUMN `vet_health_card` VARCHAR(500) NULL";
        if ($wpdb->query($alter_sql) === false) {
            error_log('[MealsDB Installer] Failed to widen vet_health_card column: ' . $wpdb->last_error);
        }
    }

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

            // Only log an actual drop. DROP TABLE IF EXISTS succeeds on a
            // missing table too, so the old unconditional "Dropped" line ran on
            // every install/upgrade and implied recurring destructive action.
            $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;

            if ($wpdb->query(sprintf("DROP TABLE IF EXISTS `%s`", $escaped)) === false) {
                error_log(sprintf('[MealsDB Installer] Failed to drop defunct table %s: %s', $table_name, $wpdb->last_error));
            } elseif ($exists) {
                error_log(sprintf('[MealsDB Installer] Dropped defunct table: %s', $table_name));
            }
        }
    }
}
