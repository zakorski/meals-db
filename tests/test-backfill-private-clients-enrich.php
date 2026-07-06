<?php
/**
 * MealsDB_Migration_Consolidated::enrich_existing fills blank
 * columns on existing Private rows from usermeta + the user's most
 * recent qualifying WC order, and never overwrites an admin-set
 * value. Encrypted columns (customer_comments, diet_concerns) are
 * encrypted before update.
 *
 * Run with: php tests/test-backfill-private-clients-enrich.php
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
        /** @var array<int, array<string, mixed>> Existing meals_clients rows. */
        public array $clients = [];
        /** @var array<int, int> wp_user_id => recent order id seeded for the order-lookup join. */
        public array $recent_order_ids = [];
        /** @var array<int, array{data:array, where:array}> Update calls captured for assertions. */
        public array $updates = [];

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
        public function get_row($query, $output = OBJECT) { return null; }
        public function get_var($query, $x = 0, $y = 0) {
            if (stripos($query, 'information_schema') !== false) {
                return 1;
            }
            return null;
        }
        public function get_col($query, $x = 0) { return []; }
        public function get_results($query, $output = OBJECT) {
            // Private clients enumeration.
            if (stripos($query, "client_type = 'Private'") !== false) {
                return $this->clients;
            }
            // Recent-order-per-user lookup.
            if (stripos($query, 'recent_order_id') !== false) {
                $rows = [];
                foreach ($this->recent_order_ids as $uid => $oid) {
                    $rows[] = ['customer_id' => (string) $uid, 'recent_order_id' => (string) $oid];
                }
                return $rows;
            }
            return [];
        }
        public function query($query) { return 0; }
        public function insert($table, $data) { return 1; }
        public function update($table, $data, $where) {
            $this->updates[] = ['data' => $data, 'where' => $where];
            return 1;
        }
    }
}
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
}

