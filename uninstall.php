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

// Drop every plugin-specific table. Ordered so child tables drop before
// parents (CLIENT_RATES, CLIENT_ALLOCATIONS, DELIVERY_ALLOCATIONS reference
// CLIENTS). Derived from the canonical table list in MealsDB_Tables so this
// list cannot drift from the schema again.
$drop_order = [
    MealsDB_Tables::CLIENT_RATES,
    MealsDB_Tables::CLIENT_ALLOCATIONS,
    MealsDB_Tables::DELIVERY_ALLOCATIONS,
    MealsDB_Tables::DRAFTS,
    MealsDB_Tables::IGNORED_CONFLICTS,
    MealsDB_Tables::AUDIT_LOG,
    MealsDB_Tables::STAFF,
    MealsDB_Tables::PRODUCTS,
    MealsDB_Tables::CLIENTS,
];

// Defensive: include any table declared in MealsDB_Tables::all() but missing
// from the explicit drop order above.
foreach (MealsDB_Tables::all() as $base) {
    if (!in_array($base, $drop_order, true)) {
        $drop_order[] = $base;
    }
}

foreach ($drop_order as $base) {
    $table   = MealsDB_DB::get_table_name($base);
    $escaped = str_replace('`', '``', $table);
    $sql     = "DROP TABLE IF EXISTS `{$escaped}`";
    $wpdb->query($sql);
    if ($wpdb->last_error) {
        error_log("Failed to drop $table: " . $wpdb->last_error);
    }
}

// Clear scheduled events. Note: the actual hook name registered by the
// plugin is mealsdb_nightly_allocation_sync (see meals-db-main.php).
wp_clear_scheduled_hook('mealsdb_nightly_allocation_sync');
wp_clear_scheduled_hook('mealsdb_nightly_sync'); // legacy name, for safety

// Remove plugin options so reinstall is a clean slate.
delete_option('mealsdb_settings');
delete_option('mealsdb_db_version');
delete_option('mealsdb_zone_delivery_schedule');
