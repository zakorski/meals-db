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
     * @param string|null $client_type  Optional client type filter.
     * @param string|null $search       Optional search string that matches first or last name.
     * @param bool        $show_inactive Whether inactive clients should be included in the results.
     * @return array<int, array<string, string|null>>
     */
    public static function get_clients(?string $client_type = null, ?string $search = null, bool $show_inactive = false, int $limit = 100, int $offset = 0): array {
        global $wpdb;
        if (!$wpdb) {
            return [];
        }

        $repository = new MealsDB_Clients_Repository();

        return $repository->search_clients($client_type, $search, $show_inactive, $limit, $offset);
    }

    /**
     * Count clients matching the given filters (for pagination UIs).
     */
    public static function count_clients(?string $client_type = null, ?string $search = null, bool $show_inactive = false): int {
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
        $started = $wpdb->query('START TRANSACTION');
        $transaction_started = $started !== false;

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
     */
    private static function table_has_column(string $table_name, string $column): bool {
        if (!self::table_exists($table_name)) {
            return false;
        }

        global $wpdb;

        $escaped_column = $wpdb->_real_escape($column);

        $sql = sprintf("SHOW COLUMNS FROM `%s` LIKE '%s'", $table_name, $escaped_column);
        $result = $wpdb->get_results($sql, ARRAY_A);

        if (is_array($result)) {
            return count($result) > 0;
        }

        return false;
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
