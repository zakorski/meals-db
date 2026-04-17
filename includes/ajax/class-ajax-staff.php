<?php
/**
 * AJAX handlers for Meals DB staff management endpoints.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

/**
 * Handles AJAX requests for staff management.
 */
class MealsDB_Ajax_Staff {

    /**
     * Register the AJAX actions for staff management events.
     *
     * @return void
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_add_staff', [self::class, 'add_staff']);
        add_action('wp_ajax_mealsdb_update_staff', [self::class, 'update_staff']);
        add_action('wp_ajax_mealsdb_deactivate_staff', [self::class, 'deactivate_staff']);
    }

    /**
     * Handle adding a staff member.
     *
     * @return void
     */
    public static function add_staff(): void {
        $table = self::get_staff_table_name();

        self::send_error(
            __('Adding staff via AJAX is not available at this time.', 'meals-db'),
            sprintf('[MealsDB AJAX Staff] add_staff called without implementation. Table: %s', $table)
        );
    }

    /**
     * Handle updating a staff member.
     *
     * @return void
     */
    public static function update_staff(): void {
        $table = self::get_staff_table_name();

        self::send_error(
            __('Updating staff via AJAX is not available at this time.', 'meals-db'),
            sprintf('[MealsDB AJAX Staff] update_staff called without implementation. Table: %s', $table)
        );
    }

    /**
     * Handle deactivating a staff member.
     *
     * @return void
     */
    public static function deactivate_staff(): void {
        $table = self::get_staff_table_name();

        self::send_error(
            __('Deactivating staff via AJAX is not available at this time.', 'meals-db'),
            sprintf('[MealsDB AJAX Staff] deactivate_staff called without implementation. Table: %s', $table)
        );
    }

    /**
     * Resolve and escape the staff table name for safe use in SQL identifiers.
     */
    private static function get_staff_table_name(): string {
        $table_name = MealsDB_DB::get_table_name(MealsDB_Tables::STAFF);

        $escaped_table = str_replace('`', '``', $table_name);

        return '`' . $escaped_table . '`';
    }

    /**
     * Send a standardized JSON error response and log the underlying issue.
     */
    private static function send_error(string $message, string $log_message = ''): void {
        if ($log_message !== '') {
            MealsDB_Logger::error($log_message);
        }

        wp_send_json_error([
            'message' => $message,
        ]);
    }
}
