<?php
/**
 * K21 Test 32 — THE NO-OVERWRITE INVARIANT. Release gate for the K21 sync.
 *
 * Rev 2 removed the mass-conflict and mass-write breakers; this test is what
 * replaces them. It must pass before K21 ships:
 *
 *   Across every AUTOMATED sync path — the nightly reconcile, every real-time
 *   hook, detection — no value that was non-blank before a stage is different
 *   after it, on either side. The only automated replacement allowed is a
 *   value the registry itself defines as blank: an empty string, a
 *   placeholder (shipping_phone '0', integer '0', date '0'), or a nickname
 *   that is not exactly 3 characters (ITEM 5). delivery_initials never
 *   changes at all. Nothing outside the registry changes. Staff and admin
 *   accounts do not change at all.
 *
 * (Operator resolution is deliberately excluded: it is the one path allowed to
 * overwrite, and it is covered by test-sync-resolver-k21.php.)
 *
 * The data is GENERATED from a fixed seed: 300 accounts whose every registry
 * field draws from per-comparator pools — formatting variants that compare
 * equal, real differences, placeholders, unparseable values, absent keys —
 * with 1-3 linked clients, inactive siblings, staff and admin accounts, and
 * non-registry columns/meta that must never move.
 *
 * "Blank" is defined HERE, independently, from the directive's text
 * (spec_blank() below) — NOT by calling the sync's own comparators. A
 * comparator bug that read a real value as blank would make the sync fill
 * over it, and an invariant that asked the same comparator would agree with
 * the bug. This one does not.
 *
 * Run: php tests/test-sync-invariant-k21.php [seed]
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

$SEED = (int) ($argv[1] ?? 20261103);
mt_srand($SEED);

// ---------------------------------------------------------------------------
// Value pools. Placeholders appear once per pool so a field's placeholder
// fill rate stays under the 20% breaker — the test must exercise real fills,
// not a refused run.
// ---------------------------------------------------------------------------
$pick = static fn (array $pool) => $pool[mt_rand(0, count($pool) - 1)];

$meals_pool = [
    'text'    => ['', 'Main St', 'main  st', 'Oak Ave', '14 Elm St Apt 3', 'Moncton', 'Dieppe', 'Cheque', 'Cash', 'SVC-1'],
    'email'   => ['', 'a@example.org', 'A@Example.org', 'b@example.org', 'noemail412@example.org'],
    'phone'   => ['', '506-555-0100', '(506) 555-0100', '5065550199', '506-988-1777 Denise', '204-7570'],
    'postal'  => ['', 'E1C 4B5', 'e1c4b5', 'E1A 1A1'],
    'code'    => ['', 'NB', 'nb', 'NS'],
    'integer' => ['', '1', '2', '4'],
    'date'    => ['', '2024-09-03', '2024-10-01', '2025-01-15'],
];
$wp_pool = [
    'text'    => ['', ' ', 'Main St', 'MAIN ST', 'Oak Ave', '14 Elm Street Apt 3', 'Moncton', 'Dieppe', 'Cheque', 'Cash', 'SVC-1', 'SVC-2'],
    'email'   => ['', 'a@example.org', 'a@EXAMPLE.org', 'c@example.org', 'noemail412@example.org'],
    'phone'   => ['', '0', '506-555-0100', '5065550100', '1-506-555-0199', '506-988-1777', '506 555 0300', '204-7570', '506-555-0142', '506-555-0177'],
    'postal'  => ['', 'E1C 4B5', 'E1C4B5', 'E1A 1A1', 'E1B 2B2'],
    'code'    => ['', 'NB', 'NB ', 'NS', 'PE'],
    'integer' => ['', '0', '1', '01', '2', '2 weeks', '3', '4', '6', '8'],
    'date'    => ['', '0', '2024-09-03', '2024-9-3', '2024-10-01', '202409-02-03', '2025-01-15', '2025-02-01', '2023-12-31', '2025-03-03'],
];
$special_meals = [
    'delivery_area_name'     => ['', 'Zone 1', 'Zone 3', 'Zone 9'],
    'service_center_charged' => ['', 'Moncton', 'moncton', 'Sussex', 'veterans', 'Halifax'],
    'delivery_initials'      => ['', 'ANS', 'BAE', 'RKU', 'MTR'],
];
$special_wp = [
    'delivery_area_name'     => ['', 'Zone 1', 'zone 1', 'Zone 3', 'Zone 9'],
    'service_center_charged' => ['', 'Moncton', 'moncton', 'Sussex', 'SUSSEX', 'veterans', 'Halifax'],
    'client_type'            => ['', 'sdnb', 'SDNB', 'sdnb rural', 'Extra Mural', 'extramural', 'veterans', 'private', 'meals on wheels'],
    // Mostly 3-character codes (never touched); one long value per pool.
    'delivery_initials'      => ['', 'MTR', 'ANS', 'BAE', 'RKU', 'XYZ', 'QQQ', 'LMN', 'annsmith'],
];

// ---------------------------------------------------------------------------
// Generate the world
// ---------------------------------------------------------------------------
$db = new wpdb();
$GLOBALS['wpdb'] = $db;
$GLOBALS['users'] = []; $GLOBALS['meta'] = []; $GLOBALS['wp_writes'] = [];
$GLOBALS['options']['mealsdb_settings'] = ['shadow_mode' => '0'];
$fields = MealsDB_Sync_Registry::fields();
$protected_accounts = [];

$meals_value = static function (string $col, array $field) use ($pick, $meals_pool, $special_meals): string {
    if (isset($special_meals[$col])) { return $pick($special_meals[$col]); }
    if ($field['comparator'] === 'client_type') { return $pick(['SDNB', 'Veteran', 'Private']); }
    return $pick($meals_pool[$field['comparator']]);
};
$wp_value = static function (string $col, array $field) use ($pick, $wp_pool, $special_wp): string {
    if (isset($special_wp[$col])) { return $pick($special_wp[$col]); }
    return $pick($wp_pool[$field['comparator']]);
};

$client_id = 1;
for ($a = 1; $a <= 300; $a++) {
    $uid = 1000 + $a;
    if ($a === 1) { $uid = 1; } // the site admin, linked to test client 1
    $roll = mt_rand(1, 100);
    $n = $roll <= 75 ? 1 : ($roll <= 93 ? 2 : 3);

    $first = null;
    for ($k = 0; $k < $n; $k++) {
        $row = ['client_id' => $client_id++, 'wp_user_id' => $uid, 'active' => 1,
            // Non-registry columns: must never move.
            'individual_id' => 'ENC:' . bin2hex(random_bytes(4)), 'client_contribution' => '12.50',
            'use_legacy_billing' => '1', 'next_order_date' => '2026-11-10',
            'delivery_area_zone' => $pick(['', '', 'M', 'S']), 'delivery_day' => $pick(['', '', 'tuesday', 'thursday'])];
        foreach ($fields as $col => $field) {
            // Siblings usually agree; sometimes they differ.
            $row[$col] = ($first !== null && mt_rand(1, 100) <= 70) ? $first[$col] : $meals_value($col, $field);
        }
        if ($k > 0 && mt_rand(1, 100) <= 15) {
            $row['active'] = 0; // inactive sibling: never read or written
        }
        $first = $first ?? $row;
        $db->clients[] = $row;
    }

    $u = new WP_User($uid);
    $u->roles = $uid === 1 ? ['administrator'] : ['customer'];
    $u->display_name = 'User ' . $uid;
    foreach ($fields as $col => $field) {
        // WordPress usually matches client 1 exactly; otherwise draw a value.
        $v = mt_rand(1, 100) <= 45 ? $first[$col] : $wp_value($col, $field);
        if ($col === 'client_type' && mt_rand(1, 100) <= 45) {
            $v = $pick(['sdnb', 'veterans', 'private']);
        }
        if ($field['wp']['type'] === 'core') {
            $u->{$field['wp']['key']} = $v;
            continue;
        }
        if (mt_rand(1, 100) <= 8) {
            continue; // absent key
        }
        $GLOBALS['meta']["$uid|{$field['wp']['key']}"] = $v;
        foreach ($field['wp']['also_write'] ?? [] as $extra) {
            $GLOBALS['meta']["$uid|$extra"] = mt_rand(1, 100) <= 90 ? $v : $wp_value($col, $field);
        }
    }
    // Non-registry meta: must never move.
    $GLOBALS['meta']["$uid|vat_number"] = 'VAT-' . $uid;
    $GLOBALS['meta']["$uid|first_order_note"] = 'keep me';
    $GLOBALS['users'][$uid] = $u;

    if ($uid === 1 || $a % 37 === 0) {
        $protected_accounts[$uid] = true;
    }
}
$db->staff = array_values(array_filter(array_keys($protected_accounts), static fn ($id) => $id !== 1));

// ---------------------------------------------------------------------------
// Snapshot + invariant
// ---------------------------------------------------------------------------
$snapshot = static function () use ($db): array {
    $users = [];
    foreach ($GLOBALS['users'] as $uid => $u) { $users[$uid] = get_object_vars($u); }
    return ['clients' => array_column($db->clients, null, 'client_id'), 'users' => $users, 'meta' => $GLOBALS['meta']];
};

// meta key -> registry column (read keys and also_write keys)
$meta_column = [];
$core_column = [];
foreach ($fields as $col => $field) {
    if ($field['wp']['type'] === 'meta') {
        $meta_column[$field['wp']['key']] = $col;
        foreach ($field['wp']['also_write'] ?? [] as $extra) { $meta_column[$extra] = $col; }
    } else {
        $core_column[$field['wp']['key']] = $col;
    }
}

/**
 * The directive's definition of blank, restated independently of
 * MealsDB_Sync_Comparator (see the header): empty or whitespace; a phone with
 * fewer than 7 digits (K20); an integer of only zeros; a date of '0' or the
 * zero date; and, on the WordPress side of delivery_initials, a nickname that
 * is not exactly 3 characters (ITEM 5).
 */
