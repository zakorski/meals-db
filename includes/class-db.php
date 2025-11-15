<?php
/**
 * Handles mysqli connection to the Meals DB external database.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

if (!class_exists('MealsDB_Config')) {
    require_once __DIR__ . '/class-config.php';
}

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
     * Get the existing DB connection, or establish one if it doesn't exist.
     *
     * @return mysqli|null
     */
    public static function get_connection() {
        if (self::$connection instanceof mysqli) {
            return self::$connection;
        }

        $config = new MealsDB_Config();

        $host = $config->get_db_host();
        $user = $config->get_db_user();
        $pass = $config->get_db_password();
        $name = $config->get_db_name();

        if ($host === null || $host === '' || $user === null || $user === '' || $pass === null || $pass === '' || $name === null || $name === '') {
            error_log('[MealsDB] External database credentials are missing. Set MEALSDB_* environment variables or define the MEALS_DB_* constants.');
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

    /**
     * Retrieve the table name prefixed with the active WordPress prefix.
     */
    public static function get_table_name(string $table): string {
        $prefix = self::get_table_prefix();

        if ($prefix !== '' && strpos($table, $prefix) === 0) {
            return $table;
        }

        return $prefix . $table;
    }

    /**
     * Determine the WordPress table prefix.
     */
    private static function get_table_prefix(): string {
        if (self::$table_prefix !== null) {
            return self::$table_prefix;
        }

        $prefix = '';

        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']) && property_exists($GLOBALS['wpdb'], 'prefix')) {
            $prefix_value = $GLOBALS['wpdb']->prefix;
            if (is_string($prefix_value)) {
                $prefix = $prefix_value;
            }
        }

        self::$table_prefix = $prefix;

        return self::$table_prefix;
    }
}
