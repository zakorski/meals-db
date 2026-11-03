<?php
/**
 * Tests for directive K21 ITEM 7: the nightly fill-blank reconcile
 * (MealsDB_Sync_Reconciler::run_reconcile, reached via
 * MealsDB_Sync::run_nightly_sync). It has no runtime breakers (removed by
 * operator decision; the no-overwrite invariant test is the release gate).
 *
 * K21 Tests covered: 2, 3, 4, 7, 9, 12, 14, 15, 16, 19, 24, 25, 26, 28, 29,
 * 30, 33, 37, 40 (fill half), 42, 43, plus placeholder-heavy runs proceeding,
 * the missing-key alarm and second-run idempotency.
 *
 * Run: php tests/test-sync-nightly-k21.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class MealsDB_Rate_Limiter {
    public static function check_rate_limit(string $bucket): bool { return true; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
require_once __DIR__ . '/fixtures/k21-sync-fake.php';

$failures = []; $passed = 0;
function chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $label;
}

/**
 * Fresh world. Every account gets the "presence" keys an Enzebra user
 * normally carries (so the missing-key guard does not skip WordPress fills
 * unless a test wants it to), then the test's own values on top.
 */
function world(array $accounts, int $pad = 20, ?array $common_meta = null): wpdb {
    // Quiet accounts (nothing to fill, nothing to conflict) so breaker ratios
    // resemble production (~1,031 accounts) instead of 1-of-1 = 100%.
    for ($i = 1; $i <= $pad; $i++) {
        $accounts[5000 + $i] = ['clients' => [50000 + $i => []]];
    }
    $db = new wpdb();
    $GLOBALS['wpdb']  = $db;
    $GLOBALS['users'] = []; $GLOBALS['meta'] = []; $GLOBALS['wp_writes'] = [];
    $GLOBALS['emails_taken'] = []; $GLOBALS['filters'] = [];
    unset($GLOBALS['syncing_during_update'], $GLOBALS['email_filter_active_during_update'], $GLOBALS['syncing_during_meta_write']);
    $GLOBALS['options']['mealsdb_settings'] = ['shadow_mode' => '0'];
    $common_meta = $common_meta ?? array_fill_keys([
        'billing_phone', 'shipping_phone', 'billing_address_1', 'billing_city', 'billing_state', 'billing_postcode',
        'shipping_address_1', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_address_2',
        'billing_address_2', 'customer_group', 'service_centre_charged', 'nickname', 'commence_date', 'delivery_frequency',
    ], '');
    foreach ($accounts as $uid => $a) {
        foreach ($a['clients'] as $cid => $over) {
            $row = ['client_id' => $cid, 'wp_user_id' => $uid, 'client_type' => 'SDNB', 'active' => 1,
                'delivery_area_zone' => '', 'delivery_day' => ''];
            foreach (array_keys(MealsDB_Sync_Registry::fields()) as $c) { $row[$c] = $row[$c] ?? ''; }
            $row['client_type'] = 'SDNB';
            $db->clients[] = array_merge($row, $over);
        }
        $u = new WP_User($uid);
        foreach ($a['core'] ?? [] as $k => $v) { $u->$k = $v; }
        $u->roles = $a['roles'] ?? ['customer'];
        $GLOBALS['users'][$uid] = $u;
        foreach ($common_meta as $k => $v) { $GLOBALS['meta']["$uid|$k"] = $v; }
        foreach ($a['meta'] ?? [] as $k => $v) {
            if ($v === null) { unset($GLOBALS['meta']["$uid|$k"]); } else { $GLOBALS['meta']["$uid|$k"] = $v; }
        }
    }
    return $db;
}

function client(wpdb $db, int $cid): array {
    foreach ($db->clients as $r) { if ((int) $r['client_id'] === $cid) { return $r; } }
    return [];
}

function audit_for(wpdb $db, string $action, string $field): array {
    return array_values(array_filter($db->audit, static fn ($a) => $a['action'] === $action && $a['field'] === $field));
}

