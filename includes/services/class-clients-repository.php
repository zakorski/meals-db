<?php
/**
 * Repository for interacting with Meals DB client records.
 *
 * COLUMN NAME CONVENTION: This class uses DB-side column names
 * exclusively (e.g. `wp_user_id`, NOT `wordpress_user_id`; `client_phone_1`,
 * NOT `phone_primary`; `postal_code`, NOT `address_postal`). Callers must
 * convert form-side names to DB-side before calling. MealsDB_Client_Form::
 * map_form_to_db handles this for the standard form flow; direct callers
 * (sync, migration, backfill, AJAX handlers outside the form pipeline)
 * must convert manually.
 *
 * filter_to_known_columns detects and logs unknown column names but
 * cannot fix them — a logged warning indicates a caller bug. See CRIT-2
 * in the v1.0.346 audit (link_client_to_wp_user wrote 'wordpress_user_id',
 * got silently dropped, and the handler reported success while doing
 * nothing). See CLAUDE.md section "Form-side vs DB-side column names"
 * for the full mapping table.
 */

defined('ABSPATH') || exit;

class MealsDB_Clients_Repository {
    /**
     * @var string|null
     */
    private $table_name;

    /**
     * Track recent unknown-key warnings to avoid log flooding.
     *
     * If a buggy caller invokes update_client in a tight loop with the
     * wrong column names, naive logging would write thousands of entries
     * per minute. Dedupe by the unknown-key signature within this request;
     * each signature logs at most once per PHP process.
     *
     * @var array<string, true>
     */
    private static $logged_unknown_signatures = [];

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

