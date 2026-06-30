<?php
/**
 * Tests for MealsDB_Ajax_Slip_Batch (directive 05) — the dompdf-free
 * JSON mutation paths. Streaming PDF downloads are live-only;
 * here we pin the guard stack and the mutation handlers' validation/audit:
 *
 *   G-1  guard: nonce fail / cap fail / rate-limit fail → error, no work
 *   GB-1 generate_batch: unknown zone / bad date → error
 *   GB-2 generate_batch: zero orders → error (no batch created)
 *   GB-3 generate_batch: happy path → batch persisted, audit row, success
 *   CN-1 cancel: happy path → row gone + audit; missing → error
 *   LS-1 list → returns batch rows
 *
 * Stubs the generator; uses the REAL MealsDB_Slip_Batch + Encryption
 * with an in-memory $wpdb and a real temp upload dir.
 *
 * Run: php tests/test-ajax-slip-batch.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

$GLOBALS['NONCE_OK'] = true;
$GLOBALS['CAP_OK']   = true;
$GLOBALS['RL_OK']    = true;
$GLOBALS['JSON']     = null;
$GLOBALS['AUDIT']    = [];
$GLOBALS['GEN_DATA'] = ['order_count' => 2, 'doc4_orders' => [
    ['order_number' => '#1', 'initials' => 'AAA', 'take_from_hold' => false, 'client_name' => 'A'],
    ['order_number' => '#2', 'initials' => 'BBB', 'take_from_hold' => true,  'client_name' => 'B'],
]];
$GLOBALS['TEST_UPLOAD_BASE'] = sys_get_temp_dir() . '/mealsdb-ajaxslip-' . getmypid();
$GLOBALS['TEST_OPTIONS'] = [
    'mealsdb_settings' => ['encryption_key' => 'base64:' . base64_encode(str_repeat('k', 32))],
    'mealsdb_zone_delivery_schedule' => ['Moncton Downtown' => ['day' => 'Wednesday']],
];

// ---- WP + plugin stubs --------------------------------------------------
if (!function_exists('get_option')) {
    function get_option($n, $d = false) { return $GLOBALS['TEST_OPTIONS'][$n] ?? $d; }
}
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $o = 0, $p = 512) { return json_encode($d, $o, $p); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id(): int { return 7; } }
if (!function_exists('trailingslashit')) { function trailingslashit($s) { return rtrim((string) $s, '/\\') . '/'; } }
if (!function_exists('wp_mkdir_p')) { function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); } }
if (!function_exists('wp_upload_dir')) { function wp_upload_dir() { return ['basedir' => $GLOBALS['TEST_UPLOAD_BASE']]; } }
if (!function_exists('wp_generate_password')) { function wp_generate_password($l = 12, $s = true) { return substr(md5((string) mt_rand()), 0, $l); } }
if (!function_exists('__')) { function __($t, $d = null) { return $t; } }
if (!function_exists('esc_html__')) { function esc_html__($t, $d = null) { return $t; } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!function_exists('absint')) { function absint($v) { return abs((int) $v); } }
if (!function_exists('check_ajax_referer')) { function check_ajax_referer($a, $n = false, $d = true) { return (bool) $GLOBALS['NONCE_OK']; } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return (bool) $GLOBALS['CAP_OK']; } }
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($d = null, $s = null) { $GLOBALS['JSON'] = ['type' => 'success', 'data' => $d, 'status' => $s]; }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($d = null, $s = null) { $GLOBALS['JSON'] = ['type' => 'error', 'data' => $d, 'status' => $s]; }
}
if (!function_exists('add_action')) { function add_action() { return true; } }
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
}
if (!function_exists('admin_url')) { function admin_url($p = '') { return 'http://t/wp-admin/' . $p; } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($a = '') { return 'nonce'; } }

// Stub collaborators BEFORE the autoloader so the real ones don't load.
class MealsDB_Rate_Limiter {
    public static function check_rate_limit(string $b, ?int $u = null): bool { return (bool) $GLOBALS['RL_OK']; }
}
class MealsDB_Logger {
    public static function log($action, $id, $field, $old, $new, $src = 'mealsdb') {
        $GLOBALS['AUDIT'][] = compact('action', 'id', 'field', 'old', 'new');
    }
    public static function error($m) {}
}
// Generator + collaborators (make_pdf_generator news these).
class MealsDB_WC_Order_Query { public function __construct($wpdb = null) {} }
class MealsDB_Delivery_Slip_Generator { public function __construct($q = null) {} }
class MealsDB_Collection_Calculator {}
class MealsDB_Slip_PDF_Generator {
    public function __construct($q = null, $c = null) {}
    public function build_batch_data(array $zones, string $s, string $e): array { return $GLOBALS['GEN_DATA']; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!class_exists('wpdb')) { class wpdb {} }

class AjaxSlipWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
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
    public function insert($t, $d, $f = null) {
        if (strpos($t, 'meals_slip_batches') === false) { return 1; }
        $id = $this->next_id++; $d['batch_id'] = $id; $this->rows[$id] = $d; $this->insert_id = $id; return 1;
    }
    public function get_row($q, $o = null) {
        if (stripos($q, 'meals_slip_batches') !== false && preg_match('/batch_id = (\d+)/', $q, $m)) {
            return $this->rows[(int) $m[1]] ?? null;
        }
        return null;
    }
    public function get_results($q, $o = null) {
        if (stripos($q, 'meals_slip_batches') === false) { return []; }
        $out = [];
        foreach ($this->rows as $r) { unset($r['doc4_payload']); $out[] = $r; }
        return $out;
    }
    public function update($t, $d, $w, $df = null, $wf = null) {
        if (strpos($t, 'meals_slip_batches') === false) { return false; }
        $id = (int) ($w['batch_id'] ?? 0);
        if (!isset($this->rows[$id])) { return 0; }
        foreach ($d as $k => $v) { $this->rows[$id][$k] = $v; }
        return 1;
    }
    public function delete($t, $w, $wf = null) {
        if (strpos($t, 'meals_slip_batches') === false) { return false; }
        $id = (int) ($w['batch_id'] ?? 0);
        if (!isset($this->rows[$id])) { return 0; }
        unset($this->rows[$id]); return 1;
    }
}

// ---- harness ------------------------------------------------------------
$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; } else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function chk_true($c, $l) { chk((bool) $c, true, $l); }
function jtype() { return $GLOBALS['JSON']['type'] ?? null; }
function jdata() { return $GLOBALS['JSON']['data'] ?? null; }
function reset_env(): AjaxSlipWpdb {
    $GLOBALS['NONCE_OK'] = true; $GLOBALS['CAP_OK'] = true; $GLOBALS['RL_OK'] = true;
    $GLOBALS['JSON'] = null; $GLOBALS['AUDIT'] = []; $_POST = []; $_FILES = []; $_REQUEST = [];
    $w = new AjaxSlipWpdb(); $GLOBALS['wpdb'] = $w; return $w;
}

// ===========================================================================
// G-1 — guard.
// ===========================================================================
reset_env(); $GLOBALS['NONCE_OK'] = false;
$_POST = ['zone' => 'Moncton Downtown', 'delivery_date' => '2026-06-30'];
MealsDB_Ajax_Slip_Batch::generate_batch();
chk(jtype(), 'error', 'G-1 nonce fail → error');

reset_env(); $GLOBALS['CAP_OK'] = false;
MealsDB_Ajax_Slip_Batch::generate_batch();
chk(jtype(), 'error', 'G-1 cap fail → error');

reset_env(); $GLOBALS['RL_OK'] = false;
MealsDB_Ajax_Slip_Batch::generate_batch();
chk(jtype(), 'error', 'G-1 rate-limit fail → error');
chk($GLOBALS['JSON']['status'] ?? null, 429, 'G-1 rate limit → 429');

// ===========================================================================
// GB-1 — bad zone / bad date.
// ===========================================================================
reset_env();
$_POST = ['zone' => 'Atlantis', 'delivery_date' => '2026-06-30'];
MealsDB_Ajax_Slip_Batch::generate_batch();
chk(jtype(), 'error', 'GB-1 unknown zone → error');

reset_env();
$_POST = ['zone' => 'Moncton Downtown', 'delivery_date' => 'June 30'];
MealsDB_Ajax_Slip_Batch::generate_batch();
chk(jtype(), 'error', 'GB-1 bad date → error');

// ===========================================================================
// GB-2 — zero orders.
// ===========================================================================
$w = reset_env();
$GLOBALS['GEN_DATA'] = ['order_count' => 0, 'doc4_orders' => []];
$_POST = ['zone' => 'Moncton Downtown', 'delivery_date' => '2026-06-30'];
MealsDB_Ajax_Slip_Batch::generate_batch();
chk(jtype(), 'error', 'GB-2 zero orders → error');
chk(count($w->rows), 0, 'GB-2 no batch row created');

// ===========================================================================
// GB-3 — happy path.
// ===========================================================================
$w = reset_env();
$GLOBALS['GEN_DATA'] = ['order_count' => 2, 'doc4_orders' => [
    ['order_number' => '#1', 'initials' => 'AAA', 'take_from_hold' => false],
    ['order_number' => '#2', 'initials' => 'BBB', 'take_from_hold' => true],
]];
$_POST = ['zone' => 'Moncton Downtown', 'delivery_date' => '2026-06-30'];
MealsDB_Ajax_Slip_Batch::generate_batch();
chk(jtype(), 'success', 'GB-3 success');
chk(jdata()['order_count'] ?? null, 2, 'GB-3 order_count returned');
chk(count($w->rows), 1, 'GB-3 batch persisted');
$batch_id = jdata()['batch_id'] ?? 0;
chk_true($batch_id > 0, 'GB-3 batch_id returned');
chk_true(count($GLOBALS['AUDIT']) === 1 && $GLOBALS['AUDIT'][0]['action'] === 'slip_batch_generated', 'GB-3 audit row written');

// ===========================================================================
// CN-1 — cancel.
// ===========================================================================
$w = reset_env();
$bid = MealsDB_Slip_Batch::create('Moncton Downtown', '2026-06-30', [['order_number' => '#1']]);
$_POST = ['batch_id' => $bid];
MealsDB_Ajax_Slip_Batch::cancel();
chk(jtype(), 'success', 'CN-1 cancel success');
chk(count($w->rows), 0, 'CN-1 row removed');
chk_true(in_array('slip_batch_cancelled', array_column($GLOBALS['AUDIT'], 'action'), true), 'CN-1 audit written');

reset_env();
$_POST = ['batch_id' => 4242];
MealsDB_Ajax_Slip_Batch::cancel();
chk(jtype(), 'error', 'CN-1 cancel missing → error');

// ===========================================================================
// LS-1 — list.
// ===========================================================================
$w = reset_env();
MealsDB_Slip_Batch::create('Moncton Downtown', '2026-06-30', [['order_number' => '#1']]);
MealsDB_Ajax_Slip_Batch::list_batches();
chk(jtype(), 'success', 'LS-1 list success');
chk(count(jdata()['batches'] ?? []), 1, 'LS-1 one batch listed');

// ===========================================================================
// DL-1 — download_url builds the combined-download action (underscore kept).
// ===========================================================================
reset_env();
$url = MealsDB_Ajax_Slip_Batch::download_url(5, 'packing_slips');
chk_true(strpos($url, 'action=mealsdb_slip_download_packing_slips') !== false, 'DL-1 packing_slips action name');
$url4 = MealsDB_Ajax_Slip_Batch::download_url(5, 'doc4');
chk_true(strpos($url4, 'action=mealsdb_slip_download_doc4') !== false, 'DL-1 doc4 action unchanged');

// ---- cleanup temp upload root ----
$base = $GLOBALS['TEST_UPLOAD_BASE'];
if (is_dir($base)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f) : @unlink($f); }
    @rmdir($base);
}

echo "\n=== MealsDB_Ajax_Slip_Batch ===\n";
if (empty($failures)) { echo "PASS — {$passed} checks\n"; exit(0); }
echo "FAIL — {$passed} passed, " . count($failures) . " failed:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
