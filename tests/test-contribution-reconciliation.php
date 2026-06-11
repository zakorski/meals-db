<?php
/**
 * BC-4 regression tests — contribution reconciliation must not report false
 * discrepancies.
 *
 * Two bugs the fix closes:
 *   (1) "expected" was one flat monthly contribution but "actual paid" was
 *       summed over an ARBITRARY date range, so a multi-month range reported
 *       everyone as 2x/3x over- or under-paid. The contribution is a per-
 *       billing-month charge → restrict the report to a single calendar month
 *       (reject multi-month ranges).
 *   (2) No client_type filter: a Private client with a non-zero
 *       client_contribution column (which the fee engine never bills) showed
 *       as permanently "underpaid". → only SDNB/Veteran.
 *
 * Run: php tests/test-contribution-reconciliation.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!function_exists('current_user_can'))  { function current_user_can($c) { return true; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!function_exists('apply_filters'))     { function apply_filters($tag, $value, ...$a) { return $value; } }
if (!function_exists('__'))                { function __($t, $d = 'default') { return $t; } }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

class BC4FakeWpdb extends wpdb {
    public $prefix = 'wp_';
    public array $queries = [];
    public array $clients = [];
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function get_results($q, $o = null) {
        $this->queries[] = $q;
        if (stripos($q, 'FROM') !== false && stripos($q, 'client_contribution') !== false) {
            return $this->clients;
        }
        return [];
    }
    public function get_var($q) { return null; }
    public function get_col($q) { return []; }
}

// Fake order_query: must be an instance of MealsDB_WC_Order_Query (the report
// guards on that). Returns a fixed "paid" amount per user.
class BC4FakeOrderQuery extends MealsDB_WC_Order_Query {
    public array $paid = []; // wp_user_id => dollars paid
    public function get_total_fee_paid_for_user(int $wp_user_id, string $type, string $start, string $end): float {
        return (float) ($this->paid[$wp_user_id] ?? 0.0);
    }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}

// ---------------------------------------------------------------------------
// [BC4-1] A multi-month range is rejected (would otherwise report false 2x).
// ---------------------------------------------------------------------------
$w = new BC4FakeWpdb();
$oq = new BC4FakeOrderQuery($w);
$GLOBALS["wpdb"] = $w; $reports = new MealsDB_Reports($w, $oq);
$res = $reports->contribution_reconciliation('2025-01-01', '2025-03-31');
chk(isset($res['error']) && $res['error'] !== '', true, '[BC4-1] multi-month range returns an error');
chk($res['rows'], [], '[BC4-1] multi-month range returns no rows');

// ---------------------------------------------------------------------------
// [BC4-2] A single calendar month is accepted, and the clients query filters to
//         the billed types (SDNB/Veteran).
// ---------------------------------------------------------------------------
$w = new BC4FakeWpdb();
$w->clients = [
    ['client_id' => 1, 'wp_user_id' => 11, 'first_name' => 'A', 'last_name' => 'X',
     'client_contribution' => 40.00, 'client_type' => 'SDNB'],
];
$oq = new BC4FakeOrderQuery($w);
$oq->paid = [11 => 40.00];
$GLOBALS["wpdb"] = $w; $reports = new MealsDB_Reports($w, $oq);
$res = $reports->contribution_reconciliation('2025-04-01', '2025-04-30');

chk(isset($res['error']), false, '[BC4-2] single-month range is accepted (no error)');
$clients_sql = '';
foreach ($w->queries as $q) { if (stripos($q, 'client_contribution') !== false && stripos($q, 'FROM') !== false) { $clients_sql = $q; break; } }
chk(stripos($clients_sql, 'client_type IN') !== false
    && stripos($clients_sql, 'SDNB') !== false
    && stripos($clients_sql, 'Veteran') !== false, true,
    '[BC4-2] clients query filters to SDNB/Veteran only');

// Exact match → zero difference.
chk(count($res['rows']), 1, '[BC4-2] one client row returned');
chk((float) $res['rows'][0]['difference'], 0.0, '[BC4-2] exact-month payment reconciles to zero');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