        $client_type = $data['client_type'] ?? '';
        if (is_string($client_type) && $client_type !== '' && !self::is_recognised_client_type($client_type)) {
            error_log(sprintf(
                '[MealsDB Clients Repository] Rejected unknown client_type on create: %s',
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
     * Return the insert ID from the most recent create_client() call.
     */
    public function last_insert_id(): int {
        global $wpdb;
        return isset($wpdb->insert_id) ? (int) $wpdb->insert_id : 0;
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

        // Validate the new client_type if caller is trying to change it.
        if (isset($data['client_type']) && is_string($data['client_type']) && $data['client_type'] !== '') {
            if (!self::is_recognised_client_type($data['client_type'])) {
                error_log(sprintf(
                    '[MealsDB Clients Repository] Rejected unknown client_type on update: %s',
                    $data['client_type']
                ));
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
     * @param string|array<int,string>|null $client_type   Optional client type filter.
     *                                                     Pass an array to match multiple types via IN().
     * @param string|null                   $search        Optional search string that matches first or last name.
     * @param bool                          $show_inactive Whether inactive clients should be included in the results.
     * @param int                           $limit         Max rows per page. Hard-capped to 1000.
     * @param int                           $offset        Row offset. Clamped to 0.
     * @return array<int, array<string, string|null>>
     */
    public function search_clients($client_type = null, ?string $search = null, bool $show_inactive = false, int $limit = 100, int $offset = 0): array {
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

            $type_clause = self::build_client_type_clause($client_type);
            if ($type_clause !== null) {
                $conditions[] = $type_clause['sql'];
                foreach ($type_clause['args'] as $arg) {
                    $prepare_args[] = $arg;
                }
            }

            if ($search !== null && $search !== '') {
                // Cap the search needle. Real names cap out around 50
                // characters; an unbounded payload here is either an
                // input mistake or a probing attempt to inflate the
                // %needle% scan. The trailing wildcards on the LIKE
                // pattern already prevent any index from helping —
                // bounding the needle at least keeps memory and the
                // network round-trip predictable.
                $needle = function_exists('mb_substr')
                    ? mb_substr($search, 0, 100)
                    : substr($search, 0, 100);
                $conditions[] = '(LOWER(first_name) LIKE %s OR LOWER(last_name) LIKE %s OR LOWER(CONCAT(first_name, " ", last_name)) LIKE %s)';
                $like = '%' . $wpdb->esc_like(strtolower($needle)) . '%';
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
     *
     * @param string|array<int,string>|null $client_type
     */
    public function count_clients($client_type = null, ?string $search = null, bool $show_inactive = false): int {
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
            $type_clause = self::build_client_type_clause($client_type);
            if ($type_clause !== null) {
                $conditions[] = $type_clause['sql'];
                foreach ($type_clause['args'] as $arg) {
                    $prepare_args[] = $arg;
                }
            }
            if ($search !== null && $search !== '') {
                // Cap the search needle. Real names cap out around 50
                // characters; an unbounded payload here is either an
                // input mistake or a probing attempt to inflate the
                // %needle% scan. The trailing wildcards on the LIKE
                // pattern already prevent any index from helping —
                // bounding the needle at least keeps memory and the
                // network round-trip predictable.
                $needle = function_exists('mb_substr')
                    ? mb_substr($search, 0, 100)
                    : substr($search, 0, 100);
                $conditions[] = '(LOWER(first_name) LIKE %s OR LOWER(last_name) LIKE %s OR LOWER(CONCAT(first_name, " ", last_name)) LIKE %s)';
                $like = '%' . $wpdb->esc_like(strtolower($needle)) . '%';
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
     */
    private static function is_government_client(string $client_type): bool {
        return $client_type === 'SDNB' || $client_type === 'Veteran';
    }

    /**
     * Build the WHERE-clause fragment + prepared args for filtering by
     * client_type. Accepts a single string, an array of strings, or null.
     * Returns null when the filter should be omitted entirely.
     *
     * @param string|array<int,string>|null $client_type
     * @return array{sql:string, args:array<int,string>}|null
     */
    private static function build_client_type_clause($client_type): ?array {
        if ($client_type === null) {
            return null;
        }
        if (is_string($client_type)) {
            $client_type = trim($client_type);
            if ($client_type === '') {
                return null;
            }
            return [
                'sql'  => 'UPPER(client_type) = %s',
                'args' => [strtoupper($client_type)],
            ];
        }
        if (is_array($client_type)) {
            $types = [];
            foreach ($client_type as $t) {
                if (is_string($t) && trim($t) !== '') {
                    $types[] = strtoupper(trim($t));
                }
            }
            $types = array_values(array_unique($types));
            if (empty($types)) {
                return null;
            }
            $placeholders = implode(',', array_fill(0, count($types), '%s'));
            return [
                'sql'  => 'UPPER(client_type) IN (' . $placeholders . ')',
                'args' => $types,
            ];
        }
        return null;
    }

    /**
     * Client types the schema's ENUM accepts.
     */
    private static function is_recognised_client_type(string $client_type): bool {
        return in_array($client_type, ['SDNB', 'Veteran', 'Private'], true);
    }

    /**
     * Fetch the meals_clients row linked to a WordPress user, or null when
     * no record exists. Returns the raw row including encrypted PII blobs —
     * callers that render to the UI should decrypt via MealsDB_Encryption.
     *
     * @return array<string, mixed>|null
     */
    public static function get_by_wp_user_id(int $wp_user_id): ?array {
        global $wpdb;
        if ($wp_user_id <= 0 || !($wpdb instanceof wpdb)) {
            return null;
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $escaped = str_replace('`', '``', $table);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$escaped}` WHERE wp_user_id = %d LIMIT 1",
            $wp_user_id
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Return every wp_user_id already present in meals_clients so the
     * backfill can diff against the candidate set without N round-trips.
     *
     * @return int[]
     */
    public static function get_all_wp_user_ids(): array {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return [];
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $escaped = str_replace('`', '``', $table);

        $ids = $wpdb->get_col(
            "SELECT wp_user_id FROM `{$escaped}` WHERE wp_user_id > 0"
        );

        if (!is_array($ids)) {
            return [];
        }

        return array_map('intval', $ids);
    }

    /**
     * Static convenience for creating a client row, returning the new
     * client_id on success (0 on failure). Encrypts PII columns in the
     * same step so callers don't have to coordinate that separately.
     *
     * @param array<string, mixed> $data Raw column values keyed by DB column.
     *                                   PII columns named in
     *                                   MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS
     *                                   are encrypted before insert.
     */
    public static function create(array $data): int {
        if (empty($data)) {
            return 0;
        }

        // Encrypt sensitive columns if present. Inserts that don't touch
        // those columns pass through unchanged.
        try {
            $data = MealsDB_Encryption::encrypt_columns($data);
        } catch (\Throwable $e) {
            error_log('[MealsDB Clients Repository] Create aborted: encryption failure (' . $e->getMessage() . ').');
            return 0;
        }

        $repo = new self();
        if (!$repo->create_client($data)) {
            return 0;
        }

        return $repo->last_insert_id();
    }

    /**
     * Drop any keys from $data that are not declared in the canonical
     * meals_clients schema (or in the small set of related sidecar
     * columns the form pipeline emits, e.g. *_index hashes).
     *
     * Unknown keys are still dropped (preserving existing behavior),
     * but their names are logged with a caller-chain breadcrumb so
     * silent-drop bugs surface in the operational log instead of
     * hiding as zero-row updates. Values are NOT logged — they may
     * contain PII. Dedupe by signature within the request via
     * self::$logged_unknown_signatures so a tight buggy loop logs
     * once, not thousands.
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

        $unknown_keys = array_keys(array_diff_key($data, $allowed));
        if (!empty($unknown_keys) && class_exists('MealsDB_Logger')) {
            sort($unknown_keys);
            $signature = implode(',', $unknown_keys);
            if (!isset(self::$logged_unknown_signatures[$signature])) {
                self::$logged_unknown_signatures[$signature] = true;
                MealsDB_Logger::error(sprintf(
                    '[MealsDB Repository] filter_to_known_columns dropped unknown column(s): %s. Called from: %s',
                    $signature,
                    self::get_caller_info(3)
                ));
            }
        }

        return array_intersect_key($data, $allowed);
    }

    /**
     * Get a compact caller chain for logging.
     *
     * Returns up to $depth frames in the form
     *   "ClassName::method (file:line) <- ClassName::method (file:line)"
     * Skips this helper and the filter_to_known_columns frame so the
     * first segment is the immediate caller of filter_to_known_columns
     * (typically create_client / update_client).
     *
     * @param int $depth Max frames to include.
     * @return string
     */
    private static function get_caller_info(int $depth = 3): string {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $depth + 2);
        // Skip get_caller_info itself and filter_to_known_columns
        $frames = array_slice($frames, 2);

        $parts = [];
        foreach ($frames as $f) {
            $where = '';
            if (!empty($f['class']) && !empty($f['function'])) {
                $where = $f['class'] . '::' . $f['function'];
            } elseif (!empty($f['function'])) {
                $where = $f['function'];
            }

            $location = '';
            if (!empty($f['file']) && !empty($f['line'])) {
                $location = ' (' . basename($f['file']) . ':' . $f['line'] . ')';
            }

            if ($where !== '') {
                $parts[] = $where . $location;
            }
        }

        return $parts ? implode(' <- ', $parts) : '(unknown caller)';
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
