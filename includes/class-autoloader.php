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
     * Explicit class map for loading known classes from fixed paths.
     *
     * @var array<string, string>
     */
    private $class_map = [];

    /**
     * Register the Meals DB autoloader.
     *
     * @param string                 $base_dir
     * @param string[]               $directories
     * @param array<string, string>  $class_map
     */
    public static function register(string $base_dir, array $directories = [], array $class_map = []): void {
        $directories = !empty($directories) ? $directories : [
            'includes',
            'includes/ajax',
            'includes/services',
            'includes/services/sync',
        ];

        $class_map = array_merge([
            'MealsDB_Products'         => 'includes/class-products.php',
            'MealsDB_Products_Loader'  => 'includes/class-products-loader.php',
        ], $class_map);

        if (function_exists('apply_filters')) {
            $directories = apply_filters('mealsdb_autoloader_directories', $directories, $base_dir);
        }

        self::$instance = new self($base_dir, $directories, $class_map);
        spl_autoload_register([self::$instance, 'autoload']);
    }

    /**
     * @param string                 $base_dir
     * @param string[]               $directories
     * @param array<string, string>  $class_map
     */
    private function __construct(string $base_dir, array $directories, array $class_map) {
        $this->base_dir = rtrim($base_dir, '/\\') . DIRECTORY_SEPARATOR;
        $this->directories = array_map([
            $this,
            'normalise_directory',
        ], $directories);
        $this->class_map = $class_map;
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

        $mapped = $this->class_map[$class_name] ?? null;
        if (is_string($mapped)) {
            $this->load_mapped_class($class_name, $mapped);

            if (class_exists($class_name, false) || interface_exists($class_name, false) || trait_exists($class_name, false)) {
                return;
            }
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
     * Load a class from a predefined map entry.
     */
    private function load_mapped_class(string $class_name, string $mapped_path): void {
        $file = $this->base_dir . ltrim($mapped_path, '/\\');

        if (is_readable($file)) {
            require_once $file;
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