$spec_blank = static function (string $col, string $side, string $value) use ($fields): bool {
    $t = trim(str_replace("\u{00A0}", ' ', $value));
    if ($t === '') { return true; }
    if ($col === 'delivery_initials' && $side === 'wp') { return mb_strlen($t, 'UTF-8') !== 3; }
    switch ($fields[$col]['comparator']) {
        case 'phone':   return strlen((string) preg_replace('/\D+/', '', $t)) < 7;
        case 'integer': return preg_match('/^0+$/', $t) === 1;
        case 'date':    return $t === '0' || preg_match('/^0000-00-00/', $t) === 1;
        default:        return false;
    }
};

/** @return string[] violations */
$check = static function (array $before, array $after) use ($fields, $meta_column, $core_column, $protected_accounts, $spec_blank): array {
    $v = [];
    foreach ($before['clients'] as $cid => $row) {
        $now = $after['clients'][$cid] ?? null;
        if ($now === null) { $v[] = "client $cid deleted"; continue; }
        $protected = isset($protected_accounts[(int) $row['wp_user_id']]);
        foreach ($row as $col => $old) {
            $new = $now[$col] ?? null;
            if ($new === $old) { continue; }
            if ($protected) { $v[] = "protected account client $cid.$col changed"; continue; }
            if ($col === 'delivery_initials') { $v[] = "client $cid delivery_initials changed"; continue; }
            if (isset($fields[$col])) {
                if (!$spec_blank($col, 'meals', (string) $old)) {
                    $v[] = "client $cid.$col overwritten: " . json_encode($old) . ' -> ' . json_encode($new);
                }
                continue;
            }
            if (in_array($col, ['delivery_area_zone', 'delivery_day'], true)) {
                if (trim((string) $old) !== '') { $v[] = "client $cid.$col (derived, populated) overwritten: $old -> $new"; }
                continue;
            }
            $v[] = "client $cid.$col (non-registry) changed";
        }
    }
    foreach ($before['users'] as $uid => $props) {
        $protected = isset($protected_accounts[$uid]);
        foreach ($props as $prop => $old) {
            $new = $after['users'][$uid][$prop] ?? null;
            if ($new === $old) { continue; }
            if ($protected) { $v[] = "protected account user $uid.$prop changed"; continue; }
            $col = $core_column[$prop] ?? null;
            if ($col === null) { $v[] = "user $uid.$prop (non-registry) changed"; continue; }
            if (!$spec_blank($col, 'wp', (string) $old)) {
                $v[] = "user $uid.$prop overwritten: " . json_encode($old) . ' -> ' . json_encode($new);
            }
        }
    }
    foreach ($before['meta'] as $key => $old) {
        [$uid, $mk] = explode('|', $key, 2);
        $new = $after['meta'][$key] ?? null;
        if ($new === $old) { continue; }
        if ($new === null) { $v[] = "meta $key deleted"; continue; }
        if (isset($protected_accounts[(int) $uid])) { $v[] = "protected account meta $key changed"; continue; }
        $col = $meta_column[$mk] ?? null;
        if ($col === null) { $v[] = "meta $key (non-registry) changed"; continue; }
        if (!$spec_blank($col, 'wp', (string) $old)) {
            $v[] = "meta $key overwritten: " . json_encode($old) . ' -> ' . json_encode($new);
        }
    }
    foreach ($after['meta'] as $key => $new) {
        if (array_key_exists($key, $before['meta'])) { continue; }
        [$uid, $mk] = explode('|', $key, 2);
        if (isset($protected_accounts[(int) $uid])) { $v[] = "protected account meta $key created"; }
        elseif (!isset($meta_column[$mk])) { $v[] = "non-registry meta $key created"; }
        elseif (trim((string) $new) === '') { $v[] = "meta $key created empty"; }
    }
    // Independent of the comparators: a fill never writes an empty value.
    foreach ($after['clients'] as $cid => $row) {
        foreach ($row as $col => $new) {
            $old = $before['clients'][$cid][$col] ?? null;
            if ($new !== $old && trim((string) $new) === '') { $v[] = "client $cid.$col blanked"; }
        }
    }
    return $v;
};

