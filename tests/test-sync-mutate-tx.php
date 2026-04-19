<?php
/**
 * Tests for transaction wrapping in MealsDB_Sync_Mutate::update_meals_client
 * and create_meals_client.
 *
 * Locks in the START-TRANSACTION / COMMIT / ROLLBACK pattern so a
 * future edit that adds a second write (audit row, related-table
 * update) inherits atomicity automatically.
 *
 * Run with: php tests/test-sync-mutate-tx.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default') { return $text; }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args) { return $value; }
}
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
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

// Fallback wpdb shim for runs outside WordPress.
if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public int $insert_id = 0;
        public int $rows_affected = 0;
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $output = 'OBJECT') { return []; }
        public function get_row($query, $output = 'OBJECT', $y = 0) { return null; }
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function query($query) { return 0; }
    }
}

/**
 * Records every query in order. Responds to the shapes we expect
 * Sync_Mutate to issue:
 *  - SHOW COLUMNS for get_table_columns()
 *  - SELECT `client_type` for get_client_type()
 *  - START TRANSACTION / COMMIT / ROLLBACK
 *  - UPDATE / INSERT
 */
class SyncTxTest_Wpdb extends wpdb {
    public array $query_log = [];
    public bool $fail_mutation = false;
    public bool $fail_commit = false;
    public string $client_type = 'SDNB';

    public function __construct() { /* no parent */ }

    public function prepare($query, ...$args) {
        if (!empty($args) && is_array($args[0] ?? null)) { $args = $args[0]; }
        $out = $query;
        foreach ($args as $a) {
            $out = preg_replace('/%d|%s/', is_int($a) ? (string) $a : "'" . addslashes((string) $a) . "'", $out, 1);
        }
        return $out;
    }

    public function get_results($sql, $output = 'OBJECT') {
        $this->query_log[] = ['m' => 'get_results', 'sql' => $sql];
        if (stripos($sql, 'SHOW COLUMNS') !== false) {
            // Describe meals_clients with the minimum columns the sync
            // mutator needs: the PK ('client_id') plus one non-encrypted
            // column to touch via update/insert.
            return [
                ['Field' => 'client_id'],
                ['Field' => 'first_name'],
                ['Field' => 'last_name'],
                ['Field' => 'client_type'],
                ['Field' => 'client_email'],
            ];
        }
        return [];
    }

    public function get_var($sql, $x = 0, $y = 0) {
        $this->query_log[] = ['m' => 'get_var', 'sql' => $sql];
        if (stripos($sql, 'client_type') !== false) {
            return $this->client_type;
        }
        return null;
    }

    public function query($sql) {
        $this->query_log[] = ['m' => 'query', 'sql' => $sql];
        if (stripos($sql, 'START TRANSACTION') === 0) { return 1; }
        if (stripos($sql, 'COMMIT')  === 0) { return $this->fail_commit ? false : 1; }
        if (stripos($sql, 'ROLLBACK') === 0) { return 1; }
        if ((stripos($sql, 'UPDATE') === 0 || stripos($sql, 'INSERT') === 0) && $this->fail_mutation) {
            $this->last_error = 'forced failure';
            return false;
        }
        $this->insert_id = 99;
        return 1;
    }

    public function keywords_in_order(): array {
        $found = [];
        foreach ($this->query_log as $row) {
            $sql = strtoupper($row['sql']);
            foreach (['START TRANSACTION', 'COMMIT', 'ROLLBACK', 'UPDATE ', 'INSERT INTO'] as $kw) {
                if (strpos($sql, $kw) !== false) {
                    $found[] = trim($kw);
                    break;
                }
            }
        }
        return $found;
    }
}

$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}

function make_mutator(SyncTxTest_Wpdb $wpdb): MealsDB_Sync_Mutate {
    $GLOBALS['wpdb'] = $wpdb;
    return new MealsDB_Sync_Mutate();
}

// ---------------------------------------------------------------------------
// update_meals_client happy path: START TRANSACTION -> UPDATE -> COMMIT.
// ---------------------------------------------------------------------------
$wpdb = new SyncTxTest_Wpdb();
$mut  = make_mutator($wpdb);

assert_equal(true, $mut->update_meals_client(42, ['first_name' => 'Alice']), 'update_meals_client returns true on success');
assert_equal(['START TRANSACTION', 'UPDATE', 'COMMIT'], $wpdb->keywords_in_order(), 'update issues BEGIN -> UPDATE -> COMMIT in order');

// ---------------------------------------------------------------------------
// update_meals_client UPDATE fails: START TRANSACTION -> UPDATE -> ROLLBACK.
// ---------------------------------------------------------------------------
$wpdb = new SyncTxTest_Wpdb();
$wpdb->fail_mutation = true;
$mut  = make_mutator($wpdb);

assert_equal(false, $mut->update_meals_client(42, ['first_name' => 'Alice']), 'update returns false when UPDATE fails');
assert_equal(['START TRANSACTION', 'UPDATE', 'ROLLBACK'], $wpdb->keywords_in_order(), 'update rolls back when UPDATE fails');

// ---------------------------------------------------------------------------
// update_meals_client COMMIT fails: ROLLBACK follows the failed COMMIT.
// ---------------------------------------------------------------------------
$wpdb = new SyncTxTest_Wpdb();
$wpdb->fail_commit = true;
$mut  = make_mutator($wpdb);

assert_equal(false, $mut->update_meals_client(42, ['first_name' => 'Alice']), 'update returns false when COMMIT fails');
assert_equal(['START TRANSACTION', 'UPDATE', 'COMMIT', 'ROLLBACK'], $wpdb->keywords_in_order(), 'update rolls back when COMMIT fails');

// ---------------------------------------------------------------------------
// create_meals_client happy path: START TRANSACTION -> INSERT -> COMMIT,
// returning the stubbed insert_id.
// ---------------------------------------------------------------------------
$wpdb = new SyncTxTest_Wpdb();
$mut  = make_mutator($wpdb);

assert_equal(99, $mut->create_meals_client(['client_type' => 'SDNB', 'first_name' => 'A', 'last_name' => 'B']), 'create returns insert_id');
assert_equal(['START TRANSACTION', 'INSERT INTO', 'COMMIT'], $wpdb->keywords_in_order(), 'create issues BEGIN -> INSERT -> COMMIT in order');

// ---------------------------------------------------------------------------
// create_meals_client INSERT fails: START TRANSACTION -> INSERT -> ROLLBACK.
// ---------------------------------------------------------------------------
$wpdb = new SyncTxTest_Wpdb();
$wpdb->fail_mutation = true;
$mut  = make_mutator($wpdb);

assert_equal(false, $mut->create_meals_client(['client_type' => 'SDNB', 'first_name' => 'A']), 'create returns false when INSERT fails');
assert_equal(['START TRANSACTION', 'INSERT INTO', 'ROLLBACK'], $wpdb->keywords_in_order(), 'create rolls back when INSERT fails');

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
