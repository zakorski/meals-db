<?php
/**
 * MealsDB_Order_Audit service tests (spec 2026-07-30).
 * Run with: php tests/test-order-audit.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('MEALS_DB_KEY')) { define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32))); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__'))            { function __($t, $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 7; } }
if (!function_exists('current_user_can'))    { function current_user_can($c) { return true; } }
if (!function_exists('is_user_logged_in'))   { function is_user_logged_in() { return true; } }
if (!function_exists('get_option'))    { function get_option($k, $d = false) { return $d; } }
// Classification stub: product 100 is a Main, everything else a Side.
if (!function_exists('has_term')) {
    function has_term($term, $tax, $pid) { return (int) $pid === 100; }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $m;
        public function __construct($code = '', $message = '') { $this->m = $message; }
        public function get_error_message() { return $this->m; }
    }
}

if (!class_exists('wpdb')) { class wpdb {} }
class OAWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public array $rows = [];       // audit_id => stored row (assoc)
    public array $audit_log = [];  // captured MealsDB_Logger rows (raw SQL strings)
    private $next_id = 1;

    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        foreach ($args as $a) {
            $repl = is_int($a) || is_float($a) ? (string) $a : "'" . addslashes((string) $a) . "'";
            $sql = preg_replace('/%[sdf]/', str_replace('$', '\\$', $repl), $sql, 1);
        }
        return $sql;
    }
    /**
     * MealsDB_Logger::log() builds an INSERT via $wpdb->prepare() and then
     * executes it through $wpdb->query() (not insert()). Capture SQL strings
     * that target the audit_log table so tests can assert the write happened.
     */
    public function query($sql) {
        if (stripos((string) $sql, 'audit_log') !== false) {
            $this->audit_log[] = (string) $sql;
            return 1;
        }
        return 1;
    }
    public function insert($table, $data, $formats = null) {
        // insert() is NOT used by MealsDB_Logger::log() (it uses query()), but
        // keep the audit_log branch in case a future caller takes that path.
        if (stripos($table, 'audit_log') !== false) { $this->audit_log[] = $data; return 1; }
        if (stripos($table, 'event_log') !== false) { return 1; }
        $id = $this->next_id++;
        $this->insert_id = $id;
        $this->rows[$id] = array_merge(['audit_id' => $id], $data);
        return 1;
    }
    public function update($table, $data, $where, $f1 = null, $f2 = null) {
        $id = (int) ($where['audit_id'] ?? 0);
        if (!isset($this->rows[$id])) { return 0; }
        $this->rows[$id] = array_merge($this->rows[$id], $data);
        return 1;
    }
    public function delete($table, $where, $formats = null) {
        $id = (int) ($where['audit_id'] ?? 0);
        if (!isset($this->rows[$id])) { return 0; }
        unset($this->rows[$id]);
        return 1;
    }
    public function get_row($sql, $output = ARRAY_A) {
        if (preg_match("/week_start = '(\\d{4}-\\d{2}-\\d{2})'/", (string) $sql, $m)) {
            foreach ($this->rows as $r) {
                if (($r['week_start'] ?? '') === $m[1]) { return $r; }
            }
            return null;
        }
        if (preg_match('/audit_id = (\\d+)/', (string) $sql, $m)) {
            return $this->rows[(int) $m[1]] ?? null;
        }
        return null;
    }
    public function get_results($sql, $output = ARRAY_A) {
        $out = [];
        foreach ($this->rows as $r) { $x = $r; unset($x['payload']); $out[] = $x; }
        return $out;
    }
}

$failures = []; $passed = 0;
function oa_chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = 'FAIL: ' . $label; }
}
function oa_reset(): OAWpdb {
    $wpdb = new OAWpdb();
    $GLOBALS['wpdb'] = $wpdb;
    return $wpdb;
}

