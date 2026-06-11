<?php
/**
 * Tests for phase 2 — invoice generator data path.
 *
 * Exercises:
 *   - MealsDB_Invoice_Generator::get_phase2_billing_data (the canonical
 *     fetcher: allocated quantities + contribution sum + tax)
 *   - Tax computation against the real Janet rate $14.66 (modal 30 tax sides
 *     × urban side rate 4.48 × 15% = $20.16, matching her Nov 2025 submission).
 *     Post-LB-7 HST is side_rate × 0.15; the urban result is unchanged because
 *     the old 0.672 multiplier was exactly 4.48 × 0.15.
 *   - Legacy Dept. Cost = Basic − Contribution math against Janet's
 *     real Jan 2025 Moncton row (Brammah Peter)
 *   - VAC total = mains_cost + sides_cost + HST (no contribution
 *     subtraction, per old vet-invoice line 521)
 *
 * Run: php tests/test-phase2-billing.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
// BC-5: sum_contribution_for_orders now resolves the contribution product id via
// get_fee_product_ids() -> get_option(). With no override, defaults apply (5675).
if (!function_exists('get_option')) { function get_option($name, $default = false) { return $default; } }

// Mock WooCommerce's tax API: get_phase2_billing_data resolves the HST
// rate live from WC_Tax (LB-7 follow-up — no fallback). 15% standard rate.
if (!class_exists('WC_Tax')) {
    class WC_Tax {
        public static function get_rates($tax_class = '') { return [['rate' => 15.0]]; }
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

// Mock wpdb that returns scripted results per query key.
class P2Wpdb extends wpdb {
    public $prefix = 'wp_';
    public array $scripted = []; // method => [pattern => result]
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    private function lookup(string $method, string $sql) {
        foreach ($this->scripted[$method] ?? [] as $pat => $res) {
            if (stripos($sql, $pat) !== false) { return $res; }
        }
        return null;
    }
    public function get_results($q, $o = null) { return $this->lookup('get_results', (string) $q) ?? []; }
    public function get_var($q)                { return $this->lookup('get_var',     (string) $q); }
    public function get_row($q, $o = null)     { return $this->lookup('get_row',     (string) $q); }
    public function get_col($q)                { return $this->lookup('get_col',     (string) $q) ?? []; }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; } else {
        $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true);
    }
}
function chk_close(float $got, float $exp, float $tol, string $label) {
    global $failures, $passed;
    if (abs($got - $exp) <= $tol) { $passed++; } else {
        $failures[] = "$label: expected ~$exp got $got";
    }
}

// Reflective access for the private static fetcher.
function call_p2(array $client_rows, string $billing_month) {
    $rm = new ReflectionMethod(MealsDB_Invoice_Generator::class, 'get_phase2_billing_data');
    $rm->setAccessible(true);
    return $rm->invoke(null, $client_rows, $billing_month);
}

// ---------------------------------------------------------------------------
// Test 1: a client with 30 allocated taxable sides at the 14.66 rate
// produces exactly $20.16 of HST (Janet's modal value).
// ---------------------------------------------------------------------------
$wpdb = new P2Wpdb();
$wpdb->scripted = [
    'get_results' => [
        "FROM `wp_meals_client_allocations`" => [
            ['client_id' => 42, 'used_mains' => 30, 'used_sides' => 30, 'used_tax_sides' => 30, 'used_nontax_sides' => 0],
        ],
        "FROM `wp_meals_delivery_allocations`" => [
            ['client_id' => 42, 'wc_order_id' => 999],
        ],
    ],
    'get_var' => [
        // Rate-resolution and contribution-sum: both hit get_var; route via SQL hints.
        "SUM(CAST(ls.meta_value AS DECIMAL" => '0.0000', // no contribution
    ],
    'get_row' => [
        // resolve_rate_for_order may hit get_row for the rates table.
    ],
];
// resolve_rate_for_order falls back to client.default_rate_id mapping when DB returns null;
// we pre-fill resolved_rate path by setting default_rate_id and relying on the rates table miss → 0,
// then assert by computing tax outside. But the fetcher uses the resolver's actual return value.
// For this test, override default_rate_id semantics by stubbing the rates-table get_row.
class P2WpdbWithRate extends P2Wpdb {
    public float $rate = 14.66;
    public function get_row($q, $o = null) {
        if (stripos($q, 'meals_client_rates') !== false || stripos($q, 'meals_rates') !== false || stripos($q, 'rate_amount') !== false) {
            return ['rate' => $this->rate, 'rate_amount' => $this->rate];
        }
        return parent::get_row($q, $o);
    }
}
$wpdb2 = new P2WpdbWithRate();
$wpdb2->scripted = $wpdb->scripted;
$wpdb2->rate = 14.66;
$GLOBALS['wpdb'] = $wpdb2;

$client = ['client_id' => 42, 'wp_user_id' => 100, 'default_rate_id' => 1, 'client_contribution' => 0,
           'first_name' => 'Test', 'last_name' => 'Client'];
$out = call_p2([$client], '2025-11');
$row = $out[42] ?? null;
chk(is_array($row), true, '14.66 HST: row returned');
chk((int) $row['allocated_mains'], 30, '14.66 HST: 30 mains');
chk((int) $row['allocated_tax_sides'], 30, '14.66 HST: 30 tax sides');
chk_close((float) $row['resolved_rate'], 14.66, 0.001, '14.66 HST: rate resolved');
chk((int) $row['tax_cents'], 2016, '14.66 HST: 30 sides × 4.48 × 15% = $20.16 (2016 cents)');
chk((int) $row['contribution_cents'], 0, '14.66 HST: no contribution');

// ---------------------------------------------------------------------------
// Test 1b (LB-7 zone regression): a RURAL client (delivery_area_zone='S')
// with 30 taxable sides must bill HST at the RURAL side rate ($4.54), NOT
// the urban $4.48. Regression guard for callers (e.g. generate_sdnb_new_portal)
// that must select delivery_area_zone — without it the row falls back to
// urban and under-reports HST. 30 × 4.54 × 15% = $20.43 → 2043 cents.
// ---------------------------------------------------------------------------
$wpdb_rural = new P2WpdbWithRate();
$wpdb_rural->rate = 15.47;
$wpdb_rural->scripted = $wpdb->scripted;
$GLOBALS['wpdb'] = $wpdb_rural;
$rural_client = ['client_id' => 42, 'wp_user_id' => 100, 'default_rate_id' => 1, 'client_contribution' => 0,
                 'first_name' => 'Test', 'last_name' => 'Client', 'delivery_area_zone' => 'S'];
$out = call_p2([$rural_client], '2025-11');
chk((int) $out[42]['tax_cents'], 2043, 'rural HST: 30 sides × 4.54 × 15% = $20.43 (2043 cents)');

// Same client WITHOUT the zone column present → urban fallback (documents
// the bug that motivated requiring delivery_area_zone in the new-portal query).
unset($rural_client['delivery_area_zone']);
$GLOBALS['wpdb'] = $wpdb_rural;
$out = call_p2([$rural_client], '2025-11');
chk((int) $out[42]['tax_cents'], 2016, 'missing zone falls back to urban side rate (2016 cents)');

// ---------------------------------------------------------------------------
// Test 2: contribution sum picks up the order-line query result.
// ---------------------------------------------------------------------------
$wpdb3 = new P2WpdbWithRate();
$wpdb3->rate = 14.66;
$wpdb3->scripted = [
    'get_results' => [
        "FROM `wp_meals_client_allocations`" => [
            ['client_id' => 7, 'used_mains' => 30, 'used_sides' => 0, 'used_tax_sides' => 0, 'used_nontax_sides' => 0],
        ],
        "FROM `wp_meals_delivery_allocations`" => [
            ['client_id' => 7, 'wc_order_id' => 555],
        ],
    ],
    'get_var' => [
        // The product-5675 contribution sum query returns this decimal:
        "SUM(CAST(ls.meta_value AS DECIMAL" => '19.7700',
    ],
];
$GLOBALS['wpdb'] = $wpdb3;
$out = call_p2([['client_id' => 7, 'wp_user_id' => 50, 'default_rate_id' => 1, 'first_name' => 'T', 'last_name' => 'L']], '2025-11');
chk((int) $out[7]['contribution_cents'], 1977, 'contribution: $19.77 → 1977 cents (Terrence LeBlanc real case)');

// ---------------------------------------------------------------------------
// Test 3: Janet's real Brammah Peter row math.
//   25 mains × $14.66 = $366.50 basic
//   contribution $10.24
//   Dept. Cost = $366.50 - $10.24 = $356.26  (Janet's actual)
//   Total = Dept + Tax (0) = $356.26
// We verify the cent arithmetic.
// ---------------------------------------------------------------------------
$basic_cents = MealsDB_Money::multiply(25, 14.66);
$contribution_cents = MealsDB_Money::to_cents(10.24);
$dept_cents = $basic_cents - $contribution_cents;
$tax_cents = 0;
$total_cents = $dept_cents + $tax_cents;
chk($basic_cents, 36650, 'Brammah: basic 25 × 14.66 = $366.50 (36650 cents)');
chk($contribution_cents, 1024, 'Brammah: contribution = 1024 cents');
chk($dept_cents, 35626, 'Brammah: Dept = Basic - Contribution = $356.26');
chk($total_cents, 35626, 'Brammah: Total = Dept (tax=0 this run) = $356.26');

// ---------------------------------------------------------------------------
// Test 4: Janet's Doyle Linda row (bigger contribution).
//   28 mains × $14.66 = $410.48 basic
//   contribution $236.69
//   Dept = $410.48 - $236.69 = $173.79  (Janet's actual)
// ---------------------------------------------------------------------------
$basic_cents = MealsDB_Money::multiply(28, 14.66);
$contribution_cents = MealsDB_Money::to_cents(236.69);
$dept_cents = $basic_cents - $contribution_cents;
chk($basic_cents, 41048, 'Doyle: basic 28 × 14.66 = $410.48');
chk($contribution_cents, 23669, 'Doyle: contribution = $236.69');
chk($dept_cents, 17379, 'Doyle: Dept = $173.79');

// ---------------------------------------------------------------------------
// Test 5: VAC — no contribution subtraction in new_total.
//   30 mains @ $9.05 = $271.50 mains cost
//   no sides, no tax → new_total = $271.50
//   (Robert Ralph in Janet's Jan 2025 VAC PDF: 31 × $9.05 = $280.55)
// ---------------------------------------------------------------------------
$vet_mains_cost = MealsDB_Money::multiply(31, 9.05);
$sides_cost = 0;
$sides_tax = 0;
$new_total = $vet_mains_cost + $sides_cost + $sides_tax;
chk($new_total, 28055, 'VAC Ralph: new_total = $280.55 (no contribution subtraction)');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
