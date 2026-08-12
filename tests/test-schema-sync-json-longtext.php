<?php
/**
 * Tests for MealsDB_Schema_Sync JSON≡LONGTEXT equivalence on MariaDB
 * (directive DIRECTIVE-json-longtext-mariadb-equivalence, Solution B).
 *
 * MariaDB stores the `JSON` type as an ALIAS for `LONGTEXT` (+ a CHECK
 * constraint), so INFORMATION_SCHEMA.COLUMN_TYPE always reports `longtext`
 * for a canonical `JSON` column. Without folding the two to one token, the
 * 11 canonical JSON columns are flagged as drifted FOREVER on MariaDB.
 *
 * column_matches() is the public wrapper over the private comparator.
 * Pure function, no DB. Run: php tests/test-schema-sync-json-longtext.php
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
function matches(string $expected_def, array $actual): bool {
    return MealsDB_Schema_Sync::column_matches($expected_def, $actual);
}

// --- MariaDB case: canonical JSON backed by a longtext storage column ------
// These are the 11 perpetual false-positives the directive fixes.
eq(matches('JSON NULL', col('longtext', 'YES')), true,
    'canonical JSON NULL matches MariaDB longtext (nullable)');
eq(matches('JSON NOT NULL', col('longtext', 'NO')), true,
    'canonical JSON NOT NULL matches MariaDB longtext (not-null)');

// --- MySQL 8 case: canonical JSON backed by a real json column -------------
eq(matches('JSON NULL', col('json', 'YES')), true,
    'canonical JSON NULL matches real MySQL json column');

// --- The equivalence is json<->longtext ONLY, not json<->anything ----------
eq(matches('JSON NULL', col('varchar(255)', 'YES')), false,
    'canonical JSON does NOT match varchar(255) — equivalence is scoped');
eq(matches('JSON NULL', col('text', 'YES')), false,
    'canonical JSON does NOT match text — only longtext is equivalent');

// --- Nullability is still compared, not swallowed by the type fold ---------
eq(matches('JSON NOT NULL', col('longtext', 'YES')), false,
    'JSON NOT NULL vs nullable longtext still drifts on nullability');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