// ---------------------------------------------------------------------------
// Fills in both directions; conflicts untouched
// ---------------------------------------------------------------------------
$db = world([
    100 => [
        'clients' => [10 => [
            'city' => '', 'street_name' => '14 Elm St', 'last_name' => 'Smith', 'client_phone_2' => '506-555-0200',
            'delivery_initials' => 'BAE', 'client_type' => 'Veteran', 'delivery_area_name' => '',
            'service_commence_date' => '', 'delivery_frequency' => '',
        ]],
        'core' => ['last_name' => 'Smyth'],
        'meta' => [
            'billing_city' => 'Moncton', 'billing_address_1' => '', 'shipping_phone' => '0',
            'nickname' => 'Betty Ann', 'customer_group' => '', 'shipping_address_2' => 'Zone 3', 'billing_address_2' => 'Zone 3',
            'commence_date' => '202409-02-03', 'delivery_frequency' => '2 weeks',
        ],
    ],
]);
MealsDB_Sync::run_nightly_sync();
$c = client($db, 10);
chk($c['city'] === 'Moncton', 'T2: MealsDB blank, WP populated -> MealsDB filled');
chk($GLOBALS['meta']['100|billing_address_1'] === '14 Elm St', 'T3: WP blank, MealsDB populated -> WP filled');
chk($c['last_name'] === 'Smith' && $GLOBALS['users'][100]->last_name === 'Smyth', 'T4: both populated, different -> nothing written');
chk(isset($db->live()['100|last_name']), 'T4: ... and a conflict is open');
chk($GLOBALS['meta']['100|shipping_phone'] === '506-555-0200', "T7: shipping_phone '0' filled from client_phone_2");
chk($GLOBALS['meta']['100|nickname'] === 'BAE', 'T14: nickname Betty Ann -> BAE');
chk($GLOBALS['meta']['100|customer_group'] === 'veterans', 'T9: blank customer_group filled with the canonical group');
chk($c['delivery_area_name'] === 'Zone 3', 'delivery_area_name filled from shipping_address_2');
chk($c['delivery_day'] === 'thursday', 'T19: delivery_day filled (was blank) from the filled area');
chk($c['service_commence_date'] === '' && isset($db->live()['100|service_commence_date']), 'T42: unparseable WP date never fills; held as a conflict');
chk($c['delivery_frequency'] === '' && isset($db->live()['100|delivery_frequency']), 'T43: non-integer WP frequency never fills');
chk($c['delivery_initials'] === 'BAE', 'T16: delivery_initials unchanged');

$fill = audit_for($db, 'sync_fill_client', 'city');
chk(count($fill) === 1 && $fill[0]['old'] === '' && $fill[0]['new'] === 'Moncton' && $fill[0]['source'] === 'nightly',
    'fills are audit-logged with old, new and source');
chk(count(audit_for($db, 'sync_fill_wp', 'billing_address_1')) === 1, 'WP fills audit-logged under the meta key');
chk(!empty($GLOBALS['syncing_during_meta_write']) && !in_array(false, $GLOBALS['syncing_during_meta_write'], true),
    'T28: every WordPress meta write ran with the sync hooks suppressed');
chk(MealsDB_Sync::is_syncing() === false, 'T28: hook flag restored after the run');
chk(in_array('sync.reconcile_applied', $db->event_names(), true), 'summary event recorded');
$summary = array_values(array_filter($db->events, static fn ($e) => ($e['event'] ?? '') === 'sync.reconcile_applied'))[0] ?? [];
$ctx = json_decode((string) ($summary['context'] ?? ''), true) ?: [];
chk(($ctx['applied']['field:client_phone_2']['wp'] ?? 0) === 1 && ($ctx['fields']['field:street_name']['fill'] ?? 0) === 1,
    'summary counts for sensitive-named fields survive the event-log scrubber');
chk(strpos((string) ($summary['context'] ?? ''), 'redacted') === false, 'summary context carries no redactions');

// T16 / T29 across everything written.
$written_cols = [];
foreach ($db->client_writes as $w) { if ($w[0] === 'update') { $written_cols = array_merge($written_cols, array_keys($w[2])); } }
chk(!in_array('delivery_initials', $written_cols, true), 'T16: no nightly write touched delivery_initials');
chk(array_intersect($written_cols, MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS) === [], 'T29: no encrypted column written');
$wp_keys = array_map(static fn ($w) => $w[0] === 'meta' ? $w[2] : 'core', $GLOBALS['wp_writes']);
chk(array_intersect($wp_keys, ['individual_id', 'requisition_id', 'vat_number', 'vet_health_card']) === [], 'T29: no encrypted value pushed to WordPress');

// Second run: everything that could be filled was; nothing more is written.
$writes_before = count($db->client_writes) + count($GLOBALS['wp_writes']);
$stats = MealsDB_Sync_Reconciler::run_reconcile();
chk(count($db->client_writes) + count($GLOBALS['wp_writes']) === $writes_before && $stats['applied'] === [], 'second run writes nothing (idempotent)');

