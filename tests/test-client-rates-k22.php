<?php
/**
 * Tests for directive K22: new clients get a rate row; no rate is ever
 * silently $0.00.
 *
 * Covers the shared helper (MealsDB_Client_Rates::ensure_default_for_client),
 * the nightly pass (reached through MealsDB_Sync::run_nightly_sync), the
 * migration rate phase (client_id cursor, no $0.00 placeholder), rate
 * resolution for a newly rated client, and the dashboard panel.
 *
 * K22 Tests 1-10 are labelled T1..T10 in the check names. T1 and T3 fail
 * against v1.0.593 (no nightly rate pass; the migration wrote $0.00).
 *
 * Run: php tests/test-client-rates-k22.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
require_once __DIR__ . '/fixtures/k21-sync-fake.php';

function current_time($type, $gmt = 0) { return $type === 'Y-m-d' ? '2026-11-10' : '2026-11-10 12:00:00'; }
function update_option($k, $v, $autoload = null) { $GLOBALS['options'][$k] = $v; return true; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url($t) { return (string) $t; }
function esc_html_e($t, $d = 'default') { echo esc_html($t); }
function _n($s, $p, $n, $d = 'default') { return (int) $n === 1 ? $s : $p; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }

/**
 * The K21 fake plus the rate table: meals_client_rates rows, the queries the
 * K22 helper / migration phase / order-query issue, and failure switches.
 */
class K22Wpdb extends wpdb {
    public array $rates = [];              // rate_id => row
    public bool $fail_rate_insert = false;
    public bool $fail_rate_lookup = false;
    public bool $fail_unrated_query = false;
    private int $next_rate_id = 1;

    private function default_for(int $client_id): ?array {
        foreach ($this->rates as $r) {
            if ((int) $r['client_id'] === $client_id && (int) $r['is_default'] === 1) { return $r; }
        }
        return null;
    }
    private function unrated(bool $billed_only): array {
        $rows = array_values(array_filter($this->clients, function ($c) use ($billed_only) {
            if ($this->default_for((int) $c['client_id']) !== null) { return false; }
            return !$billed_only
                || ((int) $c['active'] === 1 && in_array($c['client_type'], ['SDNB', 'Veteran'], true));
        }));
        usort($rows, static fn ($a, $b) => $a['client_id'] <=> $b['client_id']);
        return $rows;
    }
    public function seed_rate(int $client_id, string $rate, int $is_default = 1): int {
        $id = $this->next_rate_id++;
        $this->rates[$id] = ['rate_id' => $id, 'client_id' => $client_id, 'label' => 'Standard', 'rate' => $rate, 'is_default' => $is_default];
        return $id;
    }

