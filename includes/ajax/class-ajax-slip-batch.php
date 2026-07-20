<?php
/**
 * AJAX handlers for the Midland packing-slip batch workflow (directive 05).
 *
 * Endpoints, each carrying the full guard stack (nonce → manage_options →
 * rate limit), mirroring MealsDB_Ajax_Invoice_Draft::guard():
 *
 *   JSON mutations (POST):
 *     mealsdb_slip_generate_batch  → generate a batch (persist doc 4 payloads)
 *     mealsdb_slip_cancel          → hard-delete the batch
 *     mealsdb_slip_list            → history rows (table refresh)
 *
 *   File streams (GET link, nonce in URL):
 *     mealsdb_slip_download_packing_slips → cover (page 1) + packer slips, one PDF
 *     mealsdb_slip_download_doc4          → standalone driver blocks (manual overlay)
 *
 * manage_options is REQUIRED on every endpoint: the packing-slips and doc-4
 * downloads expose DECRYPTED client PII (name/address/phone), exactly like the
 * invoice-draft grid — do NOT loosen to the baseline plugin cap.
 *
 * Audit: a committed change to a persisted record (generate / cancel) goes to
 * the AUDIT log via MealsDB_Logger::log() — the STR-LOG boundary, matching what
 * MealsDB_Invoice_Draft actually does. (Directive 07 mentioned Event_Log::record,
 * but it also said "match invoice-draft", which uses the audit log; the audit log
 * is correct for committed record changes.)
 *
 * Every handler is wrapped so a generator/dompdf failure becomes a clean
 * JSON error or wp_die — never a bare 500 with a leaked path.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Ajax_Slip_Batch {

    /** One nonce action for the whole workflow (each endpoint still verifies). */
    public const NONCE_ACTION = 'mealsdb_slip_batch_nonce';

    public static function init(): void {
        // JSON mutations.
        add_action('wp_ajax_mealsdb_slip_generate_batch', [self::class, 'generate_batch']);
        add_action('wp_ajax_mealsdb_slip_cancel',         [self::class, 'cancel']);
        add_action('wp_ajax_mealsdb_slip_list',           [self::class, 'list_batches']);

        // File streams (GET download links).
        add_action('wp_ajax_mealsdb_slip_download_packing_slips', [self::class, 'download_packing_slips']);
        add_action('wp_ajax_mealsdb_slip_download_doc4',          [self::class, 'download_doc4']);
    }

    // ================================================================= //
    //  Mutations
    // ================================================================= //

    /**
     * Generate a batch for one zone + delivery date: build the positional doc 4
     * payloads and persist them. Doc 1 / doc 2 are produced on demand by the
     * download handlers (nothing extra stored).
     */
    public static function generate_batch(): void {
        if (!self::guard('delivery_slips')) {
            return;
        }
        try {
            $zone = sanitize_text_field(wp_unslash($_POST['zone'] ?? ''));
            $date = sanitize_text_field(wp_unslash($_POST['delivery_date'] ?? ''));

            if ($zone === '' || !self::valid_zone($zone)) {
                wp_send_json_error(['message' => __('Unknown or missing zone.', 'meals-db')]);
                return;
            }
            if (!self::valid_date($date)) {
                wp_send_json_error(['message' => __('Invalid date format. Use YYYY-MM-DD.', 'meals-db')]);
                return;
            }

            $generator = self::make_pdf_generator();
            // A batch is a single delivery date: the degenerate range [D, D].
            $data = $generator->build_batch_data([$zone], $date, $date);

            $order_count = (int) ($data['order_count'] ?? 0);
            if ($order_count <= 0) {
                wp_send_json_error(['message' => __('No orders found for that zone and delivery date.', 'meals-db')]);
                return;
            }

            $batch_id = MealsDB_Slip_Batch::create($zone, $date, $data['doc4_orders']);
            if ($batch_id <= 0) {
                wp_send_json_error(['message' => __('Could not create the batch (see Event Log).', 'meals-db')]);
                return;
            }

            self::audit('slip_batch_generated', $batch_id, 'zone', '', $zone . ' | ' . $date . ' | ' . $order_count);

            wp_send_json_success([
                'batch_id'    => $batch_id,
                'order_count' => $order_count,
            ]);
        } catch (\Throwable $e) {
            self::fail_json($e, __('Could not generate the batch.', 'meals-db'));
        }
    }

    /**
     * Hard-delete a batch row. The confirmation popup is
     * front-end (unit 06); the server just executes.
     */
    public static function cancel(): void {
        if (!self::guard('settings_modify')) {
            return;
        }
        try {
            $batch_id = isset($_POST['batch_id']) ? absint($_POST['batch_id']) : 0;
            $batch    = $batch_id > 0 ? MealsDB_Slip_Batch::get($batch_id) : null;
            // Capture identity for the audit before the row is gone (best effort).
            $zone = $batch ? (string) ($batch['zone_name'] ?? '') : '';
            $date = $batch ? (string) ($batch['delivery_date'] ?? '') : '';

            if (!MealsDB_Slip_Batch::cancel($batch_id)) {
                wp_send_json_error(['message' => __('Batch not found or already removed.', 'meals-db')]);
                return;
            }

            self::audit('slip_batch_cancelled', $batch_id, 'zone', $zone . ' | ' . $date, 'deleted');
            wp_send_json_success(['batch_id' => $batch_id]);
        } catch (\Throwable $e) {
            self::fail_json($e, __('Could not cancel the batch.', 'meals-db'));
        }
    }

    /** History rows for the table (no PII). */
    public static function list_batches(): void {
        if (!self::guard('delivery_slips')) {
            return;
        }
        try {
            $rows = MealsDB_Slip_Batch::list_batches();
            wp_send_json_success(['batches' => $rows]);
        } catch (\Throwable $e) {
            self::fail_json($e, __('Could not load the batch list.', 'meals-db'));
        }
    }

    // ================================================================= //
    //  Downloads (GET file streams)
    // ================================================================= //

    public static function download_packing_slips(): void {
        $batch = self::download_guard();
        try {
            $generator = self::make_pdf_generator();
            $pdf = $generator->generate_packing_slips_combined(
                (string) ($batch['zone_name'] ?? ''),
                (string) ($batch['delivery_date'] ?? ''),
                [
                    'order_count' => (int) ($batch['order_count'] ?? 0),
                    'orders'      => is_array($batch['orders'] ?? null) ? $batch['orders'] : [],
                    'created_at'  => (string) ($batch['created_at'] ?? ''),
                ]
            );
            self::stream_pdf($pdf, self::filename($batch, 'packing-slips'));
        } catch (\Throwable $e) {
            self::fail_die($e);
        }
    }

    public static function download_doc4(): void {
        $batch = self::download_guard();
        try {
            $generator = self::make_pdf_generator();
            // Chunked doc-2 orders span multiple physical sheets; pass the
            // live per-order page counts so doc 4 pads blank pages and the
            // manual overlay lands on each order's FIRST sheet.
            $pdf = $generator->generate_doc4_driver_blocks(
                is_array($batch['orders'] ?? null) ? $batch['orders'] : [],
                $generator->doc2_page_counts(
                    (string) ($batch['zone_name'] ?? ''),
                    (string) ($batch['delivery_date'] ?? '')
                )
            );
            self::stream_pdf($pdf, self::filename($batch, 'driver'));
        } catch (\Throwable $e) {
            self::fail_die($e);
        }
    }

    // ================================================================= //
    //  Guards + helpers
    // ================================================================= //

    /**
     * Shared mutation guard: nonce → manage_options → rate limit, each failing
     * CLOSED with a JSON error. Mirrors MealsDB_Ajax_Invoice_Draft::guard().
     */
    private static function guard(string $rate_bucket): bool {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token.', 'meals-db')]);
            return false;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'meals-db')]);
            return false;
        }
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit($rate_bucket)) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
            return false;
        }
        return true;
    }

    /**
     * The download (GET file stream) guard: same three layers, but failing with
     * wp_die (these are top-level links, not XHR), then loads + returns the
     * batch (with decrypted orders). Exits on any failure.
     *
     * @return array the decrypted batch (never returns on failure)
     */
    private static function download_guard(): array {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'meals-db'), 403);
        }
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Invalid or expired download link.', 'meals-db'), 403);
        }
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('delivery_slips')) {
            wp_die(esc_html__('Rate limit exceeded. Please try again later.', 'meals-db'), 429);
        }

        $batch_id = isset($_REQUEST['batch_id']) ? absint($_REQUEST['batch_id']) : 0;
        $batch    = $batch_id > 0 ? MealsDB_Slip_Batch::get($batch_id) : null;
        if ($batch === null) {
            wp_die(esc_html__('Batch not found.', 'meals-db'), 404);
        }
        return $batch;
    }

    private static function stream_pdf(string $pdf, string $filename): void {
        if ($pdf === '') {
            wp_die(esc_html__('No PDF was produced.', 'meals-db'), 500);
        }
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
        if ($filename === '' || $filename === null) {
            $filename = 'slips.pdf';
        }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    /** Build a GET download URL (nonce in the link) for the history page. */
    public static function download_url(int $batch_id, string $which): string {
        // Keep '_' in the whitelist: the suffix must match the REGISTERED action
        // (e.g. 'packing_slips' → mealsdb_slip_download_packing_slips). Collapsing
        // underscores here would silently 404 the download.
        $action = 'mealsdb_slip_download_' . preg_replace('/[^a-z0-9_]/', '', $which);
        return add_query_arg(
            [
                'action'   => $action,
                'batch_id' => $batch_id,
                'nonce'    => wp_create_nonce(self::NONCE_ACTION),
            ],
            admin_url('admin-ajax.php')
        );
    }

    private static function filename(array $batch, string $kind): string {
        $zone = preg_replace('/[^A-Za-z0-9]+/', '', (string) ($batch['zone_name'] ?? 'zone'));
        $date = preg_replace('/[^0-9-]+/', '', (string) ($batch['delivery_date'] ?? ''));
        return sprintf('%s-%s-%s.pdf', $kind, $zone !== '' ? $zone : 'zone', $date !== '' ? $date : 'date');
    }

    /** Is the configured zone schedule listing this zone? (Fail open if none.) */
    private static function valid_zone(string $zone): bool {
        $schedule = get_option('mealsdb_zone_delivery_schedule', []);
        if (!is_array($schedule) || empty($schedule)) {
            return true; // no schedule configured → don't block generation
        }
        return in_array($zone, array_keys($schedule), true);
    }

    private static function valid_date(string $date): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }

    private static function make_pdf_generator(): MealsDB_Slip_PDF_Generator {
        $client_query = new MealsDB_Delivery_Slip_Generator(
            new MealsDB_WC_Order_Query($GLOBALS['wpdb'])
        );
        $calculator = new MealsDB_Collection_Calculator();
        return new MealsDB_Slip_PDF_Generator($client_query, $calculator);
    }

    /** Committed record change → audit log (STR-LOG boundary). */
    private static function audit(string $action, int $batch_id, string $field, string $old, string $new): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log($action, $batch_id, $field, $old, $new);
        }
    }

    private static function fail_json(\Throwable $e, string $message): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Slip Batch AJAX] ' . $e->getMessage());
        }
        wp_send_json_error(['message' => $message]);
    }

    private static function fail_die(\Throwable $e): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Slip Batch AJAX] download failed: ' . $e->getMessage());
        }
        wp_die(esc_html__('Unable to produce the document. Please contact an administrator.', 'meals-db'), 500);
    }
}
