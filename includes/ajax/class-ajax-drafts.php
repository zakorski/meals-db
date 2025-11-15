<?php
/**
 * AJAX handlers for Meals DB draft management endpoints.
 *
 * @package MealsDB
 */

/**
 * Handles AJAX requests for draft management.
 */
class MealsDB_Ajax_Drafts {

    /**
     * Register the AJAX actions for draft management events.
     *
     * @return void
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_save_draft', [self::class, 'save_draft']);
        add_action('wp_ajax_mealsdb_load_draft', [self::class, 'load_draft']);
        add_action('wp_ajax_mealsdb_clear_draft', [self::class, 'clear_draft']);
    }

    /**
     * Handle saving a draft.
     *
     * @return void
     */
    public static function save_draft(): void {
        // Placeholder for save draft logic.
    }

    /**
     * Handle loading a draft.
     *
     * @return void
     */
    public static function load_draft(): void {
        // Placeholder for load draft logic.
    }

    /**
     * Handle clearing a draft.
     *
     * @return void
     */
    public static function clear_draft(): void {
        // Placeholder for clear draft logic.
    }
}