    public function get_results($q, $o = null) {
        $sql  = is_array($q) ? $q['sql'] : (string) $q;
        $args = is_array($q) ? $q['args'] : [];
        if (strpos($sql, 'r.is_default = 1') !== false && strpos($sql, 'c.active = 1') !== false) {
            $this->last_error = '';
            if ($this->fail_unrated_query) { $this->last_error = 'forced'; return null; }
            [$cursor, $limit] = $args;
            return array_slice(array_values(array_filter($this->unrated(true),
                static fn ($c) => (int) $c['client_id'] > $cursor)), 0, $limit);
        }
        return parent::get_results($q, $o);
    }
    public function get_col($q) {
        $sql  = is_array($q) ? $q['sql'] : (string) $q;
        $args = is_array($q) ? $q['args'] : [];
        if (strpos($sql, 'SELECT c.client_id FROM') !== false) {
            [$cursor, $limit] = $args;
            $ids = array_map(static fn ($c) => (int) $c['client_id'], $this->unrated(false));
            return array_slice(array_values(array_filter($ids, static fn ($id) => $id > $cursor)), 0, $limit);
        }
        return parent::get_col($q);
    }
    public function get_var($q) {
        $sql  = is_array($q) ? $q['sql'] : (string) $q;
        $args = is_array($q) ? $q['args'] : [];
        if (strpos($sql, 'SELECT rate_id FROM') !== false) {
            $this->last_error = '';
            if ($this->fail_rate_lookup) { $this->last_error = 'forced'; return null; }
            $r = $this->default_for((int) $args[0]);
            return $r === null ? null : (string) $r['rate_id'];
        }
        if (strpos($sql, 'SELECT MAX(c.client_id)') !== false) {
            $ids = array_map(static fn ($c) => (int) $c['client_id'], $this->unrated(false));
            return $ids === [] ? null : (string) max($ids);
        }
        return parent::get_var($q);
    }
    public function get_row($q, $o = null) {
        $sql  = is_array($q) ? $q['sql'] : (string) $q;
        $args = is_array($q) ? $q['args'] : [];
        if (strpos($sql, 'SELECT client_id, wp_user_id FROM') !== false) {
            $this->last_error = '';
            foreach ($this->clients as $c) {
                if ((int) $c['client_id'] === (int) $args[0]) {
                    return ['client_id' => $c['client_id'], 'wp_user_id' => $c['wp_user_id']];
                }
            }
            return null;
        }
        if (strpos($sql, 'SELECT rate FROM') !== false) {
            if (strpos($sql, 'rate_id = %d') !== false) {
                $r = $this->rates[(int) $args[0]] ?? null;
                return ($r !== null && (int) $r['client_id'] === (int) $args[1]) ? ['rate' => $r['rate']] : null;
            }
            $r = $this->default_for((int) $args[0]);
            return $r === null ? null : ['rate' => $r['rate']];
        }
        return parent::get_row($q, $o);
    }
    public function insert($table, $data, $f = null) {
        if (strpos($table, 'meals_client_rates') !== false) {
            if ($this->fail_rate_insert) { $this->last_error = 'forced insert failure'; return false; }
            $id = $this->next_rate_id++;
            $this->rates[$id] = $data + ['rate_id' => $id];
            $this->insert_id  = $id;
            return 1;
        }
        return parent::insert($table, $data, $f);
    }
    public function update($table, $data, $where, $f = null, $wf = null) {
        if (strpos($table, 'meals_clients') !== false && array_keys($data) === ['default_rate_id']) {
            foreach ($this->clients as &$c) {
                if ((int) $c['client_id'] === (int) $where['client_id']) { $c['default_rate_id'] = $data['default_rate_id']; }
            }
            unset($c);
            return 1;
        }
        return parent::update($table, $data, $where, $f, $wf);
    }
}

$failures = []; $passed = 0;
function chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $label;
}

/**
 * Fresh world. $clients: client_id => [wp_user_id, client_type, basic_cost
 * (null = meta absent), plus optional 'active' / 'roles' / names].
 */
function world(array $clients): K22Wpdb {
    $db = new K22Wpdb();
    $GLOBALS['wpdb']  = $db;
    $GLOBALS['users'] = []; $GLOBALS['meta'] = []; $GLOBALS['wp_writes'] = [];
    $GLOBALS['options']['mealsdb_settings'] = ['shadow_mode' => '0'];
    $GLOBALS['cap_ok'] = true;
    foreach ($clients as $cid => $c) {
        $uid = (int) $c['wp'];
        $row = ['client_id' => $cid, 'wp_user_id' => $uid, 'client_type' => $c['type'] ?? 'SDNB',
            'active' => $c['active'] ?? 1, 'default_rate_id' => null,
            'first_name' => $c['first'] ?? 'First' . $cid, 'last_name' => $c['last'] ?? 'Last' . $cid,
            'delivery_area_zone' => 'M', 'delivery_day' => ''];
        foreach (array_keys(MealsDB_Sync_Registry::fields()) as $col) { $row[$col] = $row[$col] ?? ''; }
        $db->clients[] = $row;
        if ($uid > 0) {
            $u = new WP_User($uid);
            $u->roles = $c['roles'] ?? ['customer'];
            $GLOBALS['users'][$uid] = $u;
            if (array_key_exists('cost', $c) && $c['cost'] !== null) { $GLOBALS['meta']["$uid|basic_cost"] = $c['cost']; }
        }
    }
    return $db;
}
function client_row(K22Wpdb $db, int $cid): array {
    foreach ($db->clients as $r) { if ((int) $r['client_id'] === $cid) { return $r; } }
    return [];
}
function rates_for(K22Wpdb $db, int $cid): array {
    return array_values(array_filter($db->rates, static fn ($r) => (int) $r['client_id'] === $cid));
}
function events(K22Wpdb $db, string $name): array {
    return array_values(array_filter($db->events, static fn ($e) => ($e['event'] ?? '') === $name));
}
function ctx(array $event): array { return json_decode((string) ($event['context'] ?? ''), true) ?: []; }
/** No rate row anywhere is $0.00 or outside the plausible window. */
function no_bad_rates(K22Wpdb $db): bool {
    foreach ($db->rates as $r) {
        $cents = MealsDB_Money::to_cents($r['rate']);
        if ($cents < MealsDB_Client_Rates::MIN_RATE_CENTS || $cents > MealsDB_Client_Rates::MAX_RATE_CENTS) { return false; }
    }
    return true;
}

