<?php
/**
 * Plugin Name: Meals Database
 * Plugin URI: https://github.com/zakorski/meals-db
 * Description: Custom plugin for Meals & More database integration.
 * Version: 1.0.330
 * Author: Zak Sikorski
 * Author URI: https://zakorski.com
 * GitHub Plugin URI: zakorski/meals-db
 * Primary Branch: main
 * License: GPL-3.0-or-later
 * Requires PHP: 7.4
 * Requires at least: 5.8
 *
 * This plugin is licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

if (!defined('MEALS_DB_PLUGIN_FILE')) {
    define('MEALS_DB_PLUGIN_FILE', __FILE__);
}

if (!defined('MEALS_DB_PLUGIN_DIR')) {
    define('MEALS_DB_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('MEALS_DB_PLUGIN_URL')) {
    define('MEALS_DB_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (!defined('MEALS_DB_VERSION')) {
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $mealsdb_plugin_data = get_plugin_data(__FILE__, false, false);
    define('MEALS_DB_VERSION', $mealsdb_plugin_data['Version'] ?? '0.0.0');
}

// Old Autoloader - Deprecated.

require_once plugin_dir_path(__FILE__) . 'includes/class-autoloader.php';
MealsDB_Autoloader::register(MEALS_DB_PLUGIN_DIR);

add_action('plugins_loaded', function() {
    if (class_exists('MealsDB_Config')) {
        MealsDB_Config::instance();
    }
});

// New loader for cleaner plugin interface.

require_once __DIR__ . '/includes/class-plugin.php';
MealsDB_Plugin::init();

/**
 * Initialize plugin functionality after all plugins are loaded.
 */
add_action('plugins_loaded', function () {
    MealsDB_Admin_UI::init();
    MealsDB_Ajax_Sync::init();
    MealsDB_Ajax_Clients::init();
    MealsDB_Ajax_Staff::init();
    MealsDB_Ajax_Drafts::init();
    MealsDB_Ajax_Initials::init();
    MealsDB_Ajax_Invoice::init();
    MealsDB_Ajax_Delivery_Slips::init();
    MealsDB_Ajax_Reports::init();
    MealsDB_Quick_Order_Ajax::init();
    MealsDB_Staff::init();
    MealsDB_WC_Product_Tab::init();
    MealsDB_Invoice_Page::init();
    MealsDB_Migration_Page::init();
    MealsDB_Ajax_Migration::init();
    MealsDB_Ajax_DB_Sync::init();
    MealsDB_Ajax_Settings::init();
    MealsDB_Product_Display_Sync::init();
    MealsDB_Sync::register_hooks();
    MealsDB_Allocation_Hooks::init();
});

/**
 * Run schema install/upgrade when the plugin version advances.
 *
 * WordPress only fires register_activation_hook() on explicit activation,
 * so auto-updates, manual file replacement, and fresh activation (which
 * hands the schema off to this block rather than doing the heavy work
 * inside the activation hook itself) all funnel through the same path
 * here. The installer is idempotent; this hook just decides whether
 * it's time to run it and serialises concurrent admin requests.
 */
add_action('admin_init', 'mealsdb_maybe_upgrade_schema');

