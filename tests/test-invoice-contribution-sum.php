<?php
/**
 * DIRECTIVE K8 regression — the invoice client contribution is sourced from the
 * client record (meals_clients.client_contribution), NEVER from order line items.
 *
 * Before K8, get_phase2_billing_data summed a $1.00 contribution PRODUCT line on
 * the month's orders and used that as the deduction — so six SDNB clients billed
 * $1.00 instead of their real record figure (~$380/month over-billed), and the
 * number moved whenever unrelated order data changed. The order-based helpers
 * (sum_contribution_for_orders / contribution_orders_for_month) were deleted.
 *
 * This test asserts, through get_phase2_billing_data:
 *   1. contribution_cents equals the client record's client_contribution;
 *   2. NO order line-item / _line_subtotal query is ever issued for it;
 *   3. the monthly figure is billed in FULL (no proration).
 *
 * Run: php tests/test-invoice-contribution-sum.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!function_exists('get_option')) { function get_option($name, $default = false) { return $default; } }
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($d, $f = 0, $depth = 512) { $r = json_encode($d, $f, $depth); return $r === false ? false : $r; }
}

// resolve_hst_rate() reads the live WC rate (no fallback). 15% standard.
if (!class_exists('WC_Tax')) {
    class WC_Tax {
        public static function get_rates($tax_class = '') { return [['rate' => 15.0]]; }
        public static function find_rates($args = []) {
            $match = ($args['country'] ?? '') === 'CA' && ($args['state'] ?? '') === 'NB' && ($args['tax_class'] ?? '') === 'hst';
            return $match ? [1 => ['rate' => 15.0, 'label' => 'HST', 'shipping' => 'yes', 'compound' => 'no']] : [];
        }
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

// Mock wpdb: answers the allocation-summary get_results and the rate get_row.
// It RECORDS every SQL string so the test can prove no contribution order-line
// query is issued.
class K8Wpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public array $all_sql = [];
    public float $rate = 14.66;
    public int $summary_cid = 7; // which client the allocation summary is for
    public function insert($table, $data, $formats = null) { $this->insert_id++; return 1; }
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function get_results($q, $o = null) {
        $this->all_sql[] = (string) $q;
        if (stripos($q, 'meals_client_allocations') !== false) {
            return [['client_id' => $this->summary_cid, 'used_mains' => 25, 'used_sides' => 0, 'used_tax_sides' => 0, 'used_nontax_sides' => 0]];
        }
        return [];
    }
    public function get_row($q, $o = null) {
        $this->all_sql[] = (string) $q;
        if (stripos($q, 'rate') !== false) { return ['rate' => $this->rate, 'rate_amount' => $this->rate]; }
        return null;
    }
    public function get_var($q) { $this->all_sql[] = (string) $q; return null; }
    public function get_col($q) { $this->all_sql[] = (string) $q; return []; }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function call_p2(array $client_rows, string $billing_month) {
    $rm = new ReflectionMethod(MealsDB_Invoice_Generator::class, 'get_phase2_billing_data');
    $rm->setAccessible(true);
    return $rm->invoke(null, $client_rows, $billing_month);
}

$wpdb = new K8Wpdb();
$GLOBALS['wpdb'] = $wpdb;

// A client whose record holds $143.96 and 25 allocated mains.
$client = ['client_id' => 7, 'wp_user_id' => 50, 'default_rate_id' => 1,
           'client_contribution' => 143.96, 'first_name' => 'Leonard', 'last_name' => 'Corkum'];
$out = call_p2([$client], '2026-08');
$row = $out[7] ?? null;

chk(is_array($row), true, 'K8: row returned for a client with mains');
chk((int) ($row['contribution_cents'] ?? -1), 14396, 'K8: contribution_cents = $143.96 from the client record (Leonard Corkum)');

// (2) no order-line / _line_subtotal query was ever issued for the contribution.
$all = implode("\n", $wpdb->all_sql);
chk(stripos($all, '_line_subtotal') === false, true, 'K8: no _line_subtotal (order-line) query is issued');
chk(stripos($all, 'woocommerce_order_itemmeta') === false, true, 'K8: no order itemmeta query is issued for contribution');

// (3) billed in FULL — no proration. A record value with cents survives intact.
$client2 = ['client_id' => 9, 'wp_user_id' => 52, 'default_rate_id' => 1,
            'client_contribution' => 33.81, 'first_name' => 'Edward', 'last_name' => 'Belanger'];
$wpdb->summary_cid = 9;
$out2 = call_p2([$client2], '2026-08');
chk((int) ($out2[9]['contribution_cents'] ?? -1), 3381, 'K8: $33.81 billed in full, no proration (Edward Belanger)');

// A client with a zero/blank record contribution deducts nothing.
$client3 = ['client_id' => 11, 'wp_user_id' => 53, 'default_rate_id' => 1,
            'first_name' => 'No', 'last_name' => 'Contribution'];
$wpdb->summary_cid = 11;
$out3 = call_p2([$client3], '2026-08');
chk((int) ($out3[11]['contribution_cents'] ?? -1), 0, 'K8: absent client_contribution → 0 cents deducted');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
