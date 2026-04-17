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

/**
 * Drop every Meals DB table on the current site, clear cron, and purge
 * options + transients. Used directly on single-site installs and called
 * for every site in the network on Multisite.
 */
function mealsdb_uninstall_cleanup_current_site(): void {
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

    // Remove plugin options so reinstall is a clean slate. The encryption
    // key lives inside mealsdb_settings, so this also wipes that secret.
    delete_option('mealsdb_settings');
    delete_option('mealsdb_db_version');
    delete_option('mealsdb_zone_delivery_schedule');
    delete_option('mealsdb_overage_product_ids');
    delete_option('mealsdb_legacy_decrypt_disabled');

    // Plugin transients — caches that would otherwise linger as stale
    // wp_options rows after the tables they describe are gone.
    delete_transient('mealsdb_qo_all_products');
    delete_transient('mealsdb_qo_categories');

    // Per-user migration credential and rate-limit transients are stored
    // with random tokens, so blanket-delete by prefix.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_mealsdb_mig_creds_%'
            OR option_name LIKE '_transient_timeout_mealsdb_mig_creds_%'
            OR option_name LIKE '_transient_mealsdb_rate_%'
            OR option_name LIKE '_transient_timeout_mealsdb_rate_%'
            OR option_name LIKE '_transient_mealsdb_qo_%'
            OR option_name LIKE '_transient_timeout_mealsdb_qo_%'"
    );
}

if (is_multisite()) {
    // Iterate every site in the network so each blog's prefix gets
    // cleaned up. Without this, only the network-admin's current blog's
    // tables and options would be removed.
    $sites = function_exists('get_sites') ? get_sites(['fields' => 'ids', 'number' => 0]) : [];
    foreach ($sites as $site_id) {
        switch_to_blog((int) $site_id);
        try {
            mealsdb_uninstall_cleanup_current_site();
        } finally {
            restore_current_blog();
        }
    }
} else {
    mealsdb_uninstall_cleanup_current_site();
}
