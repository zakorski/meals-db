<?php
/**
 * MealsDB_Backfill_Private_Clients::preview excludes users who already
 * have a meals_clients record, and returns a row-per-eligible-user
 * shape that the admin preview table can consume.
 *
 * Run with: php tests/test-backfill-private-clients-criteria.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public array $eligible_users = [];
        public array $existing_client_user_ids = [];
        public function prepare($query, ...$args) {
            $flat = $args;
            if (count($flat) === 1 && is_array($flat[0])) {
                $flat = $flat[0];
            }
            foreach ($flat as $arg) {
                $pos = strpos($query, '%d');
                if ($pos === false) $pos = strpos($query, '%s');
                if ($pos === false) break;
                $query = substr($query, 0, $pos) . (is_int($arg) ? (string)$arg : "'" . addslashes((string)$arg) . "'") . substr($query, $pos + 2);
            }
            return $query;
        }
        public function get_row($query, $output = OBJECT) { return null; }
        public function get_var($query, $x = 0, $y = 0) {
            if (stripos($query, 'information_schema') !== false) {
                return 1;
            }
            return null;
        }
        public function get_col($query, $x = 0) {
            // Two call sites: (1) eligible WC users, (2) existing meals_clients wp_user_ids.
            if (stripos($query, 'customer_id') !== false) {
                return array_map('strval', $this->eligible_users);
            }
            if (stripos($query, 'wp_user_id') !== false) {
                return array_map('strval', $this->existing_client_user_ids);
            }
            return [];
        }
        public function get_results($query, $output = OBJECT) { return []; }
        public function query($query) { return 0; }
        public function insert($table, $data) { return 1; }
    }
}
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
}

if (!class_exists('WP_User')) {
    class WP_User {
        public $user_email;
        public $first_name;
        public $last_name;
        public $display_name;
        public function __construct(int $id) {
            $this->user_email = 'u' . $id . '@example.com';
            $this->first_name = 'First' . $id;
            $this->last_name  = 'Last' . $id;
            $this->display_name = $this->first_name . ' ' . $this->last_name;
        }
    }
}
if (!function_exists('get_userdata')) {
    function get_userdata($id) { return $id > 0 ? new WP_User((int)$id) : null; }
}
if (!function_exists('wc_get_customer_order_count')) {
    function wc_get_customer_order_count($uid) { return (int) $uid; }
}
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single = true) { return ''; }
}
if (!function_exists('current_time')) {
    function current_time($t) { return '2026-04-22 12:00:00'; }
}

global $wpdb;
$wpdb = new wpdb();

// 4 WC users qualify; 2 of them already have meals_clients rows.
$wpdb->eligible_users = [10, 20, 30, 40];
$wpdb->existing_client_user_ids = [20, 40];

$failures = [];
$passed = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}

$preview = MealsDB_Backfill_Private_Clients::preview(24);
assert_equal(2, count($preview), 'existing rows excluded — 2 net-new users');

// Verify the exact set.
$ids = array_map(function ($r) { return $r['wp_user_id']; }, $preview);
sort($ids);
assert_equal([10, 30], $ids, 'remaining users are the two without meals_clients');

// Row shape: wp_user_id, email, name, order_count.
$first = $preview[0];
assert_equal(true, isset($first['wp_user_id']), 'row has wp_user_id');
assert_equal(true, isset($first['email']), 'row has email');
assert_equal(true, isset($first['name']), 'row has name');
assert_equal(true, isset($first['order_count']), 'row has order_count');

// Empty eligible set → empty preview.
$wpdb->eligible_users = [];
$empty_preview = MealsDB_Backfill_Private_Clients::preview(24);
assert_equal(0, count($empty_preview), 'no eligible users → empty preview');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
