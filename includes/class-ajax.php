<?php
/**
 * Central bootstrap for Meals DB AJAX handlers.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Ajax {

    /*
     * NOTE: This class is retained solely for backward compatibility.
     * All AJAX actions are registered through the domain-specific handlers in
     * includes/ajax/. New code should call those classes directly.
     */

    /**
     * Instantiate all AJAX handlers.
     *
     * @deprecated 1.0.60 Use the domain-specific MealsDB_Ajax_*::init() methods instead.
     */
    public static function init(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Sync::init()');
        }

        // Init every current domain-specific handler so a caller relying
        // on the legacy one-shot bootstrap gets the same set of AJAX
        // endpoints registered as the modern meals-db-main.php path.
        // Guarded by class_exists so the shim works even if a future
        // refactor splits a handler away.
        foreach ([
            'MealsDB_Ajax_Sync',
            'MealsDB_Ajax_Drafts',
            'MealsDB_Ajax_Clients',
            'MealsDB_Ajax_Staff',
            'MealsDB_Ajax_Initials',
            'MealsDB_Ajax_Invoice',
            'MealsDB_Ajax_Delivery_Slips',
            'MealsDB_Ajax_Reports',
            'MealsDB_Ajax_Settings',
            'MealsDB_Ajax_Migration',
            'MealsDB_Ajax_DB_Sync',
            'MealsDB_Quick_Order_Ajax',
        ] as $handler) {
            if (class_exists($handler) && method_exists($handler, 'init')) {
                $handler::init();
            }
        }
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Sync::sync_field() instead.
     */
    public static function sync_field(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Sync::sync_field');
        }

        MealsDB_Ajax_Sync::sync_field();
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Sync::toggle_ignore() instead.
     */
    public static function toggle_ignore(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Sync::toggle_ignore');
        }

        MealsDB_Ajax_Sync::toggle_ignore();
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Drafts::save_draft() instead.
     */
    public static function save_draft(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Drafts::save_draft');
        }

        MealsDB_Ajax_Drafts::save_draft();
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Drafts::delete_draft() instead.
     */
    public static function delete_draft(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Drafts::delete_draft');
        }

        MealsDB_Ajax_Drafts::delete_draft();
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Sync::check_updates() instead.
     */
    public static function check_updates(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Sync::check_updates');
        }

        MealsDB_Ajax_Sync::check_updates();
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Sync::run_update() instead.
     */
    public static function run_update(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Sync::run_update');
        }

        MealsDB_Ajax_Sync::run_update();
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Sync::update_database() instead.
     */
    public static function update_database(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Sync::update_database');
        }

        MealsDB_Ajax_Sync::update_database();
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Initials::generate_initials() instead.
     */
    public static function generate_initials(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Initials::generate_initials');
        }

        MealsDB_Ajax_Initials::generate_initials();
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Initials::validate_initials() instead.
     */
    public static function validate_initials(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Initials::validate_initials');
        }

        MealsDB_Ajax_Initials::validate_initials();
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Clients::link_client() instead.
     */
    public static function link_client(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Clients::link_client');
        }

        MealsDB_Ajax_Clients::link_client();
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Clients::link_client_to_wp_user() instead.
     */
    public static function link_client_to_wp_user(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Clients::link_client_to_wp_user');
        }

        MealsDB_Ajax_Clients::link_client_to_wp_user();
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Clients::activate_client() instead.
     */
    public static function activate_client(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Clients::activate_client');
        }

        MealsDB_Ajax_Clients::activate_client();
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Clients::deactivate_client() instead.
     */
    public static function deactivate_client(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Clients::deactivate_client');
        }

        MealsDB_Ajax_Clients::deactivate_client();
    }

    /**
     * @deprecated 1.0.60 Use MealsDB_Ajax_Clients::delete_client() instead.
     */
    public static function delete_client(): void {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__METHOD__, '1.0.60', 'MealsDB_Ajax_Clients::delete_client');
        }

        MealsDB_Ajax_Clients::delete_client();
    }
}