// ---------------------------------------------------------------------------
// evaluate_basic_cost (ITEM 3) — pure
// ---------------------------------------------------------------------------
$ev = static fn ($v) => MealsDB_Client_Rates::evaluate_basic_cost($v);
chk($ev('11.40') === ['status' => 'ok', 'rate' => '11.40'], 'evaluate: 11.40 ok');
chk($ev(' 11.4 ')['rate'] === '11.40' && $ev('12')['rate'] === '12.00', 'evaluate: normalised to 2 decimals');
chk($ev('1.00')['status'] === 'ok' && $ev('50.00')['status'] === 'ok', 'evaluate: range edges are inclusive');
foreach ([null, false, '', '   ', '0', '0.00', 0, 0.0] as $v) {
    chk($ev($v)['status'] === 'no_basic_cost', 'evaluate: missing/empty/zero -> no_basic_cost (' . var_export($v, true) . ')');
}
foreach (['23455', '23455.00', '0.99', '50.01', '-11.40', 'abc', '$11.40', '11,40', '1e1', '1140', ['11.40'], str_repeat('9', 40)] as $v) {
    chk($ev($v)['status'] === 'invalid_basic_cost' && $ev($v)['rate'] === null,
        'evaluate: rejected -> invalid_basic_cost (' . (is_array($v) ? 'array' : substr((string) $v, 0, 12)) . ')');
}

// ---------------------------------------------------------------------------
// Nightly pass (ITEM 2) — through MealsDB_Sync::run_nightly_sync
// ---------------------------------------------------------------------------
$db = world([
    1027 => ['wp' => 2027, 'cost' => '11.40'],                               // T1
    1028 => ['wp' => 2028, 'cost' => '14.66'],                               // T2 (pre-rated below)
    1029 => ['wp' => 2029, 'cost' => null],                                  // T3 missing
    1030 => ['wp' => 2030, 'cost' => '0.00'],                                // T3 zero
    1031 => ['wp' => 2031, 'cost' => '23455'],                               // T4
    1032 => ['wp' => 2032, 'cost' => '9.50', 'type' => 'Private'],           // T5
    1    => ['wp' => 1,    'cost' => '11.40', 'roles' => ['administrator']], // T6 admin / test client 1
    1033 => ['wp' => 2033, 'cost' => '11.40'],                               // T6 meals_staff link
    1034 => ['wp' => 2034, 'cost' => '11.40', 'active' => 0],                // inactive
    1035 => ['wp' => 2035, 'cost' => '9.50', 'type' => 'Veteran'],           // Veteran is billed
    1036 => ['wp' => 0,    'cost' => null],                                  // no linked WP user
    1037 => ['wp' => 2037, 'cost' => '12.25'],                               // existing $0.00 default row
]);
$db->staff = [2033];
$pre_rated = $db->seed_rate(1028, '11.40');
$zero_row  = $db->seed_rate(1037, '0.00');
MealsDB_Sync::run_nightly_sync();

