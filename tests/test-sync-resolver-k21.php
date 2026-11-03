<?php
/**
 * Tests for directive K21 ITEMS 10-12: resolution writes
 * (MealsDB_Sync_Resolver via MealsDB_Sync_Conflict_View::apply_choices).
 *
 * Every scenario runs real detection, renders the card to get the hash the
 * page would carry, then resolves through the same entry point the AJAX
 * endpoint uses. Writes go through the real MealsDB_Sync_Mutate against the
 * shared fake $wpdb, and WordPress writes through the fake wp_update_user /
 * update_user_meta.
 *
 * K21 Tests covered: 11, 16, 18, 19 (resolution half), 24, 25, 26, 28, 40
 * (resolution half), 45, 46, plus the lossy client_type fill (9).
 *
 * Run: php tests/test-sync-resolver-k21.php
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

$V = 'MealsDB_Sync_Conflict_View';
$GLOBALS['current_user_id'] = 7;

/** Fresh world: one fake DB, accounts from $spec. Returns the db. */
function world(array $accounts): wpdb {
    $db = new wpdb();
    $GLOBALS['wpdb']  = $db;
    $GLOBALS['users'] = []; $GLOBALS['meta'] = []; $GLOBALS['wp_writes'] = [];
    $GLOBALS['emails_taken'] = []; $GLOBALS['filters'] = [];
    unset($GLOBALS['syncing_during_update'], $GLOBALS['email_filter_active_during_update']);
    $GLOBALS['options']['mealsdb_settings'] = ['shadow_mode' => '0'];
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
        $u->roles = ['customer'];
        $GLOBALS['users'][$uid] = $u;
        foreach ($a['meta'] ?? [] as $k => $v) { $GLOBALS['meta']["$uid|$k"] = $v; }
    }
    MealsDB_Sync_Reconciler::run_detection();
    return $db;
}

/** The conflict row for (uid, field) as the page renders it. */
function card_row(int $uid, string $field): ?array {
    $page = MealsDB_Sync_Conflict_View::page($field, 'open', 1);
    foreach ($page['cards'] as $card) {
        if ($card['wp_user_id'] !== $uid) { continue; }
        foreach ($card['conflicts'] as $row) {
            if ($row['column'] === $field) { return $row; }
        }
    }
    return null;
}

function keep(int $uid, string $field, string $side): array {
    $row = card_row($uid, $field);
    if ($row === null) { return ['code' => 'no_card_row', 'ok' => false, 'message' => '']; }
    $r = MealsDB_Sync_Conflict_View::apply_choices($uid, [[
        'conflict_id' => $row['conflict']['id'], 'values_hash' => $row['conflict']['hash'],
        'action' => 'keep', 'side' => $side,
    ]], 7);
    return $r['results'][0] ?? ['code' => 'error:' . (string) $r['error'], 'ok' => false, 'message' => ''];
}

function client(wpdb $db, int $cid): array {
    foreach ($db->clients as $r) { if ((int) $r['client_id'] === $cid) { return $r; } }
    return [];
}

function conflict_status(wpdb $db, int $uid, string $field): array {
    $rows = array_values(array_filter($db->conflicts, static fn ($r) => (int) $r['wp_user_id'] === $uid && $r['field'] === $field));
    return end($rows) ?: [];
}

// ---------------------------------------------------------------------------
// Keep WordPress / keep client, single client
// ---------------------------------------------------------------------------
$db = world([100 => ['clients' => [10 => ['last_name' => 'Smith']], 'core' => ['last_name' => 'Smyth']]]);
$r = keep(100, 'last_name', 'wp');
chk($r['ok'] && $r['code'] === 'resolved', 'keep WP: resolved');
chk(client($db, 10)['last_name'] === 'Smyth', 'keep WP: client written with the WP value');
chk($GLOBALS['wp_writes'] === [], 'keep WP: WordPress untouched');
$c = conflict_status($db, 100, 'last_name');
chk($c['status'] === 'resolved' && $c['resolution'] === 'wp' && (int) $c['resolved_by'] === 7 && $c['open_dedup'] === null,
    'keep WP: conflict resolved, operator and side recorded, slot freed');
