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
