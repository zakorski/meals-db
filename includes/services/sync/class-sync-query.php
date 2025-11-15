<?php
/**
 * Provides data retrieval methods used during Meals DB synchronization workflows.
 */

class MealsDB_Sync_Query {
    /**
     * Active mysqli connection, when available.
     */
    private ?\mysqli $connection;

    public function __construct() {
        $conn = MealsDB_DB::get_connection();
        $this->connection = $conn instanceof \mysqli ? $conn : null;
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

        $clients = $this->batched_query(
            function (int $batch_size, int $page, int $offset) use ($connection, &$query_error): array {
                $sql = sprintf(
                    'SELECT id, individual_id, first_name, last_name, client_email, phone_primary, address_postal, wordpress_user_id FROM meals_clients LIMIT %d OFFSET %d',
                    (int) $batch_size,
                    (int) $offset
                );

                $result = $connection->query($sql);

                if (!($result instanceof \mysqli_result)) {
                    $message = $connection->error ?: __('Unknown database error.', 'meals-db');
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

                $rows = [];

                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }

                $result->free();

                return $rows;
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

        $sql = 'SELECT field_name, source_value, target_value FROM meals_ignored_conflicts';
        $stmt = $connection->prepare($sql);

        if (!$stmt) {
            error_log('[MealsDB Sync] Failed to prepare ignored conflicts query: ' . ($connection->error ?? 'unknown error'));
            return $ignored;
        }

        if (!$stmt->execute()) {
            error_log('[MealsDB Sync] Failed to execute ignored conflicts query: ' . ($stmt->error ?? 'unknown error'));
            $stmt->close();
            return $ignored;
        }

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result instanceof \mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    $field  = $this->sanitize_ignore_value($row['field_name'] ?? '');
                    $source = $this->sanitize_ignore_value($row['source_value'] ?? '');
                    $target = $this->sanitize_ignore_value($row['target_value'] ?? '');
                    $ignored[$this->build_ignore_key($field, $source, $target)] = true;
                }

                $result->free();
            }
        } else {
            if ($stmt->bind_result($field, $source, $target)) {
                while ($stmt->fetch()) {
                    $ignored[$this->build_ignore_key(
                        $this->sanitize_ignore_value($field ?? ''),
                        $this->sanitize_ignore_value($source ?? ''),
                        $this->sanitize_ignore_value($target ?? '')
                    )] = true;
                }
            }
        }

        $stmt->close();

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
        $sql = 'SELECT wordpress_user_id FROM meals_staff WHERE wordpress_user_id IS NOT NULL AND wordpress_user_id > 0';
        $result = $connection->query($sql);

        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $wp_id_raw = $row['wordpress_user_id'] ?? null;

                if (is_numeric($wp_id_raw)) {
                    $wp_id = (int) $wp_id_raw;

                    if ($wp_id > 0) {
                        $staff_ids[$wp_id] = true;
                    }
                }
            }

            $result->free();
        }

        return $staff_ids;
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
     * Ensure a mysqli connection is available.
     *
     * @return \mysqli|WP_Error
     */
    private function require_connection() {
        if ($this->connection instanceof \mysqli) {
            return $this->connection;
        }

        $connection = MealsDB_DB::get_connection();

        if ($connection instanceof \mysqli) {
            $this->connection = $connection;
            return $this->connection;
        }

        return new WP_Error(
            'mealsdb_db_connection_failed',
            __('Unable to connect to the Meals DB database. Please try again later.', 'meals-db')
        );
    }

    /**
     * Normalize a Meals DB client record and decrypt the stored individual ID when possible.
     *
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function normalize_client_row(array $client): array {
        $individual_id = $client['individual_id'] ?? '';

        if ($individual_id !== '') {
            try {
                $individual_id = MealsDB_Encryption::decrypt($individual_id);
            } catch (Exception $e) {
                error_log('[MealsDB Sync] Failed to decrypt individual_id for client ID ' . ($client['id'] ?? 'unknown') . ': ' . $e->getMessage());
                $individual_id = '';
            }
        }

        $wp_id_raw = $client['wordpress_user_id'] ?? 0;
        $wp_id = is_numeric($wp_id_raw) ? (int) $wp_id_raw : 0;

        if ($wp_id < 0) {
            $wp_id = 0;
        }

        return [
            'id'                => isset($client['id']) ? (int) $client['id'] : 0,
            'individual_id'     => (string) $individual_id,
            'first_name'        => isset($client['first_name']) ? (string) $client['first_name'] : '',
            'last_name'         => isset($client['last_name']) ? (string) $client['last_name'] : '',
            'client_email'      => isset($client['client_email']) ? (string) $client['client_email'] : '',
            'phone_primary'     => isset($client['phone_primary']) ? (string) $client['phone_primary'] : '',
            'address_postal'    => isset($client['address_postal']) ? (string) $client['address_postal'] : '',
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
}
