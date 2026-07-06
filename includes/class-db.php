<?php
/**
 * Database abstraction layer for Meals DB.
 *
 * Uses the WordPress $wpdb instance. Tables live in the WordPress database
 * with the standard $wpdb->prefix.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_DB
{
    /**
     * Cache of resolved table names keyed by base table.
     *
     * @var array<string, string>
     */
    private static $table_name_cache = [];

    /**
     * Return the global $wpdb instance.
     *
     * @return wpdb
     */
    public static function connection()
    {
        global $wpdb;

        return $wpdb;
    }

    /**
     * BACKWARDS-COMPAT: old name used everywhere.
     *
     * @return wpdb
     */
    public static function get_connection()
    {
        return self::connection();
    }

    /**
     * Validate that a table name is in the whitelist of allowed tables.
     *
     * @param string $table The base table name to validate.
     * @return bool True if the table is allowed.
     */
    private static function validate_table_name(string $table): bool
    {
        if (!class_exists('MealsDB_Tables')) {
            return false;
        }

        $allowed_tables = MealsDB_Tables::all();

        if (in_array($table, $allowed_tables, true)) {
            return true;
        }

        // Check if table starts with the WP prefix + allowed base name
        global $wpdb;
        $prefix = $wpdb->prefix;

        if ($prefix !== '') {
            foreach ($allowed_tables as $allowed) {
                if ($table === $prefix . $allowed) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Core table-name resolver. Prepends $wpdb->prefix to the base table name.
     *
     * @param string $table
     * @return string
     */
    public static function table(string $table): string
    {
        if (isset(self::$table_name_cache[$table])) {
            return self::$table_name_cache[$table];
        }

        // Validate table name against whitelist
        if (!self::validate_table_name($table)) {
            error_log('[MealsDB DB] Invalid table name attempted: ' . $table);
            throw new InvalidArgumentException('Invalid table name.');
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        $prefixed_table = ($prefix !== '' && strpos($table, $prefix) !== 0)
            ? $prefix . $table
            : $table;

        self::$table_name_cache[$table] = $prefixed_table;

        return $prefixed_table;
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
}
