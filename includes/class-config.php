<?php
/**
 * MealsDB Configuration Loader
 *
 * Handles loading environment variables from:
 *  - .env file in the plugin root
 *  - WP-config.php constants
 *  - Server environment variables
 *  - Hard defaults
 *
 * Author: Fishhorn Design
 * Licensed under GPL 3.0+
 */

class MealsDB_Config {

    private static $instance = null;

    private $env_loaded = false;
    private $plugin_root;

    private function __construct() {
        $this->plugin_root = dirname(__FILE__, 2);
        $this->load_env_file();
    }

    /**
     * Singleton Factory
     */
    public static function get() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load .env file into environment variables.
     */
    private function load_env_file() {
        if ($this->env_loaded) {
            return;
        }

        $env_path = $this->plugin_root . '/.env';

        if (!file_exists($env_path)) {
            $this->env_loaded = true;
            return;
        }

        $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {

            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse key=value
            if (strpos($line, '=') !== false) {
                list($key, $value) = array_map('trim', explode('=', $line, 2));

                if ($key !== '' && getenv($key) === false) {
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }

        $this->env_loaded = true;
    }

    /**
     * Helper to resolve values from priority:
     *   1. Environment variable
     *   2. WordPress constant
     *   3. Hardcoded fallback
     */
    private function resolve($env_key, $wp_constant, $default = null) {

        // Environment variable?
        $env_value = getenv($env_key);
        if ($env_value !== false && $env_value !== '') {
            return $env_value;
        }

        // WP-config.php constant?
        if (defined($wp_constant)) {
            return constant($wp_constant);
        }

        return $default;
    }

    /* ------------------------
     * DATABASE CONFIG METHODS
     * ------------------------ */

    public function db_host() {
        return $this->resolve('PLUGIN_DB_HOST', 'MEALS_DB_HOST', 'localhost');
    }

    public function db_name() {
        return $this->resolve('PLUGIN_DB_NAME', 'MEALS_DB_NAME', null);
    }

    public function db_user() {
        return $this->resolve('PLUGIN_DB_USER', 'MEALS_DB_USER', null);
    }

    public function db_pass() {
        return $this->resolve('PLUGIN_DB_PASS', 'MEALS_DB_PASS', null);
    }

    public function table_prefix() {
        return $this->resolve('MEALSDB_TABLE_PREFIX', 'MEALS_DB_TABLE_PREFIX', '');
    }
}
