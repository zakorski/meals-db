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
            'manage_woocommerce', // matches shop_manager/admin
            'meals-db',
            [__CLASS__, 'render_main_page'],
            'dashicons-clipboard',
            56
        );
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
        $tabs = [
            'sync' => 'Sync Dashboard',
            'add' => 'Add New Client',
            'drafts' => 'Drafts',
            'ignored' => 'Ignored Conflicts',
        ];

        echo '<nav class="nav-tab-wrapper">';

        foreach ($tabs as $key => $label) {
            $class = ($active === $key) ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url = admin_url('admin.php?page=meals-db&tab=' . $key);

            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }

        echo '</nav>';
    }
}
