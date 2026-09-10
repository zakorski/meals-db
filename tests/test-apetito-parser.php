<?php
/**
 * K12: MealsDB_Apetito_Parser turns a Nutridata page into a per-field
 * required-or-report struct. Pure — runs against committed fixtures, never
 * the network. No mb_* (local CLI lacks mbstring); DOMDocument only.
 *
 * Run: php tests/test-apetito-parser.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$fx = __DIR__ . '/fixtures/apetito/';
$fail = []; $pass = 0;
function ok($label, $expected, $actual) {
    global $fail, $pass;
    if ($expected === $actual) { $pass++; return; }
    $fail[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label,
        var_export($expected, true), var_export($actual, true));
}

$r = MealsDB_Apetito_Parser::parse(file_get_contents($fx . '12212.html'), '12212');
$f = $r['fields'];

// Test 1 — full parse of 12212
ok('name_en', 'Chicken with Creamy Mushroom Sauce', $f['name_en']['value'] ?? null);
ok('name_en ok', true, $f['name_en']['ok']);
ok('category', 'Individual Complete Meals', $f['category']['value'] ?? null);
ok('subcategory', 'Poultry', $f['subcategory']['value'] ?? null);
ok('code_on_page', '12212', $f['code_on_page']['value'] ?? null);
ok('allergens', ['Eggs','Milk','Soy','Sulphites'], $f['allergens']['value'] ?? null);
ok('serving_size', '330g', $f['serving_size']['value'] ?? null);
ok('pack_size', '12 x 330g', $f['pack_size']['value'] ?? null);
ok('portions_per_case', 12, $f['portions_per_case']['value'] ?? null);
ok('diet_tags ok', true, $f['diet_tags']['ok']);

// Test 5 — French name entity decode (é / à) via DOM, no mb_*
ok('name_fr decoded', 'Poulet à la sauce crémeuse aux champignons', $f['name_fr']['value'] ?? null);

// Test 6 — display:none English span still parsed (hidden != absent)
ok('hidden en parsed', true, $f['name_en']['ok']);

// Test 2 — 12217 second shape (has a Vegan diet tag)
$r2 = MealsDB_Apetito_Parser::parse(file_get_contents($fx . '12217.html'), '12217');
ok('12217 has Vegan', true, in_array('Vegan', $r2['fields']['diet_tags']['value'] ?? [], true));

// Test 3 — allergens block removed => absent, no crash, no write value
$r3 = MealsDB_Apetito_Parser::parse(file_get_contents($fx . '12212-no-allergens.html'), '12212');
ok('allergens absent ok=false', false, $r3['fields']['allergens']['ok']);
ok('allergens absent has reason', true, isset($r3['fields']['allergens']['reason']));
ok('name still ok when allergens gone', true, $r3['fields']['name_en']['ok']);

// Test 4 — renamed Portions heading => portions absent, others ok
$r4 = MealsDB_Apetito_Parser::parse(file_get_contents($fx . '12212-renamed-portions.html'), '12212');
ok('portions absent ok=false', false, $r4['fields']['portions_per_case']['ok']);
ok('serving still ok', true, $r4['fields']['serving_size']['ok']);

// Test 7 — code_on_page mismatch surfaced
$rm = MealsDB_Apetito_Parser::parse(file_get_contents($fx . '12212.html'), '99999');
ok('requested code echoed', '99999', $rm['code']);
ok('page code differs', '12212', $rm['fields']['code_on_page']['value'] ?? null);

if ($fail) { echo implode("\n", $fail) . "\n"; echo "FAILED ({$pass} passed)\n"; exit(1); }
echo "OK ({$pass} passed)\n";
