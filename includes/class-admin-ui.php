<?php
/**
 * Admin menu & tab routing for Meals DB plugin.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Created as a work for hire for Meals and More.
 */

class MealsDB_Admin_UI {

    /**
     * Initialize the admin UI: menu + routing.
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Register the Meals DB menu and subpage.
     */
    public static function register_menu() {
        if (!MealsDB_Permissions::can_access_plugin()) {
            return;
        }

        add_menu_page(
            'Meals DB',
            'Meals DB',
            MealsDB_Permissions::required_capability(),
            'meals-db',
            [__CLASS__, 'render_main_page'],
            'dashicons-clipboard',
            56
        );
    }

    /**
     * Enqueue admin scripts and styles for Meals DB screens.
     *
     * @param string $hook
     */
    public static function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_meals-db') {
            return;
        }

        $style_path = MEALS_DB_PLUGIN_DIR . 'assets/css/admin.css';
        $style_version = file_exists($style_path) ? filemtime($style_path) : MEALS_DB_VERSION;
        wp_enqueue_style(
            'mealsdb-admin',
            MEALS_DB_PLUGIN_URL . 'assets/css/admin.css',
            [],
            $style_version
        );

        $script_path = MEALS_DB_PLUGIN_DIR . 'assets/js/admin.js';
        $script_version = file_exists($script_path) ? filemtime($script_path) : MEALS_DB_VERSION;
        wp_enqueue_script(
            'mealsdb-admin',
            MEALS_DB_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'jquery-ui-datepicker'],
            $script_version,
            true
        );

        wp_localize_script('mealsdb-admin', 'mealsdb', [
            'nonce' => wp_create_nonce('mealsdb_nonce'),
        ]);
    }

    /**
     * Render the main admin page, routing to correct tab.
     */
    public static function render_main_page() {
        MealsDB_Permissions::enforce();

        $tab = $_GET['tab'] ?? 'sync';

        echo '<div class="wrap">';
        echo '<h1>Meals DB</h1>';

        self::render_tabs($tab);

        echo '<div class="mealsdb-tab-content">';

        switch ($tab) {
            case 'sync':
                include plugin_dir_path(__FILE__) . '/../views/dashboard.php';
                break;

            case 'add':
                include plugin_dir_path(__FILE__) . '/../views/add-client.php';
                break;

            case 'drafts':
                include plugin_dir_path(__FILE__) . '/../views/drafts.php';
                break;

            case 'ignored':
                include plugin_dir_path(__FILE__) . '/../views/ignored.php';
                break;

            default:
                echo '<p>Invalid tab selected.</p>';
        }

        echo '</div></div>';
    }

    /**
     * Renders the tab navigation.
     *
     * @param string $active
     */
    private static function render_tabs(string $active = 'sync') {
        $active_tab = $active;
        include MEALS_DB_PLUGIN_DIR . 'views/partials/tabs.php';
    }
}
