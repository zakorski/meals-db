<?php
/**
 * Tests for directive K23: Extra Mural is not SDNB.
 *
 * Extra Mural is billed separately (confirmed 2026-11-03) and MealsDB has no
 * client_type for it, so no path may classify an Extra Mural user as SDNB,
 * Veteran or Private, and no sync path may write customer_group or
 * client_type for one.
 *
 * K23 Tests 1-11 are labelled T1..T11 in the check names. T2, T4, T5 and T7
 * fail against v1.0.594 (Extra Mural mapped to SDNB).
 *
 * Run: php tests/test-extra-mural-k23.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!defined('MEALS_DB_KEY')) { define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32))); }

class MealsDB_Rate_Limiter {
    public static function check_rate_limit(string $bucket): bool { return true; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
require_once __DIR__ . '/fixtures/k21-sync-fake.php';

function update_option($k, $v, $autoload = null) { $GLOBALS['options'][$k] = $v; return true; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url($t) { return (string) $t; }
function esc_html_e($t, $d = 'default') { echo esc_html($t); }
function esc_html__($t, $d = 'default') { return esc_html($t); }
function _n($s, $p, $n, $d = 'default') { return (int) $n === 1 ? $s : $p; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }

$failures = []; $passed = 0;
function chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $label;
}

/**
 * The K21 fake plus the usermeta / users reads the phase-1 migration and the
 * Extra Mural lookups issue. usermeta is $GLOBALS['meta'] ("id|key" => value),
 * the same store get_user_meta() reads. The customer_group IN (...) match is
 * case-insensitive, as it is under MySQL's default collation.
 */
class K23Wpdb extends wpdb {
    public bool $fail_mural_query = false;

    /** @return array<int, string> user_id => customer_group */
    private function groups(): array {
        $out = [];
        foreach ($GLOBALS['meta'] as $key => $value) {
            [$uid, $meta_key] = explode('|', $key, 2);
            if ($meta_key === 'customer_group') { $out[(int) $uid] = (string) $value; }
        }
        ksort($out);
        return $out;
    }
    /** @return int[] */
    private function in_groups(array $wanted): array {
        $wanted = array_map('strtolower', $wanted);
        return array_keys(array_filter($this->groups(), static fn ($g) => in_array(strtolower($g), $wanted, true)));
    }
    public function get_results($q, $o = null) {
        $sql  = is_array($q) ? $q['sql'] : (string) $q;
        $args = is_array($q) ? $q['args'] : [];
        if (strpos($sql, "LIKE '%mural%'") !== false) {
            if ($this->fail_mural_query) { $this->last_error = 'forced'; return null; }
            $rows = [];
            foreach ($this->groups() as $uid => $g) {
                if (stripos($g, 'mural') !== false) { $rows[] = ['user_id' => $uid, 'meta_value' => $g]; }
            }
            return $rows;
        }
        if (strpos($sql, 'SELECT meta_key, meta_value FROM') !== false) {
            $rows = [];
            foreach ($GLOBALS['meta'] as $key => $value) {
                [$uid, $meta_key] = explode('|', $key, 2);
                if ((int) $uid === (int) $args[0]) { $rows[] = ['meta_key' => $meta_key, 'meta_value' => $value]; }
            }
            return $rows;
        }
        if (strpos($sql, 'last_delivery_date') !== false && strpos($sql, 'active = 1 AND wp_user_id IN') !== false) {
            // Extra Mural panel: ANY client_type, active only.
            $rows = array_values(array_filter($this->clients, static fn ($r) =>
                (int) $r['active'] === 1 && in_array((int) $r['wp_user_id'], $args, true)));
            usort($rows, static fn ($a, $b) => $a['client_id'] <=> $b['client_id']);
            return $rows;
        }
        return parent::get_results($q, $o);
    }
    public function get_var($q) {
        $sql  = is_array($q) ? $q['sql'] : (string) $q;
        $args = is_array($q) ? $q['args'] : [];
        if (strpos($sql, 'COUNT(DISTINCT user_id)') !== false) {
            return (string) count($this->in_groups($args));
        }
        if (strpos($sql, 'SELECT client_id FROM') !== false) {
            foreach ($this->clients as $r) {
                if ((int) $r['wp_user_id'] === (int) $args[0]) { return (string) $r['client_id']; }
            }
            return null;
        }
        if (stripos($sql, 'information_schema') !== false) { return 1; }
        return parent::get_var($q);
    }
    public function get_col($q) {
        $sql  = is_array($q) ? $q['sql'] : (string) $q;
        $args = is_array($q) ? $q['args'] : [];
        if (strpos($sql, 'SELECT DISTINCT user_id FROM') !== false) {
            $offset = (int) array_pop($args); $limit = (int) array_pop($args);
            return array_slice($this->in_groups($args), $offset, $limit);
        }
        return parent::get_col($q);
    }
    public function get_row($q, $o = null) {
        $sql  = is_array($q) ? $q['sql'] : (string) $q;
        $args = is_array($q) ? $q['args'] : [];
        if (strpos($sql, 'SELECT * FROM ' . $this->users) !== false) {
            $uid = (int) $args[0];
            return isset($GLOBALS['users'][$uid]) ? ['ID' => $uid, 'display_name' => 'User ' . $uid, 'user_email' => "u$uid@example.test"] : null;
        }
        return parent::get_row($q, $o);
    }
    /** Rows inserted into meals_clients (migration and promotion both INSERT). */
    public function client_inserts(): array {
        return array_values(array_filter($this->client_writes, static fn ($w) =>
            $w[0] === 'insert' && strpos((string) $w[1], 'meals_clients') !== false));
    }
    public function events_named(string $event): array {
        return array_values(array_filter($this->events, static fn ($e) => ($e['event'] ?? '') === $event));
    }
}

