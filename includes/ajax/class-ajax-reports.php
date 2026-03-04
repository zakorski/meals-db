<?php
/**
 * AJAX handler for report generation endpoints.
 *
 * @package MealsDB
 */

class MealsDB_Ajax_Reports {

    /**
     * Register AJAX actions.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_generate_purchase_order', [self::class, 'generate_purchase_order']);
    }

    /**
     * Generate a purchase order projection.
     */
    public static function generate_purchase_order(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        $capability = MealsDB_Permissions::required_capability();
        if (!is_string($capability) || $capability === '') {
            $capability = 'manage_woocommerce';
        }
        if (!current_user_can($capability)) {
            wp_send_json([
                'success' => false,
                'message' => __('You are not allowed to perform this action.', 'meals-db'),
            ], 403);
        }

        $weeks_ahead    = isset($_REQUEST['weeks_ahead']) ? intval($_REQUEST['weeks_ahead']) : 1;
        $trailing_weeks = isset($_REQUEST['trailing_weeks']) ? intval($_REQUEST['trailing_weeks']) : 8;

        // Clamp values.
        $weeks_ahead    = max(1, min(12, $weeks_ahead));
        $trailing_weeks = max(1, min(52, $trailing_weeks));

        $reports  = new MealsDB_Reports($GLOBALS['wpdb']);
        $po_rows  = $reports->generate_purchase_order($weeks_ahead, $trailing_weeks);
        $csv      = $reports->export_purchase_order_csv($po_rows);

        wp_send_json([
            'success' => true,
            'data'    => $po_rows,
            'csv'     => $csv,
        ]);
    }
}
