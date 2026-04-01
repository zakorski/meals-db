<?php
/**
 * AJAX endpoints for the consolidated site migration tool.
 *
 * @package MealsDB
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MealsDB_Ajax_Migration {

    public static function init(): void {
        add_action( 'wp_ajax_mealsdb_migration_detect',      [ self::class, 'detect_prefix' ] );
        add_action( 'wp_ajax_mealsdb_migration_test_db',     [ self::class, 'test_db' ] );
        add_action( 'wp_ajax_mealsdb_migration_upload',      [ self::class, 'upload_file' ] );
        add_action( 'wp_ajax_mealsdb_migration_load',        [ self::class, 'load_source' ] );
        add_action( 'wp_ajax_mealsdb_migration_load_from_db', [ self::class, 'load_from_db' ] );
        add_action( 'wp_ajax_mealsdb_migration_phase',       [ self::class, 'run_phase' ] );
        add_action( 'wp_ajax_mealsdb_migration_cleanup',     [ self::class, 'cleanup' ] );
        add_action( 'wp_ajax_mealsdb_migration_reset',       [ self::class, 'reset' ] );
        add_action( 'wp_ajax_mealsdb_migration_log',         [ self::class, 'get_log' ] );
        add_action( 'wp_ajax_mealsdb_backfill_allowances',   [ self::class, 'backfill_allowances' ] );
    }

    /**
     * Test a direct database connection and detect the table prefix.
     */
    public static function test_db(): void {
        self::verify();

        $host   = sanitize_text_field( $_POST['db_host'] ?? '' );
        $name   = sanitize_text_field( $_POST['db_name'] ?? '' );
        $user   = sanitize_text_field( $_POST['db_user'] ?? '' );
        $pass   = $_POST['db_pass'] ?? '';

        if ( ! $host || ! $name || ! $user ) {
            wp_send_json_error( [ 'message' => 'Host, database name, and username are required.' ] );
        }

        $result = MealsDB_Migration::test_source_db( $host, $name, $user, $pass );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * Handle SQL file upload.
     */
    public static function upload_file(): void {
        self::verify();

        if ( empty( $_FILES['sql_file'] ) ) {
            wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
        }

        $file = $_FILES['sql_file'];

        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize limit.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            ];
            $msg = $errors[ $file['error'] ] ?? 'Upload error code: ' . $file['error'];
            wp_send_json_error( [ 'message' => $msg ] );
        }

        // Validate extension
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'sql', 'gz' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Only .sql and .sql.gz files are allowed.' ] );
        }

        // Move to uploads directory
        $upload_dir = wp_upload_dir();
        $dest_dir   = trailingslashit( $upload_dir['basedir'] ) . 'mealsdb-migration';

        if ( ! wp_mkdir_p( $dest_dir ) ) {
            wp_send_json_error( [ 'message' => 'Cannot create upload directory.' ] );
        }

        // Write an .htaccess to block direct access
        $htaccess = $dest_dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Deny from all\n" );
        }

        $dest_path = $dest_dir . '/migration-source.sql';

        if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
            wp_send_json_error( [ 'message' => 'Failed to move uploaded file.' ] );
        }

        // Detect prefix
        $prefix = MealsDB_Migration::detect_prefix( $dest_path );
        if ( ! $prefix ) {
            wp_send_json_error( [ 'message' => 'File uploaded but could not detect a table prefix in the SQL dump.' ] );
        }

        $file_size = filesize( $dest_path );

        wp_send_json_success( [
            'prefix'    => $prefix,
            'file_path' => $dest_path,
            'file_size' => $file_size,
            'file_mb'   => round( $file_size / ( 1024 * 1024 ), 1 ),
        ] );
    }

    /**
     * Detect the table prefix from a SQL dump file path (legacy / fallback).
     */
    public static function detect_prefix(): void {
        self::verify();

        $file_path = self::sanitize_path( $_POST['file_path'] ?? '' );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            wp_send_json_error( [ 'message' => 'File not found: ' . $file_path ] );
        }

        $prefix = MealsDB_Migration::detect_prefix( $file_path );
        if ( ! $prefix ) {
            wp_send_json_error( [ 'message' => 'Could not detect table prefix in the SQL dump.' ] );
        }

        $file_size = filesize( $file_path );

        wp_send_json_success( [
            'prefix'    => $prefix,
            'file_size' => $file_size,
            'file_mb'   => round( $file_size / ( 1024 * 1024 ), 1 ),
        ] );
    }

    /**
     * Phase 0 (file mode): Load source tables from the SQL dump (chunked).
     */
    public static function load_source(): void {
        self::verify();
        set_time_limit( 300 );

        $file_path     = self::sanitize_path( $_POST['file_path'] ?? '' );
        $source_prefix = sanitize_text_field( $_POST['source_prefix'] ?? '' );
        $byte_offset   = (int) ( $_POST['byte_offset'] ?? 0 );
        $dry_run       = filter_var( $_POST['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN );

        if ( ! $file_path || ! $source_prefix ) {
            wp_send_json_error( [ 'message' => 'Missing file_path or source_prefix.' ] );
        }

        $result = MealsDB_Migration::load_source( $file_path, $source_prefix, $byte_offset, $dry_run );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        if ( $result['complete'] ) {
            MealsDB_Migration::append_log(
                "Phase 0 complete: loaded source tables from dump ({$result['statements']} total statements)."
            );
        }

        wp_send_json_success( $result );
    }

    /**
     * Phase 0 (database mode): Copy source tables from the remote DB (batched).
     */
    public static function load_from_db(): void {
        self::verify();
        set_time_limit( 300 );

        $host          = sanitize_text_field( $_POST['db_host'] ?? '' );
        $name          = sanitize_text_field( $_POST['db_name'] ?? '' );
        $user          = sanitize_text_field( $_POST['db_user'] ?? '' );
        $pass          = $_POST['db_pass'] ?? '';
        $source_prefix = sanitize_text_field( $_POST['source_prefix'] ?? '' );
        $table_index   = (int) ( $_POST['table_index'] ?? 0 );
        $dry_run       = filter_var( $_POST['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN );

        if ( ! $host || ! $name || ! $user || ! $source_prefix ) {
            wp_send_json_error( [ 'message' => 'Missing database connection parameters.' ] );
        }

        $result = MealsDB_Migration::copy_table_from_db( $host, $name, $user, $pass, $source_prefix, $table_index, $dry_run );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        if ( $result['complete'] ) {
            MealsDB_Migration::append_log(
                "Phase 0 complete: copied {$result['tables_copied']} source tables from database '{$name}'."
            );
        }

        wp_send_json_success( $result );
    }

    /**
     * Run a migration phase (1-5).
     */
    public static function run_phase(): void {
        self::verify();
        set_time_limit( 300 );

        $phase         = (int) ( $_POST['phase'] ?? 0 );
        $offset        = (int) ( $_POST['offset'] ?? 0 );
        $dry_run       = filter_var( $_POST['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN );
        $source_prefix = sanitize_text_field( $_POST['source_prefix'] ?? '' );

        $result = [];

        switch ( $phase ) {
            case 1:
                $result = MealsDB_Migration::migrate_users( $source_prefix, $offset, $dry_run );
                break;
            case 2:
                $result = MealsDB_Migration::migrate_products( $source_prefix, $offset, $dry_run );
                break;
            case 3:
                $result = MealsDB_Migration::migrate_orders( $source_prefix, $offset, $dry_run );
                break;
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

        // Log phase completion
        if ( ! empty( $result['complete'] ) ) {
            $phase_names = [
                1 => 'Users',
                2 => 'Products',
                3 => 'Orders',
                4 => 'Meals Clients',
                5 => 'Client Rates',
            ];
            $name  = $phase_names[ $phase ] ?? "Phase {$phase}";
            $mode  = $dry_run ? ' (dry run)' : '';
            $stats = isset( $result['stats'] ) ? wp_json_encode( $result['stats'] ) : '{}';
            MealsDB_Migration::append_log( "{$name}{$mode} complete. Stats: {$stats}" );
        }

        $result['phase'] = $phase;
        wp_send_json_success( $result );
    }

    /**
     * Cleanup: drop source tables.
     */
    public static function cleanup(): void {
        self::verify();

        $source_prefix = sanitize_text_field( $_POST['source_prefix'] ?? '' );
        if ( ! $source_prefix ) {
            wp_send_json_error( [ 'message' => 'Missing source_prefix.' ] );
        }

        $result = MealsDB_Migration::cleanup( $source_prefix );
        MealsDB_Migration::append_log( "Cleanup: dropped {$result['dropped']} source tables." );

        wp_send_json_success( $result );
    }

    /**
     * Reset progress and log.
     */
    public static function reset(): void {
        self::verify();
        MealsDB_Migration::reset();
        wp_send_json_success();
    }

    /**
     * Return the migration log.
     */
    public static function get_log(): void {
        self::verify();
        wp_send_json_success( [ 'log' => MealsDB_Migration::get_log() ] );
    }

    // ── Helpers ──────────────────────────────────

    private static function verify(): void {
        check_ajax_referer( 'mealsdb_migration_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
    }

    /**
     * Sanitize and validate a file-system path.
     */
    private static function sanitize_path( string $raw ): string {
        $path = wp_normalize_path( trim( $raw ) );

        // Block directory traversal
        if ( strpos( $path, '..' ) !== false ) {
            return '';
        }

        return $path;
    }

    /**
     * Backfill allowance_mains, allowance_sides, and requisition_period
     * from legacy wp_usermeta values.
     */
    public static function backfill_allowances(): void {
        if (!check_ajax_referer('mealsdb_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
            return;
        }

        $dry_run = !empty($_POST['dry_run']);

        require_once dirname(dirname(__FILE__)) . '/services/class-backfill-allowances.php';

        $result = MealsDB_Backfill_Allowances::run($dry_run);

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
            return;
        }

        wp_send_json_success($result);
    }
}