if (!class_exists('WP_User')) {
    class WP_User {
        public $user_email;
        public $first_name;
        public $last_name;
        public function __construct(int $id) {
            $this->user_email = 'u' . $id . '@example.com';
            $this->first_name = 'First' . $id;
            $this->last_name  = 'Last' . $id;
        }
    }
}
if (!function_exists('get_userdata')) {
    function get_userdata($id) { return $id > 0 ? new WP_User((int)$id) : null; }
}
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('current_time')) {
    function current_time($t) { return '2026-04-25 12:00:00'; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($v) { return json_encode($v); }
}

// Per-user usermeta fixtures keyed by uid → key → value.
$GLOBALS['enrich_meta'] = [
    101 => [
        'billing_address_1'       => '12 Maple St',
        'billing_city'            => 'Moncton',
        'billing_state'           => 'NB',
        'billing_postcode'        => 'E1A 1A1',
        'shipping_address_1'      => '12 Maple St',
        'shipping_address_2'      => 'Zone 3',
        'shipping_city'           => 'Moncton',
        'shipping_state'          => 'NB',
        'shipping_postcode'       => 'E1A 1A1',
        'payment_method'          => 'Stripe',
        'ordering_frequency'      => '7',
        'delivery_frequency'      => '14',
        'freeze_capacity'         => 'Medium',
        'delivery_fee'            => '5.50',
        'customer_comments'       => 'Front porch only',
        'dietary_needs'           => 'No nuts',
        'ordering_contact_method' => 'Phone',
        'nickname'                => 'abc',
    ],
    // 102 has no usermeta — enrichment should skip the row entirely.
    102 => [],
];
if (!function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single = true) {
        return $GLOBALS['enrich_meta'][$uid][$key] ?? '';
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order {
        // Stub returns empty strings — the test exercises the
        // usermeta-fallback path for enrichment, since existing rows
        // typically pre-date a triggering order.
        public function get_id(): int { return 999; }
        public function get_billing_first_name(): string { return ''; }
        public function get_billing_last_name(): string { return ''; }
        public function get_billing_phone(): string { return ''; }
        public function get_billing_address_1(): string { return ''; }
        public function get_billing_city(): string { return ''; }
        public function get_billing_state(): string { return ''; }
        public function get_billing_postcode(): string { return ''; }
        public function get_shipping_address_1(): string { return ''; }
        public function get_shipping_address_2(): string { return ''; }
        public function get_shipping_city(): string { return ''; }
        public function get_shipping_state(): string { return ''; }
        public function get_shipping_postcode(): string { return ''; }
    }
}
if (!function_exists('wc_get_order')) {
    function wc_get_order($id) { return new WC_Order(); }
}

global $wpdb;
$wpdb = new wpdb();

// Row 101: skeleton with everything blank — should pick up address,
// zone, service / notes, numerics from usermeta.
// Row 102: skeleton with no usermeta — should be skipped.
// Row 103: has a manually-set delivery_fee and payment_method that
// must NOT be overwritten, but its address is blank and should fill.
$wpdb->clients = [
    [
        'client_id'              => 1,
        'wp_user_id'             => 101,
        'client_type'            => 'Private',
        'first_name'             => 'First101',
        'last_name'              => 'Last101',
        'client_email'           => 'u101@example.com',
        'client_phone_1'         => '',
        'street_name'            => null,
        'city'                   => null,
        'province'               => null,
        'postal_code'            => null,
        'delivery_street_name'   => null,
        'delivery_city'          => null,
        'delivery_province'      => null,
        'delivery_postal_code'   => null,
        'delivery_area_name'     => null,
        'payment_method'         => null,
        'ordering_frequency'     => null,
        'delivery_frequency'     => null,
        'ordering_contact_method'=> null,
        'freezer_capacity'       => null,
        'delivery_fee'           => null,
        'customer_comments'      => null,
        'diet_concerns'          => null,
        // delivery_initials column is NOT NULL DEFAULT '' — empty
        // string represents an unfilled skeleton.
        'delivery_initials'      => '',
    ],
    [
        'client_id'              => 2,
        'wp_user_id'             => 102,
        'client_type'            => 'Private',
        'first_name'             => 'First102',
        'last_name'              => 'Last102',
        'client_email'           => 'u102@example.com',
        'client_phone_1'         => '',
        'street_name'            => null,
        'city'                   => null,
        'province'               => null,
        'postal_code'            => null,
        'delivery_street_name'   => null,
        'delivery_city'          => null,
        'delivery_province'      => null,
        'delivery_postal_code'   => null,
        'delivery_area_name'     => null,
        'payment_method'         => null,
        'ordering_frequency'     => null,
        'delivery_frequency'     => null,
        'ordering_contact_method'=> null,
        'freezer_capacity'       => null,
        'delivery_fee'           => null,
        'customer_comments'      => null,
        'diet_concerns'          => null,
        'delivery_initials'      => '',
    ],
    [
        'client_id'              => 3,
        'wp_user_id'             => 101, // re-use the populated meta fixture
        'client_type'            => 'Private',
        'first_name'             => 'First101b',
        'last_name'              => 'Last101b',
        'client_email'           => 'u101@example.com',
        'client_phone_1'         => '',
        'street_name'            => null,
        'city'                   => null,
        'province'               => null,
        'postal_code'            => null,
        'delivery_street_name'   => null,
        'delivery_city'          => null,
        'delivery_province'      => null,
        'delivery_postal_code'   => null,
        'delivery_area_name'     => null,
        // Admin-set values that must NOT be overwritten.
        'payment_method'         => 'Cash',
        'delivery_fee'           => '12.00',
        'ordering_frequency'     => null,
        'delivery_frequency'     => null,
        'ordering_contact_method'=> null,
        'freezer_capacity'       => null,
        'customer_comments'      => null,
        'diet_concerns'          => null,
        // Admin already set the bag initials — must not be overwritten.
        'delivery_initials'      => 'XYZ',
    ],
];

$failures = [];
$passed = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}
function assert_true($cond, string $label) {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = "FAIL: $label";
}

// Dry run: counts changes but performs no UPDATEs.
$dry_stats = MealsDB_Migration_Consolidated::enrich_existing(true);
assert_equal(3, $dry_stats['scanned'], 'dry run scanned = 3');
assert_equal(2, $dry_stats['enriched'], 'dry run enriched = 2 (rows 1 and 3 have something to fill)');
assert_equal(1, $dry_stats['skipped'], 'dry run skipped = 1 (row 2 has no usermeta source)');
assert_equal(0, $dry_stats['errors'], 'dry run errors = 0');
assert_equal(0, count($wpdb->updates), 'dry run issued no UPDATEs');

