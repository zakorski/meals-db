<?php
/**
 * MealsDB_Private_Intake::maybe_promote is safe to call repeatedly —
 * when a meals_clients row already exists for the wp_user_id, the
 * method returns the existing client_id without a second insert.
 *
 * Run with: php tests/test-private-intake-idempotent.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// Minimal wpdb stub that returns a pre-seeded row on get_by_wp_user_id's
// SELECT, and tracks whether any INSERT ever executes so we can assert
// the idempotent short-circuit.
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public int $insert_id = 0;
        public int $insert_calls = 0;
        public array $seed_rows = [];
        public function prepare($query, ...$args) {
            // Substitute the simplest %d occurrences — enough to keep the
            // query stable so seed_rows key lookups work predictably.
            if (empty($args)) return $query;
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
        public function get_row($query, $output = OBJECT) {
            foreach ($this->seed_rows as $pattern => $row) {
                if (strpos($query, $pattern) !== false) {
                    return $row;
                }
            }
            return null;
        }
        public function get_var($query, $x = 0, $y = 0) {
            if (stripos($query, 'information_schema') !== false) {
                return 1;
            }
            return null;
        }
        public function get_col($query, $x = 0) { return []; }
        public function get_results($query, $output = OBJECT) { return []; }
        public function query($query) { return 0; }
        public function insert($table, $data) {
            $this->insert_calls++;
            $this->insert_id = 9999;
            return 1;
        }
    }
}
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

// Encryption constant required by the Encryption::encrypt_columns
// call that MealsDB_Clients_Repository::create triggers.
if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
}

// WP stubs — get_userdata returns a WP_User shell so maybe_promote
// would continue past the user-existence check IF it ever reached it.
if (!class_exists('WP_User')) {
    class WP_User {
        public $user_email = 'x@example.com';
        public $first_name = '';
        public $last_name = '';
    }
}
if (!function_exists('get_userdata')) {
    function get_userdata($id) { return $id > 0 ? new WP_User() : null; }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single = true) { return ''; }
}
if (!function_exists('current_time')) {
    function current_time($type) { return '2026-04-22 12:00:00'; }
}
if (!function_exists('current_user_can')) { function current_user_can($cap) { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }

global $wpdb;
$wpdb = new wpdb();

// MealsDB_Tables / DB need to resolve to a table name for the
// repository's get_by_wp_user_id query. If the autoloader picks them
// up (they're real plugin files), this works. If not, they'll fatal.
// The existing tests rely on the autoloader so we lean on it here too.

// Pre-seed a "row already exists" response keyed by the WHERE fragment.
$wpdb->seed_rows['wp_user_id =  123'] = [
    'client_id'   => 555,
    'wp_user_id'  => 123,
    'client_type' => 'Private',
    'first_name'  => 'Existing',
    'last_name'   => 'User',
];
// Also match the unspaced form the ArrayA prepare path may emit.
$wpdb->seed_rows['wp_user_id = 123'] = $wpdb->seed_rows['wp_user_id =  123'];

$failures = [];
$passed = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}

$result = MealsDB_Private_Intake::maybe_promote(123);
assert_equal(555, $result, 'existing record → returns client_id without INSERT');
assert_equal(0, $wpdb->insert_calls, 'no INSERT issued when row exists');

// Second call is also a no-op.
$result2 = MealsDB_Private_Intake::maybe_promote(123);
assert_equal(555, $result2, 'second call → same client_id');
assert_equal(0, $wpdb->insert_calls, 'still no INSERT after second call');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
