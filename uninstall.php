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

// Load .env
$env_path = plugin_dir_path(__FILE__) . '.env';
if (!file_exists($env_path)) {
    error_log('Meals DB uninstall aborted: .env file not found.');
    return;
}

$dotenv = parse_ini_file($env_path);
$db_host = $dotenv['MEALS_DB_HOST'] ?? '';
$db_user = $dotenv['MEALS_DB_USER'] ?? '';
$db_pass = $dotenv['MEALS_DB_PASS'] ?? '';
$db_name = $dotenv['MEALS_DB_NAME'] ?? '';

// Connect to external Meals DB
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
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

$conn->close();

// Optional: remove plugin options or transients
// delete_option('mealsdb_plugin_version');
// delete_transient('mealsdb_sync_cache');

?>