// T15: a 3-character nickname is never touched.
$db = world([110 => ['clients' => [11 => ['delivery_initials' => 'RKU']], 'meta' => ['nickname' => 'MTR']]]);
MealsDB_Sync::run_nightly_sync();
chk($GLOBALS['meta']['110|nickname'] === 'MTR' && !isset($db->live()['110|delivery_initials']), 'T15: MTR kept, no conflict');

// Regression (caught by Test 32): a delivery_area_name fill writes BOTH
// address_2 keys, but must not overwrite a populated billing_address_2 when
// only shipping_address_2 is blank.
$db = world([130 => ['clients' => [14 => ['delivery_area_name' => 'Zone 3']],
    'meta' => ['shipping_address_2' => '', 'billing_address_2' => 'Zone 1']]]);
MealsDB_Sync::run_nightly_sync();
chk($GLOBALS['meta']['130|shipping_address_2'] === 'Zone 3', 'ITEM 4: blank shipping_address_2 filled');
chk($GLOBALS['meta']['130|billing_address_2'] === 'Zone 1', 'ITEM 4: populated billing_address_2 NOT overwritten by a fill');

$db = world([131 => ['clients' => [15 => ['delivery_area_name' => 'Zone 3']],
    'meta' => ['shipping_address_2' => '', 'billing_address_2' => '']]]);
MealsDB_Sync::run_nightly_sync();
chk($GLOBALS['meta']['131|shipping_address_2'] === 'Zone 3' && $GLOBALS['meta']['131|billing_address_2'] === 'Zone 3',
    'ITEM 4: both blank -> both filled');

// ---------------------------------------------------------------------------
// Derived fill-blank (ITEM 6 / Test 40, nightly half)
// ---------------------------------------------------------------------------
$db = world([
    120 => ['clients' => [12 => ['service_center_charged' => '', 'delivery_area_zone' => 'M']], 'meta' => ['service_centre_charged' => 'sussex']],
    121 => ['clients' => [13 => ['service_center_charged' => '', 'delivery_area_zone' => '']], 'meta' => ['service_centre_charged' => 'sussex']],
]);
MealsDB_Sync::run_nightly_sync();
chk(client($db, 12)['service_center_charged'] === 'sussex' && client($db, 12)['delivery_area_zone'] === 'M',
    'T40: fill does NOT overwrite a populated, different zone');
chk(in_array('sync.derived_mismatch', $db->event_names(), true), 'T40: mismatch logged');
chk(client($db, 13)['delivery_area_zone'] === 'S' && in_array('sync.derived_zone_changed', $db->event_names(), true),
    'blank zone filled from the filled service centre, and evented');

// ---------------------------------------------------------------------------
// Multi-client, staff, inactive (T33, T30, T37)
// ---------------------------------------------------------------------------
$db = world([
    200 => ['clients' => [20 => ['client_phone_2' => ''], 21 => ['client_phone_2' => '506-555-0300']], 'meta' => ['shipping_phone' => '']],
    1   => ['clients' => [1 => ['city' => '']], 'meta' => ['billing_city' => 'Admin Town'], 'roles' => ['administrator']],
    500 => ['clients' => [50 => ['city' => '']], 'meta' => ['billing_city' => 'Staffville']],
    400 => ['clients' => [40 => ['city' => '', 'active' => 0]], 'meta' => ['billing_city' => 'Gone']],
]);
$db->staff = [500];
MealsDB_Sync::run_nightly_sync();
chk(client($db, 20)['client_phone_2'] === '506-555-0300' && $GLOBALS['meta']['200|shipping_phone'] === '506-555-0300',
    'T33: sibling value fills the blank sibling and WordPress');
chk(client($db, 1)['city'] === '' && client($db, 50)['city'] === '', 'T30: admin (wp 1) and meals_staff accounts never synced');
chk(client($db, 40)['city'] === '', 'T37: inactive client never written');

