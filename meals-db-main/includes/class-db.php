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

        $host = defined('MEALS_DB_HOST') ? MEALS_DB_HOST : null;
        $user = defined('MEALS_DB_USER') ? MEALS_DB_USER : null;
        $pass = defined('MEALS_DB_PASS') ? MEALS_DB_PASS : null;
        $name = defined('MEALS_DB_NAME') ? MEALS_DB_NAME : null;

        if ($host === null || $user === null || $name === null) {
            error_log('[MealsDB] Database configuration constants are missing.');
            return null;
        }

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
