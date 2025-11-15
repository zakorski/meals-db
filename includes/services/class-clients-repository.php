<?php
/**
 * Repository for interacting with Meals DB client records.
 */

class MealsDB_Clients_Repository {
    /**
     * @var mysqli|null
     */
    private $connection;

    public function __construct(?mysqli $connection = null) {
        if ($connection instanceof mysqli) {
            $this->connection = $connection;
        } else {
            $this->connection = null;
        }
    }

    /**
     * Retrieve all clients.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_all_clients(): array {
        $conn = $this->get_or_fetch_connection();
        if (!$conn) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when fetching clients.');
            return [];
        }

        try {
            $stmt = $conn->prepare('SELECT * FROM meals_clients');
            if (!is_object($stmt)) {
                error_log('[MealsDB Clients Repository] Failed to prepare client list query: ' . ($conn->error ?? 'unknown error'));
                return [];
            }

            if (!method_exists($stmt, 'execute') || !$stmt->execute()) {
                error_log('[MealsDB Clients Repository] Failed to execute client list query: ' . ($stmt->error ?? 'unknown error'));
                if (method_exists($stmt, 'close')) {
                    $stmt->close();
                }
                return [];
            }

            $rows = $this->fetch_all_assoc($stmt);
            if (method_exists($stmt, 'close')) {
                $stmt->close();
            }

            return $rows;
        } catch (Throwable $e) {
            error_log('[MealsDB Clients Repository] Exception while fetching clients: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch a single client by ID.
     */
    public function get_client_by_id(int $client_id): ?array {
        $conn = $this->get_or_fetch_connection();
        if (!$conn) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when fetching client by ID.');
            return null;
        }