$a = end($db->audit);
chk($a['action'] === 'sync_resolve_client' && $a['target_id'] === 10 && $a['field'] === 'last_name'
    && $a['old'] === 'Smith' && $a['new'] === 'Smyth' && $a['source'] === 'resolution:wp',
    'keep WP: audit entry with old, new and direction');
chk(!isset($GLOBALS['syncing_during_update']), 'keep WP: wp_update_user never called');

$stats = MealsDB_Sync_Reconciler::run_detection();
chk(($stats['fields']['last_name']['equal'] ?? 0) === 1 && conflict_status($db, 100, 'last_name')['status'] === 'resolved',
    'after resolution the nightly sees equal values and leaves the resolved row alone');

$db = world([100 => ['clients' => [10 => ['last_name' => 'Smith']], 'core' => ['last_name' => 'Smyth']]]);
$r = keep(100, 'last_name', 'client:10');
chk($r['ok'], 'keep client: resolved');
chk($GLOBALS['users'][100]->last_name === 'Smith' && client($db, 10)['last_name'] === 'Smith', 'keep client: WordPress written, client untouched');
chk(($GLOBALS['syncing_during_update'] ?? false) === true && MealsDB_Sync::is_syncing() === false,
    'T28: WP write ran with sync hooks suppressed, flag restored after');
$a = end($db->audit);
chk($a['action'] === 'sync_resolve_wp' && $a['target_id'] === 100 && $a['source'] === 'resolution:client:10', 'keep client: WP write audited');
chk(conflict_status($db, 100, 'last_name')['resolution'] === 'client:10', 'keep client: resolution side recorded');

// ---------------------------------------------------------------------------
// Multi-client account
// ---------------------------------------------------------------------------
$db = world([200 => ['clients' => [20 => ['client_type' => 'Veteran', 'first_name' => 'Bob'], 21 => ['client_type' => 'Private', 'first_name' => 'Rob']],
    'core' => ['first_name' => 'Bob'], 'meta' => ['customer_group' => 'veterans']]]);
$r = keep(200, 'first_name', 'client:21');
chk($r['ok'], 'multi-client keep: resolved');
chk($GLOBALS['users'][200]->first_name === 'Rob' && client($db, 20)['first_name'] === 'Rob' && client($db, 21)['first_name'] === 'Rob',
    'multi-client keep: WP and the other client get the chosen value');

// ---------------------------------------------------------------------------
// client_type: by class, lossy toward MealsDB (Test 11)
// ---------------------------------------------------------------------------
$db = world([300 => ['clients' => [30 => ['client_type' => 'SDNB'], 31 => ['client_type' => 'Private']],
    'meta' => ['customer_group' => 'sdnb rural']]]);
$r = keep(300, 'client_type', 'client:30');
chk($r['ok'], 'client_type keep SDNB: resolved');
chk($GLOBALS['meta']['300|customer_group'] === 'sdnb rural', "T11: 'sdnb rural' never replaced by 'sdnb' (same class)");
chk(client($db, 31)['client_type'] === 'SDNB', 'client_type: differing client set to the class');

$db = world([310 => ['clients' => [32 => ['client_type' => 'SDNB']], 'meta' => ['customer_group' => 'private']]]);
chk(keep(310, 'client_type', 'client:32')['ok'] && $GLOBALS['meta']['310|customer_group'] === 'sdnb', 'client_type to WP writes the canonical group');
$db = world([320 => ['clients' => [33 => ['client_type' => 'SDNB']], 'meta' => ['customer_group' => 'veterans']]]);
chk(keep(320, 'client_type', 'wp')['ok'] && client($db, 33)['client_type'] === 'Veteran', 'client_type to MealsDB writes the class');

// ---------------------------------------------------------------------------
// Email (Tests 24, 25)
// ---------------------------------------------------------------------------
$db = world([400 => ['clients' => [40 => ['client_email' => 'new@example.org']], 'core' => ['user_email' => 'old@example.org']]]);
$r = keep(400, 'client_email', 'client:40');
chk($r['ok'] && $GLOBALS['users'][400]->user_email === 'new@example.org', 'email: WP user_email updated');
chk(($GLOBALS['email_filter_active_during_update'] ?? false) === true && !has_filter('send_email_change_email'),
    'T25: email-change notice suppressed during the write, filter removed after');

