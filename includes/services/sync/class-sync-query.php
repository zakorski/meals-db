<?php
/**
 * Provides data retrieval methods used during Meals DB synchronization workflows.
 */

defined('ABSPATH') || exit;

class MealsDB_Sync_Query {
    /**
     * Active wpdb connection, when available.
     */
    private ?wpdb $connection;

    /**
     * Primary key column name for the Meals DB clients table, when available.
     */
    private ?string $clients_primary_key;

    /**
     * Cache of discovered column names keyed by table.
     *
     * @var array<string, array<string, bool>>
     */
    private array $column_cache = [];

    public function __construct() {
        global $wpdb;
        $this->connection = $wpdb instanceof wpdb ? $wpdb : null;
        $this->clients_primary_key = MealsDB_Schema::get_primary_key_column(MealsDB_Tables::CLIENTS);
    }

    /**
     * Retrieve WordPress user records that participate in synchronization.
     *
     * @return array<int, WP_User> List of WordPress users.
     */
    public function get_wp_users(): array {
        $users = $this->batched_query(
            static function (int $batch_size, int $page, int $offset): array {
                unset($offset);

                $results = get_users([
                    'fields' => 'all_with_meta',
                    'number' => $batch_size,
                    'paged'  => $page,
                ]);

                return is_array($results) ? $results : [];
            }
        );

        $valid_users = [];

        foreach ($users as $user) {
            if ($user instanceof WP_User) {
                $valid_users[] = $user;
            }
        }

        return $valid_users;
    }

    /**
     * Retrieve Meals DB client records required for synchronization.
     *
     * @return array{by_wp_id: array<int, array<int, array<string, mixed>>>, without_wp_id: array<int, array<string, mixed>>}|WP_Error
     */
    public function get_meals_clients() {
        $connection = $this->require_connection();

        if (is_wp_error($connection)) {
            return $connection;
        }

        $query_error = null;

        // Keyset pagination on client_id. Switched from OFFSET because a
        // concurrent INSERT (admin saving a client form during a sync)
        // would shift every subsequent page's window and either skip
        // or duplicate rows depending on the direction of the shift.
        // client_id is the PK so every row is guaranteed to have a
        // unique, monotonically-assigned key — perfect for cursoring.
        $clients = $this->keyset_batched_query(
            function (int $batch_size, $last_key) use ($connection, &$query_error): array {
                $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
                $available_columns = $this->get_table_columns($clients_table);
                $column_map = $this->build_client_column_map($available_columns);

                if (empty($column_map)) {
                    $query_error = new WP_Error(
                        'mealsdb_missing_columns',
                        __('No compatible Meals DB columns were found for comparison.', 'meals-db')
                    );

                    return [];
                }

                // client_id must always be in the SELECT list so the
                // extractor below can read the cursor value. Force it
                // in if the column map didn't include it.
                if (!isset($column_map['client_id'])) {
                    $column_map = ['client_id' => 'client_id'] + $column_map;
                }

                $quoted_columns = [];
                foreach ($column_map as $column => $alias) {
                    $escaped_column = str_replace('`', '``', $column);
                    $escaped_alias  = str_replace('`', '``', $alias);
                    if ($escaped_column === $escaped_alias) {
                        $quoted_columns[] = sprintf('`%s`', $escaped_column);
                    } else {
                        $quoted_columns[] = sprintf('`%s` AS `%s`', $escaped_column, $escaped_alias);
                    }
                }

                $escaped_table = str_replace('`', '``', $clients_table);
                $cursor = $last_key === null ? 0 : (int) $last_key;
                $sql = $connection->prepare(
                    sprintf(
                        "SELECT %s FROM `%s`
                         WHERE client_type IN ('SDNB', 'Veteran')
                           AND client_id > %%d
                         ORDER BY client_id ASC
                         LIMIT %%d",
                        implode(', ', $quoted_columns),
                        $escaped_table
                    ),
                    $cursor,
                    $batch_size
                );

                $rows = $connection->get_results($sql, ARRAY_A);

                if (!is_array($rows)) {
                    $message = $connection->last_error ?: __('Unknown database error.', 'meals-db');
                    error_log('[MealsDB Sync] Failed to fetch Meals DB records: ' . $message);
                    $query_error = new WP_Error(
                        'mealsdb_query_failed',
                        sprintf(
                            /* translators: %s: database error message */
                            __('Failed to retrieve Meals DB records: %s', 'meals-db'),
                            $message
                        )
                    );

                    return [];
                }

                return $rows;
            },
            // Extract the cursor from the last row of each batch.
            // Returns 0 rather than null for a missing / zero client_id
            // so the loop doesn't infinite-spin on a malformed row.
            static function (array $row) {
                $id = isset($row['client_id']) ? (int) $row['client_id'] : 0;
                return $id > 0 ? $id : 0;
            }
        );

        if ($query_error instanceof WP_Error) {
            return $query_error;
        }

        $clients_by_wp_id = [];
        $clients_without_id = [];

        foreach ($clients as $client) {
            $normalized = $this->normalize_client_row($client);

            if ($normalized['wordpress_user_id'] > 0) {
                $clients_by_wp_id[$normalized['wordpress_user_id']][] = $normalized;
            } else {
                $clients_without_id[] = $normalized;
            }
        }

        return [
            'by_wp_id'      => $clients_by_wp_id,
            'without_wp_id' => $clients_without_id,
        ];
    }

