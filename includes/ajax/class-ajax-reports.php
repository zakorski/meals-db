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
            // U05-reports-11: use wp_send_json_error (message under data.message)
            // so the shape matches the other handlers and the report consumers,
            // which all read resp.data.message. The old top-level 'message' shape
            // never displayed.
            wp_send_json_error(
                ['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')],
                429
            );
        }
    }

    /**
     * Read + validate the start_date/end_date POST pair shared by the four
     * date-ranged reconciliation reports (U05-reports-10). On failure it emits
     * the SAME wp_send_json_error the four handlers used inline and returns
     * null, so the caller returns without running the report; on success it
     * returns [start_date, end_date]. Keeping the send-error-then-return
     * contract preserves each handler's original control flow byte-for-byte.
     *
     * @return array{0:string,1:string}|null
     */
    private static function require_date_range(): ?array {
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date   = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';

        if (!$start_date || !$end_date) {
            wp_send_json_error(['message' => __('Start date and end date are required.', 'meals-db')]);
            return null;
        }

        if (!MealsDB_Helpers::is_valid_ymd($start_date) || !MealsDB_Helpers::is_valid_ymd($end_date)) {
            wp_send_json_error(['message' => __('Dates must be in YYYY-MM-DD format.', 'meals-db')]);
            return null;
        }

        return [$start_date, $end_date];
    }

    /**
     * Generate a seasonally-adjusted purchase order projection.
     */
    public static function generate_purchase_order(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        // can_access_plugin() already resolves the required capability (filter +
        // whitelist + non-empty fallback) and checks it — no inline fallback
        // copy to drift out of lockstep (U05-reports-10). A logged-out user
        // fails current_user_can() too, so the reject set is unchanged.
        if (!MealsDB_Permissions::can_access_plugin()) {
            // U05-reports-11: match the data.message shape the consumers read.
            wp_send_json_error(
                ['message' => __('You are not allowed to perform this action.', 'meals-db')],
                403
            );
        }

        self::enforce_rate_limit();

        // The forecasting model is locked to the back-test-validated
        // configuration (12-week recency-weighted history, decay 0.85, 6-week
        // horizon + 3-week demand-proportional buffer = 9 weeks coverage). It
        // takes no parameters by design — any trailing/horizon/decay request
        // inputs are ignored (the controls were removed from the view).
        $reports  = new MealsDB_Reports($GLOBALS['wpdb']);
        $po_rows  = $reports->generate_purchase_order();
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

        $range = self::require_date_range();
        if ($range === null) {
            return;
        }
        [$start_date, $end_date] = $range;

        $reports = new MealsDB_Reports($GLOBALS['wpdb']);
        $result  = $reports->contribution_reconciliation($start_date, $end_date);

        // The service layer guards against a multi-month range (the contribution
        // is a flat per-billing-month charge, so any range spanning >1 month
        // always reports a false discrepancy) and returns an 'error' key with
        // empty rows. Surface that as a real error response rather than wrapping
        // it in success — otherwise the JS renders an empty $0.00 table and the
        // operator never sees why. Mirrors the permission/date-format error
        // shape above; the reports JS already displays data.message.
        if (!empty($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
            return;
        }

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

        $range = self::require_date_range();
        if ($range === null) {
            return;
        }
        [$start_date, $end_date] = $range;

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

        $range = self::require_date_range();
        if ($range === null) {
            return;
        }
        [$start_date, $end_date] = $range;

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

        $range = self::require_date_range();
        if ($range === null) {
            return;
        }
        [$start_date, $end_date] = $range;

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

        // can_access_plugin() encapsulates the required-capability resolution
        // (filter + whitelist + non-empty fallback) and the check — one place,
        // no inline copy (U05-reports-10).
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('You are not allowed to perform this action.', 'meals-db')], 403);
            return;
        }

        self::enforce_rate_limit();

        $billing_month = isset($_REQUEST['billing_month'])
            ? sanitize_text_field(wp_unslash((string) $_REQUEST['billing_month']))
            : '';
        // Constrain the month to 01-12: a bare \d{2} would let 2025-13 or
        // 2025-00 through to the service layer, where DateTime either throws
        // (500) or silently normalises to the wrong month. is_valid_ym()
        // enforces exactly that (format + 01-12 range) — one implementation
        // shared with the sibling YMD handlers (U05-reports-12).
        if (!MealsDB_Helpers::is_valid_ym($billing_month)) {
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
