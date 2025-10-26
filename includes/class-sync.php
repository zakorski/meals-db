<?php
/**
 * Handles data sync comparison between Meals DB and WooCommerce.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Created as a work for hire for Meals and More.
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

        $mismatches = [];

        $ignored_keys = self::load_ignored_conflicts($conn);

        // Get all active clients from Meals DB
        $query = "SELECT id, individual_id, first_name, last_name, client_email, phone_primary, address_postal FROM meals_clients WHERE status = 'active'";
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
            $individual_id = $client['individual_id'] ?? '';

            if ($individual_id !== '') {
                try {
                    $individual_id = MealsDB_Encryption::decrypt($individual_id);
                } catch (Exception $e) {
                    error_log('[MealsDB Sync] Failed to decrypt individual_id for client ID ' . $client['id'] . ': ' . $e->getMessage());
                    $individual_id = '';
                }
            }

            $client['individual_id'] = $individual_id;

            // Try to find matching WooCommerce user by email or individual ID
            $woo_user = self::find_woocommerce_user($client['client_email'], $individual_id);

            if ($woo_user) {
                $diffs = self::compare_fields($client, $woo_user);

                $filtered_diffs = [];

                foreach ($diffs as $field => $values) {
                    $field_key   = self::sanitize_ignore_value($field);
                    $source_val  = self::sanitize_ignore_value($values['meals_db'] ?? '');
                    $target_val  = self::sanitize_ignore_value($values['woocommerce'] ?? '');
                    $ignore_key  = self::build_ignore_key($field_key, $source_val, $target_val);

                    if (isset($ignored_keys[$ignore_key])) {
                        continue;
                    }

                    $filtered_diffs[$field] = $values;
                }

                if (!empty($filtered_diffs)) {
                    $mismatches[] = [
                        'client_id' => $client['id'],
                        'woo_user_id' => $woo_user->ID,
                        'fields' => $filtered_diffs
                    ];
                }
            }
        }
        $result->free();

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
     * Find a WooCommerce user by email or custom meta matching individual_id.
     *
     * @param string|null $email
     * @param string|null $individual_id
     * @return WP_User|null
     */
    private static function find_woocommerce_user(?string $email, ?string $individual_id): ?WP_User {
        if ($email) {
            $user = get_user_by('email', $email);
            if ($user instanceof WP_User) {
                return $user;
            }
        }

        if ($individual_id !== null && $individual_id !== '') {
            $users = get_users([
                'meta_key' => 'meals_individual_id',
                'meta_value' => $individual_id,
                'number' => 1
            ]);

            if (!empty($users)) {
                return $users[0];
            }
        }

        return null;
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