// Live run: writes UPDATEs.
$live_stats = MealsDB_Migration_Consolidated::enrich_existing(false);
assert_equal(3, $live_stats['scanned'], 'live scanned = 3');
assert_equal(2, $live_stats['enriched'], 'live enriched = 2');
assert_equal(1, $live_stats['skipped'], 'live skipped = 1');
assert_equal(0, $live_stats['errors'], 'live errors = 0');
assert_equal(2, count($wpdb->updates), 'live issued 2 UPDATEs');

// Verify the first UPDATE filled all the blank columns on row 1.
$row1_update = null;
foreach ($wpdb->updates as $u) {
    if (($u['where']['client_id'] ?? null) === 1) { $row1_update = $u; break; }
}
assert_true($row1_update !== null, 'row 1 was updated');

$d1 = $row1_update['data'] ?? [];
assert_equal('12 Maple St', $d1['street_name'] ?? null, 'row1 street_name from billing_address_1');
assert_equal('Moncton', $d1['city'] ?? null, 'row1 city from billing_city');
assert_equal('NB', $d1['province'] ?? null, 'row1 province from billing_state');
// U09-clients-repo-10: intake normalises postal to the form-valid A1A1A1 shape.
assert_equal('E1A1A1', $d1['postal_code'] ?? null, 'row1 postal_code normalized from billing_postcode');
assert_equal('Zone 3', $d1['delivery_area_name'] ?? null, 'row1 delivery_area_name from shipping_address_2');
assert_equal('Stripe', $d1['payment_method'] ?? null, 'row1 payment_method from usermeta');
assert_equal(7, $d1['ordering_frequency'] ?? null, 'row1 ordering_frequency cast to int');
assert_equal(14, $d1['delivery_frequency'] ?? null, 'row1 delivery_frequency cast to int');
assert_equal('Medium', $d1['freezer_capacity'] ?? null, 'row1 freezer_capacity from freeze_capacity meta');
assert_equal('5.50', $d1['delivery_fee'] ?? null, 'row1 delivery_fee normalised to 2dp');
assert_equal('Phone', $d1['ordering_contact_method'] ?? null, 'row1 ordering_contact_method from usermeta');
assert_true(isset($d1['customer_comments']) && $d1['customer_comments'] !== 'Front porch only', 'row1 customer_comments encrypted before update');
assert_true(isset($d1['diet_concerns']) && $d1['diet_concerns'] !== 'No nuts', 'row1 diet_concerns encrypted before update');
assert_equal('ABC', $d1['delivery_initials'] ?? null, 'row1 delivery_initials uppercased from nickname meta');

// client_phone_1 was '' on the existing row but billing_phone meta is
// also unset — first_name etc. on row 1 were already populated. None
// of those should appear in the update payload (already non-blank).
assert_true(!array_key_exists('first_name', $d1), 'row1 update does NOT touch already-populated first_name');
assert_true(!array_key_exists('last_name', $d1), 'row1 update does NOT touch already-populated last_name');
assert_true(!array_key_exists('client_email', $d1), 'row1 update does NOT touch already-populated client_email');

// Row 3: existing payment_method='Cash' and delivery_fee='12.00' must
// NOT be overwritten by the Stripe / 5.50 from usermeta.
$row3_update = null;
foreach ($wpdb->updates as $u) {
    if (($u['where']['client_id'] ?? null) === 3) { $row3_update = $u; break; }
}
assert_true($row3_update !== null, 'row 3 was updated');

$d3 = $row3_update['data'] ?? [];
assert_true(!array_key_exists('payment_method', $d3), 'row3 admin-set payment_method preserved');
assert_true(!array_key_exists('delivery_fee', $d3), 'row3 admin-set delivery_fee preserved');
assert_true(!array_key_exists('delivery_initials', $d3), 'row3 admin-set delivery_initials preserved');
// But blank columns ARE filled.
assert_equal('12 Maple St', $d3['street_name'] ?? null, 'row3 still gets blank street_name filled');
assert_equal('Zone 3', $d3['delivery_area_name'] ?? null, 'row3 still gets blank delivery_area_name filled');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