        try {
            $stmt = $conn->prepare('SELECT * FROM meals_clients WHERE id = ? LIMIT 1');
            if (!is_object($stmt)) {
                error_log('[MealsDB Clients Repository] Failed to prepare client lookup query: ' . ($conn->error ?? 'unknown error'));
                return null;
            }

            if (!method_exists($stmt, 'bind_param') || !$stmt->bind_param('i', $client_id)) {
                error_log('[MealsDB Clients Repository] Failed to bind client lookup parameter.');
                if (method_exists($stmt, 'close')) {
                    $stmt->close();
                }
                return null;
            }

            if (!method_exists($stmt, 'execute') || !$stmt->execute()) {
                error_log('[MealsDB Clients Repository] Failed to execute client lookup query: ' . ($stmt->error ?? 'unknown error'));
                if (method_exists($stmt, 'close')) {
                    $stmt->close();
                }
                return null;
            }

            $rows = $this->fetch_all_assoc($stmt);
            if (method_exists($stmt, 'close')) {
                $stmt->close();
            }

            return $rows[0] ?? null;
        } catch (Throwable $e) {
            error_log('[MealsDB Clients Repository] Exception while fetching client by ID: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new client record.
     */
    public function create_client(array $data): bool {
        $conn = $this->get_or_fetch_connection();
        if (!$conn) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when creating client.');
            return false;
        }

        if (empty($data)) {
            error_log('[MealsDB Clients Repository] Attempted to create client with no data.');
            return false;
        }

        try {
            $columns = array_keys($data);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $column_list = '`' . implode('`, `', $columns) . '`';
            $sql = sprintf('INSERT INTO meals_clients (%s) VALUES (%s)', $column_list, $placeholders);

            $stmt = $conn->prepare($sql);
            if (!is_object($stmt)) {
                error_log('[MealsDB Clients Repository] Failed to prepare client insert statement: ' . ($conn->error ?? 'unknown error'));
                return false;
            }

            $values = array_values($data);
            $types = $this->determine_types($values);
            $params = $this->build_bind_params($types, $values);

            if ($params === null || !method_exists($stmt, 'bind_param') || call_user_func_array([$stmt, 'bind_param'], $params) === false) {
                error_log('[MealsDB Clients Repository] Failed to bind parameters for client insert.');
                if (method_exists($stmt, 'close')) {
                    $stmt->close();
                }
                return false;
            }

            if (!method_exists($stmt, 'execute') || !$stmt->execute()) {
                error_log('[MealsDB Clients Repository] Failed to execute client insert: ' . ($stmt->error ?? 'unknown error'));
                if (method_exists($stmt, 'close')) {
                    $stmt->close();
                }
                return false;
            }

            if (method_exists($stmt, 'close')) {
                $stmt->close();
            }

            return true;
        } catch (Throwable $e) {
            error_log('[MealsDB Clients Repository] Exception while creating client: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update an existing client record.
     */
    public function update_client(int $client_id, array $data): bool {
        if ($client_id <= 0) {
            error_log('[MealsDB Clients Repository] Attempted to update client with invalid ID.');
            return false;
        }

        $conn = $this->get_or_fetch_connection();
        if (!$conn) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when updating client.');
            return false;
        }

        if (empty($data)) {
            error_log('[MealsDB Clients Repository] Attempted to update client with no data.');
            return false;
        }

        try {
            $set_parts = [];
            foreach (array_keys($data) as $column) {
                $set_parts[] = sprintf('`%s` = ?', $column);
            }

            $sql = sprintf('UPDATE meals_clients SET %s WHERE id = ? LIMIT 1', implode(', ', $set_parts));
            $stmt = $conn->prepare($sql);
            if (!is_object($stmt)) {
                error_log('[MealsDB Clients Repository] Failed to prepare client update statement: ' . ($conn->error ?? 'unknown error'));
                return false;
            }

            $values = array_values($data);
            $values[] = $client_id;
            $types = $this->determine_types($values);
            $params = $this->build_bind_params($types, $values);

            if ($params === null || !method_exists($stmt, 'bind_param') || call_user_func_array([$stmt, 'bind_param'], $params) === false) {
                error_log('[MealsDB Clients Repository] Failed to bind parameters for client update.');
                if (method_exists($stmt, 'close')) {
                    $stmt->close();
                }
                return false;
            }

            if (!method_exists($stmt, 'execute') || !$stmt->execute()) {
                error_log('[MealsDB Clients Repository] Failed to execute client update: ' . ($stmt->error ?? 'unknown error'));
                if (method_exists($stmt, 'close')) {
                    $stmt->close();
                }
                return false;
            }

            if (method_exists($stmt, 'close')) {
                $stmt->close();
            }

            return true;
        } catch (Throwable $e) {
            error_log('[MealsDB Clients Repository] Exception while updating client: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a client record.
     */
    public function delete_client(int $client_id): bool {
        if ($client_id <= 0) {
            error_log('[MealsDB Clients Repository] Attempted to delete client with invalid ID.');
            return false;
        }

        $conn = $this->get_or_fetch_connection();
        if (!$conn) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when deleting client.');
            return false;
        }

        try {
            $stmt = $conn->prepare('DELETE FROM meals_clients WHERE id = ?');
            if (!is_object($stmt)) {
                error_log('[MealsDB Clients Repository] Failed to prepare client delete statement: ' . ($conn->error ?? 'unknown error'));
                return false;
            }

            if (!method_exists($stmt, 'bind_param') || !$stmt->bind_param('i', $client_id)) {
                error_log('[MealsDB Clients Repository] Failed to bind client delete parameter.');
                if (method_exists($stmt, 'close')) {
                    $stmt->close();
                }
                return false;
            }

            if (!method_exists($stmt, 'execute') || !$stmt->execute()) {
                error_log('[MealsDB Clients Repository] Failed to execute client delete: ' . ($stmt->error ?? 'unknown error'));
                if (method_exists($stmt, 'close')) {
                    $stmt->close();
                }
                return false;
            }

            $affected_rows = property_exists($stmt, 'affected_rows') ? $stmt->affected_rows : ($conn->affected_rows ?? 0);
            if ((int) $affected_rows < 1) {
                error_log('[MealsDB Clients Repository] Client delete affected 0 rows.');
                if (method_exists($stmt, 'close')) {
                    $stmt->close();
                }
                return false;
            }

            if (method_exists($stmt, 'close')) {
                $stmt->close();
            }

            return true;
        } catch (Throwable $e) {
            error_log('[MealsDB Clients Repository] Exception while deleting client: ' . $e->getMessage());
            return false;
        }
    }

    private function get_or_fetch_connection(): ?mysqli {
        if ($this->connection instanceof mysqli) {
            return $this->connection;
        }

        $this->connection = MealsDB_DB::get_connection();

        return $this->connection instanceof mysqli ? $this->connection : null;
    }

    /**
     * Determine bind_param types for provided values.
     *
     * @param array<int, mixed> $values
     */
    private function determine_types(array $values): string {
        $types = '';
        foreach ($values as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_bool($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        return $types;
    }

    /**
     * Build bind_param argument list.
     *
     * @param array<int, mixed> $values
     * @return array<int, mixed>|null
     */
    private function build_bind_params(string $types, array &$values): ?array {
        if ($types === '') {
            return null;
        }

        $params = [$types];
        foreach ($values as $index => &$value) {
            if (is_bool($value)) {
                $values[$index] = $value ? 1 : 0;
            }
            $params[] =& $values[$index];
        }

        return $params;
    }

    /**
     * Fetch all rows from a statement as associative arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetch_all_assoc(mysqli_stmt $stmt): array {
        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if ($result instanceof mysqli_result) {
                $rows = [];
                while ($row = $result->fetch_assoc()) {
                    if ($row === null) {
                        break;
                    }
                    $rows[] = $row;
                }
                $result->free();

                return $rows;
            }
        }

        if (!method_exists($stmt, 'result_metadata') || !method_exists($stmt, 'bind_result')) {
            return [];
        }

        $metadata = $stmt->result_metadata();
        if (!$metadata) {
            return [];
        }

        $fields = $metadata->fetch_fields();
        $metadata->free();

        if (empty($fields)) {
            return [];
        }

        $row = [];
        $bind_params = [];
        foreach ($fields as $field) {
            $row[$field->name] = null;
            $bind_params[] =& $row[$field->name];
        }

        if (!call_user_func_array([$stmt, 'bind_result'], $bind_params)) {
            return [];
        }

        $rows = [];
        while ($stmt->fetch()) {
            $row_copy = [];
            foreach ($row as $key => $value) {
                $row_copy[$key] = $value;
            }
            $rows[] = $row_copy;
        }

        return $rows;
    }
}
