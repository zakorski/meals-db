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
    }

    public static function save_settings(): void {
        check_ajax_referer( 'mealsdb_settings_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        $settings = [
            'encryption_key' => sanitize_text_field( wp_unslash( $_POST['encryption_key'] ?? '' ) ),
        ];

        // Validate encryption key format if provided
        $enc_key = $settings['encryption_key'];
        if ( $enc_key !== '' ) {
            if ( strpos( $enc_key, 'base64:' ) !== 0 ) {
                wp_send_json_error( [ 'message' => 'Encryption key must start with "base64:" prefix.' ] );
            }

            $decoded = base64_decode( substr( $enc_key, 7 ), true );
            if ( $decoded === false || strlen( $decoded ) !== 32 ) {
                wp_send_json_error( [ 'message' => 'Encryption key must decode to exactly 32 bytes (256 bits).' ] );
            }
        }

        update_option( 'mealsdb_settings', $settings, false );

        // Save overage product IDs if provided.
        $overage_mains = intval( $_POST['overage_mains'] ?? 0 );
        $overage_tax   = intval( $_POST['overage_taxable_sides'] ?? 0 );
        $overage_nt    = intval( $_POST['overage_nontax_sides'] ?? 0 );
        if ( $overage_mains > 0 || $overage_tax > 0 || $overage_nt > 0 ) {
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