$db = world([410 => ['clients' => [41 => ['client_email' => 'taken@example.org'], 42 => ['client_email' => 'z@example.org']],
    'core' => ['user_email' => 'mine@example.org']]]);
$GLOBALS['emails_taken'] = ['taken@example.org'];
$r = keep(410, 'client_email', 'client:41');
chk(!$r['ok'] && $r['code'] === 'write_failed' && strpos($r['message'], 'already used') !== false, 'T24: collision reported with the reason');
chk(client($db, 42)['client_email'] === 'z@example.org' && $GLOBALS['users'][410]->user_email === 'mine@example.org',
    'T24: WordPress refused, so MealsDB was not touched');
chk(conflict_status($db, 410, 'client_email')['status'] === 'open', 'T24: conflict stays open');

// ---------------------------------------------------------------------------
// Address_2 pair and derived fields (Tests 12, 18, 19, 40)
// ---------------------------------------------------------------------------
$db = world([500 => ['clients' => [50 => ['delivery_area_name' => 'Zone 3', 'delivery_day' => 'tuesday']],
    'meta' => ['shipping_address_2' => 'Zone 1', 'billing_address_2' => 'Zone 1']]]);
$r = keep(500, 'delivery_area_name', 'client:50');
chk($r['ok'], 'delivery_area_name keep client: resolved');
chk($GLOBALS['meta']['500|shipping_address_2'] === 'Zone 3' && $GLOBALS['meta']['500|billing_address_2'] === 'Zone 3',
    'T12: both address_2 keys written');
chk(client($db, 50)['delivery_day'] === 'thursday', 'T19: delivery_day recomputed from the resolved area');
chk(in_array('sync.derived_day_changed', $db->event_names(), true), 'derived day change evented');
chk(count(array_filter($db->audit, static fn ($x) => $x['action'] === 'sync_derived_recompute' && $x['old'] === 'tuesday' && $x['new'] === 'thursday')) === 1,
    'derived day change audited with old and new');

$db = world([510 => ['clients' => [51 => ['service_center_charged' => 'Moncton', 'delivery_area_zone' => 'M']],
    'meta' => ['service_centre_charged' => 'sussex']]]);
chk(keep(510, 'service_center_charged', 'wp')['ok'], 'service centre keep WP: resolved');
chk(client($db, 51)['service_center_charged'] === 'sussex' && client($db, 51)['delivery_area_zone'] === 'S',
    'T40: resolution recomputes and overwrites the populated zone');
chk(in_array('sync.derived_zone_changed', $db->event_names(), true), 'T18: zone change evented');

$db = world([520 => ['clients' => [52 => ['delivery_area_name' => 'Zone 1', 'delivery_day' => 'tuesday']],
    'meta' => ['shipping_address_2' => 'Zone 9', 'billing_address_2' => 'Zone 9']]]);
chk(keep(520, 'delivery_area_name', 'wp')['ok'], 'unknown zone keep WP: resolved');
chk(client($db, 52)['delivery_day'] === 'tuesday' && in_array('sync.derived_unresolved', $db->event_names(), true),
    'T41: unresolvable zone leaves delivery_day unchanged and logs');

// ---------------------------------------------------------------------------
// Refusals: unparseable, blank, shadow mode, stale, partial failure
// ---------------------------------------------------------------------------
$db = world([600 => ['clients' => [60 => ['service_commence_date' => '2024-09-02']], 'meta' => ['commence_date' => '202409-02-03']]]);
$r = keep(600, 'service_commence_date', 'wp');
chk(!$r['ok'] && $r['code'] === 'unparseable_choice' && client($db, 60)['service_commence_date'] === '2024-09-02',
    'unparseable WP date cannot be kept; nothing written');
chk(keep(600, 'service_commence_date', 'client:60')['ok'] && $GLOBALS['meta']['600|commence_date'] === '2024-09-02',
    'keeping the valid MealsDB date fixes WordPress');

$db = world([610 => ['clients' => [61 => ['city' => 'Moncton'], 62 => ['city' => 'Dieppe']]]]);
$r = keep(610, 'city', 'wp');
chk(!$r['ok'] && $r['code'] === 'blank_choice', 'a blank side cannot be kept');