// Fixture: two orders as get_orders_for_delivery_range() returns them
// (order_id, wp_user_id, date_created_gmt, delivery_occurrence, items[]),
// plus the client rows keyed by wp_user_id the builder joins against.
function oa_orders(): array {
    return [
        [
            'order_id' => 501, 'wp_user_id' => 60, 'date_created_gmt' => '2026-07-20 10:00:00',
            'delivery_occurrence' => '2026-07-22',
            'items' => [
                ['order_item_id' => 1, 'order_item_name' => 'Beef Stew',  'wc_product_id' => 100, 'quantity' => 5],
                ['order_item_id' => 2, 'order_item_name' => 'Side Salad', 'wc_product_id' => 200, 'quantity' => 3],
                ['order_item_id' => 3, 'order_item_name' => 'Client Contribution', 'wc_product_id' => 5675, 'quantity' => 1],
            ],
        ],
        [
            'order_id' => 502, 'wp_user_id' => 61, 'date_created_gmt' => '2026-07-21 09:00:00',
            'delivery_occurrence' => '2026-07-23',
            'items' => [
                ['order_item_id' => 4, 'order_item_name' => 'Chicken Pie', 'wc_product_id' => 100, 'quantity' => 2],
            ],
        ],
    ];
}
function oa_clients(): array {
    return [
        60 => ['client_id' => 9, 'wp_user_id' => 60, 'first_name' => 'Pat', 'last_name' => 'Doe',
               'delivery_area_zone' => 'M', 'delivery_day' => 'wednesday', 'delivery_frequency' => 1],
        61 => ['client_id' => 10, 'wp_user_id' => 61, 'first_name' => 'Sam', 'last_name' => 'Roe',
               'delivery_area_zone' => 'S', 'delivery_day' => 'thursday', 'delivery_frequency' => 1],
    ];
}

// ---------------------------------------------------------------------------
// Task 3 checks: snapshot builder + create/get/list
// ---------------------------------------------------------------------------

// 1. build_rows_from_orders(): one row per order, summary counts classified
//    Main (product 100) vs Side, fee product 5675 excluded from items.
oa_reset();
$rows = MealsDB_Order_Audit::build_rows_from_orders(oa_orders(), oa_clients());
oa_chk(count($rows) === 2 && isset($rows[501], $rows[502]), '3.1: one row per order, keyed by order_id');
oa_chk($rows[501]['client_name'] === 'Pat Doe', '3.1: client name joined from client row');
oa_chk($rows[501]['delivery_date'] === '2026-07-22', '3.1: delivery date is the occurrence');
oa_chk($rows[501]['mains_count'] === 5 && $rows[501]['sides_count'] === 3, '3.1: mains/sides classified');
oa_chk(count($rows[501]['items']) === 2, '3.1: fee line (5675) excluded from items');
oa_chk($rows[501]['audit_status'] === 'pending', '3.1: rows start pending');
oa_chk($rows[501]['note'] === '' && $rows[501]['edited_items'] === [], '3.1: empty note/edits');

// Edge fixture A: order whose wp_user_id has no matching client row.
// build_rows_from_orders() must still emit a row; client_id defaults to 0
// and client_name to '' (the trim(' ') → '' path in the builder).
$edge_orders_a = [
    ['order_id' => 600, 'wp_user_id' => 999, 'date_created_gmt' => '2026-07-20 08:00:00',
     'delivery_occurrence' => '2026-07-22',
     'items' => [['order_item_id' => 9, 'order_item_name' => 'Beef Stew', 'wc_product_id' => 100, 'quantity' => 1]]],
];
$edge_rows_a = MealsDB_Order_Audit::build_rows_from_orders($edge_orders_a, oa_clients());
oa_chk(isset($edge_rows_a[600]), '3.1 edge-A: row emitted even when wp_user_id has no client');
oa_chk((int) ($edge_rows_a[600]['client_id'] ?? -1) === 0, '3.1 edge-A: client_id is 0');
oa_chk(($edge_rows_a[600]['client_name'] ?? 'x') === '', '3.1 edge-A: client_name is empty string');

