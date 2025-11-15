<?php
/**
 * Handles data sync comparison between Meals DB and WooCommerce.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

require_once __DIR__ . '/services/sync/class-sync-query.php';
require_once __DIR__ . '/services/sync/class-sync-compare.php';
require_once __DIR__ . '/services/sync/class-sync-mutate.php';

class MealsDB_Sync {

    /**
     * Get a list of mismatched fields between Meals DB and WooCommerce.
     *
     * @return array|WP_Error
     */
    public static function get_mismatches() {
        $query   = new MealsDB_Sync_Query();
        $compare = new MealsDB_Sync_Compare();

        $clients = $query->get_meals_clients();
        if (is_wp_error($clients)) {
            return $clients;
        }

        $ignored_keys = $query->get_ignored_conflicts();
        if (is_wp_error($ignored_keys)) {
            return $ignored_keys;
        }

        $staff_wp_ids = $query->get_staff_wordpress_ids();
        if (is_wp_error($staff_wp_ids)) {
            return $staff_wp_ids;
        }

        $wp_users = $query->get_wp_users();

        $mismatches = $compare->detect_mismatches(
            $wp_users,
            $clients['by_wp_id'] ?? [],
            $clients['without_wp_id'] ?? [],
            $staff_wp_ids
        );

        return $compare->filter_ignored($mismatches, is_array($ignored_keys) ? $ignored_keys : []);
    }

    /**
     * Link a Meals DB client to a WordPress user account.
     *
     * @param int $client_id
     * @param int $wp_user_id
     * @return true|WP_Error
     */
    public static function link_client_to_wordpress_user(int $client_id, int $wp_user_id) {
        $mutate = new MealsDB_Sync_Mutate();

        return $mutate->link_client_to_wordpress_user($client_id, $wp_user_id);
    }

    /**
     * Find probable WordPress user matches for a Meals DB client.
     *
     * @param array<string, mixed> $client
     * @return array<int, array<string, mixed>>
     */
    public static function find_probable_matches_for_client(array $client): array {
        $query   = new MealsDB_Sync_Query();
        $compare = new MealsDB_Sync_Compare();

        $wp_users = $query->get_wp_users();

        return $compare->find_probable_matches($client, $wp_users);
    }

    /**
     * Sync a single field from Meals DB to WooCommerce.
     *
     * @param int    $woo_user_id
     * @param string $field
     * @param string $new_value
     *
     * @return true|WP_Error Whether the field was synced successfully.
     */
    public static function push_to_woocommerce(int $woo_user_id, string $field, string $new_value) {
        $mutate = new MealsDB_Sync_Mutate();

        return $mutate->update_wp_user($woo_user_id, [
            $field => $new_value,
        ]);
    }
}
