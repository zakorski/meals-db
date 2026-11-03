<?php
/**
 * Tests for directive K21 ITEMS 1-3: the single sync field registry and the
 * per-comparator equality / blankness rules.
 *
 * Part 1 pins the registry's contents against the directive's scope table and
 * its exclusions. Part 2 proves the refactor is behaviour-preserving: the two
 * legacy accessors return exactly what they returned in v1.0.592 (snapshot
 * below). Parts 3-4 cover the comparators and the client_type class mapping.
 * Part 5 covers the core-field empty-overwrite guard the mutator gained.
 *
 * K21 Tests covered here: 1, 7, 8, 10, and the comparator halves of 20, 31,
 * 42, 43 (the sync-level halves land with ITEM 7).
 *
 * Run: php tests/test-sync-registry-k21.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

$failures = []; $passed = 0;
function chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $label;
}

$R = 'MealsDB_Sync_Registry';
$C = 'MealsDB_Sync_Comparator';

// ---------------------------------------------------------------------------
// Part 1 — registry contents match the K21 scope table
// ---------------------------------------------------------------------------
$expected_two_way = [
    'first_name' => ['core', 'first_name', 'text'],
    'last_name' => ['core', 'last_name', 'text'],
    'client_email' => ['core', 'user_email', 'email'],
    'client_phone_1' => ['meta', 'billing_phone', 'phone'],
    'client_phone_2' => ['meta', 'shipping_phone', 'phone'],
    'street_name' => ['meta', 'billing_address_1', 'text'],
    'city' => ['meta', 'billing_city', 'text'],
    'province' => ['meta', 'billing_state', 'code'],
    'postal_code' => ['meta', 'billing_postcode', 'postal'],
    'delivery_street_name' => ['meta', 'shipping_address_1', 'text'],
    'delivery_city' => ['meta', 'shipping_city', 'text'],
    'delivery_province' => ['meta', 'shipping_state', 'code'],
    'delivery_postal_code' => ['meta', 'shipping_postcode', 'postal'],
    'alternate_contact_name' => ['meta', 'alternate_contact_name', 'text'],
    'alternate_contact_phone_1' => ['meta', 'alternate_contact_phone_1', 'phone'],
    'alternate_contact_phone_2' => ['meta', 'alternate_contact_phone_2', 'phone'],
    'alternate_contact_email' => ['meta', 'alternate_contact_email', 'email'],
    'client_type' => ['meta', 'customer_group', 'client_type'],
    'delivery_area_name' => ['meta', 'shipping_address_2', 'text'],
    'service_center_charged' => ['meta', 'service_centre_charged', 'text'],
    'payment_method' => ['meta', 'payment_method', 'text'],
    'delivery_frequency' => ['meta', 'delivery_frequency', 'integer'],
    'ordering_frequency' => ['meta', 'ordering_frequency', 'integer'],
    'service_id' => ['meta', 'service_id', 'text'],
    'service_commence_date' => ['meta', 'commence_date', 'date'],
    'expected_termination_date' => ['meta', 'service_termination_date', 'date'],
];
chk($R::two_way_columns() === array_keys($expected_two_way), 'registry: exactly the 26 two-way columns, in table order');
foreach ($expected_two_way as $col => [$type, $key, $cmp]) {
    $f = $R::get($col);
    chk($f !== null && $f['wp']['type'] === $type && $f['wp']['key'] === $key,
        "registry: $col -> $type $key");
    chk($f !== null && $f['comparator'] === $cmp, "registry: $col comparator $cmp");
}

// One-way initials push.
$ini = $R::get('delivery_initials');
chk($ini !== null && $ini['direction'] === $R::TO_WP_ONLY, 'registry: delivery_initials is to_wp_only');
chk($ini !== null && $ini['wp']['key'] === 'nickname', 'registry: delivery_initials -> nickname');
chk(count($R::fields()) === 27, 'registry: 26 two-way + 1 one-way = 27 entries');
chk(!in_array('delivery_initials', $R::two_way_columns(), true), 'registry: delivery_initials never two-way');

// ITEM 4: address_2 pair.
$area = $R::get('delivery_area_name');
chk(($area['wp']['also_write'] ?? null) === ['billing_address_2'], 'registry: delivery_area_name fill also writes billing_address_2');
chk(($area['wp']['consistency'] ?? null) === 'billing_address_2', 'registry: delivery_area_name consistency-checks billing_address_2');

// ITEM 6: derived dependants.
chk($R::get('service_center_charged')['derived'] === ['delivery_area_zone'], 'registry: service_center_charged derives delivery_area_zone');
chk($R::get('delivery_area_name')['derived'] === ['delivery_day'], 'registry: delivery_area_name derives delivery_day');

// Exclusions: encrypted, billing, manual, computed, derived.
$encrypted = MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS;
chk(count($encrypted) >= 5, 'fixture: encryption column list loaded');
$excluded = array_merge($encrypted, [
    'client_contribution', 'delivery_fee', 'allowance_mains', 'allowance_sides',
    'requisition_period', 'units', 'use_legacy_billing',
    'next_order_date', 'next_delivery_date', 'last_delivery_date',
    'delivery_area_zone', 'delivery_day', 'wp_user_id',
]);
foreach ($excluded as $col) {
    chk(!$R::has($col), "registry: excluded column $col is not synced");
}

// Every comparator named in the registry exists.
foreach ($R::fields() as $col => $f) {
    chk(in_array($f['comparator'], $C::ALL, true), "registry: $col names a known comparator");
}

// No two entries read the same WordPress key (two maps disagreeing = K13).
$keys = array_map(static fn ($f) => $f['wp']['type'] . ':' . $f['wp']['key'], $R::fields());
chk(count($keys) === count(array_unique($keys)), 'registry: no WordPress key is mapped twice');

// ---------------------------------------------------------------------------
// Part 2 — legacy accessors are byte-identical to v1.0.592
// ---------------------------------------------------------------------------
$v592_fields = [
    'first_name', 'last_name', 'client_email', 'client_phone_1', 'client_phone_2',
    'street_name', 'city', 'province', 'postal_code', 'delivery_street_name',
    'delivery_city', 'delivery_province', 'delivery_postal_code',
    'alternate_contact_name', 'alternate_contact_phone_1', 'alternate_contact_phone_2',
    'alternate_contact_email',
];
chk(MealsDB_Sync::get_wp_authoritative_fields() === $v592_fields, 'legacy: get_wp_authoritative_fields() unchanged (17, same order)');

$v592_map = [
    'first_name' => ['type' => 'core', 'key' => 'first_name'],
    'last_name' => ['type' => 'core', 'key' => 'last_name'],
    'client_email' => ['type' => 'core', 'key' => 'user_email'],
    'client_phone_1' => ['type' => 'meta', 'key' => 'billing_phone'],
    'city' => ['type' => 'meta', 'key' => 'billing_city'],
    'province' => ['type' => 'meta', 'key' => 'billing_state'],
    'postal_code' => ['type' => 'meta', 'key' => 'billing_postcode'],
    'delivery_city' => ['type' => 'meta', 'key' => 'shipping_city'],
    'delivery_province' => ['type' => 'meta', 'key' => 'shipping_state'],
    'delivery_postal_code' => ['type' => 'meta', 'key' => 'shipping_postcode'],
    'client_phone_2' => ['type' => 'meta', 'key' => 'shipping_phone'],
    'street_name' => ['type' => 'meta', 'key' => 'billing_address_1'],
    'delivery_street_name' => ['type' => 'meta', 'key' => 'shipping_address_1'],
    'alternate_contact_name' => ['type' => 'meta', 'key' => 'alternate_contact_name'],
    'alternate_contact_phone_1' => ['type' => 'meta', 'key' => 'alternate_contact_phone_1'],
    'alternate_contact_phone_2' => ['type' => 'meta', 'key' => 'alternate_contact_phone_2'],
    'alternate_contact_email' => ['type' => 'meta', 'key' => 'alternate_contact_email'],
    // v1.0.592 also carried next_order_date / next_delivery_date -> mealsdb_*
    // keys; they were never in the synced field list, so nothing read them.
];
$map = MealsDB_Sync::get_field_to_wp_meta_map();
ksort($map); ksort($v592_map);
chk($map === $v592_map, 'legacy: get_field_to_wp_meta_map() unchanged for every synced field');
chk(!isset($map['next_order_date']) && !isset($map['next_delivery_date']), 'legacy: computed next_* dates dropped from the map');

// ---------------------------------------------------------------------------
// Part 3 — comparators (ITEM 2)
// ---------------------------------------------------------------------------
// K21 Test 1: formatting-only phone difference is equal.
chk($C::equals('phone', '(506) 555-0100', '5065550100'), 'T1: (506) 555-0100 == 5065550100');
chk($C::equals('phone', '1-506-555-0100', '506 555 0100'), 'phone: leading NANP 1 ignored');
chk(!$C::equals('phone', '506-988-1777 Denise', '5069881777'), 'phone: annotated number is not the bare number (K20)');
chk(!$C::equals('phone', '5065550100', '5065550199'), 'phone: different numbers differ');
// K21 Test 7: the '0' placeholder is blank.
chk($C::is_blank('phone', '0'), "T7: shipping_phone '0' is blank");
chk($C::is_blank('phone', '-') && $C::is_blank('phone', 'n/a') && $C::is_blank('phone', ''), 'phone: placeholders blank');
chk(!$C::is_blank('phone', '204-7570 (Joyce)'), 'phone: 7-digit local number is not blank');
chk($C::equals('phone', '0', ''), 'phone: two blanks are equal');
chk(!$C::equals('phone', '0', '5065550100'), 'phone: blank never equals a number');

// text
chk($C::equals('text', '  123  Main   St ', '123 main st'), 'text: case, trim, internal whitespace collapsed');
chk($C::equals('text', "Zone\u{00A0}1", 'zone 1'), 'text: NBSP treated as whitespace');
chk($C::equals('text', 'Élise', 'élise'), 'text: Unicode case-folding');
chk(!$C::equals('text', 'Main St', 'Main Street'), 'text: no fuzzy matching');
chk($C::is_blank('text', "  \u{00A0} "), 'text: whitespace/NBSP-only is blank');

// email / postal / code
chk($C::equals('email', ' Ann@Example.COM ', 'ann@example.com'), 'email: case/trim');
chk($C::equals('postal', 'e1c 4b5', 'E1C4B5'), 'postal: case and spaces');
chk(!$C::equals('postal', 'E1C4B5', 'E1C4B6'), 'postal: different codes differ');
chk($C::equals('code', ' nb', 'NB'), 'code: case/trim');

// integer
chk($C::equals('integer', '2', ' 02 '), 'integer: leading zeros / trim');
chk(!$C::equals('integer', '2', '3'), 'integer: different values differ');
chk($C::is_blank('integer', '0') && $C::is_blank('integer', ''), "integer: '' and '0' are blank");
chk(!$C::is_blank('integer', '2 weeks') && !$C::parses('integer', '2 weeks'),
    "T43: '2 weeks' is not blank and not a fill source");
chk(!$C::equals('integer', '2 weeks', '2'), "integer: '2 weeks' vs 2 is a conflict, not equal");

// date (K21 Tests 20 / 42 at the comparator level)
chk($C::equals('date', '2024-09-03', '2024-9-3'), 'date: zero-padding irrelevant');
chk($C::equals('date', '2024-09-03', '2024-09-03 00:00:00'), 'date: DATETIME shape accepted');
chk(!$C::equals('date', '2024-09-03', '2024-09-04'), 'date: different days differ');
chk(!$C::is_blank('date', '202409-02-03'), "T20/T42: '202409-02-03' is NOT blank");
chk(!$C::parses('date', '202409-02-03'), "T42: '202409-02-03' is not a fill source");
chk(!$C::equals('date', '202409-02-03', '2024-09-02'), 'T20: unparseable vs real date is a conflict');
chk($C::equals('date', '202409-02-03', '202409-02-03'), 'date: identical garbage compares equal (no phantom conflict)');
chk(!$C::parses('date', '2024-02-30'), 'date: impossible calendar date does not parse');
chk($C::is_blank('date', '0') && $C::is_blank('date', '0000-00-00'), "date: '0' and zero-date are blank placeholders");

// Unknown comparator fails loudly instead of falling through to text.
$threw = false;
try { $C::is_blank('txet', 'x'); } catch (InvalidArgumentException $e) { $threw = true; }
chk($threw, 'unknown comparator name throws');

// ---------------------------------------------------------------------------
// Part 4 — client_type by class (ITEM 3)
// ---------------------------------------------------------------------------
// K21 Test 8.
foreach (['sdnb', 'SDNB', 'sdnb rural', 'Extra Mural', 'extramural', ' sdnb  rural '] as $g) {
    chk($C::equals('client_type', $g, 'SDNB'), "T8: customer_group '$g' == SDNB");
}
chk($C::equals('client_type', 'veterans', 'Veteran'), 'veterans == Veteran');
chk($C::equals('client_type', 'private', 'Private'), 'private == Private');
// K21 Test 10.
chk(!$C::equals('client_type', 'private', 'SDNB'), 'T10: private vs SDNB is a conflict');
chk(!$C::equals('client_type', 'veterans', 'SDNB'), 'veterans vs SDNB is a conflict');
// K21 Test 31 (comparator half): unrecognised is flagged, NOT blank.
chk($C::is_unrecognised('client_type', 'meals on wheels'), 'T31: unrecognised customer_group flagged for logging');
chk(!$C::is_blank('client_type', 'meals on wheels'), 'T31: unrecognised is not blank (never overwritten by a fill)');
chk(!$C::equals('client_type', 'meals on wheels', 'SDNB'), 'unrecognised vs SDNB is a conflict');
chk($C::is_blank('client_type', '  '), 'blank customer_group is blank');

// Canonical fill values (K21 Test 9 at the mapping level).
$M = 'MealsDB_Migration_Consolidated';
chk($M::client_type_to_customer_group('SDNB') === 'sdnb', 'canonical: SDNB -> sdnb');
chk($M::client_type_to_customer_group('Veteran') === 'veterans', 'canonical: Veteran -> veterans');
chk($M::client_type_to_customer_group('Private') === 'private', 'canonical: Private -> private');
chk($M::client_type_to_customer_group('Other') === null, 'canonical: unknown class -> null');
// Directive 7 contract unchanged: private -> null for the gov-only mapper.
chk($M::customer_group_to_client_type('private') === null, 'Directive 7: customer_group_to_client_type(private) still null');
chk($M::customer_group_to_client_type('extramural') === 'SDNB', 'extramural now maps to SDNB (provisional)');

// ---------------------------------------------------------------------------
// Part 5 — nickname blank rule (ITEM 5, registry level)
// ---------------------------------------------------------------------------
chk($R::is_blank('delivery_initials', 'wp', 'Betty Ann'), "T14: nickname 'Betty Ann' counts as blank");
chk($R::is_blank('delivery_initials', 'wp', 'admin'), "nickname 'admin' counts as blank");
chk(!$R::is_blank('delivery_initials', 'wp', 'MTR'), "T15: nickname 'MTR' is not blank");
chk(!$R::is_blank('delivery_initials', 'wp', ' MTR '), 'nickname: surrounding spaces ignored');
chk($R::is_blank('delivery_initials', 'meals', ''), 'delivery_initials: empty is blank on the MealsDB side');
chk(!$R::is_blank('delivery_initials', 'meals', 'Betty Ann'), 'the 3-char rule applies to the WordPress side only');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