// Edge fixture B: item with explicit quantity 0.
// The builder must still include the item in the items list (qty 0) and
// add 0 to the counts — it does not filter zero-quantity items.
$edge_orders_b = [
    ['order_id' => 601, 'wp_user_id' => 60, 'date_created_gmt' => '2026-07-20 08:00:00',
     'delivery_occurrence' => '2026-07-22',
     'items' => [['order_item_id' => 10, 'order_item_name' => 'Cancelled Main', 'wc_product_id' => 100, 'quantity' => 0]]],
];
$edge_rows_b = MealsDB_Order_Audit::build_rows_from_orders($edge_orders_b, oa_clients());
oa_chk(isset($edge_rows_b[601]), '3.1 edge-B: row emitted for order with zero-qty item');
oa_chk(count($edge_rows_b[601]['items']) === 1
    && (int) $edge_rows_b[601]['items'][0]['qty'] === 0, '3.1 edge-B: zero-qty item present in items with qty 0');
oa_chk((int) ($edge_rows_b[601]['mains_count'] ?? -1) === 0
    && (int) ($edge_rows_b[601]['sides_count'] ?? -1) === 0, '3.1 edge-B: zero-qty adds nothing to counts');

// 2. create_for_week(): persists encrypted payload, generated == current,
//    counts denormalized; get() round-trips.
$wpdb = oa_reset();
$audit_id = MealsDB_Order_Audit::create_for_week('2026-07-20', '2026-07-26', $rows);
oa_chk($audit_id === 1, '3.2: create returns audit_id');
$stored = $wpdb->rows[1];
oa_chk($stored['status'] === 'draft' && (int) $stored['row_count'] === 2, '3.2: draft with row_count 2');
oa_chk((int) $stored['confirmed_count'] === 0 && (int) $stored['edited_count'] === 0, '3.2: zero progress counts');
oa_chk(strpos((string) $stored['payload'], 'Pat Doe') === false, '3.2: payload is NOT plaintext (encrypted at rest)');
$loaded = MealsDB_Order_Audit::get(1);
oa_chk(is_array($loaded) && $loaded['payload']['generated'] == $loaded['payload']['current'],
    '3.2: get() decrypts; generated == current at creation');
oa_chk($loaded['payload']['current'][501]['client_name'] === 'Pat Doe', '3.2: row content round-trips');
// Audit-log write: create_for_week() calls MealsDB_Logger::log('order_audit_created', ...)
// which builds an INSERT via $wpdb->prepare() and executes it through $wpdb->query().
// OAWpdb::query() captures any SQL containing 'audit_log' into $this->audit_log[].
oa_chk(count($wpdb->audit_log) === 1, '3.2: exactly one audit-log write occurred on create');
oa_chk(stripos($wpdb->audit_log[0] ?? '', 'order_audit_created') !== false,
    '3.2: audit-log SQL references order_audit_created action');

// 3. find_by_week(): returns the existing audit_id; create is expected to be
//    guarded by the caller (AJAX) via find_by_week — one audit per week.
oa_chk(MealsDB_Order_Audit::find_by_week('2026-07-20') === 1, '3.3: find_by_week finds the audit');
oa_chk(MealsDB_Order_Audit::find_by_week('2026-01-05') === 0, '3.3: find_by_week returns 0 when absent');

// 4. Encryption failure → create returns 0 (fail closed, no plaintext row).
//    Feed a payload json_encode cannot serialize (invalid UTF-8) so
//    encode_payload returns false.
$wpdb = oa_reset();
$bad = [501 => ['broken' => "\xB1\x31"]];
$audit_id = MealsDB_Order_Audit::create_for_week('2026-07-27', '2026-08-02', $bad);
oa_chk($audit_id === 0 && empty($wpdb->rows), '3.4: unencodable payload → create fails closed, nothing stored');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
