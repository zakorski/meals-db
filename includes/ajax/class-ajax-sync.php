<?php
/**
 * AJAX handlers for Meals DB synchronization endpoints.
 *
 * @package MealsDB
 */

/**
 * Handles AJAX requests related to synchronization operations.
 */
class MealsDB_Ajax_Sync {

    /**
     * Register the AJAX actions for synchronization events.
     *
     * @return void
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_force_sync', [self::class, 'force_sync']);
        add_action('wp_ajax_mealsdb_resolve_conflict', [self::class, 'resolve_conflict']);
        add_action('wp_ajax_mealsdb_refresh_mismatches', [self::class, 'refresh_mismatches']);
    }

    /**
     * Handle forcing a synchronization operation.
     *
     * @return void
     */
    public static function force_sync(): void {
        // Placeholder for force sync logic.
    }

    /**
     * Handle resolving synchronization conflicts.
     *
     * @return void
     */
    public static function resolve_conflict(): void {
        // Placeholder for conflict resolution logic.
    }

    /**
     * Handle refreshing synchronization mismatches.
     *
     * @return void
     */
    public static function refresh_mismatches(): void {
        // Placeholder for mismatches refresh logic.
    }
}
