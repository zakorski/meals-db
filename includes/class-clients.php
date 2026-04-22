<?php
/**
 * Helper utilities for fetching client records for admin screens.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Clients {

    /**
     * Fetch all client types currently stored.
     *
     * @return string[]
     */
    public static function get_client_types(): array {
        global $wpdb;
        if (!$wpdb) {
            return [];
        }

        $repository = new MealsDB_Clients_Repository();

        return $repository->get_client_types();
    }

    /**
     * Fetch a paginated list of clients for the admin table.
     *
     * @param string|array<int,string>|null $client_type  Optional client type filter (string or array for IN()).
     * @param string|null                   $search       Optional search string that matches first or last name.
     * @param bool                          $show_inactive Whether inactive clients should be included in the results.
     * @return array<int, array<string, string|null>>
     */
    public static function get_clients($client_type = null, ?string $search = null, bool $show_inactive = false, int $limit = 100, int $offset = 0): array {
        global $wpdb;
        if (!$wpdb) {
            return [];
        }

        $repository = new MealsDB_Clients_Repository();

        return $repository->search_clients($client_type, $search, $show_inactive, $limit, $offset);
    }

    /**
     * Count clients matching the given filters (for pagination UIs).
     *
     * @param string|array<int,string>|null $client_type
     */
    public static function count_clients($client_type = null, ?string $search = null, bool $show_inactive = false): int {
        global $wpdb;
        if (!$wpdb) {
            return 0;
        }
        $repository = new MealsDB_Clients_Repository();
        return $repository->count_clients($client_type, $search, $show_inactive);
    }

    /**
     * Deactivate a client by ID.
     *
     * @return bool
     */
    public static function deactivate_client(int $client_id): bool {
        return self::set_client_active_status($client_id, 0, 'deactivate_client');
    }

    /**
     * Activate a client by ID.
     *
     * @return bool
     */
    public static function activate_client(int $client_id): bool {
        return self::set_client_active_status($client_id, 1, 'activate_client');
    }

    /**
     * Permanently delete a client and any optionally related rows.
     */
    public static function delete_client(int $client_id): bool {
        // Defence-in-depth: enforce capability here even if a future caller
        // skips the AJAX gate. Deletes cascade across drafts, conflicts,
        // and the client row itself.
        if (function_exists('current_user_can')
            && (!is_user_logged_in() || !MealsDB_Permissions::can_access_plugin())) {
            error_log('[MealsDB] delete_client blocked: insufficient permissions.');
            return false;
        }

        if ($client_id <= 0) {
            return false;
        }

        global $wpdb;
        if (!$wpdb) {
            return false;
        }

        $repository = new MealsDB_Clients_Repository();

        $client_snapshot = null;
        $client_record = $repository->get_client_by_id($client_id);
        if (is_array($client_record)) {
            $client_snapshot = [
                'first_name' => $client_record['first_name'] ?? null,
                'last_name' => $client_record['last_name'] ?? null,
                'client_type' => $client_record['client_type'] ?? null,
                'client_email' => $client_record['client_email'] ?? null,
            ];
        }

        // Verify START TRANSACTION actually succeeded before assuming we
        // have transactional safety; otherwise COMMIT/ROLLBACK below would
        // be no-ops while the destructive deletes still happen.
        //
        // When the transaction cannot be started (DB unreachable, MyISAM
        // storage engine with AUTOCOMMIT, connection in an aborted state),
        // REFUSE the delete rather than proceed with autocommitted
        // destructive work. delete_client cascades across drafts,
        // ignored_conflicts, and the clients row itself; without a
        // rollback available a partial failure mid-loop would corrupt
        // the data model with no recovery path.
        $started = $wpdb->query('START TRANSACTION');
        $transaction_started = $started !== false;

        if (!$transaction_started) {
            error_log(sprintf(
                '[MealsDB] delete_client aborted: START TRANSACTION failed (client_id=%d, last_error=%s)',
                $client_id,
                $wpdb->last_error !== '' ? $wpdb->last_error : 'unknown'
            ));
            return false;
        }

        $success = true;

        $tables_to_cleanup = [
            ['table' => MealsDB_Tables::DRAFTS, 'column' => 'client_id'],
            ['table' => MealsDB_Tables::IGNORED_CONFLICTS, 'column' => 'client_id'],
        ];

        foreach ($tables_to_cleanup as $cleanup) {
            $table_name = MealsDB_DB::get_table_name($cleanup['table']);
            $column = $cleanup['column'];

            if (!self::table_has_column($table_name, $column)) {
                continue;
            }

            $sql = $wpdb->prepare(
                sprintf('DELETE FROM `%s` WHERE `%s` = %%d', $table_name, $column),
                $client_id
            );

            $result = $wpdb->query($sql);
            if ($result === false) {
                error_log(sprintf('[MealsDB] Failed to execute cleanup delete for %s: %s', $cleanup['table'], $wpdb->last_error));
                $success = false;
                break;
            }
        }

        if ($success) {
            if (!$repository->delete_client($client_id)) {
                $success = false;
            }
        }

        if ($transaction_started) {
            if ($success) {
                if ($wpdb->query('COMMIT') === false) {
                    error_log('[MealsDB] Failed to commit client deletion transaction.');
                    $wpdb->query('ROLLBACK');
                    $success = false;
                }
            } else {
                $wpdb->query('ROLLBACK');
            }
        }

        if ($success) {
            $old_value = null;
            if ($client_snapshot !== null) {
                $encoded = json_encode($client_snapshot);
                if ($encoded !== false) {
                    $old_value = $encoded;
                }
            }
            MealsDB_Logger::log('delete_client', $client_id, 'record', $old_value, null);
        }

        return $success;
    }

    /**
     * Update a client's active status and log the change.
     *
     * @return bool
     */
    private static function set_client_active_status(int $client_id, int $active, string $action): bool {
        global $wpdb;
        if (!$wpdb) {
            return false;
        }

        $repository = new MealsDB_Clients_Repository();

        $old_value = null;
        $existing = $repository->get_client_by_id($client_id);
        if (is_array($existing) && array_key_exists('active', $existing)) {
            $old_value = (string) $existing['active'];
        }

        if (!$repository->update_client($client_id, ['active' => $active])) {
            return false;
        }

        MealsDB_Logger::log($action, $client_id, 'active', $old_value, (string) $active);

        return true;
    }

    /**
     * Check if a table contains a specific column.
     *
     * Uses INFORMATION_SCHEMA with both identifiers bound as %s through
     * $wpdb->prepare. The previous implementation fed the column name
     * through _real_escape() and into a LIKE clause, which (a) doesn't
     * neutralise LIKE wildcards — a column literally named `_` would
     * match every column — and (b) let the caller quietly influence
     * match semantics. Column-existence is stable within a request, so
     * the result is cached per (table, column) to keep repeated schema
     * probes from reissuing the same query.
     */
    private static function table_has_column(string $table_name, string $column): bool {
        if (!self::table_exists($table_name)) {
            return false;
        }

        static $cache = [];
        $key = $table_name . "\0" . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND COLUMN_NAME = %s
             LIMIT 1",
            $table_name,
            $column
        ));

        return $cache[$key] = ($found !== null);
    }

    /**
     * Determine if the target table exists in the configured database.
     */
    private static function table_exists(string $table_name): bool {
        global $wpdb;
        if (!$wpdb) {
            return false;
        }

        $row = $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s LIMIT 1',
            $table_name
        ));

        return $row !== null;
    }
}
