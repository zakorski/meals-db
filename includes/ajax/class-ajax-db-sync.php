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

        // QW-1: throttle the START of a real (writing) run only — NOT every
        // chunk. This endpoint is driven by views/data-ops.php, which
        // recursively re-posts itself per 100-row chunk (offset-based) and then
        // again for the rates phase, so one sync legitimately fires many
        // back-to-back requests. Gating every call with migration_destructive
        // (5/hour) would 429 the operator mid-walk and leave the sync partial —
        // the exact failure the sibling migration handlers avoid (see
        // MealsDB_Ajax_Migration::run_consolidated_phase, whose first-chunk-only
        // pattern this mirrors, and ::verify(), which is deliberately
        // unthrottled for the same reason).
        //
        //   - Dry runs write nothing, so they are never rate-limited.
        //   - Real runs are checked only on the first chunk of a phase
        //     (offset === 0); subsequent chunks of the same walk pass through.
        //
        // A full real sync walks 2 phases (clients, rates) and consumes one
        // token per phase start — comfortably within the 5/hour bucket — while
        // still capping how many fresh destructive runs can be kicked off.
        if ( ! $dry_run && $offset === 0
            && class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'migration_destructive' ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded. Please wait before retrying.', 'meals-db' ) ], 429 );
        }

        $result = [];

        switch ( $phase ) {
            case 4:
                $result = MealsDB_Migration::create_clients( $offset, $dry_run );
                break;
            case 5:
                $result = MealsDB_Migration::create_rates( $offset, $dry_run );
                break;
            default:
                // Don't echo the user-supplied phase back in the error.
                // Reflecting untrusted input in admin-visible messages
                // is a small but unnecessary info-disclosure lever; the
                // operator already knows what they submitted.
                wp_send_json_error( [ 'message' => 'Invalid phase.' ] );
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
