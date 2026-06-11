<?php
/**
 * BC-5 regression tests — invoice contribution sum.
 *
 * Three bugs the fix closes:
 *   (1) hardcoded product id 5675 instead of the operator-tunable
 *       mealsdb_fee_product_ids → a changed id silently zeroed the invoice's
 *       contribution and over-billed the department.
 *   (2) summed _line_total while the reconciliation layer sums _line_subtotal
 *       → the two could diverge by any per-line adjustment.
 *   (3) an order spanning a month boundary appeared in BOTH months' order lists
 *       (delivery_allocations has rows in each), so its contribution was
 *       deducted twice. → attribute each order to its PRIMARY (earliest) month.
 *
 * Run: php tests/test-invoice-contribution-sum.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
// get_fee_product_ids() reads the operator override via get_option().
$GLOBALS['__bc5_options'] = ['mealsdb_fee_product_ids' => ['client_contribution' => 9999, 'delivery_fee' => 4122]];
if (!function_exists('get_option')) {
    function get_option($name, $default = false) { return $GLOBALS['__bc5_options'][$name] ?? $default; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

class BC5FakeWpdb extends wpdb {
    public $prefix = 'wp_';
    public array $get_var_sql = [];
    public function get_var($q) { $this->get_var_sql[] = $q; return '0'; }
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function invoke_private_static(string $method, array $args) {
    $rm = new ReflectionMethod(MealsDB_Invoice_Generator::class, $method);
    $rm->setAccessible(true);
    return $rm->invoke(null, ...$args);
}

// ---------------------------------------------------------------------------
// [BC5-1] sum_contribution_for_orders uses the CONFIGURED product id and
//         _line_subtotal — not hardcoded 5675 / _line_total.
// ---------------------------------------------------------------------------
$GLOBALS['wpdb'] = new BC5FakeWpdb();
invoke_private_static('sum_contribution_for_orders', [[100, 101]]);
$sql = implode("\n", $GLOBALS['wpdb']->get_var_sql);

chk(strpos($sql, '9999') !== false, true,     '[BC5-1] sum uses the configured contribution product id (9999)');
chk(strpos($sql, '5675') === false, true,      '[BC5-1] hardcoded 5675 is gone');
chk(stripos($sql, '_line_subtotal') !== false, true, '[BC5-1] sum reads _line_subtotal');
chk(stripos($sql, '_line_total') === false, true,    '[BC5-1] sum no longer reads _line_total');

// [BC5-1b] zero-config safety: if the contribution id resolves to 0, return 0
//          and issue no sum query.
$GLOBALS['__bc5_options'] = ['mealsdb_fee_product_ids' => ['client_contribution' => 0, 'delivery_fee' => 0]];
$GLOBALS['wpdb'] = new BC5FakeWpdb();
$zero = invoke_private_static('sum_contribution_for_orders', [[100]]);
chk($zero, 0, '[BC5-1b] contribution id 0 → returns 0 cents');
chk(count($GLOBALS['wpdb']->get_var_sql), 0, '[BC5-1b] contribution id 0 → no sum query issued');
$GLOBALS['__bc5_options'] = ['mealsdb_fee_product_ids' => ['client_contribution' => 9999, 'delivery_fee' => 4122]];

// ---------------------------------------------------------------------------
// [BC5-2] contribution_orders_for_month keeps only orders whose PRIMARY
//         (earliest) billing month is the month being invoiced — so a spilled
//         order is counted once, in its primary month.
// ---------------------------------------------------------------------------
$primary = [100 => '2025-05', 101 => '2025-04', 102 => '2025-05'];
$kept = invoke_private_static('contribution_orders_for_month', [[100, 101, 102], $primary, '2025-05']);
sort($kept);
chk($kept, [100, 102], '[BC5-2] order whose primary month is April is excluded from May');

// An order with no recorded primary month defaults to the current month (kept).
$kept2 = invoke_private_static('contribution_orders_for_month', [[200], [], '2025-05']);
chk($kept2, [200], '[BC5-2] order with no primary-month entry is kept (defaults to current)');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
