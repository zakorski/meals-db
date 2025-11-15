<?php
/**
 * Contains write operations used to mutate data during Meals DB synchronization.
 */

class MealsDB_Sync_Mutate {
    /**
     * Active mysqli connection, when available.
     */
    private ?\mysqli $connection;

    public function __construct() {
        $conn = MealsDB_DB::get_connection();
        $this->connection = $conn instanceof \mysqli ? $conn : null;
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

        $allowed_fields = [
            'first_name'     => 'first_name',
            'last_name'      => 'last_name',
            'client_email'   => 'client_email',
            'phone_primary'  => 'phone_primary',
            'address_postal' => 'address_postal',
        ];

        if (!isset($allowed_fields[$field])) {
            return new WP_Error(
                'mealsdb_sync_unsupported_field',
                __('This field cannot be overridden from WooCommerce.', 'meals-db')
            );
        }

        $connection = $this->require_connection();

        if (is_wp_error($connection)) {
            return $connection;
        }

        $column = $allowed_fields[$field];
        $select_sql = sprintf('SELECT %s FROM meals_clients WHERE id = ? LIMIT 1', $column);
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
            $columns[] = $column . ' = ?';
            $types    .= 's';
            $values[]  = (string) $value;
        }

        $types  .= 'i';
        $values[] = $client_id;

        $sql = 'UPDATE meals_clients SET ' . implode(', ', $columns) . ' WHERE id = ?';
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

        $columns = array_keys($fields);
        $placeholders = array_fill(0, count($fields), '?');
        $types = str_repeat('s', count($fields));
        $values = array_map(static fn($value) => (string) $value, $fields);

        $sql = 'INSERT INTO meals_clients (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
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
     * Link a Meals DB client to a WordPress user account.
     *
     * @return true|WP_Error
     */
    public function link_client_to_wordpress_user(int $client_id, int $wp_user_id) {
        if ($client_id <= 0 || $wp_user_id <= 0) {
            return new WP_Error(
                'mealsdb_invalid_link_request',
                __('A valid client ID and WordPress user ID are required to create a link.', 'meals-db')
            );
        }

        $connection = $this->require_connection();

        if (is_wp_error($connection)) {
            return $connection;
        }

        $wp_user = get_userdata($wp_user_id);
        if (!$wp_user instanceof WP_User) {
            return new WP_Error(
                'mealsdb_sync_user_missing',
                __('Unable to locate the selected WordPress user.', 'meals-db')
            );
        }

        $current_id = null;
        $check_stmt = $connection->prepare('SELECT wordpress_user_id FROM meals_clients WHERE id = ? LIMIT 1');
        if ($check_stmt) {
            if ($check_stmt->bind_param('i', $client_id) && $check_stmt->execute()) {
                $result = $check_stmt->get_result();
                if ($result instanceof \mysqli_result && ($row = $result->fetch_assoc())) {
                    $raw = $row['wordpress_user_id'] ?? null;
                    if ($raw !== null && $raw !== '') {
                        $current_id = (int) $raw;
                    }
                }
                if ($result instanceof \mysqli_result) {
                    $result->free();
                }
            }
            $check_stmt->close();
        }

        if ($current_id === $wp_user_id) {
            return true;
        }

        $stmt = $connection->prepare('UPDATE meals_clients SET wordpress_user_id = ? WHERE id = ?');
        if (!$stmt) {
            error_log('[MealsDB Sync] Failed to prepare client link statement: ' . ($connection->error ?? 'unknown error'));
            return new WP_Error(
                'mealsdb_link_prepare_failed',
                __('Failed to prepare the database statement to link the client.', 'meals-db')
            );
        }

        if (!$stmt->bind_param('ii', $wp_user_id, $client_id)) {
            $stmt->close();
            error_log('[MealsDB Sync] Failed binding parameters for client link statement.');
            return new WP_Error(
                'mealsdb_link_bind_failed',
                __('Failed to bind parameters for the link request.', 'meals-db')
            );
        }

        if (!$stmt->execute()) {
            $message = $stmt->error ?: __('Unknown database error.', 'meals-db');
            $stmt->close();
            error_log('[MealsDB Sync] Failed executing client link statement: ' . $message);

            return new WP_Error(
                'mealsdb_link_execute_failed',
                sprintf(__('Unable to link the client to the WordPress user: %s', 'meals-db'), $message)
            );
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            return new WP_Error(
                'mealsdb_link_no_rows',
                __('No Meals DB client record was updated. The client may not exist.', 'meals-db')
            );
        }

        return true;
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
            case 'address_postal':
                $old_value = get_user_meta($woo_user_id, 'billing_postcode', true);
                $update_success = update_user_meta($woo_user_id, 'billing_postcode', $new_value) !== false;
                if (!$update_success) {
                    $error_message = __('Unable to update the customer postal code.', 'meals-db');
                    error_log('[MealsDB Sync] Failed to sync postal code for user ' . $woo_user_id . '.');
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
