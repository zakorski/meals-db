<?php
/**
 * AJAX handler for the Settings tab.
 *
 * Handles saving DB credentials, generating encryption keys,
 * and testing database connections.
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
        add_action( 'wp_ajax_mealsdb_test_db_connection', [ self::class, 'test_connection' ] );
    }

    public static function save_settings(): void {
        check_ajax_referer( 'mealsdb_settings_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        $settings = [
            'db_host'        => sanitize_text_field( wp_unslash( $_POST['db_host'] ?? '' ) ),
            'db_name'        => sanitize_text_field( wp_unslash( $_POST['db_name'] ?? '' ) ),
            'db_user'        => sanitize_text_field( wp_unslash( $_POST['db_user'] ?? '' ) ),
            'db_pass'        => wp_unslash( $_POST['db_pass'] ?? '' ),
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

    public static function test_connection(): void {
        check_ajax_referer( 'mealsdb_settings_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        $host = sanitize_text_field( wp_unslash( $_POST['db_host'] ?? '' ) );
        $name = sanitize_text_field( wp_unslash( $_POST['db_name'] ?? '' ) );
        $user = sanitize_text_field( wp_unslash( $_POST['db_user'] ?? '' ) );
        $pass = wp_unslash( $_POST['db_pass'] ?? '' );

        if ( $host === '' || $name === '' || $user === '' || $pass === '' ) {
            wp_send_json_error( [ 'message' => 'All four database fields are required.' ] );
        }

        if ( ! class_exists( 'mysqli' ) ) {
            wp_send_json_error( [ 'message' => 'mysqli extension is not available.' ] );
        }

        $previous = function_exists( 'mysqli_report' ) ? mysqli_report( MYSQLI_REPORT_OFF ) : null;

        try {
            $conn = @new mysqli( $host, $user, $pass, $name );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'Connection failed: ' . $e->getMessage() ] );
        } finally {
            if ( $previous !== null ) {
                mysqli_report( $previous );
            }
        }

        if ( $conn->connect_error ) {
            $error = $conn->connect_error;
            $conn->close();
            wp_send_json_error( [ 'message' => 'Connection failed: ' . $error ] );
        }

        $conn->close();
        wp_send_json_success( [ 'message' => 'Connection successful.' ] );
    }
}
