<?php
/**
 * Repository for interacting with Meals DB client records.
 */

class MealsDB_Clients_Repository {
    /**
     * @var mysqli|null
     */
    private $connection;

    /**
     * @var string|null
     */
    private $table_name;

    /**
     * Create a new repository instance.
     *
     * @param mysqli|null $connection Optional mysqli connection to reuse.
     */
    public function __construct($connection = null) {
        if (MealsDB_DB::is_mysqli($connection)) {
            $this->connection = $connection;
            $this->ensure_table_name($connection);
        } else {
            $this->connection = null;
            $this->table_name = null;
        }
    }

    /**
     * Retrieve all clients.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_all_clients(): array {
        $conn = $this->get_or_fetch_connection();
        if (!$conn || !$this->ensure_table_name($conn)) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when fetching clients.');
            return [];
        }

        try {
            $sql = sprintf('SELECT * FROM `%s`', $this->escape_table_name());
            $stmt = $conn->prepare($sql);
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
     *
     * @param int $client_id Client primary key.
     * @return array<string, mixed>|null
     */
    public function get_client_by_id(int $client_id): ?array {
        $conn = $this->get_or_fetch_connection();
        if (!$conn || !$this->ensure_table_name($conn)) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when fetching client by ID.');
            return null;
        }

        try {
            $sql = sprintf('SELECT * FROM `%s` WHERE client_id = ? LIMIT 1', $this->escape_table_name());
            $stmt = $conn->prepare($sql);
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
     *
     * @param array<string, mixed> $data Column values to insert.
     */
    public function create_client(array $data): bool {
        $conn = $this->get_or_fetch_connection();
        if (!$conn || !$this->ensure_table_name($conn)) {
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
            $sql = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $this->escape_table_name(), $column_list, $placeholders);

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
     *
     * @param int                  $client_id Client primary key.
     * @param array<string, mixed> $data      Column values to update.
     */
    public function update_client(int $client_id, array $data): bool {
        if ($client_id <= 0) {
            error_log('[MealsDB Clients Repository] Attempted to update client with invalid ID.');
            return false;
        }

        $conn = $this->get_or_fetch_connection();
        if (!$conn || !$this->ensure_table_name($conn)) {
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

            $sql = sprintf('UPDATE `%s` SET %s WHERE client_id = ? LIMIT 1', $this->escape_table_name(), implode(', ', $set_parts));
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
     *
     * @param int $client_id Client primary key.
     */
    public function delete_client(int $client_id): bool {
        if ($client_id <= 0) {
            error_log('[MealsDB Clients Repository] Attempted to delete client with invalid ID.');
            return false;
        }

        $conn = $this->get_or_fetch_connection();
        if (!$conn || !$this->ensure_table_name($conn)) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when deleting client.');
            return false;
        }

        try {
            $sql = sprintf('DELETE FROM `%s` WHERE client_id = ?', $this->escape_table_name());
            $stmt = $conn->prepare($sql);
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

    /**
     * Retrieve distinct client types.
     *
     * @return string[]
     */
    public function get_client_types(): array {
        $conn = $this->get_or_fetch_connection();
        if (!$conn || !$this->ensure_table_name($conn)) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when fetching client types.');
            return [];
        }

        try {
            $sql = sprintf(
                'SELECT DISTINCT client_type FROM `%s` WHERE client_type <> "" ORDER BY client_type ASC',
                $this->escape_table_name()
            );

            $result = $conn->query($sql);
            if (!MealsDB_DB::is_mysqli_result($result)) {
                return [];
            }

            $types = [];
            while ($row = $result->fetch_assoc()) {
                if ($row === null) {
                    break;
                }
                $types[] = $row['client_type'];
            }

            $result->free();

            return $types;
        } catch (Throwable $e) {
            error_log('[MealsDB Clients Repository] Exception while fetching client types: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Search clients with optional filters.
     *
     * @param string|null $client_type   Optional client type filter.
     * @param string|null $search        Optional search string that matches first or last name.
     * @param bool        $show_inactive Whether inactive clients should be included in the results.
     * @return array<int, array<string, string|null>>
     */
    public function search_clients(?string $client_type = null, ?string $search = null, bool $show_inactive = false): array {
        $conn = $this->get_or_fetch_connection();
        if (!$conn || !$this->ensure_table_name($conn)) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when searching clients.');
            return [];
        }

        try {
            $columns = ['client_id', 'client_id AS id', 'first_name', 'last_name', 'client_type', 'client_phone_1 AS phone_primary', 'client_email'];
            $has_active_column = $this->table_has_column($conn, 'active');

            if ($has_active_column) {
                $columns[] = 'active';
            }

            $sql = sprintf('SELECT %s FROM `%s`', implode(', ', $columns), $this->escape_table_name());
            $conditions = [];
            $types = '';
            $params = [];

            if (!$show_inactive && $has_active_column) {
                $conditions[] = 'active = 1';
            }

            if ($client_type !== null && $client_type !== '') {
                $conditions[] = 'UPPER(client_type) = ?';
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
                error_log('[MealsDB Clients Repository] Failed to prepare client search query: ' . ($conn->error ?? 'unknown error'));
                return [];
            }

            if (!empty($params)) {
                $bind_params = [$types];
                foreach ($params as $index => $value) {
                    $bind_params[] =& $params[$index];
                }

                if (call_user_func_array([$stmt, 'bind_param'], $bind_params) === false) {
                    error_log('[MealsDB Clients Repository] Failed to bind parameters for client search query.');
                    $stmt->close();
                    return [];
                }
            }

            if (!$stmt->execute()) {
                error_log('[MealsDB Clients Repository] Failed to execute client search query: ' . ($stmt->error ?? 'unknown error'));
                $stmt->close();
                return [];
            }

            $records = [];
            $result = $stmt->get_result();
            if (MealsDB_DB::is_mysqli_result($result)) {
                while ($row = $result->fetch_assoc()) {
                    $records[] = $row;
                }
            }

            $stmt->close();

            return $records;
        } catch (Throwable $e) {
            error_log('[MealsDB Clients Repository] Exception while searching clients: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Determine whether a given column contains a matching value.
     *
     * @param string   $column      Column name to check.
     * @param mixed    $value       Value to search for.
     * @param int|null $exclude_id  Optional client ID to exclude from the check.
     * @return bool True if a match exists, false otherwise.
     */
    public function column_value_exists(string $column, $value, ?int $exclude_id = null): bool {
        $conn = $this->get_or_fetch_connection();
        if (!$conn || !$this->ensure_table_name($conn)) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when checking unique fields.');
            return false;
        }

        try {
            $escaped_column = str_replace('`', '``', $column);
            $sql = sprintf('SELECT client_id FROM `%s` WHERE `%s` = ?', $this->escape_table_name(), $escaped_column);
            if ($exclude_id !== null) {
                $sql .= ' AND client_id <> ?';
            }
            $sql .= ' LIMIT 1';

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log('[MealsDB Clients Repository] Failed to prepare unique field check for column ' . $column . ': ' . ($conn->error ?? 'unknown error'));
                return false;
            }

            if ($exclude_id !== null) {
                if (!$stmt->bind_param('si', $value, $exclude_id)) {
                    error_log('[MealsDB Clients Repository] Failed to bind parameters for unique field check on column ' . $column . '.');
                    $stmt->close();
                    return false;
                }
            } elseif (!$stmt->bind_param('s', $value)) {
                error_log('[MealsDB Clients Repository] Failed to bind parameter for unique field check on column ' . $column . '.');
                $stmt->close();
                return false;
            }

            if (!$stmt->execute()) {
                error_log('[MealsDB Clients Repository] Failed to execute unique field check for column ' . $column . ': ' . ($stmt->error ?? 'unknown error'));
                $stmt->close();
                return false;
            }

            if (method_exists($stmt, 'store_result')) {
                $stmt->store_result();
            }

            $exists = $stmt->num_rows > 0;
            $stmt->close();

            return $exists;
        } catch (Throwable $e) {
            error_log('[MealsDB Clients Repository] Exception while checking unique field for column ' . $column . ': ' . $e->getMessage());
            return false;
        }
    }

    private function get_or_fetch_connection() {
        if (MealsDB_DB::is_mysqli($this->connection)) {
            return $this->connection;
        }

        $this->connection = MealsDB_DB::get_connection();

        if (!MealsDB_DB::is_mysqli($this->connection)) {
            return null;
        }

        if (!$this->ensure_table_name($this->connection)) {
            return null;
        }

        return $this->connection;
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
     * Get the sanitized table name for meals clients.
     */
    private function escape_table_name(): string {
        return str_replace('`', '``', (string) $this->table_name);
    }

    /**
     * Fetch all rows from a statement as associative arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetch_all_assoc($stmt): array {
        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if (MealsDB_DB::is_mysqli_result($result)) {
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

    private function ensure_table_name($conn): bool {
        if ($this->table_name !== null) {
            return true;
        }

        $resolved = $this->resolve_client_table($conn);
        if ($resolved === null) {
            error_log('[MealsDB Clients Repository] ' . MealsDB_Tables::CLIENTS . ' table is missing; cannot continue.');
            return false;
        }

        $this->table_name = $resolved;

        return true;
    }

    private function resolve_client_table($conn): ?string {
        $prefixed = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        if ($this->table_exists($conn, $prefixed)) {
            return $prefixed;
        }

        $unprefixed = MealsDB_Tables::CLIENTS;
        if ($prefixed !== $unprefixed && $this->table_exists($conn, $unprefixed)) {
            return $unprefixed;
        }

        return null;
    }

    private function table_has_column($conn, string $column): bool {
        if (!$this->table_exists($conn, (string) $this->table_name)) {
            return false;
        }

        $escaped_table = str_replace('`', '``', (string) $this->table_name);
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

    private function table_exists($conn, string $table_name): bool {
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