$r = rates_for($db, 1027);
chk(count($r) === 1 && $r[0]['rate'] === '11.40' && (int) $r[0]['is_default'] === 1 && $r[0]['label'] === 'Standard',
    "T1: unrated SDNB client with basic_cost 11.40 -> 'Standard' default row at 11.40");
chk(($r[0]['effective_date'] ?? '') === '2026-11-10', 'T1: effective_date is the site-local day');
chk((int) client_row($db, 1027)['default_rate_id'] === (int) ($r[0]['rate_id'] ?? 0) && (int) ($r[0]['rate_id'] ?? 0) > 0,
    'T1: default_rate_id set to the new row');
chk(count(array_filter($db->audit, static fn ($a) => $a['action'] === 'rate_default_created' && (int) $a['target_id'] === 1027 && $a['new'] === '11.40')) === 1,
    'T1: creation is audit-logged');

chk(count(rates_for($db, 1028)) === 1 && $db->rates[$pre_rated]['rate'] === '11.40' && client_row($db, 1028)['default_rate_id'] === null,
    'T2: already-rated client untouched (rate not re-read from basic_cost, pointer not rewritten)');
chk(count(rates_for($db, 1037)) === 1 && $db->rates[$zero_row]['rate'] === '0.00',
    'T2: an existing $0.00 default row is left alone (data decision, out of scope)');

chk(rates_for($db, 1029) === [] && rates_for($db, 1030) === [], 'T3: missing / zero basic_cost -> no row inserted');
$no_cost_ids = array_map(static fn ($e) => ctx($e)['client_id'] ?? null, events($db, 'rate.unrated_no_basic_cost'));
sort($no_cost_ids);
chk($no_cost_ids === [1029, 1030, 1036], 'T3: rate.unrated_no_basic_cost logged once per unrated client, with the client ID');
chk(client_row($db, 1029)['default_rate_id'] === null, 'T3: client stays unrated');

chk(rates_for($db, 1031) === [], 'T4: basic_cost 23455 -> no row');
$inv = events($db, 'rate.invalid_basic_cost');
chk(count($inv) === 1 && (ctx($inv[0])['client_id'] ?? null) === 1031 && (ctx($inv[0])['basic_cost'] ?? null) === '23455'
    && $inv[0]['outcome'] === 'degraded', 'T4: rate.invalid_basic_cost logged (degraded) with the client ID and rejected value');

chk(rates_for($db, 1032) === [], 'T5: unrated Private client untouched');
chk(rates_for($db, 1) === [] && rates_for($db, 1033) === [], 'T6: administrator and meals_staff accounts untouched');
chk(rates_for($db, 1034) === [], 'inactive client untouched');
chk((rates_for($db, 1035)[0]['rate'] ?? null) === '9.50', 'Veteran client is rated');
chk(rates_for($db, 1036) === [], 'client with no linked WordPress user stays unrated (no_basic_cost)');

$summary = events($db, 'rates.created');
$sctx    = ctx($summary[0] ?? []);
chk(count($summary) === 1, 'exactly one rates.created event per run');
chk(($sctx['count'] ?? null) === 2 && ($sctx['client_ids'] ?? null) === [1027, 1035], 'rates.created carries the count and the client IDs');
chk(($summary[0]['outcome'] ?? '') === 'succeeded' && ($sctx['no_basic_cost'] ?? null) === 3 && ($sctx['invalid_basic_cost'] ?? null) === 1
    && ($sctx['staff_skipped'] ?? null) === 2, 'rates.created reports what was left unrated / skipped');
$written = clone $db;
unset($written->rates[$zero_row]);   // pre-existing, not written by this run
chk(no_bad_rates($written), 'acceptance: nothing this run wrote is $0.00 or out of range');

// Second run: fill-blank + idempotent.
$rate_count = count($db->rates);
MealsDB_Sync::run_nightly_sync();
chk(count($db->rates) === $rate_count, 'second run creates nothing');
chk((ctx(events($db, 'rates.created')[1] ?? [])['count'] ?? null) === 0, 'second run still emits its summary event, count 0');