/**
 * Fresh world. $users: wp_user_id => ['group' => customer_group|null, 'meta' => [...]].
 * $clients: client_id => row overrides (must carry wp_user_id).
 */
function world(array $users, array $clients = []): K23Wpdb {
    $db = new K23Wpdb();
    $GLOBALS['wpdb']  = $db;
    $GLOBALS['users'] = []; $GLOBALS['meta'] = []; $GLOBALS['wp_writes'] = [];
    $GLOBALS['options']['mealsdb_settings'] = ['shadow_mode' => '0'];
    unset($GLOBALS['options']['mealsdb_migration_log']);
    foreach ($users as $uid => $spec) {
        $u = new WP_User($uid);
        $u->first_name = 'First' . $uid; $u->last_name = 'Last' . $uid;
        $u->user_email = "u$uid@example.test"; $u->roles = ['customer'];
        $GLOBALS['users'][$uid] = $u;
        if (($spec['group'] ?? null) !== null) { $GLOBALS['meta']["$uid|customer_group"] = $spec['group']; }
        foreach ($spec['meta'] ?? [] as $k => $v) { $GLOBALS['meta']["$uid|$k"] = $v; }
    }
    foreach ($clients as $cid => $over) {
        $row = ['client_id' => $cid, 'active' => 1, 'delivery_area_zone' => '', 'delivery_day' => '', 'last_delivery_date' => null];
        foreach (array_keys(MealsDB_Sync_Registry::fields()) as $c) { $row[$c] = ''; }
        $row['client_type'] = 'SDNB';
        $db->clients[] = array_merge($row, $over);
    }
    return $db;
}

function client(wpdb $db, int $cid): array {
    foreach ($db->clients as $r) { if ((int) $r['client_id'] === $cid) { return $r; } }
    return [];
}

/** The conflict row for (uid, field) as the page renders it. */
function card_row(int $uid, string $field, string $status = 'open'): ?array {
    $page = MealsDB_Sync_Conflict_View::page($field, $status, 1);
    foreach ($page['cards'] as $card) {
        if ($card['wp_user_id'] !== $uid) { continue; }
        foreach ($card['conflicts'] as $row) {
            if ($row['column'] === $field) { return $row; }
        }
    }
    return null;
}

function choose(int $uid, string $field, string $action, string $side = ''): array {
    $row = card_row($uid, $field);
    if ($row === null) { return ['code' => 'no_card_row', 'ok' => false, 'message' => '']; }
    $r = MealsDB_Sync_Conflict_View::apply_choices($uid, [[
        'conflict_id' => $row['conflict']['id'], 'values_hash' => $row['conflict']['hash'],
        'action' => $action, 'side' => $side,
    ]], 7);
    return $r['results'][0] ?? ['code' => 'error:' . (string) $r['error'], 'ok' => false, 'message' => ''];
}

