<?php
/**
 * Invoice Generation Admin Page
 *
 * Provides UI for generating government invoices (SDNB and VAC)
 *
 * @package MealsDB
 * @since 1.0.249
 */

if (!defined('ABSPATH')) {
    exit;
}

class MealsDB_Invoice_Page {

    /**
     * Initialize the admin page
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
    }

    /**
     * Register the admin menu page
     */
    public static function register_menu() {
        add_submenu_page(
            'mealsdb',                                   // Parent slug
            'Government Invoices',                       // Page title
            'Invoices',                                  // Menu title
            MealsDB_Permissions::required_capability(), // Capability
            'mealsdb-invoices',                         // Menu slug
            [__CLASS__, 'render_page']                  // Callback
        );
    }

    /**
     * Enqueue scripts and styles for the invoice page
     */
    public static function enqueue_scripts($hook) {
        if ($hook !== 'meals-db_page_mealsdb-invoices') {
            return;
        }

        // Enqueue WordPress date picker
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');

        // Custom invoice script
        wp_enqueue_script(
            'mealsdb-invoice-js',
            plugins_url('assets/js/invoice.js', dirname(dirname(__FILE__))),
            ['jquery', 'jquery-ui-datepicker'],
            '1.0.249',
            true
        );

        $invoice_data = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('mealsdb_invoice_nonce'),
        ];
        wp_add_inline_script(
            'mealsdb-invoice-js',
            'window.mealsdbInvoice = ' . wp_json_encode($invoice_data) . ';',
            'before'
        );
    }

    /**
     * Render the invoice generation page
     */
    public static function render_page() {
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Get zones from database
        $zones = self::get_available_zones();

        // Include the view
        include dirname(dirname(dirname(__FILE__))) . '/views/admin-invoice.php';
    }

    /**
     * Get available delivery zones from database
     *
     * @return array Array of zone codes
     */
    private static function get_available_zones() {
        global $wpdb;

        $table = MealsDB_DB::table(MealsDB_Tables::CLIENTS);
        $query = "
            SELECT DISTINCT delivery_area_zone
            FROM `{$table}`
            WHERE client_type = 'SDNB'
                AND use_legacy_billing = 1
                AND delivery_area_zone IS NOT NULL
                AND delivery_area_zone != ''
            ORDER BY delivery_area_zone
        ";

        $zones = $wpdb->get_col($query);

        // Default zones if none found
        if (empty($zones)) {
            $zones = ['M', 'S'];
        }

        return $zones;
    }
}