// Shadow mode: rates are meals_* only, so the pass still runs.
$db = world([1040 => ['wp' => 2040, 'cost' => '12.25']]);
$GLOBALS['options']['mealsdb_settings'] = ['shadow_mode' => '1'];
MealsDB_Sync::run_nightly_sync();
chk((rates_for($db, 1040)[0]['rate'] ?? null) === '12.25', 'shadow mode does not suppress the rate pass');

// More clients than one batch, with permanently-unrated clients in front:
// the client_id cursor must still reach the rateable ones behind them.
$many = [];
for ($i = 1; $i <= 230; $i++) { $many[3000 + $i] = ['wp' => 6000 + $i, 'cost' => null]; }
for ($i = 231; $i <= 250; $i++) { $many[3000 + $i] = ['wp' => 6000 + $i, 'cost' => '11.40']; }
$db    = world($many);
$stats = MealsDB_Client_Rates::run_nightly_pass();
chk($stats['created'] === 20 && $stats['no_basic_cost'] === 230 && count($db->rates) === 20,
    'nightly pass walks past >1 batch of unrated clients (client_id cursor, no placeholder rows)');
chk($GLOBALS['wp_writes'] === [] && $db->client_writes === [], 'the rate pass writes nothing to WordPress or to other client columns');

// Fail closed on an unreadable staff list; abort on a failed query.
$db = world([1050 => ['wp' => 2050, 'cost' => '11.40']]);
$db->fail_staff = true;
$stats = MealsDB_Client_Rates::run_nightly_pass();
chk($stats['aborted'] === true && $db->rates === [] && (events($db, 'rates.nightly_failed')[0]['outcome'] ?? '') === 'failed',
    'staff list unreadable -> pass fails CLOSED, nothing written, failure recorded');

$db = world([1051 => ['wp' => 2051, 'cost' => '11.40']]);
$db->fail_unrated_query = true;
$stats = MealsDB_Client_Rates::run_nightly_pass();
chk($stats['aborted'] === true && $db->rates === [] && count(events($db, 'rates.nightly_failed')) === 1, 'unrated query failure -> recorded, nothing written');

// INSERT failure: counted, summary degraded — never reported as success.
$db = world([1052 => ['wp' => 2052, 'cost' => '11.40']]);
$db->fail_rate_insert = true;
$stats = MealsDB_Client_Rates::run_nightly_pass();
chk($stats['errors'] === 1 && $stats['created'] === 0 && (events($db, 'rates.created')[0]['outcome'] ?? '') === 'degraded'
    && count(events($db, 'rate.create_failed')) === 1, 'INSERT failure -> error counted, summary degraded');

// ---------------------------------------------------------------------------
// Helper statuses
// ---------------------------------------------------------------------------
$db = world([1060 => ['wp' => 2060, 'cost' => '11.40'], 1061 => ['wp' => 2061, 'cost' => '11.40']]);
$out = MealsDB_Client_Rates::ensure_default_for_client(1060, true);
chk($out['status'] === 'created' && $out['rate'] === '11.40' && $db->rates === [] && $db->events === [] && $db->audit === [],
    'dry run classifies as created and writes nothing');
chk(MealsDB_Client_Rates::ensure_default_for_client(1060)['status'] === 'created', 'helper: created');
chk(MealsDB_Client_Rates::ensure_default_for_client(1060)['status'] === 'already_rated' && count($db->rates) === 1, 'helper: already_rated, no second row');
chk(MealsDB_Client_Rates::ensure_default_for_client(99999)['status'] === 'skipped', 'helper: unknown client -> skipped');
chk(MealsDB_Client_Rates::ensure_default_for_client(0)['status'] === 'skipped', 'helper: client_id 0 -> skipped');
// A failed "is there a default row?" read must not be read as "no row".
$db->fail_rate_lookup = true;
chk(MealsDB_Client_Rates::ensure_default_for_client(1061)['status'] === 'error' && count($db->rates) === 1,
    'helper: failed default-rate lookup -> error, NOT a second default row');
