<?php
/**
 * Helper utilities for fetching client records for admin screens.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Clients {

    /**
     * Fetch all client types currently stored.
     *
     * @return string[]
     */
    public static function get_client_types(): array {
        $conn = MealsDB_DB::get_connection();
        if (!$conn) {
            return [];
        }

        $repository = new MealsDB_Clients_Repository($conn);

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
    public static function get_clients(?string $client_type = null, ?string $search = null, bool $show_inactive = false): array {
        $conn = MealsDB_DB::get_connection();
        if (!$conn) {
            return [];
        }

        $repository = new MealsDB_Clients_Repository($conn);

        return $repository->search_clients($client_type, $search, $show_inactive);
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
        $conn = MealsDB_DB::get_connection();
        if (!$conn) {
            return false;
        }

        $repository = new MealsDB_Clients_Repository($conn);

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

        $transaction_started = false;
        if (method_exists($conn, 'begin_transaction')) {
            $transaction_started = $conn->begin_transaction();
            if (!$transaction_started) {
                error_log('[MealsDB] Failed to begin transaction for client deletion.');
            }
        }

        $success = true;

        $tables_to_cleanup = [
            ['table' => 'meals_drafts', 'column' => 'client_id'],
            ['table' => 'meals_ignored_conflicts', 'column' => 'client_id'],
        ];

        foreach ($tables_to_cleanup as $cleanup) {
            $table_name = MealsDB_DB::get_table_name($cleanup['table']);
            $column = $cleanup['column'];

            if (!self::table_has_column($conn, $table_name, $column)) {
                continue;
            }

            $escaped_table = str_replace('`', '``', $table_name);
            $escaped_column = str_replace('`', '``', $column);
            $sql = sprintf('DELETE FROM `%s` WHERE `%s` = ?', $escaped_table, $escaped_column);

            $stmt = $conn->prepare($sql);
            if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
                error_log(sprintf('[MealsDB] Failed to prepare cleanup delete for %s.', $cleanup['table']));
                $success = false;
                break;
            }

            if (!$stmt->bind_param('i', $client_id)) {
                error_log(sprintf('[MealsDB] Failed to bind cleanup delete parameters for %s.', $cleanup['table']));
                $stmt->close();
                $success = false;
                break;
            }

            if (!$stmt->execute()) {
                error_log(sprintf('[MealsDB] Failed to execute cleanup delete for %s: %s', $cleanup['table'], $stmt->error ?? 'unknown error'));
                $stmt->close();
                $success = false;
                break;
            }

            $stmt->close();
        }

        if ($success) {
            if (!$repository->delete_client($client_id)) {
                $success = false;
            }
        }

        if ($transaction_started) {
            if ($success) {
                if (!$conn->commit()) {
                    error_log('[MealsDB] Failed to commit client deletion transaction.');
                    $conn->rollback();
                    $success = false;
                }
            } else {
                $conn->rollback();
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
        $conn = MealsDB_DB::get_connection();
        if (!$conn) {
            return false;
        }

        $repository = new MealsDB_Clients_Repository($conn);

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
    private static function table_has_column($conn, string $table_name, string $column): bool {
        if (!self::table_exists($conn, $table_name)) {
            return false;
        }

        $escaped_table = str_replace('`', '``', $table_name);
        $escaped_column = $column;

        if (method_exists($conn, 'real_escape_string')) {
            $escaped_column = $conn->real_escape_string($escaped_column);
        }

        $sql = sprintf("SHOW COLUMNS FROM `%s` LIKE '%s'", $escaped_table, $escaped_column);
        $result = $conn->query($sql);

        if (MealsDB_DB::is_mysqli_result($result)) {
            $exists = $result->num_rows > 0;
            $result->free();
            return $exists;
        }

        if ($result && isset($result->num_rows)) {
            $exists = $result->num_rows > 0;
            if (method_exists($result, 'free')) {
                $result->free();
            }
            return $exists;
        }

        return false;
    }

    /**
     * Determine if the target table exists in the configured database.
     */
    private static function table_exists($conn, string $table_name): bool {
        if (!MealsDB_DB::is_mysqli($conn)) {
            return false;
        }

        $sql = 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return false;
        }

        if (!$stmt->bind_param('s', $table_name) || !$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $exists;
    }
}
