<?php
/**
 * AJAX handlers for the Midland packing-slip batch workflow (directive 05).
 *
 * Endpoints, each carrying the full guard stack (nonce → manage_options →
 * rate limit), mirroring MealsDB_Ajax_Invoice_Draft::guard():
 *
 *   JSON mutations (POST):
 *     mealsdb_slip_generate_batch  → generate a batch (persist doc 4 payloads)
 *     mealsdb_slip_upload_doc3     → upload + validate the returned scan
 *     mealsdb_slip_combine         → composite doc 4 onto doc 3 → merged PDF
 *     mealsdb_slip_cancel          → hard-delete the batch (+ files)
 *     mealsdb_slip_list            → history rows (table refresh)
 *
 *   File streams (GET link, nonce in URL):
 *     mealsdb_slip_download_doc1   → cover sheet (regenerated from the batch)
 *     mealsdb_slip_download_doc2   → packer slips (re-queried live; with divider)
 *     mealsdb_slip_download_doc4   → standalone driver blocks (manual fallback)
 *     mealsdb_slip_download_merged → the saved merged output (streamed from disk)
 *
 * manage_options is REQUIRED on every endpoint: doc 4 / the merged output expose
 * DECRYPTED client PII (name/address/phone), exactly like the invoice-draft
 * grid — do NOT loosen to the baseline plugin cap.
 *
 * Audit: a committed change to a persisted record (generate / doc3 upload /
 * combine / cancel) goes to the AUDIT log via MealsDB_Logger::log() — the
 * STR-LOG boundary, matching what MealsDB_Invoice_Draft actually does. (Directive
 * 07 mentioned Event_Log::record, but it also said "match invoice-draft", which
 * uses the audit log; the audit log is correct for committed record changes.)
 *
 * Every handler is wrapped so a generator/Imagick/dompdf failure becomes a clean
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

    /** Hard cap on an uploaded doc 3 (generous for a multi-page 300-DPI scan). */
    private const MAX_UPLOAD_BYTES = 100 * 1024 * 1024; // 100 MB

    public static function init(): void {
        // JSON mutations.
        add_action('wp_ajax_mealsdb_slip_generate_batch', [self::class, 'generate_batch']);
        add_action('wp_ajax_mealsdb_slip_upload_doc3',    [self::class, 'upload_doc3']);
        add_action('wp_ajax_mealsdb_slip_combine',        [self::class, 'combine']);
        add_action('wp_ajax_mealsdb_slip_cancel',         [self::class, 'cancel']);
        add_action('wp_ajax_mealsdb_slip_list',           [self::class, 'list_batches']);

        // File streams (GET download links).
        add_action('wp_ajax_mealsdb_slip_download_doc1',   [self::class, 'download_doc1']);
        add_action('wp_ajax_mealsdb_slip_download_doc2',   [self::class, 'download_doc2']);
        add_action('wp_ajax_mealsdb_slip_download_doc4',   [self::class, 'download_doc4']);
        add_action('wp_ajax_mealsdb_slip_download_merged', [self::class, 'download_merged']);
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
     * Upload the returned doc 3 scan for a batch. Validates (PDF + size +
     * page-count === order-count) BEFORE storing; a mismatch is reported and
     * NOT stored, so the front-end keeps Combine disabled. Replaceable.
     */
    public static function upload_doc3(): void {
        if (!self::guard('delivery_slips')) {
            return;
        }
        $tmp_copy = '';
        try {
            $batch_id = isset($_POST['batch_id']) ? absint($_POST['batch_id']) : 0;
            $batch    = $batch_id > 0 ? MealsDB_Slip_Batch::get($batch_id) : null;
            if ($batch === null) {
                wp_send_json_error(['message' => __('Batch not found.', 'meals-db'), 'valid' => false]);
                return;
            }

            if (empty($_FILES['doc3']) || !is_array($_FILES['doc3'])) {
                wp_send_json_error(['message' => __('No file was uploaded.', 'meals-db'), 'valid' => false]);
                return;
            }
            $file = $_FILES['doc3'];
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                wp_send_json_error(['message' => __('The upload did not complete.', 'meals-db'), 'valid' => false]);
                return;
            }
            if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > self::MAX_UPLOAD_BYTES) {
                wp_send_json_error(['message' => __('The file is empty or too large.', 'meals-db'), 'valid' => false]);
                return;
            }

            $src = (string) ($file['tmp_name'] ?? '');
            if ($src === '' || !is_uploaded_file($src)) {
                wp_send_json_error(['message' => __('Invalid upload.', 'meals-db'), 'valid' => false]);
                return;
            }

            // Extension + content-sniff: must be a PDF (never trust the name).
            $name = (string) ($file['name'] ?? '');
            if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf'
                || !self::sniff_pdf($src)) {
                wp_send_json_error(['message' => __('Only PDF files are accepted.', 'meals-db'), 'valid' => false]);
                return;
            }

            // Copy the upload to a scratch path we control for validation +
            // (on success) hand-off to the store.
            $tmp_copy = self::scratch_copy($src);
            if ($tmp_copy === '') {
                wp_send_json_error(['message' => __('Could not stage the upload.', 'meals-db'), 'valid' => false]);
                return;
            }

            $expected = (int) ($batch['order_count'] ?? 0);
            $check    = MealsDB_Slip_Merge::validate_doc3($tmp_copy, $expected);
            if (empty($check['ok'])) {
                @unlink($tmp_copy);
                wp_send_json_error([
                    'message' => (string) ($check['reason'] ?? __('The scan failed validation.', 'meals-db')),
                    'valid'   => false,
                ]);
                return;
            }

            $stored = MealsDB_Slip_Batch::store_doc3($batch_id, $tmp_copy, (int) $check['page_count']);
            // store_doc3 moves the file; drop any leftover only if it remains.
            if (is_file($tmp_copy)) { @unlink($tmp_copy); }
            if (!$stored) {
                wp_send_json_error(['message' => __('Could not store the scan.', 'meals-db'), 'valid' => false]);
                return;
            }

            self::audit('slip_batch_doc3_uploaded', $batch_id, 'pages', '', (string) $check['page_count']);
            wp_send_json_success(['valid' => true, 'page_count' => (int) $check['page_count']]);
        } catch (\Throwable $e) {
            if ($tmp_copy !== '' && is_file($tmp_copy)) { @unlink($tmp_copy); }
            self::fail_json($e, __('Could not process the upload.', 'meals-db'));
        }
    }

    /**
     * Composite the saved doc 4 blocks onto the uploaded doc 3 → merged PDF,
     * stored on the batch row. Re-combinable any number of times.
     */
    public static function combine(): void {
        if (!self::guard('delivery_slips')) {
            return;
        }
        try {
            $batch_id = isset($_POST['batch_id']) ? absint($_POST['batch_id']) : 0;
            $batch    = $batch_id > 0 ? MealsDB_Slip_Batch::get($batch_id) : null;
            if ($batch === null) {
                wp_send_json_error(['message' => __('Batch not found.', 'meals-db')]);
                return;
            }
            $doc3 = (string) ($batch['doc3_path'] ?? '');
            if ($doc3 === '' || !is_readable($doc3)) {
                wp_send_json_error(['message' => __('Upload a valid doc 3 scan before combining.', 'meals-db')]);
                return;
            }

            $bytes = MealsDB_Slip_Merge::combine($batch['orders'] ?? [], $doc3);
            if ($bytes === '') {
                // Surface the SPECIFIC cause when the merge service knows it
                // (e.g. the PDF image tool is unavailable, vs a page-count
                // mismatch) so the operator isn't sent to the Event Log to
                // guess. Falls back to the generic message otherwise.
                $reason = method_exists('MealsDB_Slip_Merge', 'last_error_reason')
                    ? MealsDB_Slip_Merge::last_error_reason()
                    : '';
                $message = $reason !== ''
                    ? sprintf(__('The merge failed: %s', 'meals-db'), $reason)
                    : __('The merge failed (see Event Log).', 'meals-db');
                wp_send_json_error(['message' => $message]);
                return;
            }

            $path = MealsDB_Slip_Batch::store_merged($batch_id, $bytes);
            if ($path === '') {
                wp_send_json_error(['message' => __('Could not save the merged output.', 'meals-db')]);
                return;
            }

            self::audit('slip_batch_combined', $batch_id, 'status', '', 'combined');
            wp_send_json_success(['download' => self::download_url($batch_id, 'merged')]);
        } catch (\Throwable $e) {
            self::fail_json($e, __('Could not combine the documents.', 'meals-db'));
        }
    }

    /**
     * Hard-delete a batch (row + doc3/merged files). The confirmation popup is
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

    public static function download_doc1(): void {
        $batch = self::download_guard();
        try {
            $generator = self::make_pdf_generator();
            $pdf = $generator->generate_doc1_cover_sheet(
                (string) ($batch['zone_name'] ?? ''),
                (string) ($batch['delivery_date'] ?? ''),
                [
                    'order_count' => (int) ($batch['order_count'] ?? 0),
                    'orders'      => is_array($batch['orders'] ?? null) ? $batch['orders'] : [],
                    'created_at'  => (string) ($batch['created_at'] ?? ''),
                ]
            );
            self::stream_pdf($pdf, self::filename($batch, 'cover'));
        } catch (\Throwable $e) {
            self::fail_die($e);
        }
    }

    public static function download_doc2(): void {
        $batch = self::download_guard();
        try {
            $generator = self::make_pdf_generator();
            $pdf = $generator->generate_doc2_packer_by_zones(
                [(string) ($batch['zone_name'] ?? '')],
                (string) ($batch['delivery_date'] ?? ''),
                (string) ($batch['delivery_date'] ?? '')
            );
            self::stream_pdf($pdf, self::filename($batch, 'packer'));
        } catch (\Throwable $e) {
            self::fail_die($e);
        }
    }

    public static function download_doc4(): void {
        $batch = self::download_guard();
        try {
            $generator = self::make_pdf_generator();
            $pdf = $generator->generate_doc4_driver_blocks(
                is_array($batch['orders'] ?? null) ? $batch['orders'] : []
            );
            self::stream_pdf($pdf, self::filename($batch, 'driver'));
        } catch (\Throwable $e) {
            self::fail_die($e);
        }
    }

    public static function download_merged(): void {
        $batch = self::download_guard();
        try {
            $path = (string) ($batch['merged_path'] ?? '');
            if ($path === '' || !is_readable($path)) {
                wp_die(esc_html__('No merged output is available for this batch.', 'meals-db'), 404);
            }
            $bytes = (string) file_get_contents($path);
            self::stream_pdf($bytes, self::filename($batch, 'merged'));
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
        $action = 'mealsdb_slip_download_' . preg_replace('/[^a-z0-9]/', '', $which);
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

    /** Magic-byte PDF sniff for the upload. */
    private static function sniff_pdf(string $path): bool {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = (string) fread($fh, 5);
        fclose($fh);
        return strncmp($head, '%PDF-', 5) === 0;
    }

    /** Copy an upload into our protected tmp dir; returns path or ''. */
    private static function scratch_copy(string $src): string {
        $dir = class_exists('MealsDB_Slip_Batch') ? MealsDB_Slip_Batch::storage_dir('tmp') : null;
        if ($dir === null) {
            return '';
        }
        try {
            $dest = trailingslashit($dir) . 'upload-' . bin2hex(random_bytes(8)) . '.pdf';
        } catch (\Throwable $e) {
            $dest = trailingslashit($dir) . 'upload-' . md5((string) memory_get_usage()) . '.pdf';
        }
        if (!@copy($src, $dest)) {
            return '';
        }
        @chmod($dest, 0600);
        return $dest;
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
