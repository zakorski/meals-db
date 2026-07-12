<?php
/**
 * MealsDB_Client_Form unit tests (rewritten).
 *
 * HISTORY: the previous version of this file (1,303 lines) targeted the RETIRED
 * external-mysqli architecture — it injected a StubMysqli into a
 * MealsDB_DB::$connection property via reflection. That property no longer
 * exists (the plugin moved to $wpdb), so the file could not run: with no
 * ABSPATH it exited 0 with ZERO assertions (a false green), and with ABSPATH it
 * fatally errored on the missing property. It was the only dedicated coverage of
 * check_unique_fields dedup, delete_draft ownership authz, and the validate()
 * per-type matrices, so that coverage was effectively absent.
 *
 * This rewrite uses the $wpdb-stub harness (same shape as
 * tests/test-client-save-index-guard.php) and asserts the CURRENT behaviour —
 * notably the post-MAJ-1 change where a duplicate individual_id / requisition_id
 * is a non-blocking WARNING, not a hard error.
 *
 * Coverage:
 *   A  validate() surfaces a HARD conflict for a duplicate hard-unique field
 *      (vet_health_card) — "already exists in another client."
 *   B  validate() does NOT hard-error a duplicate WARN-only field
 *      (individual_id); it emits a warning naming the other client instead.
 *   C  validate() rejects invalid enumerated / numeric inputs.
 *   D  validate() accepts a valid payload and sanitizes it.
 *   E  validate() rejects a Staff client_type (managed via the Staff Directory).
 *   F  delete_draft() enforces created_by ownership: a non-owner cannot delete;
 *      the owner can.
 *
 * Run with: php tests/test-client-form.php
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

/**
 * A $wpdb stub that reports the deterministic index columns + unique indexes as
 * present (so ensure_index_columns_exist() is a no-op success), answers the
 * unique-field / warning lookups from $columnOwner, and models one draft row so
 * delete_draft()'s ownership check can be exercised.
 */
if (!class_exists('wpdb')) { class wpdb {} }
class ClientFormWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $rows_affected = 0;

    /** @var string[] index columns that exist */
    public $columns = ['individual_id_index', 'requisition_id_index', 'vet_health_card_index', 'delivery_initials_index'];
    /** @var array<string,int> index/plain column => client_id returned by a dedup/warn lookup */
    public $columnOwner = [];

    /** One modelled draft row for the delete_draft ownership test. */
    public $draftId = 0;
    public $draftOwner = 0;
    public $deleteAttempted = false;

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
            return 1; // the clients / drafts table exists
        }

        if (stripos($sql, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            $this->last_error = '';
            if (preg_match("/COLUMN_NAME = '([a-z_]+)'/i", $sql, $m)) {
                return in_array($m[1], $this->columns, true) ? $m[1] : null;
            }
            return null;
        }

        // Draft existence / ownership lookup.
        if (stripos($sql, 'draft') !== false && stripos($sql, 'SELECT id FROM') !== false) {
            $id = preg_match('/id = (\d+)/', $sql, $mi) ? (int) $mi[1] : 0;
            if (stripos($sql, 'created_by') !== false) {
                $owner = preg_match('/created_by = (\d+)/', $sql, $mo) ? (int) $mo[1] : -1;
                return ($id === $this->draftId && $owner === $this->draftOwner) ? $id : null;
            }
            return ($id === $this->draftId) ? $id : null;
        }

        // Unique-field / warning lookup: SELECT client_id ... WHERE `<col>` = ...
        if (stripos($sql, 'SELECT client_id FROM') !== false
            && preg_match('/WHERE `([a-z_]+)`/i', $sql, $m)) {
            return $this->columnOwner[$m[1]] ?? null;
        }

        return null;
    }

    public function get_results($sql, $output = ARRAY_A) {
        $sql = (string) $sql;
        // Report every queried index as present + unique so
        // ensure_index_columns_exist() never tries to build one.
        if (stripos($sql, 'SHOW INDEX FROM') === 0
            && preg_match("/Key_name = '([a-z_]+)'/i", $sql, $m)) {
            return [['Key_name' => $m[1], 'Non_unique' => 0]];
        }
        return [];
    }

    public function get_row($sql, $output = ARRAY_A) {
        $sql = (string) $sql;
        // U01-client-form-11: delete_draft()/save_draft() now do ONE
        // "SELECT created_by FROM ... drafts WHERE id = %d" owner lookup
        // (was two draft_exists() SELECTs). Model the single draft row.
        if (stripos($sql, 'draft') !== false && stripos($sql, 'created_by') !== false) {
            $id = preg_match('/id = (\d+)/', $sql, $m) ? (int) $m[1] : 0;
            if ($id !== $this->draftId) {
                return null; // draft not found
            }
            return ['created_by' => $this->draftOwner];
        }
        return null;
    }

    public function query($sql) {
        $sql = (string) $sql;

        if (stripos($sql, 'DELETE FROM') !== false && stripos($sql, 'draft') !== false) {
            $this->deleteAttempted = true;
            $id    = preg_match('/id = (\d+)/', $sql, $mi) ? (int) $mi[1] : 0;
            $owner = preg_match('/created_by = (\d+)/', $sql, $mo) ? (int) $mo[1] : -1;
            if ($id === $this->draftId && $owner === $this->draftOwner) {
                $this->rows_affected = 1;
                return 1;
            }
            $this->rows_affected = 0;
            return 0;
        }

        // ADD COLUMN / CREATE INDEX etc. — succeed silently (not exercised here
        // because the columns/indexes already "exist").
        return 0;
    }

    public function insert(string $table, array $data, $formats = null) { $this->insert_id = 1; return 1; }
    public function update(string $table, array $data, array $where, $f1 = null, $f2 = null) { return 1; }
    public function _real_escape($v) { return addslashes((string) $v); }
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------
$failures = [];
$passed   = 0;
function check($cond, string $label) {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = 'FAIL: ' . $label;
}

