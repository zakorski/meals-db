<?php
/**
 * Handles mysqli connection to the Meals DB external database.
 * 
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_DB {

    /**
     * @var mysqli|null
     */
    private static $connection = null;

    /**
     * Get the existing DB connection, or establish one if it doesn't exist.
     *
     * @return mysqli|null
     */
    public static function get_connection() {
        if (self::$connection instanceof mysqli) {
            return self::$connection;
        }

        $host = getenv('PLUGIN_DB_HOST');
        $user = getenv('PLUGIN_DB_USER');
        $pass = getenv('PLUGIN_DB_PASS');
        $name = getenv('PLUGIN_DB_NAME');

        $previousReportMode = null;
        if (function_exists('mysqli_report')) {
            $previousReportMode = mysqli_report(MYSQLI_REPORT_OFF);
        }

        try {
            self::$connection = @new mysqli($host, $user, $pass, $name);
        } catch (Throwable $e) {
            error_log('[MealsDB] Database connection exception: ' . $e->getMessage());
            self::$connection = null;
        } finally {
            if (function_exists('mysqli_report') && $previousReportMode !== null) {
                mysqli_report($previousReportMode);
            }
        }

        if (self::$connection instanceof mysqli && self::$connection->connect_error) {
            error_log('[MealsDB] Database connection failed: ' . self::$connection->connect_error);
            self::$connection = null;
        } elseif (self::$connection instanceof mysqli) {
            self::$connection->set_charset('utf8mb4');
        }

        return self::$connection;
    }

    /**
     * Close the DB connection manually if needed.
     */
    public static function close_connection() {
        if (self::$connection instanceof mysqli) {
            self::$connection->close();
            self::$connection = null;
        }
    }
}
