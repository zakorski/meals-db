<?php
/**
 * allocate_order() early-returns when the resolved client is Private.
 * Private customers have no monthly allowances; running them through
 * the engine would create zero-filled allocation rows.
 *
 * The test drives the engine with a stub wpdb that:
 *   - returns a valid client_id for the initial customer_id lookup
 *   - reports client_type = 'Private' for the guard query we added in Phase S
 * After the guard returns, no further order-date / order-items work
 * should happen. We assert by counting total queries issued.
 *
 * Run with: php tests/test-allocation-engine-skips-private.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public int $query_count = 0;
        public int $post_guard_queries = 0;
        public bool $past_guard = false;
        public function prepare($query, ...$args) {
            if (empty($args)) return $query;
            $flat = $args;
            if (count($flat) === 1 && is_array($flat[0])) {
                $flat = $flat[0];
            }
            foreach ($flat as $arg) {
                $pos = strpos($query, '%d');
                if ($pos === false) $pos = strpos($query, '%s');
                if ($pos === false) break;
                $query = substr($query, 0, $pos) . (is_int($arg) ? (string)$arg : "'" . addslashes((string)$arg) . "'") . substr($query, $pos + 2);
            }
            return $query;
        }
        public function get_var($query, $x = 0, $y = 0) {
            $this->query_count++;
            if ($this->past_guard) { $this->post_guard_queries++; }

            if (stripos($query, 'information_schema') !== false) {
                return 1;
            }
            if (stripos($query, 'mealsdb_client_id') !== false) {
                // Meta lookup: no client_id in meta.
                return null;
            }
            if (stripos($query, 'mealsdb_client_user_id') !== false) {
                return null;
            }
            if (stripos($query, 'SELECT customer_id FROM') !== false) {
                // Native WC customer_id: return 501.
                return 501;
            }
            if (stripos($query, 'SELECT client_id FROM') !== false) {
                return 42;
            }
            if (stripos($query, 'SELECT client_type FROM') !== false) {
                // Phase S guard hits this query. Mark that we got here;
                // any subsequent get_var is an "after guard" leak.
                $this->past_guard = true;
                return 'Private';
            }
            return null;
        }
        public function get_row($query, $output = OBJECT) {
            $this->query_count++;
            if ($this->past_guard) { $this->post_guard_queries++; }
            return null;
        }
        public function get_col($query, $x = 0) {
            $this->query_count++;
            if ($this->past_guard) { $this->post_guard_queries++; }
            // MAJ-1: the customer_id fallback now resolves the candidate
            // client list via get_col (deterministic multi-client routing).
            // A single candidate (501 -> client 42) returns straight away.
            if (stripos($query, 'SELECT client_id FROM') !== false) {
                return [42];
            }
            return [];
        }
        public function get_results($query, $output = OBJECT) {
            $this->query_count++;
            if ($this->past_guard) { $this->post_guard_queries++; }
            return [];
        }
        public function query($query) {
            $this->query_count++;
            if ($this->past_guard) { $this->post_guard_queries++; }
            return 0;
        }
        public function insert($table, $data) { return 1; }
    }
}
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
}
if (!function_exists('__')) { function __($t, $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $v, ...$a) { return $v; } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }

global $wpdb;
$wpdb = new wpdb();

$failures = [];
$passed = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}

// Stub the WC order query the engine's constructor depends on.
if (!class_exists('MealsDB_WC_Order_Query_Test_Stub')) {
    class MealsDB_WC_Order_Query_Test_Stub extends MealsDB_WC_Order_Query {
        public function __construct() { /* bypass ctor */ }
        public function get_order_items(array $ids): array { return []; }
        public function get_orders_with_items_for_users(array $a, string $b, string $c, array $excl = []): array { return []; }
        public function get_product_types_for_ids(array $ids): array { return []; }
    }
}

// Instantiate the engine via reflection (its ctor needs real deps).
$engine = (new ReflectionClass('MealsDB_Allocation_Engine'))->newInstanceWithoutConstructor();
// Inject wpdb and order_query.
foreach ((new ReflectionClass('MealsDB_Allocation_Engine'))->getProperties() as $p) {
    $p->setAccessible(true);
    $name = $p->getName();
    if ($name === 'wpdb') {
        $p->setValue($engine, $wpdb);
    } elseif ($name === 'order_query') {
        $p->setValue($engine, new MealsDB_WC_Order_Query_Test_Stub());
    }
}

$engine->allocate_order(999);

// The guard fires right after the client_id is resolved via the
// customer_id fallback. The SELECT client_type query marks the
// boundary; no further get_var / get_results should run.
assert_equal(true, $wpdb->past_guard, 'guard query ran (SELECT client_type)');
assert_equal(0, $wpdb->post_guard_queries, 'no queries issued after the Private guard');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
