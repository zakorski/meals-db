<?php
/**
 * Tests for directive K21 ITEM 8 (conflict store) and the detection-only
 * reconcile (ITEM 7a classification, no writes).
 *
 * Part 1: MealsDB_Sync_Reconciler::evaluate_field() — pure classification.
 * Part 2: MealsDB_Sync_Reconciler::run_detection() end to end against a fake
 *         $wpdb holding meals_clients + meals_sync_conflicts, asserting that
 *         NOTHING is written to client data in either system.
 * Part 3: conflict lifecycle across runs — dedupe, newest-wins, auto-close,
 *         dismissal, reopen, relink, sweep.
 * Part 4: legacy meals_ignored_conflicts delegation and store failure modes.
 *
 * K21 Tests covered: 1, 4 (detection half), 5, 6, 7, 8, 10, 13, 15, 20, 21,
 * 30, 33, 34, 37, 38, 39, 42, 43.
 *
 * Run: php tests/test-sync-conflicts-k21.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $f = 0, $d = 512) { return json_encode($v, $f, $d); } }
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message;
        public function __construct($c = '', $m = '') { $this->code = $c; $this->message = $m; }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!class_exists('WP_User')) {
    class WP_User {
        public $ID = 0; public $first_name = ''; public $last_name = ''; public $user_email = ''; public $roles = [];
        public function __construct($id = 0) { $this->ID = $id; }
    }
}

// --- WordPress user store ----------------------------------------------------
$GLOBALS['users'] = [];   // id => WP_User
$GLOBALS['meta']  = [];   // "id|key" => value
$GLOBALS['wp_writes'] = [];
function get_userdata($id) { return $GLOBALS['users'][(int) $id] ?? false; }
function get_user_meta($id, $key, $single = false) { return $GLOBALS['meta']["$id|$key"] ?? ''; }
function cache_users($ids) {}
function get_current_user_id() { return 0; }
function update_meta_cache($type, $ids) { return true; }
function update_user_meta($id, $k, $v) { $GLOBALS['wp_writes'][] = ['meta', $id, $k, $v]; return true; }
function wp_update_user($d) { $GLOBALS['wp_writes'][] = ['user', $d]; return $d['ID']; }

// --- Fake wpdb ---------------------------------------------------------------
class wpdb {
    public $prefix = 'wp_'; public $usermeta = 'wp_usermeta'; public $users = 'wp_users';
    public $last_error = ''; public $insert_id = 0;
    public array $clients = [];     // client rows
    public array $conflicts = [];   // conflict_id => row
    public array $staff = [];       // wp ids in meals_staff
    public array $ignored = [];     // legacy ignore rows
    public array $events = [];
    public array $client_writes = [];
    public bool $fail_live = false;
    private int $next_id = 1;

    public function prepare($q, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        return ['sql' => $q, 'args' => $args];
    }
    private static function split($q): array {
        return is_array($q) ? [$q['sql'], $q['args']] : [(string) $q, []];
    }
    private function tracked(array $r): bool {
        return in_array($r['client_type'], ['SDNB', 'Veteran', 'Private'], true) && (int) $r['active'] === 1;
    }
    public function get_col($q) {
        [$sql, $args] = self::split($q);
        if (strpos($sql, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            return ['wp_user_id'];
        }
        if (strpos($sql, 'SELECT client_id FROM') !== false) {
            return array_map(static fn ($r) => $r['client_id'],
                array_filter($this->clients, static fn ($r) => (int) $r['wp_user_id'] === (int) $args[0]));
        }
        if (strpos($sql, 'SELECT DISTINCT wp_user_id') !== false) {
            [$cursor, $limit] = $args;
            $ids = [];
            foreach ($this->clients as $r) {
                if ($this->tracked($r) && (int) $r['wp_user_id'] > $cursor) { $ids[(int) $r['wp_user_id']] = true; }
            }
            $ids = array_keys($ids); sort($ids);
            return array_slice($ids, 0, $limit);
        }
        return [];
    }
    public function get_results($q, $o = null) {
        [$sql, $args] = self::split($q);
        if (strpos($sql, 'meals_sync_conflicts') !== false && strpos($sql, "status IN ('open', 'dismissed')") !== false) {
            if ($this->fail_live) { $this->last_error = 'table missing'; return null; }
            return array_values(array_filter($this->conflicts, static fn ($r) => in_array($r['status'], ['open', 'dismissed'], true)));
        }
        if (strpos($sql, 'SHOW COLUMNS') !== false && strpos($sql, 'meals_staff') !== false) {
            return [['Field' => 'wordpress_user_id']];
        }
        if (strpos($sql, 'AS wordpress_user_id FROM') !== false) {
            return array_map(static fn ($id) => ['wordpress_user_id' => $id], $this->staff);
        }
        if (strpos($sql, 'field_name, source_value, target_value') !== false) {
            return $this->ignored;
        }
        if (strpos($sql, 'WHERE wp_user_id IN') !== false) {
            $rows = array_values(array_filter($this->clients, fn ($r) => $this->tracked($r) && in_array((int) $r['wp_user_id'], $args, true)));
            usort($rows, static fn ($a, $b) => [$a['wp_user_id'], $a['client_id']] <=> [$b['wp_user_id'], $b['client_id']]);
            return $rows;
        }
        return [];
    }
    public function insert($table, $data, $f = null) {
        if (strpos($table, 'meals_event_log') !== false) {
            $this->events[] = $data; $this->insert_id = count($this->events); return 1;
        }
        if (strpos($table, 'meals_sync_conflicts') !== false) {
            if (isset($data['open_dedup'])) {
                foreach ($this->conflicts as $r) {
                    if (($r['open_dedup'] ?? null) === $data['open_dedup']) { $this->last_error = 'Duplicate entry'; return false; }
                }
            }
            $id = $this->next_id++;
            $this->conflicts[$id] = $data + ['conflict_id' => $id, 'resolution' => null, 'resolved_at' => null,
                'resolved_by' => null, 'dismissed_pair_hash' => null];
            return 1;
        }
        $this->client_writes[] = ['insert', $table, $data];
        return 1;
    }
    public function update($table, $data, $where, $f = null, $wf = null) {
        if (strpos($table, 'meals_event_log') !== false) { return 1; }
        if (strpos($table, 'meals_sync_conflicts') !== false) {
            $id = (int) $where['conflict_id'];
            if (!isset($this->conflicts[$id])) { return false; }
            $this->conflicts[$id] = array_merge($this->conflicts[$id], $data);
            return 1;
        }
        $this->client_writes[] = ['update', $table, $data];
        return 1;
    }
    public function query($q) {
        [$sql, $args] = self::split($q);
        if (strpos($sql, "SET status = 'superseded'") !== false) {
            [$now, $uid] = $args; $n = 0;
            foreach ($this->conflicts as $id => $r) {
                if ((int) $r['wp_user_id'] === $uid && in_array($r['status'], ['open', 'dismissed'], true)) {
                    $this->conflicts[$id] = array_merge($r, ['status' => 'superseded', 'resolution' => 'relinked', 'resolved_at' => $now, 'open_dedup' => null]);
                    $n++;
                }
            }
            return $n;
        }
        if (preg_match('/^\s*(UPDATE|INSERT|DELETE|REPLACE)/i', $sql) && strpos($sql, 'meals_event_log') === false) {
            $this->client_writes[] = ['query', $sql];
        }
        return 0;
    }
    public function get_var($q) { return null; }
    public function get_row($q, $o = null) { return null; }

    // Test helpers.
    public function event_names(): array { return array_column($this->events, 'event'); }
    public function live(): array {
        $out = [];
        foreach ($this->conflicts as $r) {
            if (in_array($r['status'], ['open', 'dismissed'], true)) { $out[$r['wp_user_id'] . '|' . $r['field']] = $r; }
        }
        return $out;
    }
}

$failures = []; $passed = 0;
function chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $label;
}

$E = 'MealsDB_Sync_Reconciler';

// ===========================================================================
// Part 1 — evaluate_field (pure)
// ===========================================================================
$o = static fn (string $col, string $wp, array $meals) => $E::evaluate_field($col, $wp, $meals);

// T1: equal after normalisation.
chk($o('client_phone_1', '(506) 555-0100', [10 => '5065550100'])['outcome'] === 'equal', 'T1: formatted phone equal');
// T2/T3 at classification level.
$r = $o('city', 'Moncton', [10 => '']);
chk($r['outcome'] === 'fill' && $r['fill_clients'] === [10] && !$r['fill_wp'], 'T2: MealsDB blank, WP populated -> fill MealsDB');
$r = $o('city', '', [10 => 'Moncton']);
chk($r['outcome'] === 'fill' && $r['fill_wp'] && $r['fill_clients'] === [], 'T3: WP blank, MealsDB populated -> fill WP');
// T4.
chk($o('last_name', 'Smyth', [10 => 'Smith'])['outcome'] === 'conflict', 'T4: both populated, different -> conflict');
chk($o('city', '', [10 => ''])['outcome'] === 'blank', 'all blank -> blank');
// T7.
$r = $o('client_phone_2', '0', [10 => '506-555-0200']);
chk($r['outcome'] === 'fill' && $r['fill_wp'], "T7: shipping_phone '0' filled from client_phone_2, no conflict");
// T8 / T10.
chk($o('client_type', 'sdnb rural', [10 => 'SDNB'])['outcome'] === 'equal', 'T8: sdnb rural == SDNB');
chk($o('client_type', 'extramural', [10 => 'SDNB'])['outcome'] === 'equal', 'T8: extramural == SDNB');
chk($o('client_type', 'private', [10 => 'SDNB'])['outcome'] === 'conflict', 'T10: private vs SDNB -> conflict');
$r = $o('client_type', '', [10 => 'Veteran']);
chk($r['outcome'] === 'fill' && $r['fill_wp'], 'T9 (classification): blank customer_group, Veteran -> fill WP');
$r = $o('client_type', 'meals on wheels', [10 => 'SDNB']);
chk($r['outcome'] === 'conflict' && $r['unrecognised'] === ['meals on wheels'], 'T31: unrecognised group -> conflict + reported');
// T20 / T42 / T43.
chk($o('service_commence_date', '202409-02-03', [10 => '2024-09-02'])['outcome'] === 'conflict', 'T20: unparseable vs real date -> conflict');
chk($o('service_commence_date', '202409-02-03', [10 => ''])['outcome'] === 'conflict', 'T42: unparseable never fills a blank DATE');
chk($o('delivery_frequency', '2 weeks', [10 => ''])['outcome'] === 'conflict', 'T43: non-integer never fills a blank INT');
chk($o('delivery_frequency', '2', [10 => '02'])['outcome'] === 'equal', 'integer: 2 == 02');
chk($o('service_commence_date', '202409-02-03', [10 => '202409-02-03'])['outcome'] === 'equal', 'identical garbage both sides: equal, no phantom conflict');

// T33 / T34: multi-client accounts.
$r = $o('client_phone_2', '', [20 => '', 21 => '506-555-0200']);
chk($r['outcome'] === 'fill' && $r['fill_wp'] && $r['fill_clients'] === [20], 'T33: siblings agree -> fill WP and the blank sibling');
chk($o('first_name', 'Bob', [20 => 'Bob', 21 => 'Rob'])['outcome'] === 'conflict', 'T34: siblings disagree -> conflict, even if WP matches one');
chk($o('first_name', '', [20 => 'Bob', 21 => 'Rob'])['outcome'] === 'conflict', 'T34: siblings disagree, WP blank -> conflict, no fill');
chk($o('client_type', 'veterans', [20 => 'Veteran', 21 => 'Private'])['outcome'] === 'conflict', 'dual-program client_type -> conflict (dismissed by operator)');

// ITEM 5 initials.
$r = $o('delivery_initials', 'Betty Ann', [10 => 'BAE']);
chk($r['outcome'] === 'fill' && $r['fill_wp'], 'T14: nickname Betty Ann -> push BAE');
chk($o('delivery_initials', 'MTR', [10 => 'RKU'])['outcome'] === 'keep', 'T15: 3-char nickname kept, no conflict');
chk($o('delivery_initials', 'admin', [10 => ''])['outcome'] === 'blank', 'no initials -> nothing to push');
chk($o('delivery_initials', 'x', [20 => 'ABC', 21 => 'XYZ'])['outcome'] === 'skip', 'siblings with different initials -> skip');
chk($o('delivery_initials', 'x', [10 => 'ABC'])['fill_clients'] === [], 'initials never fill MealsDB (T16)');

// ===========================================================================
// Part 2 — run_detection end to end
// ===========================================================================
$db = new wpdb();
$GLOBALS['wpdb'] = $db;

$client = static function (array $over): array {
    $row = ['client_type' => 'SDNB', 'active' => 1];
    foreach (array_keys(MealsDB_Sync_Registry::fields()) as $c) { $row[$c] = ''; }
    $row['client_type'] = 'SDNB';
    return array_merge($row, $over);
};
$user = static function (int $id, array $core, array $meta, array $roles = ['customer']): void {
    $u = new WP_User($id);
    foreach ($core as $k => $v) { $u->$k = $v; }
    $u->roles = $roles;
    $GLOBALS['users'][$id] = $u;
    foreach ($meta as $k => $v) { $GLOBALS['meta']["$id|$k"] = $v; }
};

// Account 100: one client.
$db->clients[] = $client([
    'client_id' => 10, 'wp_user_id' => 100, 'first_name' => 'Ann', 'last_name' => 'Smith',
    'client_email' => 'ann@example.com', 'client_phone_1' => '(506) 555-0100', 'street_name' => '1 Main St',
    'delivery_area_name' => 'Zone 1', 'service_center_charged' => 'Moncton', 'delivery_initials' => 'ANS',
    'service_commence_date' => '2024-09-03', 'delivery_frequency' => '1',
]);
$user(100, ['first_name' => 'Ann', 'last_name' => 'Smyth', 'user_email' => 'ANN@example.com'], [
    'billing_phone' => '5065550100', 'billing_address_1' => '', 'customer_group' => 'sdnb rural',
    'shipping_address_2' => 'zone 1', 'billing_address_2' => 'Zone 1', 'service_centre_charged' => 'moncton',
    'nickname' => 'annsmith', 'commence_date' => '202409-02-03', 'delivery_frequency' => '1',
]);
// Account 200: two clients (dual-program).
$db->clients[] = $client(['client_id' => 20, 'wp_user_id' => 200, 'client_type' => 'Veteran',
    'first_name' => 'Bob', 'last_name' => 'Brown', 'client_phone_2' => '']);
$db->clients[] = $client(['client_id' => 21, 'wp_user_id' => 200, 'client_type' => 'Private',
    'first_name' => 'Rob', 'last_name' => 'Brown', 'client_phone_2' => '506-555-0200']);
$user(200, ['first_name' => 'Bob', 'last_name' => 'Brown'], [
    'shipping_phone' => '0', 'customer_group' => 'veterans',
    'shipping_address_2' => 'Zone 2', 'billing_address_2' => 'Zone 3',
]);
// Account 1: site admin linked to test client 1 (T30).
$db->clients[] = $client(['client_id' => 1, 'wp_user_id' => 1, 'last_name' => 'Test']);
$user(1, ['last_name' => 'Admin'], [], ['administrator']);
// Account 500: a meals_staff link (T30).
$db->clients[] = $client(['client_id' => 50, 'wp_user_id' => 500, 'last_name' => 'Staffer']);
$user(500, ['last_name' => 'Different'], []);
$db->staff = [500];
// Account 400: inactive client only (T37).
$db->clients[] = $client(['client_id' => 40, 'wp_user_id' => 400, 'active' => 0, 'last_name' => 'Gone']);
$user(400, ['last_name' => 'Other'], []);
// Account 600: WP user deleted.
$db->clients[] = $client(['client_id' => 60, 'wp_user_id' => 600, 'last_name' => 'Orphan']);
// Untracked client type is ignored entirely.
$db->clients[] = $client(['client_id' => 70, 'wp_user_id' => 700, 'client_type' => '', 'last_name' => 'X']);

$stats = $E::run_detection();

chk(!isset($stats['failed']), 'run 1 completed');
chk($stats['accounts_evaluated'] === 2, 'run 1: 2 accounts evaluated (100, 200)');
chk($stats['accounts_multi_client'] === 1, 'run 1: 1 multi-client account');
chk($stats['accounts_staff_skipped'] === 2, 'T30: admin (wp 1) and meals_staff (wp 500) skipped');
chk($stats['accounts_missing_user'] === 1, 'run 1: missing WP user counted');

$live = $db->live();
$expected = ['100|last_name', '100|service_commence_date', '200|first_name', '200|client_type'];
$got = array_keys($live); sort($got); $exp = $expected; sort($exp);
chk($got === $exp, 'run 1: exactly the expected conflicts recorded: ' . implode(', ', $got));
chk(!isset($live['1|last_name']) && !isset($live['500|last_name']) && !isset($live['400|last_name']),
    'T30/T37: no conflicts for staff, admin or inactive accounts');
chk(($live['200|first_name']['meals_values'] ?? '') === '{"20":"Bob","21":"Rob"}', 'T34: one row for the account, both clients\' raw values');
chk(($live['200|first_name']['wp_value'] ?? '') === 'Bob', 'raw WP value stored');
chk(($live['100|last_name']['status'] ?? '') === 'open', 'new conflicts are open');

$f = $stats['fields'];
chk(($f['client_phone_1']['equal'] ?? 0) === 1, 'T1: phone formatting difference counted equal');
chk(($f['client_email']['equal'] ?? 0) === 1, 'email case difference counted equal');
chk(($f['street_name']['fill_wp'] ?? 0) === 1, 'T3: blank billing_address_1 counted as a WP fill');
chk(($f['client_phone_2']['fill_wp'] ?? 0) === 1 && ($f['client_phone_2']['fill_meals'] ?? 0) === 1, "T7/T33: '0' and blank sibling counted as fills");
chk(($f['client_type']['equal'] ?? 0) === 1 && ($f['client_type']['conflict'] ?? 0) === 1, 'T8: sdnb rural equal; dual-program conflict');
chk(($f['delivery_initials']['fill'] ?? 0) === 1, 'initials push counted for account 100');
chk(($stats['wp_inconsistent']['delivery_area_name'] ?? 0) === 1 && $stats['wp_inconsistent_users'] === [200],
    'T13: address_2 disagreement logged for account 200 only');
chk(!isset($live['200|delivery_area_name']), 'T13: address_2 disagreement is not a MealsDB conflict');

// THE point of this stage: nothing written to client data.
chk($db->client_writes === [], 'detection wrote nothing to meals_clients');
chk($GLOBALS['wp_writes'] === [], 'detection wrote nothing to WordPress');
chk(in_array('sync.reconcile_detected', $db->event_names(), true), 'summary event recorded');
chk(in_array('sync.wp_internal_inconsistency', $db->event_names(), true), 'address_2 inconsistency event recorded');

// ===========================================================================
// Part 3 — lifecycle across runs
// ===========================================================================
// T5: a second run with no changes duplicates nothing.
$db->conflicts[$live['100|last_name']['conflict_id']]['last_seen_at'] = '2000-01-01 00:00:00';
$stats = $E::run_detection();
$live2 = $db->live();
chk(count($db->conflicts) === 4, 'T5: second run adds no rows');
chk(($stats['conflicts']['updated'] ?? 0) === 4, 'T5: all four rows updated in place');
chk($live2['100|last_name']['last_seen_at'] !== '2000-01-01 00:00:00', 'T5: last_seen_at refreshed');
chk($live2['100|last_name']['first_seen_at'] === $live['100|last_name']['first_seen_at'], 'T5: first_seen_at kept');

// T38: newest observation replaces the stored values.
$GLOBALS['users'][100]->last_name = 'Smythe';
$E::run_detection();
$live3 = $db->live();
chk(count($db->conflicts) === 4 && $live3['100|last_name']['wp_value'] === 'Smythe', 'T38: newest WP value replaces the old, still one row');

// T6: values converge -> auto_resolved.
$GLOBALS['users'][100]->last_name = 'smith ';
$stats = $E::run_detection();
$closed = $db->conflicts[$live['100|last_name']['conflict_id']];
chk($closed['status'] === 'auto_resolved' && $closed['resolution'] === 'auto' && $closed['open_dedup'] === null,
    'T6: converged conflict closed auto_resolved, dedup slot freed');
chk(($stats['conflicts']['auto_resolved'] ?? 0) === 1, 'T6: auto_resolved counted');

// T21: a dismissal holds while values are unchanged, reopens on change.
$row = $db->live()['200|first_name'];
$db->conflicts[$row['conflict_id']]['status'] = 'dismissed';
$db->conflicts[$row['conflict_id']]['resolution'] = 'dismissed';
$db->conflicts[$row['conflict_id']]['dismissed_pair_hash'] = $row['values_hash'];
$stats = $E::run_detection();
chk($db->conflicts[$row['conflict_id']]['status'] === 'dismissed', 'T21: dismissed stays dismissed while values unchanged');
chk(($stats['conflicts']['still_dismissed'] ?? 0) === 1, 'T21: counted as still_dismissed');
foreach ($db->clients as &$c) { if ($c['client_id'] === 21) { $c['first_name'] = 'Robert'; } } unset($c);
$stats = $E::run_detection();
$re = $db->conflicts[$row['conflict_id']];
chk($re['status'] === 'open' && $re['dismissed_pair_hash'] === null && $re['meals_values'] === '{"20":"Bob","21":"Robert"}',
    'T21: value change reopens the dismissed conflict with the new values');
chk(($stats['conflicts']['reopened'] ?? 0) === 1, 'T21: counted as reopened');

// T39: the linked client set changes -> old row superseded, fresh row.
$before_id = $db->live()['200|first_name']['conflict_id'];
foreach ($db->clients as &$c) { if ($c['client_id'] === 21) { $c['wp_user_id'] = 800; } } unset($c);
$GLOBALS['users'][800] = new WP_User(800);
$GLOBALS['users'][800]->first_name = 'Robert';
$stats = $E::run_detection();
chk($db->conflicts[$before_id]['status'] === 'superseded' && $db->conflicts[$before_id]['resolution'] === 'relinked',
    'T39: relink supersedes the old account row');
chk(!isset($db->live()['200|first_name']), 'T39: account 200 (now just Bob, matching WP) has no first_name conflict');
chk(isset($db->live()['200|client_type']) === false, 'T39: dual-program client_type conflict gone once Private client moved away');

// Sweep: an account that is no longer tracked has its rows superseded.
$cid = $db->live()['100|service_commence_date']['conflict_id'];
foreach ($db->clients as &$c) { if ($c['client_id'] === 10) { $c['active'] = 0; } } unset($c);
$stats = $E::run_detection();
chk($db->conflicts[$cid]['status'] === 'superseded', 'sweep: deactivated account\'s conflict superseded');
chk($db->client_writes === [] && $GLOBALS['wp_writes'] === [], 'no client data written across all runs');

// supersede_account (on_wp_user_deleted path).
$store = new MealsDB_Sync_Conflicts($db);
$store->record(null, 900, 'city', 'A', [90 => 'B'], '2026-11-03 00:00:00', false);
chk($store->supersede_account(900, '2026-11-03 01:00:00') === 1, 'supersede_account closes the account\'s live rows');
chk(!isset($db->live()['900|city']), 'supersede_account freed the slot');

// ===========================================================================
// Part 4 — legacy ignore delegation and failure modes
// ===========================================================================
$db2 = new wpdb();
$GLOBALS['wpdb'] = $db2;
$GLOBALS['users'] = []; $GLOBALS['meta'] = [];
$db2->clients[] = $client(['client_id' => 11, 'wp_user_id' => 110, 'last_name' => 'Smith', 'client_phone_1' => '506-555-0111']);
$user(110, ['last_name' => 'Smyth'], ['billing_phone' => '506-555-0999']);
$db2->ignored = [
    ['field_name' => 'last_name', 'source_value' => 'Smith', 'target_value' => 'Smyth'],
    // The old dashboard keyed client_phone_1 as 'phone_primary'.
    ['field_name' => 'phone_primary', 'source_value' => '506-555-0111', 'target_value' => '506-555-0999'],
];
$stats = $E::run_detection();
$l = $db2->live();
chk(($l['110|last_name']['status'] ?? '') === 'dismissed', 'legacy ignore rule -> conflict recorded as dismissed');
chk(($l['110|client_phone_1']['status'] ?? '') === 'dismissed', "legacy 'phone_primary' rule applies to client_phone_1");
chk(($stats['conflicts']['inserted_dismissed'] ?? 0) === 2, 'legacy dismissals counted');
$GLOBALS['users'][110]->last_name = 'Smithe';
$E::run_detection();
chk(($db2->live()['110|last_name']['status'] ?? '') === 'open', 'legacy-dismissed conflict reopens when values change');

// Store unreadable -> run aborts, writes nothing, does not sweep.
$db2->fail_live = true;
$before = $db2->conflicts;
$stats = $E::run_detection();
chk(isset($stats['failed']), 'unreadable store aborts the run');
chk($db2->conflicts === $before, 'aborted run changes no conflict rows');
chk(in_array('sync.reconcile_failed', $db2->event_names(), true), 'aborted run logs sync.reconcile_failed');
$db2->fail_live = false;

// Duplicate open_dedup insert -> R_ERROR, not an exception.
$store2 = new MealsDB_Sync_Conflicts($db2);
chk($store2->record(null, 110, 'last_name', 'x', [11 => 'y'], '2026-11-03 00:00:00', false) === MealsDB_Sync_Conflicts::R_ERROR,
    'insert colliding with a live row returns R_ERROR');

// values_hash: any change to either side or the client set changes it.
$h = MealsDB_Sync_Conflicts::values_hash('city', 'A', [1 => 'B']);
chk($h !== MealsDB_Sync_Conflicts::values_hash('city', 'A', [1 => 'C']), 'hash: MealsDB value change');
chk($h !== MealsDB_Sync_Conflicts::values_hash('city', 'a', [1 => 'B']), 'hash: raw (case) change');
chk($h !== MealsDB_Sync_Conflicts::values_hash('city', 'A', [1 => 'B', 2 => 'B']), 'hash: client set change');
chk($h === MealsDB_Sync_Conflicts::values_hash('city', 'A', ['1' => 'B']), 'hash: stable across key types');

// ===========================================================================
// Part 5 — wiring: the nightly runs detection first; user deletion supersedes
// ===========================================================================
$db3 = new wpdb();
$GLOBALS['wpdb'] = $db3;
$GLOBALS['users'] = []; $GLOBALS['meta'] = []; $GLOBALS['wp_writes'] = [];
$db3->clients[] = $client(['client_id' => 12, 'wp_user_id' => 120, 'last_name' => 'Lee']);
$user(120, ['last_name' => 'Li'], []);
MealsDB_Sync::run_nightly_sync();
chk(in_array('sync.reconcile_detected', $db3->event_names(), true), 'run_nightly_sync runs K21 detection');
chk(isset($db3->live()['120|last_name']), 'run_nightly_sync: detection recorded the conflict');

MealsDB_Sync::on_wp_user_deleted(120);
chk(!isset($db3->live()['120|last_name']), 'on_wp_user_deleted supersedes the account\'s conflicts immediately');
$row = array_values(array_filter($db3->conflicts, static fn ($r) => (int) $r['wp_user_id'] === 120))[0] ?? [];
chk(($row['status'] ?? '') === 'superseded' && ($row['resolution'] ?? '') === 'relinked', 'deleted user: superseded / relinked');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
