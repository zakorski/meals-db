<?php
/**
 * Tests for MealsDB_Task_Rules::delete_rule (audit-2026-08 B11 / theme T1).
 *
 * delete_rule removes the rule, then un-orphans its spawned tasks
 * (source_rule_id -> NULL). That second $wpdb->update() return was DISCARDED,
 * so if the un-orphan failed the tasks were silently orphaned (STR-1) while
 * delete_rule reported a clean success. The delete itself still succeeded, so
 * the method still returns true — but a failed un-orphan now surfaces a
 * degraded Event Log record instead of vanishing.
 *
 * Run: php tests/test-task-rules-delete.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

// Capture degraded events.
class MealsDB_Event_Log {
    public static array $records = [];
    public static function record(array $args): void { self::$records[] = $args; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

/** Minimal $wpdb: delete()/update() return controllable values; ops recorded. */
class DelFakeWpdb {
    public $prefix = 'wp_';
    public $delete_ret = 1;
    public $update_ret = 1;
    public array $ops = [];
    public function prepare($q, ...$a) { return $q; }
    public function delete($table, $where, $format = null) {
        $this->ops[] = ['delete', $table, $where];
        return $this->delete_ret;
    }
    public function update($table, $data, $where, $df = null, $wf = null) {
        $this->ops[] = ['update', $table, $data, $where];
        return $this->update_ret;
    }
}

$failures = []; $passed = 0;
function eq($a, $e, string $l): void {
    global $failures, $passed;
    if ($a === $e) { $passed++; return; }
    $failures[] = sprintf('%s: expected %s got %s', $l, var_export($e, true), var_export($a, true));
}
function degraded(string $event): int {
    $n = 0;
    foreach (MealsDB_Event_Log::$records as $r) {
        if (($r['event'] ?? '') === $event && ($r['outcome'] ?? '') === 'degraded') { $n++; }
    }
    return $n;
}
function has_update(DelFakeWpdb $w): bool {
    foreach ($w->ops as $o) { if ($o[0] === 'update') { return true; } }
    return false;
}

// Delete OK + un-orphan OK → true, no degrade.
MealsDB_Event_Log::$records = [];
$w = new DelFakeWpdb();
$GLOBALS['wpdb'] = $w;
$rules = new MealsDB_Task_Rules($w);
eq($rules->delete_rule(5), true, 'delete ok, un-orphan ok -> true');
eq(degraded('rule.delete_unorphan_failed'), 0, 'un-orphan ok -> no degraded event');
eq(has_update($w), true, 'un-orphan update attempted');

// Delete fails → false, un-orphan NOT attempted.
$w = new DelFakeWpdb();
$w->delete_ret = false;
$GLOBALS['wpdb'] = $w;
$rules = new MealsDB_Task_Rules($w);
eq($rules->delete_rule(5), false, 'delete fails -> false');
eq(has_update($w), false, 'delete fails -> no un-orphan update');

// Delete OK but un-orphan FAILS → still true (delete succeeded) + degraded event.
MealsDB_Event_Log::$records = [];
$w = new DelFakeWpdb();
$w->update_ret = false;
$GLOBALS['wpdb'] = $w;
$rules = new MealsDB_Task_Rules($w);
eq($rules->delete_rule(5), true, 'un-orphan fails -> still true (delete succeeded)');
eq(degraded('rule.delete_unorphan_failed'), 1, 'un-orphan fails -> degraded event recorded');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
