<?php
/**
 * Handles data sync comparison between Meals DB and WooCommerce.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Sync {

    /**
     * Get a list of mismatched fields between Meals DB and WooCommerce.
     *
     * @return array|WP_Error
     */
    public static function get_mismatches() {
        $conn = MealsDB_DB::get_connection();

        if (!$conn) {
            return new WP_Error(
                'mealsdb_db_connection_failed',
                __('Unable to connect to the Meals DB database. Please try again later.', 'meals-db')
            );
        }

        $ignored_keys = self::load_ignored_conflicts($conn);
        $mismatches   = [];

        $clients_by_wp_id   = [];
        $clients_without_id = [];

        $query  = "SELECT id, individual_id, first_name, last_name, client_email, phone_primary, address_postal, wordpress_user_id FROM meals_clients";
        $result = $conn->query($query);

        if (!($result instanceof \mysqli_result)) {
            $message = $conn->error ?: __('Unknown database error.', 'meals-db');
            error_log('[MealsDB Sync] Failed to fetch Meals DB records: ' . $message);

            return new WP_Error(
                'mealsdb_query_failed',
                sprintf(
                    /* translators: %s: database error message */
                    __('Failed to retrieve Meals DB records: %s', 'meals-db'),
                    $message
                )
            );
        }

        while ($client = $result->fetch_assoc()) {
            $normalized = self::normalize_client_row($client);

            if ($normalized['wordpress_user_id'] > 0) {
                $clients_by_wp_id[$normalized['wordpress_user_id']][] = $normalized;
            } else {
                $clients_without_id[] = $normalized;
            }
        }

        $result->free();

        $wp_users = get_users([
            'fields' => 'all_with_meta',
        ]);

        foreach ($wp_users as $woo_user) {
            if (!$woo_user instanceof WP_User) {
                continue;
            }

            $wp_id = (int) $woo_user->ID;

            if (isset($clients_by_wp_id[$wp_id])) {
                foreach ($clients_by_wp_id[$wp_id] as $client) {
                    $diffs = self::compare_fields($client, $woo_user);
                    $filtered_diffs = self::filter_ignored_fields($diffs, $ignored_keys);

                    if (!empty($filtered_diffs)) {
                        $mismatches[] = [
                            'type'         => 'field_mismatch',
                            'client_id'    => $client['id'],
                            'woo_user_id'  => $wp_id,
                            'fields'       => $filtered_diffs,
                            'allow_sync'   => true,
                            'notice'       => '',
                            'meals_client' => $client,
                            'wp_user'      => self::extract_user_snapshot($woo_user),
                        ];
                    }
                }

                unset($clients_by_wp_id[$wp_id]);
            } else {
                $conflict = self::build_wordpress_only_conflict($woo_user, $ignored_keys);

                if ($conflict !== null) {
                    $mismatches[] = $conflict;
                }
            }
        }

        if (!empty($clients_by_wp_id)) {
            foreach ($clients_by_wp_id as $clients) {
                foreach ($clients as $client) {
                    $conflict = self::build_meals_only_conflict($client, $ignored_keys, true);

                    if ($conflict !== null) {
                        $mismatches[] = $conflict;
                    }
                }
            }
        }

        foreach ($clients_without_id as $client) {
            $conflict = self::build_meals_only_conflict($client, $ignored_keys, false);

            if ($conflict !== null) {
                $mismatches[] = $conflict;
            }
        }

        return $mismatches;
    }

    /**
     * Build a hash map of ignored mismatch combinations.
     *
     * @param \mysqli $conn
     * @return array<string, bool>
     */
    private static function load_ignored_conflicts(\mysqli $conn): array {
        $ignored = [];

        $sql = 'SELECT field_name, source_value, target_value FROM meals_ignored_conflicts';
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            error_log('[MealsDB Sync] Failed to prepare ignored conflicts query: ' . ($conn->error ?? 'unknown error'));
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
                    $field  = self::sanitize_ignore_value($row['field_name'] ?? '');
                    $source = self::sanitize_ignore_value($row['source_value'] ?? '');
                    $target = self::sanitize_ignore_value($row['target_value'] ?? '');
                    $ignored[self::build_ignore_key($field, $source, $target)] = true;
                }

                $result->free();
            }
        } else {
            if ($stmt->bind_result($field, $source, $target)) {
                while ($stmt->fetch()) {
                    $ignored[self::build_ignore_key(
                        self::sanitize_ignore_value($field ?? ''),
                        self::sanitize_ignore_value($source ?? ''),
                        self::sanitize_ignore_value($target ?? '')
                    )] = true;
                }
            }
        }

        $stmt->close();

        return $ignored;
    }

    /**
     * Normalize ignore values before hashing.
     *
     * @param mixed $value
     * @return string
     */
    private static function sanitize_ignore_value($value): string {
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
     *
     * @param string $field
     * @param string $source
     * @param string $target
     * @return string
     */
    private static function build_ignore_key(string $field, string $source, string $target): string {
        return md5($field . '|' . $source . '|' . $target);
    }

    /**
     * Compare Meals DB record and Woo user fields.
     *
     * @param array $client
     * @param WP_User $woo_user
     * @return array Mismatched fields
     */
    private static function compare_fields(array $client, WP_User $woo_user): array {
        $mismatches = [];

        // Meals DB = source of truth
        $map = [
            'first_name' => $woo_user->first_name,
            'last_name'  => $woo_user->last_name,
            'client_email' => $woo_user->user_email,
            'phone_primary' => get_user_meta($woo_user->ID, 'billing_phone', true),
            'address_postal' => get_user_meta($woo_user->ID, 'billing_postcode', true),
        ];

        foreach ($map as $field => $woo_value) {
            $plugin_value = $client[$field] ?? '';

            if (trim(strtolower($plugin_value)) !== trim(strtolower($woo_value))) {
                $mismatches[$field] = [
                    'meals_db' => $plugin_value,
                    'woocommerce' => $woo_value
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Filter out fields that have been ignored by the administrator.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, bool>                 $ignored_keys
     * @return array<string, array<string, mixed>>
     */
    private static function filter_ignored_fields(array $fields, array $ignored_keys): array {
        $filtered = [];

        foreach ($fields as $field => $values) {
            $field_key  = self::sanitize_ignore_value($field);
            $source_val = self::sanitize_ignore_value($values['meals_db'] ?? '');
            $target_val = self::sanitize_ignore_value($values['woocommerce'] ?? '');
            $ignore_key = self::build_ignore_key($field_key, $source_val, $target_val);

            if (isset($ignored_keys[$ignore_key])) {
                continue;
            }

            $filtered[$field] = $values;
        }

        return $filtered;
    }

    /**
     * Build a conflict entry for a WordPress user that does not exist in Meals DB.
     *
     * @param WP_User               $woo_user
     * @param array<string, bool>   $ignored_keys
     * @return array<string, mixed>|null
     */
    private static function build_wordpress_only_conflict(WP_User $woo_user, array $ignored_keys): ?array {
        $no_meals_message = __('No Meals DB client is linked to this WordPress user.', 'meals-db');

        $fields = [
            'wordpress_user_id' => [
                'meals_db'    => $no_meals_message,
                'woocommerce' => (string) $woo_user->ID,
            ],
            'first_name' => [
                'meals_db'    => $no_meals_message,
                'woocommerce' => isset($woo_user->first_name) ? (string) $woo_user->first_name : '',
            ],
            'last_name' => [
                'meals_db'    => $no_meals_message,
                'woocommerce' => isset($woo_user->last_name) ? (string) $woo_user->last_name : '',
            ],
            'client_email' => [
                'meals_db'    => $no_meals_message,
                'woocommerce' => isset($woo_user->user_email) ? (string) $woo_user->user_email : '',
            ],
        ];

        $filtered = self::filter_ignored_fields($fields, $ignored_keys);

        if (empty($filtered)) {
            return null;
        }

        return [
            'type'         => 'wordpress_only',
            'client_id'    => 0,
            'woo_user_id'  => (int) $woo_user->ID,
            'fields'       => $filtered,
            'allow_sync'   => false,
            'notice'       => __('No Meals DB client record matches this WordPress user.', 'meals-db'),
            'meals_client' => null,
            'wp_user'      => self::extract_user_snapshot($woo_user),
        ];
    }

    /**
     * Build a conflict entry for a Meals DB client without a matching WordPress user record.
     *
     * @param array<string, mixed>  $client
     * @param array<string, bool>   $ignored_keys
     * @param bool                  $has_wordpress_id Whether the client references a WordPress user ID.
     * @return array<string, mixed>|null
     */
    private static function build_meals_only_conflict(array $client, array $ignored_keys, bool $has_wordpress_id): ?array {
        $wp_id = $client['wordpress_user_id'] ?? 0;

        if ($has_wordpress_id) {
            $notice = __('The linked WordPress user could not be found.', 'meals-db');
            $woo_message = __('No WordPress user exists with this ID.', 'meals-db');
            $meals_value = (string) $wp_id;
        } else {
            $notice = __('This Meals DB client does not have a linked WordPress user ID.', 'meals-db');
            $woo_message = __('This client is not linked to a WordPress user ID.', 'meals-db');
            $meals_value = __('(not set)', 'meals-db');
        }

        $no_wp_data_message = $woo_message;

        $fields = [
            'wordpress_user_id' => [
                'meals_db'    => $meals_value,
                'woocommerce' => $woo_message,
            ],
            'first_name' => [
                'meals_db'    => isset($client['first_name']) ? (string) $client['first_name'] : '',
                'woocommerce' => $no_wp_data_message,
            ],
            'last_name' => [
                'meals_db'    => isset($client['last_name']) ? (string) $client['last_name'] : '',
                'woocommerce' => $no_wp_data_message,
            ],
            'client_email' => [
                'meals_db'    => isset($client['client_email']) ? (string) $client['client_email'] : '',
                'woocommerce' => $no_wp_data_message,
            ],
        ];

        $filtered = self::filter_ignored_fields($fields, $ignored_keys);

        if (empty($filtered)) {
            return null;
        }

        return [
            'type'         => 'meals_only',
            'client_id'    => $client['id'] ?? 0,
            'woo_user_id'  => $has_wordpress_id ? (int) $wp_id : 0,
            'fields'       => $filtered,
            'allow_sync'   => false,
            'notice'       => $notice,
            'meals_client' => $client,
            'wp_user'      => null,
        ];
    }

    /**
     * Normalize a Meals DB client record and decrypt the stored individual ID when possible.
     *
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private static function normalize_client_row(array $client): array {
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
            'id'               => isset($client['id']) ? (int) $client['id'] : 0,
            'individual_id'    => (string) $individual_id,
            'first_name'       => isset($client['first_name']) ? (string) $client['first_name'] : '',
            'last_name'        => isset($client['last_name']) ? (string) $client['last_name'] : '',
            'client_email'     => isset($client['client_email']) ? (string) $client['client_email'] : '',
            'phone_primary'    => isset($client['phone_primary']) ? (string) $client['phone_primary'] : '',
            'address_postal'   => isset($client['address_postal']) ? (string) $client['address_postal'] : '',
            'wordpress_user_id'=> $wp_id,
        ];
    }

    /**
     * Create a lightweight snapshot of a WordPress user for display purposes.
     *
     * @param WP_User $woo_user
     * @return array<string, string|int>
     */
    private static function extract_user_snapshot(WP_User $woo_user): array {
        return [
            'id'         => (int) $woo_user->ID,
            'first_name' => isset($woo_user->first_name) ? (string) $woo_user->first_name : '',
            'last_name'  => isset($woo_user->last_name) ? (string) $woo_user->last_name : '',
            'email'      => isset($woo_user->user_email) ? (string) $woo_user->user_email : '',
        ];
    }

    /**
     * Sync a single field from Meals DB to WooCommerce.
     *
     * @param int $woo_user_id
     * @param string $field
     * @param string $new_value
     *
     * @return true|WP_Error Whether the field was synced successfully.
     */
    public static function push_to_woocommerce(int $woo_user_id, string $field, string $new_value) {
        $user = get_userdata($woo_user_id);

        if (!$user instanceof WP_User) {
            $message = __('Unable to locate the WooCommerce customer for this override.', 'meals-db');
            return new WP_Error('mealsdb_sync_user_missing', $message);
        }

        $old_value = null;
        $update_success = false;
        $error_code = 'mealsdb_sync_failed';
        $error_message = '';

        switch ($field) {
            case 'first_name':
            case 'last_name':
                $old_value = $field === 'first_name' ? $user->first_name : $user->last_name;
                $result = wp_update_user([
                    'ID' => $woo_user_id,
                    $field => $new_value
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
                    'ID' => $woo_user_id,
                    'user_email' => $new_value
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
