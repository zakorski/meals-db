<?php
/**
 * Lightweight autoloader for Meals DB classes.
 */

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
        $this->base_dir = rtrim($base_dir, '/\\') . DIRECTORY_SEPARATOR;
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
        if (strpos($class_name, 'MealsDB_') !== 0) {
            return;
        }

        $slug = strtolower(str_replace('_', '-', substr($class_name, 8)));
        if ($slug === '') {
            return;
        }

        $candidate_files = $this->build_candidate_files($slug);

        foreach ($candidate_files as $file) {
            if (is_readable($file)) {
                require_once $file;

                if (class_exists($class_name, false) || interface_exists($class_name, false) || trait_exists($class_name, false)) {
                    return;
                }
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
