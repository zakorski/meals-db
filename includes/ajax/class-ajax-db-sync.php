<?php
/**
 * AJAX handler for the Complete DB Sync tool.
 *
 * Runs Phases 4 (create clients) and 5 (create rates) from the migration
 * service against the current site's own wp_usermeta.
 *
 * @package MealsDB
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MealsDB_Ajax_DB_Sync {

    public static function init(): void {
        add_action( 'wp_ajax_mealsdb_db_sync_phase', [ self::class, 'run_phase' ] );
    }

    public static function run_phase(): void {
        check_ajax_referer( 'mealsdb_db_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        set_time_limit( 300 );

        $phase   = (int) ( $_POST['phase'] ?? 0 );
        $offset  = (int) ( $_POST['offset'] ?? 0 );
        $dry_run = MealsDB_Helpers::bool_flag( $_POST['dry_run'] ?? null, true );

        $result = [];

        switch ( $phase ) {
            case 4:
                $result = MealsDB_Migration::create_clients( $offset, $dry_run );
                break;
            case 5:
                $result = MealsDB_Migration::create_rates( $offset, $dry_run );
                break;
            default:
                wp_send_json_error( [ 'message' => 'Invalid phase: ' . $phase ] );
        }

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        if ( ! empty( $result['complete'] ) ) {
            $name  = $phase === 4 ? 'Create Clients' : 'Create Rates';
            $mode  = $dry_run ? ' (dry run)' : '';
            $stats = isset( $result['stats'] ) ? wp_json_encode( $result['stats'] ) : '{}';
            MealsDB_Migration::append_log( "DB Sync — {$name}{$mode} complete. Stats: {$stats}" );
        }

        wp_send_json_success( $result );
    }
}
