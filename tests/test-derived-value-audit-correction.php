<?php
/**
 * Tests for MealsDB_Derived_Value_Audit::apply_correction (audit-2026-08 B04 /
 * theme T1: unchecked $wpdb->update() returns treated as success).
 *
 * $wpdb->update() returns the number of rows AFFECTED (0 when the stored value
 * already equalled the target, or no row matched) or false on error. The guard
 * only rejected `=== false`, so a 0-row update still wrote a
 * 'derived_value_corrected' audit entry and counted a correction that did not
 * happen — polluting the append-only audit log and over-counting the job stat.
 * Only a genuine change (> 0 rows) is a correction.
 *
 * apply_correction is private; exercised via reflection (pure over an injected
 * $wpdb, so no DB is needed).
 *
 * Run: php tests/test-derived-value-audit-correction.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

// Capture audit-log writes.
class MealsDB_Logger {
    public static array $logs = [];
    public static function log(string $action, int $target_id, string $field, ?string $old, ?string $new, string $source = 'mealsdb') {
        self::$logs[] = compact('action', 'target_id', 'field', 'old', 'new');
    }
    public static function error($m): void {}
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

/** Minimal $wpdb whose update() returns a controllable value. */
class DvaFakeWpdb {
    public $ret = 1;
    public array $updates = [];
    public function update($table, $data, $where, $df = null, $wf = null) {
        $this->updates[] = [$table, $data, $where];
        return $this->ret;
    }
}

$failures = []; $passed = 0;
function eq($a, $e, string $l): void {
    global $failures, $passed;
    if ($a === $e) { $passed++; return; }
    $failures[] = sprintf('%s: expected %s got %s', $l, var_export($e, true), var_export($a, true));
}

$rm = new ReflectionMethod('MealsDB_Derived_Value_Audit', 'apply_correction');
$rm->setAccessible(true);
$m = ['field' => 'next_order_date', 'stored' => '2026-01-01', 'expected' => '2026-01-08', 'reason' => 'recomputed'];

// A genuine change (>0 rows) → corrected + audited.
MealsDB_Logger::$logs = []; $w = new DvaFakeWpdb(); $w->ret = 1;
eq($rm->invoke(null, $w, 'clients', 42, $m), true, 'row changed -> true');
eq(count(MealsDB_Logger::$logs), 1, 'row changed -> one audit entry');

// 0 rows affected (value already equal / no row) → NOT a correction, NOT audited.
MealsDB_Logger::$logs = []; $w = new DvaFakeWpdb(); $w->ret = 0;
eq($rm->invoke(null, $w, 'clients', 42, $m), false, 'no row changed -> false (no phantom correction)');
eq(count(MealsDB_Logger::$logs), 0, 'no row changed -> NOT audited (no phantom audit row)');

// Update error (false) → false, not audited (unchanged behaviour).
MealsDB_Logger::$logs = []; $w = new DvaFakeWpdb(); $w->ret = false;
eq($rm->invoke(null, $w, 'clients', 42, $m), false, 'update error -> false');
eq(count(MealsDB_Logger::$logs), 0, 'update error -> not audited');

// client_id <= 0 → false, no update attempted.
$w = new DvaFakeWpdb();
eq($rm->invoke(null, $w, 'clients', 0, $m), false, 'client_id 0 -> false');
eq(count($w->updates), 0, 'client_id 0 -> no update attempted');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
