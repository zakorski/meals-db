<?php
/**
 * Regression test for directive GUI-SAVE-INDEX — the deterministic-index guard
 * blocked EVERY client save ("deterministic index columns are unavailable.")
 * whenever a UNIQUE index could not be built over duplicate government IDs
 * (MySQL errno 1062). On staging, migrated dual-program clients legitimately
 * shared an individual_id / requisition_id, so CREATE UNIQUE INDEX failed and
 * the guard fail-CLOSED, aborting create AND edit for all client types.
 *
 * This test deliberately does NOT rely on the constraint-blind stub wpdb that
 * let 79/79 unit tests pass while staging failed (see the directive's closing
 * note). It exercises the guard's true/false decision directly against a wpdb
 * stub that CAN fail a CREATE UNIQUE INDEX, proving:
 *
 *   T-1  save() succeeds despite an unbuildable UNIQUE index (degraded, not abort)
 *   T-2  dedup find-by-index still works without the DB-level UNIQUE constraint
 *   T-3  two clients may share an individual_id — a WARNING names the other
 *        client, validation still passes, the save proceeds (allow-and-warn)
 *   T-4  a genuinely missing/unbuildable index COLUMN still aborts, with a
 *        clear attributed message (never the bare "Database error occurred.")
 *   T-5  the degraded path AND the genuine abort each emit an Event-Log entry
 *
 * Run with: php tests/test-client-save-index-guard.php
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
// A wpdb stub that, unlike the constraint-blind one, can REFUSE a CREATE UNIQUE
// INDEX (errno 1062) and can report a hash column as missing/unaddable — the
// two real-MySQL behaviours the staging failure depended on.
// ---------------------------------------------------------------------------
if (!class_exists('wpdb')) { class wpdb {} }
class IndexGuardWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    /** @var array<string,bool> index name => is_unique */
    public $indexes = [];
    /** @var string[] hash columns that currently exist */
    public $columns;
    /** @var string[] columns whose ADD COLUMN must fail */
    public $addColumnFails = [];
    /** @var string[] index columns whose CREATE UNIQUE INDEX must fail (1062) */
    public $uniqueBuildFails = [];
    /** @var array<string,int> index column => client_id returned by a dedup lookup */
    public $hashOwner = [];

    public $clientInsert = null;
    public $clientInsertAttempted = false;
    public $events = [];

    public function __construct() {
        // Default: all four hash columns already present.
        $this->columns = [
            'individual_id_index', 'requisition_id_index',
            'vet_health_card_index', 'delivery_initials_index',
        ];
    }

    public function prepare(string $sql, ...$args) {
        // Flatten a single array arg (wpdb accepts both shapes).
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
            return 1; // the clients table exists
        }

        if (stripos($sql, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            $this->last_error = '';
            if (preg_match("/COLUMN_NAME = '([a-z_]+)'/i", $sql, $m)) {
                return in_array($m[1], $this->columns, true) ? $m[1] : null;
            }
            return null;
        }

        // Dedup lookup: SELECT client_id ... WHERE `<col>` = '<hash>' ...
        if (stripos($sql, 'SELECT client_id FROM') !== false
            && preg_match('/WHERE `([a-z_]+)`/i', $sql, $m)) {
            return $this->hashOwner[$m[1]] ?? null;
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
            return [[
                'Key_name'   => $name,
                'Non_unique' => $this->indexes[$name] ? 0 : 1,
            ]];
        }

        // Backfill SELECT — nothing to backfill in these scenarios.
        return [];
    }

    public function query($sql) {
        $sql = (string) $sql;

        if (stripos($sql, 'ADD COLUMN') !== false
            && preg_match('/ADD COLUMN `([a-z_]+)`/i', $sql, $m)) {
            if (in_array($m[1], $this->addColumnFails, true)) {
                $this->last_error = "Error: cannot add column {$m[1]}";
                return false;
            }
            $this->columns[] = $m[1];
            $this->last_error = '';
            return true;
        }

        if (stripos($sql, 'DROP INDEX') !== false
            && preg_match('/DROP INDEX `([a-z_]+)`/i', $sql, $m)) {
            unset($this->indexes[$m[1]]);
            $this->last_error = '';
            return true;
        }

        if (stripos($sql, 'CREATE UNIQUE INDEX') === 0
            && preg_match('/`(unique_[a-z_]+)` ON `[^`]+` \(`([a-z_]+)`\)/i', $sql, $m)) {
            $col = $m[2];
            if (in_array($col, $this->uniqueBuildFails, true)) {
                // errno 1062: duplicate entry — the staging condition.
                $this->last_error = "Duplicate entry for key '{$m[1]}' (errno: 1062)";
                return false;
            }
            $this->indexes[$m[1]] = true;
            $this->last_error = '';
            return true;
        }

        return 0;
    }

    public function insert(string $table, array $data, $formats = null) {
        if (stripos($table, 'event_log') !== false) {
            $this->events[] = $data;
            $this->insert_id = count($this->events);
            return 1;
        }

        // clients insert
        $this->clientInsertAttempted = true;
        $this->clientInsert = $data;
        $this->last_error = '';
        $this->insert_id = 321;
        return 1;
    }

    public function update(string $table, array $data, array $where, $f1 = null, $f2 = null) {
        $this->last_error = '';
        return 1;
    }

    public function _real_escape($v) { return addslashes((string) $v); }
}

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

