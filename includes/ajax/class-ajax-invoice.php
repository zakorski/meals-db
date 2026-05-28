<?php
/**
 * AJAX Handler for Invoice Generation
 *
 * Handles AJAX requests for generating and downloading government invoices
 *
 * @package MealsDB
 * @since 1.0.249
 */

if (!defined('ABSPATH')) {
    exit;
}

class MealsDB_Ajax_Invoice {

    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        add_action('wp_ajax_mealsdb_generate_invoice', [__CLASS__, 'generate_invoice']);
    }

    /**
     * Handle invoice generation AJAX request
     */
    public static function generate_invoice() {
        // Verify nonce
        if (!check_ajax_referer('mealsdb_invoice_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        // Check permissions
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
            return;
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('client_modify')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
            return;
        }

        // Get and validate parameters
        $invoice_type = sanitize_text_field(wp_unslash($_POST['invoice_type'] ?? ''));
        $start_date = sanitize_text_field(wp_unslash($_POST['start_date'] ?? ''));
        $end_date = sanitize_text_field(wp_unslash($_POST['end_date'] ?? ''));
        $zone = sanitize_text_field(wp_unslash($_POST['zone'] ?? ''));
        $weeks_in_month = intval($_POST['weeks_in_month'] ?? 4);
        if ($weeks_in_month < 1 || $weeks_in_month > 6) {
            $weeks_in_month = 4;
        }

        // Validate dates
        if (empty($start_date) || empty($end_date)) {
            wp_send_json_error(['message' => 'Start date and end date are required.']);
            return;
        }

        if (!self::validate_date($start_date) || !self::validate_date($end_date)) {
            wp_send_json_error(['message' => 'Invalid date format. Use YYYY-MM-DD.']);
            return;
        }

        if (strtotime($start_date) > strtotime($end_date)) {
            wp_send_json_error(['message' => 'Start date must be before or equal to end date.']);
            return;
        }

        try {
            switch ($invoice_type) {
                case 'sdnb_legacy':
                    if (empty($zone)) {
                        wp_send_json_error(['message' => 'Zone is required for SDNB legacy invoices.']);
                        return;
                    }
                    $zone_canonical = strtoupper(trim($zone));
                    if (!in_array($zone_canonical, self::allowed_sdnb_zones(), true)) {
                        wp_send_json_error(['message' => 'Unknown SDNB zone.']);
                        return;
                    }
                    self::download_sdnb_legacy($zone_canonical, $start_date, $end_date, $weeks_in_month);
                    break;

                case 'sdnb_portal':
                    self::download_sdnb_portal($start_date, $end_date);
                    break;

                case 'vac_csv':
                    self::download_vac_csv($start_date, $end_date);
                    break;

                case 'vac_pdf':
                    self::download_vac_pdf($start_date, $end_date);
                    break;

                default:
                    wp_send_json_error(['message' => 'Invalid invoice type.']);
                    return;
            }
        } catch (Exception $e) {
            error_log('[MealsDB Invoice] generate_invoice failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to generate invoice. Please contact an administrator.', 'meals-db')]);
        }
    }

    /**
     * Validate date format (YYYY-MM-DD)
     *
     * @param string $date Date string
     * @return bool True if valid
     */
    private static function validate_date($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Allowed SDNB service-zone codes.
     *
     * Matches MealsDB_Invoice_Generator::$service_centers and the
     * meals_clients.delivery_area_zone values the generator queries
     * against. Exposed via a filter so a deployment with additional
     * service centers can extend the list without patching this
     * handler. Unknown zones are rejected outright rather than silently
     * falling back to Moncton ('M').
     *
     * @return array<int, string>
     */
    private static function allowed_sdnb_zones(): array {
        $defaults = ['M', 'S'];
        if (!function_exists('apply_filters')) {
            return $defaults;
        }
        $zones = apply_filters('mealsdb_allowed_sdnb_zones', $defaults);
        if (!is_array($zones) || empty($zones)) {
            return $defaults;
        }
        $clean = [];
        foreach ($zones as $z) {
            if (is_string($z) && $z !== '') {
                $clean[] = strtoupper(trim($z));
            }
        }
        return !empty($clean) ? array_values(array_unique($clean)) : $defaults;
    }

    /**
     * Strip everything except [A-Za-z0-9_-] from a value before using it as a
     * Content-Disposition filename token. sanitize_text_field() preserves
     * quotes, which would otherwise let a value like  evil"; filename="x
     * inject a second filename parameter into the response header.
     */
    private static function safe_filename_token(string $value): string {
        $clean = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?? '';
        $clean = trim($clean, '_-');
        return $clean === '' ? 'data' : $clean;
    }

    /**
     * Strip anything that could break an HTTP header or the
     * Content-Disposition `filename` parameter out of a full filename
     * (including the extension). Defence-in-depth behind
     * safe_filename_token(): even if a token ever slips through unsafe
     * (future refactor, new caller) we still can't emit CR/LF that
     * would split the response and inject headers, or an embedded
     * double-quote that would close the filename parameter and let an
     * attacker tack on a second one.
     */
    private static function safe_attachment_filename(string $filename): string {
        // Drop control chars (inc. \r \n \t and NUL), stray backslashes,
        // and double-quotes — the three classes that break an
        // Content-Disposition value.
        $clean = preg_replace('/[\x00-\x1F\x7F"\\\\]+/', '', $filename) ?? '';
        $clean = ltrim($clean, '.'); // don't let the filename start with a dot.
        return $clean === '' ? 'download' : $clean;
    }

    /**
     * Emit a complete Content-Disposition: attachment header for the
     * given filename. Includes both the ASCII `filename=""` for old
     * clients and an RFC 5987 `filename*=UTF-8''...` so non-ASCII
     * client names survive browsers that only honour the starred form.
     */
    private static function emit_attachment_header(string $filename): void {
        $safe = self::safe_attachment_filename($filename);
        header(sprintf(
            'Content-Disposition: attachment; filename="%s"; filename*=UTF-8\'\'%s',
            $safe,
            rawurlencode($safe)
        ));
    }

    /**
     * Generate and download SDNB legacy invoice
     */
    private static function download_sdnb_legacy($zone, $start_date, $end_date, $weeks_in_month = 4) {
        $csv_content = MealsDB_Invoice_Generator::generate_sdnb_legacy($zone, $start_date, $end_date, $weeks_in_month);

        if (empty($csv_content)) {
            wp_send_json_error(['message' => 'No data found for the specified period.']);
            return;
        }

        // Generate filename
        $filename = sprintf(
            'SDNB_Legacy_%s_%s_to_%s.csv',
            self::safe_filename_token($zone),
            str_replace('-', '', $start_date),
            str_replace('-', '', $end_date)
        );

        // Set headers and output
        header('Content-Type: text/csv; charset=utf-8');
        self::emit_attachment_header($filename);
        header('Content-Length: ' . strlen($csv_content));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        echo $csv_content;
        exit;
    }

    /**
     * Generate and download SDNB portal invoice
     */
    private static function download_sdnb_portal($start_date, $end_date) {
        $csv_content = MealsDB_Invoice_Generator::generate_sdnb_new_portal($start_date, $end_date);

        if (empty($csv_content)) {
            wp_send_json_error(['message' => 'No data found for the specified period.']);
            return;
        }

        // Generate filename
        $filename = sprintf(
            'SDNB_Portal_%s_to_%s.csv',
            str_replace('-', '', $start_date),
            str_replace('-', '', $end_date)
        );

        // Set headers and output
        header('Content-Type: text/csv; charset=utf-8');
        self::emit_attachment_header($filename);
        header('Content-Length: ' . strlen($csv_content));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        echo $csv_content;
        exit;
    }

    /**
     * Generate and download VAC CSV invoice
     */
    private static function download_vac_csv($start_date, $end_date) {
        $csv_content = MealsDB_Invoice_Generator::generate_vac_csv($start_date, $end_date);

        if (empty($csv_content)) {
            wp_send_json_error(['message' => 'No data found for the specified period.']);
            return;
        }

        // Generate filename
        $filename = sprintf(
            'VAC_Invoice_%s_to_%s.csv',
            str_replace('-', '', $start_date),
            str_replace('-', '', $end_date)
        );

        // Set headers and output
        header('Content-Type: text/csv; charset=utf-8');
        self::emit_attachment_header($filename);
        header('Content-Length: ' . strlen($csv_content));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        echo $csv_content;
        exit;
    }

    /**
     * Generate and download VAC PDF invoice. The generator returns PDF
     * bytes (phase 4: dompdf rendering, no temp file on disk).
     */
    private static function download_vac_pdf($start_date, $end_date) {
        $pdf_bytes = MealsDB_Invoice_Generator::generate_vac_pdf($start_date, $end_date);

        if (!is_string($pdf_bytes) || $pdf_bytes === '') {
            wp_send_json_error(['message' => 'Error generating PDF (no veterans in range or render failed).']);
            return;
        }

        $filename = sprintf(
            'VAC_Invoice_%s_to_%s.pdf',
            str_replace('-', '', $start_date),
            str_replace('-', '', $end_date)
        );

        header('Content-Type: application/pdf');
        self::emit_attachment_header($filename);
        header('Content-Length: ' . strlen($pdf_bytes));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        echo $pdf_bytes;
        exit;
    }

}
