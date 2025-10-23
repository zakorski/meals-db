<?php
/**
 * Access control for Meals DB plugin.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Created as a work for hire for Meals and More.
 */

class MealsDB_Permissions {

    /**
     * Checks if the current user can access Meals DB plugin features.
     *
     * @return bool
     */
    public static function can_access_plugin(): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        $user = wp_get_current_user();

        if (empty($user->roles)) {
            return false;
        }

        $allowed_roles = ['administrator', 'shop_manager'];

        foreach ($allowed_roles as $role) {
            if (in_array($role, (array) $user->roles, true)) {
                return true;
            }
        }

        return false;
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
