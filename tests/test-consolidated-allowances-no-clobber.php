<?php
/**
 * Regression test for the consolidated allowances phase.
 *
 * Verifies the HIGH-severity clobber bug is fixed: when legacy usermeta
 * supplies only ONE of mains / sides / service, the consolidated phase
 * must write ONLY that column and leave the others untouched. The old
 * MealsDB_Backfill_Allowances::run() wrote all three every time, zeroing
 * allowance_sides and blanking requisition_period on a partial row.
 *
 * Run with: php tests/test-consolidated-allowances-no-clobber.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __($t, $d = 'default') { return $t; } }

$failures = [];
$passed = 0;
function check($cond, $label) {
    global $failures, $passed;
    if ($cond) { $passed++; }
    else { $failures[] = $label; }
}

/**
 * Minimal wpdb fake that captures the UPDATE SQL the phase emits and
 * serves a fixed client + usermeta set.
 */
class ConsAllowWpdb {
    public $usermeta = 'wp_usermeta';
    public $prefix = 'wp_';
    public $last_error = '';
    public array $captured_sql = [];
    private array $clients;
    private array $meta;

    public function __construct(array $clients, array $meta) {
        $this->clients = $clients;
        $this->meta = $meta;
    }

    public function prepare($q, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        // Emulate just enough of wpdb::prepare for assertions: replace
        // %d/%s/%f positionally so captured SQL shows the bound values.
        $i = 0;
        return preg_replace_callback('/%[dsf]/', function ($m) use (&$i, $args) {
            $v = $args[$i] ?? '';
            $i++;
            if ($m[0] === '%d') { return (string) (int) $v; }
            if ($m[0] === '%f') { return (string) (float) $v; }
            return "'" . $v . "'";
        }, $q);
    }

    public function get_var($q) {
        if (stripos($q, 'COUNT(') !== false) { return (string) count($this->clients); }
        return null;
    }

    public function get_results($q, $o = 'OBJECT') {
        if (stripos($q, $this->usermeta) !== false) {
            // Return the usermeta rows.
            $rows = [];
            foreach ($this->meta as $uid => $kv) {
                foreach ($kv as $k => $v) {
                    $rows[] = ['user_id' => $uid, 'meta_key' => $k, 'meta_value' => $v];
                }
            }
            return $rows;
        }
        // meals_clients select
        return array_values($this->clients);
    }

    public function query($sql) {
        $this->captured_sql[] = $sql;
        return 1;
    }

    public function get_table_name_passthrough() {}
}

// One client; usermeta supplies ONLY mains (no sides, no service).
$clients = [
    ['client_id' => 7, 'wp_user_id' => 700, 'requisition_period' => 'Week', 'allowance_mains' => null, 'allowance_sides' => 21],
];
$meta = [
    700 => ['mains' => '14'],   // sides + service intentionally absent
];

$wpdb = new ConsAllowWpdb($clients, $meta);
$GLOBALS['wpdb'] = $wpdb;

// Live run (dry_run = false) so the UPDATE is emitted and captured.
$result = MealsDB_Migration_Consolidated::run_phase_allowances(0, false);

check(isset($result['stats']), 'phase returned stats');
check(($result['stats']['updated'] ?? 0) === 1, 'one client updated');
check(count($wpdb->captured_sql) === 1, 'exactly one UPDATE emitted');

$sql = $wpdb->captured_sql[0] ?? '';

// The fix: SET must include allowance_mains and must NOT mention
// allowance_sides or requisition_period (those had no legacy value).
check(strpos($sql, 'allowance_mains') !== false, 'UPDATE sets allowance_mains');
check(strpos($sql, 'allowance_sides') === false, 'UPDATE does NOT touch allowance_sides (no clobber)');
check(strpos($sql, 'requisition_period') === false, 'UPDATE does NOT touch requisition_period (no clobber)');
check(strpos($sql, '14') !== false, 'mains value 14 bound into SET');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
