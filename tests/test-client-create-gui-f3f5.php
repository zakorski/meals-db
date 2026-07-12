<?php
/**
 * Regression test for directive GUI-F3F5 — new-client create through the GUI
 * failed at the DB insert ("Database error occurred."), persisting only a draft.
 *
 * Two distinct create-path defects are proven and guarded here:
 *
 *   1. PHANTOM COLUMNS (the reason even a *valid* create failed — cases F3/F5).
 *      save() called apply_insert_defaults() AFTER map_form_to_db() while the
 *      defaults map was keyed on FORM-side names (phone_primary, address_postal).
 *      Post-mapping those keys were always absent, so the defaults were injected
 *      as columns that do not exist in meals_clients. create_client() does NOT
 *      filter_to_known_columns (update_client() does — which is why edit worked),
 *      so the phantom columns reached $wpdb->insert and every create failed.
 *
 *   2. PROVINCE OVERFLOW. province (VARCHAR(10)) had no validation/clamp, so a
 *      full name like "New Brunswick" (13) overflowed the column at insert.
 *
 * Also covers STEP 3: a failed create now surfaces a field-attributed message
 * instead of a generic "Database error occurred.", without leaking the raw
 * $wpdb error.
 *
 * Run with: php tests/test-client-create-gui-f3f5.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }

// Encryption key for encrypt_columns() (Private payloads still run the
// encrypt-then-insert path even though no PII columns are populated here).
if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
}

// Zone schedule stub: delivery_day is zone-derived (spec 2026-07-11).
// The test uses Zone 1, which maps to Wednesday.
$GLOBALS['mealsdb_zone_schedule'] = [
    'Zone 1' => ['day' => 'Wednesday', 'label' => 'Wednesday morning'],
];

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__'))                  { function __($t, $d = 'default') { return $t; } }
if (!function_exists('apply_filters'))       { function apply_filters($t, $v) { return $v; } }
if (!function_exists('wp_unslash'))          { function wp_unslash($v) { return $v; } }
if (!function_exists('is_user_logged_in'))   { function is_user_logged_in() { return true; } }
if (!function_exists('current_user_can'))    { function current_user_can($c) { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('sanitize_email'))      { function sanitize_email($v) { return trim((string) $v); } }
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        if ($key === 'mealsdb_zone_delivery_schedule') {
            return $GLOBALS['mealsdb_zone_schedule'] ?? $default;
        }
        return $default;
    }
}
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

// WP-user existence stub for directive GUI-F3F5-v2. save()/update() now require
// wordpress_user_id to name an EXISTING WP user; the form verifies via
// get_userdata. Treat the IDs in $GLOBALS['mealsdb_known_wp_users'] as real and
// everything else as nonexistent so the create path can be exercised both ways.
if (!class_exists('WP_User')) { class WP_User { public $ID = 0; } }
$GLOBALS['mealsdb_known_wp_users'] = [500, 777];
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

// $wpdb stub: records insert()/update() payloads and can be flipped to fail an
// insert (with a chosen last_error) to exercise the STEP-3 attribution path.
if (!class_exists('wpdb')) { class wpdb {} }
class F3F5TestWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $lastInsert = null;
    public $lastUpdate = null;
    public $failInsert = false;
    public $failError = '';

    public function insert(string $t, array $d, $f = null) {
        $this->lastInsert = $d;
        if ($this->failInsert) {
            $this->last_error = $this->failError;
            return false;
        }
        $this->last_error = '';
        $this->insert_id = 123;
        return 1;
    }
    public function update(string $t, array $d, array $w, $f1 = null, $f2 = null) {
        $this->lastUpdate = $d;
        $this->last_error = '';
        return 1;
    }
    public function query($sql) { return 0; }
    public function prepare(string $sql, ...$args) { return $sql; }
    public function get_var($sql) {
        // The repository confirms the clients table exists before any write.
        if (stripos((string) $sql, 'information_schema.tables') !== false) {
            return 1;
        }
        return null;
    }
    public function get_row($sql, $o = OBJECT) { return null; }
    public function get_results($sql, $o = OBJECT) { return []; }
    public function get_col($sql) { return []; }
    public function _real_escape($v) { return addslashes((string) $v); }
}
$wpdb = new F3F5TestWpdb();
$GLOBALS['wpdb'] = $wpdb;

// Skip the deterministic-index DDL probe — it is not what's under test.
$flag = new ReflectionProperty('MealsDB_Client_Form', 'indexes_ensured');
$flag->setAccessible(true);
$flag->setValue(null, true);

$failures = [];
$passed   = 0;
function check($cond, string $label) {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = 'FAIL: ' . $label;
}

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
        'delivery_area_name'  => 'Zone 1',
        'payment_method'      => 'Cheque',
        'delivery_initials'   => 'ACL',
        'client_email'        => 'alex@example.com',
        // GUI-F3F5-v2: every client links to an existing WP user. 500 is in the
        // get_userdata stub's known set above.
        'wordpress_user_id'   => '500',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// T-2 / F3-F5: a valid Private client persists, and the insert payload carries
// the real DB columns with NO phantom form-side columns.
// ---------------------------------------------------------------------------
$wpdb->failInsert = false;
$wpdb->lastInsert = null;
$saved = MealsDB_Client_Form::save(valid_private_payload());
check($saved === true, 'valid Private create returns true (F3/F5 persists)');

$ins = $wpdb->lastInsert ?? [];
check(is_array($ins) && !empty($ins), 'insert payload captured');
check(($ins['client_phone_1'] ?? null) === '(506)-555-1234', 'phone stored in DB column client_phone_1');
check(($ins['postal_code'] ?? null) === 'E1E1E1', 'postal stored in DB column postal_code');
check(($ins['province'] ?? null) === 'NB', 'province stored in DB column province');
check(!array_key_exists('phone_primary', $ins), 'NO phantom form-side phone_primary column in insert');
check(!array_key_exists('address_postal', $ins), 'NO phantom form-side address_postal column in insert');
check(!array_key_exists('address_province', $ins), 'NO phantom form-side address_province column in insert');

// ---------------------------------------------------------------------------
// T-3: create/edit parity — the form maps to the same DB column vocabulary on
// both paths; neither leaks a form-side name.
// ---------------------------------------------------------------------------
$wpdb->lastUpdate = null;
MealsDB_Client_Form::update(7, valid_private_payload());
$upd = $wpdb->lastUpdate ?? [];
check(array_key_exists('client_phone_1', $upd), 'update also writes DB column client_phone_1');
check(!array_key_exists('phone_primary', $upd), 'update does not leak form-side phone_primary');
$insCols = array_keys($ins);
$leaked = array_intersect($insCols, ['phone_primary', 'address_postal', 'address_province', 'wordpress_user_id', 'client_comments']);
check(empty($leaked), 'create insert column set contains no form-side names: ' . implode(',', $leaked));

// ---------------------------------------------------------------------------
// T-2b: province full name normalises to its code (no overflow).
// ---------------------------------------------------------------------------
$wpdb->lastInsert = null;
$saved2 = MealsDB_Client_Form::save(valid_private_payload(['address_province' => 'New Brunswick']));
check($saved2 === true, 'create with full-name province succeeds');
check((($wpdb->lastInsert['province'] ?? null) === 'NB'), '"New Brunswick" normalised to "NB" before insert');

// ---------------------------------------------------------------------------
// T-1: over-long phone and bogus province are rejected by validate() with NAMED
// field errors — before any DB write.
// ---------------------------------------------------------------------------
$v1 = MealsDB_Client_Form::validate(valid_private_payload(['phone_primary' => '(506)-555-1234 ext 99999']));
check($v1['valid'] === false, 'over-long phone fails validation');
check(isset($v1['error_details']['invalid_format']['phone_primary']), 'over-long phone produces a named phone field error');

$v2 = MealsDB_Client_Form::validate(valid_private_payload(['address_province' => 'Onterio']));
check($v2['valid'] === false, 'unrecognised province fails validation');
check(isset($v2['error_details']['invalid_format']['address_province']), 'bad province produces a named province field error');

$v3 = MealsDB_Client_Form::validate(valid_private_payload(['address_province' => 'New Brunswick']));
check($v3['valid'] === true, 'full-name province validates (normalised to code)');

// ---------------------------------------------------------------------------
// T-5: a well-formatted phone with trailing whitespace is normalised and
// accepted cleanly (not passed through to fail at $wpdb).
// ---------------------------------------------------------------------------
$v5 = MealsDB_Client_Form::validate(valid_private_payload(['phone_primary' => "(506)-555-1234 \t"]));
check($v5['valid'] === true, 'phone with trailing whitespace is normalised and accepted');

// ---------------------------------------------------------------------------
// T-4 / STEP 3: a forced insert failure yields a field-attributed message and
// the raw $wpdb error is NOT returned to the caller.
// ---------------------------------------------------------------------------
$wpdb->failInsert = true;
$wpdb->failError  = 'Processing the value for the following field failed: client_phone_1. The supplied value may be too long or contains invalid data.';
$saved3 = MealsDB_Client_Form::save(valid_private_payload());
check($saved3 === false, 'forced insert failure makes save() return false');
check(MealsDB_Clients_Repository::last_failed_column() === 'client_phone_1', 'repository parses the offending column from the wpdb error');

$msg = MealsDB_Client_Form::last_save_error();
check(stripos($msg, 'Client Phone #1') !== false, 'save error message names the offending field: ' . $msg);
check(stripos($msg, 'Processing the value') === false, 'save error message does NOT leak the raw wpdb error');

// Repository column parser handles the other common wpdb/MySQL shapes.
$parse = new ReflectionMethod('MealsDB_Clients_Repository', 'parse_failed_column');
$parse->setAccessible(true);
check($parse->invoke(null, "Data too long for column 'province' at row 1") === 'province', 'parses "Data too long for column" shape');
check($parse->invoke(null, "Unknown column 'phone_primary' in 'field list'") === 'phone_primary', 'parses "Unknown column" shape');
check($parse->invoke(null, 'some unrelated error') === null, 'returns null when no column is identifiable');

// ---------------------------------------------------------------------------
// GUI-F3F5-v2 T-5: create REQUIRES a validated WP user. A blank
// wordpress_user_id is a named field error with NO insert attempted and NO
// raw "Database error occurred." (the actual root-cause fix).
// ---------------------------------------------------------------------------
$wpdb->failInsert = false;
$wpdb->lastInsert = null;
$savedBlank = MealsDB_Client_Form::save(valid_private_payload(['wordpress_user_id' => '']));
check($savedBlank === false, 'create with blank WP user returns false (no userless client)');
check($wpdb->lastInsert === null, 'create with blank WP user attempts NO insert');
$blankMsg = MealsDB_Client_Form::last_save_error();
check(stripos($blankMsg, 'WordPress User ID') !== false, 'blank WP user yields a named WordPress-User-ID error: ' . $blankMsg);
check(stripos($blankMsg, 'Database error') === false, 'blank WP user does NOT surface "Database error occurred."');

// T-8: a syntactically-valid but NONEXISTENT id (bypassing the Validate button)
// is rejected server-side with a named error — never a raw DB failure.
$wpdb->lastInsert = null;
$savedGhost = MealsDB_Client_Form::save(valid_private_payload(['wordpress_user_id' => '999999']));
check($savedGhost === false, 'create with nonexistent WP user returns false');
check($wpdb->lastInsert === null, 'create with nonexistent WP user attempts NO insert');
check(stripos(MealsDB_Client_Form::last_save_error(), '999999') !== false, 'nonexistent WP user error names the offending id');

// A non-numeric id is rejected too (not just blank/nonexistent).
$wpdb->lastInsert = null;
$savedBad = MealsDB_Client_Form::save(valid_private_payload(['wordpress_user_id' => 'abc']));
check($savedBad === false, 'create with non-numeric WP user returns false');

// T-5 positive: WITH a valid existing WP user the client persists and the
// insert carries wp_user_id (DB-side), never the form-side name.
$wpdb->lastInsert = null;
$savedOk = MealsDB_Client_Form::save(valid_private_payload(['wordpress_user_id' => '777']));
check($savedOk === true, 'create WITH a valid WP user persists (F-R1)');
check((int) ($wpdb->lastInsert['wp_user_id'] ?? 0) === 777, 'insert carries wp_user_id (DB-side) = 777');
check(!array_key_exists('wordpress_user_id', $wpdb->lastInsert ?? []), 'insert has no form-side wordpress_user_id column');

// T-6: update enforces the WP user identically — a blank wordpress_user_id on
// update is a named error (NOT a silent null), and a present valid id is fine.
$wpdb->lastUpdate = null;
$updBlank = MealsDB_Client_Form::update(7, valid_private_payload(['wordpress_user_id' => '']));
check($updBlank === false, 'update with blank WP user returns false (parity with create)');
check(stripos(MealsDB_Client_Form::last_save_error(), 'WordPress User ID') !== false, 'update blank WP user yields a named error');

$wpdb->lastUpdate = null;
$updOk = MealsDB_Client_Form::update(7, valid_private_payload(['wordpress_user_id' => '500']));
check($updOk === true, 'update WITH a valid WP user succeeds');
check((int) ($wpdb->lastUpdate['wp_user_id'] ?? 0) === 500, 'update carries wp_user_id (DB-side) = 500');

// A partial update that does NOT touch wordpress_user_id is NOT forced to
// resupply it (the existing row already carries a valid link).
$wpdb->lastUpdate = null;
$updPartial = MealsDB_Client_Form::update(7, ['first_name' => 'Renamed', 'delivery_initials' => 'RNM']);
check($updPartial === true, 'partial update without wordpress_user_id still succeeds');

// ---------------------------------------------------------------------------
if (!empty($failures)) {
    echo implode("\n", $failures) . "\n";
    echo sprintf("\n%d passed, %d FAILED\n", $passed, count($failures));
    exit(1);
}
echo sprintf("All %d assertions passed.\n", $passed);
