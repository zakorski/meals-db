<?php
/**
 * Defence-in-depth tests for MealsDB_Client_Form::save / update capability gate.
 *
 * views/add-client.php and views/edit-client.php already enforce the
 * capability before calling save()/update(), but these tests lock in
 * the service-layer guard so a future caller (WP-CLI, REST endpoint)
 * can't bypass the view-level check.
 *
 * Run with: php tests/test-client-form-authz.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default') { return $text; }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return 0; }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool {
        return isset($GLOBALS['__mealsdb_test_logged_in']) ? (bool) $GLOBALS['__mealsdb_test_logged_in'] : false;
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can(string $cap): bool {
        return isset($GLOBALS['__mealsdb_test_caps'][$cap]) ? (bool) $GLOBALS['__mealsdb_test_caps'][$cap] : false;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args) { return $value; }
}

// Minimal wpdb stub: the guard should short-circuit BEFORE save/update
// reach $wpdb. If the guard is bypassed, any method call on this stub
// throws and fails the test.
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $output = 'OBJECT') { return []; }
        public function get_row($query, $output = 'OBJECT', $y = 0) { return null; }
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function query($query) { return 0; }
    }
}

class AuthzGuardTest_Wpdb extends wpdb {
    public function __construct() { /* no parent */ }
    public function prepare($query, ...$args) {
        throw new RuntimeException('wpdb::prepare() must not be reached when caller is unauthorized.');
    }
}

$failures = [];
$passed   = 0;

function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}

// ---------------------------------------------------------------------------
// Unauthorized: save() and update() return false and never touch $wpdb.
// ---------------------------------------------------------------------------
$GLOBALS['__mealsdb_test_logged_in'] = false;
$GLOBALS['__mealsdb_test_caps']      = [];

$GLOBALS['wpdb'] = new AuthzGuardTest_Wpdb();

assert_equal(false, MealsDB_Client_Form::save(['client_type' => 'PRIVATE', 'first_name' => 'Test']), 'save() returns false when unauthorized');
assert_equal(false, MealsDB_Client_Form::update(42, ['first_name' => 'Updated']), 'update() returns false when unauthorized');

// Missing capability with a logged-in user: still denied.
$GLOBALS['__mealsdb_test_logged_in'] = true;
$GLOBALS['__mealsdb_test_caps']      = ['subscriber' => true]; // no manage_* caps

assert_equal(false, MealsDB_Client_Form::save(['client_type' => 'PRIVATE']), 'save() denies a logged-in non-admin');
assert_equal(false, MealsDB_Client_Form::update(42, ['first_name' => 'X']), 'update() denies a logged-in non-admin');

// ---------------------------------------------------------------------------
// update() with client_id=0: rejected by the guard, not by the early
// client_id check, because the guard runs first.
// ---------------------------------------------------------------------------
assert_equal(false, MealsDB_Client_Form::update(0, ['first_name' => 'Y']), 'update() returns false when unauthorized even with invalid client_id');

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