$report = static function (string $stage, array $violations): void {
    chk($violations === [], "Test 32 [$stage]: no populated value overwritten"
        . ($violations === [] ? '' : ' — ' . count($violations) . ' violations, e.g. ' . implode('; ', array_slice($violations, 0, 5))));
};

$audit_count = static function (string $action, ?string $source = null) use ($db): int {
    return count(array_filter($db->audit, static fn ($a) => $a['action'] === $action && ($source === null || $a['source'] === $source)));
};

// ---------------------------------------------------------------------------
// Stage 1: first nightly (the big initial fill)
// ---------------------------------------------------------------------------
$s0 = $snapshot();
$stats = MealsDB_Sync_Reconciler::run_reconcile();
$s1 = $snapshot();
chk(!isset($stats['failed']), 'stage 1 nightly ran (breaker did not refuse): ' . (string) ($stats['failed'] ?? ''));
chk($audit_count('sync_fill_client') > 50 && $audit_count('sync_fill_wp') > 50,
    sprintf('stage 1 actually filled both sides (%d MealsDB, %d WordPress writes) — not vacuous', $audit_count('sync_fill_client'), $audit_count('sync_fill_wp')));
chk(count($db->live()) > 50, 'stage 1 recorded conflicts (' . count($db->live()) . ')');
$report('nightly #1', $check($s0, $s1));

