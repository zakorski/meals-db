<?php

defined('ABSPATH') || exit;

class MealsDB_Plugin {

    private static $instance = null;

    public $path;
    public $url;
    public $basename;

    public static function init() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->path     = plugin_dir_path( __FILE__ ) . '../'; 
        $this->url      = plugin_dir_url( __FILE__ ) . '../';
        $this->basename = plugin_basename( dirname( __FILE__, 2 ) . '/meals-db-main.php' );
    }

    public static function path( $file = '' ) {
        return self::$instance->path . ltrim( $file, '/' );
    }

    public static function url( $file = '' ) {
        return self::$instance->url . ltrim( $file, '/' );
    }
}
