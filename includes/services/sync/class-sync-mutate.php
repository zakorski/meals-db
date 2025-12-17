<?php
/**
 * Contains write operations used to mutate data during Meals DB synchronization.
 */

class MealsDB_Sync_Mutate {
    /**
     * Active mysqli connection, when available.
     */
    private ?\mysqli $connection;

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
        $conn = MealsDB_DB::get_connection();
        $this->connection = $conn instanceof \mysqli ? $conn : null;
        $this->clients_primary_key = MealsDB_Schema::get_primary_key_column(MealsDB_Tables::CLIENTS);
    }

    /**
     * Update a WordPress user with the provided field values.
     *
     * @param int                  $user_id Identifier of the WordPress user to update.
     * @param array<string, mixed> $fields  Associative array of field names and values to persist.
     *
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    public function update_wp_user(int $user_id, array $fields) {
        $user = get_userdata($user_id);

        if (!$user instanceof WP_User) {
            return new WP_Error(
                'mealsdb_sync_user_missing',
                __('Unable to locate the WooCommerce customer for this override.', 'meals-db')
            );
        }

        foreach ($fields as $field => $value) {
            $error = $this->apply_wp_user_update($user, $field, $value);

            if (is_wp_error($error)) {
                return $error;
            }
        }

        return true;
    }

    /**
     * Sync a single field from Meals DB to WooCommerce.
     *
     * @return true|WP_Error
     */
    public function push_to_woocommerce(int $woo_user_id, string $field, string $new_value) {
        return $this->update_wp_user($woo_user_id, [
            $field => $new_value,
        ]);
    }

    /**
     * Sync a single field from WooCommerce to Meals DB.
     *
     * @return true|WP_Error
     */
    public function push_to_meals_db(int $client_id, string $field, string $new_value) {
        if ($client_id <= 0) {
            return new WP_Error(
                'mealsdb_sync_invalid_client',
                __('A valid Meals DB client is required to sync this field.', 'meals-db')
            );
        }

        $connection = $this->require_connection();

        if (is_wp_error($connection)) {
            return $connection;
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $available_columns = $this->get_table_columns($connection, $clients_table);
        $column_map = $this->build_identity_column_map($available_columns);

        if (!isset($column_map[$field])) {
            return new WP_Error(
                'mealsdb_sync_unsupported_field',
                __('This field cannot be overridden from WooCommerce.', 'meals-db')
            );
        }

        $column = $column_map[$field];
        $primary_key = $this->resolve_primary_key_column($available_columns);

        if ($primary_key === null) {
            return new WP_Error(
                'mealsdb_sync_failed',
                __('Unable to determine the Meals DB client primary key.', 'meals-db')
            );
        }

        $clients_table = str_replace('`', '``', $clients_table);
        $select_sql = sprintf('SELECT `%s` FROM `%s` WHERE `%s` = ? LIMIT 1', str_replace('`', '``', $column), $clients_table, str_replace('`', '``', $primary_key));
        $stmt = $connection->prepare($select_sql);

        if (!$stmt) {
            error_log('[MealsDB Sync] Failed to prepare Meals DB client lookup statement: ' . ($connection->error ?? 'unknown error'));

            return new WP_Error(
                'mealsdb_sync_failed',
                __('Unable to read the current Meals DB value for this client.', 'meals-db')
            );
        }

        if (!$stmt->bind_param('i', $client_id)) {
            $stmt->close();
            error_log('[MealsDB Sync] Failed binding parameters for Meals DB lookup statement.');

            return new WP_Error(
                'mealsdb_sync_failed',
                __('Unable to load the Meals DB client record.', 'meals-db')
            );
        }

        $existing_value = null;

        if ($stmt->execute()) {
            $value_raw = null;

            if (!$stmt->bind_result($value_raw)) {
                $stmt->close();
                error_log('[MealsDB Sync] Failed binding result for Meals DB lookup statement.');

                return new WP_Error(
                    'mealsdb_sync_failed',
                    __('Unable to read the Meals DB value for this client.', 'meals-db')
                );
            }

            if ($stmt->fetch()) {
                $existing_value = is_scalar($value_raw) ? (string) $value_raw : '';
            } else {
                $stmt->close();

                return new WP_Error(
                    'mealsdb_sync_client_missing',
                    __('The Meals DB client could not be found.', 'meals-db')
                );
            }
        } else {
            $message = $stmt->error ?: __('Unknown database error.', 'meals-db');
            $stmt->close();
            error_log('[MealsDB Sync] Failed executing Meals DB lookup statement: ' . $message);

            return new WP_Error(
                'mealsdb_sync_failed',
                sprintf(__('Unable to load the Meals DB client record: %s', 'meals-db'), $message)
            );
        }

        $stmt->close();

        if ($existing_value === null) {
            return new WP_Error(
                'mealsdb_sync_failed',
                __('Unable to determine the existing Meals DB value for this client.', 'meals-db')
            );
        }

        $update_success = $this->update_meals_client($client_id, [
            $column => $new_value,
        ]);

        if (!$update_success) {
            return new WP_Error(
                'mealsdb_sync_failed',
                __('Unable to update the Meals DB client record.', 'meals-db')
            );
        }

        MealsDB_Logger::log(
            'sync_override',
            $client_id,
            $field,
            $existing_value,
            $new_value,
            'woocommerce'
        );

        return true;
    }

    /**
     * Update a Meals DB client with the provided field values.
     *
     * @param int                  $client_id Identifier of the Meals DB client to update.
     * @param array<string, mixed> $fields    Associative array of field names and values to persist.
     *
     * @return bool True on success, false on failure.
     */
    public function update_meals_client(int $client_id, array $fields): bool {
        $connection = $this->require_connection();

        if (is_wp_error($connection) || empty($fields)) {
            return false;
        }

        $columns = [];
        $types   = '';
        $values  = [];

        foreach ($fields as $column => $value) {
            $columns[] = sprintf('`%s` = ?', str_replace('`', '``', $column));
            $types    .= 's';
            $values[]  = (string) $value;
        }

        $types  .= 'i';
        $values[] = $client_id;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $available_columns = $this->get_table_columns($connection, $clients_table);
        $primary_key = $this->resolve_primary_key_column($available_columns);

        if ($primary_key === null) {
            return false;
        }

        $escaped_table = str_replace('`', '``', $clients_table);
        $escaped_pk    = str_replace('`', '``', $primary_key);
        $sql = sprintf('UPDATE `%s` SET %s WHERE `%s` = ?', $escaped_table, implode(', ', $columns), $escaped_pk);
        $stmt = $connection->prepare($sql);

        if (!$stmt) {
            error_log('[MealsDB Sync] Failed to prepare Meals DB client update statement: ' . ($connection->error ?? 'unknown error'));
            return false;
        }

        $stmt->bind_param($types, ...$values);
        $success = $stmt->execute();
        $stmt->close();

        return $success;
    }

    /**
     * Create a Meals DB client record with the provided field values.
     *
     * @param array<string, mixed> $fields Associative array of field names and values for the new client.
     *
     * @return int|false The created client ID on success, or false on failure.
     */
    public function create_meals_client(array $fields) {
        $connection = $this->require_connection();

        if (is_wp_error($connection) || empty($fields)) {
            return false;
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $available_columns = $this->get_table_columns($connection, $clients_table);
        $column_map = $this->build_identity_column_map($available_columns);

        $prepared_columns = [];
        $placeholders = [];
        $values = [];

        foreach ($fields as $field => $value) {
            if (!isset($column_map[$field])) {
                continue;
            }

            $prepared_columns[] = sprintf('`%s`', str_replace('`', '``', $column_map[$field]));
            $placeholders[] = '?';
            $values[] = (string) $value;
        }

        if (empty($prepared_columns)) {
            return false;
        }

        $types = str_repeat('s', count($values));

        $escaped_table = str_replace('`', '``', $clients_table);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $escaped_table,
            implode(', ', $prepared_columns),
            implode(', ', $placeholders)
        );
        $stmt = $connection->prepare($sql);

        if (!$stmt) {
            error_log('[MealsDB Sync] Failed to prepare Meals DB client insert statement: ' . ($connection->error ?? 'unknown error'));
            return false;
        }

        $stmt->bind_param($types, ...$values);

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $insert_id = $stmt->insert_id;
        $stmt->close();

        return $insert_id > 0 ? $insert_id : false;
    }

    /**
     * Resolve a synchronization conflict using the provided descriptor.
     *
     * @param array<string, mixed> $conflict Conflict metadata describing the resolution to apply.
     *
     * @return bool True when the conflict has been resolved, false otherwise.
     */
    public function resolve_conflict(array $conflict): bool {
        return false;
    }

    /**
     * Link a Meals DB client to a WooCommerce user.
     *
     * @param int $client_id
     * @param int $user_id
     * @return true|WP_Error
     */
    public function link_meals_client_to_wc_user(int $client_id, int $user_id) {
        if ($client_id <= 0 || $user_id <= 0) {
            return new WP_Error(
                'mealsdb_invalid_link_request',
                __('A valid client ID and WordPress user ID are required to create a link.', 'meals-db')
            );
        }

        $connection = $this->require_connection();

        if (is_wp_error($connection)) {
            return $connection;
        }

        $wp_user = get_userdata($user_id);
        if (!$wp_user instanceof WP_User) {
            return new WP_Error(
                'mealsdb_sync_user_missing',
                __('Unable to locate the selected WordPress user.', 'meals-db')
            );
        }

        $transaction_started = false;

        if (method_exists($connection, 'begin_transaction')) {
            $transaction_started = $connection->begin_transaction();
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $available_columns = $this->get_table_columns($connection, $clients_table);
        $primary_key = $this->resolve_primary_key_column($available_columns);
        $wp_column = $this->choose_column(['wordpress_user_id', 'wp_user_id'], $available_columns);

        if ($primary_key === null || $wp_column === null) {
            if ($transaction_started) {
                $connection->rollback();
            }

            return new WP_Error(
                'mealsdb_link_prepare_failed',
                __('Required Meals DB columns for linking could not be found.', 'meals-db')
            );
        }

        $escaped_table  = str_replace('`', '``', $clients_table);
        $escaped_pk     = str_replace('`', '``', $primary_key);
        $escaped_column = str_replace('`', '``', $wp_column);
        $update_stmt = $connection->prepare(sprintf('UPDATE `%s` SET `%s` = ? WHERE `%s` = ?', $escaped_table, $escaped_column, $escaped_pk));

        if (!$update_stmt) {
            if ($transaction_started) {
                $connection->rollback();
            }

            error_log('[MealsDB Sync] Failed to prepare client link statement: ' . ($connection->error ?? 'unknown error'));

            return new WP_Error(
                'mealsdb_link_prepare_failed',
                __('Failed to prepare the database statement to link the client.', 'meals-db')
            );
        }

        if (!$update_stmt->bind_param('ii', $user_id, $client_id) || !$update_stmt->execute()) {
            $message = $update_stmt->error ?: __('Unknown database error.', 'meals-db');
            $update_stmt->close();

            if ($transaction_started) {
                $connection->rollback();
            }

            error_log('[MealsDB Sync] Failed executing client link statement: ' . $message);

            return new WP_Error(
                'mealsdb_link_execute_failed',
                sprintf(__('Unable to link the client to the WordPress user: %s', 'meals-db'), $message)
            );
        }

        $affected = $update_stmt->affected_rows;
        $update_stmt->close();

        if ($affected === 0) {
            if ($transaction_started) {
                $connection->rollback();
            }

            return new WP_Error(
                'mealsdb_link_no_rows',
                __('No Meals DB client record was updated. The client may not exist.', 'meals-db')
            );
        }

        if ($transaction_started && !$connection->commit()) {
            $connection->rollback();

            return new WP_Error(
                'mealsdb_link_commit_failed',
                __('Failed to finalize the client link transaction.', 'meals-db')
            );
        }

        return true;
    }

    /**
     * Link a Meals DB client to a WordPress user account.
     *
     * @return true|WP_Error
     */
    public function link_client_to_wordpress_user(int $client_id, int $wp_user_id) {
        return $this->link_meals_client_to_wc_user($client_id, $wp_user_id);
    }

    /**
     * Ensure a mysqli connection is available for mutations.
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
     * Build a safe mapping of identity fields to Meals DB columns.
     *
     * @param array<string, bool> $available_columns
     * @return array<string, string>
     */
    private function build_identity_column_map(array $available_columns): array {
        $map = [];

        $field_candidates = [
            'first_name'        => ['first_name'],
            'last_name'         => ['last_name'],
            'client_email'      => ['client_email'],
            'phone_primary'     => ['phone_primary', 'client_phone_1', 'phone'],
            'wordpress_user_id' => ['wordpress_user_id', 'wp_user_id'],
        ];

        foreach ($field_candidates as $field => $candidates) {
            $column = $this->choose_column($candidates, $available_columns);

            if ($column !== null) {
                $map[$field] = $column;
            }
        }

        return $map;
    }

    /**
     * Resolve the Meals DB clients primary key column.
     *
     * @param array<string, bool> $available_columns
     */
    private function resolve_primary_key_column(array $available_columns): ?string {
        return $this->choose_column([
            $this->clients_primary_key,
            'client_id',
            'id',
        ], $available_columns);
    }

    /**
     * Pick the first available column from a candidate list.
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
     * @param \mysqli $connection
     * @return array<string, bool>
     */
    private function get_table_columns(\mysqli $connection, string $table): array {
        if (isset($this->column_cache[$table])) {
            return $this->column_cache[$table];
        }

        $escaped_table = str_replace('`', '``', $table);
        $columns = [];

        $sql = sprintf('SHOW COLUMNS FROM `%s`', $escaped_table);
        $result = $connection->query($sql);

        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                if (!empty($row['Field'])) {
                    $columns[(string) $row['Field']] = true;
                }
            }

            $result->free();
        }

        $this->column_cache[$table] = $columns;

        return $columns;
    }

    /**
     * Apply an individual update operation to a WordPress user field.
     *
     * @param WP_User $user
     * @param string  $field
     * @param mixed   $value
     *
     * @return true|WP_Error
     */
    private function apply_wp_user_update(WP_User $user, string $field, $value) {
        $woo_user_id = (int) $user->ID;
        $new_value   = is_scalar($value) ? (string) $value : '';
        $old_value   = null;
        $update_success = false;
        $error_code = 'mealsdb_sync_failed';
        $error_message = '';

        switch ($field) {
            case 'first_name':
            case 'last_name':
                $old_value = $field === 'first_name' ? $user->first_name : $user->last_name;
                $result = wp_update_user([
                    'ID'    => $woo_user_id,
                    $field  => $new_value,
                ]);
                if (!is_wp_error($result)) {
                    $update_success = true;
                } else {
                    $error_message = $result->get_error_message();
                    error_log('[MealsDB Sync] Failed to sync ' . $field . ' for user ' . $woo_user_id . ': ' . $error_message);
                }
                break;
            case 'client_email':
                $old_value = $user->user_email;
                $result = wp_update_user([
                    'ID'         => $woo_user_id,
                    'user_email' => $new_value,
                ]);
                if (!is_wp_error($result)) {
                    $update_success = true;
                } else {
                    $error_message = $result->get_error_message();
                    error_log('[MealsDB Sync] Failed to sync email for user ' . $woo_user_id . ': ' . $error_message);
                }
                break;
            case 'phone_primary':
                $old_value = get_user_meta($woo_user_id, 'billing_phone', true);
                $update_success = update_user_meta($woo_user_id, 'billing_phone', $new_value) !== false;
                if (!$update_success) {
                    $error_message = __('Unable to update the customer phone number.', 'meals-db');
                    error_log('[MealsDB Sync] Failed to sync phone for user ' . $woo_user_id . '.');
                }
                break;
            default:
                $error_code = 'mealsdb_sync_unsupported_field';
                $error_message = __('This field cannot be overridden from Meals DB.', 'meals-db');
                break;
        }

        if (!$update_success) {
            if ($error_message === '') {
                $error_message = __('An unexpected error prevented the override from completing.', 'meals-db');
            }

            return new WP_Error($error_code, $error_message);
        }

        MealsDB_Logger::log(
            'sync_override',
            $woo_user_id,
            $field,
            is_scalar($old_value) ? (string) $old_value : null,
            $new_value,
            'mealsdb'
        );

        return true;
    }
}