// ---------------------------------------------------------------------------
// Email collision, notification suppression (T24, T25)
// ---------------------------------------------------------------------------
$db = world([
    300 => ['clients' => [30 => ['client_email' => 'taken@example.org']], 'core' => ['user_email' => '']],
    301 => ['clients' => [31 => ['client_email' => 'free@example.org']], 'core' => ['user_email' => '']],
    302 => ['clients' => [32 => ['city' => '']], 'meta' => ['billing_city' => 'Dieppe']],
]);
$GLOBALS['emails_taken'] = ['taken@example.org'];
$stats = MealsDB_Sync_Reconciler::run_reconcile();
chk($GLOBALS['users'][300]->user_email === '' && $stats['fill_failures'] === 1, 'T24: collision -> fill failure recorded, nothing written');
chk(in_array('sync.fill_failed', $db->event_names(), true), 'T24: failure evented');
chk($GLOBALS['users'][301]->user_email === 'free@example.org' && client($db, 32)['city'] === 'Dieppe', 'T24: the run continued for other accounts');
chk(($GLOBALS['email_filter_active_during_update'] ?? false) === true && !has_filter('send_email_change_email'), 'T25: email-change notice suppressed for sync writes');

// ---------------------------------------------------------------------------
// Shadow mode (T26)
// ---------------------------------------------------------------------------
$db = world([
    600 => ['clients' => [60 => ['street_name' => '1 Main St', 'city' => '']], 'meta' => ['billing_address_1' => '', 'billing_city' => 'Sussex']],
]);
$GLOBALS['options']['mealsdb_settings'] = ['shadow_mode' => '1'];
$stats = MealsDB_Sync_Reconciler::run_reconcile();
chk($GLOBALS['meta']['600|billing_address_1'] === '' && $GLOBALS['wp_writes'] === [], 'T26: shadow mode -> no WordPress writes');
chk(($stats['shadow_suppressed']['street_name'] ?? 0) === 1 && in_array('sync.shadow_suppressed', $db->event_names(), true),
    'T26: suppressed writes counted and reported');
chk(client($db, 60)['city'] === 'Sussex', 'T26: MealsDB fills still happen');
$GLOBALS['options']['mealsdb_settings'] = ['shadow_mode' => '0'];

// ---------------------------------------------------------------------------
// No breakers: a placeholder-heavy run (30% of accounts filling over
// shipping_phone '0') is a correct run and proceeds.
// ---------------------------------------------------------------------------
$make = static function (int $placeholders): array {
    $accounts = [];
    for ($i = 1; $i <= 10; $i++) {
        $uid = 700 + $i;
        $phone = '506-555-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
        $accounts[$uid] = ['clients' => [7000 + $i => ['client_phone_2' => $phone, 'city' => '']],
            'meta' => ['shipping_phone' => $i <= $placeholders ? '0' : $phone, 'billing_city' => 'Moncton']];
    }
    return $accounts;
};
$db = world($make(3), 0);
$stats = MealsDB_Sync_Reconciler::run_reconcile();
chk(!isset($stats['failed']) && !in_array('sync.mass_blank_refused', $db->event_names(), true), 'no breaker: 3/10 placeholder fills proceed');
chk($GLOBALS['meta']['701|shipping_phone'] === '506-555-0001' && $GLOBALS['meta']['704|shipping_phone'] === '506-555-0004',
    'no breaker: placeholders filled, real values untouched');
chk(client($db, 7005)['city'] === 'Moncton', 'no breaker: unrelated fills happen in the same run');

// ---------------------------------------------------------------------------
// Missing key: absent for every user -> alarmed, fills still proceed
// ---------------------------------------------------------------------------
$db = world([
    800 => ['clients' => [80 => ['payment_method' => 'Cheque', 'city' => '']], 'meta' => ['billing_city' => 'Moncton']],
    801 => ['clients' => [81 => ['payment_method' => 'Cash']]],
]);
$stats = MealsDB_Sync_Reconciler::run_reconcile();
chk(in_array('sync.meta_key_missing', $db->event_names(), true), 'missing key: alarmed');
chk(($GLOBALS['meta']['800|payment_method'] ?? '') === 'Cheque' && ($GLOBALS['meta']['801|payment_method'] ?? '') === 'Cash',
    'missing key: fills proceed (creating a key overwrites nothing)');
chk(client($db, 80)['city'] === 'Moncton', 'missing key: other fields filled');

// ---------------------------------------------------------------------------
// Detection-only mode still writes nothing
// ---------------------------------------------------------------------------
$db = world([900 => ['clients' => [90 => ['city' => '']], 'meta' => ['billing_city' => 'Moncton']]]);
MealsDB_Sync_Reconciler::run_detection();
chk($db->client_writes === [] && $GLOBALS['wp_writes'] === [] && client($db, 90)['city'] === '', 'run_detection writes no client data');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
