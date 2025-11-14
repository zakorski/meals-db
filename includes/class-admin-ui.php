<?php
/**
 * Admin menu & tab routing for Meals DB plugin.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
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

        $initials_script_path = MEALS_DB_PLUGIN_DIR . 'assets/js/client-initials.js';
        $initials_script_version = file_exists($initials_script_path) ? filemtime($initials_script_path) : MEALS_DB_VERSION;
        wp_enqueue_script(
            'mealsdb-client-initials',
            MEALS_DB_PLUGIN_URL . 'assets/js/client-initials.js',
            ['jquery', 'mealsdb-admin'],
            $initials_script_version,
            true
        );

        wp_localize_script('mealsdb-admin', 'mealsdb', [
            'nonce'   => wp_create_nonce('mealsdb_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ]);

        wp_localize_script('mealsdb-client-initials', 'mealsdbInitials', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces'  => [
                'generate' => wp_create_nonce('mealsdb_generate_initials'),
                'validate' => wp_create_nonce('mealsdb_validate_initials'),
            ],
            'messages' => [
                'success'       => __('Initials are valid.', 'meals-db'),
                'invalid'       => __('These initials are invalid or already in use.', 'meals-db'),
                'required'      => __('Please validate the initials before submitting.', 'meals-db'),
                'empty'         => __('Enter initials before validating.', 'meals-db'),
                'error'         => __('An unexpected error occurred. Please try again.', 'meals-db'),
                'generateError' => __('Unable to generate initials. Please try again.', 'meals-db'),
                'validating'    => __('Validating initials…', 'meals-db'),
            ],
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

            case 'clients':
                $action = $_GET['action'] ?? '';
                if (function_exists('wp_unslash')) {
                    $action = wp_unslash($action);
                }
                if (function_exists('sanitize_key')) {
                    $action = sanitize_key($action);
                } else {
                    $action = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $action));
                }
                if ($action === 'edit') {
                    include plugin_dir_path(__FILE__) . '/../views/edit-client.php';
                } else {
                    include plugin_dir_path(__FILE__) . '/../views/view-clients.php';
                }
                break;

            case 'drafts':
                include plugin_dir_path(__FILE__) . '/../views/drafts.php';
                break;

            case 'ignored':
                include plugin_dir_path(__FILE__) . '/../views/ignored.php';
                break;

            case 'updates':
                include plugin_dir_path(__FILE__) . '/../views/updates.php';
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
        $tabs = [
            'sync'    => __('Sync Dashboard', 'meals-db'),
            'add'     => __('Add New Client', 'meals-db'),
            'clients' => __('View Clients', 'meals-db'),
            'drafts'  => __('Drafts', 'meals-db'),
            'ignored' => __('Ignored Conflicts', 'meals-db'),
            'updates' => __('Updates', 'meals-db'),
        ];

        include MEALS_DB_PLUGIN_DIR . 'views/partials/tabs.php';
    }
}
