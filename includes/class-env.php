<?php
/**
 * Loads environment variables from a .env file into $_ENV and getenv().
 * 
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Created as a work for hire for Meals and More.
 */

class MealsDB_Env {

    /**
     * Load .env file and make variables available globally.
     *
     * @param string $file_path Absolute path to the .env file.
     */
    public static function load($file_path) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            error_log('[MealsDB] .env file not found or unreadable at: ' . $file_path);
            return;
        }

        $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse key=value
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);

                $key = trim($key);
                $value = trim($value);

                // Strip optional surrounding quotes
                $value = trim($value, '"\'');

                if (!array_key_exists($key, $_ENV)) {
                    $_ENV[$key] = $value;
                }

                if (function_exists('putenv')) {
                    putenv("$key=$value");
                }
            }
        }
    }
}