$M = 'MealsDB_Migration_Consolidated';
$C = 'MealsDB_Sync_Comparator';
$R = 'MealsDB_Sync_Reconciler';
$SPELLINGS = ['Extra Mural', 'extra mural', 'extramural', 'Extra  Mural'];

// ---------------------------------------------------------------------------
// T1-T3 — the mapping
// ---------------------------------------------------------------------------
chk($M::CLASS_EXTRA_MURAL === 'ExtraMural', 'T1: the class lives in one named constant');
foreach (array_merge($SPELLINGS, ['  EXTRAMURAL ', "extra\tmural"]) as $g) {
    chk($M::customer_group_class($g) === 'ExtraMural', 'T1: customer_group_class(' . var_export($g, true) . ') === ExtraMural');
    chk($M::customer_group_to_client_type($g) === null, 'T2: customer_group_to_client_type(' . var_export($g, true) . ') === null');
    chk($M::is_extra_mural_group($g), 'is_extra_mural_group(' . var_export($g, true) . ')');
}
chk($M::customer_group_class('sdnb rural') === 'SDNB', 'T3: sdnb rural is still SDNB');
chk($M::customer_group_to_client_type('sdnb rural') === 'SDNB' && $M::customer_group_to_client_type('veterans') === 'Veteran',
    'T3: government groups still map to their client_type');
chk($M::customer_group_class('private') === 'Private' && $M::customer_group_class('mural') === null && $M::customer_group_class('') === null,
    'T3: private / unknown / blank unchanged');
foreach (['sdnb', 'sdnb rural', 'veterans', 'private', '', 'meals on wheels'] as $g) {
    chk(!$M::is_extra_mural_group($g), 'not Extra Mural: ' . var_export($g, true));
}
chk($M::client_type_to_customer_group('ExtraMural') === null, 'no canonical customer_group is ever written for ExtraMural');
chk(!in_array('ExtraMural', $C::CLIENT_TYPE_CLASSES, true), 'ExtraMural is not a MealsDB client_type class');

// ---------------------------------------------------------------------------
// T4 — migration does not import Extra Mural users
// ---------------------------------------------------------------------------
$GLOBALS['current_user_id'] = 7;
$gov_meta = ['service_centre_charged' => 'Moncton', 'shipping_address_2' => 'Zone 1'];
$db = world([
    300 => ['group' => 'sdnb',        'meta' => $gov_meta],   // control: imported
    301 => ['group' => 'Extra Mural', 'meta' => $gov_meta],
    302 => ['group' => 'extra mural', 'meta' => $gov_meta],
    303 => ['group' => 'extramural',  'meta' => $gov_meta],
    304 => ['group' => 'Extra  Mural', 'meta' => $gov_meta],
    305 => ['group' => 'private'],
]);
$res = $M::run_phase_create_clients(0, false, []);
chk(!isset($res['error']), 'T4: phase 1 ran' . (isset($res['error']) ? ' — ' . $res['error'] : ''));
$inserted = array_map(static fn ($w) => (int) ($w[2]['wp_user_id'] ?? 0), $db->client_inserts());
chk($inserted === [300], 'T4: only the SDNB user is imported; no client for any Extra Mural user (got ' . json_encode($inserted) . ')');
chk(($db->client_inserts()[0][2]['client_type'] ?? null) === 'SDNB', 'T4: control user imported as SDNB');
$ev = $db->events_named('migration.extra_mural_skipped');
chk(count($ev) === 1, 'T4: exactly one migration.extra_mural_skipped event per run');
$ctx = json_decode((string) ($ev[0]['context'] ?? ''), true) ?: [];
chk(($ctx['count'] ?? null) === 4 && ($ctx['wp_user_ids'] ?? null) === [301, 302, 303, 304],
    'T4: the event carries the count and the WordPress user IDs (got ' . json_encode($ctx) . ')');
