<?php
/**
 * Handles mysqli connection to the external Meals DB database.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

if ( ! class_exists( 'MealsDB_DB' ) ) {

    class MealsDB_DB {

        /**
         * @var mysqli|null
         */
        private static $connection = null;

        /**
         * @var string|null
         */
        private static $table_prefix = null;

        /**
         * Cache of resolved table names keyed by base table.
         *
         * @var array<string, string>
         */
        private static $table_name_cache = array();

        /**
         * Get the existing DB connection, or establish one if it doesn't exist.
         *
         * @return mysqli|null
         */
        public static function get_connection() {
            if ( self::is_mysqli( self::$connection ) ) {
                return self::$connection;
            }

            if ( ! self::has_mysqli() ) {
                error_log( '[MealsDB DB] mysqli extension is missing; Meals DB features are disabled.' );
                return null;
            }

            if ( ! class_exists( 'MealsDB_Config' ) || ! MealsDB_Config::is_db_configured() ) {
                error_log( '[MealsDB DB] External DB is not configured; see MealsDB_Config documentation.' );
                return null;
            }

            $host = MealsDB_Config::db_host();
            $user = MealsDB_Config::db_user();
            $pass = MealsDB_Config::db_pass();
            $name = MealsDB_Config::db_name();

            $has_missing = (
                $host === null || $host === '' ||
                $user === null || $user === '' ||
                $pass === null || $pass === '' ||
                $name === null || $name === ''
            );

            if ( $has_missing ) {
                error_log( '[MealsDB DB] External DB credentials are incomplete. Check environment/constant configuration.' );
                return null;
            }

            $previous_report_mode = null;
            if ( function_exists( 'mysqli_report' ) ) {
                $previous_report_mode = mysqli_report( MYSQLI_REPORT_OFF );
            }

            try {
                self::$connection = @new mysqli( $host, $user, $pass, $name );
            } catch ( Throwable $e ) {
                error_log( '[MealsDB DB] Database connection exception: ' . $e->getMessage() );
                self::$connection = null;
            } finally {
                if ( function_exists( 'mysqli_report' ) && $previous_report_mode !== null ) {
                    mysqli_report( $previous_report_mode );
                }
            }

            if ( self::is_mysqli( self::$connection ) && self::$connection->connect_error ) {
                error_log( '[MealsDB DB] Database connection failed: ' . self::$connection->connect_error );
                self::$connection = null;
            } elseif ( self::is_mysqli( self::$connection ) ) {
                self::$connection->set_charset( 'utf8mb4' );
            }

            return self::$connection;
        }

        /**
         * Close the DB connection manually if needed.
         */
        public static function close_connection(): void {
            if ( self::is_mysqli( self::$connection ) ) {
                self::$connection->close();
                self::$connection = null;
            }
        }

        /**
         * Retrieve the table name, resolving prefixes and legacy names.
         *
         * This is intentionally legacy-aware:
         * - For 'mealsdb_transactions', we fall back to 'meals_transactions' variants.
         * - For 'mealsdb_clients', we fall back to 'meals_clients' variants.
         *
         * @param string $table Base table identifier (e.g. 'mealsdb_transactions').
         *
         * @return string Resolved table name to use in queries.
         */
        public static function get_table_name( string $table ): string {
            if ( isset( self::$table_name_cache[ $table ] ) ) {
                return self::$table_name_cache[ $table ];
            }

            $prefix = self::get_table_prefix();
            $candidates = array();

            // Primary candidates: prefix + base, then base.
            if ( $prefix !== '' && strpos( $table, $prefix ) !== 0 ) {
                $candidates[] = $prefix . $table;
            }
            $candidates[] = $table;

            // Legacy compatibility: special handling for transactions and clients.
            if ( $table === 'mealsdb_transactions' ) {
                $legacy_base = 'meals_transactions';
                if ( $prefix !== '' ) {
                    $candidates[] = $prefix . $legacy_base;
                }
                $candidates[] = $legacy_base;
            } elseif ( $table === 'mealsdb_clients' ) {
                $legacy_base = 'meals_clients';
                if ( $prefix !== '' ) {
                    $candidates[] = $prefix . $legacy_base;
                }
                $candidates[] = $legacy_base;
            }

            // De-duplicate while preserving order.
            $candidates = array_values( array_unique( $candidates ) );

            $resolved_table = $candidates[0];
            $connection     = self::get_connection();

            if ( self::is_mysqli( $connection ) ) {
                foreach ( $candidates as $candidate ) {
                    if ( self::table_exists( $connection, $candidate ) ) {
                        $resolved_table = $candidate;
                        break;
                    }
                }
            }

            self::$table_name_cache[ $table ] = $resolved_table;

            return $resolved_table;
        }

        /**
         * Determine the Meals DB table prefix for the EXTERNAL database.
         *
         * IMPORTANT:
         * - We DO NOT fall back to $wpdb->prefix here.
         * - If no explicit Meals DB prefix is configured, we use '' (no prefix).
         *
         * @return string
         */
        private static function get_table_prefix(): string {
            if ( self::$table_prefix !== null ) {
                return self::$table_prefix;
            }

            $prefix_override = '';
            if ( class_exists( 'MealsDB_Config' ) ) {
                $prefix_override = (string) MealsDB_Config::table_prefix();
            }

            self::$table_prefix = $prefix_override !== null ? (string) $prefix_override : '';

            return self::$table_prefix;
        }

        /**
         * Determine if the mysqli extension is available.
         *
         * @return bool
         */
        public static function has_mysqli(): bool {
            return class_exists( 'mysqli' );
        }

        /**
         * Safely verify a mysqli connection instance.
         *
         * @param mixed $value Potential mysqli connection.
         * @return bool
         */
        public static function is_mysqli( $value ): bool {
            return self::has_mysqli() && $value instanceof mysqli;
        }

        /**
         * Safely verify a mysqli statement instance.
         *
         * @param mixed $value Potential mysqli_stmt instance.
         * @return bool
         */
        public static function is_mysqli_stmt( $value ): bool {
            return class_exists( 'mysqli_stmt' ) && $value instanceof mysqli_stmt;
        }

        /**
         * Safely verify a mysqli result instance.
         *
         * @param mixed $value Potential mysqli_result instance.
         * @return bool
         */
        public static function is_mysqli_result( $value ): bool {
            return class_exists( 'mysqli_result' ) && $value instanceof mysqli_result;
        }

        /**
         * Retrieve all clients ordered alphabetically by last name.
         *
         * NOTE: Kept signature compatible with existing views:
         *       MealsDB_DB::get_all_clients( $conn )
         *
         * @param mysqli $conn Active database connection.
         *
         * @return mysqli_result|false
         */
        public static function get_all_clients( $conn ) {
            if ( ! self::is_mysqli( $conn ) ) {
                return false;
            }

            // Use the canonical 'mealsdb_clients' base; get_table_name will handle
            // prefixing and legacy fallbacks (e.g., meals_clients).
            $clients_table = self::get_table_name( 'mealsdb_clients' );
            $clients_table = str_replace( '`', '``', $clients_table );

            $sql = "SELECT client_id, first_name, last_name, client_type FROM `{$clients_table}` ORDER BY last_name ASC";

            return $conn->query( $sql );
        }

        /**
         * Determine if a given table exists in the active database.
         *
         * @param mysqli $connection Active DB connection.
         * @param string $table_name Table name to check.
         *
         * @return bool
         */
        private static function table_exists( mysqli $connection, string $table_name ): bool {
            if ( ! method_exists( $connection, 'real_escape_string' ) || ! method_exists( $connection, 'query' ) ) {
                return false;
            }

            $escaped_table = $connection->real_escape_string( $table_name );
            $sql           = sprintf(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '%s' LIMIT 1",
                $escaped_table
            );

            $result = $connection->query( $sql );
            if ( self::is_mysqli_result( $result ) ) {
                $exists = $result->num_rows > 0;
                $result->free();

                return $exists;
            }

            return false;
        }
    }
}
