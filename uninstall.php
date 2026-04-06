<?php
/**
 * Uninstall script for Meals DB plugin
 * Author: Fishhorn Design
 * Work for hire for Meals and More
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit; // Abort if not triggered by WordPress
}

// Optional: do not delete data if this flag is enabled
$preserve_data = false; // Set to true to preserve tables during uninstall

if ($preserve_data) {
    return;
}

// Reuse the runtime environment and database helpers so uninstall stays in sync with
// the plugin's connection settings.
$plugin_dir = plugin_dir_path(__FILE__);
require_once $plugin_dir . 'includes/class-autoloader.php';
MealsDB_Autoloader::register($plugin_dir);

global $wpdb;

// Drop plugin-specific tables (order respects FK dependencies: CLIENT_RATES before CLIENTS)
$tables = [
    MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES),
    MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS),
    MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS),
    MealsDB_DB::get_table_name(MealsDB_Tables::STAFF),
    MealsDB_DB::get_table_name(MealsDB_Tables::DRAFTS),
    MealsDB_DB::get_table_name(MealsDB_Tables::IGNORED_CONFLICTS),
    MealsDB_DB::get_table_name(MealsDB_Tables::AUDIT_LOG),
];

foreach ($tables as $table) {
    $escaped = str_replace('`', '``', $table);
    $sql     = "DROP TABLE IF EXISTS `{$escaped}`";
    $wpdb->query($sql);
    if ($wpdb->last_error) {
        error_log("Failed to drop $table: " . $wpdb->last_error);
    }
}

// Clear scheduled events
wp_clear_scheduled_hook('mealsdb_nightly_sync');

// Optional: remove plugin options or transients
// delete_option('mealsdb_plugin_version');
// delete_transient('mealsdb_sync_cache');
