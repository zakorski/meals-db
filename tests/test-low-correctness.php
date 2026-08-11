<?php
/**
 * LOW-findings batch 1 — defensive/correctness.
 *
 *  - MealsDB_Initials::exists_in_db fails CLOSED on a DB error (a uniqueness
 *    gate must not report "available" when it couldn't check).
 *  - MealsDB_Event_Log::record maps a PRESENT-but-unrecognised outcome to
 *    'degraded' (visible), not silently 'succeeded'; an ABSENT outcome still
 *    defaults to 'succeeded'.
 *  - MealsDB_Collection_Calculator sums money in integer cents (no float drift).
 *
 * Run: php tests/test-low-correctness.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $o = 0, $depth = 512) { return json_encode($d, $o, $depth); } }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

class LowFakeWpdb extends wpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 1;
    public ?array $get_row_return = null;
    public array $get_results_return = [];
    public array $inserts = [];
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function get_row($q, $o = null) { return $this->get_row_return; }
    // Mirror WP: a failed query returns null (not an array), which the initials
    // lookup treats as fail-closed. A clean query returns the seeded rows.
    public function get_results($q, $o = null) {
        return $this->last_error !== '' ? null : $this->get_results_return;
    }
    public function get_var($q) { return null; }
    public function insert($table, $row, $fmt = null) { $this->inserts[$table][] = $row; return 1; }
    public function query($q) { return 1; }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}

// ---------------------------------------------------------------------------
// Initials: fail closed on DB error.
// ---------------------------------------------------------------------------
$w = new LowFakeWpdb();
$w->get_row_return = null;       // "no row found" …
$w->last_error = 'server gone';  // … but the query actually errored.
$GLOBALS['wpdb'] = $w;
chk(MealsDB_Initials::exists_in_db('ABC'), true, '[initials] DB error → treated as taken (fail closed)');

$w2 = new LowFakeWpdb();
$w2->get_row_return = null;      // genuinely not found, no error.
$GLOBALS['wpdb'] = $w2;
chk(MealsDB_Initials::exists_in_db('ABC'), false, '[initials] clean "not found" → available');

// Existence-query consolidation (audit T8): MealsDB_Initials::exists_in_db()
// delegates to the canonical MealsDB_Initials_Validator::initials_exist(), so
// the two fail-closed lookups can no longer drift. Both must agree.
$w3 = new LowFakeWpdb();
$w3->get_results_return = [['id' => 5, 'first_name' => 'Pat', 'last_name' => 'Doe']];
$GLOBALS['wpdb'] = $w3;
chk(MealsDB_Initials::exists_in_db('ABC'), true, '[initials] populated match → taken (via validator)');
chk(MealsDB_Initials_Validator::initials_exist('ABC'), true, '[initials] validator initials_exist agrees');
// Editing the sole holder excludes it → the code is available to that client.
chk(MealsDB_Initials::exists_in_db('ABC', 5), false, '[initials] excluded self → available');
chk(MealsDB_Initials_Validator::initials_exist('ABC', 5), false, '[initials] validator exclusion agrees');
// Fail closed also flows through the delegated path.
$w4 = new LowFakeWpdb();
$w4->last_error = 'server gone';
$GLOBALS['wpdb'] = $w4;
chk(MealsDB_Initials_Validator::initials_exist('ABC'), true, '[initials] validator fails closed on DB error');

// ---------------------------------------------------------------------------
// Event Log: unrecognised outcome → degraded; absent → succeeded.
// ---------------------------------------------------------------------------
$evt = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);

$w = new LowFakeWpdb(); $GLOBALS['wpdb'] = $w;
MealsDB_Event_Log::record(['category' => 'test', 'event' => 'x', 'outcome' => 'fail']); // typo
chk($w->inserts[$evt][0]['outcome'] ?? '', 'degraded', '[event-log] unrecognised outcome → degraded');

$w = new LowFakeWpdb(); $GLOBALS['wpdb'] = $w;
MealsDB_Event_Log::record(['category' => 'test', 'event' => 'x']); // no outcome
chk($w->inserts[$evt][0]['outcome'] ?? '', 'succeeded', '[event-log] absent outcome → succeeded');

$w = new LowFakeWpdb(); $GLOBALS['wpdb'] = $w;
MealsDB_Event_Log::record(['category' => 'test', 'event' => 'x', 'outcome' => 'degraded']);
chk($w->inserts[$evt][0]['outcome'] ?? '', 'degraded', '[event-log] valid outcome preserved');

// ---------------------------------------------------------------------------
// Collection calculator: integer-cents sums (no float drift).
// ---------------------------------------------------------------------------
$r = MealsDB_Collection_Calculator::for_private(25.4, 0.3, 'cash');
chk($r['collect'], 25.7, '[collection] private cash sum is exact (25.4 + 0.3)');

$g = MealsDB_Collection_Calculator::for_government(10.10, 5.05, true);
chk($g['collect'], 15.15, '[collection] government sum is exact (10.10 + 5.05)');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
