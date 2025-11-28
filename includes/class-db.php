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
     * @var string|null
     */
    private static $table_prefix = null;

    /**
     * Get the existing DB connection, or establish one if it doesn't exist.
     *
     * @return mysqli|null
     */
    public static function get_connection() {
        if (self::is_mysqli(self::$connection)) {
            return self::$connection;
        }

        if (!self::has_mysqli()) {
            error_log('[MealsDB DB] mysqli extension is missing; Meals DB features are disabled.');
            return null;
        }

        $config = new MealsDB_Config();

        $host = $config->get_db_host();
        $user = $config->get_db_user();
        $pass = $config->get_db_password();
        $name = $config->get_db_name();

        $has_missing_credentials = $host === null || $host === ''
            || $user === null || $user === ''
            || $pass === null || $pass === ''
            || $name === null || $name === '';

        if ($has_missing_credentials) {
            error_log('[MealsDB DB] External DB credentials are missing. See MealsDB_Config documentation.');
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

        if (self::is_mysqli(self::$connection) && self::$connection->connect_error) {
            error_log('[MealsDB] Database connection failed: ' . self::$connection->connect_error);
            self::$connection = null;
        } elseif (self::is_mysqli(self::$connection)) {
            self::$connection->set_charset('utf8mb4');
        }

        return self::$connection;
    }

    /**
     * Close the DB connection manually if needed.
     */
    public static function close_connection() {
        if (self::is_mysqli(self::$connection)) {
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

    /**
     * Determine if the mysqli extension is available.
     */
    public static function has_mysqli(): bool {
        return class_exists('mysqli');
    }

    /**
     * Safely verify a mysqli connection instance.
     *
     * @param mixed $value Potential mysqli connection.
     */
    public static function is_mysqli($value): bool {
        return self::has_mysqli() && $value instanceof mysqli;
    }

    /**
     * Safely verify a mysqli statement instance.
     *
     * @param mixed $value Potential mysqli_stmt instance.
     */
    public static function is_mysqli_stmt($value): bool {
        return class_exists('mysqli_stmt') && $value instanceof mysqli_stmt;
    }

    /**
     * Safely verify a mysqli result instance.
     *
     * @param mixed $value Potential mysqli_result instance.
     */
    public static function is_mysqli_result($value): bool {
        return class_exists('mysqli_result') && $value instanceof mysqli_result;
    }
}
