<?php
/**
 * Plugin Name: Meals Database
 * Plugin URI: https://github.com/zakorski/meals-db
 * Description: Custom plugin for Meals & More database integration.
 * Version: 1.0.315
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
    define('MEALS_DB_VERSION', '1.0.0');
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

    // Load DB schema and run installer
    require_once plugin_dir_path(__FILE__) . 'includes/install-schema.php';
    MealsDB_Installer::install();

    // Seed the zone delivery schedule option (Phase Q).
    if (false === get_option('mealsdb_zone_delivery_schedule')) {
        add_option('mealsdb_zone_delivery_schedule', [
            'Zone 1' => ['day' => 'Wednesday', 'label' => 'Wednesday morning - Moncton Downtown'],
            'Zone 2' => ['day' => 'Wednesday', 'label' => 'Wednesday afternoon - Sackville / Amherst'],
            'Zone 3' => ['day' => 'Thursday',  'label' => 'Thursday morning - Moncton Other / Sussex'],
            'Zone 4' => ['day' => 'Thursday',  'label' => 'Thursday afternoon - Shediac'],
            'Zone 5' => ['day' => 'Friday',    'label' => 'Friday - Dieppe / Riverview'],
            'Zone 6' => ['day' => 'Thursday',  'label' => 'Thursday morning - Sussex (combined with Zone 3)'],
        ]);
    }
}

// Register the plugin update checker against the GitHub repository.
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';

$updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/zakorski/meals-db/',
    __FILE__,
    'meals-db-main'
);

$updateChecker->setBranch('main');
$updateChecker->getVcsApi()->enableReleaseAssets();
