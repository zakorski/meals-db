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
require_once plugin_dir_path(__FILE__) . 'includes/class-db.php';

// Confirm required constants exist before proceeding.
if (!defined('MEALS_DB_HOST') || !defined('MEALS_DB_USER') || !defined('MEALS_DB_NAME')) {
    error_log('Meals DB uninstall aborted: configuration constants are missing.');
    return;
}

// Connect to external Meals DB using the same logic as the runtime plugin.
$conn = MealsDB_DB::get_connection();

if (!$conn instanceof mysqli) {
    error_log('Meals DB uninstall: failed to connect to database.');
    return;
}

// Drop plugin-specific tables
$tables = [
    MealsDB_DB::get_table_name('meals_clients'),
    'meals_drafts',
    'meals_ignored_conflicts',
    'meals_audit_log',
];

foreach ($tables as $table) {
    $tableSafe = $conn->real_escape_string($table);
    $sql       = "DROP TABLE IF EXISTS `{$tableSafe}`";
    if (!$conn->query($sql)) {
        error_log("Failed to drop $table: " . $conn->error);
    }
}

MealsDB_DB::close_connection();

// Optional: remove plugin options or transients
// delete_option('mealsdb_plugin_version');
// delete_transient('mealsdb_sync_cache');

?>
