<?php
/**
 * Tests for directive K21 ITEM 13: the real-time hooks (profile_update,
 * woocommerce_customer_save_address, woocommerce_created_customer) follow the
 * same fill-blank rules as the nightly, through
 * MealsDB_Sync_Reconciler::reconcile_account().
 *
 * K21 Tests covered: 16 (hook half), 27, 28 (hook half), 30 (hook half), plus
 * every linked client reconciled (no LIMIT 1), WordPress never written from
 * a hook, and derived fill-blank on the real-time path.
 *
 * Run: php tests/test-sync-realtime-k21.php
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

function world(array $accounts): wpdb {
    $db = new wpdb();
    $GLOBALS['wpdb']  = $db;
    $GLOBALS['users'] = []; $GLOBALS['meta'] = []; $GLOBALS['wp_writes'] = [];
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
        $u->roles = $a['roles'] ?? ['customer'];
        $GLOBALS['users'][$uid] = $u;
        foreach ($a['meta'] ?? [] as $k => $v) { $GLOBALS['meta']["$uid|$k"] = $v; }
    }
    return $db;
}

function client(wpdb $db, int $cid): array {
    foreach ($db->clients as $r) { if ((int) $r['client_id'] === $cid) { return $r; } }
    return [];
}

/** Last hook row recorded for a hook name. */
function hook_row(wpdb $db, string $hook): array {
    $rows = array_values(array_filter($db->events, static fn ($e) => ($e['category'] ?? '') === 'hook' && ($e['event'] ?? '') === $hook));
    $row = end($rows) ?: [];
    if (isset($row['context']) && is_string($row['context'])) {
        $row['ctx'] = json_decode($row['context'], true) ?: [];
    }
    return $row;
}

// ---------------------------------------------------------------------------
// T27: a profile edit never overwrites a populated MealsDB value
// ---------------------------------------------------------------------------
$db = world([100 => [
    'clients' => [10 => ['street_name' => '14 Elm St', 'city' => '', 'client_phone_1' => '506-555-0100', 'delivery_initials' => 'ANS']],
    'core'    => ['first_name' => 'Ann'],
    'meta'    => ['billing_address_1' => '99 New Rd', 'billing_city' => 'Moncton', 'billing_phone' => '', 'nickname' => 'annsmith'],
]]);
MealsDB_Sync::on_wp_user_updated(100, null);
chk(client($db, 10)['street_name'] === '14 Elm St', 'T27: populated MealsDB address NOT overwritten by the profile edit');
chk(isset($db->live()['100|street_name']), 'T27: the difference is recorded as a conflict');
chk(client($db, 10)['city'] === 'Moncton', 'blank MealsDB city filled from WordPress');
$fill = array_values(array_filter($db->audit, static fn ($a) => $a['action'] === 'sync_fill_client' && $a['field'] === 'city'));
chk(count($fill) === 1 && $fill[0]['source'] === 'realtime', 'real-time fill audited with source realtime');
chk($GLOBALS['wp_writes'] === [], 'the hook never writes WordPress (blank billing_phone, long nickname left for the nightly)');
chk(client($db, 10)['delivery_initials'] === 'ANS', 'T16: delivery_initials untouched by the hook');
$h = hook_row($db, 'profile_update');
chk(($h['ctx']['hook_outcome'] ?? '') === 'processed', 'hook recorded as processed');
chk(($h['ctx']['stats']['applied']['field:client_phone_1']['wp_deferred_to_nightly'] ?? 0) === 1,
    'deferred WordPress fill counted (and not redacted: phone is a sensitive key name)');

// Converged after the edit -> the hook closes the open conflict.
$GLOBALS['meta']['100|billing_address_1'] = '14 Elm St';
MealsDB_Sync::on_wc_address_saved(100, 'billing');
$closed = array_values(array_filter($db->conflicts, static fn ($r) => (int) $r['wp_user_id'] === 100 && $r['field'] === 'street_name'));
chk(($closed[0]['status'] ?? '') === 'auto_resolved', 'address save that converges the values auto-resolves the conflict');
chk((hook_row($db, 'woocommerce_customer_save_address')['ctx']['address_type'] ?? '') === 'billing', 'address type carried into the hook record');

MealsDB_Sync::on_wc_address_saved(100, 'nonsense');
chk((hook_row($db, 'woocommerce_customer_save_address')['ctx']['reason'] ?? '') === 'unknown_address_type', 'unknown address type skipped');