    /**
     * Retrieve conflict definitions that should be ignored during comparison.
     *
     * @return array<string, bool>|WP_Error
     */
    public function get_ignored_conflicts() {
        $connection = $this->require_connection();

        if (is_wp_error($connection)) {
            return $connection;
        }

        $ignored = [];

        $table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::IGNORED_CONFLICTS));
        $sql   = sprintf('SELECT field_name, source_value, target_value FROM `%s`', $table);

        $results = $connection->get_results($sql, ARRAY_A);

        if (is_array($results)) {
            foreach ($results as $row) {
                $field  = $this->sanitize_ignore_value($row['field_name'] ?? '');
                $source = $this->sanitize_ignore_value($row['source_value'] ?? '');
                $target = $this->sanitize_ignore_value($row['target_value'] ?? '');
                $ignored[$this->build_ignore_key($field, $source, $target)] = true;
            }
        } else {
            error_log('[MealsDB Sync] Failed to execute ignored conflicts query: ' . ($connection->last_error ?? 'unknown error'));
        }

        return $ignored;
    }

    /**
     * Retrieve Meals DB or WordPress draft records that affect synchronization decisions.
     *
     * @return array<int, array<string, mixed>> List of drafts considered during synchronization.
     */
    public function get_drafts(): array {
        return [];
    }

    /**
     * Load a lookup map of WordPress user IDs that are linked to staff records.
     *
     * @return array<int, bool>|WP_Error
     */
    public function get_staff_wordpress_ids() {
        $connection = $this->require_connection();

        if (is_wp_error($connection)) {
            return $connection;
        }

        $staff_ids = [];
        $table_name = MealsDB_DB::get_table_name(MealsDB_Tables::STAFF);
        $available_columns = $this->get_table_columns($table_name);
        $wp_column = $this->choose_column(['wordpress_user_id', 'wp_user_id'], $available_columns);

        if ($wp_column === null) {
            return $staff_ids;
        }

        $escaped_table = str_replace('`', '``', $table_name);
        $escaped_column = str_replace('`', '``', $wp_column);
        $table        = '`' . $escaped_table . '`';

        $sql = "SELECT `{$escaped_column}` AS wordpress_user_id FROM {$table} WHERE `{$escaped_column}` IS NOT NULL AND `{$escaped_column}` > 0";
        $results = $connection->get_results($sql, ARRAY_A);

        if (is_array($results)) {
            foreach ($results as $row) {
                $wp_id_raw = $row['wordpress_user_id'] ?? null;

                if (is_numeric($wp_id_raw)) {
                    $wp_id = (int) $wp_id_raw;

                    if ($wp_id > 0) {
                        $staff_ids[$wp_id] = true;
                    }
                }
            }
        }

        return $staff_ids;
    }

    /**
     * Find candidate WooCommerce customers that may match a given Meals DB client
     * based on first name, last name, and phone number.
     *
     * @param array $meals_client Row from meals_clients including at least first_name, last_name, phone_primary, and wordpress_user_id.
     * @return array<int,array<string,mixed>>|WP_Error
     */
    public function find_candidate_wc_matches_for_client(array $meals_client) {
        if (!empty($meals_client['wordpress_user_id'])) {
            return [];
        }

        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            return [];
        }

        $first_name = isset($meals_client['first_name']) ? (string) $meals_client['first_name'] : '';
        $last_name  = isset($meals_client['last_name']) ? (string) $meals_client['last_name'] : '';
        $phone_raw  = isset($meals_client['phone_primary']) ? (string) $meals_client['phone_primary'] : '';

        // Request-scoped memoisation. The dashboard render loop calls
        // this once per unmatched mismatch, and identical (name, phone)
        // tuples are common when multiple meals_clients share a
        // household. The underlying query is  LIKE %needle%  against
        // wp_usermeta, which cannot use an index — caching is the
        // highest-leverage improvement without a schema change.
        //
        // Bound the cache to keep an unusually long render loop (or a
        // long-running CLI consumer) from accumulating one entry per
        // distinct (name, phone) tuple in memory until the request
        // ends. 1024 entries comfortably covers a full sync dashboard
        // with thousands of clients while capping the worst-case
        // resident set. When the cap is reached the oldest half is
        // dropped — cheaper than a true LRU, and "old" entries are
        // also the least likely to recur because the dashboard walks
        // mismatches in stable order.
        static $cache = [];
        $cache_max = 1024;
        $cache_key = md5($first_name . '|' . $last_name . '|' . $phone_raw);
        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }
        if (count($cache) >= $cache_max) {
            $cache = array_slice($cache, intdiv($cache_max, 2), null, true);
        }

        // Phone normalisation: strip everything but digits, drop a
        // leading '1' that's just the NANP country code, and keep the
        // last 10 digits at most. Without these steps:
        //   - "+1 (506) 555-0100" and "5065550100" wouldn't match,
        //   - extensions ("...x123") would tail-pollute the LIKE
        //     comparison and produce false negatives,
        //   - international numbers with longer country codes still
        //     compare against their last 10 digits (best effort).
        $normalized_phone = preg_replace('/\D+/', '', $phone_raw);
        if ($normalized_phone !== null && strlen($normalized_phone) === 11 && strpos($normalized_phone, '1') === 0) {
            $normalized_phone = substr($normalized_phone, 1);
        }
        if ($normalized_phone !== null && strlen($normalized_phone) > 10) {
            $normalized_phone = substr($normalized_phone, -10);
        }

        $conditions = [];
        $params     = [];

        if ($first_name !== '' || $last_name !== '') {
            $first_like = '%' . $wpdb->esc_like(strtolower($first_name)) . '%';
            $last_like  = '%' . $wpdb->esc_like(strtolower($last_name)) . '%';

            if ($first_name !== '' && $last_name !== '') {
                $conditions[] = '((LOWER(IFNULL(um_first.meta_value, "")) LIKE %s) AND (LOWER(IFNULL(um_last.meta_value, "")) LIKE %s))';
                $params[]     = $first_like;
                $params[]     = $last_like;
            } elseif ($first_name !== '') {
                $conditions[] = '(LOWER(IFNULL(um_first.meta_value, "")) LIKE %s)';
                $params[]     = $first_like;
            } elseif ($last_name !== '') {
                $conditions[] = '(LOWER(IFNULL(um_last.meta_value, "")) LIKE %s)';
                $params[]     = $last_like;
            }
        }

        if ($normalized_phone !== '') {
            $phone_like = '%' . $wpdb->esc_like($normalized_phone) . '%';
            $conditions[] = 'um_phone.meta_value LIKE %s';
            $params[]     = $phone_like;
        }

        if (empty($conditions)) {
            $cache[$cache_key] = [];
            return [];
        }

        $users_table = $wpdb->users;
        $meta_table  = $wpdb->usermeta;

        $sql = "
            SELECT
                u.ID AS user_id,
                um_first.meta_value AS first_name,
                um_last.meta_value  AS last_name,
                u.user_email AS email,
                um_phone.meta_value AS billing_phone
            FROM {$users_table} AS u
            LEFT JOIN {$meta_table} AS um_first ON (um_first.user_id = u.ID AND um_first.meta_key = 'first_name')
            LEFT JOIN {$meta_table} AS um_last ON (um_last.user_id = u.ID AND um_last.meta_key = 'last_name')
            LEFT JOIN {$meta_table} AS um_phone ON (um_phone.user_id = u.ID AND um_phone.meta_key = 'billing_phone')
            WHERE (
                " . implode(' OR ', $conditions) . "
            )
            LIMIT 20
        ";

        $prepared = $wpdb->prepare($sql, $params);

        if ($prepared === false) {
            return [];
        }

        $results = $wpdb->get_results($prepared, ARRAY_A);

        if (!is_array($results)) {
            return [];
        }

        $candidates = [];
        foreach ($results as $row) {
            $candidate_phone = isset($row['billing_phone']) ? preg_replace('/\D+/', '', (string) $row['billing_phone']) : '';

            if ($normalized_phone !== '' && $candidate_phone !== '') {
                $compare_length = min(7, strlen($normalized_phone));
                $target_tail    = substr($normalized_phone, -$compare_length);
                $candidate_tail = substr($candidate_phone, -$compare_length);

                if ($target_tail === '' || $target_tail !== $candidate_tail) {
                    continue;
                }
            }

            $candidates[] = [
                'user_id'            => isset($row['user_id']) ? (int) $row['user_id'] : 0,
                'first_name'         => (string) ($row['first_name'] ?? ''),
                'last_name'          => (string) ($row['last_name'] ?? ''),
                'email'              => (string) ($row['email'] ?? ''),
                'billing_phone'      => (string) ($row['billing_phone'] ?? ''),
            ];
        }

        $cache[$cache_key] = $candidates;
        return $candidates;
    }

    /**
     * Execute batched callbacks until the dataset is fully retrieved.
     *
     * @param callable $callback   Callback invoked with (int $batch_size, int $page, int $offset).
     * @param int      $batch_size Number of records to request on each iteration.
     *
     * @return array<int, mixed> Concatenated results from all batches.
     */
    private function batched_query(callable $callback, int $batch_size = 500): array {
        $results = [];
        $page    = 1;
        $offset  = 0;

        while (true) {
            $batch = $callback($batch_size, $page, $offset);

            if (!is_array($batch) || $batch === []) {
                break;
            }

            $results = array_merge($results, $batch);

            if (count($batch) < $batch_size) {
                break;
            }

            $page++;
            $offset += $batch_size;
        }

        return $results;
    }

    /**
     * Keyset-paginated batched query.
     *
     * Calls $callback repeatedly with ($batch_size, $last_seen_key),
     * expecting an array of rows sorted ascending by the callback's
     * key column. Extracts the next cursor via $key_extractor on the
     * last row of each batch; iterates until a short batch arrives.
     *
     * Preferred over the offset variant above when:
     *
     *   - The underlying table can change under us while we iterate
     *     (OFFSET pagination silently skips or duplicates rows when
     *     the row count shifts mid-walk).
     *   - The table is large enough that deep OFFSETs matter for
     *     performance — MySQL still reads and discards OFFSET rows
     *     even when an index is in play.
     *
     * The callback must emit rows in key-ascending order AND include
     * the key column in the returned row; the extractor uses that
     * column value as the next cursor. A callback that returns rows
     * out of order or without the key column will silently re-fetch
     * the same batch forever — not a safety issue but a hang.
     *
     * @param callable(int $batch_size, int|string|null $last_key): array $callback
     * @param callable(array $row): (int|string)                          $key_extractor
     */
    private function keyset_batched_query(callable $callback, callable $key_extractor, int $batch_size = 500): array {
        $results  = [];
        $last_key = null;

        while (true) {
            $batch = $callback($batch_size, $last_key);
            if (!is_array($batch) || $batch === []) {
                break;
            }

            $results = array_merge($results, $batch);
            $last_row = end($batch);
            $last_key = $last_row !== false ? $key_extractor($last_row) : null;

            if (count($batch) < $batch_size || $last_key === null) {
                break;
            }
        }

        return $results;
    }

    /**
     * Ensure a wpdb connection is available.
     *
     * @return wpdb|WP_Error
     */
    private function require_connection() {
        if ($this->connection instanceof wpdb) {
            return $this->connection;
        }

        global $wpdb;

        if ($wpdb instanceof wpdb) {
            $this->connection = $wpdb;
            return $this->connection;
        }

        return new WP_Error(
            'mealsdb_db_connection_failed',
            __('Unable to connect to the Meals DB database. Please try again later.', 'meals-db')
        );
    }

    /**
     * Normalize a Meals DB client record for comparison.
     *
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function normalize_client_row(array $client): array {
        $client_id_raw = $client['client_id'] ?? 0;
        $client_id = is_numeric($client_id_raw) ? (int) $client_id_raw : 0;

        $wp_id_raw = $client['wordpress_user_id'] ?? 0;
        $wp_id = is_numeric($wp_id_raw) ? (int) $wp_id_raw : 0;

        if ($wp_id < 0) {
            $wp_id = 0;
        }

        return [
            'client_id'         => $client_id,
            'first_name'        => isset($client['first_name']) ? (string) $client['first_name'] : '',
            'last_name'         => isset($client['last_name']) ? (string) $client['last_name'] : '',
            'client_email'      => isset($client['client_email']) ? (string) $client['client_email'] : '',
            'phone_primary'     => isset($client['phone_primary']) ? (string) $client['phone_primary'] : '',
            'wordpress_user_id' => $wp_id,
        ];
    }

    /**
     * Normalize ignore values before hashing.
     *
     * @param mixed $value
     * @return string
     */
    private function sanitize_ignore_value($value): string {
        if (!is_scalar($value)) {
            $value = '';
        }

        $value = (string) $value;

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        return trim($value);
    }

    /**
     * Build the lookup key used for ignored conflicts.
     */
    private function build_ignore_key(string $field, string $source, string $target): string {
        return md5($field . '|' . $source . '|' . $target);
    }

    /**
     * Build a safe mapping of Meals DB columns to aliases for identity comparison.
     *
     * @param array<string, bool> $available_columns
     * @return array<string, string> Map of column name => alias.
     */
    private function build_client_column_map(array $available_columns): array {
        $column_map = [];

        $primary_column = $this->choose_column([$this->clients_primary_key, 'client_id', 'id'], $available_columns);
        if ($primary_column !== null) {
            $column_map[$primary_column] = 'client_id';
        }

        $wp_column = $this->choose_column(['wordpress_user_id', 'wp_user_id'], $available_columns);
        if ($wp_column !== null) {
            $column_map[$wp_column] = 'wordpress_user_id';
        }

        $identity_fields = [
            'first_name'    => ['first_name'],
            'last_name'     => ['last_name'],
            'client_email'  => ['client_email'],
            'phone_primary' => ['phone_primary', 'client_phone_1', 'phone'],
        ];

        foreach ($identity_fields as $alias => $candidates) {
            $column = $this->choose_column($candidates, $available_columns);

            if ($column !== null) {
                $column_map[$column] = $alias;
            }
        }

        return $column_map;
    }

    /**
     * Select the first available column from the provided candidates.
     *
     * @param array<int, string|null> $candidates
     * @param array<string, bool>     $available_columns
     */
    private function choose_column(array $candidates, array $available_columns): ?string {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && isset($available_columns[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Retrieve and cache the available columns for a table.
     *
     * @return array<string, bool>
     */
    private function get_table_columns(string $table): array {
        if (isset($this->column_cache[$table])) {
            return $this->column_cache[$table];
        }

        $connection = $this->connection;
        $columns = [];

        if (!$connection instanceof wpdb) {
            $this->column_cache[$table] = $columns;
            return $columns;
        }

        $escaped_table = str_replace('`', '``', $table);
        $sql = sprintf('SHOW COLUMNS FROM `%s`', $escaped_table);
        $results = $connection->get_results($sql, ARRAY_A);

        if (is_array($results)) {
            foreach ($results as $row) {
                if (!empty($row['Field'])) {
                    $columns[(string) $row['Field']] = true;
                }
            }
        }

        $this->column_cache[$table] = $columns;

        return $columns;
    }
}