chk(($ev[0]['outcome'] ?? '') === 'degraded', 'T4: the event is degraded, so it shows on the Event Log default view');
chk(strpos((string) ($GLOBALS['options']['mealsdb_migration_log'] ?? ''), '301, 302, 303, 304') !== false, 'T4: the skipped users are named in the migration log');

// A later chunk of the same walk does not report again.
$db->events = [];
$M::run_phase_create_clients(25, false, []);
chk($db->events_named('migration.extra_mural_skipped') === [], 'T4: not re-logged on later chunks of the same run');

$src = (string) file_get_contents(dirname(__DIR__) . '/includes/services/class-migration-consolidated.php');
chk(strpos($src, 'Migrated from Extra Mural program.') === false, 'T4: the unreachable Extra Mural note is removed');
chk(preg_match('/\$gov_groups\s*=\s*\[[^\]]*mural/i', $src) === 0, 'T4: Extra Mural is not in $gov_groups');

// No Extra Mural users: nothing logged. Unreadable lookup: phase still runs.
$db = world([300 => ['group' => 'sdnb', 'meta' => $gov_meta]]);
$M::run_phase_create_clients(0, true, []);
chk($db->events_named('migration.extra_mural_skipped') === [], 'T4: no event when there are no Extra Mural users');
$db = world([300 => ['group' => 'sdnb', 'meta' => $gov_meta], 301 => ['group' => 'Extra Mural']]);
$db->fail_mural_query = true;
$res = $M::run_phase_create_clients(0, true, []);
chk(!isset($res['error']) && $db->events_named('migration.extra_mural_skipped') === [], 'T4: a failed lookup does not stop the phase');

// ---------------------------------------------------------------------------
// T5 / T6 — promotion
// ---------------------------------------------------------------------------
foreach ($SPELLINGS as $i => $g) {
    $uid = 400 + $i;
    $db = world([$uid => ['group' => $g]]);
    $result = MealsDB_Private_Intake::maybe_promote($uid, null);
    chk($result === null, "T5: promotion of '$g' returns no client");
    chk($db->client_inserts() === [], "T5: promotion of '$g' creates no record — not SDNB, not Private");
    $ev = $db->events_named('promote.extra_mural_skipped');
    chk(count($ev) === 1 && (int) ($ev[0]['entity_id'] ?? 0) === $uid && strpos((string) $ev[0]['message'], (string) $uid) !== false,
        "T5: promote.extra_mural_skipped logged with the user ID for '$g'");
    chk($db->events_named('promote.customer_group_fallback') === [], "T5: '$g' never reaches the Private fallback");
}

$db = world([410 => ['group' => null]]);
$result = MealsDB_Private_Intake::maybe_promote(410, null);
$ins = $db->client_inserts();
chk(count($ins) === 1 && ($ins[0][2]['client_type'] ?? null) === 'Private', 'T6: blank customer_group still promotes as Private');
chk(count($db->events_named('promote.customer_group_fallback')) === 1 && $db->events_named('promote.extra_mural_skipped') === [],
    'T6: the blank-group fallback event is unchanged');
$db = world([411 => ['group' => 'gibberish'], 412 => ['group' => 'sdnb']]);
MealsDB_Private_Intake::maybe_promote(411, null);
MealsDB_Private_Intake::maybe_promote(412, null);
$types = array_map(static fn ($w) => $w[2]['client_type'] ?? null, $db->client_inserts());
chk($types === ['Private', 'SDNB'], 'T6: unrecognised group -> Private, sdnb -> SDNB (unchanged)');

