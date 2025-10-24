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
// the plugin's connection settings. The .env file must define PLUGIN_DB_HOST,
// PLUGIN_DB_USER, PLUGIN_DB_PASS, and PLUGIN_DB_NAME — the same variables consumed by
// MealsDB_DB::get_connection().
require_once plugin_dir_path(__FILE__) . 'includes/class-env.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-db.php';

// Load .env values into getenv()/$_ENV.
$env_path = plugin_dir_path(__FILE__) . '.env';
if (!file_exists($env_path)) {
    error_log('Meals DB uninstall aborted: .env file not found.');
    return;
}

MealsDB_Env::load($env_path);

// Connect to external Meals DB using the same logic as the runtime plugin.
$conn = MealsDB_DB::get_connection();

if (!$conn instanceof mysqli) {
    error_log('Meals DB uninstall: failed to connect to database.');
    return;
}

// Drop plugin-specific tables
$tables = [
    'meals_clients',
    'meals_drafts',
    'meals_ignored_conflicts',
    'meals_audit_log'
];

foreach ($tables as $table) {
    $sql = "DROP TABLE IF EXISTS $table";
    if (!$conn->query($sql)) {
        error_log("Failed to drop $table: " . $conn->error);
    }
}

MealsDB_DB::close_connection();

// Optional: remove plugin options or transients
// delete_option('mealsdb_plugin_version');
// delete_transient('mealsdb_sync_cache');

?>
