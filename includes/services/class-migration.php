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
    //  Phase 0 – Load source tables from SQL dump
    // ──────────────────────────────────────────────

    /**
     * Stream the SQL dump and execute CREATE TABLE / INSERT statements
     * for the target tables only.  Processes up to LOAD_CHUNK_BYTES per call.
     *
     * @return array{statements:int, byte_offset:int, file_size:int, complete:bool, percent:float, errors:string[]}
     */
    public static function load_source( string $file_path, string $source_prefix, int $byte_offset = 0 ): array {
        global $wpdb;

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
            $line = fgets( $handle, 32 * 1024 * 1024 ); // 32 MB line buffer
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

            // Execute when statement is complete (ends with ;)
            if ( $in_target && substr( $trimmed, -1 ) === ';' ) {
                // Suppress errors so wpdb doesn't bail
                $wpdb->suppress_errors( true );
                $result = $wpdb->query( $buffer );
                $wpdb->suppress_errors( false );

                if ( $result === false && $wpdb->last_error ) {
                    $errors[] = substr( $wpdb->last_error, 0, 200 );
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

            // Copy usermeta (auto-generate umeta_id)
            $meta_count = (int) $wpdb->query( $wpdb->prepare(
                "INSERT INTO `{$dst_meta}` (user_id, meta_key, meta_value)
                 SELECT user_id, meta_key, meta_value FROM `{$src_meta}` WHERE user_id = %d",
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

        if ( $dry_run ) {
            $stats['posts'] = count( $ids );
            return [
                'stats'    => $stats,
                'offset'   => $offset + self::BATCH_SIZE,
                'total'    => $total,
                'complete' => count( $ids ) < self::BATCH_SIZE,
            ];
        }

        $ids_str = implode( ',', array_map( 'intval', $ids ) );

        // Import terms (once – idempotent via IGNORE)
        if ( $offset === 0 ) {
            $wpdb->query( "INSERT IGNORE INTO `{$dst_terms}` SELECT * FROM `{$src_terms}`" );
            $wpdb->query( "INSERT IGNORE INTO `{$dst_tt}` SELECT * FROM `{$src_tt}`" );
        }

        // Posts
        $stats['posts'] = max( 0, (int) $wpdb->query(
            "INSERT IGNORE INTO `{$dst_posts}` SELECT * FROM `{$src_posts}` WHERE ID IN ({$ids_str})"
        ) );

        // Postmeta (auto-generate meta_id)
        $stats['meta'] = max( 0, (int) $wpdb->query(
            "INSERT INTO `{$dst_meta}` (post_id, meta_key, meta_value)
             SELECT post_id, meta_key, meta_value FROM `{$src_meta}` WHERE post_id IN ({$ids_str})"
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

        if ( $dry_run ) {
            $stats['orders'] = count( $order_ids );
            return [
                'stats'    => $stats,
                'offset'   => $offset + self::BATCH_SIZE,
                'total'    => $total,
                'complete' => count( $order_ids ) < self::BATCH_SIZE,
            ];
        }

        $oids = implode( ',', array_map( 'intval', $order_ids ) );

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
        global $wpdb;

        $conn = MealsDB_DB::get_connection();
        if ( ! MealsDB_DB::is_mysqli( $conn ) ) {
            return [ 'error' => 'Cannot connect to external Meals DB.' ];
        }

        $clients_table = str_replace( '`', '``', MealsDB_DB::get_table_name( MealsDB_Tables::CLIENTS ) );

        // Government / Extra Mural user IDs
        $gov_groups = [ 'sdnb', 'SDNB', 'sdnb rural', 'veterans', 'Extra Mural', 'extra mural' ];
        $placeholders = implode( ',', array_fill( 0, count( $gov_groups ), '%s' ) );

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
             WHERE meta_key = 'customer_group' AND meta_value IN ({$placeholders})",
            $gov_groups
        ) );

        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = 'customer_group' AND meta_value IN ({$placeholders})
             ORDER BY user_id ASC LIMIT %d OFFSET %d",
            array_merge( $gov_groups, [ self::BATCH_SIZE, $offset ] )
        ) );

        $stats = [ 'created' => 0, 'skipped' => 0, 'errors' => 0 ];

        foreach ( $user_ids as $uid ) {
            $uid = (int) $uid;

            // Gather all usermeta
            $meta = [];
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d",
                $uid
            ), ARRAY_A );
            foreach ( $rows as $r ) {
                $meta[ $r['meta_key'] ] = $r['meta_value'];
            }

            $user = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->users} WHERE ID = %d",
                $uid
            ), ARRAY_A );

            if ( ! $user ) {
                $stats['errors']++;
                continue;
            }

            // Check if client already exists in external DB
            $exists = $conn->prepare( sprintf(
                'SELECT client_id FROM `%s` WHERE wp_user_id = ? LIMIT 1',
                $clients_table
            ) );
            if ( MealsDB_DB::is_mysqli_stmt( $exists ) ) {
                $exists->bind_param( 'i', $uid );
                $exists->execute();
                $res = $exists->get_result();
                if ( MealsDB_DB::is_mysqli_result( $res ) && $res->num_rows > 0 ) {
                    $exists->close();
                    $stats['skipped']++;
                    continue;
                }
                $exists->close();
            }

            // Normalize client type
            $group       = strtolower( trim( $meta['customer_group'] ?? '' ) );
            $client_type = self::$type_map[ $group ] ?? 'SDNB';

            // Build client record
            $first = $meta['first_name'] ?? $user['display_name'] ?? '';
            $last  = $meta['last_name']  ?? '';

            // Encrypt sensitive fields
            $individual_id       = null;
            $individual_id_index = null;
            if ( ! empty( $meta['individual_id'] ) ) {
                $individual_id       = MealsDB_Encryption::encrypt( $meta['individual_id'] );
                $individual_id_index = MealsDB_Encryption::create_index( $meta['individual_id'] );
            }

            $requisition_id       = null;
            $requisition_id_index = null;
            if ( ! empty( $meta['requisition_id'] ) ) {
                $requisition_id       = MealsDB_Encryption::encrypt( $meta['requisition_id'] );
                $requisition_id_index = MealsDB_Encryption::create_index( $meta['requisition_id'] );
            }

            $vet_health_card       = null;
            $vet_health_card_index = null;
            if ( $client_type === 'Veteran' && ! empty( $meta['vat_number'] ) ) {
                $vet_health_card       = MealsDB_Encryption::encrypt( $meta['vat_number'] );
                $vet_health_card_index = MealsDB_Encryption::create_index( $meta['vat_number'] );
            }

            // Extra Mural / sdnb rural notes
            $notes = '';
            if ( $group === 'extra mural' ) {
                $notes = 'Migrated from Extra Mural program.';
            }
            $service_name_zone = null;
            if ( $group === 'sdnb rural' ) {
                $service_name_zone = 'rural';
            }

            // Map service_centre_charged → delivery_area_zone
            $zone_map = [ 'Moncton' => 'M', 'Sussex' => 'S', 'veterans' => null ];
            $sc_raw   = $meta['service_centre_charged'] ?? '';
            $zone     = $zone_map[ $sc_raw ] ?? null;

            // Determine open date
            $open_date = null;
            if ( ! empty( $meta['commence_date'] ) && $meta['commence_date'] !== '0' ) {
                $open_date = $meta['commence_date'];
            }

            // Build mains / sides note
            $mains = $meta['mains'] ?? '';
            $sides = $meta['sides'] ?? '';
            if ( $mains !== '' || $sides !== '' ) {
                $meal_note = "Mains: {$mains}, Sides: {$sides}";
                $notes     = $notes ? $notes . ' ' . $meal_note : $meal_note;
            }

            if ( $dry_run ) {
                $stats['created']++;
                continue;
            }

            // Generate delivery initials
            $initials = MealsDB_Initials_Validator::generate( $first, $last, [] );
            if ( $initials === false ) {
                $initials = '';
            }
            $initials_index = $initials !== '' ? MealsDB_Encryption::create_index( $initials ) : null;

            // INSERT into external meals_clients
            $sql = sprintf(
                "INSERT INTO `%s` (
                    wp_user_id, client_type, first_name, last_name, client_email, active,
                    client_phone_1, client_phone_2, payment_method,
                    open_date, individual_id, individual_id_index,
                    service_id, requisition_id, requisition_id_index,
                    requisition_period, units, client_contribution,
                    vet_health_card, vet_health_card_index,
                    service_center_charged, delivery_area_zone, service_name_zone,
                    delivery_frequency, ordering_frequency, freezer_capacity,
                    delivery_fee, diet_concerns, customer_comments,
                    service_commence_date, expected_termination_date,
                    notes_to_service_provider,
                    delivery_initials, delivery_initials_index,
                    use_legacy_billing
                ) VALUES (
                    ?, ?, ?, ?, ?, 1,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?,
                    ?, ?,
                    1
                )",
                $clients_table
            );

            $stmt = $conn->prepare( $sql );
            if ( ! MealsDB_DB::is_mysqli_stmt( $stmt ) ) {
                $stats['errors']++;
                continue;
            }

            $email     = $user['user_email'] ?? null;
            $phone1    = ! empty( $meta['billing_phone'] ) ? $meta['billing_phone'] : null;
            $phone2    = null;
            $payment   = $meta['payment_method']       ?? null;
            $service_id = $meta['service_id']           ?? null;
            $req_period = $meta['rate']                 ?? null;
            $units      = ! empty( $meta['requisition_units'] ) ? (int) $meta['requisition_units'] : null;
            $contrib    = ! empty( $meta['contribution'] ) ? (float) $meta['contribution'] : null;
            $del_freq   = ! empty( $meta['delivery_frequency'] ) ? (int) $meta['delivery_frequency'] : null;
            $ord_freq   = ! empty( $meta['ordering_frequency'] ) ? (int) $meta['ordering_frequency'] : null;
            $freeze_cap = $meta['freeze_capacity']     ?? null;
            $del_fee    = ! empty( $meta['delivery_fee'] ) ? (float) $meta['delivery_fee'] : null;
            $diet       = ( ! empty( $meta['dietary_needs'] ) && $meta['dietary_needs'] !== '0' ) ? $meta['dietary_needs'] : null;
            $comments   = ! empty( $meta['customer_comments'] ) ? $meta['customer_comments'] : null;
            $commence   = $open_date;
            $term_date  = ! empty( $meta['service_termination_date'] ) && $meta['service_termination_date'] !== '0'
                ? $meta['service_termination_date'] : null;
            $notes_final = $notes !== '' ? $notes : null;

            $stmt->bind_param(
                'issssssssssssssidsssssiisdssssss',
                $uid, $client_type, $first, $last, $email,
                $phone1, $phone2, $payment,
                $open_date, $individual_id, $individual_id_index,
                $service_id, $requisition_id, $requisition_id_index,
                $req_period, $units, $contrib,
                $vet_health_card, $vet_health_card_index,
                $sc_raw, $zone, $service_name_zone,
                $del_freq, $ord_freq, $freeze_cap,
                $del_fee, $diet, $comments,
                $commence, $term_date,
                $notes_final,
                $initials, $initials_index
            );

            if ( $stmt->execute() ) {
                $stats['created']++;
            } else {
                $stats['errors']++;
            }
            $stmt->close();
        }

        return [
            'stats'    => $stats,
            'offset'   => $offset + self::BATCH_SIZE,
            'total'    => $total,
            'complete' => count( $user_ids ) < self::BATCH_SIZE,
        ];
    }

    // ──────────────────────────────────────────────
    //  Phase 5 – Create meals_client_rates
    // ──────────────────────────────────────────────

    /**
     * For each meals_clients record, create a default rate row from the
     * imported basic_cost usermeta value.
     */
    public static function create_rates( int $offset = 0, bool $dry_run = false ): array {
        $conn = MealsDB_DB::get_connection();
        if ( ! MealsDB_DB::is_mysqli( $conn ) ) {
            return [ 'error' => 'Cannot connect to external Meals DB.' ];
        }

        $clients_table = str_replace( '`', '``', MealsDB_DB::get_table_name( MealsDB_Tables::CLIENTS ) );
        $rates_table   = str_replace( '`', '``', MealsDB_DB::get_table_name( MealsDB_Tables::CLIENT_RATES ) );

        // Get clients that don't have a rate yet
        $sql = sprintf(
            "SELECT c.client_id, c.wp_user_id
             FROM `%s` c
             LEFT JOIN `%s` r ON r.client_id = c.client_id
             WHERE r.rate_id IS NULL
             ORDER BY c.client_id ASC
             LIMIT %d OFFSET %d",
            $clients_table,
            $rates_table,
            self::BATCH_SIZE,
            $offset
        );

        $result = $conn->query( $sql );
        $rows   = [];
        if ( MealsDB_DB::is_mysqli_result( $result ) ) {
            while ( $row = $result->fetch_assoc() ) {
                $rows[] = $row;
            }
        }

        // Count total clients without rates
        $count_sql = sprintf(
            "SELECT COUNT(*) AS cnt FROM `%s` c LEFT JOIN `%s` r ON r.client_id = c.client_id WHERE r.rate_id IS NULL",
            $clients_table,
            $rates_table
        );
        $count_result = $conn->query( $count_sql );
        $total = 0;
        if ( MealsDB_DB::is_mysqli_result( $count_result ) ) {
            $cr    = $count_result->fetch_assoc();
            $total = (int) ( $cr['cnt'] ?? 0 );
        }

        $stats = [ 'created' => 0, 'skipped' => 0, 'errors' => 0 ];

        global $wpdb;

        foreach ( $rows as $row ) {
            $client_id  = (int) $row['client_id'];
            $wp_user_id = (int) $row['wp_user_id'];

            // Get basic_cost from WP usermeta
            $basic_cost = $wpdb->get_var( $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'basic_cost' LIMIT 1",
                $wp_user_id
            ) );

            $rate = ! empty( $basic_cost ) ? (float) $basic_cost : 0.00;

            if ( $rate <= 0 ) {
                $stats['skipped']++;
                continue;
            }

            if ( $dry_run ) {
                $stats['created']++;
                continue;
            }

            $insert_sql = sprintf(
                "INSERT INTO `%s` (client_id, label, rate, is_default, effective_date)
                 VALUES (?, 'Default Rate', ?, 1, CURDATE())",
                $rates_table
            );

            $stmt = $conn->prepare( $insert_sql );
            if ( ! MealsDB_DB::is_mysqli_stmt( $stmt ) ) {
                $stats['errors']++;
                continue;
            }

            $stmt->bind_param( 'id', $client_id, $rate );
            if ( $stmt->execute() ) {
                // Update client's default_rate_id
                $rate_id = (int) $stmt->insert_id;
                $update  = $conn->prepare( sprintf(
                    "UPDATE `%s` SET default_rate_id = ? WHERE client_id = ?",
                    $clients_table
                ) );
                if ( MealsDB_DB::is_mysqli_stmt( $update ) ) {
                    $update->bind_param( 'ii', $rate_id, $client_id );
                    $update->execute();
                    $update->close();
                }

                $stats['created']++;
            } else {
                $stats['errors']++;
            }
            $stmt->close();
        }

        return [
            'stats'    => $stats,
            'offset'   => $offset + self::BATCH_SIZE,
            'total'    => $total,
            'complete' => count( $rows ) < self::BATCH_SIZE,
        ];
    }

    // ──────────────────────────────────────────────
    //  Cleanup – drop source tables
    // ──────────────────────────────────────────────

    /**
     * Drop all source-prefixed tables that were imported in Phase 0.
     */
    public static function cleanup( string $source_prefix ): array {
        global $wpdb;

        $tables  = self::get_source_tables( $source_prefix );
        $dropped = 0;

        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
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