// ---------------------------------------------------------------------------
// Every linked client, not LIMIT 1
// ---------------------------------------------------------------------------
$db = world([200 => [
    'clients' => [20 => ['city' => ''], 21 => ['city' => ''], 22 => ['city' => '', 'active' => 0]],
    'meta'    => ['billing_city' => 'Dieppe'],
]]);
MealsDB_Sync::on_wp_user_updated(200, null);
chk(client($db, 20)['city'] === 'Dieppe' && client($db, 21)['city'] === 'Dieppe', 'both active linked clients filled (old path updated one arbitrary row)');
chk(client($db, 22)['city'] === '', 'inactive linked client untouched');

$db = world([210 => [
    'clients' => [23 => ['last_name' => 'Brown'], 24 => ['last_name' => 'Browne']],
    'core'    => ['last_name' => 'Brown'],
]]);
MealsDB_Sync::on_wp_user_updated(210, null);
chk(client($db, 24)['last_name'] === 'Browne' && isset($db->live()['210|last_name']), 'siblings that disagree -> conflict, neither overwritten');

// ---------------------------------------------------------------------------
// Derived fill-blank on the real-time path
// ---------------------------------------------------------------------------
$db = world([300 => [
    'clients' => [30 => ['service_center_charged' => '', 'delivery_area_zone' => ''], 31 => ['service_center_charged' => '', 'delivery_area_zone' => 'M']],
    'meta'    => ['service_centre_charged' => 'Sussex'],
]]);
MealsDB_Sync::on_wp_user_updated(300, null);
chk(client($db, 30)['delivery_area_zone'] === 'S', 'derived zone filled where blank');
chk(client($db, 31)['delivery_area_zone'] === 'M' && in_array('sync.derived_mismatch', $db->event_names(), true),
    'populated, different zone left alone and logged');

// ---------------------------------------------------------------------------
// Skips: staff, admin, untracked, recursion (T28), missing user
// ---------------------------------------------------------------------------
$db = world([
    1   => ['clients' => [1 => ['city' => '']], 'meta' => ['billing_city' => 'X'], 'roles' => ['administrator']],
    500 => ['clients' => [50 => ['city' => '']], 'meta' => ['billing_city' => 'Y']],
    600 => ['clients' => [], 'meta' => ['billing_city' => 'Z']],
    700 => ['clients' => [70 => ['city' => '']], 'meta' => ['billing_city' => 'W']],
]);
$db->staff = [500];
MealsDB_Sync::on_wp_user_updated(1, null);
chk(client($db, 1)['city'] === '' && (hook_row($db, 'profile_update')['ctx']['reason'] ?? '') === 'staff', 'T30: admin account (wp 1) skipped');
MealsDB_Sync::on_wp_user_updated(500, null);
chk(client($db, 50)['city'] === '' && (hook_row($db, 'profile_update')['ctx']['reason'] ?? '') === 'staff', 'T30: meals_staff account skipped');
MealsDB_Sync::on_wc_customer_created(600);
chk((hook_row($db, 'woocommerce_created_customer')['ctx']['reason'] ?? '') === 'not_tracked_client', 'new customer with no client: skipped');
MealsDB_Sync::without_sync_hooks(static function (): void { MealsDB_Sync::on_wp_user_updated(700, null); });
chk(client($db, 70)['city'] === '' && (hook_row($db, 'profile_update')['ctx']['reason'] ?? '') === 'recursion_guard',
    'T28: a hook fired during a sync write is skipped');
MealsDB_Sync::on_wp_user_updated(999, null);
chk((hook_row($db, 'profile_update')['ctx']['reason'] ?? '') === 'no_userdata', 'unknown user: skipped');

// Staff list unreadable -> the hook skips instead of syncing a possibly
// staff-linked account (PR review finding).
$db = world([800 => ['clients' => [80 => ['city' => '']], 'meta' => ['billing_city' => 'Moncton']]]);
$db->staff = [800];
$db->fail_staff = true;
MealsDB_Sync::on_wp_user_updated(800, null);
chk(client($db, 80)['city'] === '' && (hook_row($db, 'profile_update')['ctx']['reason'] ?? '') === 'staff_lookup_failed',
    'staff query failure: hook skipped (fail closed), nothing written');

chk($GLOBALS['wp_writes'] === [], 'no hook in this file wrote WordPress');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
