<?php
/**
 * Migration phase entry points for client + rate creation.
 *
 * Historically this class also ingested an old-site SQL dump (uploaded by
 * an operator) and migrated its users/products/orders into the live tables.
 * That upload-based import path has been removed: the plugin is now
 * installed directly on the live site, so there is no foreign dump to read.
 *
 * What remains are the phase entry points that build meals_clients /
 * meals_client_rates from data ALREADY in the live WP/WC tables. Their
 * logic lives in MealsDB_Migration_Consolidated; these methods are thin
 * delegators kept as stable call sites for the admin UI and AJAX layer.
 *
 * @package MealsDB
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MealsDB_Migration {

    const BATCH_SIZE      = 100;
    const PROGRESS_OPTION = 'mealsdb_migration_progress';
    const LOG_OPTION      = 'mealsdb_migration_log';

    // ──────────────────────────────────────────────
    //  Phase 4 – Create meals_clients
    // ──────────────────────────────────────────────

    /**
     * Create meals_clients records for government clients found in the live
     * WP/WC tables. Delegated to the consolidated engine so client creation
     * has a single implementation.
     */
    public static function create_clients( int $offset = 0, bool $dry_run = false ): array {
        return MealsDB_Migration_Consolidated::run_phase_create_clients( $offset, $dry_run );
    }

    // ──────────────────────────────────────────────
    //  Phase 5 – Create meals_client_rates
    // ──────────────────────────────────────────────

    /**
     * For each meals_clients record, create a default rate row. Delegated to
     * the consolidated engine (single implementation, includes the
     * OFFSET-drift pagination fix).
     */
    public static function create_rates( int $offset = 0, bool $dry_run = false ): array {
        return MealsDB_Migration_Consolidated::run_phase_create_rates( $offset, $dry_run );
    }

    // ──────────────────────────────────────────────
    //  Progress helpers
    // ──────────────────────────────────────────────

    public static function get_progress(): array {
        $default = [
            'phase'         => 0,
            'phase_offset'  => 0,
            'dry_run'       => true,
            'complete'      => false,
        ];
        $val = get_option( self::PROGRESS_OPTION, $default );
        return is_array( $val ) ? array_merge( $default, $val ) : $default;
    }

    public static function save_progress( array $data ): void {
        update_option( self::PROGRESS_OPTION, $data, false );
    }

    public static function reset(): void {
        delete_option( self::PROGRESS_OPTION );
        delete_option( self::LOG_OPTION );
    }

    public static function append_log( string $message ): void {
        $log   = get_option( self::LOG_OPTION, '' );
        $log  .= '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . "\n";
        update_option( self::LOG_OPTION, $log, false );
    }

    public static function get_log(): string {
        return (string) get_option( self::LOG_OPTION, '' );
    }
}
