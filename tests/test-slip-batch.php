<?php
/**
 * Tests for MealsDB_Slip_Batch (directive 04, Midland packing documents).
 *
 *   T-1  create() → positive id, row persisted, status 'generated', count right
 *   T-2  doc4_payload is ENCRYPTED at rest (ciphertext, not the plaintext PII)
 *   T-3  get() round-trips the ordered doc 4 payloads exactly
 *   T-4  create() rejects a bad delivery date (returns 0)
 *   T-5  list_batches() returns meta only — NO orders/payload, no has_* flags
 *   T-9  cancel() hard-deletes the row
 *
 * Uses the REAL MealsDB_Encryption + MealsDB_Slip_Batch (so the encrypt/decrypt
 * + fail-closed story is exercised) and an in-memory $wpdb.
 *
 * Run: php tests/test-slip-batch.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

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
// T-5 — list_batches returns meta only (no PII), no has_* flags.
// ===========================================================================
$w  = reset_env();
$id = MealsDB_Slip_Batch::create('Sussex', '2026-07-02', sample_orders());
$list = MealsDB_Slip_Batch::list_batches();
chk(count($list), 1, 'T-5: one batch listed');
chk_true(!isset($list[0]['orders']) && !isset($list[0]['doc4_payload']),
    'T-5: list carries NO payload/orders');
chk_true(!isset($list[0]['has_doc3']) && !isset($list[0]['has_merged']),
    'T-5: list no longer exposes has_doc3/has_merged');
// Filter by zone.
chk(count(MealsDB_Slip_Batch::list_batches(['zone_name' => 'Nowhere'])), 0, 'T-5: zone filter excludes');

// ===========================================================================
// T-9 — cancel hard-deletes the row.
// ===========================================================================
chk_true(MealsDB_Slip_Batch::cancel($id), 'T-9: cancel returns true');
chk_true(!isset($w->rows[$id]), 'T-9: row removed');

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
