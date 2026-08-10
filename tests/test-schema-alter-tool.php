<?php
/**
 * Tests for the RISKY-change tool backend (audit H7, slice 4):
 *   - MealsDB_Schema_Sync::detect_column_mismatches() — scan existing tables for
 *     column drift WITHOUT applying anything (feeds the preview tool).
 *   - MealsDB_Schema_Alter_Executor::preview() — for one mismatch, classify +
 *     plan + run the live pre-flight probes, reporting per-check row counts and
 *     whether the change can currently be applied.
 *
 * Run: php tests/test-schema-alter-tool.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

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
function mm(string $t, string $c, string $exp, array $actual): array {
    return ['table' => $t, 'column' => $c, 'expected' => $exp, 'actual' => $actual];
}

/** Fake wpdb: SHOW TABLES + INFORMATION_SCHEMA columns + a probe COUNT. */
class ToolFakeWpdb {
    public $prefix = 'wp_';
    public $dbhost = 'toolhost';
    public $dbname = 'tooldb';
    public $last_error = '';
    public array $tables = [];
    public array $columns_by_table = [];
    public int $count_return = 0;
    public function get_col($q) { return stripos($q, 'SHOW TABLES') !== false ? $this->tables : []; }
    public function prepare($q, ...$a) {
        if (!empty($a) && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function get_results($q, $o = null) {
        if (stripos($q, 'INFORMATION_SCHEMA.COLUMNS') !== false && preg_match("/TABLE_NAME = '([^']+)'/", $q, $m)) {
            $out = [];
            foreach ($this->columns_by_table[$m[1]] ?? [] as $name => $info) {
                $out[] = [
                    'COLUMN_NAME' => $name, 'COLUMN_TYPE' => $info['column_type'],
                    'IS_NULLABLE' => $info['is_nullable'], 'COLUMN_DEFAULT' => $info['column_default'],
                    'EXTRA' => $info['extra'],
                ];
            }
            return $out;
        }
        return [];
    }
    public function get_var($q) { return $this->count_return; }
}

$w = new ToolFakeWpdb();
$GLOBALS['wpdb'] = $w;
$clients = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

// Only the clients table exists; drift one column (canonical first_name is
// VARCHAR(100) NOT NULL -> present as varchar(80)).
$w->tables = [$clients];
$w->columns_by_table[$clients] = [
    'first_name' => ['column_type' => 'varchar(80)', 'is_nullable' => 'NO', 'column_default' => null, 'extra' => ''],
];

// --- detect_column_mismatches() -------------------------------------------
$found = MealsDB_Schema_Sync::detect_column_mismatches($w);
eq(count($found), 1, 'detect: exactly one column mismatch');
eq($found[0]['column'], 'first_name', 'detect: the drifted column');
eq($found[0]['table'], $clients, 'detect: prefixed table name');
truthy(is_array($found[0]['actual']), 'detect: actual is the info-schema row');
truthy(is_string($found[0]['expected']) && $found[0]['expected'] !== '', 'detect: expected is a definition string');

// --- executor preview() ---------------------------------------------------
$ex = new MealsDB_Schema_Alter_Executor($w);

// SAFE widen: no probes, applyable.
$w->count_return = 0;
$pv = $ex->preview(mm($clients, 'first_name', 'VARCHAR(120) NOT NULL', col('varchar(100)', 'NO')));
eq($pv['tier'], 'safe', 'preview: widen is safe');
eq($pv['can_apply'], true, 'preview: safe can_apply');
eq($pv['preflight'], [], 'preview: safe has no probes');
eq($pv['alter_sql'], "ALTER TABLE `{$clients}` MODIFY COLUMN `first_name` VARCHAR(120) NOT NULL", 'preview: plain ALTER SQL');

// RISKY narrow, probe finds 0 rows -> applyable.
$w->count_return = 0;
$pv2 = $ex->preview(mm($clients, 'gender', 'VARCHAR(6) NULL', col('varchar(10)', 'YES')));
eq($pv2['tier'], 'risky', 'preview: narrow is risky');
eq($pv2['can_apply'], true, 'preview: risky w/ clear probe can apply');
eq($pv2['preflight'][0]['count'], 0, 'preview: probe count 0');
eq($pv2['preflight'][0]['blocks'], false, 'preview: probe not blocking');

// RISKY narrow, probe finds rows -> BLOCKED.
$w->count_return = 4;
$pv3 = $ex->preview(mm($clients, 'gender', 'VARCHAR(6) NULL', col('varchar(10)', 'YES')));
eq($pv3['can_apply'], false, 'preview: probe finds rows -> cannot apply');
eq($pv3['preflight'][0]['count'], 4, 'preview: probe count 4');
eq($pv3['preflight'][0]['blocks'], true, 'preview: probe blocking');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
