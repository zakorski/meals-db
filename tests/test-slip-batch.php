<?php
/**
 * Tests for MealsDB_Slip_Batch (directive 04, Midland packing documents).
 *
 *   T-1  create() → positive id, row persisted, status 'generated', count right
 *   T-2  doc4_payload is ENCRYPTED at rest (ciphertext, not the plaintext PII)
 *   T-3  get() round-trips the ordered doc 4 payloads exactly
 *   T-4  create() rejects a bad delivery date (returns 0)
 *   T-5  list_batches() returns meta only — NO orders/payload — + has_* flags
 *   T-6  store_doc3() moves the file into the protected dir, flips status,
 *        records page count; the dir carries .htaccess + index.html guards
 *   T-7  re-upload replaces the prior doc 3 file (latest wins)
 *   T-8  store_merged() writes bytes, flips status 'combined', returns the path
 *   T-9  cancel() hard-deletes the row AND removes the doc3/merged files
 *
 * Uses the REAL MealsDB_Encryption + MealsDB_Slip_Batch (so the encrypt/decrypt
 * + fail-closed story is exercised), an in-memory $wpdb, and a real temp upload
 * dir (wp_upload_dir stubbed) so the on-disk file lifecycle is real.
 *
 * Run: php tests/test-slip-batch.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

// A real, isolated temp upload root for this run.
$GLOBALS['TEST_UPLOAD_BASE'] = sys_get_temp_dir() . '/mealsdb-slip-test-' . getmypid();

$GLOBALS['TEST_OPTIONS'] = [
    'mealsdb_settings' => ['encryption_key' => 'base64:' . base64_encode(str_repeat('k', 32))],
];

// ---------------------------------------------------------------------------
// Minimal WP function stubs.
// ---------------------------------------------------------------------------
if (!function_exists('get_option')) {
    function get_option($name, $default = false) { return $GLOBALS['TEST_OPTIONS'][$name] ?? $default; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return 7; }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($s) { return rtrim((string) $s, '/\\') . '/'; }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() { return ['basedir' => $GLOBALS['TEST_UPLOAD_BASE']]; }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12, $special = true) { return substr(md5((string) mt_rand()), 0, $len); }
}
if (!function_exists('__')) {
    function __($t, $d = null) { return $t; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!class_exists('wpdb')) { class wpdb {} }

/**
 * In-memory wpdb for the slip_batches table.
 */
class SlipBatchWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public array $rows = [];
    private int $next_id = 1;

    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }

    public function insert($table, $data, $formats = null) {
        if (strpos($table, 'meals_slip_batches') === false) { return false; }
        $id = $this->next_id++;
        $data['batch_id'] = $id;
        $this->rows[$id]  = $data;
        $this->insert_id  = $id;
        return 1;
    }

    public function get_row($q, $o = null) {
        if (stripos($q, 'meals_slip_batches') !== false
            && preg_match('/batch_id = (\d+)/', $q, $m)) {
            return $this->rows[(int) $m[1]] ?? null;
        }
        return null;
    }

    public function get_results($q, $o = null) {
        if (stripos($q, 'meals_slip_batches') === false) { return []; }
        $st = preg_match("/status = '([^']*)'/", $q, $m1) ? $m1[1] : null;
        $zn = preg_match("/zone_name = '([^']*)'/", $q, $m2) ? $m2[1] : null;
        $out = [];
        foreach ($this->rows as $row) {
            if ($st !== null && (string) ($row['status'] ?? '') !== $st) { continue; }
            if ($zn !== null && (string) ($row['zone_name'] ?? '') !== $zn) { continue; }
            unset($row['doc4_payload']);
            $out[] = $row;
        }
        return $out;
    }

    public function update($table, $data, $where, $df = null, $wf = null) {
        if (strpos($table, 'meals_slip_batches') === false) { return false; }
        $id = (int) ($where['batch_id'] ?? 0);
        if (!isset($this->rows[$id])) { return 0; }
        foreach ($data as $k => $v) { $this->rows[$id][$k] = $v; }
        return 1;
    }

    public function delete($table, $where, $wf = null) {
        if (strpos($table, 'meals_slip_batches') === false) { return false; }
        $id = (int) ($where['batch_id'] ?? 0);
        if (!isset($this->rows[$id])) { return 0; }
        unset($this->rows[$id]);
        return 1;
    }
}

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------
$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function chk_true($cond, $label) { chk((bool) $cond, true, $label); }

function reset_env(): SlipBatchWpdb {
    $w = new SlipBatchWpdb();
    $GLOBALS['wpdb'] = $w;
    return $w;
}

/** A realistic positional doc 4 payload set (two orders). */
function sample_orders(): array {
    return [
        ['order_number' => 24738, 'client_name' => 'Magella Landry',
         'street' => '12 Rue Principale', 'city' => 'Dieppe', 'postal' => 'E1A 1A1',
         'phone' => '506-555-0101', 'collect_amount' => 0.0, 'collect_label' => 'Collect: $0.00'],
        ['order_number' => 27120, 'client_name' => 'Chuck Khan',
         'street' => '99 Main St', 'city' => 'Moncton', 'postal' => 'E1C 1C1',
         'phone' => '506-555-0202', 'collect_amount' => 14.66, 'collect_label' => 'Collect: $14.66'],
    ];
}

// Write a throwaway "PDF" file, return its path.
function make_tmp_pdf(string $tag): string {
    $p = sys_get_temp_dir() . '/slip-src-' . $tag . '-' . getmypid() . '.pdf';
    file_put_contents($p, "%PDF-1.4\n% $tag\n");
    return $p;
}

