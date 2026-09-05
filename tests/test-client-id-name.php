<?php
/**
 * DIRECTIVE 6 (ITEM 3) — MealsDB_Clients::format_id_with_name().
 *
 * "#352 (Patricia LeBlanc)" for a known client; the bare "#352" when the name
 * is blank (never "#352 ()").
 *
 * Run: php tests/test-client-id-name.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// Stub $wpdb: get_row returns the name row for the requested client id.
class MealsDB_Test_IdName_Wpdb {
    public $prefix = 'wp_';
    public $names = [];
    public function prepare($sql, ...$args) {
        // Last numeric arg is the client id.
        $this->last_id = (int) end($args);
        return $sql;
    }
    public $last_id = 0;
    public function get_row($sql, $output = null) {
        return $this->names[$this->last_id] ?? null;
    }
}
$wpdb = new MealsDB_Test_IdName_Wpdb();
$wpdb->names = [
    352 => ['first_name' => 'Patricia', 'last_name' => 'LeBlanc'],
    988 => ['first_name' => '',          'last_name' => ''],          // blank name
];
$GLOBALS['wpdb'] = $wpdb;

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}

chk(MealsDB_Clients::format_id_with_name(352), '#352 (Patricia LeBlanc)', 'named client → "#352 (Patricia LeBlanc)"');
chk(MealsDB_Clients::format_id_with_name(988), '#988', 'blank name → bare "#988" (not "#988 ()")');
chk(MealsDB_Clients::format_id_with_name(777), '#777', 'unknown client → bare id');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
