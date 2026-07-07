<?php
/**
 * Regression tests for DIRECTIVE-po-engine-3week-buffer: lock the PO engine to
 * the back-test-validated model — a fixed 6-week horizon PLUS a
 * demand-proportional 3-week safety buffer (9 weeks of coverage), with NO
 * configurable knobs and NO flat per-product `buffer` meta.
 *
 * Drives the full generate_purchase_order() with one controlled product:
 *   - 12 weeks of constant demand (qty 10/wk) → weighted_avg = 10.00
 *   - no prior-year data → seasonal_index = 1.0 → adjusted_weekly = 10.00
 *   - stock 20, future 0 → total_available = 20
 *   - case_size 12
 *   - a deliberately HUGE flat `buffer` meta (1000) that must be IGNORED
 *
 * Validated model: projected_need = ceil(10 * 9) = 90; units = 90 - 20 = 70;
 * cases = ceil(70/12) = 6; order_quantity = 72. (Old 6-week+flat-buffer math
 * would have produced a wildly different number — so order_quantity == 72
 * proves both the 9-week coverage and that the flat buffer is gone.)
 *
 *   P-1  signature takes NO forecasting parameters
 *   P-2  projected_need reflects 9-week coverage (90)
 *   P-3  order_quantity == 72 (9 weeks, flat buffer ignored despite meta=1000)
 *   P-4  the dropped Buffer/Needed columns are absent from the row
 *   P-5  CSV header no longer carries 'Buffer' / 'Qty Needed'
 *   P-6  source no longer reads get_post_meta($pid, 'buffer')
 *
 * Run: php tests/test-purchase-order-3week-buffer.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id(): int { return 0; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(): bool { return true; } }
if (!function_exists('current_user_can')) { function current_user_can(string $c): bool { return true; } }
if (!function_exists('apply_filters')) { function apply_filters(string $t, $v, ...$a) { return $v; } }
if (!function_exists('get_option')) {
    // Empty excluded-category list → no category exclusion, has_term never called.
    function get_option(string $name, $default = false) { return $name === 'mealsdb_appetito_excluded_categories' ? [] : $default; }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($id, string $key, bool $single = false) {
        if ($key === 'buffer') { return 1000; }                       // must be IGNORED by the validated model
        if ($key === 'case_size') { return 12; }
        // Dead branch: the forecast no longer reads _future_inventory_quantity
        // (retired plugin's data is unreliable); kept to document the intent.
        if ($key === '_future_inventory_quantity') { return 0; }
        return '';
    }
}
class PoFakeProduct {
    public function get_sku(): string { return 'SKU555'; }
    public function get_stock_quantity(): int { return 20; }
}
if (!function_exists('wc_get_product')) { function wc_get_product($id) { return new PoFakeProduct(); } }
if (!function_exists('has_term')) { function has_term($t, $tax, $id) { return false; } }

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $output = OBJECT) { return []; }
        public function get_row($query, $output = OBJECT, $y = 0) { return null; }
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function query($query) { return 0; }
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// wpdb stub that returns 12 weeks of constant demand for the recent-period
// query (the only query whose results drive the math) and [] for everything
// else (prior-year → seasonal 1.0; product-type lookup → case_size fallback).
class PoWpdb extends wpdb {
    public function __construct() { $this->prefix = 'wp_'; }
    public function prepare($query, ...$args) { return $query; }
    public function get_results($query, $output = ARRAY_A) {
        if (strpos($query, 'order_item_name AS product_name') !== false) {
            $rows = [];
            for ($w = 1; $w <= 12; $w++) {
                $rows[] = [
                    'wc_product_id' => 555,
                    'product_name'  => 'Test Meal',
                    'year_week'     => sprintf('2026%02d', $w),
                    'weekly_qty'    => 10.0,
                ];
            }
            return $rows;
        }
        return [];
    }
    public function get_row($query, $output = OBJECT, $y = 0) { return null; }
    public function get_var($query, $x = 0, $y = 0) { return null; }
    public function get_col($query, $x = 0) { return []; }
    public function query($query) { return 0; }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function chk_true($c, $l) { chk((bool) $c, true, $l); }

// P-1 — no forecasting parameters on the signature.
$ref = new ReflectionMethod(MealsDB_Reports::class, 'generate_purchase_order');
chk($ref->getNumberOfParameters(), 0, 'P-1 generate_purchase_order takes no forecasting params');

$GLOBALS['wpdb'] = new PoWpdb(); // MealsDB_DB / WC_Order_Query reach for the global.
$reports = new MealsDB_Reports($GLOBALS['wpdb']);
$rows = $reports->generate_purchase_order();

chk_true(is_array($rows) && count($rows) === 1, 'P-0 one PO row produced');
$row = $rows[0] ?? [];

// P-2 / P-3 — validated 9-week coverage, flat buffer ignored.
chk($row['projected_need'] ?? null, 90, 'P-2 projected_need = ceil(10 * 9) = 90');
chk($row['order_quantity'] ?? null, 72, 'P-3 order_quantity = 72 (9wk coverage; flat buffer meta ignored)');

// P-4 — dropped columns absent.
chk_true(!array_key_exists('buffer', $row), 'P-4a row has no buffer key');
chk_true(!array_key_exists('qty_needed', $row), 'P-4b row has no qty_needed key');

// P-5 — CSV header drops Buffer / Qty Needed.
$csv = $reports->export_purchase_order_csv($rows);
chk_true(strpos($csv, 'Buffer') === false, 'P-5a CSV header has no Buffer column');
chk_true(strpos($csv, 'Qty Needed') === false, 'P-5b CSV header has no Qty Needed column');
// The Future column was removed with the future-dated-inventory read.
chk_true(strpos($csv, 'Future') === false, 'P-5c CSV header has no Future column');

// P-6 — source no longer reads the flat buffer meta.
$src = file_get_contents(__DIR__ . '/../includes/services/class-reports.php');
chk_true(strpos($src, "get_post_meta(\$pid, 'buffer'") === false, 'P-6 no get_post_meta($pid, \'buffer\') in source');

echo "\n=== PO engine — 3-week-buffer validated model ===\n";
if (empty($failures)) { echo "PASS — {$passed} checks\n"; exit(0); }
echo "FAIL — {$passed} passed, " . count($failures) . " failed:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
