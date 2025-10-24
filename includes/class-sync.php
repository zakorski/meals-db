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
     * @return array
     */
    public static function get_mismatches(): array {
        $conn = MealsDB_DB::get_connection();

        if (!$conn) {
            return [];
        }

        $mismatches = [];

        // Get all active clients from Meals DB
        $query = "SELECT id, individual_id, first_name, last_name, client_email, phone_primary, address_postal FROM meals_clients WHERE status = 'active'";
        $result = $conn->query($query);

        if (!$result) {
            error_log('[MealsDB Sync] Failed to fetch Meals DB records: ' . $conn->error);
            return [];
        }

        while ($client = $result->fetch_assoc()) {
            $individual_id = $client['individual_id'] ?? '';

            if (!empty($individual_id)) {
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
                if (!empty($individual_id)) {
                    $stored_identifier = get_user_meta($woo_user->ID, 'meals_individual_id', true);

                    if ($stored_identifier !== $individual_id) {
                        update_user_meta($woo_user->ID, 'meals_individual_id', $individual_id);
                    }
                }

                $diffs = self::compare_fields($client, $woo_user);

                if (!empty($diffs)) {
                    $mismatches[] = [
                        'client_id' => $client['id'],
                        'woo_user_id' => $woo_user->ID,
                        'fields' => $diffs
                    ];
                }
            }
        }

        return $mismatches;
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

        if (!empty($individual_id)) {
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
     */
    public static function push_to_woocommerce(int $woo_user_id, string $field, string $new_value): void {
        switch ($field) {
            case 'first_name':
            case 'last_name':
                wp_update_user([
                    'ID' => $woo_user_id,
                    $field => $new_value
                ]);
                break;
            case 'client_email':
                wp_update_user([
                    'ID' => $woo_user_id,
                    'user_email' => $new_value
                ]);
                break;
            case 'phone_primary':
                update_user_meta($woo_user_id, 'billing_phone', $new_value);
                break;
            case 'address_postal':
                update_user_meta($woo_user_id, 'billing_postcode', $new_value);
                break;
        }

        // Log it
        MealsDB_Logger::log(
            'sync_override',
            $woo_user_id,
            $field,
            '[Woo old]',
            $new_value,
            'mealsdb'
        );
    }
}
