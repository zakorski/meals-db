<?php
/**
 * Central configuration loader for Meals DB.
 * Reads settings from WordPress options first, then falls back to .env.
 */

class MealsDB_Config
{
    /** @var self|null */
    private static $instance = null;

    /** @var bool */
    private static $env_loaded = false;

    /** @var string */
    private $db_host = '';

    /** @var string */
    private $db_user = '';

    /** @var string */
    private $db_pass = '';

    /** @var string */
    private $db_name = '';

    /** @var string */
    private $table_prefix = '';

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
        $this->load_env_from_wp_root();
        $this->load_settings();
    }

    /**
     * Detect and load the .env in the WordPress root directory.
     */
    private function load_env_from_wp_root(): void
    {
        if (self::$env_loaded) {
            return;
        }

        $wp_root = dirname(ABSPATH . 'index.php');
        $env_path = $wp_root . '/.env';

        if (!is_readable($env_path)) {
            return;
        }

        foreach (file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.*)$/', $trimmed, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2], " \t\n\r\0\x0B\"'");
                putenv("$key=$value");
            }
        }

        self::$env_loaded = true;
    }

    /**
     * Load DB settings from WordPress options first, then environment vars.
     */
    private function load_settings(): void
    {
        $opts = function_exists('get_option') ? get_option('mealsdb_settings', []) : [];

        if (!is_array($opts)) {
            $opts = [];
        }

        $this->db_host      = !empty($opts['db_host']) ? $opts['db_host'] : (getenv('MEALS_DB_HOST') ?: '');
        $this->db_user      = !empty($opts['db_user']) ? $opts['db_user'] : (getenv('MEALS_DB_USER') ?: '');
        $this->db_pass      = !empty($opts['db_pass']) ? $opts['db_pass'] : (getenv('MEALS_DB_PASS') ?: '');
        $this->db_name      = !empty($opts['db_name']) ? $opts['db_name'] : (getenv('MEALS_DB_NAME') ?: '');
        $this->table_prefix = getenv('MEALS_DB_PREFIX') ?: '';
    }

    /**
     * Public getters for configuration values.
     */
    public function db_host(): string
    {
        return $this->db_host;
    }

    public function db_user(): string
    {
        return $this->db_user;
    }

    public function db_pass(): string
    {
        return $this->db_pass;
    }

    public function db_name(): string
    {
        return $this->db_name;
    }

    public function table_prefix(): string
    {
        return $this->table_prefix;
    }
}