// ---------------------------------------------------------------------------
// T7 / T8 — sync: always a conflict, never a fill or an equal
// ---------------------------------------------------------------------------
$o = static fn (string $wp, array $meals) => $R::evaluate_field('client_type', $wp, $meals);
foreach ($SPELLINGS as $g) {
    chk($o($g, [10 => 'SDNB'])['outcome'] === 'conflict', "T7: WP '$g' vs MealsDB SDNB -> conflict");
    chk($o($g, [10 => 'Private'])['outcome'] === 'conflict', "T8: WP '$g' vs MealsDB Private -> conflict");
    chk($o($g, [10 => 'Veteran'])['outcome'] === 'conflict', "WP '$g' vs MealsDB Veteran -> conflict");
    // Not reachable through the ENUM, but the guarantee must not depend on that.
    $r = $o($g, [10 => '']);
    chk($r['outcome'] === 'conflict' && $r['fill_clients'] === [] && !$r['fill_wp'], "WP '$g' vs blank MealsDB -> conflict, never a fill into client_type");
    $r = $o('', [10 => $g]);
    chk($r['outcome'] === 'conflict' && !$r['fill_wp'], "blank WP vs MealsDB '$g' -> conflict, never a fill into customer_group");
    chk($o($g, [10 => $g])['outcome'] === 'conflict', "'$g' on both sides is still a conflict, never equal");
    chk($o($g, [10 => 'SDNB', 11 => ''])['outcome'] === 'conflict', "multi-client: '$g' with a blank sibling -> conflict, no fill");
    chk($o($g, [10 => 'SDNB'])['unrecognised'] === [], "'$g' is a known group: not reported as unrecognised");
    chk(!$C::equals('client_type', $g, 'SDNB') && !$C::equals('client_type', $g, 'Private') && !$C::equals('client_type', $g, 'Veteran'),
        "comparator: '$g' equals no MealsDB class");
    chk(!$C::parses('client_type', $g) && !$C::is_blank('client_type', $g), "comparator: '$g' is never a fill source and never blank");
    chk($C::is_extra_mural($g), "comparator: is_extra_mural('$g')");
}
chk(!$C::is_extra_mural('sdnb') && !$C::is_extra_mural('SDNB') && !$C::is_extra_mural('') && !$C::is_extra_mural('meals on wheels'),
    'comparator: other values are not Extra Mural');
chk($o('sdnb rural', [10 => 'SDNB'])['outcome'] === 'equal' && $o('', [10 => 'Veteran'])['outcome'] === 'fill',
    'regression: sdnb rural == SDNB; blank customer_group is still filled from a real class');

// End to end: the nightly records the conflict and writes nothing, in either mode.
foreach (['SDNB', 'Private'] as $type) {
    $db = world([500 => ['group' => 'Extra Mural']], [10 => ['wp_user_id' => 500, 'client_type' => $type, 'first_name' => 'First500', 'last_name' => 'Last500', 'client_email' => 'u500@example.test']]);
    $stats = $R::run_reconcile();
    $live = $db->live();
    chk(isset($live['500|client_type']) && $live['500|client_type']['status'] === 'open', "T7/T8: nightly opens a client_type conflict (MealsDB $type)");
    chk(($stats['fields']['client_type'] ?? []) === ['conflict' => 1], "nightly: client_type classified as conflict only (MealsDB $type)");
    chk(client($db, 10)['client_type'] === $type && $GLOBALS['meta']['500|customer_group'] === 'Extra Mural',
        "nightly: neither client_type nor customer_group written (MealsDB $type)");
    chk(array_filter($GLOBALS['wp_writes'], static fn ($w) => $w[0] === 'meta' && $w[2] === 'customer_group') === [],
        "nightly: no customer_group write attempted (MealsDB $type)");
    chk(!in_array('sync.value_unrecognised', $db->event_names(), true), "nightly: Extra Mural not reported as an unrecognised group (MealsDB $type)");
    // Real-time path (profile save) too.
    $before = $db->client_writes;
    $R::reconcile_account(500);
    chk($db->client_writes === $before && client($db, 10)['client_type'] === $type, "real-time reconcile writes nothing (MealsDB $type)");
}

