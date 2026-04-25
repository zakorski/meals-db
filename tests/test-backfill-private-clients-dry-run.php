<?php
/**
 * MealsDB_Backfill_Private_Clients::run($months, true) walks the preview
 * but never calls the promotion code, so no rows are ever created on
 * a dry run. The eligible count must still reflect what a live run
 * would process.
 *
 * Run with: php tests/test-backfill-private-clients-dry-run.php
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
        public bool $tables_present = true;
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
                return $this->tables_present ? 1 : null;
            }
            return null;
        }
        public function get_col($query, $x = 0) {
            // The eligible-WC-users query moved to get_results when
            // recent_order_id was added; get_col now serves only the
            // existing-meals_clients wp_user_id lookup, which has no
            // seeded fixtures here.
            return [];
        }
        public function get_results($query, $output = OBJECT) {
            if (stripos($query, 'customer_id') !== false && stripos($query, 'recent_order_id') !== false) {
                $rows = [];
                foreach ($this->eligible_users as $uid) {
                    $rows[] = [
                        'customer_id'     => (string) $uid,
                        'recent_order_id' => (string) ((int) $uid * 100),
                    ];
                }
                return $rows;
            }
            return [];
        }
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
        public $user_email = 'x@example.com';
        public $first_name = 'Bob';
        public $last_name  = 'Jones';
        public $display_name = 'Bob Jones';
    }
}
if (!function_exists('get_userdata')) {
    function get_userdata($id) { return $id > 0 ? new WP_User() : null; }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single = true) { return ''; }
}
if (!function_exists('wc_get_customer_order_count')) {
    function wc_get_customer_order_count($uid) { return 3; }
}
if (!function_exists('current_time')) {
    function current_time($t) { return '2026-04-22 12:00:00'; }
}
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }

global $wpdb;
$wpdb = new wpdb();
$wpdb->eligible_users = [101, 102, 103];

$failures = [];
$passed = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}

// Preview returns three rows keyed by the stubbed eligible users.
$preview = MealsDB_Backfill_Private_Clients::preview(24);
assert_equal(3, count($preview), 'preview yields 3 eligible users');

// Dry run: stats reflect eligible count, nothing promoted.
$stats = MealsDB_Backfill_Private_Clients::run(24, true);
assert_equal(3, $stats['eligible'], 'dry run: eligible=3');
assert_equal(0, $stats['promoted'], 'dry run: promoted=0');
assert_equal(0, $stats['errors'], 'dry run: errors=0');
assert_equal(0, $stats['skipped'], 'dry run: skipped=0');

// Missing tables → empty preview, not a fatal.
$wpdb->tables_present = false;
$preview_empty = MealsDB_Backfill_Private_Clients::preview(24);
assert_equal(0, count($preview_empty), 'missing tables → empty preview');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
