<?php
/**
 * Tests for MealsDB_User_Delete::anonymise_meals_client_for_wp_user().
 *
 * Locks in the GDPR behaviour: when a WP user is deleted, the
 * meals_clients row linked to them must have every PII column blanked
 * and the wp_user_id link zeroed before wp_delete_user fires — and the
 * helper must return 0 (not an error) when no linked client exists.
 *
 * Run with: php tests/test-user-delete-anonymise.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default') { return $text; }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args) { return $value; }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return 9999; }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public string $code;
        public string $message;
        public function __construct(string $code = '', string $message = '') {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_message(): string { return $this->message; }
        public function get_error_code(): string { return $this->code; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool { return $thing instanceof WP_Error; }
}

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

/**
 * Records queries and returns configurable results for get_var.
 * Captures the final UPDATE SQL so tests can assert the column set.
 */
class AnonymiseTest_Wpdb extends wpdb {
    public array $query_log = [];
    public ?int  $client_id_for_user = 77;
    public bool  $update_fails = false;

    public function __construct() { /* no parent */ }

    public function prepare($query, ...$args) {
        if (!empty($args) && is_array($args[0] ?? null)) { $args = $args[0]; }
        $out = $query;
        foreach ($args as $a) {
            $out = preg_replace('/%d|%s/', is_int($a) ? (string) $a : "'" . addslashes((string) $a) . "'", $out, 1);
        }
        return $out;
    }

    public function get_var($sql, $x = 0, $y = 0) {
        $this->query_log[] = ['m' => 'get_var', 'sql' => $sql];
        if (stripos($sql, 'SELECT client_id') !== false) {
            return $this->client_id_for_user !== null ? (string) $this->client_id_for_user : null;
        }
        return null;
    }

    public function query($sql) {
        $this->query_log[] = ['m' => 'query', 'sql' => $sql];
        if (stripos($sql, 'UPDATE') !== false && $this->update_fails) {
            $this->last_error = 'forced failure';
            return false;
        }
        return 1;
    }

    public function last_update_sql(): string {
        $updates = array_values(array_filter($this->query_log, static function ($row) {
            return stripos($row['sql'], 'UPDATE') === 0;
        }));
        return end($updates)['sql'] ?? '';
    }
}

$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}
function assert_true($value, string $label) {
    global $failures, $passed;
    if ((bool) $value) { $passed++; return; }
    $failures[] = "FAIL: $label (expected true, got " . var_export($value, true) . ')';
}
function assert_contains(string $needle, string $haystack, string $label) {
    global $failures, $passed;
    if (strpos($haystack, $needle) !== false) { $passed++; return; }
    $failures[] = "FAIL: $label (expected '$needle' in the UPDATE SQL)";
}

// ---------------------------------------------------------------------------
// Happy path: linked client found, UPDATE issued, returns client_id.
// ---------------------------------------------------------------------------
$GLOBALS['wpdb'] = new AnonymiseTest_Wpdb();
$result = MealsDB_User_Delete::anonymise_meals_client_for_wp_user(42);
assert_equal(77, $result, 'returns client_id when a linked client is anonymised');

$sql = $GLOBALS['wpdb']->last_update_sql();
assert_contains('`wp_user_id` = 0',  $sql, 'UPDATE zeros wp_user_id');
assert_contains('`active` = 0',      $sql, 'UPDATE marks client inactive');
assert_contains("`first_name` = ''", $sql, 'first_name blanked (NOT NULL)');
assert_contains("`last_name` = ''",  $sql, 'last_name blanked (NOT NULL)');
assert_contains("`delivery_initials` = ''", $sql, 'delivery_initials blanked (NOT NULL)');
assert_contains('`individual_id` = NULL',         $sql, 'individual_id nulled');
assert_contains('`individual_id_index` = NULL',   $sql, 'individual_id hash-index nulled (prevents rainbow match)');
assert_contains('`requisition_id` = NULL',        $sql, 'requisition_id nulled');
assert_contains('`requisition_id_index` = NULL',  $sql, 'requisition_id hash-index nulled');
assert_contains('`vet_health_card` = NULL',       $sql, 'vet_health_card nulled');
assert_contains('`vet_health_card_index` = NULL', $sql, 'vet_health_card hash-index nulled');
assert_contains('`delivery_initials_index` = NULL', $sql, 'delivery_initials hash-index nulled');
assert_contains('`client_email` = NULL',          $sql, 'email nulled');
assert_contains('`client_phone_1` = NULL',        $sql, 'primary phone nulled');
assert_contains('`client_phone_2` = NULL',        $sql, 'secondary phone nulled');
assert_contains('`diet_concerns` = NULL',         $sql, 'diet_concerns nulled');
assert_contains('`customer_comments` = NULL',     $sql, 'customer_comments nulled');
assert_contains('`street_name` = NULL',           $sql, 'home address nulled');
assert_contains('`delivery_street_name` = NULL',  $sql, 'delivery address nulled');
assert_contains('`birth_date` = NULL',            $sql, 'DOB nulled');
assert_contains('WHERE `client_id` = 77',         $sql, 'WHERE pins the exact client_id');

// NOT anonymised columns — client_type is a required ENUM, use_legacy_billing
// is a billing flag; neither is PII, and nulling them would break the row.
assert_true(strpos($sql, '`client_type`') === false, 'client_type is NOT modified');
assert_true(strpos($sql, '`use_legacy_billing`') === false, 'use_legacy_billing is NOT modified');

// ---------------------------------------------------------------------------
// No linked client: returns 0, no UPDATE issued.
// ---------------------------------------------------------------------------
$GLOBALS['wpdb'] = new AnonymiseTest_Wpdb();
$GLOBALS['wpdb']->client_id_for_user = null;
$result = MealsDB_User_Delete::anonymise_meals_client_for_wp_user(42);
assert_equal(0, $result, 'returns 0 when no linked client exists');

$any_updates = array_filter($GLOBALS['wpdb']->query_log, static function ($row) {
    return stripos($row['sql'], 'UPDATE') !== false;
});
assert_equal([], array_values($any_updates), 'no UPDATE fires when there is nothing to anonymise');

// ---------------------------------------------------------------------------
// Non-positive wp_user_id: fast-path returns 0 without touching wpdb.
// ---------------------------------------------------------------------------
$GLOBALS['wpdb'] = new AnonymiseTest_Wpdb();
assert_equal(0, MealsDB_User_Delete::anonymise_meals_client_for_wp_user(0), 'wp_user_id=0 returns 0');
assert_equal(0, MealsDB_User_Delete::anonymise_meals_client_for_wp_user(-1), 'wp_user_id<0 returns 0');
assert_equal([], $GLOBALS['wpdb']->query_log, 'non-positive wp_user_id never queries');

// ---------------------------------------------------------------------------
// UPDATE failure: WP_Error with the wpdb error message.
// ---------------------------------------------------------------------------
$GLOBALS['wpdb'] = new AnonymiseTest_Wpdb();
$GLOBALS['wpdb']->update_fails = true;
$result = MealsDB_User_Delete::anonymise_meals_client_for_wp_user(42);
assert_true(is_wp_error($result), 'returns WP_Error when UPDATE fails');
assert_equal('mealsdb_anonymise_failed', $result->get_error_code(), 'error code is mealsdb_anonymise_failed');

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
