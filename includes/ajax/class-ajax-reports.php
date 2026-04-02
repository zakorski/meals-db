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
        add_action('wp_ajax_mealsdb_contribution_reconciliation', [self::class, 'contribution_reconciliation']);
        add_action('wp_ajax_mealsdb_delivery_fee_reconciliation', [self::class, 'delivery_fee_reconciliation']);
        add_action('wp_ajax_mealsdb_private_customer_report', [self::class, 'private_customer_report']);
        add_action('wp_ajax_mealsdb_order_error_report', [self::class, 'order_error_report']);
    }

    /**
     * Generate an Appetito-style purchase order.
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

        $end_date        = isset($_REQUEST['end_date']) ? sanitize_text_field($_REQUEST['end_date']) : gmdate('Y-m-d');
        $weeks_per_period = isset($_REQUEST['weeks_per_period']) ? intval($_REQUEST['weeks_per_period']) : 6;
        $future_inv_date = isset($_REQUEST['future_inv_date']) ? sanitize_text_field($_REQUEST['future_inv_date']) : '';

        // Clamp weeks.
        $weeks_per_period = max(1, min(12, $weeks_per_period));

        $reports  = new MealsDB_Reports($GLOBALS['wpdb']);
        $po_rows  = $reports->generate_purchase_order($end_date, $weeks_per_period, $future_inv_date);
        $csv      = $reports->export_purchase_order_csv($po_rows);

        wp_send_json([
            'success' => true,
            'data'    => $po_rows,
            'csv'     => $csv,
        ]);
    }

    /**
     * Run contribution reconciliation report.
     */
    public static function contribution_reconciliation(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'meals-db')]);
            return;
        }

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date   = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';

        if (!$start_date || !$end_date) {
            wp_send_json_error(['message' => __('Start date and end date are required.', 'meals-db')]);
            return;
        }

        $reports = new MealsDB_Reports($GLOBALS['wpdb']);
        $result  = $reports->contribution_reconciliation($start_date, $end_date);

        wp_send_json_success($result);
    }

    /**
     * Run delivery fee reconciliation report.
     */
    public static function delivery_fee_reconciliation(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'meals-db')]);
            return;
        }

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date   = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';

        if (!$start_date || !$end_date) {
            wp_send_json_error(['message' => __('Start date and end date are required.', 'meals-db')]);
            return;
        }

        $reports = new MealsDB_Reports($GLOBALS['wpdb']);
        $result  = $reports->delivery_fee_reconciliation($start_date, $end_date);

        wp_send_json_success($result);
    }

    /**
     * Run private customer sales report.
     */
    public static function private_customer_report(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'meals-db')]);
            return;
        }

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date   = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';

        if (!$start_date || !$end_date) {
            wp_send_json_error(['message' => __('Start date and end date are required.', 'meals-db')]);
            return;
        }

        $reports = new MealsDB_Reports($GLOBALS['wpdb']);
        $result  = $reports->private_customer_report($start_date, $end_date);
        $csv     = $reports->export_private_report_csv($result);

        wp_send_json([
            'success' => true,
            'data'    => $result,
            'csv'     => $csv,
        ]);
    }

    /**
     * Run order error report.
     */
    public static function order_error_report(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'meals-db')]);
            return;
        }

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date   = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';

        if (!$start_date || !$end_date) {
            wp_send_json_error(['message' => __('Start date and end date are required.', 'meals-db')]);
            return;
        }

        $reports = new MealsDB_Reports($GLOBALS['wpdb']);
        $result  = $reports->order_error_report($start_date, $end_date);

        wp_send_json_success($result);
    }
}
