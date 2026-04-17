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
        add_action( 'wp_ajax_mealsdb_backfill_addresses',   [ self::class, 'backfill_addresses' ] );
        add_action( 'wp_ajax_mealsdb_backfill_allocation_engine', [ self::class, 'backfill_allocation_engine' ] );
    }

    /**
     * Test a direct database connection and detect the table prefix.
     *
     * On success, the credentials are stashed in a short-lived transient
     * keyed to the requesting admin and a random token is returned. The
     * client never has to keep the password in JS memory or re-transmit
     * it on subsequent AJAX calls — it sends the token instead.
     */
    public static function test_db(): void {
        self::verify();

        $host   = sanitize_text_field( wp_unslash( $_POST['db_host'] ?? '' ) );
        $name   = sanitize_text_field( wp_unslash( $_POST['db_name'] ?? '' ) );
        $user   = sanitize_text_field( wp_unslash( $_POST['db_user'] ?? '' ) );
        $pass   = (string) wp_unslash( $_POST['db_pass'] ?? '' );

        if ( ! $host || ! $name || ! $user ) {
            wp_send_json_error( [ 'message' => 'Host, database name, and username are required.' ] );
        }

        $result = MealsDB_Migration::test_source_db( $host, $name, $user, $pass );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        $token = self::stash_credentials( [
            'host' => $host,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
        ] );

        if ( $token !== '' ) {
            $result['creds_token'] = $token;
        }

        wp_send_json_success( $result );
    }

    /**
     * Store source-DB credentials in a per-user transient for ~1 hour and
     * return an opaque token the client can use in lieu of resending creds.
     *
     * The transient is keyed by user_id + random token, so credentials from
     * one admin's session can't be reached by another. Returns '' if the
     * caller is unauthenticated (shouldn't happen — verify() ran already).
     */
    private static function stash_credentials( array $creds ): string {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return '';
        }
        $token = bin2hex( random_bytes( 16 ) );
        set_transient(
            self::credentials_transient_key( $user_id, $token ),
            $creds,
            HOUR_IN_SECONDS
        );
        return $token;
    }

    /**
     * Resolve a previously-stashed credentials token to the original creds.
     * Returns null if the token is unknown / expired / belongs to a
     * different user.
     */
    private static function resolve_credentials( string $token ): ?array {
        $token = preg_replace( '/[^a-f0-9]/', '', strtolower( $token ) );
        if ( $token === '' || strlen( $token ) !== 32 ) {
            return null;
        }
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return null;
        }
        $value = get_transient( self::credentials_transient_key( $user_id, $token ) );
        return is_array( $value ) ? $value : null;
    }

    private static function credentials_transient_key( int $user_id, string $token ): string {
        return 'mealsdb_mig_creds_' . $user_id . '_' . $token;
    }

    /**
     * Maximum migration upload size in bytes (2 GB hard cap).
     */
    private const MAX_UPLOAD_BYTES = 2147483648;

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

        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => 'Invalid upload.' ] );
        }

        if ( ! empty( $file['size'] ) && (int) $file['size'] > self::MAX_UPLOAD_BYTES ) {
            wp_send_json_error( [ 'message' => 'File exceeds the migration upload size limit.' ] );
        }

        // Validate extension
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'sql', 'gz' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Only .sql and .sql.gz files are allowed.' ] );
        }

        // Sniff content: SQL dumps start with comment / SQL keyword tokens;
        // gzip files always start with the magic bytes 1f 8b. This catches
        // a renamed PHP file before it hits disk in the upload directory.
        $head = (string) file_get_contents( $file['tmp_name'], false, null, 0, 1024 );
        if ( ! self::looks_like_sql_or_gzip( $head, $ext ) ) {
            wp_send_json_error( [ 'message' => 'File contents do not look like a SQL dump or gzip archive.' ] );
        }

        // Move to uploads directory
        $upload_dir = wp_upload_dir();
        $dest_dir   = trailingslashit( $upload_dir['basedir'] ) . 'mealsdb-migration';

        if ( ! wp_mkdir_p( $dest_dir ) ) {
            wp_send_json_error( [ 'message' => 'Cannot create upload directory.' ] );
        }

        // Defence-in-depth web blockers. .htaccess only helps Apache; nginx
        // and IIS ignore it entirely, which is why we also drop an
        // index.php that returns 403 and store with a random filename.
        self::ensure_web_block_files( $dest_dir );

        // Random destination filename. Defeats both the predictable
        // /uploads/mealsdb-migration/migration-source.sql guess and the
        // race where two concurrent uploads clobber each other.
        $token     = bin2hex( random_bytes( 16 ) );
        $dest_path = $dest_dir . '/migration-' . $token . '.' . ( $ext === 'gz' ? 'sql.gz' : 'sql' );

        if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
            wp_send_json_error( [ 'message' => 'Failed to move uploaded file.' ] );
        }

        // Restrict permissions where the OS lets us.
        @chmod( $dest_path, 0600 );

        // Detect prefix
        $prefix = MealsDB_Migration::detect_prefix( $dest_path );
        if ( ! $prefix ) {
            @unlink( $dest_path );
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
     * Coarse content sniff for SQL dumps (.sql) and gzip archives (.sql.gz).
     */
    private static function looks_like_sql_or_gzip( string $head, string $ext ): bool {
        if ( $head === '' ) {
            return false;
        }
        if ( $ext === 'gz' ) {
            return strncmp( $head, "\x1f\x8b", 2 ) === 0;
        }
        // SQL: tolerate UTF-8 BOM and leading whitespace, then expect a
        // comment marker or one of the common dump keywords.
        $trimmed = ltrim( $head, "\xEF\xBB\xBF \t\r\n" );
        $upper   = strtoupper( substr( $trimmed, 0, 32 ) );
        $prefixes = [ '--', '#', '/*', 'CREATE ', 'DROP ', 'INSERT ', 'SET ', 'USE ', 'LOCK ', 'UNLOCK ', 'ALTER ', 'START TRANSACTION', 'BEGIN' ];
        foreach ( $prefixes as $prefix ) {
            if ( strncmp( $upper, $prefix, strlen( $prefix ) ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Drop web-server blockers in the migration upload dir.
     *
     * .htaccess covers Apache; index.php covers nginx/IIS by serving a
     * 403 if anyone manages to guess a filename inside the directory.
     */
    private static function ensure_web_block_files( string $dir ): void {
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Order allow,deny\nDeny from all\n" );
            @chmod( $htaccess, 0600 );
        }

        $index_php = $dir . '/index.php';
        if ( ! file_exists( $index_php ) ) {
            file_put_contents(
                $index_php,
                "<?php\nhttp_response_code(403);\nexit;\n"
            );
            @chmod( $index_php, 0600 );
        }

        $index_html = $dir . '/index.html';
        if ( ! file_exists( $index_html ) ) {
            file_put_contents( $index_html, '' );
        }
    }

    /**
     * Detect the table prefix from a SQL dump file path (legacy / fallback).
     */
    public static function detect_prefix(): void {
        self::verify();

        $file_path = self::sanitize_path( (string) wp_unslash( $_POST['file_path'] ?? '' ) );
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

        $file_path     = self::sanitize_path( (string) wp_unslash( $_POST['file_path'] ?? '' ) );
        $source_prefix = sanitize_text_field( wp_unslash( $_POST['source_prefix'] ?? '' ) );
        $byte_offset   = (int) ( $_POST['byte_offset'] ?? 0 );
        $dry_run       = MealsDB_Helpers::bool_flag( $_POST['dry_run'] ?? null, true );

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

        $source_prefix = sanitize_text_field( wp_unslash( $_POST['source_prefix'] ?? '' ) );
        $table_index   = (int) ( $_POST['table_index'] ?? 0 );
        $dry_run       = MealsDB_Helpers::bool_flag( $_POST['dry_run'] ?? null, true );

        // Prefer the credentials token issued by test_db. The raw fields
        // remain accepted only as a backwards-compat fallback.
        $token = sanitize_text_field( wp_unslash( $_POST['creds_token'] ?? '' ) );
        $creds = $token !== '' ? self::resolve_credentials( $token ) : null;

        if ( $creds !== null ) {
            $host = $creds['host'] ?? '';
            $name = $creds['name'] ?? '';
            $user = $creds['user'] ?? '';
            $pass = $creds['pass'] ?? '';
        } else {
            $host = sanitize_text_field( wp_unslash( $_POST['db_host'] ?? '' ) );
            $name = sanitize_text_field( wp_unslash( $_POST['db_name'] ?? '' ) );
            $user = sanitize_text_field( wp_unslash( $_POST['db_user'] ?? '' ) );
            $pass = (string) wp_unslash( $_POST['db_pass'] ?? '' );
        }

        if ( ! $host || ! $name || ! $user || ! $source_prefix ) {
            wp_send_json_error( [ 'message' => 'Missing database connection parameters. Re-run "Test connection" to refresh credentials.' ] );
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
        $dry_run       = MealsDB_Helpers::bool_flag( $_POST['dry_run'] ?? null, true );
        $source_prefix = sanitize_text_field( wp_unslash( $_POST['source_prefix'] ?? '' ) );

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

        $source_prefix = sanitize_text_field( wp_unslash( $_POST['source_prefix'] ?? '' ) );
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
     *
     * Returns '' unless the resolved path lives under the plugin's migration
     * upload directory. The previous '..'-substring check let absolute paths
     * such as /etc/passwd or wp-config.php through; downstream readers would
     * then disclose file size or contents back to the admin.
     */
    private static function sanitize_path( string $raw ): string {
        $path = wp_normalize_path( trim( $raw ) );

        if ( $path === '' ) {
            return '';
        }

        $base = self::migration_dir_realpath();
        if ( $base === '' ) {
            return '';
        }

        $resolved = realpath( $path );
        if ( $resolved === false ) {
            return '';
        }
        $resolved = wp_normalize_path( $resolved );

        // strict prefix match — the trailing slash prevents
        // /uploads/mealsdb-migration-other/ from satisfying a base of
        // /uploads/mealsdb-migration.
        if ( strpos( $resolved . '/', $base . '/' ) !== 0 ) {
            return '';
        }

        return $resolved;
    }

    /**
     * Resolve the on-disk migration upload directory (canonical path).
     */
    private static function migration_dir_realpath(): string {
        $uploads = wp_upload_dir();
        if ( empty( $uploads['basedir'] ) ) {
            return '';
        }
        $dir = trailingslashit( $uploads['basedir'] ) . 'mealsdb-migration';
        $resolved = realpath( $dir );
        return $resolved === false ? '' : wp_normalize_path( $resolved );
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

    /**
     * Backfill allocation tables from historical WooCommerce orders.
     */
    public static function backfill_allocation_engine(): void {
        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';
        if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'mealsdb_nonce' ) ) {
            wp_send_json( [ 'success' => false, 'message' => 'Invalid request.' ] );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json( [ 'success' => false, 'message' => 'Insufficient permissions.' ], 403 );
        }

        $start_month = isset( $_REQUEST['start_month'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['start_month'] ) ) : '';
        $end_month   = isset( $_REQUEST['end_month'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['end_month'] ) ) : gmdate( 'Y-m' );
        $dry_run     = MealsDB_Helpers::bool_flag( $_REQUEST['dry_run'] ?? null, true );

        if ( ! MealsDB_Helpers::is_valid_ym( $start_month ) || ! MealsDB_Helpers::is_valid_ym( $end_month ) ) {
            wp_send_json( [ 'success' => false, 'message' => 'Invalid month format. Use YYYY-MM.' ] );
        }

        require_once dirname( dirname( __FILE__ ) ) . '/services/class-backfill-allocations-engine.php';

        $result = MealsDB_Backfill_Allocations_Engine::run( $start_month, $end_month, $dry_run );

        if ( isset( $result['error'] ) ) {
            wp_send_json( [ 'success' => false, 'message' => $result['error'] ] );
        }

        wp_send_json( [ 'success' => true, 'stats' => $result ] );
    }

    /**
     * Backfill addresses, delivery_area_name (zone data), and default_rate_id
     * from legacy wp_usermeta values and meals_client_rates.
     */
    public static function backfill_addresses(): void {
        if (!check_ajax_referer('mealsdb_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
            return;
        }

        $dry_run = !empty($_POST['dry_run']);

        require_once dirname(dirname(__FILE__)) . '/services/class-backfill-addresses.php';

        $result = MealsDB_Backfill_Addresses::run($dry_run);

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
            return;
        }

        wp_send_json_success($result);
    }
}
