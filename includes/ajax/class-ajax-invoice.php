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

        // Get and validate parameters
        $invoice_type = sanitize_text_field($_POST['invoice_type'] ?? '');
        $start_date = sanitize_text_field($_POST['start_date'] ?? '');
        $end_date = sanitize_text_field($_POST['end_date'] ?? '');
        $zone = sanitize_text_field($_POST['zone'] ?? '');
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
                    self::download_sdnb_legacy($zone, $start_date, $end_date, $weeks_in_month);
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
            wp_send_json_error(['message' => 'Error generating invoice: ' . $e->getMessage()]);
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
            $zone,
            str_replace('-', '', $start_date),
            str_replace('-', '', $end_date)
        );

        // Set headers and output
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
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
        header('Content-Disposition: attachment; filename="' . $filename . '"');
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
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv_content));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        echo $csv_content;
        exit;
    }

    /**
     * Generate and download VAC PDF invoice
     */
    private static function download_vac_pdf($start_date, $end_date) {
        $pdf_path = MealsDB_Invoice_Generator::generate_vac_pdf($start_date, $end_date);

        if (!file_exists($pdf_path)) {
            wp_send_json_error(['message' => 'Error generating PDF file.']);
            return;
        }

        // Generate filename
        $filename = sprintf(
            'VAC_Invoice_%s_to_%s.pdf',
            str_replace('-', '', $start_date),
            str_replace('-', '', $end_date)
        );

        // Set headers and output
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($pdf_path));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        readfile($pdf_path);

        // Clean up temp file
        unlink($pdf_path);

        exit;
    }
}