// ===========================================================================
// T-1 / T-2 / T-3 — create, encryption-at-rest, get() round-trip.
// ===========================================================================
$w  = reset_env();
$id = MealsDB_Slip_Batch::create('Moncton Downtown', '2026-06-30', sample_orders());
chk_true($id > 0, 'T-1: create returns positive batch_id');
chk((string) ($w->rows[$id]['status'] ?? ''), 'generated', 'T-1: status is generated');
chk((int) ($w->rows[$id]['order_count'] ?? -1), 2, 'T-1: order_count = 2');

$stored = (string) ($w->rows[$id]['doc4_payload'] ?? '');
chk_true($stored !== '' && strpos($stored, 'Magella Landry') === false,
    'T-2: doc4_payload is encrypted at rest (no plaintext PII)');

$got = MealsDB_Slip_Batch::get($id);
chk_true(is_array($got) && isset($got['orders']), 'T-3: get() returns decoded orders');
// The payload is persisted as encrypted JSON, so the honest expectation is
// equality after the SAME json round-trip the storage layer applies (e.g. a
// float 0.0 returns as int 0 — harmless: doc 4 renders the pre-formatted
// collect_label string, not the raw number).
$json_norm = json_decode(json_encode(sample_orders()), true);
chk($got['orders'] ?? null, $json_norm, 'T-3: orders round-trip through json storage');

// ===========================================================================
// T-4 — bad delivery date rejected.
// ===========================================================================
reset_env();
chk(MealsDB_Slip_Batch::create('Z', 'not-a-date', sample_orders()), 0, 'T-4: bad date → 0');
chk(MealsDB_Slip_Batch::create('', '2026-06-30', sample_orders()), 0, 'T-4: empty zone → 0');

// ===========================================================================
// T-5 — list_batches returns meta only (no PII), with has_* flags.
// ===========================================================================
$w  = reset_env();
$id = MealsDB_Slip_Batch::create('Sussex', '2026-07-02', sample_orders());
$list = MealsDB_Slip_Batch::list_batches();
chk(count($list), 1, 'T-5: one batch listed');
chk_true(!isset($list[0]['orders']) && !isset($list[0]['doc4_payload']),
    'T-5: list carries NO payload/orders');
chk($list[0]['has_doc3'] ?? null, false, 'T-5: has_doc3 false initially');
chk($list[0]['has_merged'] ?? null, false, 'T-5: has_merged false initially');
// Filter by zone.
chk(count(MealsDB_Slip_Batch::list_batches(['zone_name' => 'Nowhere'])), 0, 'T-5: zone filter excludes');

// ===========================================================================
// T-6 — store_doc3 moves the file into a protected dir + flips status.
// ===========================================================================
$src = make_tmp_pdf('a');
chk_true(MealsDB_Slip_Batch::store_doc3($id, $src, 2), 'T-6: store_doc3 returns true');
chk_true(!file_exists($src), 'T-6: source temp file consumed (moved)');
$doc3_path = (string) ($w->rows[$id]['doc3_path'] ?? '');
chk_true($doc3_path !== '' && is_file($doc3_path), 'T-6: doc3 file exists at recorded path');
chk((string) ($w->rows[$id]['status'] ?? ''), 'doc3_uploaded', 'T-6: status → doc3_uploaded');
chk((int) ($w->rows[$id]['doc3_page_count'] ?? -1), 2, 'T-6: page count recorded');
// Protected-dir guards present.
$doc3_dir = dirname($doc3_path);
chk_true(is_file($doc3_dir . '/.htaccess'), 'T-6: .htaccess guard present');
chk_true(is_file($doc3_dir . '/index.html'), 'T-6: index.html guard present');

// ===========================================================================
// T-7 — re-upload replaces the prior doc 3 file.
// ===========================================================================
$old_doc3 = $doc3_path;
$src2 = make_tmp_pdf('b');
chk_true(MealsDB_Slip_Batch::store_doc3($id, $src2, 2), 'T-7: re-upload returns true');
$new_doc3 = (string) ($w->rows[$id]['doc3_path'] ?? '');
chk_true($new_doc3 !== '' && $new_doc3 !== $old_doc3, 'T-7: doc3_path changed');
chk_true(!file_exists($old_doc3), 'T-7: old doc3 file deleted');
chk_true(is_file($new_doc3), 'T-7: new doc3 file present');

// ===========================================================================
// T-8 — store_merged writes bytes, flips status, returns path.
// ===========================================================================
$merged_path = MealsDB_Slip_Batch::store_merged($id, "%PDF-1.4\nmerged\n");
chk_true($merged_path !== '' && is_file($merged_path), 'T-8: merged file written');
chk((string) ($w->rows[$id]['status'] ?? ''), 'combined', 'T-8: status → combined');
chk((string) ($w->rows[$id]['merged_path'] ?? ''), $merged_path, 'T-8: merged_path recorded');

// ===========================================================================
// T-9 — cancel hard-deletes the row and the files.
// ===========================================================================
chk_true(MealsDB_Slip_Batch::cancel($id), 'T-9: cancel returns true');
chk_true(!isset($w->rows[$id]), 'T-9: row removed');
chk_true(!file_exists($new_doc3), 'T-9: doc3 file removed');
chk_true(!file_exists($merged_path), 'T-9: merged file removed');

// ---------------------------------------------------------------------------
// Cleanup the temp upload root.
// ---------------------------------------------------------------------------
$base = $GLOBALS['TEST_UPLOAD_BASE'];
if (is_dir($base)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f) : @unlink($f); }
    @rmdir($base);
}

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
echo "\n=== MealsDB_Slip_Batch ===\n";
if (empty($failures)) {
    echo "PASS — {$passed} checks\n";
    exit(0);
}
echo "FAIL — {$passed} passed, " . count($failures) . " failed:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