$db->fail_rate_lookup = false;
// A non-default row does not make a client rated.
$db->seed_rate(1061, '14.66', 0);
chk(MealsDB_Client_Rates::ensure_default_for_client(1061)['status'] === 'created', 'helper: a non-default row does not count as rated');

// ---------------------------------------------------------------------------
// T9 — an order with rate_id = 0 resolves to the new default rate
// ---------------------------------------------------------------------------
$db = world([1027 => ['wp' => 2027, 'cost' => '11.40']]);
$oq = new MealsDB_WC_Order_Query($db);
chk(abs($oq->resolve_rate_for_order(0, 1027, 'SDNB', 'M') - MealsDB_Operational_Constants::get_sdnb_main_rate('primary', false)) < 0.0001,
    'T9 (before): unrated SDNB client falls back to the program primary rate');
MealsDB_Sync::run_nightly_sync();
chk(abs($oq->resolve_rate_for_order(0, 1027, 'SDNB', 'M') - 11.40) < 0.0001, 'T9: rate_id = 0 now resolves to the new default rate, 11.40');

// ---------------------------------------------------------------------------
// Migration rate phase (T7, T8)
// ---------------------------------------------------------------------------
$mig = [];
for ($i = 1; $i <= 150; $i++) { $mig[4000 + $i] = ['wp' => 7000 + $i, 'cost' => $i % 2 ? null : '0']; } // 150 unrateable, first
$mig[4200] = ['wp' => 7200, 'cost' => '11.40'];
$mig[4201] = ['wp' => 7201, 'cost' => '23455.00'];
$mig[4202] = ['wp' => 7202, 'cost' => '12.25'];
$mig[4203] = ['wp' => 7203, 'cost' => '14.66'];   // already rated below

/** Drive the phase the way the JS / drain loop does. */
function drive_rates(bool $dry_run): array {
    $offset = 0; $calls = 0; $totals = []; $offsets = [];
    do {
        $res = MealsDB_Migration::create_rates($offset, $dry_run);
        foreach ($res['stats'] ?? [] as $k => $v) { $totals[$k] = ($totals[$k] ?? 0) + $v; }
        $offset = (int) $res['offset']; $offsets[] = $offset; $calls++;
    } while (empty($res['complete']) && $calls < 50);
    return ['calls' => $calls, 'totals' => $totals, 'offsets' => $offsets, 'last' => $res];
}

$db = world($mig);
$db->seed_rate(4203, '14.66');
$dry = drive_rates(true);
chk($dry['calls'] < 50 && !empty($dry['last']['complete']), 'T8: dry run terminates (no placeholder needed to advance)');
chk(count($db->rates) === 1 && $db->events === [], 'dry run writes no rate row and logs no event');
chk($dry['totals']['created'] === 2 && $dry['totals']['no_basic_cost'] === 150 && $dry['totals']['invalid_basic_cost'] === 1,
    'dry run projects created / no_basic_cost / invalid_basic_cost');

$live = drive_rates(false);
chk($live['calls'] === 2 && !empty($live['last']['complete']), 'T8: live run completes in 2 chunks past 150 unrateable clients');
chk($live['offsets'] === [4100, 4202], 'T8: the cursor is the last client_id processed');
chk($live['totals'] === $dry['totals'], 'live run matches the dry-run projection');
chk((rates_for($db, 4200)[0]['rate'] ?? null) === '11.40' && (rates_for($db, 4202)[0]['rate'] ?? null) === '12.25'
    && (int) client_row($db, 4200)['default_rate_id'] > 0, 'T7: migration creates rates through the shared helper');
chk(count($db->rates) === 3 && rates_for($db, 4001) === [] && rates_for($db, 4002) === [] && rates_for($db, 4201) === [],
    'T7/T8: no placeholder row for missing, zero or implausible basic_cost');
chk(no_bad_rates($db), 'T7: migration never writes a $0.00 or out-of-range rate');
chk(count(events($db, 'rate.unrated_no_basic_cost')) === 150 && count(events($db, 'rate.invalid_basic_cost')) === 1,
    'T7: migration logs unrated clients through the helper');
chk($live['last']['total'] >= $live['last']['offset'], 'progress: total (highest unrated client_id) never trails the cursor');

