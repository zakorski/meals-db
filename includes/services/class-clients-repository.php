<?php
/**
 * Repository for interacting with Meals DB client records.
 */

defined('ABSPATH') || exit;

class MealsDB_Clients_Repository {
    /**
     * @var string|null
     */
    private $table_name;

    /**
     * Create a new repository instance.
     *
     * @param mixed $connection Ignored. Retained for backward compatibility.
     */
    public function __construct($connection = null) {
        $this->table_name = null;
        $this->ensure_table_name();
    }

    /**
     * Retrieve clients.
     *
     * Paginated — callers must specify a page size and (optionally) offset.
     * The previous signature returned the whole table, which is unsafe on
     * production sites with thousands of clients.
     *
     * @param int $limit  Max rows. Hard-capped at 1000 even if a higher
     *                    value is requested.
     * @param int $offset Row offset. Clamped to 0.
     * @return array<int, array<string, mixed>>
     */
    public function get_all_clients(int $limit = 200, int $offset = 0): array {
        global $wpdb;

        if (!$this->ensure_table_name()) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when fetching clients.');
            return [];
        }

        $limit  = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        try {
            // Explicit SELECT list — never SELECT *. The previous wildcard
            // returned encrypted PII columns and deterministic-hash sidecar
            // columns to every caller, none of which are needed by current
            // listing surfaces and which expand the blast radius of any
            // accidental data echo to the response.
            $columns = self::default_select_columns();
            $sql = $wpdb->prepare(
                sprintf('SELECT %s FROM `%s` ORDER BY client_id ASC LIMIT %%d OFFSET %%d', $columns, $this->escape_table_name()),
                $limit,
                $offset
            );
            $rows = $wpdb->get_results($sql, ARRAY_A);

            if ($rows === null) {
                error_log('[MealsDB Clients Repository] Failed to execute client list query: ' . ($wpdb->last_error ?: 'unknown error'));
                return [];
            }

            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            error_log('[MealsDB Clients Repository] Exception while fetching clients: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Non-PII columns safe to surface in list views. Keep narrow — encrypted
     * blobs and *_index hashes are intentionally absent.
     */
    private static function default_select_columns(): string {
        return 'client_id, first_name, last_name, client_type, active, '
             . 'wp_user_id, client_email, client_phone_1 AS phone_primary, '
             . 'street_name, city, province, postal_code, '
             . 'delivery_area_zone, delivery_area_name';
    }

    /**
     * Fetch a single client by ID.
     *
     * @param int $client_id Client primary key.
     * @return array<string, mixed>|null
     */
    public function get_client_by_id(int $client_id): ?array {
        global $wpdb;

        if (!$this->ensure_table_name()) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when fetching client by ID.');
            return null;
        }

        try {
            $sql = $wpdb->prepare(
                sprintf('SELECT * FROM `%s` WHERE client_id = %%d LIMIT 1', $this->escape_table_name()),
                $client_id
            );

            $row = $wpdb->get_row($sql, ARRAY_A);

            return is_array($row) ? $row : null;
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
        global $wpdb;

        if (!$this->ensure_table_name()) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when creating client.');
            return false;
        }

        if (empty($data)) {
            error_log('[MealsDB Clients Repository] Attempted to create client with no data.');
            return false;
        }

        // Type gate: Private clients are not stored in the external database
        $client_type = $data['client_type'] ?? '';
        if (is_string($client_type) && $client_type !== '' && !self::is_government_client($client_type)) {
            error_log(sprintf(
                '[MealsDB Clients Repository] Skipped external DB write for Private client (client_id=%s, type=%s).',
                $data['client_id'] ?? 'new',
                $client_type
            ));
            return false;
        }

        try {
            $result = $wpdb->insert($this->table_name, $data);

            if ($result === false) {
                error_log('[MealsDB Clients Repository] Failed to execute client insert: ' . ($wpdb->last_error ?: 'unknown error'));
                return false;
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
        global $wpdb;

        if ($client_id <= 0) {
            error_log('[MealsDB Clients Repository] Attempted to update client with invalid ID.');
            return false;
        }

        if (!$this->ensure_table_name()) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when updating client.');
            return false;
        }

        if (empty($data)) {
            error_log('[MealsDB Clients Repository] Attempted to update client with no data.');
            return false;
        }

        // Type gate: verify the existing record is not a Private client
        $existing = $this->get_client_by_id($client_id);
        if (is_array($existing)) {
            $existing_type = $existing['client_type'] ?? '';
            if ($existing_type !== '' && !self::is_government_client($existing_type)) {
                error_log(sprintf('[MealsDB Clients Repository] Skipped external DB update for Private client ID %d', $client_id));
                return false;
            }
        }

        // Whitelist incoming keys against the canonical schema. Caller
        // pre-filters today, but keep defence-in-depth so a future caller
        // that hands us an unfiltered payload can't sneak unrelated
        // columns (e.g. client_type, wp_user_id) into an UPDATE.
        $data = self::filter_to_known_columns($data);
        if (empty($data)) {
            error_log('[MealsDB Clients Repository] Update aborted: no recognised columns supplied.');
            return false;
        }

        try {
            $result = $wpdb->update($this->table_name, $data, ['client_id' => $client_id]);

            if ($result === false) {
                error_log('[MealsDB Clients Repository] Failed to execute client update: ' . ($wpdb->last_error ?: 'unknown error'));
                return false;
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
        global $wpdb;

        if ($client_id <= 0) {
            error_log('[MealsDB Clients Repository] Attempted to delete client with invalid ID.');
            return false;
        }

        if (!$this->ensure_table_name()) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when deleting client.');
            return false;
        }

        try {
            $result = $wpdb->delete($this->table_name, ['client_id' => $client_id]);

            if ($result === false) {
                error_log('[MealsDB Clients Repository] Failed to execute client delete: ' . ($wpdb->last_error ?: 'unknown error'));
                return false;
            }

            if ((int) $result < 1) {
                error_log('[MealsDB Clients Repository] Client delete affected 0 rows.');
                return false;
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
        global $wpdb;

        if (!$this->ensure_table_name()) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when fetching client types.');
            return [];
        }

        try {
            $sql = sprintf(
                'SELECT DISTINCT client_type FROM `%s` WHERE client_type <> "" ORDER BY client_type ASC',
                $this->escape_table_name()
            );

            $types = $wpdb->get_col($sql);

            return is_array($types) ? $types : [];
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
     * @param int         $limit         Max rows per page. Hard-capped to 1000.
     * @param int         $offset        Row offset. Clamped to 0.
     * @return array<int, array<string, string|null>>
     */
    public function search_clients(?string $client_type = null, ?string $search = null, bool $show_inactive = false, int $limit = 100, int $offset = 0): array {
        global $wpdb;

        if (!$this->ensure_table_name()) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when searching clients.');
            return [];
        }

        $limit  = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        try {
            $columns = ['client_id', 'client_id AS id', 'first_name', 'last_name', 'client_type', 'assigned_worker_name', 'assigned_worker_email', 'client_phone_1 AS phone_primary', 'client_email'];
            $has_active_column = $this->table_has_column('active');

            if ($has_active_column) {
                $columns[] = 'active';
            }

            $sql = sprintf('SELECT %s FROM `%s`', implode(', ', $columns), $this->escape_table_name());
            $conditions = [];
            $prepare_args = [];

            if (!$show_inactive && $has_active_column) {
                $conditions[] = 'active = 1';
            }

            if ($client_type !== null && $client_type !== '') {
                $conditions[] = 'UPPER(client_type) = %s';
                $prepare_args[] = strtoupper($client_type);
            }

            if ($search !== null && $search !== '') {
                $conditions[] = '(LOWER(first_name) LIKE %s OR LOWER(last_name) LIKE %s OR LOWER(CONCAT(first_name, " ", last_name)) LIKE %s)';
                $like = '%' . $wpdb->esc_like(strtolower($search)) . '%';
                $prepare_args[] = $like;
                $prepare_args[] = $like;
                $prepare_args[] = $like;
            }

            if (!empty($conditions)) {
                $sql .= ' WHERE ' . implode(' AND ', $conditions);
            }

            $sql .= ' ORDER BY last_name ASC, first_name ASC LIMIT %d OFFSET %d';
            $prepare_args[] = $limit;
            $prepare_args[] = $offset;

            $sql = $wpdb->prepare($sql, $prepare_args);

            if ($sql === false) {
                error_log('[MealsDB Clients Repository] Failed to prepare client search query.');
                return [];
            }

            $records = $wpdb->get_results($sql, ARRAY_A);

            return is_array($records) ? $records : [];
        } catch (Throwable $e) {
            error_log('[MealsDB Clients Repository] Exception while searching clients: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Count clients matching the same filters as search_clients() so the
     * view can render pagination totals without having to also materialise
     * every row.
     */
    public function count_clients(?string $client_type = null, ?string $search = null, bool $show_inactive = false): int {
        global $wpdb;

        if (!$this->ensure_table_name()) {
            return 0;
        }

        try {
            $sql          = sprintf('SELECT COUNT(*) FROM `%s`', $this->escape_table_name());
            $conditions   = [];
            $prepare_args = [];
            $has_active   = $this->table_has_column('active');

            if (!$show_inactive && $has_active) {
                $conditions[] = 'active = 1';
            }
            if ($client_type !== null && $client_type !== '') {
                $conditions[]   = 'UPPER(client_type) = %s';
                $prepare_args[] = strtoupper($client_type);
            }
            if ($search !== null && $search !== '') {
                $conditions[] = '(LOWER(first_name) LIKE %s OR LOWER(last_name) LIKE %s OR LOWER(CONCAT(first_name, " ", last_name)) LIKE %s)';
                $like = '%' . $wpdb->esc_like(strtolower($search)) . '%';
                $prepare_args[] = $like;
                $prepare_args[] = $like;
                $prepare_args[] = $like;
            }
            if (!empty($conditions)) {
                $sql .= ' WHERE ' . implode(' AND ', $conditions);
            }

            if (!empty($prepare_args)) {
                $sql = $wpdb->prepare($sql, $prepare_args);
            }

            return (int) $wpdb->get_var($sql);
        } catch (Throwable $e) {
            error_log('[MealsDB Clients Repository] Exception while counting clients: ' . $e->getMessage());
            return 0;
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
        global $wpdb;

        if (!$this->ensure_table_name()) {
            error_log('[MealsDB Clients Repository] Database connection unavailable when checking unique fields.');
            return false;
        }

        try {
            $escaped_column = str_replace('`', '``', $column);

            if ($exclude_id !== null) {
                $sql = $wpdb->prepare(
                    sprintf('SELECT client_id FROM `%s` WHERE `%s` = %%s AND client_id <> %%d LIMIT 1', $this->escape_table_name(), $escaped_column),
                    $value,
                    $exclude_id
                );
            } else {
                $sql = $wpdb->prepare(
                    sprintf('SELECT client_id FROM `%s` WHERE `%s` = %%s LIMIT 1', $this->escape_table_name(), $escaped_column),
                    $value
                );
            }

            $result = $wpdb->get_var($sql);

            return $result !== null;
        } catch (Throwable $e) {
            error_log('[MealsDB Clients Repository] Exception while checking unique field for column ' . $column . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a client type is a government client (SDNB or Veteran).
     *
     * Only government clients are stored in the external encrypted database.
     */
    private static function is_government_client(string $client_type): bool {
        return $client_type === 'SDNB' || $client_type === 'Veteran';
    }

    /**
     * Drop any keys from $data that are not declared in the canonical
     * meals_clients schema (or in the small set of related sidecar
     * columns the form pipeline emits, e.g. *_index hashes).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function filter_to_known_columns(array $data): array {
        static $allowed = null;
        if ($allowed === null) {
            $allowed = [];
            if (class_exists('MealsDB_Schema')) {
                $schema = MealsDB_Schema::get_table_schema(MealsDB_Tables::CLIENTS);
                if (is_array($schema) && isset($schema['columns']) && is_array($schema['columns'])) {
                    $allowed = array_fill_keys(array_keys($schema['columns']), true);
                }
            }
        }

        if (empty($allowed)) {
            // Schema lookup failed — return as-is rather than silently
            // dropping every column. The wpdb->update call still goes
            // through wpdb's own escaping.
            return $data;
        }

        return array_intersect_key($data, $allowed);
    }

    /**
     * Get the sanitized table name for meals clients.
     */
    private function escape_table_name(): string {
        return str_replace('`', '``', (string) $this->table_name);
    }

    private function ensure_table_name(): bool {
        if ($this->table_name !== null) {
            return true;
        }

        $resolved = $this->resolve_client_table();
        if ($resolved === null) {
            error_log('[MealsDB Clients Repository] ' . MealsDB_Tables::CLIENTS . ' table is missing; cannot continue.');
            return false;
        }

        $this->table_name = $resolved;

        return true;
    }

    private function resolve_client_table(): ?string {
        $prefixed = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        if ($this->table_exists($prefixed)) {
            return $prefixed;
        }

        $unprefixed = MealsDB_Tables::CLIENTS;
        if ($prefixed !== $unprefixed && $this->table_exists($unprefixed)) {
            return $unprefixed;
        }

        return null;
    }

    private function table_has_column(string $column): bool {
        global $wpdb;

        if (!$this->table_exists((string) $this->table_name)) {
            return false;
        }

        // INFORMATION_SCHEMA with both identifiers bound as %s. The
        // previous SHOW COLUMNS … LIKE path let LIKE wildcards in the
        // column name influence match semantics. Column existence is
        // stable across a request, so the result is cached per
        // (table, column).
        static $cache = [];
        $key = (string) $this->table_name . "\0" . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND COLUMN_NAME = %s
             LIMIT 1",
            (string) $this->table_name,
            $column
        ));

        return $cache[$key] = ($found !== null);
    }

    private function table_exists(string $table_name): bool {
        global $wpdb;

        $result = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s LIMIT 1',
                $table_name
            )
        );

        return $result !== null;
    }
}