// ---------------------------------------------------------------------------
// T9 / T10 — both resolutions are refused; Leave both still works
// ---------------------------------------------------------------------------
foreach (['SDNB', 'Private', 'Veteran'] as $type) {
    $db = world([600 => ['group' => 'Extra Mural']], [20 => ['wp_user_id' => 600, 'client_type' => $type, 'first_name' => 'First600', 'last_name' => 'Last600', 'client_email' => 'u600@example.test']]);
    $R::run_detection();
    $row = card_row(600, 'client_type');
    chk($row !== null && $row['conflict']['stale'] === false, "view: the Extra Mural conflict is on the page (MealsDB $type)");
    chk($row['conflict']['keep']['wp']['enabled'] === false && $row['conflict']['keep']['client:20']['enabled'] === false,
        "view: both Keep options are disabled — read-only (MealsDB $type)");
    chk(strpos($row['conflict']['note'], 'Extra Mural') !== false && strpos($row['conflict']['note'], 'does not bill') !== false
        && strpos($row['conflict']['note'], 'operator decision') !== false && $row['conflict']['keep']['client:20']['reason'] === $row['conflict']['note'],
        "view: the row carries the Extra Mural note (MealsDB $type)");

    $writes = $db->client_writes;
    $r = choose(600, 'client_type', 'keep', 'client:20');
    chk(!$r['ok'] && $r['code'] === 'extra_mural', "T9: Keep MealsDB refused (MealsDB $type; got {$r['code']})");
    chk($GLOBALS['meta']['600|customer_group'] === 'Extra Mural' && $GLOBALS['wp_writes'] === [], "T9: customer_group unchanged (MealsDB $type)");

    $r = choose(600, 'client_type', 'keep', 'wp');
    chk(!$r['ok'] && $r['code'] === 'extra_mural', "T10: Keep WordPress refused (MealsDB $type; got {$r['code']})");
    chk(client($db, 20)['client_type'] === $type && $db->client_writes === $writes, "T10: client_type unchanged, no client write (MealsDB $type)");
    chk($db->live()['600|client_type']['status'] === 'open' && $db->audit === [], "refused resolutions leave the conflict open and audit nothing (MealsDB $type)");
}

// Direct call into the resolver (a future caller that skips the view).
$store = new MealsDB_Sync_Conflicts($db);
$live_row = $db->live()['600|client_type'];
foreach (['wp', 'client:20'] as $side) {
    $r = MealsDB_Sync_Resolver::resolve($store, $live_row, $side, $GLOBALS['users'][600], $R::fetch_clients([600])[600], 7, '2026-11-11 00:00:00');
    chk(!$r['ok'] && $r['code'] === 'extra_mural', "resolver refuses side '$side' directly");
}
chk($GLOBALS['wp_writes'] === [] && client($db, 20)['client_type'] === 'Veteran', 'resolver: nothing written');

// Leave both remains available, and its undo.
$r = choose(600, 'client_type', 'dismiss');
chk($r['ok'] && $r['code'] === 'dismissed' && $db->live()['600|client_type']['status'] === 'dismissed', 'Leave both still dismisses an Extra Mural conflict');
chk($GLOBALS['wp_writes'] === [] && client($db, 20)['client_type'] === 'Veteran', 'Leave both writes neither side');
$R::run_reconcile();
chk($db->live()['600|client_type']['status'] === 'dismissed' && client($db, 20)['client_type'] === 'Veteran'
    && $GLOBALS['meta']['600|customer_group'] === 'Extra Mural', 'a dismissed Extra Mural conflict stays dismissed and unwritten on the next nightly');

// An ordinary client_type conflict is still resolvable (the refusal is specific).
$db = world([610 => ['group' => 'private']], [21 => ['wp_user_id' => 610, 'client_type' => 'SDNB', 'first_name' => 'First610', 'last_name' => 'Last610', 'client_email' => 'u610@example.test']]);
$R::run_detection();
$row = card_row(610, 'client_type');
chk($row !== null && $row['conflict']['note'] === '' && $row['conflict']['keep']['wp']['enabled'] && $row['conflict']['keep']['client:21']['enabled'],
    'regression: private vs SDNB is still resolvable, with no note');
$r = choose(610, 'client_type', 'keep', 'client:21');
chk($r['ok'] && $GLOBALS['meta']['610|customer_group'] === 'sdnb', 'regression: Keep MealsDB on private vs SDNB still writes sdnb');

// The rendered view shows the note and offers no enabled Keep.
$db = world([620 => ['group' => 'extramural']], [22 => ['wp_user_id' => 620, 'client_type' => 'SDNB', 'first_name' => 'First620', 'last_name' => 'Last620', 'client_email' => 'u620@example.test']]);
$R::run_detection();
$view_src = (string) file_get_contents(dirname(__DIR__) . '/views/sync-conflicts.php');
chk(strpos($view_src, "\$mealsdb_c['note']") !== false && strpos($view_src, "esc_html(\$mealsdb_c['note'])") !== false,
    'view template prints the (escaped) note');

