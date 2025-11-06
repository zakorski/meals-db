<?php
/**
 * Access control for Meals DB plugin.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Permissions {

    private const REQUIRED_CAPABILITY = 'manage_woocommerce';

    /**
     * Checks if the current user can access Meals DB plugin features.
     *
     * @return bool
     */
    public static function can_access_plugin(): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        $capability = apply_filters('mealsdb_required_capability', self::REQUIRED_CAPABILITY);

        if (!is_string($capability) || $capability === '') {
            $capability = self::REQUIRED_CAPABILITY;
        }

        return current_user_can($capability);
    }

    /**
     * Retrieve the capability required to access the plugin UI.
     *
     * @return string
     */
    public static function required_capability(): string {
        $capability = apply_filters('mealsdb_required_capability', self::REQUIRED_CAPABILITY);

        if (!is_string($capability) || $capability === '') {
            return self::REQUIRED_CAPABILITY;
        }

        return $capability;
    }

    /**
     * Enforce permissions, or show error screen.
     *
     * Call this at the top of any protected page/view.
     */
    public static function enforce() {
        if (!self::can_access_plugin()) {
            wp_die(
                __('You do not have permission to access Meals DB.', 'meals-db'),
                __('Access Denied', 'meals-db'),
                ['back_link' => true]
            );
        }
    }
}
