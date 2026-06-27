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
// the plugin's connection settings. Guard every class reference — if the
// autoloader fails to register (e.g. the plugin directory is being
// pruned by the WP updater while uninstall runs), downstream
// MealsDB_Tables::all() would fatal and WP would still mark the
// plugin deleted without cleaning up a single table.
$plugin_dir = plugin_dir_path(__FILE__);
if (is_readable($plugin_dir . 'includes/class-autoloader.php')) {
    require_once $plugin_dir . 'includes/class-autoloader.php';
    if (class_exists('MealsDB_Autoloader')) {
        MealsDB_Autoloader::register($plugin_dir);
    }
}

if (!class_exists('MealsDB_Tables') || !class_exists('MealsDB_DB')) {
    // Autoloader didn't register — nothing we can do about table
    // cleanup. Still clear options + crons below; those don't
    // depend on plugin classes.
    error_log('[MealsDB Uninstall] Autoloader unavailable; table cleanup skipped, options/crons only.');
}

/**
 * Drop every Meals DB table on the current site, clear cron, and purge
 * options + transients. Used directly on single-site installs and called
 * for every site in the network on Multisite.
 */
function mealsdb_uninstall_cleanup_current_site(): void {
    global $wpdb;

    $drop_failures = [];

    // Drop every plugin-specific table — only if the plugin classes
    // loaded successfully. Options and crons are still cleaned below
    // regardless, so a half-successful uninstall still leaves
    // WordPress in a sane state.
    if (class_exists('MealsDB_Tables') && class_exists('MealsDB_DB')) {
        // Ordered so child tables drop before parents (CLIENT_RATES,
        // CLIENT_ALLOCATIONS, DELIVERY_ALLOCATIONS reference CLIENTS).
        // Derived from the canonical table list so this cannot drift.
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

        // Retired tables (directive STR-LOG): meals_job_log and
        // meals_hook_log were collapsed into meals_event_log and removed
        // from MealsDB_Tables, so MealsDB_Tables::all() no longer lists
        // them. Drop them here by literal prefixed name so installs that
        // upgraded across the collapse don't leave the old physical
        // tables behind. (meals_event_log itself is in all() and dropped
        // by the loop below.)
        $legacy_retired = ['meals_job_log', 'meals_hook_log'];

        foreach (array_merge($drop_order, $legacy_retired) as $base) {
            $table   = MealsDB_DB::get_table_name($base);
            $escaped = str_replace('`', '``', $table);
            $sql     = "DROP TABLE IF EXISTS `{$escaped}`";
            $wpdb->query($sql);
            if ($wpdb->last_error) {
                error_log("Failed to drop $table: " . $wpdb->last_error);
                $drop_failures[] = $table;
            }
        }
    }

    // Record any drop failures in a site transient so the next
    // plugin reactivation can surface an admin notice asking the
    // operator to clean them up manually. Without this, a failed
    // DROP (permissions, foreign-key-checks, table-in-use) would
    // only surface in the error log, which many operators never
    // see.
    if (!empty($drop_failures)) {
        set_transient('mealsdb_uninstall_drop_failures', $drop_failures, DAY_IN_SECONDS * 30);
    }

    // Clear ALL plugin-scheduled cron hooks. The deactivation hook in
    // meals-db-main.php is the canonical list — if you add a new
    // scheduled hook there, mirror it here. On hosts where WP-Cron is
    // disabled (DISABLE_WP_CRON=true), these hooks won't fire anyway,
    // but unscheduling them keeps wp_options.cron clean.
    //
    // HISTORY: The original uninstall cleared only 2 of the 5 hooks;
    // the rest were added in subsequent phases (task engine, Phase W
    // observability). Direct uninstall without prior deactivation
    // (e.g. plugin removed during a WP upgrade before the deactivation
    // hook ran) would leave 3 orphan cron entries that would attempt
    // to fire against undefined callbacks on the next cron tick.
    $plugin_cron_hooks = [
        'mealsdb_nightly_allocation_sync',
        'mealsdb_nightly_sync',
        'mealsdb_nightly_task_sync',
        'mealsdb_daily_report',
        'mealsdb_log_retention',
        'mealsdb_event_digest',
        'mealsdb_derived_value_audit',
    ];

    foreach ($plugin_cron_hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    // Remove plugin options so reinstall is a clean slate. The encryption
    // key lives inside mealsdb_settings, so this also wipes that secret.
    delete_option('mealsdb_settings');
    delete_option('mealsdb_db_version');
    delete_option('mealsdb_zone_delivery_schedule');
    delete_option('mealsdb_overage_product_ids');
    delete_option('mealsdb_fee_product_ids');
    delete_option('mealsdb_appetito_excluded_categories');
    delete_option('mealsdb_legacy_decrypt_disabled');
    // A crashed install can leave this lock row behind; clean it for a true
    // clean-slate uninstall (it's autoload='no', so otherwise an orphan).
    delete_option('mealsdb_install_lock');
    // Directive STR-LOG digest options.
    delete_option('mealsdb_event_digest_last_run');
    delete_option('mealsdb_event_digest_min_severity');
    // Directive DEFINITIONS-1 operator-editable program rates.
    delete_option('mealsdb_rate_definitions');
    // Directive ITEM1-DERIVED per-field auto-correct toggles.
    delete_option('mealsdb_derived_autocorrect');

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

    // Midland packing-slip files (directive 01/04): the meals_slip_batches table
    // is dropped by the loop above, but its on-disk doc 3 scans + merged PDFs
    // live under wp-content/uploads/mealsdb-slips/. They carry decrypted client
    // PII, so a clean-slate uninstall must remove the whole tree.
    if (function_exists('wp_upload_dir')) {
        $uploads = wp_upload_dir();
        if (is_array($uploads) && !empty($uploads['basedir'])) {
            $slip_dir = rtrim((string) $uploads['basedir'], '/\\') . '/mealsdb-slips';
            mealsdb_uninstall_rrmdir($slip_dir);
        }
    }
}

/**
 * Recursively delete a directory and its contents. Best-effort and contained:
 * only descends real directories (no symlink following) and swallows failures
 * so an uninstall is never blocked by a stray locked file.
 */
function mealsdb_uninstall_rrmdir(string $dir): void {
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    $items = @scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            mealsdb_uninstall_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
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