$db = world([620 => ['clients' => [63 => ['last_name' => 'Smith']], 'core' => ['last_name' => 'Smyth']]]);
$GLOBALS['options']['mealsdb_settings'] = ['shadow_mode' => '1'];
$row = card_row(620, 'last_name');
chk($row['conflict']['keep']['client:63']['enabled'] === false && $row['conflict']['keep']['wp']['enabled'] === true,
    'T26: shadow mode disables only choices that write WordPress');
$r = keep(620, 'last_name', 'client:63');
chk(!$r['ok'] && $r['code'] === 'shadow_mode' && $GLOBALS['users'][620]->last_name === 'Smyth' && $GLOBALS['wp_writes'] === [],
    'T26: shadow mode refuses a WordPress write server-side; nothing written');
chk(keep(620, 'last_name', 'wp')['ok'], 'T26: keeping WordPress (MealsDB-only write) still works in shadow mode');
$GLOBALS['options']['mealsdb_settings'] = ['shadow_mode' => '0'];

$db = world([630 => ['clients' => [64 => ['last_name' => 'Smith']], 'core' => ['last_name' => 'Smyth']]]);
$row = card_row(630, 'last_name');
$GLOBALS['users'][630]->last_name = 'Smythe'; // changed after the page rendered
$r = MealsDB_Sync_Conflict_View::apply_choices(630, [['conflict_id' => $row['conflict']['id'],
    'values_hash' => $row['conflict']['hash'], 'action' => 'keep', 'side' => 'wp']], 7);
chk($r['results'][0]['code'] === 'stale' && client($db, 64)['last_name'] === 'Smith', 'T45: stale page refused, nothing written');

$db = world([640 => ['clients' => [65 => ['last_name' => 'Smith']], 'core' => ['last_name' => 'Smyth']]]);
$db->fail_client_update = true;
$r = keep(640, 'last_name', 'wp');
chk(!$r['ok'] && $r['code'] === 'write_failed' && conflict_status($db, 640, 'last_name')['status'] === 'open',
    'MealsDB write failure: reported, conflict stays open');
$db->fail_client_update = false;

// ---------------------------------------------------------------------------
// Batch of two keeps on one account; initials never written (Test 16)
// ---------------------------------------------------------------------------
$db = world([700 => ['clients' => [70 => ['first_name' => 'Ann', 'last_name' => 'Smith', 'delivery_initials' => 'ANS']],
    'core' => ['first_name' => 'Anne', 'last_name' => 'Smyth'], 'meta' => ['nickname' => 'QQQ']]]);
$fn = card_row(700, 'first_name'); $ln = card_row(700, 'last_name');
$r = MealsDB_Sync_Conflict_View::apply_choices(700, [
    ['conflict_id' => $fn['conflict']['id'], 'values_hash' => $fn['conflict']['hash'], 'action' => 'keep', 'side' => 'client:70'],
    ['conflict_id' => $ln['conflict']['id'], 'values_hash' => $ln['conflict']['hash'], 'action' => 'keep', 'side' => 'wp'],
], 7);
chk($r['results'][0]['ok'] && $r['results'][1]['ok'], 'batch: two keeps on one account both resolve');
chk($GLOBALS['users'][700]->first_name === 'Ann' && client($db, 70)['last_name'] === 'Smyth', 'batch: each side written as chosen');
chk(client($db, 70)['delivery_initials'] === 'ANS' && $GLOBALS['meta']['700|nickname'] === 'QQQ', 'T16: initials and nickname untouched');
$touched = array_merge(array_column($db->audit, 'field'), ...array_map(static fn ($w) => array_keys($w[2]), $db->client_writes));
chk(!in_array('delivery_initials', $touched, true), 'T16: no resolution write touched delivery_initials');

// A forged side (another account's client, or nonsense) is refused.
$db = world([800 => ['clients' => [80 => ['last_name' => 'Smith']], 'core' => ['last_name' => 'Smyth']],
    810 => ['clients' => [81 => ['last_name' => 'Jones']], 'core' => ['last_name' => 'Jonas']]]);
chk(keep(800, 'last_name', 'client:81')['code'] === 'invalid_side', "T46: another account's client cannot be chosen");
chk(client($db, 80)['last_name'] === 'Smith' && $GLOBALS['wp_writes'] === [], 'T46: nothing written for a forged side');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