function reset_index_flag(): void {
    $flag = new ReflectionProperty('MealsDB_Client_Form', 'indexes_ensured');
    $flag->setAccessible(true);
    $flag->setValue(null, false);
}

function fresh_wpdb(): ClientFormWpdb {
    reset_index_flag();
    $wpdb = new ClientFormWpdb();
    $GLOBALS['wpdb'] = $wpdb;
    $GLOBALS['mealsdb_current_user'] = 1;
    return $wpdb;
}

// get_option stub: delivery zone schedule used by MealsDB_Zone_Day.
// validate() / save() derive delivery_day from the zone — WED AM vocabulary deleted.
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        if ($key === 'mealsdb_zone_delivery_schedule') {
            return $GLOBALS['zd_schedule'] ?? $default;
        }
        return $default;
    }
}
$GLOBALS['zd_schedule'] = [
    'Zone 1' => ['day' => 'Wednesday', 'label' => 'Moncton Downtown'],
];

function valid_private_payload(array $overrides = []): array {
    return array_merge([
        'client_type'         => 'Private',
        'first_name'          => 'Alex',
        'last_name'           => 'Client',
        'phone_primary'       => '(506)-555-1234',
        'address_street_name' => 'Main',
        'address_city'        => 'Moncton',
        'address_province'    => 'NB',
        'address_postal'      => 'E1E1E1',
        // delivery_day is zone-derived (spec 2026-07-11): WED AM vocabulary
        // is deleted. delivery_area_name drives the day; validate() /
        // save() call MealsDB_Zone_Day::day_for_zone() via
        // apply_zone_delivery_day() to set delivery_day = 'wednesday'.
        'delivery_area_name'  => 'Zone 1',
        'delivery_day'        => 'wednesday',  // still included so the Private required-field check passes
        'payment_method'      => 'Cheque',
        'delivery_initials'   => 'ACL',
        'client_email'        => 'alex@example.com',
        'wordpress_user_id'   => '500',
    ], $overrides);
}

function has_error_matching(array $result, string $needle): bool {
    foreach ($result['errors'] ?? [] as $e) {
        if (stripos($e, $needle) !== false) { return true; }
    }
    return false;
}

// ---------------------------------------------------------------------------
// A: a duplicate HARD-unique field (vet_health_card) is a blocking conflict.
// ---------------------------------------------------------------------------
$wpdb = fresh_wpdb();
$wpdb->columnOwner = ['vet_health_card_index' => 88];
$resA = MealsDB_Client_Form::validate(valid_private_payload(['vet_health_card' => 'HC-9001']));
check($resA['valid'] === false, 'A: a duplicate vet_health_card fails validation');
check(has_error_matching($resA, 'already exists in another client'),
    'A: the hard-conflict message is surfaced');