// ---------------------------------------------------------------------------
// Stage 2: users edit WordPress, then every real-time hook fires
// ---------------------------------------------------------------------------
foreach ($GLOBALS['users'] as $uid => $u) {
    if (mt_rand(1, 100) > 30 || isset($protected_accounts[$uid])) { continue; }
    foreach ($fields as $col => $field) {
        if (mt_rand(1, 100) > 15) { continue; }
        $v = $wp_value($col, $field);
        if ($field['wp']['type'] === 'core') { $u->{$field['wp']['key']} = $v; }
        else { $GLOBALS['meta']["$uid|{$field['wp']['key']}"] = $v; }
    }
}
// Some clients get a field cleared by an operator, so hooks have blanks to fill.
foreach ($db->clients as &$row) {
    if (mt_rand(1, 100) <= 10 && !isset($protected_accounts[(int) $row['wp_user_id']])) {
        $col = array_rand(array_diff_key($fields, ['delivery_initials' => 1, 'client_type' => 1]));
        $row[$col] = '';
    }
}
unset($row);

$s1b = $snapshot();
$realtime_before = $audit_count('sync_fill_client', 'realtime');
foreach (array_keys($GLOBALS['users']) as $uid) {
    MealsDB_Sync::on_wp_user_updated($uid, null);
    MealsDB_Sync::on_wc_address_saved($uid, 'billing');
    MealsDB_Sync::on_wc_address_saved($uid, 'shipping');
    MealsDB_Sync::on_wc_customer_created($uid);
}
$s2 = $snapshot();
chk($audit_count('sync_fill_client', 'realtime') - $realtime_before > 10, 'stage 2 hooks actually filled MealsDB blanks — not vacuous');
$report('real-time hooks', $check($s1b, $s2));
chk(json_encode($s1b['users']) === json_encode($s2['users'])
    && array_diff_assoc($s2['meta'], $s1b['meta']) === [] && array_diff_assoc($s1b['meta'], $s2['meta']) === [],
    'stage 2: real-time hooks wrote nothing to WordPress');

// ---------------------------------------------------------------------------
// Stage 3: detection only — must write no client data at all
// ---------------------------------------------------------------------------
MealsDB_Sync_Reconciler::run_detection();
$s3 = $snapshot();
chk(json_encode($s2) === json_encode($s3), 'stage 3: detection changed nothing in either system');

// ---------------------------------------------------------------------------
// Stage 4: second nightly after the edits
// ---------------------------------------------------------------------------
$fills_before = $audit_count('sync_fill_client') + $audit_count('sync_fill_wp');
$stats = MealsDB_Sync_Reconciler::run_reconcile();
$s4 = $snapshot();
chk(!isset($stats['failed']), 'stage 4 nightly ran');
chk($audit_count('sync_fill_client') + $audit_count('sync_fill_wp') > $fills_before, 'stage 4 filled the new blanks — not vacuous');
$report('nightly #2', $check($s3, $s4));

// ---------------------------------------------------------------------------
// Stage 5: third nightly — idempotent
// ---------------------------------------------------------------------------
MealsDB_Sync_Reconciler::run_reconcile();
$s5 = $snapshot();
chk(json_encode($s4) === json_encode($s5), 'stage 5: a nightly with nothing new to do writes nothing');

// (No end-to-end s0 -> s5 comparison: stage 2 deliberately simulates users
// and operators editing data between stages, and those edits are not sync
// writes. The invariant is per stage, against the state the stage started
// from.)

echo "Seed {$SEED}. Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
