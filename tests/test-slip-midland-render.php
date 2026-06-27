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

echo "\n=== Midland renderers (dompdf-free logic) ===\n";
if (empty($failures)) { echo "PASS — {$passed} checks\n"; exit(0); }
echo "FAIL — {$passed} passed, " . count($failures) . " failed:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
