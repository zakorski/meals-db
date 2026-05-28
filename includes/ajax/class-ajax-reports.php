<?php
/**
 * AJAX handler for report generation endpoints.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

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
        add_action('wp_ajax_mealsdb_spillover_report', [self::class, 'spillover_report']);
    }

    /**
     * Enforce per-user rate limit for expensive report generation.
     */
    private static function enforce_rate_limit(): void {
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }
    }

    /**
     * Generate a seasonally-adjusted purchase order projection.
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

        self::enforce_rate_limit();

        $trailing_weeks      = isset($_REQUEST['trailing_weeks']) ? intval($_REQUEST['trailing_weeks']) : 12;
        $order_horizon_weeks = isset($_REQUEST['order_horizon_weeks']) ? intval($_REQUEST['order_horizon_weeks']) : 6;
        $decay_factor        = isset($_REQUEST['decay_factor']) ? floatval($_REQUEST['decay_factor']) : 0.85;

        // Clamp values.
        $trailing_weeks      = max(1, min(52, $trailing_weeks));
        $order_horizon_weeks = max(1, min(12, $order_horizon_weeks));
        $decay_factor        = max(0.01, min(1.0, $decay_factor));

        $reports  = new MealsDB_Reports($GLOBALS['wpdb']);
        $po_rows  = $reports->generate_purchase_order($trailing_weeks, $order_horizon_weeks, $decay_factor);
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

        self::enforce_rate_limit();

        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date   = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';

        if (!$start_date || !$end_date) {
            wp_send_json_error(['message' => __('Start date and end date are required.', 'meals-db')]);
            return;
        }

        if (!MealsDB_Helpers::is_valid_ymd($start_date) || !MealsDB_Helpers::is_valid_ymd($end_date)) {
            wp_send_json_error(['message' => __('Dates must be in YYYY-MM-DD format.', 'meals-db')]);
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

        self::enforce_rate_limit();

        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date   = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';

        if (!$start_date || !$end_date) {
            wp_send_json_error(['message' => __('Start date and end date are required.', 'meals-db')]);
            return;
        }

        if (!MealsDB_Helpers::is_valid_ymd($start_date) || !MealsDB_Helpers::is_valid_ymd($end_date)) {
            wp_send_json_error(['message' => __('Dates must be in YYYY-MM-DD format.', 'meals-db')]);
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

        self::enforce_rate_limit();

        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date   = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';

        if (!$start_date || !$end_date) {
            wp_send_json_error(['message' => __('Start date and end date are required.', 'meals-db')]);
            return;
        }

        if (!MealsDB_Helpers::is_valid_ymd($start_date) || !MealsDB_Helpers::is_valid_ymd($end_date)) {
            wp_send_json_error(['message' => __('Dates must be in YYYY-MM-DD format.', 'meals-db')]);
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

        self::enforce_rate_limit();

        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date   = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';

        if (!$start_date || !$end_date) {
            wp_send_json_error(['message' => __('Start date and end date are required.', 'meals-db')]);
            return;
        }

        if (!MealsDB_Helpers::is_valid_ymd($start_date) || !MealsDB_Helpers::is_valid_ymd($end_date)) {
            wp_send_json_error(['message' => __('Dates must be in YYYY-MM-DD format.', 'meals-db')]);
            return;
        }

        $reports = new MealsDB_Reports($GLOBALS['wpdb']);
        $result  = $reports->order_error_report($start_date, $end_date);

        wp_send_json_success($result);
    }

    /**
     * Over-allowance spillover report — phase 3.
     * Lists meals delivered in the selected month that spilled into the
     * next month (engine's allowance fill behavior), plus any
     * multi-month-spillover errors logged by the rebuilder.
     */
    public static function spillover_report(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        $capability = MealsDB_Permissions::required_capability();
        if (!is_string($capability) || $capability === '') {
            $capability = 'manage_woocommerce';
        }
        if (!current_user_can($capability)) {
            wp_send_json_error(['message' => __('You are not allowed to perform this action.', 'meals-db')], 403);
            return;
        }

        self::enforce_rate_limit();

        $billing_month = isset($_REQUEST['billing_month'])
            ? sanitize_text_field(wp_unslash((string) $_REQUEST['billing_month']))
            : '';
        // Constrain the month to 01-12: a bare \d{2} would let 2025-13 or
        // 2025-00 through to the service layer, where DateTime either throws
        // (500) or silently normalises to the wrong month.
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $billing_month)) {
            wp_send_json_error(['message' => __('Month must be in YYYY-MM format.', 'meals-db')]);
            return;
        }

        $reports = new MealsDB_Reports($GLOBALS['wpdb']);
        $rows    = $reports->spillover_report($billing_month);
        $csv     = $reports->export_spillover_csv($rows);

        wp_send_json_success([
            'rows' => $rows,
            'csv'  => $csv,
        ]);
    }
}
