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

        $types = [];
        $sql = 'SELECT DISTINCT customer_type FROM meals_clients WHERE customer_type <> "" ORDER BY customer_type ASC';
        $result = $conn->query($sql);
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $types[] = $row['customer_type'];
            }
            $result->free();
        }

        return $types;
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

        $sql = 'SELECT id, first_name, last_name, customer_type, phone_primary, client_email, active FROM meals_clients';
        $conditions = [];
        $types = '';
        $params = [];

        if (!$show_inactive) {
            $conditions[] = 'active = 1';
        }

        if ($client_type !== null && $client_type !== '') {
            $conditions[] = 'UPPER(customer_type) = ?';
            $types .= 's';
            $params[] = strtoupper($client_type);
        }

        if ($search !== null && $search !== '') {
            $conditions[] = '(LOWER(first_name) LIKE ? OR LOWER(last_name) LIKE ? OR LOWER(CONCAT(first_name, " ", last_name)) LIKE ?)';
            $types .= 'sss';
            $like = '%' . strtolower($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY last_name ASC, first_name ASC';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('[MealsDB] Failed to prepare client listing query: ' . ($conn->error ?? 'unknown error'));
            return [];
        }

        if (!empty($params)) {
            $bind_params = [$types];
            foreach ($params as $index => $value) {
                $bind_params[] =& $params[$index];
            }

            if (call_user_func_array([$stmt, 'bind_param'], $bind_params) === false) {
                error_log('[MealsDB] Failed to bind parameters for client listing query.');
                $stmt->close();
                return [];
            }
        }

        if (!$stmt->execute()) {
            error_log('[MealsDB] Failed to execute client listing query: ' . ($stmt->error ?? 'unknown error'));
            $stmt->close();
            return [];
        }

        $records = [];
        $result = $stmt->get_result();
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $records[] = $row;
            }
        }

        $stmt->close();

        return $records;
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

        $client_snapshot = null;
        $select = $conn->prepare('SELECT first_name, last_name, customer_type, client_email FROM meals_clients WHERE id = ?');
        if ($select instanceof mysqli_stmt) {
            if ($select->bind_param('i', $client_id) && $select->execute()) {
                if ($select->bind_result($first_name, $last_name, $customer_type, $client_email)) {
                    if ($select->fetch()) {
                        $client_snapshot = [
                            'first_name' => $first_name,
                            'last_name' => $last_name,
                            'customer_type' => $customer_type,
                            'client_email' => $client_email,
                        ];
                    }
                }
            }
            $select->close();
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
            if (!$stmt instanceof mysqli_stmt) {
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
            $delete_stmt = $conn->prepare('DELETE FROM meals_clients WHERE id = ?');
            if (!$delete_stmt instanceof mysqli_stmt) {
                error_log('[MealsDB] Failed to prepare client deletion statement.');
                $success = false;
            } else {
                if (!$delete_stmt->bind_param('i', $client_id)) {
                    error_log('[MealsDB] Failed to bind client deletion parameter.');
                    $success = false;
                } elseif (!$delete_stmt->execute()) {
                    error_log('[MealsDB] Failed to execute client deletion: ' . ($delete_stmt->error ?? 'unknown error'));
                    $success = false;
                } elseif ($delete_stmt->affected_rows < 1) {
                    $success = false;
                }
                $delete_stmt->close();
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

        $old_value = null;
        $select = $conn->prepare('SELECT active FROM meals_clients WHERE id = ?');
        if ($select instanceof mysqli_stmt) {
            if ($select->bind_param('i', $client_id) && $select->execute()) {
                $current_active = null;
                if (!$select->bind_result($current_active)) {
                    $current_active = null;
                }
                if ($select->fetch()) {
                    $old_value = (string) $current_active;
                }
            }
            $select->close();
        }

        $stmt = $conn->prepare('UPDATE meals_clients SET active = ? WHERE id = ?');
        if (!$stmt) {
            error_log('[MealsDB] Failed to prepare client activation update: ' . ($conn->error ?? 'unknown error'));
            return false;
        }

        if (!$stmt->bind_param('ii', $active, $client_id)) {
            error_log('[MealsDB] Failed to bind parameters for client activation update.');
            $stmt->close();
            return false;
        }

        if (!$stmt->execute()) {
            error_log('[MealsDB] Failed to execute client activation update: ' . ($stmt->error ?? 'unknown error'));
            $stmt->close();
            return false;
        }

        $stmt->close();

        MealsDB_Logger::log($action, $client_id, 'active', $old_value, (string) $active);

        return true;
    }

    /**
     * Check if a table contains a specific column.
     */
    private static function table_has_column(mysqli $conn, string $table_name, string $column): bool {
        $escaped_table = str_replace('`', '``', $table_name);
        $escaped_column = $column;

        if (method_exists($conn, 'real_escape_string')) {
            $escaped_column = $conn->real_escape_string($escaped_column);
        }

        $sql = sprintf("SHOW COLUMNS FROM `%s` LIKE '%s'", $escaped_table, $escaped_column);
        $result = $conn->query($sql);

        if ($result instanceof mysqli_result) {
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
}
