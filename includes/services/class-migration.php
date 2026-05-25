<?php
/**
 * Consolidated site migration service.
 *
 * Reads source tables (imported from an old-site SQL dump with a foreign prefix)
 * that coexist in the WordPress database, then migrates users, products, orders
 * into the live WP/WC tables and creates meals_clients + meals_client_rates
 * records in the external Meals DB for government clients.
 *
 * @package MealsDB
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MealsDB_Migration {

    const BATCH_SIZE      = 100;
    const LOAD_CHUNK_BYTES = 10 * 1024 * 1024; // 10 MB per AJAX chunk
    /**
     * Max bytes fgets() will read into a single buffer in one call.
     * Dumps from mysqldump --extended-insert can produce INSERT lines
     * well over 1 MB on large orders tables, so we keep this large,
     * but well under the 32 MB we used to allocate unconditionally —
     * a malformed or adversarial dump with a single multi-MB line
     * would otherwise have no upper bound on per-call memory.
     */
    const LOAD_FGETS_BYTES = 4 * 1024 * 1024;  // 4 MB line buffer
    /**
     * Upper bound on the size of an accumulated multi-line statement
     * we are willing to execute. A single statement already fits
     * comfortably inside max_allowed_packet (default 4 MB, practical
     * ceiling 64 MB); anything beyond 16 MB is pathological and
     * should be rejected rather than pushed at the server.
     */
    const MAX_STATEMENT_BYTES = 16 * 1024 * 1024; // 16 MB per statement
    const PROGRESS_OPTION = 'mealsdb_migration_progress';
    const LOG_OPTION      = 'mealsdb_migration_log';

    /**
     * Table suffixes we need from the source dump.
     */
    private static $needed_suffixes = [
        'users',
        'usermeta',
        'posts',
        'postmeta',
        'terms',
        'term_taxonomy',
        'term_relationships',
        'wc_orders',
        'wc_orders_meta',
        'wc_order_addresses',
        'wc_order_operational_data',
        'woocommerce_order_items',
        'woocommerce_order_itemmeta',
    ];

    /**
     * Customer-group normalization map (lowercase key → meals_clients.client_type).
     */
    private static $type_map = [
        'sdnb'        => 'SDNB',
        'sdnb rural'  => 'SDNB',
        'extra mural' => 'SDNB',
        'veterans'    => 'Veteran',
    ];

    // ──────────────────────────────────────────────
    //  Prefix detection
    // ──────────────────────────────────────────────

    /**
     * Detect the table prefix used in a SQL dump file.
     */
    public static function detect_prefix( string $file_path ): ?string {
        $handle = @fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return null;
        }

        $chunk = fread( $handle, 100 * 1024 ); // first 100 KB
        fclose( $handle );

        // Look for CREATE TABLE `<prefix>users`
        if ( preg_match( '/CREATE TABLE.*?`(\w+?)users`/i', $chunk, $m ) ) {
            return $m[1];
        }

        // Fallback: INSERT INTO `<prefix>users`
        if ( preg_match( '/INSERT INTO `(\w+?)users`/i', $chunk, $m ) ) {
            return $m[1];
        }

        return null;
    }

    /**
     * Build the full list of source table names for a given prefix.
     */
    public static function get_source_tables( string $prefix ): array {
        return array_map( function ( $suffix ) use ( $prefix ) {
            return $prefix . $suffix;
        }, self::$needed_suffixes );
    }

    // ──────────────────────────────────────────────
    //  Database source – test connection & detect prefix
    // ──────────────────────────────────────────────

    /**
     * Test a direct MySQL connection to the source database and detect the
     * table prefix by looking for a `*_users` table.
     *
     * @return array{prefix:string, tables:int, db_name:string}|array{error:string}
     */
    public static function test_source_db( string $host, string $db_name, string $user, string $pass ): array {
        $conn = @new \mysqli( $host, $user, $pass, $db_name );

        if ( $conn->connect_errno ) {
            return [ 'error' => 'Connection failed: ' . $conn->connect_error ];
        }

        // Find the prefix by looking for a table ending in "users"
        $result = $conn->query( 'SHOW TABLES' );
        if ( ! $result ) {
            $conn->close();
            return [ 'error' => 'Cannot list tables in database.' ];
        }

        $tables = [];
        $prefix = null;
        while ( $row = $result->fetch_row() ) {
            $tables[] = $row[0];
            if ( $prefix === null && preg_match( '/^(.+?)users$/', $row[0], $m ) ) {
                $prefix = $m[1];
            }
        }
        $conn->close();

        if ( ! $prefix ) {
            return [ 'error' => 'Could not detect a table prefix (no *users table found).' ];
        }

        return [
            'prefix'  => $prefix,
            'tables'  => count( $tables ),
            'db_name' => $db_name,
        ];
    }

    /**
     * Copy a single source table from the source database into the local
     * WordPress database.  Called once per table by the AJAX handler.
     *
     * Connects to the source DB with the provided credentials, reads the
     * CREATE TABLE statement and all rows, then writes them into the local
     * WP database via $wpdb.
     *
     * @return array{table:string, rows:int, table_index:int, total_tables:int, tables_copied:int, complete:bool, percent:float}|array{error:string}
     */
    public static function copy_table_from_db( string $host, string $db_name, string $user, string $pass, string $source_prefix, int $table_index = 0, bool $dry_run = false ): array {
        global $wpdb;

        $suffixes     = self::$needed_suffixes;
        $total_tables = count( $suffixes );

        if ( $table_index >= $total_tables ) {
            return [
                'table_index'   => $table_index,
                'total_tables'  => $total_tables,
                'tables_copied' => $table_index,
                'complete'      => true,
                'percent'       => 100,
            ];
        }

        $suffix       = $suffixes[ $table_index ];
        $source_table = $source_prefix . $suffix;
        $source_esc   = '`' . str_replace( '`', '``', $source_table ) . '`';

        // Safety: never drop or overwrite live WordPress tables.
        // If the source prefix matches the local WP prefix, the tables
        // already exist in the local database — skip the copy entirely.
        if ( $source_prefix === $wpdb->prefix ) {
            $src_conn = @new \mysqli( $host, $user, $pass, $db_name );
            if ( $src_conn->connect_errno ) {
                return [ 'error' => 'Source DB connection failed: ' . $src_conn->connect_error ];
            }
            $count_result = $src_conn->query( "SELECT COUNT(*) FROM {$source_esc}" );
            $total_rows   = $count_result ? (int) $count_result->fetch_row()[0] : 0;
            $src_conn->close();

            $copied = $table_index + 1;

            return [
                'table'         => $source_table,
                'rows'          => $total_rows,
                'table_index'   => $copied,
                'total_tables'  => $total_tables,
                'tables_copied' => $copied,
                'complete'      => $copied >= $total_tables,
                'percent'       => round( ( $copied / $total_tables ) * 100, 1 ),
                'skipped'       => true,
            ];
        }

        $local_table  = $source_table; // same name in local WP DB
        $local_esc    = '`' . str_replace( '`', '``', $local_table ) . '`';

        // Prevent dropping any core WordPress table regardless of prefix detection.
        $wp_core_suffixes = [
            'users', 'usermeta', 'posts', 'postmeta', 'options',
            'comments', 'commentmeta', 'terms', 'term_taxonomy',
            'term_relationships', 'links',
        ];
        foreach ( $wp_core_suffixes as $core_suffix ) {
            if ( $local_table === $wpdb->prefix . $core_suffix ) {
                return [ 'error' => "Refusing to overwrite live WordPress table: {$local_table}" ];
            }
        }

        // Connect to source database with the provided credentials
        $src_conn = @new \mysqli( $host, $user, $pass, $db_name );
        if ( $src_conn->connect_errno ) {
            return [ 'error' => 'Source DB connection failed: ' . $src_conn->connect_error ];
        }
        $src_conn->set_charset( 'utf8mb4' );

        // Get the CREATE TABLE statement from the source
        $show = $src_conn->query( "SHOW CREATE TABLE {$source_esc}" );
        if ( ! $show || $show->num_rows === 0 ) {
            $err = $src_conn->error;
            $src_conn->close();
            return [ 'error' => "Table {$source_table} not found in source DB: {$err}" ];
        }
        $create_row = $show->fetch_row();
        $create_sql = $create_row[1]; // The full CREATE TABLE statement

        // Rewrite the table name to the local name (they're the same, but be safe)
        $create_sql = preg_replace(
            '/CREATE TABLE\s+`[^`]+`/',
            "CREATE TABLE {$local_esc}",
            $create_sql,
            1
        );

        // In dry-run mode, just count the rows without touching local tables.
        if ( $dry_run ) {
            $count_result = $src_conn->query( "SELECT COUNT(*) FROM {$source_esc}" );
            $total_rows   = $count_result ? (int) $count_result->fetch_row()[0] : 0;
            $src_conn->close();
        } else {
            // Drop + recreate locally
            $wpdb->query( "DROP TABLE IF EXISTS {$local_esc}" );
            $wpdb->suppress_errors( true );
            $create_result = $wpdb->query( $create_sql );
            if ( $create_result === false ) {
                $err = $wpdb->last_error;
                $wpdb->suppress_errors( false );
                $src_conn->close();
                return [ 'error' => "Failed to create local table {$local_table}: {$err}" ];
            }
            $wpdb->suppress_errors( false );

            // Read rows from source and insert into local DB in batches
            $total_rows  = 0;
            $batch_size  = 500;
            $offset      = 0;

            while ( true ) {
                $result = $src_conn->query(
                    "SELECT * FROM {$source_esc} LIMIT {$batch_size} OFFSET {$offset}"
                );

                if ( ! $result || $result->num_rows === 0 ) {
                    break;
                }

                $fields = $result->fetch_fields();
                $col_names = [];
                foreach ( $fields as $field ) {
                    $col_names[] = '`' . str_replace( '`', '``', $field->name ) . '`';
                }
                $col_sql = implode( ', ', $col_names );

                // Build a multi-row INSERT for the batch. Use
                // $wpdb->prepare() with %s placeholders rather than
                // $wpdb->_real_escape(): prepare() is a public, supported
                // API that routes through mysqli_real_escape_string on
                // the same DB handle wpdb is connected to, with
                // consistent quoting semantics; _real_escape() is a
                // leading-underscore private helper that has been subtly
                // broken in past WP versions when called with
                // non-string input. NULL is still emitted literally
                // since wpdb::prepare coerces '%s' binds to strings.
                $value_groups = [];
                $bind_values  = [];
                while ( $row = $result->fetch_row() ) {
                    $placeholders = [];
                    foreach ( $row as $val ) {
                        if ( $val === null ) {
                            $placeholders[] = 'NULL';
                        } else {
                            $placeholders[] = '%s';
                            $bind_values[]  = (string) $val;
                        }
                    }
                    $value_groups[] = '(' . implode( ', ', $placeholders ) . ')';
                    $total_rows++;
                }

                if ( ! empty( $value_groups ) ) {
                    $insert_sql = "INSERT IGNORE INTO {$local_esc} ({$col_sql}) VALUES " . implode( ', ', $value_groups );
                    $prepared   = $wpdb->prepare( $insert_sql, ...$bind_values );
                    if ( ! is_string( $prepared ) || $prepared === '' ) {
                        $src_conn->close();
                        return [ 'error' => "Failed to prepare INSERT batch for {$local_table}" ];
                    }
                    $wpdb->suppress_errors( true );
                    $wpdb->query( $prepared );
                    $wpdb->suppress_errors( false );
                }

                $offset += $batch_size;
            }

            $src_conn->close();
        }
        $copied = $table_index + 1;

        return [
            'table'         => $local_table,
            'rows'          => $total_rows,
            'table_index'   => $copied,
            'total_tables'  => $total_tables,
            'tables_copied' => $copied,
            'complete'      => $copied >= $total_tables,
            'percent'       => round( ( $copied / $total_tables ) * 100, 1 ),
        ];
    }

    // ──────────────────────────────────────────────
    //  Phase 0 – Load source tables from SQL dump
    // ──────────────────────────────────────────────

    /**
     * Stream the SQL dump and execute CREATE TABLE / INSERT statements
     * for the target tables only.  Processes up to LOAD_CHUNK_BYTES per call.
     *
     * @return array{statements:int, byte_offset:int, file_size:int, complete:bool, percent:float, errors:string[]}
     */
    public static function load_source( string $file_path, string $source_prefix, int $byte_offset = 0, bool $dry_run = false ): array {
        global $wpdb;

        // Safety: refuse to load if the source prefix matches the live WP prefix.
        if ( $source_prefix === $wpdb->prefix ) {
            return [ 'error' => 'Source prefix matches the live WordPress prefix. Tables already exist — Phase 0 load is not needed.' ];
        }

        $target_tables = self::get_source_tables( $source_prefix );
        $handle = @fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return [ 'error' => 'Cannot open file: ' . $file_path ];
        }

        $file_size = (int) filesize( $file_path );
        if ( $byte_offset > 0 ) {
            fseek( $handle, $byte_offset );
        }

        $statements = 0;
        $buffer     = '';
        $in_target  = false;
        $bytes_read = 0;
        $errors     = [];

        while ( ! feof( $handle ) && $bytes_read < self::LOAD_CHUNK_BYTES ) {
            $line = fgets( $handle, self::LOAD_FGETS_BYTES );
            if ( $line === false ) {
                break;
            }
            $bytes_read += strlen( $line );
            $trimmed = trim( $line );

            // Skip comments, blank lines, SET, UNLOCK
            if ( $trimmed === '' || strpos( $trimmed, '--' ) === 0 || strpos( $trimmed, '/*' ) === 0 ) {
                if ( ! $in_target ) {
                    continue;
                }
            }

            if ( strpos( $trimmed, 'UNLOCK TABLES' ) === 0 ) {
                $in_target = false;
                $buffer    = '';
                continue;
            }

            if ( strpos( $trimmed, 'LOCK TABLES' ) === 0 ) {
                $in_target = false;
                $buffer    = '';
                continue;
            }

            // Detect start of a new relevant statement
            if ( ! $in_target ) {
                $is_target = false;
                foreach ( $target_tables as $table ) {
                    if ( strpos( $trimmed, '`' . $table . '`' ) !== false ) {
                        $is_target = true;
                        break;
                    }
                }

                if ( $is_target && preg_match( '/^(CREATE TABLE|INSERT INTO|DROP TABLE)/', $trimmed ) ) {
                    $in_target = true;
                    $buffer    = $line;
                }
            } else {
                $buffer .= $line;
            }

            // Reject runaway statements. mysqldump with default settings
            // produces INSERTs well under a megabyte, so a buffer that
            // has grown past MAX_STATEMENT_BYTES without hitting a
            // terminator is either a malformed dump or an attacker
            // probing memory limits. Drop the partial statement, log
            // the event, and resume scanning for the next top-level
            // CREATE / INSERT / DROP.
            if ( $in_target && strlen( $buffer ) > self::MAX_STATEMENT_BYTES ) {
                $errors[]  = sprintf(
                    'Statement exceeded MAX_STATEMENT_BYTES (%d bytes) and was skipped.',
                    self::MAX_STATEMENT_BYTES
                );
                $buffer    = '';
                $in_target = false;
                continue;
            }

            // Execute when statement is complete (ends with ;)
            if ( $in_target && substr( $trimmed, -1 ) === ';' ) {
                if ( ! $dry_run ) {
                    // Suppress errors so wpdb doesn't bail
                    $wpdb->suppress_errors( true );
                    $result = $wpdb->query( $buffer );
                    $wpdb->suppress_errors( false );

                    if ( $result === false && $wpdb->last_error ) {
                        $errors[] = substr( $wpdb->last_error, 0, 200 );
                    }
                }

                $statements++;
                $buffer    = '';
                $in_target = false;
            }
        }

        $new_offset = $byte_offset + $bytes_read;
        $complete   = feof( $handle );
        fclose( $handle );

        return [
            'statements'  => $statements,
            'byte_offset' => $new_offset,
            'file_size'   => $file_size,
            'complete'    => $complete,
            'percent'     => $file_size > 0 ? round( ( $new_offset / $file_size ) * 100, 1 ) : 100,
            'errors'      => array_slice( $errors, 0, 10 ),
        ];
    }

    // ──────────────────────────────────────────────
    //  Phase 1 – Migrate users
    // ──────────────────────────────────────────────

    /**
     * Copy users (excluding ID 1 / admin) from the source tables into the live
     * WordPress users / usermeta tables.  Preserves original IDs.
     */
    public static function migrate_users( string $source_prefix, int $offset = 0, bool $dry_run = false ): array {
        global $wpdb;

        $src       = $source_prefix . 'users';
        $src_meta  = $source_prefix . 'usermeta';
        $dst       = $wpdb->users;
        $dst_meta  = $wpdb->usermeta;
        $total     = max( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$src}` WHERE ID > 1" ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$src}` WHERE ID > 1 ORDER BY ID ASC LIMIT %d OFFSET %d",
            self::BATCH_SIZE,
            $offset
        ), ARRAY_A );

        $stats = [ 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'meta' => 0 ];

        foreach ( $rows as $user ) {
            $uid = (int) $user['ID'];

            // Skip if already present
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$dst} WHERE ID = %d OR user_email = %s",
                $uid,
                $user['user_email']
            ) );

            if ( $exists ) {
                $stats['skipped']++;
                continue;
            }

            if ( $dry_run ) {
                $stats['imported']++;
                continue;
            }

            // Build column / value pairs
            $cols         = array_keys( $user );
            $placeholders = implode( ', ', array_fill( 0, count( $cols ), '%s' ) );
            $col_sql      = '`' . implode( '`, `', $cols ) . '`';

            $result = $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO `{$dst}` ({$col_sql}) VALUES ({$placeholders})",
                array_values( $user )
            ) );

            if ( $result === false ) {
                $stats['errors']++;
                continue;
            }

            // Copy usermeta (auto-generate umeta_id).
            // Use a NOT EXISTS guard so re-runs skip rows that already exist,
            // without deleting any live WordPress usermeta.
            $meta_count = (int) $wpdb->query( $wpdb->prepare(
                "INSERT INTO `{$dst_meta}` (user_id, meta_key, meta_value)
                 SELECT s.user_id, s.meta_key, s.meta_value
                 FROM `{$src_meta}` s
                 WHERE s.user_id = %d
                   AND NOT EXISTS (
                       SELECT 1 FROM `{$dst_meta}` d
                       WHERE d.user_id = s.user_id
                         AND d.meta_key = s.meta_key
                   )",
                $uid
            ) );

            $stats['meta'] += max( 0, $meta_count );
            $stats['imported']++;
        }

        return [
            'stats'    => $stats,
            'offset'   => $offset + self::BATCH_SIZE,
            'total'    => $total,
            'complete' => count( $rows ) < self::BATCH_SIZE,
        ];
    }

    // ──────────────────────────────────────────────
    //  Phase 2 – Migrate products
    // ──────────────────────────────────────────────

    /**
     * Copy WooCommerce products (post_type = product | product_variation) plus
     * their postmeta, terms, and term relationships.
     */
    public static function migrate_products( string $source_prefix, int $offset = 0, bool $dry_run = false ): array {
        global $wpdb;

        $src_posts  = $source_prefix . 'posts';
        $src_meta   = $source_prefix . 'postmeta';
        $src_terms  = $source_prefix . 'terms';
        $src_tt     = $source_prefix . 'term_taxonomy';
        $src_tr     = $source_prefix . 'term_relationships';
        $dst_posts  = $wpdb->posts;
        $dst_meta   = $wpdb->postmeta;
        $dst_terms  = $wpdb->terms;
        $dst_tt     = $wpdb->term_taxonomy;
        $dst_tr     = $wpdb->term_relationships;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$src_posts}` WHERE post_type IN ('product','product_variation')"
        );

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM `{$src_posts}` WHERE post_type IN ('product','product_variation') ORDER BY ID ASC LIMIT %d OFFSET %d",
            self::BATCH_SIZE,
            $offset
        ) );

        $stats = [ 'posts' => 0, 'meta' => 0, 'skipped' => 0 ];

        if ( empty( $ids ) ) {
            return [ 'stats' => $stats, 'offset' => $offset, 'total' => $total, 'complete' => true ];
        }

        $ids_str = implode( ',', array_map( 'intval', $ids ) );

        if ( $dry_run ) {
            // Count posts that don't already exist in the destination
            $existing = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$dst_posts}` WHERE ID IN ({$ids_str})"
            );
            $stats['posts']   = count( $ids ) - $existing;
            $stats['skipped'] = $existing;

            // Count meta rows that would be inserted
            $stats['meta'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$src_meta}` WHERE post_id IN ({$ids_str})"
            );

            return [
                'stats'    => $stats,
                'offset'   => $offset + self::BATCH_SIZE,
                'total'    => $total,
                'complete' => count( $ids ) < self::BATCH_SIZE,
            ];
        }

        // Import terms (once – idempotent via IGNORE)
        if ( $offset === 0 ) {
            $wpdb->query( "INSERT IGNORE INTO `{$dst_terms}` SELECT * FROM `{$src_terms}`" );
            $wpdb->query( "INSERT IGNORE INTO `{$dst_tt}` SELECT * FROM `{$src_tt}`" );
        }

        // Posts
        $stats['posts'] = max( 0, (int) $wpdb->query(
            "INSERT IGNORE INTO `{$dst_posts}` SELECT * FROM `{$src_posts}` WHERE ID IN ({$ids_str})"
        ) );

        // Postmeta (auto-generate meta_id).
        // Use a NOT EXISTS guard so re-runs skip rows that already exist,
        // without deleting any live WordPress postmeta (WC prices, images, etc.).
        $stats['meta'] = max( 0, (int) $wpdb->query(
            "INSERT INTO `{$dst_meta}` (post_id, meta_key, meta_value)
             SELECT s.post_id, s.meta_key, s.meta_value
             FROM `{$src_meta}` s
             WHERE s.post_id IN ({$ids_str})
               AND NOT EXISTS (
                   SELECT 1 FROM `{$dst_meta}` d
                   WHERE d.post_id = s.post_id
                     AND d.meta_key = s.meta_key
               )"
        ) );

        // Term relationships
        $wpdb->query(
            "INSERT IGNORE INTO `{$dst_tr}` SELECT * FROM `{$src_tr}` WHERE object_id IN ({$ids_str})"
        );

        return [
            'stats'    => $stats,
            'offset'   => $offset + self::BATCH_SIZE,
            'total'    => $total,
            'complete' => count( $ids ) < self::BATCH_SIZE,
        ];
    }

    // ──────────────────────────────────────────────
    //  Phase 3 – Migrate orders (HPOS)
    // ──────────────────────────────────────────────

    /**
     * Copy WooCommerce HPOS orders plus addresses, meta, line items,
     * and line-item meta.  Preserves all original IDs.
     */
    public static function migrate_orders( string $source_prefix, int $offset = 0, bool $dry_run = false ): array {
        global $wpdb;

        $src_orders   = $source_prefix . 'wc_orders';
        $src_meta     = $source_prefix . 'wc_orders_meta';
        $src_addr     = $source_prefix . 'wc_order_addresses';
        $src_op       = $source_prefix . 'wc_order_operational_data';
        $src_items    = $source_prefix . 'woocommerce_order_items';
        $src_itemmeta = $source_prefix . 'woocommerce_order_itemmeta';

        $pfx          = $wpdb->prefix;
        $dst_orders   = $pfx . 'wc_orders';
        $dst_meta     = $pfx . 'wc_orders_meta';
        $dst_addr     = $pfx . 'wc_order_addresses';
        $dst_op       = $pfx . 'wc_order_operational_data';
        $dst_items    = $pfx . 'woocommerce_order_items';
        $dst_itemmeta = $pfx . 'woocommerce_order_itemmeta';

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$src_orders}`" );

        $order_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM `{$src_orders}` ORDER BY id ASC LIMIT %d OFFSET %d",
            self::BATCH_SIZE,
            $offset
        ) );

        $stats = [ 'orders' => 0, 'items' => 0, 'itemmeta' => 0 ];

        if ( empty( $order_ids ) ) {
            return [ 'stats' => $stats, 'offset' => $offset, 'total' => $total, 'complete' => true ];
        }

        $oids = implode( ',', array_map( 'intval', $order_ids ) );

        if ( $dry_run ) {
            // Count orders that don't already exist in the destination
            $existing = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$dst_orders}` WHERE id IN ({$oids})"
            );
            $stats['orders'] = count( $order_ids ) - $existing;

            // Count line items for these orders
            $stats['items'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$src_items}` WHERE order_id IN ({$oids})"
            );

            // Count line item meta
            $item_ids = $wpdb->get_col(
                "SELECT order_item_id FROM `{$src_items}` WHERE order_id IN ({$oids})"
            );
            if ( ! empty( $item_ids ) ) {
                $iids = implode( ',', array_map( 'intval', $item_ids ) );
                $stats['itemmeta'] = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM `{$src_itemmeta}` WHERE order_item_id IN ({$iids})"
                );
            }

            return [
                'stats'    => $stats,
                'offset'   => $offset + self::BATCH_SIZE,
                'total'    => $total,
                'complete' => count( $order_ids ) < self::BATCH_SIZE,
            ];
        }

        // Order headers
        $stats['orders'] = max( 0, (int) $wpdb->query(
            "INSERT IGNORE INTO `{$dst_orders}` SELECT * FROM `{$src_orders}` WHERE id IN ({$oids})"
        ) );

        // Order meta
        $wpdb->query(
            "INSERT IGNORE INTO `{$dst_meta}` SELECT * FROM `{$src_meta}` WHERE order_id IN ({$oids})"
        );

        // Addresses
        $wpdb->query(
            "INSERT IGNORE INTO `{$dst_addr}` SELECT * FROM `{$src_addr}` WHERE order_id IN ({$oids})"
        );

        // Operational data
        $wpdb->query(
            "INSERT IGNORE INTO `{$dst_op}` SELECT * FROM `{$src_op}` WHERE order_id IN ({$oids})"
        );

        // Line items (preserve order_item_id for itemmeta FK)
        $stats['items'] = max( 0, (int) $wpdb->query(
            "INSERT IGNORE INTO `{$dst_items}` SELECT * FROM `{$src_items}` WHERE order_id IN ({$oids})"
        ) );

        // Line item meta
        $item_ids = $wpdb->get_col(
            "SELECT order_item_id FROM `{$src_items}` WHERE order_id IN ({$oids})"
        );

        if ( ! empty( $item_ids ) ) {
            $iids = implode( ',', array_map( 'intval', $item_ids ) );
            $stats['itemmeta'] = max( 0, (int) $wpdb->query(
                "INSERT IGNORE INTO `{$dst_itemmeta}` SELECT * FROM `{$src_itemmeta}` WHERE order_item_id IN ({$iids})"
            ) );
        }

        return [
            'stats'    => $stats,
            'offset'   => $offset + self::BATCH_SIZE,
            'total'    => $total,
            'complete' => count( $order_ids ) < self::BATCH_SIZE,
        ];
    }

    // ──────────────────────────────────────────────
    //  Phase 4 – Create meals_clients in external DB
    // ──────────────────────────────────────────────

    /**
     * Read usermeta for SDNB / Veterans / Extra Mural users and create
     * corresponding records in the external meals_clients table with
     * encrypted sensitive fields and auto-generated initials.
     */
    public static function create_clients( int $offset = 0, bool $dry_run = false ): array {
        // Delegated to the consolidated engine so client creation has a
        // single implementation. This method remains as the Phase 4 entry
        // point for the existing Enzebra import UI; the logic lives in
        // MealsDB_Migration_Consolidated::run_phase_create_clients.
        return MealsDB_Migration_Consolidated::run_phase_create_clients( $offset, $dry_run );
    }

    // ──────────────────────────────────────────────
    //  Phase 5 – Create meals_client_rates
    // ──────────────────────────────────────────────

    /**
     * For each meals_clients record, create a default rate row from the
     * imported basic_cost usermeta value.
     */
    public static function create_rates( int $offset = 0, bool $dry_run = false ): array {
        // Delegated to the consolidated engine (single implementation,
        // includes the OFFSET-drift pagination fix). Phase 5 entry point
        // for the existing Enzebra import UI.
        return MealsDB_Migration_Consolidated::run_phase_create_rates( $offset, $dry_run );
    }

    // ──────────────────────────────────────────────
    //  Cleanup – drop source tables
    // ──────────────────────────────────────────────

    /**
     * Drop all source-prefixed tables that were imported in Phase 0.
     */
    public static function cleanup( string $source_prefix ): array {
        global $wpdb;

        // Safety: never drop tables that belong to the live WordPress installation.
        if ( $source_prefix === $wpdb->prefix ) {
            return [ 'dropped' => 0, 'skipped' => true ];
        }

        $tables  = self::get_source_tables( $source_prefix );
        $dropped = 0;

        foreach ( $tables as $table ) {
            $escaped = '`' . str_replace( '`', '``', $table ) . '`';
            $wpdb->query( "DROP TABLE IF EXISTS {$escaped}" );
            $dropped++;
        }

        return [ 'dropped' => $dropped ];
    }

    // ──────────────────────────────────────────────
    //  Progress helpers
    // ──────────────────────────────────────────────

    public static function get_progress(): array {
        $default = [
            'phase'         => 0,
            'phase_offset'  => 0,
            'source_prefix' => '',
            'file_path'     => '',
            'byte_offset'   => 0,
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
