<?php
/**
 * Plugin Name: Meals DB
 * Description: Secure, encrypted client database with WooCommerce sync, conflict resolution dashboard, and audit logging.
 * Version: 1.0.0
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * License: Proprietary - Work for hire for Meals and More
 * Requires PHP: 7.4
 * Requires at least: 5.8
 *
 * This plugin was developed by Fishhorn Design as a work for hire for Meals and More.
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
}

/**
 * Load environment variables from the plugin's .env file.
 */
require_once plugin_dir_path(__FILE__) . 'includes/class-env.php';
MealsDB_Env::load(plugin_dir_path(__FILE__) . '.env');

/**
 * Load core plugin classes.
 */
require_once plugin_dir_path(__FILE__) . 'includes/class-db.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-encryption.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-sync.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-client-form.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin-ui.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ajax.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-permissions.php';

/**
 * Initialize plugin functionality after all plugins are loaded.
 */
add_action('plugins_loaded', function () {
    MealsDB_Admin_UI::init();
    MealsDB_Ajax::init();
});
