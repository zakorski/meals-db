<?php
/**
 * Lightweight autoloader for Meals DB classes.
 */

defined('ABSPATH') || exit;

class MealsDB_Autoloader {
    /**
     * Singleton instance retained to keep the autoloader registered.
     *
     * @var MealsDB_Autoloader|null
     */
    private static $instance = null;

    /**
     * Base plugin directory with trailing slash.
     *
     * @var string
     */
    private $base_dir;

    /**
     * List of sub-directories (relative to the base directory) that may contain class files.
     *
     * @var string[]
     */
    private $directories = [];

    /**
     * Register the Meals DB autoloader.
     *
     * @param string $base_dir
     * @param string[] $directories
     */
    public static function register(string $base_dir, array $directories = []): void {
        $directories = !empty($directories) ? $directories : [
            'includes',
            'includes/admin',
            'includes/ajax',
            'includes/services',
            'includes/services/sync',
        ];

        if (function_exists('apply_filters')) {
            $directories = apply_filters('mealsdb_autoloader_directories', $directories, $base_dir);
        }

        self::$instance = new self($base_dir, $directories);
        spl_autoload_register([self::$instance, 'autoload']);
    }

    /**
     * @param string $base_dir
     * @param string[] $directories
     */
    private function __construct(string $base_dir, array $directories) {
        // Resolve base_dir through realpath() so downstream path-containment
        // checks compare real inode locations, not a mix of relative
        // prefixes and resolved candidate paths.
        $resolved         = realpath($base_dir);
        $this->base_dir   = rtrim($resolved !== false ? $resolved : $base_dir, '/\\') . DIRECTORY_SEPARATOR;
        $this->directories = array_map([
            $this,
            'normalise_directory',
        ], $directories);
    }

    /**
     * Attempt to load the requested class file when referenced.
     *
     * @param string $class_name
     */
    private function autoload(string $class_name): void {
        // PHP passes any string given to class_exists() / new into registered
        // autoloaders, not just syntactically valid class names. Require a
        // strict identifier shape after the MealsDB_ prefix so a probe like
        // class_exists('MealsDB_../../etc/passwd') or a null-byte injection
        // can never reach the filesystem layer below.
        if (!preg_match('/^MealsDB_[A-Za-z0-9_]+$/', $class_name)) {
            return;
        }

        $slug = strtolower(str_replace('_', '-', substr($class_name, 8)));
        if ($slug === '') {
            return;
        }

        $candidate_files = $this->build_candidate_files($slug);

        foreach ($candidate_files as $file) {
            // Defence-in-depth: confirm the candidate resolves to a real
            // file inside base_dir before require_once. The whitelist above
            // already blocks path traversal at the slug level; this catches
            // the case where a registered autoloader directory is itself
            // configured via a filter (see apply_filters(
            // 'mealsdb_autoloader_directories', ...)) to point outside the
            // plugin tree.
            $resolved = realpath($file);
            if ($resolved === false || !is_file($resolved)) {
                continue;
            }
            if (strpos($resolved, $this->base_dir) !== 0) {
                continue;
            }

            require_once $resolved;

            if (class_exists($class_name, false) || interface_exists($class_name, false) || trait_exists($class_name, false)) {
                return;
            }
        }
    }

    /**
     * Generate a list of possible file paths for a class slug.
     *
     * @param string $slug
     *
     * @return string[]
     */
    private function build_candidate_files(string $slug): array {
        $files = [];

        foreach ($this->directories as $directory) {
            $files[] = $directory . 'class-' . $slug . '.php';
        }

        return array_unique($files);
    }

    /**
     * Normalise directory values so they can be concatenated reliably.
     *
     * @param string $directory
     *
     * @return string
     */
    private function normalise_directory(string $directory): string {
        $directory = trim($directory);

        if ($directory === '') {
            return $this->base_dir;
        }

        return $this->base_dir . trim($directory, '/\\') . DIRECTORY_SEPARATOR;
    }
}
