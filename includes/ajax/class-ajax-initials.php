<?php
/**
 * AJAX handlers for Meals DB initials validation endpoints.
 *
 * @package MealsDB
 */

/**
 * Handles AJAX requests for initials validation.
 */
class MealsDB_Ajax_Initials {

    /**
     * Register the AJAX actions for initials validation events.
     *
     * @return void
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_validate_initials', [self::class, 'validate_initials']);
        add_action('wp_ajax_mealsdb_check_initials_availability', [self::class, 'check_initials_availability']);
    }

    /**
     * Handle validating initials.
     *
     * @return void
     */
    public static function validate_initials(): void {
        // Placeholder for validate initials logic.
    }

    /**
     * Handle checking if initials are available.
     *
     * @return void
     */
    public static function check_initials_availability(): void {
        // Placeholder for initials availability logic.
    }
}