function mealsdb_maybe_upgrade_schema(): void {
    // Only run the installer for users who could legitimately be
    // triggering a plugin upgrade. admin_init also fires on
    // admin-ajax.php and for any logged-in user who happens to hit an
    // admin-facing URL; there's no reason to run dbDelta on behalf of a
    // subscriber poking at the UI.
    if (!current_user_can('activate_plugins') && !current_user_can('manage_options')) {
        return;
    }

    $stored = get_option('mealsdb_db_version', '0.0.0');
    if (version_compare($stored, MEALS_DB_VERSION, '>=')) {
        return;
    }

    global $wpdb;

    // Stale-lock recovery. If the previous attempt died mid-install (PHP
    // fatal, OOM kill, request timeout), the lock row was never
    // released. 5 minutes is well past any realistic dbDelta runtime, so
    // treat anything older as abandoned and clear it. Read directly
    // from the DB to bypass the notoptions cache, which can incorrectly
    // claim the row doesn't exist after a prior request's fatal error.
    $held_at = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1",
        'mealsdb_install_lock'
    ));
    if ($held_at > 0 && (time() - $held_at) > 300) {
        delete_option('mealsdb_install_lock');
    }

    // Atomic lock acquisition via INSERT IGNORE. The InnoDB UNIQUE
    // index on option_name serialises concurrent inserts: whichever
    // request lands first gets rows_affected=1, every other request
    // gets 0 and returns early. A transient or a WP-API add_option()
    // is NOT safe here because add_option() uses INSERT ... ON
    // DUPLICATE KEY UPDATE, which succeeds (as an update) under
    // contention — the old transient-based check was effectively a
    // no-op under real parallel load.
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, %s)",
        'mealsdb_install_lock',
        (string) time(),
        'no'
    ));

    if ((int) $wpdb->rows_affected !== 1) {
        return;
    }

    // Invalidate the notoptions cache so the release-path delete_option
    // below actually clears the real row rather than short-circuiting
    // on a stale "option does not exist" cache entry.
    wp_cache_delete('notoptions', 'options');

    try {
        require_once plugin_dir_path(__FILE__) . 'includes/install-schema.php';
        MealsDB_Installer::install();

        // Seed zone schedule for auto-updated installs that never
        // fired register_activation_hook. autoload='no' because this
        // is only read from the delivery-slip and report paths, not
        // every page load.
        if (false === get_option('mealsdb_zone_delivery_schedule')) {
            add_option('mealsdb_zone_delivery_schedule', [
                'Zone 1' => ['day' => 'Wednesday', 'label' => 'Wednesday morning - Moncton Downtown'],
                'Zone 2' => ['day' => 'Wednesday', 'label' => 'Wednesday afternoon - Sackville / Amherst'],
                'Zone 3' => ['day' => 'Thursday',  'label' => 'Thursday morning - Moncton Other / Sussex'],
                'Zone 4' => ['day' => 'Thursday',  'label' => 'Thursday afternoon - Shediac'],
                'Zone 5' => ['day' => 'Friday',    'label' => 'Friday - Dieppe / Riverview'],
                'Zone 6' => ['day' => 'Thursday',  'label' => 'Thursday morning - Sussex (combined with Zone 3)'],
            ], '', 'no');
        }

        update_option('mealsdb_db_version', MEALS_DB_VERSION, false);
    } catch (Throwable $e) {
        // Log but don't rethrow — next admin page load retries. The
        // lock still releases via the finally below so a single
        // failed attempt doesn't block recovery.
        error_log('[MealsDB] Install/upgrade failed: ' . $e->getMessage());
    } finally {
        delete_option('mealsdb_install_lock');
    }
}

/**
 * Notice shown in the admin area while the schema is still pending
 * install (stored version = 0.0.0). The installer runs on admin_init,
 * so a single admin page load after activation clears this.
 */
add_action('admin_notices', function () {
    if (get_option('mealsdb_db_version', '0.0.0') !== '0.0.0') {
        return;
    }
    if (!current_user_can('activate_plugins') && !current_user_can('manage_options')) {
        return;
    }
    echo '<div class="notice notice-info"><p>';
    echo esc_html__(
        'Meals Database: finishing schema installation on the next admin page load…',
        'meals-db'
    );
    echo '</p></div>';
});

/**
 * Check minimum PHP and WordPress versions before allowing activation.
 */
register_activation_hook(__FILE__, 'meals_db_check_requirements');

function meals_db_check_requirements() {
    global $wp_version;

    $required_php_version = '7.4';
    $required_wp_version = '5.8';

    if (version_compare(PHP_VERSION, $required_php_version, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                esc_html__(
                    'Meals DB requires PHP version %1$s or higher. Your current version is: %2$s.',
                    'meals-db'
                ),
                $required_php_version,
                PHP_VERSION
            ),
            esc_html__('Plugin Activation Error', 'meals-db'),
            ['back_link' => true]
        );
    }

    if (version_compare($wp_version, $required_wp_version, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                esc_html__(
                    'Meals DB requires WordPress version %1$s or higher. Your current version is: %2$s.',
                    'meals-db'
                ),
                $required_wp_version,
                $wp_version
            ),
            esc_html__('Plugin Activation Error', 'meals-db'),
            ['back_link' => true]
        );
    }

    // Schema installation and zone-schedule seeding run on the next
    // admin page load via mealsdb_maybe_upgrade_schema(), which is
    // atomic, idempotent, and also handles auto-updates and manual
    // file replacement where register_activation_hook() never fires.
    // Keeping heavy dbDelta work out of the activation request avoids
    // activation-time PHP timeouts that would otherwise leave the
    // plugin deactivated with a half-built schema.
}

/**
 * Clean up scheduled events on plugin deactivation.
 */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('mealsdb_nightly_allocation_sync');
});

// Register the plugin update checker against the GitHub repository.
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';

$updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/zakorski/meals-db/',
    __FILE__,
    'meals-db-main'
);

$updateChecker->setBranch('main');
$updateChecker->getVcsApi()->enableReleaseAssets();
