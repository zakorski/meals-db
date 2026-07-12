<?php
/**
 * MealsDB_Client_Form — zone-derived delivery_day tests (spec 2026-07-11).
 *
 * The zone schedule is the sole source of truth for delivery_day:
 *   1. validate() rejects a non-blank zone that resolves to no schedule key.
 *   2. validate() accepts a zone that IS in the schedule (no zone error).
 *   3. validate() ignores a posted delivery_day value (stale vocabulary must
 *      not block a save).
 *   4. save() derives delivery_day from the zone, discarding the posted value.
 *
 * Run with: php tests/test-client-form-zone-day.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }

if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__'))                  { function __($t, $d = 'default') { return $t; } }
if (!function_exists('esc_html__'))          { function esc_html__($t, $d = 'default') { return $t; } }
if (!function_exists('apply_filters'))       { function apply_filters($t, $v) { return $v; } }
if (!function_exists('wp_unslash'))          { function wp_unslash($v) { return $v; } }
if (!function_exists('is_user_logged_in'))   { function is_user_logged_in() { return true; } }
if (!function_exists('current_user_can'))    { function current_user_can($c) { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return (int) ($GLOBALS['mealsdb_current_user'] ?? 1); } }
if (!function_exists('sanitize_email'))      { function sanitize_email($v) { return trim((string) $v); } }
if (!function_exists('wp_json_encode'))      { function wp_json_encode($v, $opt = 0, $depth = 512) { return json_encode($v, $opt, max(1, $depth)); } }
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) {
        $v = (string) $v;
        $v = preg_replace('/[\r\n\t]+/', ' ', $v);
        return trim(preg_replace('/\s{2,}/', ' ', $v));
    }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($v) { return trim((string) $v); }
}

// get_option stub: tests control the schedule via $GLOBALS['zd_schedule'].
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        if ($key === 'mealsdb_zone_delivery_schedule') {
            return $GLOBALS['zd_schedule'] ?? $default;
        }
        return $default;
    }
}

if (!class_exists('WP_User')) { class WP_User { public $ID = 0; } }
$GLOBALS['mealsdb_known_wp_users'] = [500];
if (!function_exists('get_userdata')) {
    function get_userdata($id) {
        $id = (int) $id;
        if (!in_array($id, $GLOBALS['mealsdb_known_wp_users'] ?? [], true)) {
            return false;
        }
        $u = new WP_User();
        $u->ID = $id;
        return $u;
    }
}

// ---------------------------------------------------------------------------
// wpdb stub — mirrors IndexGuardWpdb from test-client-save-index-guard.php:
// captures the client INSERT payload so we can assert on derived columns.
// ---------------------------------------------------------------------------
if (!class_exists('wpdb')) { class wpdb {} }
class ZoneDayFormWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $rows_affected = 0;

    /** @var array<string,mixed>|null Captured payload from the client INSERT */
    public $clientInsert = null;
    public $clientInsertAttempted = false;

    /** @var string[] index columns that exist */
    public $columns = ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'];
    /** @var array<string,bool> index name => is_unique */
    public $indexes = [];

    public function prepare(string $sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        foreach ($args as $a) {
            $repl = is_int($a) || is_float($a) ? (string) $a : "'" . addslashes((string) $a) . "'";
            $sql = preg_replace('/%[sd]/', str_replace('$', '\\$', $repl), $sql, 1);
        }
        return $sql;
    }

    public function get_var($sql) {
        $sql = (string) $sql;
        if (stripos($sql, 'information_schema.tables') !== false) {
            return 1;
        }
        if (stripos($sql, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            $this->last_error = '';
            if (preg_match("/COLUMN_NAME = '([a-z_]+)'/i", $sql, $m)) {
                return in_array($m[1], $this->columns, true) ? $m[1] : null;
            }
            return null;
        }
        if (stripos($sql, 'SELECT client_id FROM') !== false) {
            return null; // no duplicates
        }
        return null;
    }

    public function get_results($sql, $output = ARRAY_A) {
        $sql = (string) $sql;
        if (stripos($sql, 'SHOW INDEX FROM') === 0
            && preg_match("/Key_name = '([a-z_]+)'/i", $sql, $m)) {
            $name = $m[1];
            if (!array_key_exists($name, $this->indexes)) {
                return [];
            }
            return [['Key_name' => $name, 'Non_unique' => $this->indexes[$name] ? 0 : 1]];
        }
        return [];
    }

    public function query($sql) {
        $sql = (string) $sql;
        if (stripos($sql, 'CREATE UNIQUE INDEX') === 0
            && preg_match('/`(unique_[a-z_]+)` ON `[^`]+` \(`([a-z_]+)`\)/i', $sql, $m)) {
            $this->indexes[$m[1]] = true;
            $this->last_error = '';
            return true;
        }
        return 0;
    }

    public function insert(string $table, array $data, $formats = null) {
        if (stripos($table, 'event_log') !== false) {
            $this->insert_id = 1;
            return 1;
        }
        // client insert
        $this->clientInsertAttempted = true;
        $this->clientInsert = $data;
        $this->last_error = '';
        $this->insert_id = 1;
        return 1;
    }

    public function update(string $table, array $data, array $where, $f1 = null, $f2 = null) {
        $this->last_error = '';
        return 1;
    }

    public function _real_escape($v) { return addslashes((string) $v); }
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------
$failures = [];
$passed   = 0;
function zf_check(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = 'FAIL: ' . $label;
}

function zf_reset(): ZoneDayFormWpdb {
    $flag = new ReflectionProperty('MealsDB_Client_Form', 'indexes_ensured');
    $flag->setAccessible(true);
    $flag->setValue(null, false);
    $wpdb = new ZoneDayFormWpdb();
    $GLOBALS['wpdb'] = $wpdb;
    $GLOBALS['mealsdb_current_user'] = 1;
    return $wpdb;
}

/**
 * Minimal valid Private client fixture — uses DB-side vocabulary where
 * applicable (delivery_area_name, not delivery_day, since delivery_day is
 * now zone-derived and should not be posted). For assertion 3 & 4, the
 * caller adds delivery_day to the payload.
 */
function mealsdb_test_minimal_valid_client(array $overrides = []): array {
    return array_merge([
        'client_type'         => 'Private',
        'first_name'          => 'Alex',
        'last_name'           => 'Client',
        'phone_primary'       => '(506)-555-1234',
        'address_street_name' => 'Main',
        'address_city'        => 'Moncton',
        'address_province'    => 'NB',
        'address_postal'      => 'E1E1E1',
        'delivery_area_name'  => 'Zone 1',
        // delivery_day is intentionally absent — it is derived from the zone.
        // Callers that need to test posted-value handling add it as an override.
        'delivery_day'        => 'wednesday',  // valid derived form so required-field check passes
        'payment_method'      => 'Cheque',
        'delivery_initials'   => 'ACL',
        'client_email'        => 'alex@example.com',
        'wordpress_user_id'   => '500',
    ], $overrides);
}

// Set up the schedule stub used throughout this file.
$GLOBALS['zd_schedule'] = [
    'Zone 1' => ['day' => 'Wednesday', 'label' => 'Moncton Downtown'],
];

// ---------------------------------------------------------------------------
// 1. validate(): a zone that is not a schedule key is a field error.
// ---------------------------------------------------------------------------
zf_reset();
$data1 = mealsdb_test_minimal_valid_client([
    'delivery_area_name' => 'Nope Zone',
]);
$res1 = MealsDB_Client_Form::validate($data1);
// The error is recorded via $record_format_error('delivery_area_name', …) which
// stores the field key in error_details['invalid_format'].
$has_zone_error = isset($res1['error_details']['invalid_format']['delivery_area_name']);
zf_check($has_zone_error, '1: validate() rejects a zone not in the schedule (error_details entry)');
zf_check($res1['valid'] === false, '1: validate() reports invalid when zone is unknown');

// ---------------------------------------------------------------------------
// 2. validate(): a schedule zone passes; no delivery_area_name error emitted.
// ---------------------------------------------------------------------------
zf_reset();
$data2 = mealsdb_test_minimal_valid_client();  // delivery_area_name = 'Zone 1' (in schedule)
$res2 = MealsDB_Client_Form::validate($data2);
$has_zone_error_2 = isset($res2['error_details']['invalid_format']['delivery_area_name']);
zf_check(!$has_zone_error_2, '2: validate() does not emit a zone error for a known schedule zone');

// ---------------------------------------------------------------------------
// 3. validate(): a posted delivery_day value is IGNORED — stale vocabulary
//    'WED AM' must not produce a validation error for delivery_day.
// ---------------------------------------------------------------------------
zf_reset();
$data3 = mealsdb_test_minimal_valid_client(['delivery_day' => 'WED AM']);
$res3 = MealsDB_Client_Form::validate($data3);
$day_error = isset($res3['error_details']['invalid_format']['delivery_day']);
zf_check(!$day_error, '3: validate() does not error on a posted delivery_day value (field is ignored, not validated)');

// ---------------------------------------------------------------------------
// 4. save(): derived delivery_day is stored as lowercase full day name;
//    posted 'WED AM' (or any other value) is discarded.
// ---------------------------------------------------------------------------
$wpdb4 = zf_reset();
$payload4 = mealsdb_test_minimal_valid_client(['delivery_day' => 'WED AM']);
$saved4 = MealsDB_Client_Form::save($payload4);
zf_check($saved4 === true, '4: save() succeeds with the zone-based model');
zf_check($wpdb4->clientInsertAttempted === true, '4: the client INSERT actually ran');
$inserted = $wpdb4->clientInsert;
$stored_day = $inserted['delivery_day'] ?? '(not present)';
zf_check($stored_day === 'wednesday',
    '4: stored delivery_day is the zone-derived lowercase day, not the posted WED AM — got: ' . var_export($stored_day, true));

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    echo implode("\n", $failures) . "\n";
    echo sprintf("FAILED: %d passed, %d failed\n", $passed, count($failures));
    exit(1);
}
echo "All {$passed} assertions passed.\n";