function fresh_wpdb(): IndexGuardWpdb {
    reset_index_flag();
    $wpdb = new IndexGuardWpdb();
    $GLOBALS['wpdb'] = $wpdb;
    return $wpdb;
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
        'delivery_day'        => 'WED AM',
        'payment_method'      => 'Cheque',
        'delivery_initials'   => 'ACL',
        'client_email'        => 'alex@example.com',
        'wordpress_user_id'   => '500',
    ], $overrides);
}

function events_with_outcome(IndexGuardWpdb $wpdb, string $outcome): array {
    return array_values(array_filter($wpdb->events, static function ($e) use ($outcome) {
        return ($e['outcome'] ?? '') === $outcome
            && ($e['subsystem'] ?? '') === 'client_form_index';
    }));
}

// ---------------------------------------------------------------------------
// T-1: save() SUCCEEDS even though the UNIQUE index over delivery_initials_index
// cannot be built (errno 1062). The hash column is still written; the abort that
// used to block every save is gone. A degraded Event-Log entry is recorded.
// ---------------------------------------------------------------------------
$wpdb = fresh_wpdb();
$wpdb->uniqueBuildFails = ['delivery_initials_index'];
$saved = MealsDB_Client_Form::save(valid_private_payload());
check($saved === true, 'T-1: save() succeeds despite unbuildable UNIQUE index (was: abort)');
check($wpdb->clientInsertAttempted === true, 'T-1: the client INSERT actually ran');
check(isset($wpdb->clientInsert['delivery_initials_index']), 'T-1: deterministic hash column still populated');
check(count(events_with_outcome($wpdb, 'degraded')) > 0, 'T-1/T-5: a degraded index Event-Log entry was recorded');

// ---------------------------------------------------------------------------
// T-2: the dedup find-by-index still works with NO unique constraint present.
// ---------------------------------------------------------------------------
$wpdb = fresh_wpdb();
$wpdb->hashOwner = ['individual_id_index' => 42];
$repo = new MealsDB_Clients_Repository();
$found = $repo->find_client_id_by_column('individual_id_index', str_repeat('a', 64));
check($found === 42, 'T-2: find_client_id_by_column resolves a match without a DB unique constraint');
$missing = $repo->find_client_id_by_column('requisition_id_index', str_repeat('b', 64));
check($missing === null, 'T-2: a non-colliding value returns null');

// ---------------------------------------------------------------------------
// T-3: two clients may share an individual_id. validate() stays VALID and
// surfaces a WARNING naming the other client; the save then proceeds.
// ---------------------------------------------------------------------------
$wpdb = fresh_wpdb();
$wpdb->hashOwner = ['individual_id_index' => 7]; // another client already has it
$validation = MealsDB_Client_Form::validate(valid_private_payload(['individual_id' => 'GOV-DUAL-1']));
check($validation['valid'] === true, 'T-3: a duplicate individual_id does NOT fail validation (allow-and-warn)');
check(!empty($validation['warnings']), 'T-3: a non-blocking warning is produced');
$warned = implode(' | ', $validation['warnings'] ?? []);
check(strpos($warned, '#7') !== false, 'T-3: the warning names the other client (#7): ' . $warned);
check(empty($validation['errors']), 'T-3: no hard error blocks the duplicate ID');

$wpdb = fresh_wpdb();
$wpdb->hashOwner = ['individual_id_index' => 7];
$savedDup = MealsDB_Client_Form::save(valid_private_payload(['individual_id' => 'GOV-DUAL-1']));
check($savedDup === true, 'T-3: the duplicate-ID client still persists (not blocked)');

// ---------------------------------------------------------------------------
// T-4: a hash COLUMN that is genuinely missing AND cannot be added still aborts
// the save — but with a clear, attributed message and NO insert, distinct from
// the (now non-fatal) constraint case. A failed Event-Log entry is recorded.
// ---------------------------------------------------------------------------
$wpdb = fresh_wpdb();
$wpdb->columns = ['requisition_id_index', 'vet_health_card_index', 'delivery_initials_index']; // individual_id_index absent
$wpdb->addColumnFails = ['individual_id_index']; // ... and cannot be created
$savedAbort = MealsDB_Client_Form::save(valid_private_payload());
check($savedAbort === false, 'T-4: save() aborts when a hash column cannot be ensured');
check($wpdb->clientInsertAttempted === false, 'T-4: no INSERT is attempted on a genuine index-column failure');
$abortMsg = MealsDB_Client_Form::last_save_error();
check($abortMsg !== '' && stripos($abortMsg, 'Database error occurred') === false,
    'T-4: the abort message is attributed, not the bare "Database error occurred.": ' . $abortMsg);
check(stripos($abortMsg, 'index column') !== false, 'T-4: the message points at the index-column problem');
check(count(events_with_outcome($wpdb, 'failed')) > 0, 'T-4/T-5: a failed index Event-Log entry was recorded');

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    echo implode("\n", $failures) . "\n";
    echo sprintf("FAILED: %d passed, %d failed\n", $passed, count($failures));
    exit(1);
}
echo "All {$passed} assertions passed.\n";
