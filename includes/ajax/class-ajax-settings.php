<?php
/**
 * AJAX handler for the Settings tab.
 *
 * Handles saving plugin settings and generating encryption keys.
 *
 * @package MealsDB
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MealsDB_Ajax_Settings {

    public static function init(): void {
        add_action( 'wp_ajax_mealsdb_save_settings', [ self::class, 'save_settings' ] );
        add_action( 'wp_ajax_mealsdb_generate_encryption_key', [ self::class, 'generate_key' ] );
        add_action( 'wp_ajax_mealsdb_backfill_next_dates', [ self::class, 'backfill_next_dates' ] );
        add_action( 'wp_ajax_mealsdb_preview_private_backfill', [ self::class, 'preview_private_backfill' ] );
        add_action( 'wp_ajax_mealsdb_run_private_backfill', [ self::class, 'run_private_backfill' ] );
        add_action( 'wp_ajax_mealsdb_preview_private_deactivation', [ self::class, 'preview_private_deactivation' ] );
        add_action( 'wp_ajax_mealsdb_run_private_deactivation', [ self::class, 'run_private_deactivation' ] );
        add_action( 'wp_ajax_mealsdb_enrich_private_skeletons', [ self::class, 'enrich_private_skeletons' ] );
    }

    /**
     * Preview WC users who would be promoted into meals_clients by the
     * Phase S backfill. Read-only — does not modify any rows.
     */
    public static function preview_private_backfill(): void {
        check_ajax_referer( 'mealsdb_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'meals-db' ) ], 403 );
        }

        $lookback = isset( $_POST['lookback_months'] ) ? (int) $_POST['lookback_months'] : MealsDB_Backfill_Private_Clients::DEFAULT_LOOKBACK_MONTHS;
        $rows = MealsDB_Backfill_Private_Clients::preview( $lookback );
        wp_send_json_success( [
            'count' => count( $rows ),
            'rows'  => $rows,
        ] );
    }

    /**
     * Promote every eligible user returned by the preview.
     */
    public static function run_private_backfill(): void {
        check_ajax_referer( 'mealsdb_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'meals-db' ) ], 403 );
        }

        $lookback = isset( $_POST['lookback_months'] ) ? (int) $_POST['lookback_months'] : MealsDB_Backfill_Private_Clients::DEFAULT_LOOKBACK_MONTHS;
        $stats = MealsDB_Backfill_Private_Clients::run( $lookback, false );
        wp_send_json_success( $stats );
    }

    /**
     * Preview the one-time deactivation sweep: Private meals_clients
     * with no active WC orders in the lookback window.
     */
    public static function preview_private_deactivation(): void {
        check_ajax_referer( 'mealsdb_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'meals-db' ) ], 403 );
        }

        $lookback = isset( $_POST['lookback_months'] ) ? (int) $_POST['lookback_months'] : MealsDB_Backfill_Private_Clients::DEFAULT_LOOKBACK_MONTHS;
        $rows = MealsDB_Backfill_Private_Clients::deactivation_sweep_preview( $lookback );
        wp_send_json_success( [
            'count' => count( $rows ),
            'rows'  => $rows,
        ] );
    }

    /**
     * Execute the deactivation sweep.
     */
    public static function run_private_deactivation(): void {
        check_ajax_referer( 'mealsdb_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'meals-db' ) ], 403 );
        }

        $lookback = isset( $_POST['lookback_months'] ) ? (int) $_POST['lookback_months'] : MealsDB_Backfill_Private_Clients::DEFAULT_LOOKBACK_MONTHS;
        $stats = MealsDB_Backfill_Private_Clients::deactivation_sweep_run( $lookback );
        wp_send_json_success( $stats );
    }

    /**
     * Refill blank columns on existing Private skeleton rows from
     * usermeta + the user's most recent qualifying WC order. Pass
     * `dry_run=1` in the POST body to count without writing.
     */
    public static function enrich_private_skeletons(): void {
        check_ajax_referer( 'mealsdb_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'meals-db' ) ], 403 );
        }

        $dry_run = ! empty( $_POST['dry_run'] );
        $stats = MealsDB_Backfill_Private_Clients::enrich_existing( $dry_run );
        wp_send_json_success( $stats );
    }

    /**
     * Run the one-time backfill that populates meals_clients.next_order_date
     * and next_delivery_date from wp_usermeta + the configured frequencies.
     */
    public static function backfill_next_dates(): void {
        check_ajax_referer( 'mealsdb_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'meals-db' ) ], 403 );
        }

        $result = MealsDB_Backfill_Next_Dates::run();
        wp_send_json_success( $result );
    }

    public static function save_settings(): void {
        check_ajax_referer( 'mealsdb_settings_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        // Merge into existing settings so other keys (and an existing
        // encryption_key when the user didn't submit a new one) are preserved.
        $existing = get_option( 'mealsdb_settings', [] );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }

        $submitted_key = sanitize_text_field( wp_unslash( $_POST['encryption_key'] ?? '' ) );
        $settings      = $existing;

        if ( $submitted_key !== '' ) {
            if ( strpos( $submitted_key, 'base64:' ) !== 0 ) {
                wp_send_json_error( [ 'message' => 'Encryption key must start with "base64:" prefix.' ] );
            }

            $decoded = base64_decode( substr( $submitted_key, 7 ), true );
            if ( $decoded === false || strlen( $decoded ) !== 32 ) {
                wp_send_json_error( [ 'message' => 'Encryption key must decode to exactly 32 bytes (256 bits).' ] );
            }

            $settings['encryption_key'] = $submitted_key;
        }
        // Empty input is treated as "no change" — the previous behaviour
        // silently cleared the key, which would render every encrypted
        // PII column unrecoverable on the next read.

        update_option( 'mealsdb_settings', $settings, false );

        // Save overage product IDs if provided. Validate each against
        // wc_get_product() — previously any positive integer was
        // accepted, so a typo could point the overage flow at a
        // non-existent product (wc_create_order::add_product then
        // quietly skips, leaving the operator wondering why overage
        // orders were empty) or at an unrelated product entirely.
        $overage_mains = intval( $_POST['overage_mains'] ?? 0 );
        $overage_tax   = intval( $_POST['overage_taxable_sides'] ?? 0 );
        $overage_nt    = intval( $_POST['overage_nontax_sides'] ?? 0 );
        if ( $overage_mains > 0 || $overage_tax > 0 || $overage_nt > 0 ) {
            $verify = static function ( int $pid ): int {
                if ( $pid <= 0 ) {
                    return 0;
                }
                if ( function_exists( 'wc_get_product' ) && ! wc_get_product( $pid ) ) {
                    return 0;
                }
                return $pid;
            };
            $overage_mains = $verify( $overage_mains );
            $overage_tax   = $verify( $overage_tax );
            $overage_nt    = $verify( $overage_nt );

            if ( $overage_mains === 0 && $overage_tax === 0 && $overage_nt === 0 ) {
                wp_send_json_error( [ 'message' => 'All provided overage product IDs refer to missing WooCommerce products.' ] );
            }

            update_option( 'mealsdb_overage_product_ids', [
                'mains'         => $overage_mains,
                'taxable_sides' => $overage_tax,
                'nontax_sides'  => $overage_nt,
            ], false );
        }

        // Save zone delivery schedule if provided (Phase Q).
        if ( isset( $_POST['zone_schedule'] ) && is_array( $_POST['zone_schedule'] ) ) {
            $valid_days = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];
            $schedule   = [];
            foreach ( $_POST['zone_schedule'] as $zone_name => $config ) {
                $zone_name = sanitize_text_field( wp_unslash( $zone_name ) );
                $day       = sanitize_text_field( wp_unslash( $config['day'] ?? '' ) );
                $label     = sanitize_text_field( wp_unslash( $config['label'] ?? '' ) );

                if ( $zone_name !== '' && in_array( $day, $valid_days, true ) ) {
                    $schedule[ $zone_name ] = [ 'day' => $day, 'label' => $label ];
                }
            }
            if ( ! empty( $schedule ) ) {
                update_option( 'mealsdb_zone_delivery_schedule', $schedule, false );
            }
        }

        // Force config reload on next request
        MealsDB_Config::reset();

        wp_send_json_success( [ 'message' => 'Settings saved.' ] );
    }

    public static function generate_key(): void {
        check_ajax_referer( 'mealsdb_settings_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        $bytes = random_bytes( 32 );
        $key   = 'base64:' . base64_encode( $bytes );

        wp_send_json_success( [ 'key' => $key ] );
    }
}