// ---------------------------------------------------------------------------
// B: a duplicate WARN-only field (individual_id) is NOT a hard error; it emits
//    a warning naming the other client. Asserts the post-MAJ-1 behaviour the
//    old test got wrong (it expected a hard error).
// ---------------------------------------------------------------------------
$wpdb = fresh_wpdb();
$wpdb->columnOwner = ['individual_id_index' => 7];
$resB = MealsDB_Client_Form::validate(valid_private_payload(['individual_id' => 'GOV-DUAL-1']));
check(!has_error_matching($resB, 'individual id already exists'),
    'B: a duplicate individual_id does NOT become a hard error (allow-and-warn)');
check($resB['valid'] === true, 'B: the payload still validates despite the duplicate ID');
$warnB = implode(' | ', $resB['warnings'] ?? []);
check(strpos($warnB, '#7') !== false, 'B: a warning names the other client (#7): ' . $warnB);

// ---------------------------------------------------------------------------
// C: invalid enumerated / numeric inputs are rejected.
// ---------------------------------------------------------------------------
$wpdb = fresh_wpdb();
$resC = MealsDB_Client_Form::validate(valid_private_payload([
    'gender'            => 'Unknown',
    'service_zone'      => 'Z',
    'ordering_frequency'=> 'often',
    'delivery_fee'      => 'ten dollars',
]));
check($resC['valid'] === false, 'C: invalid enumerated/numeric inputs fail validation');
check(has_error_matching($resC, 'Gender must be'), 'C: the gender enum error is surfaced');
check(has_error_matching($resC, 'Service zone must be'), 'C: the service-zone enum error is surfaced');
check(has_error_matching($resC, 'frequency must be a number'), 'C: a numeric-field error is surfaced');

// ---------------------------------------------------------------------------
// D: a valid payload validates and is sanitized.
// ---------------------------------------------------------------------------
$wpdb = fresh_wpdb();
$resD = MealsDB_Client_Form::validate(valid_private_payload(['gender' => 'Female']));
check($resD['valid'] === true, 'D: a well-formed payload validates');
check(($resD['sanitized']['first_name'] ?? null) === 'Alex', 'D: a scalar field is sanitized through');
check(($resD['sanitized']['gender'] ?? null) === 'Female', 'D: a valid enum value survives sanitization');

// ---------------------------------------------------------------------------
// E: a Staff client_type is rejected (managed via the Staff Directory).
// ---------------------------------------------------------------------------
$wpdb = fresh_wpdb();
$resE = MealsDB_Client_Form::validate([
    'client_type'       => 'Staff',
    'first_name'        => 'Sam',
    'last_name'         => 'Staffer',
    'delivery_initials' => 'SST',
]);
check($resE['valid'] === false, 'E: a Staff client_type is rejected');
check(has_error_matching($resE, 'Staff Directory'), 'E: the rejection points at the Staff Directory');

// ---------------------------------------------------------------------------
// F: delete_draft() enforces created_by ownership.
// ---------------------------------------------------------------------------
$wpdb = fresh_wpdb();
$wpdb->draftId = 55;
$wpdb->draftOwner = 500;

$GLOBALS['mealsdb_current_user'] = 999; // a different user
$deletedByStranger = MealsDB_Client_Form::delete_draft(55);
check($deletedByStranger === false, 'F: a non-owner cannot delete the draft');
check($wpdb->deleteAttempted === false, 'F: no DELETE is executed for a non-owner (ownership blocks it first)');

$wpdb->deleteAttempted = false;
$GLOBALS['mealsdb_current_user'] = 500; // the owner
$deletedByOwner = MealsDB_Client_Form::delete_draft(55);
check($deletedByOwner === true, 'F: the owner can delete the draft');
check($wpdb->deleteAttempted === true, 'F: the owner path actually runs the scoped DELETE');

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    echo implode("\n", $failures) . "\n";
    echo sprintf("FAILED: %d passed, %d failed\n", $passed, count($failures));
    exit(1);
}
echo "All {$passed} assertions passed.\n";
