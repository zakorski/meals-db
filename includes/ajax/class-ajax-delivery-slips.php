<?php
/**
 * AJAX handler for delivery slip generation.
 *
 * @package MealsDB
 */

class MealsDB_Ajax_Delivery_Slips {

    /**
     * Register AJAX actions.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_generate_packing_slip',  [self::class, 'packing_slip']);
        add_action('wp_ajax_mealsdb_generate_picking_slip',  [self::class, 'picking_slip']);
        add_action('wp_ajax_mealsdb_generate_delivery_slip', [self::class, 'delivery_slip']);
        add_action('wp_ajax_mealsdb_generate_driver_slips', [self::class, 'driver_slips']);

        // Zone-based slip generation (Phase Q).
        add_action('wp_ajax_mealsdb_zone_packing_slip',  [self::class, 'zone_packing_slip']);
        add_action('wp_ajax_mealsdb_zone_picking_slip',  [self::class, 'zone_picking_slip']);
        add_action('wp_ajax_mealsdb_zone_delivery_slip', [self::class, 'zone_delivery_slip']);
        add_action('wp_ajax_mealsdb_zone_driver_slips',  [self::class, 'zone_driver_slips']);

        // Backfill delivery_day from zone schedule.
        add_action('wp_ajax_mealsdb_backfill_delivery_day', [self::class, 'backfill_delivery_day']);
    }

    /**
     * Generate packing slip.
     */
    public static function packing_slip(): void {
        self::verify_request();
        $date      = self::get_delivery_date();
        $generator = self::make_generator();

        wp_send_json([
            'success' => true,
            'data'    => $generator->generate_packing_slip($date),
        ]);
    }

    /**
     * Generate picking slip.
     */
    public static function picking_slip(): void {
        self::verify_request();
        $date      = self::get_delivery_date();
        $generator = self::make_generator();

        wp_send_json([
            'success' => true,
            'data'    => $generator->generate_picking_slip($date),
        ]);
    }

    /**
     * Generate delivery slip.
     */
    public static function delivery_slip(): void {
        self::verify_request();
        $date      = self::get_delivery_date();
        $generator = self::make_generator();

        wp_send_json([
            'success' => true,
            'data'    => $generator->generate_delivery_slip($date),
        ]);
    }

    /**
     * Generate driver delivery slips.
     */
    public static function driver_slips(): void {
        self::verify_request();
        $date      = self::get_delivery_date();
        $generator = self::make_generator();

        wp_send_json([
            'success' => true,
            'data'    => $generator->generate_driver_slips($date),
        ]);
    }

    // --- Zone-based handlers (Phase Q) ---

    /**
     * Generate packing slip by zone + date range.
     */
    public static function zone_packing_slip(): void {
        self::verify_request();
        $params    = self::get_zone_params();
        $generator = self::make_generator();

        wp_send_json([
            'success' => true,
            'data'    => $generator->generate_packing_slip_by_zones(
                $params['zones'], $params['start_date'], $params['end_date']
            ),
        ]);
    }

    /**
     * Generate picking slip by zone + date range.
     */
    public static function zone_picking_slip(): void {
        self::verify_request();
        $params    = self::get_zone_params();
        $generator = self::make_generator();

        wp_send_json([
            'success' => true,
            'data'    => $generator->generate_picking_slip_by_zones(
                $params['zones'], $params['start_date'], $params['end_date']
            ),
        ]);
    }

    /**
     * Generate delivery slip by zone + date range.
     */
    public static function zone_delivery_slip(): void {
        self::verify_request();
        $params    = self::get_zone_params();
        $generator = self::make_generator();

        wp_send_json([
            'success' => true,
            'data'    => $generator->generate_delivery_slip_by_zones(
                $params['zones'], $params['start_date'], $params['end_date']
            ),
        ]);
    }

    /**
     * Generate driver slips by zone + date range.
     */
    public static function zone_driver_slips(): void {
        self::verify_request();
        $params    = self::get_zone_params();
        $generator = self::make_generator();

        wp_send_json([
            'success' => true,
            'data'    => $generator->generate_driver_slips_by_zones(
                $params['zones'], $params['start_date'], $params['end_date']
            ),
        ]);
    }

    /**
     * Backfill delivery_day on meals_clients from zone delivery schedule.
     */
    public static function backfill_delivery_day(): void {
        self::verify_request();

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

    /**
     * Common request verification.
     */
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

    /**
     * Extract and validate delivery_date from the request.
     *
     * @return string Y-m-d
     */
    private static function get_delivery_date(): string {
        $raw = isset($_REQUEST['delivery_date']) ? sanitize_text_field($_REQUEST['delivery_date']) : '';

        // Validate Y-m-d format.
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
     * Extract and validate zone-mode parameters from the request.
     *
     * @return array{zones: string[], start_date: string, end_date: string}
     */
    private static function get_zone_params(): array {
        $zones = isset($_REQUEST['zones']) ? array_map('sanitize_text_field', (array) $_REQUEST['zones']) : [];
        $start = isset($_REQUEST['start_date']) ? sanitize_text_field($_REQUEST['start_date']) : '';
        $end   = isset($_REQUEST['end_date']) ? sanitize_text_field($_REQUEST['end_date']) : '';

        if (empty($zones) || empty($start) || empty($end)) {
            wp_send_json([
                'success' => false,
                'message' => __('Zones and date range are required.', 'meals-db'),
            ]);
        }

        // Validate date formats.
        $date_pattern = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($date_pattern, $start) || !preg_match($date_pattern, $end)) {
            wp_send_json([
                'success' => false,
                'message' => __('Invalid date format. Use YYYY-MM-DD.', 'meals-db'),
            ]);
        }

        return ['zones' => $zones, 'start_date' => $start, 'end_date' => $end];
    }

    /**
     * Create a new generator instance.
     *
     * @return MealsDB_Delivery_Slip_Generator
     */
    private static function make_generator(): MealsDB_Delivery_Slip_Generator {
        return new MealsDB_Delivery_Slip_Generator(
            new MealsDB_WC_Order_Query($GLOBALS['wpdb'])
        );
    }
}
