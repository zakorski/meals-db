<?php
/**
 * Tests for the fail-closed-on-backend-loss policy in
 * MealsDB_Rate_Limiter::check_rate_limit() (B1).
 *
 * With no object-cache backend and no $wpdb, atomic_increment()
 * returns 0 to signal "no usable backend". check_rate_limit() then
 * applies:
 *   - Mutating action (create / modify / sync): REFUSE the request.
 *   - Read action: ALLOW (better to show stale data than break the UI
 *     during a transient cache outage).
 *
 * Run with: php tests/test-rate-limiter-failclosed.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $t, $v, ...$a) { return $v; } }
if (!function_exists('sanitize_key')) {
    function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/i', '_', strtolower((string) $s)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($s) { return trim((string) $s); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($s) { return is_string($s) ? stripslashes($s) : $s; }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return 0; }
}
// wp_using_ext_object_cache is NOT defined → the cache branch is skipped.

// Ensure $wpdb is null so atomic_increment takes the "no backend" path.
$GLOBALS['wpdb'] = null;

$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}

// Sanity: classification helper.
assert_equal(true,  MealsDB_Rate_Limiter::is_mutating_action('quick_order_create'), 'quick_order_create is mutating');
assert_equal(true,  MealsDB_Rate_Limiter::is_mutating_action('client_modify'),      'client_modify is mutating');
assert_equal(true,  MealsDB_Rate_Limiter::is_mutating_action('sync_operations'),    'sync_operations is mutating');
assert_equal(false, MealsDB_Rate_Limiter::is_mutating_action('quick_order_read'),   'quick_order_read is NOT mutating');
assert_equal(false, MealsDB_Rate_Limiter::is_mutating_action('client_search'),      'client_search is NOT mutating');
assert_equal(false, MealsDB_Rate_Limiter::is_mutating_action('delivery_slips'),     'delivery_slips is NOT mutating');
assert_equal(false, MealsDB_Rate_Limiter::is_mutating_action('unknown_action'),     'unknown action defaults to NOT mutating');

// With no backend:
//   mutating actions fail CLOSED (false)
//   read actions fail OPEN (true)
assert_equal(false, MealsDB_Rate_Limiter::check_rate_limit('quick_order_create'), 'create fails closed with no backend');
assert_equal(false, MealsDB_Rate_Limiter::check_rate_limit('client_modify'),      'modify fails closed with no backend');
assert_equal(false, MealsDB_Rate_Limiter::check_rate_limit('sync_operations'),    'sync fails closed with no backend');

assert_equal(true, MealsDB_Rate_Limiter::check_rate_limit('quick_order_read'), 'read fails open with no backend');
assert_equal(true, MealsDB_Rate_Limiter::check_rate_limit('client_search'),    'search fails open with no backend');
assert_equal(true, MealsDB_Rate_Limiter::check_rate_limit('delivery_slips'),   'delivery_slips fails open with no backend');
assert_equal(true, MealsDB_Rate_Limiter::check_rate_limit('unknown_action'),   'unknown action fails open (safe default)');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
