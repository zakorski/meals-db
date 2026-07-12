<?php
/**
 * Tests for the derived-value integrity check (directive ITEM1-DERIVED):
 *   - MealsDB_Derived_Value_Check (pure detection)
 *   - MealsDB_Derived_Value_Audit (nightly pass: flag-only vs auto-correct)
 *
 * Run: php tests/test-derived-value-check.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }

// ---- WP function stubs --------------------------------------------------
$GLOBALS['__options']  = [];
$GLOBALS['__usermeta'] = [];
if (!function_exists('get_option'))    { function get_option($k, $d = false) { return $GLOBALS['__options'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__options'][$k] = $v; return true; } }
if (!function_exists('get_user_meta')) { function get_user_meta($uid, $k, $single = false) { return $GLOBALS['__usermeta'][$uid][$k] ?? ''; } }
if (!function_exists('get_transient'))    { function get_transient($k) { return false; } }
if (!function_exists('set_transient'))    { function set_transient($k, $v, $t = 0) { return true; } }
if (!function_exists('delete_transient')) { function delete_transient($k) { return true; } }

// ---- Stub the collaborators so the REAL checker/audit + calculator load -
// (Pre-defining these means the autoloader never pulls the production
// versions, which would need a live $wpdb / WP runtime.)
class MealsDB_Tables { const CLIENTS = 'clients'; }
class MealsDB_DB { public static function get_table_name($t) { return 'wp_meals_' . $t; } }
class MealsDB_Event_Log {
    const OUTCOME_SUCCEEDED = 'succeeded';
    const OUTCOME_FAILED    = 'failed';
    const OUTCOME_DEGRADED  = 'degraded';
    const OUTCOME_RUNNING   = 'running';
    public static array $records = [];
    public static function record(array $e): int { self::$records[] = $e; return count(self::$records); }
    public static function reset(): void { self::$records = []; }
}
class MealsDB_Logger {
    public static array $audit = [];
    public static function log($action, $tid, $field, $old, $new, $source = 'mealsdb') {
        self::$audit[] = compact('action', 'tid', 'field', 'old', 'new');
    }
    public static function error($m) {}
    public static function sanitize_for_log($m) { return $m; }
    public static function reset(): void { self::$audit = []; }
}
class MealsDB_Job_Logger {
    public static function start($n, $c = []): int { return 1; }
    public static function finish($id, $s = []): void {}
    public static function fail($id, $m, $s = []): void {}
    public static function heartbeat($id, $s): void {}
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// ---- wpdb stub for the audit pass --------------------------------------
class DVAuditWpdb {
    public $prefix = 'wp_';
    public array $rows;
    public array $updates = [];
    public function __construct(array $rows) { $this->rows = $rows; }
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . $v . "'" : (string) (int) $v;
        }, $q);
    }
    public function get_results($q, $o = null) {
        // Single-page fixture: only the first page (OFFSET 0) returns rows.
        if (preg_match('/OFFSET\s+(\d+)/', $q, $mm) && (int) $mm[1] > 0) { return []; }
        return $this->rows;
    }
    public function update($t, $data, $where, $f = null, $wf = null) {
        $cid = (int) ($where['client_id'] ?? 0);
        $this->updates[] = ['client_id' => $cid, 'data' => $data];
        foreach ($this->rows as &$r) {
            if ((int) $r['client_id'] === $cid) {
                foreach ($data as $k => $v) { $r[$k] = $v; }
            }
        }
        unset($r);
        return 1;
    }
}

$failures = []; $passed = 0;
function ok($c, $l) { global $failures, $passed; if ($c) { $passed++; } else { $failures[] = $l; } }

// =========================================================================
//  Pure checker (MealsDB_Derived_Value_Check)
// =========================================================================

// T-1: in-sync client -> no mismatch.
$in_sync = [
    'client_id'          => 1,
    'next_delivery_date' => '2026-05-28',
    'last_delivery_date' => '2026-05-21', // Thursday
    'delivery_frequency' => 1,            // weekly
    'delivery_day'       => 'Thursday',
];
ok(MealsDB_Derived_Value_Check::check_client($in_sync) === [], 'T-1 in-sync client yields no mismatch');

// T-2: input drift (frequency changed weekly -> biweekly) is detected.
$drift_freq = [
    'client_id'          => 2,
    'next_delivery_date' => '2026-05-28', // reflects the old weekly cadence
    'last_delivery_date' => '2026-05-21',
    'delivery_frequency' => 2,            // now biweekly
    'delivery_day'       => 'Thursday',
];
$m = MealsDB_Derived_Value_Check::check_client($drift_freq);
ok(count($m) === 1 && $m[0]['field'] === 'next_delivery_date', 'T-2 input drift detected');
ok($m[0]['stored'] === '2026-05-28' && $m[0]['expected'] === '2026-06-04', 'T-2 stored vs expected reported');

// T-3: direct-edit drift on next_order_date is detected.
$drift_direct = [
    'client_id'          => 3,
    'next_order_date'    => '2026-07-01', // hand-set, not what the schedule produces
    'last_order_date'    => '2026-05-19', // Tuesday
    'ordering_frequency' => 1,
    'delivery_day'       => 'Thursday',
];
$m = MealsDB_Derived_Value_Check::check_client($drift_direct);
ok(count($m) === 1 && $m[0]['field'] === 'next_order_date' && $m[0]['expected'] === '2026-05-28',
   'T-3 direct-edit drift detected');

// T-4: can't-compute is skipped (no frequency / blank base / no schedule).
$incomplete = [
    'client_id'          => 4,
    'next_order_date'    => '2026-07-01', // stored, but ordering_frequency 0 -> null expected
    'ordering_frequency' => 0,
    'last_order_date'    => '2026-05-19',
    'next_delivery_date' => '2026-06-04', // stored, but no last_delivery_date -> null expected
    'delivery_frequency' => 2,
    'last_delivery_date' => '',
    'delivery_day'       => 'Thursday',   // stored, but no zone schedule -> null expected
    'delivery_area_name' => 'Moncton',
];
ok(MealsDB_Derived_Value_Check::check_client($incomplete) === [], 'T-4 can\'t-compute is skipped, not flagged');

// T-7: the expected value matches the canonical calculator for the same inputs.
$expected_canonical = MealsDB_Date_Calculator::next_date('2026-05-21', 2, 'thursday');
ok($m = MealsDB_Derived_Value_Check::check_client($drift_freq),
   'T-7 setup: drift present');
ok($m[0]['expected'] === $expected_canonical,
   'T-7 expected reuses MealsDB_Date_Calculator (no divergent reimplementation)');

// delivery_day drift via zone schedule.
$GLOBALS['__options']['mealsdb_zone_delivery_schedule'] = ['Moncton' => ['day' => 'Monday']];
$day_drift = [
    'client_id'          => 5,
    'delivery_day'       => 'Thursday', // schedule says Monday for Moncton
    'delivery_area_name' => 'Moncton',
];
$m = MealsDB_Derived_Value_Check::check_client($day_drift);
ok(count($m) === 1 && $m[0]['field'] === 'delivery_day' && $m[0]['expected'] === 'monday',
   'delivery_day drift detected via zone schedule');

// Case-only difference is NOT drift.
$case_only = ['client_id' => 6, 'delivery_day' => 'monday', 'delivery_area_name' => 'Moncton'];
ok(MealsDB_Derived_Value_Check::check_client($case_only) === [], 'case-only delivery_day is not drift');
$GLOBALS['__options']['mealsdb_zone_delivery_schedule'] = []; // reset

// T-9: delivery_day drift detected via zone schedule through MealsDB_Zone_Day service.
$GLOBALS['__options']['mealsdb_zone_delivery_schedule'] = ['Zone X' => ['day' => 'Tuesday']];
$day_drift_via_service = [
    'client_id'          => 7,
    'delivery_day'       => 'monday', // stored as 'monday'
    'delivery_area_name' => 'Zone X',
];
$m = MealsDB_Derived_Value_Check::check_client($day_drift_via_service);
ok(count($m) === 1 && $m[0]['field'] === 'delivery_day' && $m[0]['expected'] === 'tuesday',
   'T-9 delivery_day drift detected via MealsDB_Zone_Day service (Zone X → Tuesday)');
$GLOBALS['__options']['mealsdb_zone_delivery_schedule'] = []; // reset

// =========================================================================
//  Audit pass (MealsDB_Derived_Value_Audit)
// =========================================================================

// T-5: flag-only (auto-correct OFF) writes nothing.
MealsDB_Event_Log::reset(); MealsDB_Logger::reset();
$GLOBALS['__options']['mealsdb_derived_autocorrect'] = ['next_delivery_date' => 0, 'next_order_date' => 0, 'delivery_day' => 0];
$GLOBALS['__usermeta'] = [];
$GLOBALS['wpdb'] = new DVAuditWpdb([[
    'client_id'          => 10,
    'wp_user_id'         => 0,
    'ordering_frequency' => 0,
    'delivery_frequency' => 2,            // biweekly
    'delivery_day'       => 'Thursday',
    'delivery_area_name' => '',
    'next_order_date'    => '',
    'next_delivery_date' => '2026-05-28', // weekly value -> drift vs biweekly
    'last_delivery_date' => '2026-05-21',
]]);
MealsDB_Derived_Value_Audit::run();
$degraded = array_filter(MealsDB_Event_Log::$records, fn($r) => ($r['outcome'] ?? '') === 'degraded' && ($r['event'] ?? '') === 'client.next_delivery_date.drift');
ok(count($degraded) === 1, 'T-5 flag-only emits a degraded trunk event');
ok($GLOBALS['wpdb']->updates === [], 'T-5 flag-only writes nothing to the client row');
ok(MealsDB_Logger::$audit === [], 'T-5 flag-only writes no audit row');

// T-6: auto-correct ON writes the computed value, audits it, and is idempotent.
MealsDB_Event_Log::reset(); MealsDB_Logger::reset();
$GLOBALS['__options']['mealsdb_derived_autocorrect'] = ['next_delivery_date' => 1, 'next_order_date' => 0, 'delivery_day' => 0];
$GLOBALS['wpdb'] = new DVAuditWpdb([[
    'client_id'          => 11,
    'wp_user_id'         => 0,
    'ordering_frequency' => 0,
    'delivery_frequency' => 2,
    'delivery_day'       => 'Thursday',
    'delivery_area_name' => '',
    'next_order_date'    => '',
    'next_delivery_date' => '2026-05-28',
    'last_delivery_date' => '2026-05-21',
]]);
MealsDB_Derived_Value_Audit::run();
ok(count($GLOBALS['wpdb']->updates) === 1
   && ($GLOBALS['wpdb']->updates[0]['data']['next_delivery_date'] ?? '') === '2026-06-04',
   'T-6 auto-correct writes the computed value');
$audit_row = MealsDB_Logger::$audit[0] ?? [];
ok(($audit_row['action'] ?? '') === 'derived_value_corrected'
   && ($audit_row['old'] ?? '') === '2026-05-28' && ($audit_row['new'] ?? '') === '2026-06-04',
   'T-6 auto-correct records old->new on the audit log');
// Second run is a no-op (the corrected value now matches).
$updates_before = count($GLOBALS['wpdb']->updates);
MealsDB_Event_Log::reset();
MealsDB_Derived_Value_Audit::run();
ok(count($GLOBALS['wpdb']->updates) === $updates_before, 'T-6 second run is idempotent (no further writes)');
$degraded2 = array_filter(MealsDB_Event_Log::$records, fn($r) => ($r['event'] ?? '') === 'client.next_delivery_date.drift');
ok(count($degraded2) === 0, 'T-6 second run flags nothing (converged)');

// T-8: delivery_day honours its own (off) toggle even when other fields are ON.
MealsDB_Event_Log::reset(); MealsDB_Logger::reset();
$GLOBALS['__options']['mealsdb_zone_delivery_schedule'] = ['Moncton' => ['day' => 'Monday']];
$GLOBALS['__options']['mealsdb_derived_autocorrect'] = ['next_delivery_date' => 1, 'next_order_date' => 1, 'delivery_day' => 0];
$GLOBALS['wpdb'] = new DVAuditWpdb([[
    'client_id'          => 12,
    'wp_user_id'         => 0,
    'ordering_frequency' => 0,
    'delivery_frequency' => 0,
    'delivery_day'       => 'Thursday', // schedule says Monday -> drift
    'delivery_area_name' => 'Moncton',
    'next_order_date'    => '',
    'next_delivery_date' => '',
    'last_delivery_date' => '',
]]);
MealsDB_Derived_Value_Audit::run();
$day_flag = array_filter(MealsDB_Event_Log::$records, fn($r) => ($r['event'] ?? '') === 'client.delivery_day.drift');
ok(count($day_flag) === 1, 'T-8 delivery_day drift is flagged');
ok($GLOBALS['wpdb']->updates === [], 'T-8 delivery_day is NOT auto-corrected when its own toggle is off');
$GLOBALS['__options']['mealsdb_zone_delivery_schedule'] = [];

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
