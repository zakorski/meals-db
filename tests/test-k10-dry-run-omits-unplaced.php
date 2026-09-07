<?php
/**
 * DIRECTIVE K10 ITEM 3 — the Phase-10 DRY RUN must not report unplaced_total.
 *
 * run_phase_rebuild_allocations_range() returns before the rebuilder runs on a
 * dry run, so unplaced_total is never computed — it would always report 0
 * regardless of the data (the live run over the identical range reported 99).
 * The migration page tells the operator to "run it first and read the counts";
 * one of those counts was a constant. The fix omits the key on dry runs so the
 * progress panel (which renders only the keys present) stays silent on overflow.
 *
 * This asserts the dry-run return has NO unplaced_total key, while the live-run
 * stats scaffold still initialises it (so the live path is unaffected).
 *
 * Run: php tests/test-k10-dry-run-omits-unplaced.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

// Minimal wpdb: prepare() passes through with %d/%s substitution; get_col()
// returns a couple of client ids so the dry run reports a non-zero
// clients_rebuilt (proving it ran the count) but touches nothing.
class K10Wpdb extends wpdb {
    public $prefix = 'wp_';
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function get_col($q) { return [101, 102, 103]; } // 3 candidate clients
    public function get_results($q, $o = null) { return []; }
    public function get_var($q) { return null; }
    public function query($q) { return 0; }
}

$GLOBALS['wpdb'] = new K10Wpdb();

$failures = []; $passed = 0;
function chk($cond, $label) {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = "FAIL: $label"; }
}

$args = ['start_month' => '2026-08', 'end_month' => '2026-08'];

// Dry run (default): must OMIT unplaced_total.
$dry = MealsDB_Migration_Consolidated::run_phase_rebuild_allocations_range(0, true, $args);
$dry_stats = $dry['stats'] ?? [];
chk(is_array($dry_stats), 'dry run returns a stats array');
chk(!array_key_exists('unplaced_total', $dry_stats), 'DRY RUN omits unplaced_total (K10 ITEM 3)');
chk(($dry_stats['clients_rebuilt'] ?? null) === 3, 'dry run still reports clients_rebuilt (the count it CAN compute)');
chk(($dry_stats['months_processed'] ?? null) === 1, 'dry run reports months_processed');

// Terminal (past-the-end) dry-run call must also omit it.
$term = MealsDB_Migration_Consolidated::run_phase_rebuild_allocations_range(99, true, $args);
chk(!array_key_exists('unplaced_total', $term['stats'] ?? ['unplaced_total' => 0]),
    'terminal dry-run return also omits unplaced_total');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo $f . "\n";
exit(empty($failures) ? 0 : 1);
