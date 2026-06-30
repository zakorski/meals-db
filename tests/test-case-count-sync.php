<?php
/**
 * Tests for MealsDB_Product_Display_Sync::case_count_sync() — the legacy
 * case_size → meals_products.case_size COLUMN backfill.
 *
 * Verifies the column-keyed, idempotent, non-destructive behavior:
 *   CS-1 fill:             column default (1) + legacy>1 → column set to legacy, counted 'filled'
 *   CS-2 destructive guard: column already real (36) + differing legacy (24) → stays 36, 'already_ok'
 *   CS-3 no legacy:        column default + no legacy meta → unchanged, 'no_legacy'
 *   CS-4 idempotent:       second run fills nothing; filled products become 'already_ok'
 *   CS-5 failed write:     save_product_data returns false → counted 'failed', not 'filled'
 *   CS-6 multi-page:       150 products (> per_page) all scanned/filled across pages
 *
 * Stubs wc_get_products + get_post_meta + MealsDB_Products (in-memory column store)
 * + MealsDB_Quick_Order_Products; loads the REAL MealsDB_Product_Display_Sync.
 *
 * Run: php tests/test-case-count-sync.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

// ---- fixtures (mutated by the stubs) ----
$GLOBALS['CS_COLUMN']   = [];   // pid => meals_products.case_size
$GLOBALS['CS_LEGACY']   = [];   // pid => legacy `case_size` postmeta
$GLOBALS['CS_PRODUCTS'] = [];   // list of pids wc_get_products returns
$GLOBALS['CS_SAVE_OK']  = true; // save_product_data return value

// ---- WP / WC function stubs ----
if (!function_exists('__')) { function __($t, $d = null) { return $t; } }
if (!function_exists('get_post_meta')) {
    function get_post_meta($pid, $key, $single = false) {
        if ($key === 'case_size') { return $GLOBALS['CS_LEGACY'][$pid] ?? ''; }
        return '';
    }
}
if (!class_exists('WC_Product')) { class WC_Product {} }
class CS_FakeProduct extends WC_Product {
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
}
if (!function_exists('wc_get_products')) {
    function wc_get_products($args) {
        $page  = max(1, (int) ($args['page'] ?? 1));
        $limit = max(1, (int) ($args['limit'] ?? 100));
        $slice = array_slice($GLOBALS['CS_PRODUCTS'], ($page - 1) * $limit, $limit);
        $out = [];
        foreach ($slice as $pid) { $out[] = new CS_FakeProduct($pid); }
        return $out;
    }
}

// ---- collaborator stubs (defined BEFORE the autoloader so the real ones don't load) ----
class MealsDB_Products {
    public static function get_product_data(int $pid): array {
        return [
            'wc_product_id'   => $pid,
            'product_type'    => 'meal',
            'taxable'         => 0,
            'main_ingredient' => '',
            'dietary_tags'    => [],
            'allergen_flags'  => [],
            'case_size'       => (int) ($GLOBALS['CS_COLUMN'][$pid] ?? 1),
            'unit_cost'       => '0.00',
            'last_updated'    => null,
        ];
    }
    public static function save_product_data(int $pid, array $data): bool {
        if (!$GLOBALS['CS_SAVE_OK']) { return false; }
        $GLOBALS['CS_COLUMN'][$pid] = (int) ($data['case_size'] ?? 1);
        return true;
    }
}
class MealsDB_Quick_Order_Products {
    public static function get_allowed_category_slugs(): array { return ['meals']; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// ---- harness ----
$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function cs_reset() {
    $GLOBALS['CS_COLUMN'] = []; $GLOBALS['CS_LEGACY'] = [];
    $GLOBALS['CS_PRODUCTS'] = []; $GLOBALS['CS_SAVE_OK'] = true;
}

// CS-1..CS-3 — combined scenario: fill / destructive-guard / no-legacy.
cs_reset();
$GLOBALS['CS_PRODUCTS'] = [1, 2, 3];
$GLOBALS['CS_COLUMN']   = [1 => 1, 2 => 36, 3 => 1];
$GLOBALS['CS_LEGACY']   = [1 => 24, 2 => 24, 3 => 0];
$r = MealsDB_Product_Display_Sync::case_count_sync();
chk($r['scanned'], 3, 'CS: scanned 3');
chk($r['filled'], 1, 'CS-1: one filled (pid1)');
chk($r['already_ok'], 1, 'CS-2: one already_ok (pid2)');
chk($r['no_legacy'], 1, 'CS-3: one no_legacy (pid3)');
chk($r['failed'], 0, 'CS: none failed');
chk($GLOBALS['CS_COLUMN'][1], 24, 'CS-1: pid1 column filled to 24');
chk($GLOBALS['CS_COLUMN'][2], 36, 'CS-2: pid2 column unchanged (36, NOT lowered to 24)');
chk($GLOBALS['CS_COLUMN'][3], 1,  'CS-3: pid3 column stays default 1');

// CS-4 — idempotent second run (no new fills; filled product now already_ok).
$r2 = MealsDB_Product_Display_Sync::case_count_sync();
chk($r2['filled'], 0, 'CS-4: second run fills nothing');
chk($r2['already_ok'], 2, 'CS-4: pid1+pid2 now already_ok');
chk($r2['no_legacy'], 1, 'CS-4: pid3 still no_legacy');
chk($GLOBALS['CS_COLUMN'][1], 24, 'CS-4: pid1 still 24');

// CS-5 — failed write counted as 'failed', not 'filled'.
cs_reset();
$GLOBALS['CS_PRODUCTS'] = [5];
$GLOBALS['CS_COLUMN']   = [5 => 1];
$GLOBALS['CS_LEGACY']   = [5 => 48];
$GLOBALS['CS_SAVE_OK']  = false;
$r3 = MealsDB_Product_Display_Sync::case_count_sync();
chk($r3['filled'], 0, 'CS-5: failed write NOT counted as filled');
chk($r3['failed'], 1, 'CS-5: failed write counted as failed');
chk($GLOBALS['CS_COLUMN'][5], 1, 'CS-5: column unchanged on failed write');

// CS-6 — multi-page walk: 150 products (> per_page=100) must all be scanned/filled,
// proving the do/while continuation past page 1 (page1=100, page2=50, page3=0 → stop).
cs_reset();
for ($i = 1; $i <= 150; $i++) {
    $GLOBALS['CS_PRODUCTS'][] = $i;
    $GLOBALS['CS_COLUMN'][$i] = 1;   // all at default
    $GLOBALS['CS_LEGACY'][$i] = 12;  // all have a real legacy value
}
$r6 = MealsDB_Product_Display_Sync::case_count_sync();
chk($r6['scanned'], 150, 'CS-6: all 150 products scanned across pages');
chk($r6['filled'], 150, 'CS-6: all 150 filled (multi-page walk continued past page 1)');
chk($GLOBALS['CS_COLUMN'][101], 12, 'CS-6: a page-2 product was filled (proves pagination)');

echo "\n=== MealsDB case_count_sync ===\n";
if (empty($failures)) { echo "PASS — {$passed} checks\n"; exit(0); }
echo "FAIL — {$passed} passed, " . count($failures) . " failed:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
