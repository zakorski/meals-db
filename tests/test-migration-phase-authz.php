<?php
/**
 * Tests for the service-layer authorization gate on the consolidated migration
 * phases (audit-2026-08 H12).
 *
 * MealsDB_Migration_Consolidated::run_phase_* do destructive client/allocation
 * writes and are public statics reachable by any non-AJAX caller. Gating used
 * to live ONLY in the AJAX controllers; now each worker fails CLOSED without
 * `manage_options`, so a future WP-CLI/REST/import caller can't bypass it.
 * (The migration_destructive RATE limit stays at the AJAX layer to avoid
 * double-charging the chunked walk — not re-tested here.)
 *
 * Run: php tests/test-migration-phase-authz.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

// Controllable capability check.
$GLOBALS['cap_ok'] = true;
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return (bool) ($GLOBALS['cap_ok'] ?? true); }
}

// Minimal wpdb so an ALLOWED phase can run past the gate and return cleanly
// (0 candidate users -> quick empty stats, never touches PII).
class MigAuthzWpdb {
    public $usermeta = 'wp_usermeta';
    public $prefix   = 'wp_';
    public function prepare($q, ...$a) { return $q; }
    public function get_var($q) { return 0; }
    public function get_col($q) { return []; }
    public function get_results($q, $o = null) { return []; }
    public function query($q) { return 0; }
}
$GLOBALS['wpdb'] = new MigAuthzWpdb();

$failures = []; $passed = 0;
function chk($cond, string $l): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $l;
}

$M = 'MealsDB_Migration_Consolidated';

// --- guard_phase() unit (reflection) --------------------------------------
$g = new ReflectionMethod($M, 'guard_phase');
$g->setAccessible(true);
$GLOBALS['cap_ok'] = false;
$blocked = $g->invoke(null);
chk(is_array($blocked) && isset($blocked['error']), 'guard_phase blocks without manage_options');
$GLOBALS['cap_ok'] = true;
chk($g->invoke(null) === null, 'guard_phase passes with manage_options');

// --- every worker fails closed without the capability ---------------------
$GLOBALS['cap_ok'] = false;
$workers = [
    'run_phase_create_clients', 'run_phase_create_rates', 'run_phase_allowances',
    'run_phase_addresses', 'run_phase_next_dates', 'run_phase_private_clients',
    'run_phase_allocations', 'run_phase_delivery_day', 'run_phase_delivery_dates',
    'run_phase_rebuild_allocations_range',
];
foreach ($workers as $w) {
    $res = $M::$w(0, true, []);
    chk(is_array($res) && isset($res['error']), "$w blocked (error) without manage_options");
}

// The run_phase() dispatcher forwards to a guarded worker.
$res = $M::run_phase(1, 0, true, []);
chk(is_array($res) && isset($res['error']), 'run_phase dispatcher blocked without manage_options');

// --- with the capability, a phase runs past the gate (no auth error) -------
$GLOBALS['cap_ok'] = true;
$res = $M::run_phase_create_clients(0, true, []);
chk(is_array($res) && !isset($res['error']), 'authorized dry-run proceeds past the gate (no auth error)');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
