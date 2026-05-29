<?php
/**
 * Directive MAJ-1 — duplicate wp_user_id: soft-warn a legitimate dual-program
 * case (without reintroducing mis-routing).
 *
 * The operator confirmed a legitimate case the code used to hard-block: a
 * person who is BOTH an SDNB recipient AND a Veteran maps to one WordPress
 * user across two client records. This suite proves:
 *
 *   T-1  Linking a WP user already linked to a DIFFERENT client now SUCCEEDS
 *        (no WP_Error) and the UPDATE runs — contrast the OLD hard refusal.
 *   T-2  That allowed-duplicate path emits a `degraded`
 *        `link_wp_user.duplicate_allowed` trunk event naming both clients.
 *   T-3  The order->client resolver routes a multi-client user by program:
 *        the order's mealsdb_rate_id pins exactly one client.
 *   T-4  A single-client user is returned unchanged regardless of the order
 *        (the 99% path is untouched).
 *   T-5  An ambiguous multi-client case (order pins no client) is LOGGED
 *        (`resolver.ambiguous_multi_client`, degraded), never silently
 *        arbitrary — for BOTH the allocation resolver and the fee resolver.
 *   T-6  No UNIQUE constraint is introduced on meals_clients.wp_user_id.
 *
 * Run with: php tests/test-link-wp-user-dual-program.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__'))            { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $t, $v, ...$a) { return $v; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 0; } }
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($d, $f = 0, $depth = 512) {
        $r = json_encode($d, $f, $depth);
        return $r === false ? false : $r;
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public string $code;
        public string $message;
        public function __construct(string $code = '', string $message = '') {
            $this->code = $code; $this->message = $message;
        }
        public function get_error_message(): string { return $this->message; }
        public function get_error_code(): string { return $this->code; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool { return $thing instanceof WP_Error; }
}
if (!class_exists('WP_User')) { class WP_User {} }
if (!function_exists('get_userdata')) {
    // The dual-program person is a real WP user.
    function get_userdata($id) { return $id > 0 ? new WP_User() : false; }
}

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
        public function get_col($query, $x = 0) { return []; }
        public function query($query) { return 0; }
        public function insert($table, $data, $formats = null) { return 1; }
    }
}

/**
 * One programmable mock that answers every query shape this directive touches:
 * the link path (SHOW COLUMNS / client_type / duplicate-check / UPDATE), the
 * resolvers (candidate client list / rate meta / rate->client match) and the
 * Event Log INSERTs (captured for assertions).
 */
class DualProgWpdb extends wpdb {
    public string $prefix = 'wp_';
    public array $events = [];          // captured event_log inserts
    public array $candidates = [];      // get_col -> candidate client_ids
    public int $rate_meta = 0;          // order's mealsdb_rate_id meta
    public int $rate_client = 0;        // client_id the rate resolves to
    public $existing_link = null;       // duplicate-check result (link path)
    public string $client_type = 'SDNB';
    private int $next_id = 1;

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
        if (stripos($sql, 'SHOW COLUMNS') !== false) {
            // meals_clients shape: PK + the WP-user link column + client_type.
            // Deliberately expose 'wp_user_id' (not 'wordpress_user_id') so the
            // link path's choose_column() picks it.
            return [
                ['Field' => 'client_id'],
                ['Field' => 'wp_user_id'],
                ['Field' => 'client_type'],
                ['Field' => 'first_name'],
            ];
        }
        return [];
    }

    public function get_col($query, $x = 0) {
        // Only the resolver's candidate lookup uses get_col.
        return $this->candidates;
    }

    public function get_var($sql, $x = 0, $y = 0) {
        if (stripos($sql, 'mealsdb_rate_id') !== false) { return $this->rate_meta; }
        if (stripos($sql, 'client_rates')   !== false) { return $this->rate_client; }
        if (stripos($sql, 'client_type')    !== false) { return $this->client_type; }
        if (strpos($sql, '<>')              !== false) { return $this->existing_link; }
        return null;
    }

    public function query($sql) {
        if (stripos($sql, 'UPDATE') === 0) { $this->rows_affected = 1; return 1; }
        return 1; // START TRANSACTION / COMMIT / ROLLBACK
    }

    public function insert($table, $data, $formats = null) {
        if (stripos($table, 'event_log') !== false) {
            $this->events[] = $data;
        }
        $this->insert_id = $this->next_id++;
        return 1;
    }

    /** Find the first captured event whose `event` column matches. */
    public function event(string $name): ?array {
        foreach ($this->events as $row) {
            if (($row['event'] ?? '') === $name) { return $row; }
        }
        return null;
    }
}

$failures = [];
$passed   = 0;
function assert_true($v, string $label) {
    global $failures, $passed;
    if ($v) { $passed++; return; }
    $failures[] = "FAIL: $label";
}
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s",
        $label, var_export($expected, true), var_export($actual, true));
}

// A minimal WC_Order stand-in for the fee-resolver test. The fee resolver
// type-hints WC_Order, so the stub must satisfy `instanceof WC_Order`.
if (!class_exists('WC_Order')) {
    class WC_Order {
        public int $__id = 0;
        public array $__meta = [];
        public function get_id() { return $this->__id; }
        public function get_meta($key) { return $this->__meta[$key] ?? ''; }
        public function get_customer_id() { return 0; }
    }
}
class DualProgOrder extends WC_Order {
    public function __construct(int $id, array $meta = []) { $this->__id = $id; $this->__meta = $meta; }
}

// ---------------------------------------------------------------------------
// T-1 / T-2 — duplicate link is ALLOWED and WARNED, not refused.
// ---------------------------------------------------------------------------
$wpdb = new DualProgWpdb();
$wpdb->existing_link = 7;            // WP user 100 already linked to client 7
$GLOBALS['wpdb'] = $wpdb;

