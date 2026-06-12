<?php
/**
 * SEC follow-up — reencrypt_legacy() must reach EVERY legacy row, not just the
 * first batch.
 *
 * The old query was `... WHERE col IS NOT NULL AND col <> '' ORDER BY client_id
 * ASC LIMIT n` with no cursor, called once per column. Converted rows still
 * match that WHERE (they're non-empty ciphertext), so repeated calls re-read
 * the same first n rows and anything past position n is unreachable. The fix
 * paginates by keyset (client_id > cursor) so a single call drains the column.
 *
 * Run: php tests/test-reencrypt-legacy-cursor.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

// A legacy-shaped value: base64 that decodes to 17–48 bytes → classify 'legacy'.
$LEGACY = base64_encode(str_repeat('x', 24));

/**
 * Mock wpdb that serves keyset-paginated reads of one column's legacy rows
 * (client_id 1..N). get_results parses `client_id > X` and `LIMIT Y` out of the
 * prepared SQL and returns the next slice — so a method that does NOT advance
 * the cursor would loop forever / re-read, and one that does drains the set.
 */
class ReencFakeWpdb extends wpdb {
    public $prefix = 'wp_';
    public int $max_id;
    public string $legacy;
    public string $target_col;
    public function __construct(int $max_id, string $legacy, string $target_col) {
        $this->max_id = $max_id; $this->legacy = $legacy; $this->target_col = $target_col;
    }
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function get_results($q, $o = null) {
        // Only the target column has legacy rows; other columns are empty.
        if (strpos($q, "`{$this->target_col}`") === false) { return []; }
        $after = 0; $limit = 100;
        if (preg_match('/client_id > (\d+)/', $q, $m)) { $after = (int) $m[1]; }
        if (preg_match('/LIMIT (\d+)/', $q, $m))       { $limit = (int) $m[1]; }
        $rows = [];
        for ($id = $after + 1; $id <= $this->max_id && count($rows) < $limit; $id++) {
            $rows[] = ['client_id' => $id, 'v' => $this->legacy];
        }
        return $rows;
    }
    public function query($q) { return 1; }
    public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
    public function get_var($q) { return null; }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}

// 5 legacy rows in one column, batch size 2 (dry run → no encryption/key needed).
$GLOBALS['wpdb'] = new ReencFakeWpdb(5, $LEGACY, 'diet_concerns');
$stats = MealsDB_Encryption_Migrator::reencrypt_legacy(2, true);

chk((int) $stats['reencrypted'], 5, '[CURSOR] all 5 legacy rows are reached (not just the first batch of 2)');
chk((int) ($stats['columns']['diet_concerns'] ?? 0), 5, '[CURSOR] the column count reflects all 5 rows');
chk((bool) $stats['aborted'], false, '[CURSOR] run completes without aborting');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
