<?php
/**
 * Handles mysqli connection to the Meals DB external database.
 *
 * Author: Fishhorn Design
 * Licensed under GPLv3
 */

class MealsDB_DB {

    /**
     * @var mysqli|null
     */
    private static $connection = null;

    /**
     * Cache of resolved table names.
     *
     * @var array<string,string>
     */
    private static $table_cache = [];

    /**
     * Get active mysqli connection (lazy-loaded).
     */
    public static function get_connection(): ?mysqli
    {
        if (self::$connection instanceof mysqli) {
            return self::$connection;
        }

        if (!class_exists('mysqli')) {
            error_log('[MealsDB] mysqli extension missing.');
            return null;
        }

        // NEW API: static config getters
        $host = MealsDB_Config::db_host();
        $user = MealsDB_Config::db_user();
        $pass = MealsDB_Config::db_pass();
        $name = MealsDB_Config::db_name();

        if (!$host || !$user || !$pass || !$name) {
            error_log('[MealsDB] Missing database credentials in MealsDB_Config.');
            return null;
        }

        // Suppress mysqli warnings
        $prev = mysqli_report(MYSQLI_REPORT_OFF);

        try {
            self::$connection = @new mysqli($host, $user, $pass, $name);
        } catch (Throwable $e) {
            error_log('[MealsDB] Connection exception: ' . $e->getMessage());
            self::$connection = null;
        }

        mysqli_report($prev);

        if (!self::$connection instanceof mysqli || self::$connection->connect_error) {
            error_log('[MealsDB] Connection failed: ' . (self::$connection->connect_error ?? 'unknown error'));
            self::$connection = null;
            return null;
        }

        self::$connection->set_charset("utf8mb4");
        return self::$connection;
    }

    /**
     * Close DB connection manually.
     */
    public static function close_connection(): void
    {
        if (self::$connection instanceof mysqli) {
            self::$connection->close();
        }
        self::$connection = null;
    }

    /**
     * Resolve table name using new config system.
     */
    public static function table(string $base): string
    {
        if (isset(self::$table_cache[$base])) {
            return self::$table_cache[$base];
        }

        // The new config ALWAYS returns the correct table name.
        // It already applies prefixing internally.
        $resolved = MealsDB_Config::table($base);

        self::$table_cache[$base] = $resolved;
        return $resolved;
    }

    /**
     * Returns all clients sorted alphabetically.
     */
    public static function get_all_clients(mysqli $conn)
    {
        if (!$conn instanceof mysqli) {
            return false;
        }

        // NEW: uses standardized table name resolver
        $table = self::table('clients');
        $table_safe = str_replace('`', '``', $table);

        $sql = "SELECT client_id, first_name, last_name, client_type
                FROM `{$table_safe}`
                ORDER BY last_name ASC";

        return $conn->query($sql);
    }

    /**
     * Check if a table exists.
     */
    public static function table_exists(mysqli $conn, string $table): bool
    {
        if (!method_exists($conn, 'real_escape_string')) {
            return false;
        }

        $esc = $conn->real_escape_string($table);

        $sql = "
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = '{$esc}'
            LIMIT 1
        ";

        $result = $conn->query($sql);

        if ($result instanceof mysqli_result) {
            $exists = $result->num_rows > 0;
            $result->free();
            return $exists;
        }

        return false;
    }
}
