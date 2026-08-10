<?php
/**
 * Tests for MealsDB_Schema_Alter_Planner::classify() — the pure risk classifier
 * that turns a Schema_Sync column mismatch (expected canonical definition vs the
 * actual INFORMATION_SCHEMA row) into a risk tier (audit H7, scope slice 1).
 *
 *   SAFE  — value-preserving; may auto-apply on a version bump (widen varchar/
 *           text, int -> bigint, add ENUM value, relax NOT NULL, change DEFAULT).
 *   RISKY — could lose data or fail; must go through the preview+confirm tool
 *           (narrow, remove ENUM value, tighten to NOT NULL, sign/type change,
 *           and — per operator decision 4 — ANY DECIMAL/money change).
 *
 * A NULL -> NOT NULL tightening is RISKY with needs_null_check=true so the
 * executor knows to run the "0 NULL rows" pre-flight before applying.
 *
 * Pure function, no DB. Run: php tests/test-schema-alter-planner.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function eq($actual, $expected, string $label): void {
    global $failures, $passed;
    if ($actual === $expected) { $passed++; return; }
    $failures[] = sprintf('%s: expected %s got %s', $label, var_export($expected, true), var_export($actual, true));
}
/** Build an INFORMATION_SCHEMA-shaped actual-column row. */
function col(string $type, string $nullable = 'NO', $default = null, string $extra = ''): array {
    return ['column_type' => $type, 'is_nullable' => $nullable, 'column_default' => $default, 'extra' => $extra];
}
function tier(string $expected_def, array $actual): string {
    return MealsDB_Schema_Alter_Planner::classify($expected_def, $actual)['tier'];
}

// --- SAFE: widening / value-preserving ------------------------------------
eq(tier("VARCHAR(80) NOT NULL DEFAULT ''", col('varchar(40)', 'NO', '')), 'safe', 'varchar widen 40->80');
eq(tier("VARCHAR(40) NULL", col('varchar(40)', 'NO')), 'safe', 'relax NOT NULL -> NULL');
eq(tier("BIGINT UNSIGNED NOT NULL", col('int unsigned', 'NO')), 'safe', 'int -> bigint widen');
eq(tier("ENUM('a','b','c') NOT NULL", col("enum('a','b')", 'NO')), 'safe', 'add ENUM value (superset)');
eq(tier("INT NOT NULL DEFAULT 5", col('int', 'NO', '0')), 'safe', 'default change only');
eq(tier("MEDIUMTEXT NULL", col('text', 'YES')), 'safe', 'text widen text->mediumtext');
eq(tier("BOOLEAN NOT NULL DEFAULT 0", col('tinyint(1)', 'NO', '0')), 'safe', 'BOOLEAN vs tinyint(1) is not a real change');
eq(tier("VARCHAR(80) NOT NULL DEFAULT 'x'", col('varchar(40)', 'NO', '')), 'safe', 'widen + default change both safe');

// --- RISKY: narrowing / lossy / structural --------------------------------
eq(tier("VARCHAR(40) NOT NULL", col('varchar(80)', 'NO')), 'risky', 'varchar narrow 80->40');
eq(tier("INT NOT NULL", col('bigint', 'NO')), 'risky', 'bigint -> int narrow');
eq(tier("ENUM('a','b') NOT NULL", col("enum('a','b','c')", 'NO')), 'risky', 'remove ENUM value');
eq(tier("TEXT NULL", col('mediumtext', 'YES')), 'risky', 'text narrow mediumtext->text');
eq(tier("VARCHAR(40) NOT NULL", col('int', 'NO')), 'risky', 'type family change int->varchar');
eq(tier("INT NOT NULL", col('int unsigned', 'NO')), 'risky', 'sign change unsigned->signed');

// --- RISKY: money DECIMAL always manual (decision 4) ----------------------
eq(tier("DECIMAL(12,2) NOT NULL DEFAULT 0.00", col('decimal(10,2)', 'NO', '0.00')), 'risky', 'decimal widen is still RISKY (money manual)');
eq(tier("DECIMAL(10,2) NULL", col('decimal(10,4)', 'YES')), 'risky', 'decimal scale change RISKY');

// --- RISKY-conditional: tighten to NOT NULL needs a null-count pre-flight --
$r = MealsDB_Schema_Alter_Planner::classify("VARCHAR(40) NOT NULL", col('varchar(40)', 'YES'));
eq($r['tier'], 'risky', 'tighten NULL->NOT NULL is risky');
eq($r['needs_null_check'], true, 'tighten NULL->NOT NULL flags a null pre-flight');

// A tightening combined with a widen is still RISKY (worst tier wins).
$r2 = MealsDB_Schema_Alter_Planner::classify("VARCHAR(80) NOT NULL", col('varchar(40)', 'YES'));
eq($r2['tier'], 'risky', 'widen + tighten -> risky (tighten dominates)');
eq($r2['needs_null_check'], true, 'widen + tighten still flags null pre-flight');

// SAFE changes never request a null pre-flight.
eq(MealsDB_Schema_Alter_Planner::classify("VARCHAR(80) NOT NULL DEFAULT ''", col('varchar(40)', 'NO', ''))['needs_null_check'], false, 'safe change: no null pre-flight');

// Every result carries a non-empty human reason.
$reason = MealsDB_Schema_Alter_Planner::classify("VARCHAR(40) NOT NULL", col('varchar(80)', 'NO'))['reason'];
eq(is_string($reason) && $reason !== '', true, 'classification carries a reason');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
