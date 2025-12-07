<?php
/**
 * Handles mysqli connection to the Meals DB external database.
 *
 * Author: Fishhorn Design
 */

class MealsDB_DB
{
    /** @var mysqli|null */
    private static $connection = null;

    /** @var array<string,string> */
    private static $table_name_cache = [];

    /**
     * Get connection (singleton)
     */
    public static function connection(): ?mysqli
    {
        if (self::$connection instanceof mysqli) {
            return self::$connection;
        }

        if (!class_exists('mysqli')) {
            error_log('[MealsDB] mysqli extension missing.');
            return null;
        }

        $config = MealsDB_Config::instance();

        $host = $config->db_host();
        $user = $config->db_user();
        $pass = $config->db_pass();
        $name = $config->db_name();

        if (!$host || !$user || !$pass || !$name) {
            error_log('[MealsDB] Missing external DB credentials in .env.');
            return null;
        }

        // Avoid PHP warnings
        $old = mysqli_report(MYSQLI_REPORT_OFF);

        try {
            self::$connection = @new mysqli($host, $user, $pass, $name);
        } catch (Throwable $e) {
            error_log('[MealsDB] mysqli exception: ' . $e->getMessage());
            self::$connection = null;
        }

        mysqli_report($old);

        if (!self::$connection || self::$connection->connect_errno) {
            error_log('[MealsDB] DB connection failed: ' . ($self::$connection->connect_error ?? 'unknown'));
            self::$connection = null;
            return null;
        }

        self::$connection->set_charset('utf8mb4');
        return self::$connection;
    }

    /**
     * Disconnect manually
     */
    public static function close(): void
    {
        if (self::$connection instanceof mysqli) {
            self::$connection->close();
        }
        self::$connection = null;
    }

    /**
     * Compute or fetch cached table name
     */
    public static function table(string $base): string
    {
        if (isset(self::$table_name_cache[$base])) {
            return self::$table_name_cache[$base];
        }

        $config = MealsDB_Config::instance();
        $prefix = $config->table_prefix();

        $candidate = $prefix ? $prefix . $base : $base;

        $conn = self::connection();

        if (!$conn) {
            return $base;
        }

        $final = $base;

        if (self::table_exists($conn, $candidate)) {
            $final = $candidate;
        } elseif (self::table_exists($conn, $base)) {
            $final = $base;
        }

        self::$table_name_cache[$base] = $final;
        return $final;
    }

    /**
     * Check if a table exists
     */
    private static function table_exists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);

        $sql = "
            SELECT 1 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
              AND table_name = '{$table}'
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

    /**
     * Example: fetch all clients (used by View Clients)
     */
    public static function get_all_clients(): ?mysqli_result
    {
        $conn = self::connection();
        if (!$conn) {
            return null;
        }

        $table = self::table('mealsdb_clients');
        $table = str_replace('`', '``', $table);

        $sql = "SELECT client_id, first_name, last_name, client_type FROM `{$table}` ORDER BY last_name ASC";

        return $conn->query($sql);
    }
}

