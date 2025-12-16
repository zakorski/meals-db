<?php
/**
 * Handles mysqli connection to the Meals DB external database.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_DB
{
    /**
     * @var WP_Error|null
     */
    private static $last_error = null;

    /**
     * @var mysqli|null
     */
    private static $connection = null;

    /**
     * Cache of resolved table names keyed by base table.
     *
     * @var array<string, string>
     */
    private static $table_name_cache = [];

    /**
     * Retrieve or establish a mysqli connection using MealsDB_Config credentials.
     *
     * @return mysqli|WP_Error
     */
    public static function connection()
    {
        if (self::is_mysqli(self::$connection)) {
            return self::$connection;
        }

        if (!self::has_mysqli()) {
            return self::fail_connection('mysqli extension is missing; Meals DB features are disabled.');
        }

        $config = self::config();
        if ($config === null) {
            return self::fail_connection('MealsDB_Config class not found; cannot load DB credentials.');
        }

        $host = $config->db_host();
        $user = $config->db_user();
        $pass = $config->db_pass();
        $name = $config->db_name();

        $has_missing_credentials = $host === '' || $user === '' || $pass === '' || $name === '';
        if ($has_missing_credentials) {
            return self::fail_connection('External DB credentials are missing. Ensure .env contains MEALS_DB_HOST, MEALS_DB_USER, MEALS_DB_PASS, MEALS_DB_NAME.');
        }

        $previous_report_mode = null;
        if (function_exists('mysqli_report')) {
            $previous_report_mode = mysqli_report(MYSQLI_REPORT_OFF);
        }

        try {
            self::$connection = @new mysqli($host, $user, $pass, $name);
        } catch (Throwable $e) {
            self::$connection = self::fail_connection('Database connection exception: ' . $e->getMessage());
        } finally {
            if (function_exists('mysqli_report') && $previous_report_mode !== null) {
                mysqli_report($previous_report_mode);
            }
        }

        if (!self::is_mysqli(self::$connection)) {
            return self::fail_connection('Failed to initialize database connection instance.');
        }

        if (self::$connection->connect_error) {
            $error = self::$connection->connect_error;
            self::$connection = null;

            return self::fail_connection('Database connection failed: ' . $error);
        }

        self::$connection->set_charset('utf8mb4');

        return self::$connection;
    }

    /**
     * BACKWARDS-COMPAT: old name used everywhere.
     *
     * @return mysqli|WP_Error
     */
    public static function get_connection()
    {
        return self::connection();
    }

    /**
     * Close the DB connection manually if needed.
     */
    public static function close_connection()
    {
        if (self::is_mysqli(self::$connection)) {
            self::$connection->close();
            self::$connection = null;
        }
    }

    /**
     * NEW core table-name resolver.
     *
     * @param string $table
     * @return string
     */
    public static function table(string $table): string
    {
        if (isset(self::$table_name_cache[$table])) {
            return self::$table_name_cache[$table];
        }

        $prefix = '';
        $config = self::config();
        if ($config !== null) {
            $prefix = $config->table_prefix() ?: '';
        }

        $prefixed_table = $prefix !== '' && strpos($table, $prefix) !== 0 ? $prefix . $table : $table;
        $resolved_table = $prefixed_table;

        $connection = self::connection();
        if (self::is_mysqli($connection)) {
            if ($prefixed_table !== $table && self::table_exists($connection, $prefixed_table)) {
                $resolved_table = $prefixed_table;
            } elseif (self::table_exists($connection, $table)) {
                $resolved_table = $table;
            }
        }

        self::$table_name_cache[$table] = $resolved_table;

        return $resolved_table;
    }

    /**
     * BACKWARDS-COMPAT: original method name used across the plugin.
     *
     * @param string $table
     * @return string
     */
    public static function get_table_name(string $table): string
    {
        return self::table($table);
    }

    /**
     * Surface the last recorded connection error message.
     *
     * @return string|null
     */
    public static function last_error_message(): ?string
    {
        if (self::$last_error instanceof WP_Error) {
            return self::$last_error->get_error_message();
        }

        return null;
    }

    /**
     * Determine if the mysqli extension is available.
     */
    public static function has_mysqli(): bool
    {
        return class_exists('mysqli');
    }

    /**
     * Safely verify a mysqli connection instance.
     *
     * @param mixed $value Potential mysqli connection.
     */
    public static function is_mysqli($value): bool
    {
        return self::has_mysqli() && $value instanceof mysqli;
    }

    /**
     * Safely verify a mysqli statement instance.
     *
     * @param mixed $value Potential mysqli_stmt instance.
     */
    public static function is_mysqli_stmt($value): bool
    {
        return class_exists('mysqli_stmt') && $value instanceof mysqli_stmt;
    }

    /**
     * Safely verify a mysqli result instance.
     *
     * @param mixed $value Potential mysqli_result instance.
     */
    public static function is_mysqli_result($value): bool
    {
        return class_exists('mysqli_result') && $value instanceof mysqli_result;
    }

    /**
     * Determine if a given table exists in the active database.
     */
    private static function table_exists(mysqli $connection, string $table_name): bool
    {
        if (!method_exists($connection, 'real_escape_string') || !method_exists($connection, 'query')) {
            return false;
        }

        $escaped_table = $connection->real_escape_string($table_name);
        $sql = sprintf(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '%s' LIMIT 1",
            $escaped_table
        );

        $result = $connection->query($sql);
        if (self::is_mysqli_result($result)) {
            $exists = $result->num_rows > 0;
            $result->free();

            return $exists;
        }

        return false;
    }

    /**
     * Retrieve all clients ordered alphabetically by last name.
     *
     * BACKWARDS-COMPAT: keep $conn parameter optional.
     *
     * @param mysqli|null $conn
     * @return mysqli_result|null
     */
    public static function get_all_clients($conn = null)
    {
        if (!self::is_mysqli($conn)) {
            $conn = self::connection();
        }

        if (!self::is_mysqli($conn)) {
            return null;
        }

        $clients_table = self::get_table_name(MealsDB_Tables::CLIENTS);
        $clients_table = str_replace('`', '``', $clients_table);

        $sql = "SELECT client_id, first_name, last_name, client_type FROM `{$clients_table}` ORDER BY last_name ASC";

        try {
            $result = $conn->query($sql);
        } catch (Throwable $e) {
            error_log('[MealsDB DB] Failed to fetch clients: ' . $e->getMessage());
            return null;
        }

        return self::is_mysqli_result($result) ? $result : null;
    }

    /**
     * Safely retrieve the MealsDB_Config singleton if available.
     *
     * @return MealsDB_Config|null
     */
    private static function config()
    {
        if (!class_exists('MealsDB_Config')) {
            return null;
        }

        try {
            return MealsDB_Config::instance();
        } catch (Throwable $e) {
            error_log('[MealsDB DB] Failed to load MealsDB_Config: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Record and surface a fatal connection failure.
     *
     * @return WP_Error
     */
    private static function fail_connection(string $reason)
    {
        $message = __('MealsDB external database connection failed. MealsDB data operations are unavailable.', 'meals-db');
        $full_message = $message . ' ' . $reason;

        self::$connection = null;
        self::$last_error = new WP_Error('mealsdb_connection_failed', $message, ['reason' => $reason]);

        if (function_exists('is_admin') && is_admin()) {
            add_action('admin_notices', [self::class, 'render_admin_notice']);
        }

        error_log('[MealsDB DB] ' . $reason);

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            wp_send_json_error([
                'message' => $message,
            ], 500);
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            wp_send_json_error([
                'message' => $message,
            ], 500);
        }

        if (function_exists('wp_die')) {
            wp_die($full_message, __('MealsDB Database Error', 'meals-db'));
        }

        if (class_exists('RuntimeException')) {
            throw new RuntimeException($full_message);
        }

        return self::$last_error;
    }

    /**
     * Render an admin notice when the connection is unavailable.
     */
    public static function render_admin_notice(): void
    {
        if (!self::$last_error instanceof WP_Error) {
            return;
        }

        $message = self::$last_error->get_error_message();
        $details = self::$last_error->get_error_data('mealsdb_connection_failed');

        if (is_array($details) && isset($details['reason']) && is_string($details['reason'])) {
            $message .= ' ' . esc_html($details['reason']);
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html($message)
        );
    }
}
