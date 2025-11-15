<?php
/**
 * AJAX handlers for Meals DB client management endpoints.
 *
 * @package MealsDB
 */

/**
 * Handles AJAX requests for client management.
 */
class MealsDB_Ajax_Clients {

    /**
     * Register the AJAX actions for client management events.
     *
     * @return void
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_update_client', [self::class, 'update_client']);
        add_action('wp_ajax_mealsdb_delete_client', [self::class, 'delete_client']);
        add_action('wp_ajax_mealsdb_deactivate_client', [self::class, 'deactivate_client']);
    }

    /**
     * Handle updating a client.
     *
     * @return void
     */
    public static function update_client(): void {
        // Placeholder for update client logic.
    }

    /**
     * Handle deleting a client.
     *
     * @return void
     */
    public static function delete_client(): void {
        // Placeholder for delete client logic.
    }

    /**
     * Handle deactivating a client.
     *
     * @return void
     */
    public static function deactivate_client(): void {
        // Placeholder for deactivate client logic.
    }
}
