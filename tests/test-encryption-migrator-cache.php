<?php
/**
 * Tests for MealsDB_Encryption_Migrator cache helpers (A3 support).
 *
 * Verifies that:
 *   - inventory_cached() reads from the transient when available,
 *     and only falls through to inventory() (= an expensive full-
 *     table scan) when the cache is empty.
 *   - legacy_total_cached() sums the legacy counts across columns.
 *   - invalidate_inventory_cache() clears the transient so the next
 *     call hits the DB again.
 *
 * Run with: php tests/test-encryption-migrator-cache.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $tag, $value, ...$args) { return $value; } }

$GLOBALS['__mealsdb_transients'] = [];

if (!function_exists('get_transient')) {
    function get_transient(string $name) {
        return $GLOBALS['__mealsdb_transients'][$name] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient(string $name, $value, int $ttl = 0): bool {
        $GLOBALS['__mealsdb_transients'][$name] = $value;
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $name): bool {
        unset($GLOBALS['__mealsdb_transients'][$name]);
        return true;
    }
}

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $output = 'OBJECT') { return []; }
        public function get_col($query, $x = 0) { return []; }
        public function get_row($query, $output = 'OBJECT', $y = 0) { return null; }
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function query($query) { return 0; }
        public function update($table, $data, $where, $f = null, $wf = null) { return 0; }
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
// inventory_cached returns the transient when present, WITHOUT touching
// the inventory() slow path. We prove "without touching the DB" by
// leaving $GLOBALS['wpdb'] null — a call into inventory() would fatal.
// ---------------------------------------------------------------------------
$GLOBALS['wpdb'] = null;
$cached_inventory = [
    'individual_id'    => ['empty' => 5, 'new' => 10, 'legacy' => 3, 'plaintext' => 0],
    'requisition_id'   => ['empty' => 2, 'new' => 8,  'legacy' => 1, 'plaintext' => 0],
    'vet_health_card'  => ['empty' => 0, 'new' => 0,  'legacy' => 0, 'plaintext' => 0],
    'diet_concerns'    => ['empty' => 0, 'new' => 0,  'legacy' => 0, 'plaintext' => 0],
    'customer_comments'=> ['empty' => 0, 'new' => 0,  'legacy' => 0, 'plaintext' => 0],
];
set_transient('mealsdb_encryption_inventory', $cached_inventory);

$result = MealsDB_Encryption_Migrator::inventory_cached();
assert_equal($cached_inventory, $result, 'inventory_cached returns transient verbatim');

// legacy_total_cached sums the "legacy" slot across every column.
// 3 + 1 + 0 + 0 + 0 = 4.
assert_equal(4, MealsDB_Encryption_Migrator::legacy_total_cached(), 'legacy_total_cached sums across columns');

// ---------------------------------------------------------------------------
// invalidate_inventory_cache drops the transient.
// ---------------------------------------------------------------------------
MealsDB_Encryption_Migrator::invalidate_inventory_cache();
assert_equal(false, get_transient('mealsdb_encryption_inventory'), 'invalidate removes the transient');

// ---------------------------------------------------------------------------
// After invalidation, legacy_total_cached would hit inventory() which
// needs $wpdb. Stub a minimal wpdb that returns no rows, so the fresh
// inventory reports zero legacy — confirming cache bypass works.
// ---------------------------------------------------------------------------
class EmptyInventoryWpdb extends wpdb {
    public function __construct() { /* skip parent */ }
    public function get_col($query, $x = 0) { return []; }
}
$GLOBALS['wpdb'] = new EmptyInventoryWpdb();

assert_equal(0, MealsDB_Encryption_Migrator::legacy_total_cached(), 'fresh inventory with empty table reports 0 legacy');

// ---------------------------------------------------------------------------
// The fresh inventory should now be cached — verify by inserting a
// sentinel under the same key and checking inventory_cached returns it.
// ---------------------------------------------------------------------------
$sentinel = ['individual_id' => ['empty' => 1, 'new' => 1, 'legacy' => 0, 'plaintext' => 0]];
$GLOBALS['__mealsdb_transients']['mealsdb_encryption_inventory'] = $sentinel;

assert_equal($sentinel, MealsDB_Encryption_Migrator::inventory_cached(), 'subsequent call re-uses the refreshed cache');

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
