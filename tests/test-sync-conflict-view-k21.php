<?php
/**
 * Tests for directive K21 ITEM 9: the A/B conflict view's data
 * (MealsDB_Sync_Conflict_View) and its per-account choice endpoint
 * (MealsDB_Ajax_Sync::apply_conflict_choices).
 *
 * Runs real detection first, then checks that:
 *   - cards are built from LIVE values, and the hash a card renders equals
 *     the hash detection stored (so a dismissal made on the page survives the
 *     next nightly run);
 *   - delivery_initials / nickname never appears; derived fields are
 *     read-only; converged conflicts are flagged stale;
 *   - "Leave both" dismisses, undo reopens, Keep is refused (ITEM 10), and a
 *     stale page, a foreign conflict id, or a missing capability changes
 *     nothing;
 *   - nothing is written to client data in either system;
 *   - the AJAX handler validates input, rate-limits once per request, and
 *     enforces capability.
 *
 * Run: php tests/test-sync-conflict-view-k21.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

// Stub the rate limiter before the autoloader can load the real one.
class MealsDB_Rate_Limiter {
    public static array $calls = [];
    public static function check_rate_limit(string $bucket): bool {
        self::$calls[] = $bucket;
        return (bool) ($GLOBALS['rate_ok'] ?? true);
    }
}
$GLOBALS['rate_ok'] = true;

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
require_once __DIR__ . '/fixtures/k21-sync-fake.php';

if (!function_exists('absint')) { function absint($v) { return abs((int) $v); } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; } }
$GLOBALS['nonce_ok'] = true;
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action, $arg = false, $die = true) {
        $GLOBALS['nonce_action'] = $action;
        return (bool) $GLOBALS['nonce_ok'];
    }
}
function wp_send_json_success($data = null, $status = null) { $GLOBALS['json'] = ['ok' => true, 'data' => $data, 'status' => $status]; }
function wp_send_json_error($data = null, $status = null) { $GLOBALS['json'] = ['ok' => false, 'data' => $data, 'status' => $status]; }

$failures = []; $passed = 0;
function chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $label;
}

$V = 'MealsDB_Sync_Conflict_View';
$db = new wpdb();
$GLOBALS['wpdb'] = $db;
$GLOBALS['current_user_id'] = 7;

$client = static function (array $over): array {
    $row = ['client_type' => 'SDNB', 'active' => 1, 'delivery_area_zone' => '', 'delivery_day' => ''];
    foreach (array_keys(MealsDB_Sync_Registry::fields()) as $c) { $row[$c] = ''; }
    $row['client_type'] = 'SDNB';
    return array_merge($row, $over);
};
$user = static function (int $id, array $core, array $meta): void {
    $u = new WP_User($id);
    foreach ($core as $k => $v) { $u->$k = $v; }
    $u->roles = ['customer'];
    $GLOBALS['users'][$id] = $u;
    foreach ($meta as $k => $v) { $GLOBALS['meta']["$id|$k"] = $v; }
};

$db->clients[] = $client(['client_id' => 10, 'wp_user_id' => 100, 'first_name' => 'Ann', 'last_name' => 'Smith',
    'delivery_initials' => 'ANS', 'delivery_area_zone' => 'M', 'delivery_day' => 'tuesday']);
$user(100, ['first_name' => 'Ann', 'last_name' => 'Smyth'], ['nickname' => 'XYZ', 'customer_group' => 'sdnb']);
$db->clients[] = $client(['client_id' => 20, 'wp_user_id' => 200, 'client_type' => 'Veteran', 'first_name' => 'Bob']);
$db->clients[] = $client(['client_id' => 21, 'wp_user_id' => 200, 'client_type' => 'Private', 'first_name' => 'Bob']);
$user(200, ['first_name' => 'Bob'], ['customer_group' => 'veterans']);

MealsDB_Sync_Reconciler::run_detection();
$stored = $db->live();
chk(isset($stored['100|last_name']) && isset($stored['200|client_type']) && count($stored) === 2, 'fixture: detection found the 2 expected conflicts');

// ---------------------------------------------------------------------------
// page() and cards
// ---------------------------------------------------------------------------
$page = $V::page('', 'open', 1);
chk($page['error'] === null, 'page: no error');
chk($page['total_accounts'] === 2 && count($page['cards']) === 2, 'page: 2 accounts, 2 cards');
chk($page['status_counts']['open'] === 2 && $page['status_counts']['dismissed'] === 0, 'page: status counts');
chk(($page['field_counts']['last_name'] ?? 0) === 1 && ($page['field_counts']['client_type'] ?? 0) === 1, 'page: field counts');

$cards = [];
foreach ($page['cards'] as $c) { $cards[$c['wp_user_id']] = $c; }
$card100 = $cards[100];
chk(array_column($card100['conflicts'], 'column') === ['last_name'], 'card 100: one conflict row (last_name)');
$c100 = $card100['conflicts'][0];
chk($c100['wp'] === 'Smyth' && $c100['meals'] === [10 => 'Smith'], 'card: raw live values');
chk($c100['conflict']['hash'] === $stored['100|last_name']['values_hash'], 'card hash == hash detection stored');
chk($c100['conflict']['stale'] === false, 'card: current conflict is actionable');

$all_columns = array_merge(array_column($card100['conflicts'], 'column'), array_column($card100['others'], 'column'));
chk(!in_array('delivery_initials', $all_columns, true), 'ITEM 5: delivery_initials never appears');
chk(count($all_columns) === 26, 'card covers all 26 two-way fields');
chk(array_column($card100['derived'], 'column') === ['delivery_area_zone', 'delivery_day'], 'derived fields listed read-only');
chk($card100['derived'][0]['meals'] === [10 => 'M'], 'derived values shown per client');

$card200 = $cards[200];
chk(array_keys($card200['clients']) === [20, 21], 'card 200: both linked clients side by side');
chk(array_column($card200['conflicts'], 'column') === ['client_type'], 'card 200: client_type conflict');

// Field filter.
$f = $V::page('client_type', 'open', 1);
chk($f['total_accounts'] === 1 && $f['cards'][0]['wp_user_id'] === 200, 'filter by field: only matching accounts');
$f = $V::page('not_a_field', 'open', 1);
chk($f['total_accounts'] === 2, 'unknown field filter is ignored, not injected');
$f = $V::page('', 'dismissed', 1);
chk($f['total_accounts'] === 0 && $f['cards'] === [], 'dismissed filter: none yet');

// ---------------------------------------------------------------------------
// apply_choices
// ---------------------------------------------------------------------------
$id100 = (int) $stored['100|last_name']['conflict_id'];
$id200 = (int) $stored['200|client_type']['conflict_id'];
$hash100 = $c100['conflict']['hash'];
$hash200 = $card200['conflicts'][0]['conflict']['hash'];

// Leave both (the dual-program client_type case).
$r = $V::apply_choices(200, [['conflict_id' => $id200, 'values_hash' => $hash200, 'action' => 'dismiss']], 7);
chk($r['error'] === null && $r['results'][0]['ok'] === true && $r['results'][0]['code'] === 'dismissed', 'dismiss: ok');
$row = $db->conflicts[$id200];
chk($row['status'] === 'dismissed' && $row['dismissed_pair_hash'] === $hash200 && (int) $row['resolved_by'] === 7,
    'dismiss: status, pair hash and operator recorded');
chk(in_array('sync.conflict_dismissed', $db->event_names(), true), 'dismiss: event logged');

// The dismissal holds through the next nightly detection run.
$stats = MealsDB_Sync_Reconciler::run_detection();
chk($db->conflicts[$id200]['status'] === 'dismissed' && ($stats['conflicts']['still_dismissed'] ?? 0) === 1,
    'dismissal made on the page survives the next detection run');

// Keep without a side is refused (ITEM 10 resolution is covered in
// test-sync-resolver-k21.php).
$r = $V::apply_choices(100, [['conflict_id' => $id100, 'values_hash' => $hash100, 'action' => 'keep']], 7);
chk($r['results'][0]['ok'] === false && $r['results'][0]['code'] === 'invalid_side', 'keep without a side: refused');

// Stale page: WP changes after the page was rendered.
$GLOBALS['users'][100]->last_name = 'Smithe';
$r = $V::apply_choices(100, [['conflict_id' => $id100, 'values_hash' => $hash100, 'action' => 'dismiss']], 7);
chk($r['results'][0]['code'] === 'stale' && $db->conflicts[$id100]['status'] === 'open', 'stale page: refused, nothing changed');
$GLOBALS['users'][100]->last_name = 'Smyth';

// Converged since the scan: card flags it stale and dismiss is refused.
$GLOBALS['users'][100]->last_name = 'Smith';
$card = $V::page('last_name', 'open', 1)['cards'][0];
chk($card['conflicts'][0]['conflict']['stale'] === true, 'converged since scan: card shows stale');
$r = $V::apply_choices(100, [['conflict_id' => $id100, 'values_hash' => $card['conflicts'][0]['conflict']['hash'], 'action' => 'dismiss']], 7);
chk($r['results'][0]['code'] === 'stale', 'converged since scan: dismiss refused');
$GLOBALS['users'][100]->last_name = 'Smyth';

// A conflict id from another account is not found on this one.
$r = $V::apply_choices(100, [['conflict_id' => $id200, 'values_hash' => $hash200, 'action' => 'undismiss']], 7);
chk($r['results'][0]['code'] === 'not_found' && $db->conflicts[$id200]['status'] === 'dismissed', 'foreign conflict id: not_found, untouched');

// Undo dismissal; undo on an open row is refused.
$r = $V::apply_choices(200, [['conflict_id' => $id200, 'values_hash' => $hash200, 'action' => 'undismiss']], 7);
chk($r['results'][0]['ok'] === true && $db->conflicts[$id200]['status'] === 'open' && $db->conflicts[$id200]['dismissed_pair_hash'] === null,
    'undismiss: reopened');
$r = $V::apply_choices(200, [['conflict_id' => $id200, 'values_hash' => $hash200, 'action' => 'undismiss']], 7);
chk($r['results'][0]['code'] === 'not_dismissed', 'undismiss on open row: refused');

// Batch: several choices in one call, each reported.
$r = $V::apply_choices(100, [
    ['conflict_id' => $id100, 'values_hash' => $hash100, 'action' => 'dismiss'],
    ['conflict_id' => 999999, 'values_hash' => $hash100, 'action' => 'dismiss'],
], 7);
chk(count($r['results']) === 2 && $r['results'][0]['ok'] && !$r['results'][1]['ok'], 'batch: each choice reported independently');

// Service-layer capability re-check.
$GLOBALS['cap_ok'] = false;
$before = $db->conflicts;
$r = $V::apply_choices(200, [['conflict_id' => $id200, 'values_hash' => $hash200, 'action' => 'dismiss']], 7);
chk($r['error'] !== null && $db->conflicts === $before, 'no capability: refused at the service layer, nothing changed');
$GLOBALS['cap_ok'] = true;

// Limits.
$too_many = array_fill(0, $V::MAX_CHOICES + 1, ['conflict_id' => $id100, 'values_hash' => $hash100, 'action' => 'dismiss']);
chk($V::apply_choices(100, $too_many, 7)['error'] !== null, 'more than MAX_CHOICES: refused');
chk($V::apply_choices(0, [['conflict_id' => 1, 'values_hash' => $hash100, 'action' => 'dismiss']], 7)['error'] !== null, 'no account: refused');

chk($db->client_writes === [] && $GLOBALS['wp_writes'] === [], 'no client data written in either system');

// ---------------------------------------------------------------------------
// AJAX handler
// ---------------------------------------------------------------------------
$call = static function (array $post): array {
    $_POST = $post;
    $GLOBALS['json'] = null;
    MealsDB_Rate_Limiter::$calls = [];
    MealsDB_Ajax_Sync::apply_conflict_choices();
    return (array) $GLOBALS['json'];
};
$valid = ['wp_user_id' => '200', 'choices' => json_encode([['conflict_id' => $id200, 'values_hash' => $hash200, 'action' => 'dismiss']])];

$j = $call($valid);
chk($j['ok'] === true && ($j['data']['results'][0]['ok'] ?? false) === true, 'ajax: valid dismiss succeeds');
chk($GLOBALS['nonce_action'] === 'mealsdb_sync_conflicts', 'ajax: own nonce context');
chk(MealsDB_Rate_Limiter::$calls === ['sync_operations'], 'ajax: one sync_operations token per request');

chk($call(['wp_user_id' => '200', 'choices' => 'not json'])['status'] === 400, 'ajax: invalid JSON -> 400');
chk($call(['wp_user_id' => '200', 'choices' => json_encode([['conflict_id' => 1, 'values_hash' => 'abc', 'action' => 'dismiss']])])['status'] === 400, 'ajax: malformed hash -> 400');
chk($call(['wp_user_id' => '200', 'choices' => json_encode([['conflict_id' => 1, 'values_hash' => $hash200, 'action' => 'delete']])])['status'] === 400, 'ajax: unknown action -> 400');
chk($call(['wp_user_id' => '0', 'choices' => $valid['choices']])['status'] === 400, 'ajax: missing account -> 400');

$GLOBALS['rate_ok'] = false;
chk($call($valid)['status'] === 429, 'ajax: rate limited -> 429');
$GLOBALS['rate_ok'] = true;

$GLOBALS['cap_ok'] = false;
$j = $call($valid);
chk($j['status'] === 403 && MealsDB_Rate_Limiter::$calls === [], 'ajax: no capability -> 403 before any rate-limit spend');
$GLOBALS['cap_ok'] = true;

chk($db->client_writes === [] && $GLOBALS['wp_writes'] === [], 'ajax path wrote no client data');

// ---------------------------------------------------------------------------
// Template render: no PHP errors, values escaped
// ---------------------------------------------------------------------------
if (!function_exists('esc_html')) { function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_url')) { function esc_url($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_html__')) { function esc_html__($t, $d = null) { return esc_html($t); } }
if (!function_exists('esc_html_e')) { function esc_html_e($t, $d = null) { echo esc_html($t); } }
if (!function_exists('esc_attr_e')) { function esc_attr_e($t, $d = null) { echo esc_attr($t); } }
if (!function_exists('admin_url')) { function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; } }
if (!function_exists('add_query_arg')) { function add_query_arg($args, $url) { return $url . '&' . http_build_query($args); } }
if (!function_exists('selected')) { function selected($a, $b) { if ((string) $a === (string) $b) { echo ' selected="selected"'; } } }
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($a) { return 'nonce-' . $a; } }
if (!function_exists('wp_date')) { function wp_date($f, $ts) { return gmdate('Y-m-d H:i', $ts); } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $k === 'mealsdb_settings' ? ['shadow_mode' => '0'] : ($k === 'date_format' ? 'Y-m-d' : ($k === 'time_format' ? 'H:i' : $d)); } }

// A hostile value in a client record must render escaped.
foreach ($db->clients as &$c) { if ($c['client_id'] === 10) { $c['last_name'] = '<script>alert(1)</script>'; } } unset($c);
MealsDB_Sync_Reconciler::run_detection();

$_GET = ['conflict_status' => 'open'];
$warnings = [];
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$warnings): bool {
    $warnings[] = basename($file) . ":$line $msg";
    return true;
});
ob_start();
include dirname(__DIR__) . '/views/sync-conflicts.php';
$html = (string) ob_get_clean();
restore_error_handler();

chk($warnings === [], 'template renders without PHP warnings: ' . implode('; ', $warnings));
chk(strpos($html, '<script>alert(1)</script>') === false, 'template: hostile value never output raw');
chk(strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'template: hostile value shown escaped');
// Account 200's conflict was dismissed above, so the Open view holds only
// account 100 (reopened by the hostile-value change).
chk(substr_count($html, 'class="mealsdb-conflict-card"') === 1 && strpos($html, 'data-wp-user-id="100"') !== false,
    'template: one card per open account');
chk(strpos($html, 'value="keep:wp" />') !== false && strpos($html, 'value="keep:client:10" />') !== false,
    'template: Keep choices enabled for valid, non-blank sides');
chk(strpos($html, 'class="button mealsdb-conflict-use-all" data-side="wp"') !== false, 'template: Use all WordPress shortcut rendered');
chk(strpos($html, 'value="dismiss"') !== false, 'template: Leave both offered');
chk(strpos($html, 'id="mealsdb-sync-conflicts-data"') !== false && strpos($html, 'nonce-mealsdb_sync_conflicts') !== false,
    'template: data island carries the conflict nonce');
chk(strpos($html, 'Delivery Initials') === false, 'template: initials never shown');
chk(strpos($html, 'mealsdb-conflict-blank') !== false, 'template: blank values marked');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
