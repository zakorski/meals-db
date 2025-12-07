<?php
/**
 * Central configuration utility for the external Meals DB connection.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

if ( ! class_exists( 'MealsDB_Config' ) ) {

    class MealsDB_Config {

        /**
         * Whether configuration has been loaded.
         *
         * @var bool
         */
        private static $loaded = false;

        /**
         * Cached configuration values.
         *
         * @var array<string, mixed>
         */
        private static $values = array(
            'db_host'       => null,
            'db_name'       => null,
            'db_user'       => null,
            'db_pass'       => null,
            'table_prefix'  => '',
        );

        /**
         * Prevent instantiation. Configuration is fully static.
         */
        private function __construct() {
            // Intentionally empty.
        }

        /**
         * Ensure configuration has been loaded into self::$values.
         */
        private static function bootstrap(): void {
            if ( self::$loaded ) {
                return;
            }

            // 1) Load .env file from plugin root if present.
            if ( defined( 'MEALS_DB_PLUGIN_DIR' ) ) {
                $env_file = rtrim( MEALS_DB_PLUGIN_DIR, '/\\' ) . '/.env';
                if ( class_exists( 'MealsDB_Env' ) ) {
                    MealsDB_Env::load( $env_file );
                }
            }

            // 2) Populate configuration with precedence:
            //    - Environment variables
            //    - PHP constants
            //    - Fallback default

            self::$values['db_host'] = self::resolve(
                array( 'PLUGIN_DB_HOST', 'MEALS_DB_HOST', 'MEALSDB_DB_HOST' ),
                array( 'MEALS_DB_HOST', 'MEALSDB_DB_HOST' ),
                null
            );

            self::$values['db_name'] = self::resolve(
                array( 'PLUGIN_DB_NAME', 'MEALS_DB_NAME', 'MEALSDB_DB_NAME' ),
                array( 'MEALS_DB_NAME', 'MEALSDB_DB_NAME' ),
                null
            );

            self::$values['db_user'] = self::resolve(
                array( 'PLUGIN_DB_USER', 'MEALS_DB_USER', 'MEALSDB_DB_USER' ),
                array( 'MEALS_DB_USER', 'MEALSDB_DB_USER' ),
                null
            );

            self::$values['db_pass'] = self::resolve(
                array( 'PLUGIN_DB_PASS', 'MEALS_DB_PASS', 'MEALSDB_DB_PASS' ),
                array( 'MEALS_DB_PASS', 'MEALSDB_DB_PASS' ),
                null
            );

            self::$values['table_prefix'] = (string) self::resolve(
                array( 'PLUGIN_DB_TABLE_PREFIX' ),
                array( 'MEALSDB_TABLE_PREFIX', 'MEALS_DB_TABLE_PREFIX' ),
                ''
            );

            self::$loaded = true;
        }

        /**
         * Resolve a configuration value from env vars and constants.
         *
         * @param string[]    $env_keys   Environment variable names to check (first non-empty wins).
         * @param string[]    $const_keys Constant names to check (first non-empty wins).
         * @param string|null $default    Default value if nothing found.
         *
         * @return string|null
         */
        private static function resolve( array $env_keys, array $const_keys, $default = null ) {
            // Environment variables first.
            foreach ( $env_keys as $key ) {
                $value = getenv( $key );
                if ( $value !== false && $value !== '' ) {
                    return (string) $value;
                }
                if ( isset( $_ENV[ $key ] ) && $_ENV[ $key ] !== '' ) {
                    return (string) $_ENV[ $key ];
                }
            }

            // Then constants.
            foreach ( $const_keys as $key ) {
                if ( defined( $key ) ) {
                    $value = constant( $key );
                    if ( is_string( $value ) && $value !== '' ) {
                        return $value;
                    }
                }
            }

            return $default;
        }

        /**
         * Database host for external Meals DB.
         *
         * @return string|null
         */
        public static function db_host(): ?string {
            self::bootstrap();

            return self::$values['db_host'] !== '' ? (string) self::$values['db_host'] : null;
        }

        /**
         * Database name for external Meals DB.
         *
         * @return string|null
         */
        public static function db_name(): ?string {
            self::bootstrap();

            return self::$values['db_name'] !== '' ? (string) self::$values['db_name'] : null;
        }

        /**
         * Database user for external Meals DB.
         *
         * @return string|null
         */
        public static function db_user(): ?string {
            self::bootstrap();

            return self::$values['db_user'] !== '' ? (string) self::$values['db_user'] : null;
        }

        /**
         * Database password for external Meals DB.
         *
         * @return string|null
         */
        public static function db_pass(): ?string {
            self::bootstrap();

            return self::$values['db_pass'] !== '' ? (string) self::$values['db_pass'] : null;
        }

        /**
         * Meals DB table prefix to use in the EXTERNAL database.
         *
         * @return string
         */
        public static function table_prefix(): string {
            self::bootstrap();

            return (string) self::$values['table_prefix'];
        }

        /**
         * Check whether external DB configuration appears complete.
         *
         * @return bool
         */
        public static function is_db_configured(): bool {
            self::bootstrap();

            return (
                self::$values['db_host'] !== null && self::$values['db_host'] !== '' &&
                self::$values['db_name'] !== null && self::$values['db_name'] !== '' &&
                self::$values['db_user'] !== null && self::$values['db_user'] !== '' &&
                self::$values['db_pass'] !== null && self::$values['db_pass'] !== ''
            );
        }
    }
}
