<?php
/**
 * MealsDB_Client_Form — "New Portal" checkbox → use_legacy_billing column.
 *
 * The SDNB invoice pipeline split is driven by meals_clients.use_legacy_billing
 * (1 = legacy zone-based CSV, 0 = new-portal CSV). The edit form exposes it as
 * a "New Portal" checkbox (checked = new portal = 0). These tests pin the form
 * plumbing:
 *   1. update() with use_legacy_billing='0' persists '0' (checkbox checked).
 *   2. update() with use_legacy_billing='1' persists '1' (unchecked → hidden
 *      fallback input).
 *   3. update() WITHOUT the key leaves the column untouched (non-SDNB client
 *      types disable the row's inputs client-side, so the key never posts).
 *   4. Sanitizer normalizes non-canonical truthy values to '1'.
 *   5. validate() emits no error for the field.
 *   6. save() (new client) persists a posted '0' on INSERT.
 *
 * Run with: php tests/test-client-form-new-portal.php
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
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
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
// Zone schedule stub (delivery_area_name validation reads it).
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        if ($key === 'mealsdb_zone_delivery_schedule') {
            return ['Zone 1' => ['day' => 'Wednesday', 'label' => 'Moncton Downtown']];
        }
        return $default;
    }
}

if (!class_exists('WP_User')) { class WP_User { public $ID = 0; } }
if (!function_exists('get_userdata')) {
    function get_userdata($id) {
        if ((int) $id !== 500) { return false; }
        $u = new WP_User();
        $u->ID = 500;
        return $u;
    }
}

// wpdb stub — mirrors ZoneDayFormWpdb: captures the client INSERT/UPDATE payloads.
if (!class_exists('wpdb')) { class wpdb {} }
class NewPortalFormWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $rows_affected = 0;
    public $clientInsert = null;
    public $clientInsertAttempted = false;
    public $clientUpdate = null;
    public $clientUpdateAttempted = false;
    public $columns = ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'];
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
        if (stripos($sql, 'information_schema.tables') !== false) { return 1; }
        if (stripos($sql, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            $this->last_error = '';
            if (preg_match("/COLUMN_NAME = '([a-z_]+)'/i", $sql, $m)) {
                return in_array($m[1], $this->columns, true) ? $m[1] : null;
            }
            return null;
        }
        return null;
    }
    public function get_results($sql, $output = ARRAY_A) {
        $sql = (string) $sql;
        if (stripos($sql, 'SHOW INDEX FROM') === 0
            && preg_match("/Key_name = '([a-z_]+)'/i", $sql, $m)) {
            $name = $m[1];
            if (!array_key_exists($name, $this->indexes)) { return []; }
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
        $this->clientInsertAttempted = true;
        $this->clientInsert = $data;
        $this->last_error = '';
        $this->insert_id = 1;
        return 1;
    }
    public function update(string $table, array $data, array $where, $f1 = null, $f2 = null) {
        $this->clientUpdateAttempted = true;
        $this->clientUpdate = $data;
        $this->last_error = '';
        return 1;
    }
    public function _real_escape($v) { return addslashes((string) $v); }
}

$failures = [];
$passed   = 0;
function np_check(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = 'FAIL: ' . $label;
}
function np_reset(): NewPortalFormWpdb {
    $flag = new ReflectionProperty('MealsDB_Client_Form', 'indexes_ensured');
    $flag->setAccessible(true);
    $flag->setValue(null, false);
    $wpdb = new NewPortalFormWpdb();
    $GLOBALS['wpdb'] = $wpdb;
    return $wpdb;
}

// Minimal valid client fixture (form-side vocabulary), matching the zone-day
// fixture. Private type keeps the required-field surface small; the
// use_legacy_billing plumbing is type-agnostic server-side.
function np_fixture(array $overrides = []): array {
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
        'payment_method'      => 'Cheque',
        'delivery_initials'   => 'ACL',
        'client_email'        => 'alex@example.com',
        'wordpress_user_id'   => '500',
    ], $overrides);
}

// 1. update() with '0' (checkbox checked = New Portal) persists '0'.
$wpdb1 = np_reset();
$ok1 = MealsDB_Client_Form::update(1, np_fixture(['use_legacy_billing' => '0']));
np_check($ok1 === true, '1: update() accepts use_legacy_billing=0 (no unknown-field abort)');
np_check(($wpdb1->clientUpdate['use_legacy_billing'] ?? '(absent)') === '0',
    '1: UPDATE payload carries use_legacy_billing=0 — got: ' . var_export($wpdb1->clientUpdate['use_legacy_billing'] ?? null, true));

// 2. update() with '1' (unchecked → hidden fallback) persists '1'.
$wpdb2 = np_reset();
$ok2 = MealsDB_Client_Form::update(1, np_fixture(['use_legacy_billing' => '1']));
np_check($ok2 === true, '2: update() accepts use_legacy_billing=1');
np_check(($wpdb2->clientUpdate['use_legacy_billing'] ?? '(absent)') === '1',
    '2: UPDATE payload carries use_legacy_billing=1');

// 3. update() WITHOUT the key: column untouched (non-SDNB rows disable the
//    inputs client-side, so the key never posts for those types).
$wpdb3 = np_reset();
$ok3 = MealsDB_Client_Form::update(1, np_fixture());
np_check($ok3 === true, '3: update() succeeds without the key');
np_check(!array_key_exists('use_legacy_billing', $wpdb3->clientUpdate ?? []),
    '3: UPDATE payload has NO use_legacy_billing key when not posted');

// 4. Sanitizer normalizes a non-canonical truthy value to '1'.
$wpdb4 = np_reset();
MealsDB_Client_Form::update(1, np_fixture(['use_legacy_billing' => 'yes']));
np_check(($wpdb4->clientUpdate['use_legacy_billing'] ?? '(absent)') === '1',
    '4: sanitizer normalizes "yes" to 1');

// 5. validate() emits no error for the field.
np_reset();
$res5 = MealsDB_Client_Form::validate(np_fixture(['use_legacy_billing' => '0']));
np_check($res5['valid'] === true,
    '5: validate() passes with use_legacy_billing posted — errors: ' . wp_json_encode($res5['error_details'] ?? []));

// 6. save() (new client) persists a posted '0' on INSERT.
$wpdb6 = np_reset();
$saved6 = MealsDB_Client_Form::save(np_fixture(['use_legacy_billing' => '0']));
np_check($saved6 === true, '6: save() succeeds with use_legacy_billing=0');
np_check(($wpdb6->clientInsert['use_legacy_billing'] ?? '(absent)') === '0',
    '6: INSERT payload carries use_legacy_billing=0');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
