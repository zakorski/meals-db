<?php
/**
 * Tests for the full-catalog PO forecast (DIRECTIVE-po-forecast-full-catalog).
 *
 * generate_purchase_order() must forecast EVERY published meal/side product in
 * meals_products — not just products sold in the trailing 84 days.
 *
 *   FC-1  full catalog: 3 published products (2 sold, 1 unsold) → THREE rows;
 *         the unsold product present with weighted_avg 0 and its catalog case_size
 *   FC-2  each row carries its real catalog case_size (24 / 12 / 36)
 *   FC-3  sold products order on 9-week coverage (units 70): case 24→qty 72, case 12→qty 72
 *   FC-4  unsold product: order_quantity 0 but STILL a row
 *   FC-5  empty-catalog guard: catalog []=empty but recent sales present → sold product STILL forecast
 *         (proves the empty($products) guard is used, not empty($catalog))
 *   FC-6  case_size floor: sold-but-uncatalogued product with no case_size meta → case_size 1,
 *         no divide-by-zero (units 70 / case 1 → qty 70)
 *   FC-7  source: the catalog query filters is_published = 1
 *
 * Run: php tests/test-po-full-catalog.php
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
        // No legacy case_size meta (so FC-6's floor is exercised). NOTE: the
        // forecast no longer reads _future_inventory_quantity at all (the retired
        // future-dated-inventory plugin's data is unreliable), so this branch is
        // now dead — kept only to document that the meta is intentionally ignored.
        if ($key === '_future_inventory_quantity') { return 0; }
        return '';
    }
}
class FcFakeProduct {
    private int $id;
    public function __construct(int $id) { $this->id = $id; }
    public function get_sku(): string { return 'SKU' . $this->id; }
    public function get_name(): string { return 'WC Name ' . $this->id; }
    public function get_stock_quantity(): int { return 20; }
}
if (!function_exists('wc_get_product')) { function wc_get_product($id) { return new FcFakeProduct((int) $id); } }
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

/**
 * Query-shape-aware wpdb stub.
 *  - Catalog seed  → query contains 'is_published'      → returns $GLOBALS['FC_CATALOG']
 *  - Recent sales  → query contains 'order_item_name'   → weekly rows for $GLOBALS['FC_SOLD']
 *  - Everything else (LY seasonal, product-type lookup) → []
 */
class FcWpdb extends wpdb {
    public function __construct() { $this->prefix = 'wp_'; }
    public function prepare($query, ...$args) { return $query; }
    public function get_results($query, $output = ARRAY_A) {
        if (strpos($query, 'is_published') !== false) {
            return $GLOBALS['FC_CATALOG'];
        }
        if (strpos($query, 'order_item_name AS product_name') !== false) {
            $rows = [];
            foreach ($GLOBALS['FC_SOLD'] as $pid) {
                for ($w = 1; $w <= 12; $w++) {
                    $rows[] = [
                        'wc_product_id' => $pid,
                        'product_name'  => 'Sold ' . $pid,
                        'year_week'     => sprintf('2026%02d', $w),
                        'weekly_qty'    => 10.0,
                    ];
                }
            }
            return $rows;
        }
        return [];
    }
    public function get_row($query, $output = OBJECT, $y = 0) { return null; }
    public function get_var($query, $x = 0, $y = 0) { return null; }
    public function query($query) { return 0; }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function chk_true($c, $l) { chk((bool) $c, true, $l); }
function fc_row_by_sku(array $rows, string $sku) {
    foreach ($rows as $r) { if (($r['sku'] ?? '') === $sku) { return $r; } }
    return null;
}

// ---- Scenario 1: full catalog (FC-1..FC-4) ----
$GLOBALS['FC_CATALOG'] = [
    ['wc_product_id' => 101, 'product_type' => 'meal', 'case_size' => 24],
    ['wc_product_id' => 102, 'product_type' => 'side', 'case_size' => 12],
    ['wc_product_id' => 103, 'product_type' => 'meal', 'case_size' => 36],
];
$GLOBALS['FC_SOLD'] = [101, 102];
$GLOBALS['wpdb'] = new FcWpdb();
$reports = new MealsDB_Reports($GLOBALS['wpdb']);
$rows = $reports->generate_purchase_order();

chk(is_array($rows) ? count($rows) : -1, 3, 'FC-1 three rows (unsold product included)');
$r101 = fc_row_by_sku($rows, 'SKU101');
$r102 = fc_row_by_sku($rows, 'SKU102');
$r103 = fc_row_by_sku($rows, 'SKU103');
chk_true($r101 && $r102 && $r103, 'FC-1 all three products present');
chk($r101['case_size'] ?? null, 24, 'FC-2 pid101 catalog case_size 24');
chk($r102['case_size'] ?? null, 12, 'FC-2 pid102 catalog case_size 12');
chk($r103['case_size'] ?? null, 36, 'FC-2 pid103 catalog case_size 36');
chk($r101['order_quantity'] ?? null, 72, 'FC-3 pid101 order_quantity 72 (ceil(70/24)*24)');
chk($r102['order_quantity'] ?? null, 72, 'FC-3 pid102 order_quantity 72 (ceil(70/12)*12)');
// The LOCKED forecasting math returns round($weighted_sum / $weight_sum, 2) —
// a PHP float — so an empty-history product yields float 0.0 (value zero, as
// required). Assert 0.0 to match the untouched math's output type; the value is
// what matters (weighted_avg is zero for a product with no recent demand).
chk($r103['weighted_avg_weekly'] ?? null, 0.0, 'FC-4 unsold pid103 weighted_avg 0');
chk($r103['order_quantity'] ?? null, 0, 'FC-4 unsold pid103 order_quantity 0');
chk($r103['product_name'] ?? null, 'WC Name 103', 'FC-4 unsold pid103 name from WC fallback');

// ---- Scenario 2: empty catalog + recent sales (FC-5, FC-6) ----
$GLOBALS['FC_CATALOG'] = [];
$GLOBALS['FC_SOLD'] = [555];
$GLOBALS['wpdb'] = new FcWpdb();
$reports2 = new MealsDB_Reports($GLOBALS['wpdb']);
$rows2 = $reports2->generate_purchase_order();

chk(is_array($rows2) ? count($rows2) : -1, 1, 'FC-5 empty catalog + sales → sold product still forecast');
$r555 = fc_row_by_sku($rows2, 'SKU555');
chk_true($r555 !== null, 'FC-5 pid555 present via fallback');
chk($r555['case_size'] ?? null, 1, 'FC-6 case_size floored to 1 (no catalog/meta)');
chk($r555['order_quantity'] ?? null, 70, 'FC-6 order_quantity 70 (units 70 / case 1) — no divide-by-zero');

// ---- FC-7: source-level assertion that the catalog query filters is_published ----
$src = file_get_contents(__DIR__ . '/../includes/services/class-reports.php');
chk_true(strpos($src, 'is_published = 1') !== false, 'FC-7 catalog query filters is_published = 1');

echo "\n=== PO forecast — full catalog ===\n";
if (empty($failures)) { echo "PASS — {$passed} checks\n"; exit(0); }
echo "FAIL — {$passed} passed, " . count($failures) . " failed:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