$again = drive_rates(false);
chk($again['totals']['created'] === 0 && count($db->rates) === 3, 'migration re-run creates nothing new');

// An INSERT failure no longer needs to halt the phase: the cursor moves on.
$db = world([4300 => ['wp' => 7300, 'cost' => '11.40'], 4301 => ['wp' => 7301, 'cost' => '11.40']]);
$db->fail_rate_insert = true;
$res = MealsDB_Migration::create_rates(0, false);
chk($res['stats']['errors'] === 2 && $res['stats']['created'] === 0 && !empty($res['complete']) && $db->rates === [],
    'migration: INSERT failures are counted as errors, never as created');
chk(strpos((string) ($GLOBALS['options']['mealsdb_migration_log'] ?? ''), 'client_id=4300') !== false, 'migration: failure lands in the migration log');

$GLOBALS['cap_ok'] = false;
chk(isset(MealsDB_Migration::create_rates(0, false)['error']), 'migration rate phase still fails closed without manage_options');
$GLOBALS['cap_ok'] = true;

// ---------------------------------------------------------------------------
// T10 — dashboard panel
// ---------------------------------------------------------------------------
$db = world([
    1027 => ['wp' => 2027, 'cost' => null, 'first' => 'Main', 'last' => 'Only'],
    1031 => ['wp' => 2031, 'cost' => '23455', 'type' => 'Veteran', 'first' => 'Vera', 'last' => '<b>Vet</b>'],
    1041 => ['wp' => 2041, 'cost' => '11.40'],                               // pending tonight's run
    1028 => ['wp' => 2028, 'cost' => '14.66'],                               // rated
    1032 => ['wp' => 2032, 'cost' => null, 'type' => 'Private'],
    1    => ['wp' => 1,    'cost' => null, 'roles' => ['administrator']],
    1034 => ['wp' => 2034, 'cost' => null, 'active' => 0],
]);
$db->seed_rate(1028, '14.66');
$list = MealsDB_Client_Rates::get_unrated_billed_clients();
chk(array_column($list, 'reason', 'client_id') === [1027 => 'no_basic_cost', 1031 => 'invalid_basic_cost', 1041 => 'pending'],
    'T10: lists unrated SDNB/Veteran clients with their reason; rated, Private, staff and inactive excluded');
chk($db->rates !== [] && count($db->rates) === 1 && $db->events === [], 'T10: building the list writes nothing and logs nothing');

ob_start();
include __DIR__ . '/../views/partials/dashboard-unrated-clients.php';
$html = ob_get_clean();
chk(strpos($html, 'action=edit&client_id=1027') !== false && strpos($html, 'Main Only') !== false, 'T10: panel links each client');
chk(strpos($html, 'data-reason="no_basic_cost"') !== false && strpos($html, 'data-reason="invalid_basic_cost"') !== false
    && strpos($html, 'No basic_cost on the WordPress user') !== false && strpos($html, 'not a plausible rate') !== false,
    'T10: panel shows the reason for each client');
chk(strpos($html, '<b>Vet</b>') === false && strpos($html, '&lt;b&gt;Vet&lt;/b&gt;') !== false, 'T10: client names are escaped');
chk(strpos($html, 'client_id=1028') === false && strpos($html, 'client_id=1032') === false, 'T10: rated / Private clients absent from the panel');

$db->seed_rate(1027, '11.40'); $db->seed_rate(1031, '9.50'); $db->seed_rate(1041, '11.40');
ob_start();
include __DIR__ . '/../views/partials/dashboard-unrated-clients.php';
chk(trim(ob_get_clean()) === '', 'T10: panel renders nothing when every billed client is rated');

$db->fail_staff = true;
chk(MealsDB_Client_Rates::get_unrated_billed_clients() === null, 'T10: unreadable staff list -> null, not an empty list');
ob_start();
include __DIR__ . '/../views/partials/dashboard-unrated-clients.php';
chk(strpos(ob_get_clean(), 'could not be loaded') !== false, 'T10: panel says the list could not be loaded');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
