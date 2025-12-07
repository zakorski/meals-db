<?php
/**
 * Lightweight .env loader for Meals DB.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

if ( ! class_exists( 'MealsDB_Env' ) ) {

    class MealsDB_Env {

        /**
         * Load a .env file into the PHP environment.
         *
         * - Lines starting with # or ; are treated as comments.
         * - KEY=VALUE pairs are parsed; surrounding double quotes are stripped.
         * - Existing environment values are NOT overwritten.
         *
         * @param string $file_path Absolute path to the .env file.
         */
        public static function load( string $file_path ): void {
            if ( ! is_readable( $file_path ) ) {
                return;
            }

            $lines = @file( $file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
            if ( ! is_array( $lines ) ) {
                return;
            }

            foreach ( $lines as $line ) {
                $line = trim( $line );

                // Skip empty lines and comments.
                if ( $line === '' || $line[0] === '#' || $line[0] === ';' ) {
                    continue;
                }

                // Must be KEY=VALUE form.
                if ( strpos( $line, '=' ) === false ) {
                    continue;
                }

                list( $key, $value ) = explode( '=', $line, 2 );
                $key   = trim( $key );
                $value = trim( $value );

                if ( $key === '' ) {
                    continue;
                }

                // Strip surrounding double quotes if present.
                if ( strlen( $value ) >= 2 && $value[0] === '"' && substr( $value, -1 ) === '"' ) {
                    $value = substr( $value, 1, -1 );
                }

                // Do not overwrite existing values.
                if ( getenv( $key ) === false ) {
                    @putenv( $key . '=' . $value );
                }

                if ( ! array_key_exists( $key, $_ENV ) ) {
                    $_ENV[ $key ] = $value;
                }
            }
        }
    }
}
