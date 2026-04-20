<?php
/**
 * Sanity tests for MealsDB_Migration size-limit constants (J3).
 *
 * Guards the numeric ceilings we rely on when reading third-party SQL
 * dumps — a refactor that silently bumps LOAD_FGETS_BYTES back to
 * 32 MB or strips MAX_STATEMENT_BYTES should be caught by CI.
 *
 * Run with: php tests/test-migration-constants.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

$failures = [];
$passed   = 0;
function assert_true($value, string $label) {
    global $failures, $passed;
    if ((bool) $value) { $passed++; return; }
    $failures[] = "FAIL: $label (expected true, got " . var_export($value, true) . ')';
}

// LOAD_CHUNK_BYTES stays at 10 MB (existing contract).
assert_true(
    MealsDB_Migration::LOAD_CHUNK_BYTES === 10 * 1024 * 1024,
    'LOAD_CHUNK_BYTES is 10 MB (AJAX chunk window)'
);

// LOAD_FGETS_BYTES <= LOAD_CHUNK_BYTES so one fgets() call cannot
// exceed a full chunk, and <= 8 MB so an adversarial dump can't
// allocate 32 MB per fgets() the way the original implementation could.
assert_true(
    MealsDB_Migration::LOAD_FGETS_BYTES > 0,
    'LOAD_FGETS_BYTES is positive'
);
assert_true(
    MealsDB_Migration::LOAD_FGETS_BYTES <= MealsDB_Migration::LOAD_CHUNK_BYTES,
    'LOAD_FGETS_BYTES does not exceed LOAD_CHUNK_BYTES'
);
assert_true(
    MealsDB_Migration::LOAD_FGETS_BYTES <= 8 * 1024 * 1024,
    'LOAD_FGETS_BYTES is capped at 8 MB (down from the original 32 MB)'
);

// MAX_STATEMENT_BYTES generous enough for real mysqldump output but
// low enough to catch runaway / adversarial dumps.
assert_true(
    MealsDB_Migration::MAX_STATEMENT_BYTES >= MealsDB_Migration::LOAD_FGETS_BYTES,
    'MAX_STATEMENT_BYTES accommodates at least one full fgets buffer'
);
assert_true(
    MealsDB_Migration::MAX_STATEMENT_BYTES <= 64 * 1024 * 1024,
    'MAX_STATEMENT_BYTES does not exceed 64 MB (practical MySQL ceiling)'
);

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
