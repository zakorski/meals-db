<?php

defined('ABSPATH') || exit;

class MealsDB_Plugin {

    private static $instance = null;

    public $path;

    public static function init() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->path     = plugin_dir_path( __FILE__ ) . '../';
    }

    public static function path( $file = '' ) {
        return self::$instance->path . ltrim( $file, '/' );
    }
}
