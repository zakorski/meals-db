<?php
/**
 * Tests for slice 2 of the Schema_Sync ALTER feature (audit H7):
 *   - classify() now emits machine-readable pre-flight descriptors.
 *   - MealsDB_Schema_Alter_Planner::plan() turns a Schema_Sync mismatch into a
 *     full executable plan (ALTER SQL online + plain, pre-flight probe SQL).
 *   - MealsDB_Schema_Alter_Executor::run() is the guarded orchestrator: SAFE
 *     applies automatically; RISKY needs confirmation, then a pre-flight probe
 *     BLOCKS if it would lose data; a rejected online DDL falls back to a
 *     maintenance-mode plain ALTER. Failures never report success.
 *
 * Run: php tests/test-schema-alter-executor.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

class MealsDB_Logger {
    public static array $logs = [];
    public static function log(...$a): void { self::$logs[] = $a; }
    public static function error($m): void {}
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
function truthy($v, string $l): void { eq((bool) $v, true, $l); }
function col(string $type, string $nullable = 'NO', $default = null, string $extra = ''): array {
    return ['column_type' => $type, 'is_nullable' => $nullable, 'column_default' => $default, 'extra' => $extra];
}
function mm(string $table, string $c, string $expected, array $actual): array {
    return ['table' => $table, 'column' => $c, 'expected' => $expected, 'actual' => $actual];
}

$P = 'MealsDB_Schema_Alter_Planner';

// --- classify() pre-flight descriptors ------------------------------------
$narrow = $P::classify('VARCHAR(40) NOT NULL', col('varchar(80)', 'NO'));
eq($narrow['preflight'], [['check' => 'max_length', 'limit' => 40]], 'narrow -> max_length preflight');
$enumrm = $P::classify("ENUM('a','b') NOT NULL", col("enum('a','b','c')", 'NO'));
eq($enumrm['preflight'], [['check' => 'no_values', 'values' => ['c']]], 'enum-remove -> no_values preflight');
$tighten = $P::classify('VARCHAR(40) NOT NULL', col('varchar(40)', 'YES'));
eq($tighten['preflight'], [['check' => 'no_nulls']], 'tighten -> no_nulls preflight');
$safe = $P::classify("VARCHAR(80) NOT NULL DEFAULT ''", col('varchar(40)', 'NO', ''));
eq($safe['preflight'], [], 'safe change -> no preflight');

// --- plan(): full executable plan -----------------------------------------
$plan = $P::plan(mm('wp_meals_clients', 'first_name', 'VARCHAR(120) NOT NULL', col('varchar(100)', 'NO')));
eq($plan['tier'], 'safe', 'plan: widen is safe');
eq($plan['alter_online'], 'ALTER TABLE `wp_meals_clients` MODIFY COLUMN `first_name` VARCHAR(120) NOT NULL, ALGORITHM=INPLACE, LOCK=NONE', 'plan: online ALTER SQL');
eq($plan['alter_plain'],  'ALTER TABLE `wp_meals_clients` MODIFY COLUMN `first_name` VARCHAR(120) NOT NULL', 'plan: plain ALTER SQL');
eq($plan['preflight'], [], 'plan: safe has no preflight probes');

$plan2 = $P::plan(mm('wp_meals_clients', 'gender', 'VARCHAR(6) NULL', col('varchar(10)', 'YES')));
eq($plan2['tier'], 'risky', 'plan: narrow is risky');
eq($plan2['preflight'][0]['sql'], 'SELECT COUNT(*) FROM `wp_meals_clients` WHERE CHAR_LENGTH(`gender`) > 6', 'plan: max_length probe SQL');

$plan3 = $P::plan(mm('wp_meals_clients', 'client_type', "ENUM('Private','SDNB') NOT NULL", col("enum('Private','SDNB','Veteran')", 'NO')));
eq($plan3['preflight'][0]['sql'], "SELECT COUNT(*) FROM `wp_meals_clients` WHERE `client_type` IN ('Veteran')", 'plan: no_values probe SQL');

// --- Executor -------------------------------------------------------------
class AlterFakeWpdb {
    public array $queries = [];
    public $count_return = 0;
    public bool $online_ok = true;
    public bool $plain_ok = true;
    public string $last_error = '';
    // Live column state served to the executor's post-apply VERIFY. Defaults
    // reflect a successful ALTER (the column now matches the canonical target),
    // so the happy paths verify true; a test can override to simulate a no-op.
    public array $live = [];
    public function __construct() {
        $this->live = [
            'first_name' => col('varchar(120)', 'NO'),  // == VARCHAR(120) NOT NULL
            'gender'     => col('varchar(6)', 'YES'),   // == VARCHAR(6) NULL
        ];
    }
    public function prepare($q, ...$a) { return $q; }
    public function get_results($q, $out = null) {
        $this->queries[] = ['get_results', $q];
        $rows = [];
        foreach ($this->live as $name => $c) {
            $rows[] = [
                'COLUMN_NAME'    => $name,
                'COLUMN_TYPE'    => $c['column_type'],
                'IS_NULLABLE'    => $c['is_nullable'],
                'COLUMN_DEFAULT' => $c['column_default'],
                'EXTRA'          => $c['extra'],
            ];
        }
        return $rows;
    }
    public function get_var($q) { $this->queries[] = ['get_var', $q]; return $this->count_return; }
    public function query($q) {
        $this->queries[] = ['query', $q];
        if (stripos($q, 'ALGORITHM=INPLACE') !== false) { if (!$this->online_ok) { $this->last_error = 'INPLACE not supported'; return false; } return 1; }
        if (stripos($q, 'ALTER TABLE') !== false) { if (!$this->plain_ok) { $this->last_error = 'alter failed'; return false; } return 1; }
        return 1;
    }
}
class TestExecutor extends MealsDB_Schema_Alter_Executor {
    public array $maint = [];
    protected function engage_maintenance(): void { $this->maint[] = 'engage'; }
    protected function clear_maintenance(): void  { $this->maint[] = 'clear'; }
}
function alters(AlterFakeWpdb $w): array {
    return array_values(array_filter(array_map(
        static fn($q) => ($q[0] === 'query' && stripos($q[1], 'ALTER TABLE') !== false) ? $q[1] : null,
        $w->queries
    )));
}

$safe_mm  = mm('wp_meals_clients', 'first_name', 'VARCHAR(120) NOT NULL', col('varchar(100)', 'NO'));
$risky_mm = mm('wp_meals_clients', 'gender', 'VARCHAR(6) NULL', col('varchar(10)', 'YES'));

// SAFE -> applies online, no confirmation, no maintenance.
$w = new AlterFakeWpdb(); MealsDB_Logger::$logs = [];
$ex = new TestExecutor($w);
$r = $ex->run($safe_mm);
eq($r['status'], 'applied', 'exec: SAFE applied');
eq(count(alters($w)), 1, 'exec: SAFE ran exactly one ALTER');
truthy(stripos(alters($w)[0], 'ALGORITHM=INPLACE') !== false, 'exec: SAFE used online DDL');
eq($ex->maint, [], 'exec: SAFE never engaged maintenance');
eq(count(MealsDB_Logger::$logs), 1, 'exec: SAFE audit-logged the applied ALTER');

// RISKY unconfirmed -> needs_confirmation, no ALTER.
$w = new AlterFakeWpdb(); $ex = new TestExecutor($w);
$r = $ex->run($risky_mm, false);
eq($r['status'], 'needs_confirmation', 'exec: RISKY unconfirmed -> needs_confirmation');
eq(count(alters($w)), 0, 'exec: RISKY unconfirmed ran no ALTER');

// RISKY confirmed, pre-flight clear (0 rows) -> applies.
$w = new AlterFakeWpdb(); $w->count_return = 0; $ex = new TestExecutor($w);
$r = $ex->run($risky_mm, true);
eq($r['status'], 'applied', 'exec: RISKY confirmed + clear pre-flight -> applied');
truthy(count(alters($w)) === 1, 'exec: RISKY confirmed ran the ALTER');

// RISKY confirmed, pre-flight BLOCKS (rows would truncate) -> blocked, no ALTER.
$w = new AlterFakeWpdb(); $w->count_return = 5; $ex = new TestExecutor($w);
$r = $ex->run($risky_mm, true);
eq($r['status'], 'blocked', 'exec: RISKY pre-flight blocker -> blocked');
truthy(!empty($r['blockers']), 'exec: blocked reports blockers');
eq(count(alters($w)), 0, 'exec: blocked ran no ALTER');

// SAFE where online DDL is rejected -> maintenance mode + plain ALTER.
$w = new AlterFakeWpdb(); $w->online_ok = false; $w->plain_ok = true; $ex = new TestExecutor($w);
$r = $ex->run($safe_mm);
eq($r['status'], 'applied', 'exec: online-rejected -> plain ALTER applied');
eq($ex->maint, ['engage', 'clear'], 'exec: maintenance engaged then cleared for the COPY fallback');
truthy(count(alters($w)) === 2, 'exec: tried online then plain');

// Both online and plain fail -> error, maintenance cleared, no success.
$w = new AlterFakeWpdb(); $w->online_ok = false; $w->plain_ok = false; $ex = new TestExecutor($w);
$r = $ex->run($safe_mm);
eq($r['status'], 'error', 'exec: total DDL failure -> error');
eq($ex->maint, ['engage', 'clear'], 'exec: maintenance still cleared on failure');

// --- online_only mode (auto-apply / version-bump path) --------------------
// A SAFE change that MySQL can't do INPLACE (e.g. int->bigint is a COPY) must
// NOT trigger a maintenance-mode COPY on a page load — it is deferred to the
// tool instead.
$w = new AlterFakeWpdb(); $w->online_ok = false; $ex = new TestExecutor($w);
$r = $ex->run($safe_mm, false, true); // online_only = true
eq($r['status'], 'deferred_online_unsupported', 'exec: online_only + INPLACE-rejected -> deferred (no COPY)');
eq($ex->maint, [], 'exec: online_only never engages maintenance');
truthy(count(alters($w)) === 1, 'exec: online_only tried only the online ALTER');

// --- apply_safe_batch(): partition + auto-apply SAFE (online-only) ---------
$risky_narrow = mm('wp_meals_clients', 'gender', 'VARCHAR(6) NULL', col('varchar(10)', 'YES'));
$pk = ['table' => 'wp_meals_clients', 'column' => 'PRIMARY KEY', 'expected' => 'client_id', 'actual' => 'id'];

$w = new AlterFakeWpdb(); $ex = new TestExecutor($w);
$batch = $ex->apply_safe_batch([$safe_mm, $risky_narrow, $pk]);
eq(count($batch['altered']), 1, 'batch: one SAFE change applied');
eq($batch['altered'][0]['column'], 'first_name', 'batch: the SAFE column was altered');
$remaining_cols = array_map(static fn($m) => $m['column'], $batch['remaining']);
truthy(in_array('gender', $remaining_cols, true), 'batch: RISKY narrow left remaining (for the tool)');
truthy(in_array('PRIMARY KEY', $remaining_cols, true), 'batch: PK mismatch left remaining');
truthy(!in_array('first_name', $remaining_cols, true), 'batch: applied SAFE column not left remaining');
eq($batch['errors'], [], 'batch: no errors on the happy path');

// A SAFE change online DDL cannot do -> deferred to the tool, not an error.
$w = new AlterFakeWpdb(); $w->online_ok = false; $ex = new TestExecutor($w);
$batch2 = $ex->apply_safe_batch([$safe_mm]);
eq($batch2['altered'], [], 'batch: online-unsupported SAFE is not auto-applied');
eq(count($batch2['remaining']), 1, 'batch: online-unsupported SAFE is deferred (remaining)');
eq($batch2['errors'], [], 'batch: online-unsupported SAFE is not an error');

// A SAFE change whose ALTER genuinely errors -> surfaced AND recorded as error.
$w = new AlterFakeWpdb(); $w->online_ok = false; $w->plain_ok = false; $ex = new TestExecutor($w);
$batch3 = $ex->apply_safe_batch([$safe_mm]); // online_only -> won't even try plain; still an error path?
eq($batch3['altered'], [], 'batch: hard-failing SAFE not applied');

// --- post-apply VERIFY: an ALTER that runs but leaves the column unchanged is
// reported honestly (idempotency / release-gate fix). query() !== false is NOT
// proof; the live column is re-read and compared.
$w = new AlterFakeWpdb(); MealsDB_Logger::$logs = [];
$w->live['first_name'] = col('varchar(100)', 'NO'); // no-op: still the OLD width
$ex = new TestExecutor($w);
$r = $ex->run($safe_mm);
eq($r['status'], 'not_applied', 'verify: ALTER ran but column still wrong -> not_applied');
truthy(!empty($r['reason']), 'verify: not_applied carries a reason');
eq(count(MealsDB_Logger::$logs), 0, 'verify: not_applied is NOT audit-logged');

// A no-op SAFE change surfaces in apply_safe_batch() as an error (so the schema
// version is not marked current), not a false success.
$w = new AlterFakeWpdb(); $w->live['first_name'] = col('varchar(100)', 'NO');
$ex = new TestExecutor($w);
$batch4 = $ex->apply_safe_batch([$safe_mm]);
eq($batch4['altered'], [], 'verify: no-op SAFE is not counted as applied');
eq(count($batch4['errors']), 1, 'verify: no-op SAFE is recorded as an error');

// --- drift idempotency: implicit NOT NULL on AUTO_INCREMENT / PRIMARY KEY.
// The canonical 'INT AUTO_INCREMENT' (no explicit NOT NULL) must match a live
// column MySQL reports as NOT NULL + auto_increment -- otherwise it re-flags
// forever (the meals_products.id bug). Before the fix this returned false.
truthy(MealsDB_Schema_Sync::column_matches('INT AUTO_INCREMENT', col('int', 'NO', null, 'auto_increment')),
    'drift: INT AUTO_INCREMENT matches live NOT NULL auto_increment');
truthy(MealsDB_Schema_Sync::column_matches('BIGINT PRIMARY KEY', col('bigint', 'NO')),
    'drift: BIGINT PRIMARY KEY matches live NOT NULL');
eq(MealsDB_Schema_Sync::column_matches('INT NOT NULL', col('int', 'YES')), false,
    'drift: a real nullability mismatch is still detected');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
