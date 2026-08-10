<?php
/**
 * Tests for MealsDB_Task_Type_Client_Delivery::handle_complete (audit-2026-08
 * B11). The completion callback advances the client's delivery cadence via
 * MealsDB_Client_Dates::mark_delivered(). Previously its bool return was
 * IGNORED and the guard branches were silent, so:
 *   - a task with no related client completed with zero signal;
 *   - a failed mark_delivered() (bad client / uncomputable next date) left the
 *     cadence un-advanced while the task showed delivered — the client would
 *     never get a fresh delivery task and nobody was told.
 *
 * The delivery physically happened (the operator marked it), so we do NOT
 * reopen the task — we surface a degraded event so an operator can advance the
 * dates by hand.
 *
 * Run: php tests/test-client-delivery-complete.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

// Pre-define collaborators (autoloader won't override a defined class).
class MealsDB_Event_Log {
    public static array $records = [];
    public static function record(array $args): void { self::$records[] = $args; }
}
class MealsDB_Logger {
    public static function error($m): void {}
}
class MealsDB_Client_Dates {
    public static bool $return = true;
    public static array $calls = [];
    public static function mark_delivered(int $client_id, string $delivered_date): bool {
        self::$calls[] = [$client_id, $delivered_date];
        return self::$return;
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

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
function reset_state(bool $mark_return): void {
    MealsDB_Event_Log::$records = [];
    MealsDB_Client_Dates::$calls = [];
    MealsDB_Client_Dates::$return = $mark_return;
}

$H = ['MealsDB_Task_Type_Client_Delivery', 'handle_complete'];

// --- no related client → degrade, never call mark_delivered ---------------
reset_state(true);
$H(['task_id' => 5], ['delivered_date' => '2026-08-10'], 7);
eq(count(MealsDB_Client_Dates::$calls), 0, 'no-entity: mark_delivered not called');
eq(degraded('client_delivery.no_entity'), 1, 'no-entity: degraded event recorded');

// --- mark_delivered FAILS → degrade, task not reopened --------------------
reset_state(false);
$H(['task_id' => 6, 'related_entity_id' => 42], ['delivered_date' => '2026-08-10'], 7);
eq(MealsDB_Client_Dates::$calls, [[42, '2026-08-10']], 'fail: mark_delivered called with client + date');
eq(degraded('client_delivery.cadence_not_advanced'), 1, 'fail: cadence_not_advanced degraded event');

// --- mark_delivered SUCCEEDS → no degraded events -------------------------
reset_state(true);
$H(['task_id' => 7, 'related_entity_id' => 42], ['delivered_date' => '2026-08-10'], 7);
eq(MealsDB_Client_Dates::$calls, [[42, '2026-08-10']], 'ok: mark_delivered called');
eq(count(MealsDB_Event_Log::$records), 0, 'ok: no degraded events on success');

// --- malformed delivered_date falls back to today (unchanged behaviour) ---
reset_state(true);
$H(['task_id' => 8, 'related_entity_id' => 42], ['delivered_date' => 'not-a-date'], 7);
eq(MealsDB_Client_Dates::$calls, [[42, gmdate('Y-m-d')]], 'fallback: bad date -> today');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
