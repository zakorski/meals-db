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
        add_action('wp_ajax_mealsdb_preview_overages', [__CLASS__, 'preview_overages']);
        add_action('wp_ajax_mealsdb_create_overage_orders', [__CLASS__, 'create_overage_orders']);
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
     * Generate and download VAC PDF invoice
     */
    private static function download_vac_pdf($start_date, $end_date) {
        $pdf_path = MealsDB_Invoice_Generator::generate_vac_pdf($start_date, $end_date);

        // Confine the served path to the WP uploads dir. Defends against the
        // generator ever being misconfigured to return an attacker-influenced
        // path (e.g. wp-config.php) — readfile would happily oblige.
        $resolved = is_string($pdf_path) ? realpath($pdf_path) : false;
        $uploads  = wp_upload_dir();
        $base     = isset($uploads['basedir']) ? realpath($uploads['basedir']) : false;

        if (!$resolved || !$base || strpos($resolved, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($resolved)) {
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
        self::emit_attachment_header($filename);
        header('Content-Length: ' . filesize($resolved));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        readfile($resolved);

        // Clean up temp file
        @unlink($resolved);

        exit;
    }

    /**
     * Preview overages for a billing period.
     */
    public static function preview_overages() {
        if (!check_ajax_referer('mealsdb_invoice_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
            return;
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
            return;
        }

        $client_type    = sanitize_text_field(wp_unslash($_POST['client_type'] ?? ''));
        $start_date     = sanitize_text_field(wp_unslash($_POST['start_date'] ?? ''));
        $end_date       = sanitize_text_field(wp_unslash($_POST['end_date'] ?? ''));
        $zone           = sanitize_text_field(wp_unslash($_POST['zone'] ?? ''));
        $weeks_in_month = intval($_POST['weeks_in_month'] ?? 4);

        if (empty($start_date) || empty($end_date)) {
            wp_send_json_error(['message' => 'Start and end dates are required.']);
            return;
        }

        try {
            if ($client_type === 'SDNB') {
                $overages = MealsDB_Invoice_Generator::get_sdnb_overages($zone, $start_date, $end_date, $weeks_in_month);
                // Only fields actually consumed by the preview UI + create_overage_orders.
                // individual_id (encrypted PII) is intentionally not returned here —
                // the UI keys off wp_user_id and displays last/first name only.
                $rows = array_map(function ($row) {
                    return [
                        'name'                => ($row['client']['last_name'] ?? '') . ', ' . ($row['client']['first_name'] ?? ''),
                        'wp_user_id'          => (int) ($row['client']['wp_user_id'] ?? 0),
                        'bnm_mains'           => $row['bnm_mains'],
                        'overage_tax_sides'   => $row['overage_tax_sides'],
                        'overage_nontax_sides'=> $row['overage_nontax_sides'],
                    ];
                }, $overages);
            } elseif ($client_type === 'Veteran') {
                $vac_rows = MealsDB_Invoice_Generator::get_vac_overages($start_date, $end_date);
                // Strip encrypted PII (K# / health_card) from the JSON surface.
                $rows = array_map(function ($row) {
                    return [
                        'name'                => (($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')),
                        'wp_user_id'          => (int) ($row['wp_user_id'] ?? 0),
                        'bnm_mains'           => (int) ($row['bnm_mains'] ?? 0),
                        'overage_tax_sides'   => (int) ($row['overage_tax_sides'] ?? 0),
                        'overage_nontax_sides'=> (int) ($row['overage_nontax_sides'] ?? 0),
                    ];
                }, $vac_rows);
            } else {
                wp_send_json_error(['message' => 'Invalid client type.']);
                return;
            }

            wp_send_json_success(['overages' => array_values($rows), 'count' => count($rows)]);
        } catch (Exception $e) {
            error_log('[MealsDB Invoice] preview_overages failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to preview overages. Please contact an administrator.', 'meals-db')]);
        }
    }

    /**
     * Create WooCommerce orders for overages.
     *
     * Quantities are recomputed server-side from the same period/zone the
     * preview was built from — never trust the round-tripped JSON. Clients
     * may pass `wp_user_ids` to limit the run to a subset of the previewed
     * rows (e.g. when the operator unchecks a row in the UI).
     */
    public static function create_overage_orders() {
        if (!check_ajax_referer('mealsdb_invoice_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
            return;
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('client_modify')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
            return;
        }

        $client_type    = sanitize_text_field(wp_unslash($_POST['client_type'] ?? ''));
        $start_date     = sanitize_text_field(wp_unslash($_POST['start_date'] ?? ''));
        $end_date       = sanitize_text_field(wp_unslash($_POST['end_date'] ?? ''));
        $zone           = sanitize_text_field(wp_unslash($_POST['zone'] ?? ''));
        $weeks_in_month = intval($_POST['weeks_in_month'] ?? 4);
        $invoice_date   = sanitize_text_field(wp_unslash($_POST['invoice_date'] ?? ''));

        if (!in_array($client_type, ['SDNB', 'Veteran'], true)) {
            wp_send_json_error(['message' => 'Invalid client type.']);
            return;
        }

        // SDNB overage orders require a service-zone code; VAC does not.
        // Whitelist against the same set the legacy invoice accepts so
        // a typo fails fast rather than silently processing zero clients.
        if ($client_type === 'SDNB') {
            $zone = strtoupper(trim($zone));
            if ($zone === '' || !in_array($zone, self::allowed_sdnb_zones(), true)) {
                wp_send_json_error(['message' => 'Unknown SDNB zone.']);
                return;
            }
        }

        if (!self::validate_date($start_date) || !self::validate_date($end_date)) {
            wp_send_json_error(['message' => 'Start and end dates must be in YYYY-MM-DD format.']);
            return;
        }

        if ($weeks_in_month < 1 || $weeks_in_month > 6) {
            $weeks_in_month = 4;
        }

        if (!self::validate_date($invoice_date)) {
            $invoice_date = current_time('Y-m-d');
        }

        // Optional subset filter: a client may submit a list of wp_user_ids
        // to restrict the run, but the per-user quantities are still
        // recomputed from the server-side overage report.
        $allowed_user_ids = null;
        if (isset($_POST['wp_user_ids'])) {
            $raw_ids = (array) wp_unslash($_POST['wp_user_ids']);
            $allowed_user_ids = [];
            foreach ($raw_ids as $raw_id) {
                $id = (int) $raw_id;
                if ($id > 0) {
                    $allowed_user_ids[$id] = true;
                }
            }
        }

        try {
            if ($client_type === 'SDNB') {
                $rows = MealsDB_Invoice_Generator::get_sdnb_overages($zone, $start_date, $end_date, $weeks_in_month);
                $rows = array_map(function ($row) {
                    return [
                        'wp_user_id'           => (int) ($row['client']['wp_user_id'] ?? 0),
                        'name'                 => ($row['client']['last_name'] ?? '') . ', ' . ($row['client']['first_name'] ?? ''),
                        'bnm_mains'            => (int) ($row['bnm_mains'] ?? 0),
                        'overage_tax_sides'    => (int) ($row['overage_tax_sides'] ?? 0),
                        'overage_nontax_sides' => (int) ($row['overage_nontax_sides'] ?? 0),
                    ];
                }, $rows);
            } else {
                $rows = MealsDB_Invoice_Generator::get_vac_overages($start_date, $end_date);
                $rows = array_map(function ($row) {
                    return [
                        'wp_user_id'           => (int) ($row['wp_user_id'] ?? 0),
                        'name'                 => (($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')),
                        'bnm_mains'            => (int) ($row['bnm_mains'] ?? 0),
                        'overage_tax_sides'    => (int) ($row['overage_tax_sides'] ?? 0),
                        'overage_nontax_sides' => (int) ($row['overage_nontax_sides'] ?? 0),
                    ];
                }, $rows);
            }
        } catch (Exception $e) {
            error_log('[MealsDB Invoice] create_overage_orders: failed to recompute overages: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to compute overages. Please contact an administrator.', 'meals-db')]);
            return;
        }

        if (empty($rows)) {
            wp_send_json_error(['message' => 'No overages found for the selected period.']);
            return;
        }

        $product_ids = MealsDB_Invoice_Generator::get_overage_product_ids();
        $order_count = 0;
        $skipped     = [];

        foreach ($rows as $item) {
            $wp_user_id  = (int) $item['wp_user_id'];
            $bnm_mains   = (int) $item['bnm_mains'];
            $overage_tax = (int) $item['overage_tax_sides'];
            $overage_nt  = (int) $item['overage_nontax_sides'];

            if ($wp_user_id <= 0 || !get_userdata($wp_user_id)) {
                $skipped[] = $item['name'] ?: 'Unknown';
                continue;
            }

            if ($allowed_user_ids !== null && !isset($allowed_user_ids[$wp_user_id])) {
                continue;
            }

            if ($bnm_mains <= 0 && $overage_tax <= 0 && $overage_nt <= 0) {
                continue;
            }

            $order = wc_create_order(['customer_id' => $wp_user_id]);
            if (is_wp_error($order)) {
                $skipped[] = $item['name'] ?: 'Unknown';
                continue;
            }

            // Configure the order BEFORE updating status so date_created
            // and items are persisted in a single save and the status
            // transition fires with a complete order.
            $order->set_date_created($invoice_date . ' 00:00:00');
            $order->set_date_paid($invoice_date . ' 00:00:00');

            if ($bnm_mains > 0 && $product_ids['mains'] > 0) {
                $product = wc_get_product($product_ids['mains']);
                if ($product) {
                    $order->add_product($product, $bnm_mains);
                }
            }
            if ($overage_nt > 0 && $product_ids['nontax_sides'] > 0) {
                $product = wc_get_product($product_ids['nontax_sides']);
                if ($product) {
                    $order->add_product($product, $overage_nt);
                }
            }
            if ($overage_tax > 0 && $product_ids['taxable_sides'] > 0) {
                $product = wc_get_product($product_ids['taxable_sides']);
                if ($product) {
                    $order->add_product($product, $overage_tax);
                }
            }

            $order->calculate_totals();
            $order->update_status('completed');
            $order->save();
            $order_count++;
        }

        // Audit the batch. create_overage_orders creates and
        // auto-completes WC orders at billing scale — without this
        // entry an erroneous run would have no forensic trail.
        // SEC-10 / directive 16 Pass A hardening gap.
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log(
                'overage_orders_created',
                get_current_user_id(),
                $client_type . ($zone !== '' ? '/' . $zone : ''),
                null,
                wp_json_encode([
                    'client_type'    => $client_type,
                    'zone'           => $zone,
                    'start_date'     => $start_date,
                    'end_date'       => $end_date,
                    'weeks_in_month' => $weeks_in_month,
                    'invoice_date'   => $invoice_date,
                    'created'        => $order_count,
                    'skipped'        => count($skipped),
                ])
            );
        }

        wp_send_json_success([
            'created'       => $order_count,
            'skipped'       => $skipped,
            'skipped_count' => count($skipped),
        ]);
    }
}
