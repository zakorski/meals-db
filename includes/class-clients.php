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
     * @param string|null $client_type Optional client type filter.
     * @param string|null $search      Optional search string that matches first or last name.
     * @return array<int, array<string, string|null>>
     */
    public static function get_clients(?string $client_type = null, ?string $search = null): array {
        $conn = MealsDB_DB::get_connection();
        if (!$conn) {
            return [];
        }

        $sql = 'SELECT id, first_name, last_name, customer_type, phone_primary, client_email FROM meals_clients';
        $conditions = [];
        $types = '';
        $params = [];

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
}