$mut    = new MealsDB_Sync_Mutate();
$result = $mut->link_meals_client_to_wc_user(42, 100);

assert_equal(true, $result, 'T-1: linking a WP user already linked to a different client now SUCCEEDS (no WP_Error)');

$evt = $wpdb->event('link_wp_user.duplicate_allowed');
assert_true($evt !== null, 'T-2: an allowed-duplicate link emits link_wp_user.duplicate_allowed');
if ($evt !== null) {
    assert_equal('degraded', $evt['outcome'] ?? '', 'T-2: duplicate-allowed event outcome is degraded');
    $ctx = json_decode($evt['context'] ?? '{}', true);
    assert_equal(7, $ctx['existing_client'] ?? null, 'T-2: event context carries the existing client id');
    assert_equal(42, $ctx['new_client'] ?? null, 'T-2: event context carries the new client id');
}

// ---------------------------------------------------------------------------
// Reflection handle on the private allocation resolver helper.
// ---------------------------------------------------------------------------
$ref = new ReflectionMethod('MealsDB_Allocation_Engine', 'resolve_client_id_by_wp_user');
$ref->setAccessible(true);
function resolve_alloc(ReflectionMethod $ref, int $wp_user, int $order, bool $active): int {
    $engine = new MealsDB_Allocation_Engine();
    return (int) $ref->invoke($engine, $wp_user, $order, $active);
}

// ---------------------------------------------------------------------------
// T-3 — multi-client user routes by the order's rate (program signal).
// ---------------------------------------------------------------------------
$wpdb = new DualProgWpdb();
$wpdb->candidates = [55, 77];        // SDNB=55, Veteran=77 under one WP user
$GLOBALS['wpdb'] = $wpdb;

$wpdb->rate_meta = 333; $wpdb->rate_client = 77;   // a Veteran-program rate
assert_equal(77, resolve_alloc($ref, 100, 900, true),
    'T-3: order whose rate pins the Veteran client routes to the Veteran client');

$wpdb->rate_meta = 334; $wpdb->rate_client = 55;   // an SDNB-program rate
assert_equal(55, resolve_alloc($ref, 100, 901, true),
    'T-3: order whose rate pins the SDNB client routes to the SDNB client');
assert_equal(0, count($wpdb->events),
    'T-3: a rate-resolved route emits NO ambiguity event');

// ---------------------------------------------------------------------------
// T-4 — single-client user is unchanged (program signal irrelevant).
// ---------------------------------------------------------------------------
$wpdb = new DualProgWpdb();
$wpdb->candidates = [55];            // exactly one client
$wpdb->rate_meta  = 0;               // no rate on the order
$GLOBALS['wpdb'] = $wpdb;

assert_equal(55, resolve_alloc($ref, 100, 902, true),
    'T-4: a single-client user returns that client regardless of the rate param');
assert_equal(0, count($wpdb->events),
    'T-4: the single-client path emits no event');

// ---------------------------------------------------------------------------
// T-5 — ambiguous multi-client is LOGGED (degraded), not silently arbitrary.
// ---------------------------------------------------------------------------
$wpdb = new DualProgWpdb();
$wpdb->candidates = [55, 77];
$wpdb->rate_meta  = 0;               // order pins nothing
$GLOBALS['wpdb'] = $wpdb;

$chosen = resolve_alloc($ref, 100, 903, true);
assert_equal(55, $chosen, 'T-5: ambiguous resolve still returns a deterministic first-row fallback');
$evt = $wpdb->event('resolver.ambiguous_multi_client');
assert_true($evt !== null, 'T-5: ambiguous multi-client resolve emits resolver.ambiguous_multi_client');
if ($evt !== null) {
    assert_equal('degraded', $evt['outcome'] ?? '', 'T-5: ambiguity event outcome is degraded');
}

// T-5 (fee resolver mirror) — same guarantee on the order-fees path.
$wpdb = new DualProgWpdb();
$wpdb->candidates = [55, 77];
$GLOBALS['wpdb'] = $wpdb;

$fee_ref = new ReflectionMethod('MealsDB_Order_Fees', 'resolve_client_id_by_wp_user');
$fee_ref->setAccessible(true);

// Rate pins the Veteran client -> deterministic, no event.
$order_rate = new DualProgOrder(910, ['mealsdb_rate_id' => 77]);
$wpdb->rate_client = 77;
assert_equal(77, (int) $fee_ref->invoke(null, 100, $order_rate),
    'T-5(fee): fee resolver routes a multi-client user by the order rate');

// No rate -> ambiguous -> logged.
$order_norate = new DualProgOrder(911, []);
$before = count($wpdb->events);
assert_equal(55, (int) $fee_ref->invoke(null, 100, $order_norate),
    'T-5(fee): unresolvable fee route falls back to the first client');
$evt = $wpdb->event('resolver.ambiguous_multi_client');
assert_true($evt !== null && count($wpdb->events) > $before,
    'T-5(fee): unresolvable fee route emits resolver.ambiguous_multi_client');

// ---------------------------------------------------------------------------
// T-6 — schema must NOT declare wp_user_id UNIQUE (re-imposing the hard block).
// ---------------------------------------------------------------------------
$schema  = MealsDB_Schema::get_canonical_schema();
$clients = $schema[MealsDB_Tables::CLIENTS] ?? [];
$wp_user_unique = false;
foreach (($clients['indexes'] ?? []) as $idx) {
    if (($idx['columns'] ?? []) === ['wp_user_id'] && strtoupper((string) ($idx['type'] ?? '')) === 'UNIQUE') {
        $wp_user_unique = true;
    }
}
assert_equal(false, $wp_user_unique, 'T-6: meals_clients.wp_user_id is not declared UNIQUE');

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
