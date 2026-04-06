<?php
/**
 * Central configuration loader for Meals DB.
 *
 * With the migration to $wpdb, external DB credentials are no longer needed.
 * This class now only manages the encryption key and other plugin settings.
 */

class MealsDB_Config
{
    /** @var self|null */
    private static $instance = null;

    /**
     * Singleton accessor.
     */
    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reset the singleton so the next call to instance() reloads settings.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Private constructor to enforce singleton usage.
     */
    private function __construct()
    {
        // No external DB credentials to load.
    }

    /**
     * Table prefix is now handled by $wpdb->prefix.
     */
    public function table_prefix(): string
    {
        global $wpdb;

        return $wpdb->prefix;
    }

    /**
     * External DB credentials are no longer needed.
     * The plugin now uses the WordPress database via $wpdb.
     */
    public static function is_db_configured(): bool
    {
        return true;
    }
}
