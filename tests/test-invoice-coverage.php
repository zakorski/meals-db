<?php
/**
 * MealsDB_Invoice_Coverage — SDNB cross-pipeline coverage check
 * (decision 2026-07-31: the cheap alternative to a monolithic invoice trunk).
 *
 * For a billing month, every active SDNB client with billable attribution
 * (mains or sides — contribution alone does not count, per the 2026-07-30
 * operator ruling) must land in exactly ONE SDNB pipeline. The check warns
 * (never blocks) on:
 *   - unroutable: legacy client whose delivery_area_zone is not M/S — falls
 *     out of BOTH legacy zone invoices.
 *   - overlap: a client present in more than one pipeline's draft (flag
 *     flipped between generations).
 *   - drift: a client in a draft whose pipeline no longer matches their
 *     current use_legacy_billing/zone routing.
 *   - missing: expected in a pipeline whose draft exists, but absent from it
 *     (e.g. attribution landed after the draft was generated).
 *   - stale: in a draft but no longer carrying attribution for the month.
 *
 * Run with: php tests/test-invoice-coverage.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('MEALS_DB_KEY')) { define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32))); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__'))            { function __($t, $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 7; } }
if (!function_exists('get_option'))    { function get_option($k, $d = false) { return $d; } }

if (!class_exists('wpdb')) { class wpdb {} }
class CovWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public array $scripted_results = []; // pattern => rows (for get_results)
    public array $scripted_rows = [];    // pattern => row (for get_row)
    public array $event_log = [];        // captured event-log inserts

    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        foreach ($args as $a) {
            $repl = is_int($a) || is_float($a) ? (string) $a : "'" . addslashes((string) $a) . "'";
            $sql = preg_replace('/%[sdf]/', str_replace('$', '\\$', $repl), $sql, 1);
        }
        return $sql;
    }
    public function get_results($sql, $o = ARRAY_A) {
        foreach ($this->scripted_results as $pat => $rows) {
            if (stripos((string) $sql, $pat) !== false) { return $rows; }
        }
        return [];
    }
    public function get_row($sql, $o = ARRAY_A) {
        foreach ($this->scripted_rows as $pat => $row) {
            if (stripos((string) $sql, $pat) !== false) { return $row; }
        }
        return null;
    }
    public function insert($table, $data, $formats = null) {
        if (stripos($table, 'event_log') !== false) {
            $this->event_log[] = $data;
            $this->insert_id = count($this->event_log);
            return 1;
        }
        return 1;
    }
    public function query($sql) { return 1; }
}

$failures = []; $passed = 0;
function cov_chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = 'FAIL: ' . $label; }
}
function cov_types(array $warnings): array {
    $out = [];
    foreach ($warnings as $w) { $out[] = $w['type'] . ':' . $w['client_id']; }
    sort($out);
    return $out;
}

// ---------------------------------------------------------------------------
// evaluate() — pure partition logic.
// expected:    client_id => pipeline_key ('sdnb_legacy:M' | 'sdnb_legacy:S' |
//              'sdnb_new_portal') or null (unroutable).
// memberships: pipeline_key => [client_id, ...] from the latest generated
//              draft per pipeline. A pipeline with NO draft is simply absent.
// ---------------------------------------------------------------------------

// 1. Clean partition, all drafts generated → no warnings.
$w = MealsDB_Invoice_Coverage::evaluate(
    [10 => 'sdnb_legacy:M', 11 => 'sdnb_legacy:S', 12 => 'sdnb_new_portal'],
    ['sdnb_legacy:M' => [10], 'sdnb_legacy:S' => [11], 'sdnb_new_portal' => [12]]
);
cov_chk($w === [], '1: clean partition produces no warnings');

// 2. Unroutable: legacy client with a zone outside M/S.
$w = MealsDB_Invoice_Coverage::evaluate([10 => null], []);
cov_chk(cov_types($w) === ['unroutable:10'], '2: unroutable legacy client flagged');

// 3. Overlap: client in two drafts (flag flipped between generations).
$w = MealsDB_Invoice_Coverage::evaluate(
    [10 => 'sdnb_new_portal'],
    ['sdnb_legacy:M' => [10], 'sdnb_new_portal' => [10]]
);
$types = cov_types($w);
cov_chk(in_array('overlap:10', $types, true), '3: overlap flagged');

// 4. Drift: client sits in a draft that no longer matches their routing;
//    their expected pipeline's draft does not exist yet → drift only.
$w = MealsDB_Invoice_Coverage::evaluate(
    [10 => 'sdnb_new_portal'],
    ['sdnb_legacy:M' => [10]]
);
cov_chk(cov_types($w) === ['drift:10'], '4: drifted client flagged once');

// 5. Missing: expected pipeline's draft exists but client absent from it.
$w = MealsDB_Invoice_Coverage::evaluate(
    [10 => 'sdnb_new_portal', 11 => 'sdnb_new_portal'],
    ['sdnb_new_portal' => [11]]
);
cov_chk(cov_types($w) === ['missing:10'], '5: missing client flagged');

// 6. NOT missing when the expected pipeline has no draft yet (generating
//    legacy M first must not flag every new-portal client as missing).
$w = MealsDB_Invoice_Coverage::evaluate(
    [10 => 'sdnb_legacy:M', 12 => 'sdnb_new_portal'],
    ['sdnb_legacy:M' => [10]]
);
cov_chk($w === [], '6: absent draft does not produce missing warnings');

// 7. Stale: in a draft but no attribution any more.
$w = MealsDB_Invoice_Coverage::evaluate(
    [],
    ['sdnb_legacy:M' => [10]]
);
cov_chk(cov_types($w) === ['stale:10'], '7: stale draft row flagged');

// 8. Every warning carries a non-empty human message.
$w = MealsDB_Invoice_Coverage::evaluate(
    [10 => null],
    ['sdnb_legacy:M' => [11]]
);
$all_have_messages = !empty($w);
foreach ($w as $one) {
    if (!isset($one['message']) || trim((string) $one['message']) === '') { $all_have_messages = false; }
}
cov_chk($all_have_messages, '8: warnings carry human-readable messages');

// ---------------------------------------------------------------------------
// check_month() — end to end against scripted tables + real encrypted drafts.
// Scenario: month 2026-07.
//   client 10: legacy, zone M, attribution      → expects sdnb_legacy:M
//   client 11: legacy, zone X (bad), attribution → unroutable
//   client 12: new portal, attribution           → expects sdnb_new_portal
// Drafts: legacy M draft contains 10 AND 12 (12 flipped to new portal after
// the M draft was generated) → overlap once the new-portal draft (with 12)
// exists, plus 12's drift out of legacy:M.
// ---------------------------------------------------------------------------

$wpdb = new CovWpdb();
$GLOBALS['wpdb'] = $wpdb;

$wpdb->scripted_results = [
    // expected_partition join (clients × allocations)
    'FROM `wp_meals_clients`' => [
        ['client_id' => 10, 'use_legacy_billing' => '1', 'delivery_area_zone' => 'M'],
        ['client_id' => 11, 'use_legacy_billing' => '1', 'delivery_area_zone' => 'X'],
        ['client_id' => 12, 'use_legacy_billing' => '0', 'delivery_area_zone' => 'S'],
    ],
    // MealsDB_Invoice_Draft::list() for the month (newest first).
    'FROM `wp_meals_invoice_drafts`' => [
        ['draft_id' => 2, 'pipeline' => 'sdnb_new_portal', 'billing_month' => '2026-07',
         'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => 'draft',
         'row_count' => 1, 'edit_count' => 0, 'created_by' => 7,
         'created_at' => '2026-08-01 02:00:00', 'finalized_by' => null, 'finalized_at' => null],
        ['draft_id' => 1, 'pipeline' => 'sdnb_legacy', 'billing_month' => '2026-07',
         'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => 'draft',
         'row_count' => 2, 'edit_count' => 0, 'created_by' => 7,
         'created_at' => '2026-08-01 01:00:00', 'finalized_by' => null, 'finalized_at' => null],
    ],
];
// Draft payloads: real encryption, so MealsDB_Invoice_Draft::get() decodes them.
$legacy_payload = MealsDB_Encryption::encode_payload([
    'schema' => 1,
    'generated' => [10 => ['client_id' => 10], 12 => ['client_id' => 12]],
    'current'   => [10 => ['client_id' => 10], 12 => ['client_id' => 12]],
]);
$portal_payload = MealsDB_Encryption::encode_payload([
    'schema' => 1,
    'generated' => [12 => ['client_id' => 12]],
    'current'   => [12 => ['client_id' => 12]],
]);
$wpdb->scripted_rows = [
    'draft_id = 1' => ['draft_id' => 1, 'pipeline' => 'sdnb_legacy', 'billing_month' => '2026-07',
        'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
        'params' => json_encode(['zone' => 'M']), 'status' => 'draft',
        'payload' => $legacy_payload, 'row_count' => 2, 'edit_count' => 0,
        'created_by' => 7, 'created_at' => '2026-08-01 01:00:00',
        'finalized_by' => null, 'finalized_at' => null],
    'draft_id = 2' => ['draft_id' => 2, 'pipeline' => 'sdnb_new_portal', 'billing_month' => '2026-07',
        'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
        'params' => json_encode([]), 'status' => 'draft',
        'payload' => $portal_payload, 'row_count' => 1, 'edit_count' => 0,
        'created_by' => 7, 'created_at' => '2026-08-01 02:00:00',
        'finalized_by' => null, 'finalized_at' => null],
];

$warnings = MealsDB_Invoice_Coverage::check_month('2026-07');
$types = cov_types($warnings);
cov_chk(in_array('unroutable:11', $types, true), '9: end-to-end flags the unroutable zone-X legacy client');
cov_chk(in_array('overlap:12', $types, true), '9: end-to-end flags the flipped client present in both drafts');
cov_chk(!in_array('unroutable:10', $types, true) && !in_array('missing:10', $types, true),
    '9: correctly-routed client 10 is not flagged');

// 10. One aggregated degraded event recorded (not one per warning).
cov_chk(count($wpdb->event_log) === 1, '10: exactly one degraded event for the month');
$ev = $wpdb->event_log[0] ?? [];
cov_chk(($ev['outcome'] ?? '') === 'degraded' && ($ev['event'] ?? '') === 'sdnb_coverage.gap',
    '10: event is sdnb_coverage.gap / degraded');

// 11. A clean month records NO event and returns [].
$wpdb2 = new CovWpdb();
$GLOBALS['wpdb'] = $wpdb2;
$wpdb2->scripted_results = [
    'FROM `wp_meals_clients`' => [
        ['client_id' => 10, 'use_legacy_billing' => '1', 'delivery_area_zone' => 'M'],
    ],
    'FROM `wp_meals_invoice_drafts`' => [],
];
$warnings = MealsDB_Invoice_Coverage::check_month('2026-07');
cov_chk($warnings === [], '11: clean month (no drafts yet, all routable) returns no warnings');
cov_chk(count($wpdb2->event_log) === 0, '11: no event recorded for a clean month');

// 12. A broken wpdb never throws out of check_month (Pattern 7).
class BrokenCovWpdb extends CovWpdb {
    public function get_results($sql, $o = ARRAY_A) { throw new RuntimeException('db down'); }
}
$GLOBALS['wpdb'] = new BrokenCovWpdb();
$warnings = MealsDB_Invoice_Coverage::check_month('2026-07');
cov_chk($warnings === [], '12: failure inside the check degrades to [] (never blocks generation)');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
