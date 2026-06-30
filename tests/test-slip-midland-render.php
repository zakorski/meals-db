<?php
/**
 * Tests for the dompdf-FREE logic of the Midland renderers in
 * MealsDB_Slip_PDF_Generator (directive 02). The actual PDF rendering is
 * verified on the live host (no mbstring/dompdf in CLI); here we pin the pure
 * string/data logic that drives those renders:
 *
 *   D4-1  driver_block_inner_html includes every populated field
 *   D4-2  empty fields are SKIPPED — no stray label, no dangling "()"
 *   D4-3  contact name only / contact phone only render cleanly
 *   D4-4  HTML is escaped
 *   LEG-1 build_legend_rows maps the schedule option (ZONE#/WEEKDAY/AREA),
 *         label preferred over day, in configured order
 *   LEG-2 empty/absent schedule → no rows
 *   ZN-1  resolve_zone_number = 1-based position of the zone key
 *
 * Run: php tests/test-slip-midland-render.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

$GLOBALS['TEST_OPTIONS'] = [];
if (!function_exists('get_option')) {
    function get_option($name, $default = false) { return $GLOBALS['TEST_OPTIONS'][$name] ?? $default; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function chk_true($c, $l) { chk((bool) $c, true, $l); }
function chk_contains($hay, $needle, $l) { chk_true(strpos($hay, $needle) !== false, $l . " (contains '$needle')"); }
function chk_missing($hay, $needle, $l) { chk_true(strpos($hay, $needle) === false, $l . " (must NOT contain '$needle')"); }

// Reach private instance methods without wiring the constructor deps.
$ref = new ReflectionClass('MealsDB_Slip_PDF_Generator');
$gen = $ref->newInstanceWithoutConstructor();
function call_priv($gen, $method, ...$args) {
    $m = new ReflectionMethod('MealsDB_Slip_PDF_Generator', $method);
    $m->setAccessible(true);
    return $m->invoke($gen, ...$args);
}

// ===========================================================================
// D4-1 — full payload: every field present.
// ===========================================================================
$full = [
    'collect_label'   => 'Collect: $14.66',
    'client_name'     => 'Magella Landry',
    'street'          => '12 Rue Principale',
    'city'            => 'Dieppe',
    'postal'          => 'E1A 1A1',
    'phone'           => '506-555-0101',
    'phone_secondary' => '506-555-0202',
    'contact_name'    => 'Gail',
    'contact_phone'   => '536-1126',
];
$html = MealsDB_Slip_PDF_Generator::driver_block_inner_html($full);
chk_contains($html, 'Collect: $14.66', 'D4-1 collect');
chk_contains($html, 'Magella Landry', 'D4-1 name');
chk_contains($html, '12 Rue Principale', 'D4-1 street');
chk_contains($html, 'Dieppe E1A 1A1', 'D4-1 city+postal on one line');
chk_contains($html, '506-555-0101', 'D4-1 primary phone');
chk_contains($html, '506-555-0202', 'D4-1 secondary phone');
chk_contains($html, '(Gail) 536-1126', 'D4-1 contact name+phone');

// ===========================================================================
// D4-2 — minimal payload: only collect + name. No stray markup.
// ===========================================================================
$min = ['collect_label' => 'Collect: $0.00', 'client_name' => 'Chuck Khan'];
$html = MealsDB_Slip_PDF_Generator::driver_block_inner_html($min);
chk_contains($html, 'Collect: $0.00', 'D4-2 collect present');
chk_contains($html, 'Chuck Khan', 'D4-2 name present');
chk_missing($html, '()', 'D4-2 no empty parens');
chk_missing($html, 'd4-addr', 'D4-2 no empty address line emitted');
chk_missing($html, 'd4-phone', 'D4-2 no empty phone line emitted');

// ===========================================================================
// D4-3 — contact name only / contact phone only.
// ===========================================================================
$name_only  = ['client_name' => 'X', 'contact_name' => 'Gail'];
$h1 = MealsDB_Slip_PDF_Generator::driver_block_inner_html($name_only);
chk_contains($h1, '(Gail)', 'D4-3 name-only contact');
$phone_only = ['client_name' => 'X', 'contact_phone' => '536-1126'];
$h2 = MealsDB_Slip_PDF_Generator::driver_block_inner_html($phone_only);
chk_contains($h2, '536-1126', 'D4-3 phone-only contact');
chk_missing($h2, '()', 'D4-3 phone-only has no empty parens');

// ===========================================================================
// D4-4 — escaping.
// ===========================================================================
$xss = ['client_name' => 'A & B <script>', 'collect_label' => 'Collect: $1.00'];
$h3 = MealsDB_Slip_PDF_Generator::driver_block_inner_html($xss);
chk_missing($h3, '<script>', 'D4-4 raw script tag escaped out');
chk_contains($h3, 'A &amp; B', 'D4-4 ampersand escaped');

// ===========================================================================
// LEG-1 — legend rows from the schedule option.
// ===========================================================================
$GLOBALS['TEST_OPTIONS']['mealsdb_zone_delivery_schedule'] = [
    'Moncton Downtown'   => ['day' => 'Wednesday', 'label' => 'Wednesday morning'],
    'Sackville / Amherst'=> ['day' => 'Wednesday', 'label' => 'Wednesday afternoon'],
    'Moncton Other'      => ['day' => 'Thursday',  'label' => ''],   // label empty → falls back to day
];
$rows = call_priv($gen, 'build_legend_rows');
chk(count($rows), 3, 'LEG-1 three rows');
chk($rows[0], ['zone' => '1', 'weekday' => 'Wednesday morning', 'area' => 'Moncton Downtown'], 'LEG-1 row 1');
chk($rows[1]['zone'], '2', 'LEG-1 row 2 number');
chk($rows[2]['weekday'], 'Thursday', 'LEG-1 empty label falls back to day');

// ===========================================================================
// LEG-2 — no schedule → no rows.
// ===========================================================================
$GLOBALS['TEST_OPTIONS']['mealsdb_zone_delivery_schedule'] = [];
chk(call_priv($gen, 'build_legend_rows'), [], 'LEG-2 empty schedule → []');

// ===========================================================================
// ZN-1 — zone number = position.
// ===========================================================================
$GLOBALS['TEST_OPTIONS']['mealsdb_zone_delivery_schedule'] = [
    'Moncton Downtown' => ['day' => 'Wednesday'],
    'Sussex'           => ['day' => 'Thursday'],
];
chk(call_priv($gen, 'resolve_zone_number', 'Moncton Downtown'), 1, 'ZN-1 first → 1');
chk(call_priv($gen, 'resolve_zone_number', 'Sussex'), 2, 'ZN-1 second → 2');
chk(call_priv($gen, 'resolve_zone_number', 'Nowhere'), null, 'ZN-1 unknown → null');

// ===========================================================================
// CMB-1 — combined Packing-Slips HTML: cover page 1 + continuous numbering.
// ===========================================================================
$cmb_batch = [
    'order_count' => 5, // deliberately WRONG snapshot count; the combined doc
                        // must override this with the live slip count (2 below).
    'orders'     => [
        ['initials' => 'AAA', 'take_from_hold' => true],
        ['initials' => 'BBB', 'take_from_hold' => false],
    ],
    'created_at' => '2026-06-30 12:00:00',
];
$cmb_slip = [
    'initials' => 'AAA', 'zone' => 'Zone 1', 'order_number' => '#100',
    'delivery_date' => 'June 30, 2026', 'items' => [],
    'total_items' => 0, 'total_mains' => 0, 'total_sides' => 0, 'additional_notes' => '',
];
$two_slips = [$cmb_slip, ['initials' => 'BBB'] + $cmb_slip];
$html = call_priv($gen, 'render_packing_slips_combined_html', 'Moncton Downtown', '2026-06-30', $cmb_batch, $two_slips);

// Count opening div tags, not bare class strings — the <style> block also
// contains .doc1-page/.doc2-page selectors, so a bare substr_count over the
// whole document double-counts the CSS rules.
chk(substr_count($html, '<div class="doc1-page'), 1, 'CMB-1: exactly one cover page');
chk(substr_count($html, '<div class="doc2-page'), 2, 'CMB-1: two packer pages');
chk_true(strpos($html, '<div class="doc1-page d2-break">') !== false, 'CMB-1: cover breaks before first slip');
chk_true(strpos($html, 'Page 1 of 3') !== false, 'CMB-1: cover stamped "Page 1 of 3"');
chk_true(strpos($html, 'Page 2 of 3') !== false, 'CMB-1: first slip "Page 2 of 3"');
chk_true(strpos($html, 'Page 3 of 3') !== false, 'CMB-1: last slip "Page 3 of 3"');
chk_true(strpos($html, '2 Orders') !== false, 'CMB-1: cover count reflects live slip count');
// Last page must NOT carry a trailing page break (no blank trailing page).
$last = strrpos($html, 'doc2-page');
chk_true(strpos($html, 'doc2-page d2-break', $last) === false, 'CMB-1: last slip has no trailing break');

// Zero-order edge: cover only, no trailing break.
$html0 = call_priv($gen, 'render_packing_slips_combined_html', 'Moncton Downtown', '2026-06-30', $cmb_batch, []);
chk_true(strpos($html0, '<div class="doc1-page">') !== false, 'CMB-1: zero-order cover has no break');
chk_true(strpos($html0, 'Page 1 of 1') !== false, 'CMB-1: zero-order cover "Page 1 of 1"');

echo "\n=== Midland renderers (dompdf-free logic) ===\n";
if (empty($failures)) { echo "PASS — {$passed} checks\n"; exit(0); }
echo "FAIL — {$passed} passed, " . count($failures) . " failed:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
