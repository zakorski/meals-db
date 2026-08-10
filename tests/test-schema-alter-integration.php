<?php
/**
 * INTEGRATION test — the Schema_Sync ALTER path against a REAL MySQL 8
 * (audit H7, slice 5). Unlike the unit tests (fake wpdb), this issues real DDL
 * on a scratch table and verifies the outcome + the pre-flight guard.
 *
 * It REQUIRES a live WordPress + MySQL $wpdb, so it SKIPS cleanly in the
 * standalone CLI runner (a no-op there, like the dompdf/WC tests). Run it on
 * staging inside a WP context, e.g.:
 *
 *   wp eval-file wp-content/plugins/meals-db/tests/test-schema-alter-integration.php
 *
 * It creates a dedicated scratch table ({prefix}mealsdb_alter_it) and drops it
 * afterward. Note: a narrowing/ENUM change is a COPY under MySQL, so the
 * executor briefly engages WP maintenance mode during those steps — expected.
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

function itskip(string $why): void {
    echo "  [skip] schema-alter integration: {$why}\n";
    echo "Ran 0 checks: 0 passed, 0 failed (skipped)\n";
    exit(0);
}

$wpdb = $GLOBALS['wpdb'] ?? null;
if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
    itskip('needs a live WordPress + MySQL $wpdb (run via `wp eval-file`)');
}
$probe = null;
try { $probe = (int) $wpdb->get_var('SELECT 1'); } catch (\Throwable $e) { $probe = null; }
if ($probe !== 1) {
    itskip('$wpdb cannot reach MySQL');
}

$failures = []; $passed = 0;
function chk($cond, string $l): void {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = $l; }
}
function col_type($wpdb, string $t, string $c): string {
    return strtolower((string) $wpdb->get_var($wpdb->prepare(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
        $t, $c
    )));
}
function actual_row($wpdb, string $t, string $c): array {
    $r = $wpdb->get_row($wpdb->prepare(
        "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
        $t, $c
    ), ARRAY_A);
    return [
        'column_type'    => $r['COLUMN_TYPE'] ?? '',
        'is_nullable'    => $r['IS_NULLABLE'] ?? '',
        'column_default' => $r['COLUMN_DEFAULT'] ?? null,
        'extra'          => $r['EXTRA'] ?? '',
    ];
}

$t  = $wpdb->prefix . 'mealsdb_alter_it';
$ex = new MealsDB_Schema_Alter_Executor($wpdb);

try {
    $wpdb->query("DROP TABLE IF EXISTS `{$t}`");
    $wpdb->query("CREATE TABLE `{$t}` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(10) NOT NULL DEFAULT '', kind ENUM('a','b') NOT NULL DEFAULT 'a') ENGINE=InnoDB");

    // 1. SAFE widen VARCHAR(10) -> VARCHAR(20): auto-applies (online, no confirm).
    $mm = ['table' => $t, 'column' => 'name', 'expected' => "VARCHAR(20) NOT NULL DEFAULT ''", 'actual' => actual_row($wpdb, $t, 'name')];
    $r  = $ex->run($mm);
    chk(($r['status'] ?? '') === 'applied', 'SAFE widen applied');
    chk(strpos(col_type($wpdb, $t, 'name'), 'varchar(20)') !== false, 'name is now varchar(20)');

    // idempotent no-op re-run.
    $r_id = $ex->run(['table' => $t, 'column' => 'name', 'expected' => "VARCHAR(20) NOT NULL DEFAULT ''", 'actual' => actual_row($wpdb, $t, 'name')]);
    chk(($r_id['status'] ?? '') === 'applied', 'idempotent re-apply is a harmless no-op');

    // 2. RISKY narrow with an over-length row -> pre-flight BLOCKS.
    $wpdb->query("INSERT INTO `{$t}` (name) VALUES ('twelve_chars')"); // 12 > 5
    $mm2 = ['table' => $t, 'column' => 'name', 'expected' => "VARCHAR(5) NOT NULL DEFAULT ''", 'actual' => actual_row($wpdb, $t, 'name')];
    $pv  = $ex->preview($mm2);
    chk(($pv['tier'] ?? '') === 'risky', 'narrow classified risky');
    chk(($pv['can_apply'] ?? true) === false, 'preview: cannot apply (over-length row present)');
    $r2 = $ex->run($mm2, true); // confirmed, but pre-flight must still block
    chk(($r2['status'] ?? '') === 'blocked', 'RISKY narrow BLOCKED by pre-flight');
    chk(strpos(col_type($wpdb, $t, 'name'), 'varchar(20)') !== false, 'name unchanged after a blocked narrow');

    // 3. Clean the data -> the same RISKY narrow now applies.
    $wpdb->query("DELETE FROM `{$t}` WHERE CHAR_LENGTH(name) > 5");
    $r3 = $ex->run(['table' => $t, 'column' => 'name', 'expected' => "VARCHAR(5) NOT NULL DEFAULT ''", 'actual' => actual_row($wpdb, $t, 'name')], true);
    chk(($r3['status'] ?? '') === 'applied', 'RISKY narrow applies once the data is clean');
    chk(strpos(col_type($wpdb, $t, 'name'), 'varchar(5)') !== false, 'name is now varchar(5)');

    // 4. SAFE add ENUM value 'c'.
    $mm4 = ['table' => $t, 'column' => 'kind', 'expected' => "ENUM('a','b','c') NOT NULL DEFAULT 'a'", 'actual' => actual_row($wpdb, $t, 'kind')];
    $r4  = $ex->run($mm4);
    chk(($r4['status'] ?? '') === 'applied', 'SAFE add-enum-value applied');
    chk(strpos(col_type($wpdb, $t, 'kind'), "'c'") !== false, "kind now includes 'c'");

    // 5. RISKY remove ENUM value held by a row -> BLOCKED.
    $wpdb->query("INSERT INTO `{$t}` (name, kind) VALUES ('x', 'c')");
    $mm5 = ['table' => $t, 'column' => 'kind', 'expected' => "ENUM('a','b') NOT NULL DEFAULT 'a'", 'actual' => actual_row($wpdb, $t, 'kind')];
    $pv5 = $ex->preview($mm5);
    chk(($pv5['can_apply'] ?? true) === false, 'preview: cannot remove an ENUM value a row still holds');
    $r5 = $ex->run($mm5, true);
    chk(($r5['status'] ?? '') === 'blocked', 'RISKY remove-enum BLOCKED by pre-flight');
} finally {
    $wpdb->query("DROP TABLE IF EXISTS `{$t}`");
    // Belt-and-braces: never leave staging wedged in maintenance mode.
    if (defined('ABSPATH') && file_exists(ABSPATH . '.maintenance')) {
        @unlink(ABSPATH . '.maintenance');
    }
}

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
