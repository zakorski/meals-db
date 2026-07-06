<?php
/**
 * Central configuration loader for Meals DB.
 *
 * With the migration to $wpdb, external DB credentials are no longer needed.
 * This class now only manages the encryption key and other plugin settings.
 */

defined('ABSPATH') || exit;

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
}
