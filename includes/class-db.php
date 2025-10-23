<?php
/**
 * Handles mysqli connection to the Meals DB external database.
 * 
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Created as a work for hire for Meals and More.
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

        self::$connection = new mysqli($host, $user, $pass, $name);

        if (self::$connection->connect_error) {
            error_log('[MealsDB] Database connection failed: ' . self::$connection->connect_error);
            self::$connection = null;
        } else {
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
