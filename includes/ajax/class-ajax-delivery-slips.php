<?php
/**
 * AJAX handlers for Phase T per-order PDF slips.
 *
 * Two slip types — packer and driver — each with two callers (single
 * delivery date or zone + date range). The four old screen-rendered
 * slip endpoints (packing/picking/delivery/driver, plus their
 * zone-mode counterparts) were retired with this phase; only the
 * delivery-day backfill helper survives.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Ajax_Delivery_Slips {

    /**
     * Upper bound on the number of zone names a single request may
     * reference. Real deployments ship 6 zones; anything beyond this
     * is either a misconfiguration or a hostile caller trying to
     * expand the IN (...) clause downstream.
     */
    private const MAX_ZONES_PER_REQUEST = 100;

    public static function init(): void {
        // Per-order PDF slip endpoints (Phase T).
        add_action('wp_ajax_mealsdb_packer_pdf',      [self::class, 'packer_pdf']);
        add_action('wp_ajax_mealsdb_driver_pdf',      [self::class, 'driver_pdf']);
        add_action('wp_ajax_mealsdb_zone_packer_pdf', [self::class, 'zone_packer_pdf']);
        add_action('wp_ajax_mealsdb_zone_driver_pdf', [self::class, 'zone_driver_pdf']);

        // Backfill delivery_day from zone schedule (kept from prior phases).
        add_action('wp_ajax_mealsdb_backfill_delivery_day', [self::class, 'backfill_delivery_day']);
    }

    public static function packer_pdf(): void {
        self::verify_request();
        $date = self::get_delivery_date();
        $generator = self::make_pdf_generator();

        try {
            $pdf = $generator->generate_packer_slips_for_date($date);
        } catch (\Throwable $e) {
            self::fail_with_exception($e);
            return;
        }

        self::stream_pdf($pdf, "packer-slips-{$date}.pdf");
    }

    public static function driver_pdf(): void {
        self::verify_request();
        $date = self::get_delivery_date();
        $generator = self::make_pdf_generator();

        try {
            $pdf = $generator->generate_driver_slips_for_date($date);
        } catch (\Throwable $e) {
            self::fail_with_exception($e);
            return;
        }

        self::stream_pdf($pdf, "driver-slips-{$date}.pdf");
    }

    public static function zone_packer_pdf(): void {
        self::verify_request();
        $params = self::get_zone_params();
        $generator = self::make_pdf_generator();

        try {
            $pdf = $generator->generate_packer_slips_by_zones(
                $params['zones'],
                $params['start_date'],
                $params['end_date']
            );
        } catch (\Throwable $e) {
            self::fail_with_exception($e);
            return;
        }

        $filename = self::zone_filename('packer', $params);
        self::stream_pdf($pdf, $filename);
    }

    public static function zone_driver_pdf(): void {
        self::verify_request();
        $params = self::get_zone_params();
        $generator = self::make_pdf_generator();

        try {
            $pdf = $generator->generate_driver_slips_by_zones(
                $params['zones'],
                $params['start_date'],
                $params['end_date']
            );
        } catch (\Throwable $e) {
            self::fail_with_exception($e);
            return;
        }

        $filename = self::zone_filename('driver', $params);
        self::stream_pdf($pdf, $filename);
    }

    /**
     * Backfill delivery_day on meals_clients from zone delivery schedule.
     */
    public static function backfill_delivery_day(): void {
        // This is a BULK CLIENT WRITE (UPDATEs meals_clients.delivery_day for
        // every active client in each zone), NOT a read-only slip render — so it
        // must not share verify_request()'s baseline gate (required_capability(),
        // i.e. manage_woocommerce, which the shop_manager role holds) or its
        // read-tier 'delivery_slips' bucket. Match its sibling backfills in
        // class-ajax-migration.php: full-admin capability plus the destructive
        // rate bucket. verify_request() stays on the read-only PDF endpoints
        // above. (Audit U22-ajax-misc-3.)
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json([
                'success' => false,
                'message' => __('You are not allowed to perform this action.', 'meals-db'),
            ], 403);
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('migration_destructive')) {
            wp_send_json([
                'success' => false,
                'message' => __('Backfill is rate-limited. Please wait before retrying.', 'meals-db'),
            ], 429);
        }

        $schedule = get_option('mealsdb_zone_delivery_schedule', []);
        if (empty($schedule)) {
            wp_send_json(['success' => false, 'message' => 'No zone delivery schedule configured.']);
        }

        global $wpdb;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $updated = 0;

        foreach ($schedule as $zone_name => $config) {
            $day = strtolower($config['day']);

            $sql = $wpdb->prepare(
                "UPDATE `{$clients_table}`
                 SET delivery_day = %s
                 WHERE delivery_area_name = %s
                   AND (delivery_day IS NULL OR delivery_day = '')
                   AND active = 1",
                $day,
                $zone_name
            );
            $wpdb->query($sql);
            $updated += $wpdb->rows_affected;
        }

        wp_send_json([
            'success' => true,
            'data'    => ['updated' => $updated],
            'message' => sprintf('Updated delivery_day for %d clients.', $updated),
        ]);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private static function stream_pdf(string $pdf, string $filename): void {
        if ($pdf === '') {
            self::fail_with_message(__('No PDF was produced.', 'meals-db'));
            return;
        }

        // Sanitise filename: only allow safe filesystem characters.
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

    private static function fail_with_message(string $message): void {
        wp_send_json([
            'success' => false,
            'message' => $message,
        ], 500);
    }

    /**
     * Log a generator exception server-side (scrubbed) and return a GENERIC
     * message to the client. Exception messages from the PDF generator / wpdb
     * can carry filesystem paths and SQL fragments; surfacing them to the AJAX
     * caller is information disclosure (every other handler logs + genericises).
     */
    private static function fail_with_exception(\Throwable $e): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Delivery Slips] generation failed: ' . $e->getMessage());
        }
        self::fail_with_message(__('Could not generate the slips. Please try again or check the error log.', 'meals-db'));
    }

    private static function verify_request(): void {
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

        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('delivery_slips')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }
    }

    private static function get_delivery_date(): string {
        $raw = isset($_REQUEST['delivery_date']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['delivery_date'])) : '';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            wp_send_json([
                'success' => false,
                'message' => __('Invalid date format. Use YYYY-MM-DD.', 'meals-db'),
            ]);
        }

        $dt = DateTime::createFromFormat('Y-m-d', $raw);
        if (!$dt || $dt->format('Y-m-d') !== $raw) {
            wp_send_json([
                'success' => false,
                'message' => __('Invalid date.', 'meals-db'),
            ]);
        }

        return $raw;
    }

    /**
     * @return array{zones: string[], start_date: string, end_date: string}
     */
    private static function get_zone_params(): array {
        $zones_raw = isset($_REQUEST['zones']) ? wp_unslash((array) $_REQUEST['zones']) : [];
        $start = isset($_REQUEST['start_date']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['start_date'])) : '';
        $end   = isset($_REQUEST['end_date']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['end_date'])) : '';

        if (count($zones_raw) > self::MAX_ZONES_PER_REQUEST) {
            wp_send_json([
                'success' => false,
                'message' => __('Too many zones specified.', 'meals-db'),
            ]);
        }

        $zones = array_map('sanitize_text_field', $zones_raw);

        $schedule = get_option('mealsdb_zone_delivery_schedule', []);
        if (is_array($schedule) && !empty($schedule)) {
            $allowed_zones = array_keys($schedule);
            $zones = array_values(array_filter($zones, static function ($z) use ($allowed_zones) {
                return in_array($z, $allowed_zones, true);
            }));
        }

        if (empty($zones) || empty($start) || empty($end)) {
            wp_send_json([
                'success' => false,
                'message' => __('Zones and date range are required.', 'meals-db'),
            ]);
        }

        $date_pattern = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($date_pattern, $start) || !preg_match($date_pattern, $end)) {
            wp_send_json([
                'success' => false,
                'message' => __('Invalid date format. Use YYYY-MM-DD.', 'meals-db'),
            ]);
        }

        return ['zones' => $zones, 'start_date' => $start, 'end_date' => $end];
    }

    private static function zone_filename(string $kind, array $params): string {
        $zones_part = implode('-', array_map(static function ($z) {
            return preg_replace('/[^A-Za-z0-9]+/', '', $z);
        }, $params['zones']));
        return sprintf(
            '%s-slips-%s-to-%s-zones-%s.pdf',
            $kind,
            $params['start_date'],
            $params['end_date'],
            $zones_part
        );
    }

    private static function make_pdf_generator(): MealsDB_Slip_PDF_Generator {
        $client_query = new MealsDB_Delivery_Slip_Generator(
            new MealsDB_WC_Order_Query($GLOBALS['wpdb'])
        );
        $calculator = new MealsDB_Collection_Calculator();

        return new MealsDB_Slip_PDF_Generator($client_query, $calculator);
    }
}