// ---------------------------------------------------------------------------
// T11 — dashboard panel
// ---------------------------------------------------------------------------
$db = world([
    700 => ['group' => 'Extra Mural', 'meta' => ['last_delivery_date' => '2025-12-04']],   // usermeta fallback
    701 => ['group' => 'Extra Mural'],
    702 => ['group' => 'extramural'],
    703 => ['group' => 'Extra Mural'],                                                       // inactive client
    704 => ['group' => 'sdnb'],
    705 => ['group' => 'Extra Mural'],                                                       // no client at all
], [
    510  => ['wp_user_id' => 700, 'client_type' => 'SDNB',    'first_name' => 'Cecilia', 'last_name' => 'B'],
    512  => ['wp_user_id' => 701, 'client_type' => 'SDNB',    'first_name' => 'Jeanne',  'last_name' => '<b>W</b>', 'last_delivery_date' => '2025-11-20'],
    1009 => ['wp_user_id' => 702, 'client_type' => 'Private', 'first_name' => 'Bernice', 'last_name' => 'L', 'last_delivery_date' => '0000-00-00'],
    1010 => ['wp_user_id' => 703, 'client_type' => 'SDNB',    'active' => 0],
    1011 => ['wp_user_id' => 704, 'client_type' => 'SDNB'],
]);
$list = MealsDB_Extra_Mural_Clients::get_active_clients();
chk(is_array($list) && array_column($list, 'client_type', 'client_id') === [510 => 'SDNB', 512 => 'SDNB', 1009 => 'Private'],
    'T11: lists active clients with an Extra Mural group and their current type; inactive and non-Extra-Mural excluded');
chk(array_column($list, 'last_delivery', 'client_id') === [510 => '2025-12-04', 512 => '2025-11-20', 1009 => ''],
    'T11: last delivery from the client column, else the legacy usermeta, else none');
chk($db->client_writes === [] && $db->events === [] && $GLOBALS['wp_writes'] === [], 'T11: building the list writes nothing and logs nothing');
chk(client($db, 510)['client_type'] === 'SDNB' && client($db, 1009)['client_type'] === 'Private', 'T11: stored client_type is not changed');

ob_start();
include __DIR__ . '/../views/partials/dashboard-extra-mural-clients.php';
$html = ob_get_clean();
chk(strpos($html, 'action=edit&client_id=510') !== false && strpos($html, 'Cecilia B') !== false, 'T11: panel links each client');
chk(preg_match('/data-client="510".*?<td>SDNB<\/td>\s*<td>Extra Mural<\/td>\s*<td>2025-12-04<\/td>/s', $html) === 1, 'T11: panel shows type, group and last delivery');
chk(preg_match('/data-client="1009".*?<td>Private<\/td>\s*<td>extramural<\/td>\s*<td>None recorded<\/td>/s', $html) === 1, 'T11: panel shows a Private Extra Mural client with no delivery');
chk(strpos($html, '<b>W</b>') === false && strpos($html, '&lt;b&gt;W&lt;/b&gt;') !== false, 'T11: client names are escaped');
chk(strpos($html, 'client_id=1010') === false && strpos($html, 'client_id=1011') === false, 'T11: inactive / SDNB-group clients absent');
$dash = (string) file_get_contents(dirname(__DIR__) . '/views/dashboard.php');
chk(strpos($dash, 'views/partials/dashboard-extra-mural-clients.php') !== false, 'T11: the dashboard includes the panel');

$db = world([704 => ['group' => 'sdnb']], [1011 => ['wp_user_id' => 704]]);
ob_start();
include __DIR__ . '/../views/partials/dashboard-extra-mural-clients.php';
chk(trim(ob_get_clean()) === '', 'T11: panel renders nothing when there are no Extra Mural clients');

$db = world([700 => ['group' => 'Extra Mural']], [510 => ['wp_user_id' => 700]]);
$db->fail_mural_query = true;
chk(MealsDB_Extra_Mural_Clients::get_active_clients() === null, 'T11: unreadable lookup -> null, not an empty list');
ob_start();
include __DIR__ . '/../views/partials/dashboard-extra-mural-clients.php';
chk(strpos(ob_get_clean(), 'could not be loaded') !== false, 'T11: panel says the list could not be loaded');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
