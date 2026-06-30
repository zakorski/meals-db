<?php
/**
 * Tests for MealsDB_Ajax_Invoice_Draft (directive INV-DRAFT-2).
 *
 * Covers T-1 … T-9 from the directive:
 *   T-1 generate_draft happy path → right builder → draft_id returned
 *   T-2 generate_draft validation → unknown pipeline / bad date / bad zone
 *   T-3 edit_draft_field happy path → edit applied → exactly ONE audit row
 *   T-4 edit_draft_field no-op → applied but NO audit row
 *   T-5 edit_draft_field PII path → name/ID edit is fingerprinted in the audit
 *   T-6 edit validation rejects bad money → JSON error, no edit, no audit
 *   T-7 edit refused on finalized draft → JSON error, no audit
 *   T-8 finalize_draft → success; re-finalize → error
 *   T-9 capability / nonce → rejected before any service call
 *
 * Run: php tests/test-ajax-invoice-draft.php
 *
 * Uses an in-memory $wpdb (no real DB) that ALSO captures audit-log inserts,
 * the REAL MealsDB_Invoice_Draft / Encryption / Logger (so the audit + PII
 * redaction story is exercised end-to-end), and stubbed
 * Permissions/Rate_Limiter/Generator + WP ajax helpers.
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

// ---------------------------------------------------------------------------
// Controllable guard flags + recorded JSON response.
// ---------------------------------------------------------------------------
$GLOBALS['NONCE_OK'] = true;
$GLOBALS['CAP_OK']   = true;
$GLOBALS['RL_OK']    = true;
$GLOBALS['JSON']     = null;
$GLOBALS['GEN_CALLED'] = null;

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
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) { return gmdate('Y-m-d H:i:s'); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return is_string($v) ? trim(preg_replace('/[\r\n\t]+/', ' ', $v)) : $v; }
}
if (!function_exists('absint')) {
    function absint($v) { return abs((int) $v); }
}
if (!function_exists('__')) {
    function __($t, $d = null) { return $t; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($a, $n = false, $die = true) { return (bool) $GLOBALS['NONCE_OK']; }
}
if (!function_exists('current_user_can')) {
    // The endpoints gate on manage_options; CAP_OK drives the test's answer.
    function current_user_can($cap) { return (bool) $GLOBALS['CAP_OK']; }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status = null) {
        $GLOBALS['JSON'] = ['type' => 'success', 'data' => $data, 'status' => $status];
    }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status = null) {
        $GLOBALS['JSON'] = ['type' => 'error', 'data' => $data, 'status' => $status];
    }
}
if (!function_exists('add_action')) {
    function add_action() { return true; }
}

// Minimal WP_Error.
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $msg;
        public function __construct($code = '', $message = '') { $this->msg = $message; }
        public function get_error_message() { return $this->msg; }
    }
}

// ---------------------------------------------------------------------------
// Stub plugin classes (defined BEFORE the autoloader so the real ones don't
// load): Permissions, Rate_Limiter, Invoice_Generator.
// ---------------------------------------------------------------------------
class MealsDB_Permissions {
    public static function can_access_plugin(): bool { return (bool) $GLOBALS['CAP_OK']; }
    public static function required_capability(): string { return 'manage_woocommerce'; }
}
class MealsDB_Rate_Limiter {
    public static function check_rate_limit(string $bucket, ?int $uid = null): bool { return (bool) $GLOBALS['RL_OK']; }
}
class MealsDB_Invoice_Generator {
    public static function build_vac_draft_rows($s, $e): array {
        $GLOBALS['GEN_CALLED'] = 'vac'; return self::sample();
    }
    public static function build_sdnb_legacy_draft_rows($z, $s, $e): array {
        $GLOBALS['GEN_CALLED'] = 'sdnb_legacy:' . $z; return self::sample();
    }
    public static function build_sdnb_new_portal_draft_rows($s, $e): array {
        $GLOBALS['GEN_CALLED'] = 'sdnb_new_portal'; return self::sample();
    }
    private static function sample(): array {
        return [
            42 => [
                'client_id'              => 42,
                'last_name'              => 'Zubrowski',
                'individual_id'          => 'IND-001',
                'allocated_mains'        => 12,
                'allocated_tax_sides'    => 0,
                'allocated_nontax_sides' => 0,
                'resolved_rate'          => 9.05,
                'contribution_cents'     => 0, // present so SDNB contribution edits resolve
                'tax_cents'              => 0,
            ],
        ];
    }
    // INV-DRAFT-3: finalize() now serializes `current` through these. The
    // stub returns deterministic strings; serialization correctness lives in
    // test-invoice-serialize.php (against the REAL generator).
    public static function serialize_vac_csv(array $rows): string { return "K#\n" . count($rows); }
    public static function serialize_sdnb_legacy(array $rows, array $ctx): string { return "sdnb_legacy\n" . count($rows); }
    public static function serialize_sdnb_new_portal(array $rows): string { return "sdnb_new\n" . count($rows); }
    public static function serialize_vac_pdf_from_csv(string $csv, string $end): string { return '%PDF-stub'; }
    // INVOICE-DRAFT-SPREADSHEET 3a: the edit endpoint recomputes VAC derived
    // values through the page's derived_display(), which calls THIS. A fixed
    // sentinel keeps this a WIRING test (the real formula is proven against the
    // real class in test-vac-compute-derived.php) — assert the values flow
    // through, formatted, in the response.
    public static function compute_vac_row_derived(array $row): array {
        return [
            'vet_mains_cost_cents'      => 15000,
            'vac_total_cents'           => 15000,
            'remaining_sides'           => 0,
            'allowance_remaining_cents' => 0,
        ];
    }
    // SDNB-legacy per-line recompute (directive SDNB scope). Fixed two-line
    // sentinel — WIRING only (real split math in test-sdnb-legacy-compute.php);
    // the endpoint formats these via the page's sdnb_line_display().
    public static function recompute_sdnb_legacy_lines(array $row): array {
        return [
            ['line_number' => 1, 'units' => 8, 'rate' => 11.40, 'basic_cost_cents' => 9120, 'tax_cents' => 336, 'line_total_cents' => 8956],
            ['line_number' => 2, 'units' => 4, 'rate' => 10.20, 'basic_cost_cents' => 4080, 'tax_cents' => 0,   'line_total_cents' => 4080],
        ];
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!class_exists('wpdb')) { class wpdb {} }

/**
 * In-memory wpdb: stores draft rows AND captures audit-log INSERTs so the
 * per-field audit assertions (T-3 … T-7) can inspect them.
 */
class AjaxDraftWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public array $drafts = [];
    public array $finalize_calls = [];
    public array $audit = [];   // captured audit-log INSERT SQL strings
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
        if (strpos($table, 'meals_invoice_drafts') !== false) {
            $id = $this->next_id++;
            $data['draft_id'] = $id;
            $this->drafts[$id] = $data;
            $this->insert_id = $id;
            return 1;
        }
        $this->insert_id = 1;
        return 1;
    }

    public function get_row($q, $o = null) {
        if (stripos($q, 'meals_invoice_drafts') !== false
            && preg_match('/draft_id = (\d+)/', $q, $m)) {
            return $this->drafts[(int) $m[1]] ?? null;
        }
        return null;
    }

    public function get_results($q, $o = null) {
        if (stripos($q, 'meals_invoice_drafts') !== false) {
            // Honor the status / billing_month filters (see DraftWpdb) so the
            // shared-lock conflict detection sees a realistic finalized set.
            $st = preg_match("/status = '([^']*)'/", $q, $m1) ? $m1[1] : null;
            $bm = preg_match("/billing_month = '([^']*)'/", $q, $m2) ? $m2[1] : null;
            $out = [];
            foreach ($this->drafts as $row) {
                if ($st !== null && (string) ($row['status'] ?? '') !== $st) { continue; }
                if ($bm !== null && (string) ($row['billing_month'] ?? '') !== $bm) { continue; }
                unset($row['payload']);
                $out[] = $row;
            }
            return $out;
        }
        return [];
    }

    public function query($q) {
        // Audit-log INSERT (MealsDB_Logger::log).
        if (stripos($q, 'meals_audit_log') !== false && stripos($q, 'INSERT') !== false) {
            $this->audit[] = $q;
            return 1;
        }
        // Draft payload UPDATE (edit_field).
        if (stripos($q, 'meals_invoice_drafts') !== false && stripos($q, 'UPDATE') !== false) {
            if (preg_match('/draft_id = (\d+)/', $q, $m)) {
                $id = (int) $m[1];
                if (isset($this->drafts[$id]) && ($this->drafts[$id]['status'] ?? '') === 'draft') {
                    if (preg_match("/payload = '(.*?)', edit_count/s", $q, $pm)) {
                        $this->drafts[$id]['payload'] = stripslashes($pm[1]);
                    }
                    $this->drafts[$id]['edit_count'] = (int) ($this->drafts[$id]['edit_count'] ?? 0) + 1;
                    return 1;
                }
                return 0;
            }
        }
        return true;
    }

    public function update($table, $data, $where, $df = null, $wf = null) {
        if (strpos($table, 'meals_invoice_drafts') !== false) {
            $id = (int) ($where['draft_id'] ?? 0);
            if (!isset($this->drafts[$id])) { return 0; }
            if (isset($where['status']) && ($this->drafts[$id]['status'] ?? '') !== $where['status']) {
                return 0;
            }
            foreach ($data as $k => $v) { $this->drafts[$id][$k] = $v; }
            return 1;
        }
        if (strpos($table, 'meals_client_allocations') !== false) {
            $this->finalize_calls[] = $where;
            return 1;
        }
        return 0;
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

function reset_env(): AjaxDraftWpdb {
    $GLOBALS['NONCE_OK'] = true;
    $GLOBALS['CAP_OK']   = true;
    $GLOBALS['RL_OK']    = true;
    $GLOBALS['JSON']     = null;
    $GLOBALS['GEN_CALLED'] = null;
    $_POST = [];
    $w = new AjaxDraftWpdb();
    $GLOBALS['wpdb'] = $w;
    return $w;
}
function json_type() { return $GLOBALS['JSON']['type'] ?? null; }
function json_data() { return $GLOBALS['JSON']['data'] ?? null; }

function make_draft(string $pipeline = 'vac', string $month = '2026-01'): int {
    $id = MealsDB_Invoice_Draft::create(
        $pipeline, $month, $month . '-01', $month . '-28',
        MealsDB_Invoice_Generator::build_vac_draft_rows('', ''), []
    );
    // create() audits "invoice_draft_created"; clear that so the edit/finalize
    // assertions below only see audit rows produced by the action under test.
    if (isset($GLOBALS['wpdb']->audit)) { $GLOBALS['wpdb']->audit = []; }
    $GLOBALS['GEN_CALLED'] = null;
    return $id;
}

// ===========================================================================
// T-1: generate_draft happy path.
// ===========================================================================
$w = reset_env();
$_POST = ['pipeline' => 'sdnb_legacy', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'zone' => 'm'];
MealsDB_Ajax_Invoice_Draft::generate_draft();
chk(json_type(), 'success', 'T-1: generate returns success');
chk($GLOBALS['GEN_CALLED'], 'sdnb_legacy:M', 'T-1: calls the SDNB legacy builder with canonical zone');
chk_true((int) (json_data()['draft_id'] ?? 0) > 0, 'T-1: returns a draft_id');
chk(count($w->drafts), 1, 'T-1: exactly one draft persisted');

// ===========================================================================
// T-2: generate_draft validation.
// ===========================================================================
$w = reset_env();
$_POST = ['pipeline' => 'bogus', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31'];
MealsDB_Ajax_Invoice_Draft::generate_draft();
chk(json_type(), 'error', 'T-2: unknown pipeline → error');
chk(count($w->drafts), 0, 'T-2: no draft created for unknown pipeline');

$w = reset_env();
$_POST = ['pipeline' => 'vac', 'period_start' => '2026-13-99', 'period_end' => '2026-01-31'];
MealsDB_Ajax_Invoice_Draft::generate_draft();
chk(json_type(), 'error', 'T-2: malformed date → error');
chk(count($w->drafts), 0, 'T-2: no draft created for bad date');

$w = reset_env();
$_POST = ['pipeline' => 'sdnb_legacy', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'zone' => 'ZZ'];
MealsDB_Ajax_Invoice_Draft::generate_draft();
chk(json_type(), 'error', 'T-2: unknown SDNB zone → error');
chk(count($w->drafts), 0, 'T-2: no draft created for bad zone');

// ===========================================================================
// T-3: edit_draft_field happy path + exactly one audit row.
// ===========================================================================
$w = reset_env();
$id = make_draft();
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'resolved_rate', 'new_value' => '12.50'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'success', 'T-3: edit returns success');
chk(count($w->audit), 1, 'T-3: exactly ONE audit row written');
$row = $w->audit[0];
chk_true(strpos($row, "'invoice_draft_edit'") !== false, 'T-3: audit action = invoice_draft_edit');
chk_true(strpos($row, "'42:resolved_rate'") !== false, 'T-3: audit field = <cid>:<field>');
chk_true(strpos($row, "'9.05'") !== false, 'T-3: audit carries the OLD value');
chk_true(strpos($row, "'12.5'") !== false, 'T-3: audit carries the NEW value');
// Stored value normalized to the field type (float dollars).
chk(json_data()['value'], 12.5, 'T-3: stored value normalized to float');

// ===========================================================================
// T-4: edit_draft_field no-op → applied but NO audit row.
// ===========================================================================
$w = reset_env();
$id = make_draft();
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'resolved_rate', 'new_value' => '9.05'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'success', 'T-4: no-op edit still returns success');
chk(json_data()['changed'], false, 'T-4: response flags changed=false');
chk(count($w->audit), 0, 'T-4: NO audit row for a no-op edit');
chk((int) $w->drafts[$id]['edit_count'], 1, 'T-4: edit_count still bumped (edit applied)');

// ===========================================================================
// T-5: edit_draft_field PII path → name/ID edit fingerprinted in audit.
// ===========================================================================
$w = reset_env();
$id = make_draft();
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'individual_id', 'new_value' => 'IND-999'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'success', 'T-5: PII field edit returns success');
chk(count($w->audit), 1, 'T-5: one audit row written');
$row = $w->audit[0];
chk_true(strpos($row, 'redacted:sha256=') !== false, 'T-5: audit value is fingerprinted (logger scrub fired)');
chk_true(strpos($row, 'IND-001') === false, 'T-5: cleartext OLD government ID NOT in audit row');
chk_true(strpos($row, 'IND-999') === false, 'T-5: cleartext NEW government ID NOT in audit row');

// ===========================================================================
// T-6: edit validation rejects bad money → error, no edit, no audit.
// ===========================================================================
$w = reset_env();
$id = make_draft();
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'resolved_rate', 'new_value' => '-5'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'error', 'T-6: negative money → error');
chk(count($w->audit), 0, 'T-6: no audit row on validation failure');
chk((int) $w->drafts[$id]['edit_count'], 0, 'T-6: edit_field NOT called (edit_count unchanged)');

$w = reset_env();
$id = make_draft();
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'resolved_rate', 'new_value' => '999999'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'error', 'T-6: over-ceiling money → error');
chk((int) $w->drafts[$id]['edit_count'], 0, 'T-6: over-ceiling not applied');

$w = reset_env();
$id = make_draft();
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'allocated_mains', 'new_value' => '-3'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'error', 'T-6: negative count → error');

// ===========================================================================
// T-7: edit refused on finalized draft → error, no audit.
// ===========================================================================
$w = reset_env();
$id = make_draft();
MealsDB_Invoice_Draft::finalize($id);
$w->audit = []; // drop the "invoice_draft_finalized" setup row; assert on the edit only
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'resolved_rate', 'new_value' => '13.00'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'error', 'T-7: edit on finalized draft → error');
chk(count($w->audit), 0, 'T-7: no audit row when edit refused');

// ===========================================================================
// T-8: finalize_draft → success; re-finalize → error.
// ===========================================================================
$w = reset_env();
$id = make_draft('vac', '2026-02');
$_POST = ['draft_id' => $id];
MealsDB_Ajax_Invoice_Draft::finalize_draft();
chk(json_type(), 'success', 'T-8: finalize returns success');
chk(json_data()['finalized'], true, 'T-8: response flags finalized=true');
chk($w->drafts[$id]['status'], 'finalized', 'T-8: draft status → finalized');

$GLOBALS['JSON'] = null;
$_POST = ['draft_id' => $id];
MealsDB_Ajax_Invoice_Draft::finalize_draft();
chk(json_type(), 'error', 'T-8: re-finalize the same draft → error');

// ===========================================================================
// T-9: capability / nonce → rejected before any service call.
// ===========================================================================
// Bad nonce on each endpoint.
$w = reset_env(); $GLOBALS['NONCE_OK'] = false;
$_POST = ['pipeline' => 'vac', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31'];
MealsDB_Ajax_Invoice_Draft::generate_draft();
chk(json_type(), 'error', 'T-9: generate rejected on bad nonce');
chk($GLOBALS['GEN_CALLED'], null, 'T-9: generator NOT called on bad nonce');
chk(count($w->drafts), 0, 'T-9: no draft created on bad nonce');

$w = reset_env(); $id = make_draft(); $GLOBALS['NONCE_OK'] = false;
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'resolved_rate', 'new_value' => '20'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'error', 'T-9: edit rejected on bad nonce');
chk(count($w->audit), 0, 'T-9: no audit row on bad nonce');
chk((int) $w->drafts[$id]['edit_count'], 0, 'T-9: edit not applied on bad nonce');

// Missing capability.
$w = reset_env(); $id = make_draft(); $GLOBALS['CAP_OK'] = false;
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'resolved_rate', 'new_value' => '20'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'error', 'T-9: edit rejected without capability');
chk(count($w->audit), 0, 'T-9: no audit row without capability');

$w = reset_env(); $GLOBALS['CAP_OK'] = false;
$_POST = ['draft_id' => 1];
MealsDB_Ajax_Invoice_Draft::finalize_draft();
chk(json_type(), 'error', 'T-9: finalize rejected without capability');

// Rate-limit exceeded (write bucket fails closed).
$w = reset_env(); $id = make_draft(); $GLOBALS['RL_OK'] = false;
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'resolved_rate', 'new_value' => '20'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'error', 'T-9: edit rejected when rate-limited');
chk((int) ($GLOBALS['JSON']['status'] ?? 0), 429, 'T-9: rate-limit returns HTTP 429');
chk(count($w->audit), 0, 'T-9: no audit row when rate-limited');

// ===========================================================================
// T-10 (directive INV-2): unfinalize_draft — success after finalize, the
// REQUIRED reason, missing-id rejection, audit row, and guard rejections.
// ===========================================================================
// Happy path: finalize, then un-finalize with a reason.
$w = reset_env();
$id = make_draft('vac', '2026-09');
$_POST = ['draft_id' => $id];
MealsDB_Ajax_Invoice_Draft::finalize_draft();
chk($w->drafts[$id]['status'], 'finalized', 'T-10: precondition — draft finalized');
$w->audit = []; // assert only on the unfinalize audit row
$GLOBALS['JSON'] = null;
$_POST = ['draft_id' => $id, 'reason' => 'finalized at zero against empty products'];
MealsDB_Ajax_Invoice_Draft::unfinalize_draft();
chk(json_type(), 'success', 'T-10: unfinalize returns success');
chk($w->drafts[$id]['status'], 'draft', 'T-10: draft restored to editable');
chk(count($w->audit), 1, 'T-10: exactly ONE audit row written');
chk_true(strpos($w->audit[0], "'invoice_draft_unfinalized'") !== false, 'T-10: audit action = invoice_draft_unfinalized');
chk_true(strpos($w->audit[0], 'finalized at zero against empty products') !== false, 'T-10: audit carries the reason');

// Empty / whitespace reason → rejected, no state change, no audit.
$w = reset_env();
$id = make_draft('vac', '2026-10');
$_POST = ['draft_id' => $id];
MealsDB_Ajax_Invoice_Draft::finalize_draft();
$w->audit = [];
$GLOBALS['JSON'] = null;
$_POST = ['draft_id' => $id, 'reason' => '   '];
MealsDB_Ajax_Invoice_Draft::unfinalize_draft();
chk(json_type(), 'error', 'T-10: empty reason → error');
chk($w->drafts[$id]['status'], 'finalized', 'T-10: still finalized after a reasonless request');
chk(count($w->audit), 0, 'T-10: no audit row when the reason is rejected');

// Missing draft id → error.
$w = reset_env();
$GLOBALS['JSON'] = null;
$_POST = ['reason' => 'whatever'];
MealsDB_Ajax_Invoice_Draft::unfinalize_draft();
chk(json_type(), 'error', 'T-10: missing draft id → error');

// Un-finalizing a draft-status (never finalized) draft → service refuses.
$w = reset_env();
$id = make_draft('vac', '2026-11');
$GLOBALS['JSON'] = null;
$_POST = ['draft_id' => $id, 'reason' => 'should refuse'];
MealsDB_Ajax_Invoice_Draft::unfinalize_draft();
chk(json_type(), 'error', 'T-10: un-finalize on a draft-status draft → error');

// Guard rejections: bad nonce / missing capability before any state change.
$w = reset_env(); $id = make_draft('vac', '2026-12');
$_POST = ['draft_id' => $id]; MealsDB_Ajax_Invoice_Draft::finalize_draft();
$w->audit = []; $GLOBALS['NONCE_OK'] = false; $GLOBALS['JSON'] = null;
$_POST = ['draft_id' => $id, 'reason' => 'x'];
MealsDB_Ajax_Invoice_Draft::unfinalize_draft();
chk(json_type(), 'error', 'T-10: unfinalize rejected on bad nonce');
chk($w->drafts[$id]['status'], 'finalized', 'T-10: still finalized after bad-nonce request');
chk(count($w->audit), 0, 'T-10: no audit row on bad nonce');

$w = reset_env(); $id = make_draft('vac', '2027-01');
$_POST = ['draft_id' => $id]; MealsDB_Ajax_Invoice_Draft::finalize_draft();
$w->audit = []; $GLOBALS['CAP_OK'] = false; $GLOBALS['JSON'] = null;
$_POST = ['draft_id' => $id, 'reason' => 'x'];
MealsDB_Ajax_Invoice_Draft::unfinalize_draft();
chk(json_type(), 'error', 'T-10: unfinalize rejected without capability');
chk($w->drafts[$id]['status'], 'finalized', 'T-10: still finalized without capability');

// ===========================================================================
// T-11 (PR #418 review): shared-lock confirmation round-trip. Two finalized
// drafts share a client-month → the first un-finalize call (no cascade) returns
// requires_confirmation with a message naming the sibling + shared client; the
// cascade re-call un-finalizes BOTH.
// ===========================================================================
$w = reset_env();
$idA = make_draft('vac', '2026-02');
$idB = make_draft('vac', '2026-02'); // same month + client (stub generator → 42)
MealsDB_Invoice_Draft::finalize($idA);
MealsDB_Invoice_Draft::finalize($idB);
chk($w->drafts[$idA]['status'], 'finalized', 'T-11: precondition — A finalized');
chk($w->drafts[$idB]['status'], 'finalized', 'T-11: precondition — B finalized');

// First call (no cascade) → confirmation required, nothing un-finalized yet.
$w->audit = []; $GLOBALS['JSON'] = null;
$_POST = ['draft_id' => $idA, 'reason' => 'fix zero-priced invoice'];
MealsDB_Ajax_Invoice_Draft::unfinalize_draft();
chk(json_type(), 'success', 'T-11: first call returns success envelope');
chk(json_data()['requires_confirmation'] ?? null, true, 'T-11: requires_confirmation flagged');
chk_true(strpos((string) (json_data()['message'] ?? ''), '#' . $idB) !== false, 'T-11: message names the sibling invoice');
chk_true(strpos((string) (json_data()['message'] ?? ''), 'Zubrowski') !== false, 'T-11: message names the shared client');
chk($w->drafts[$idA]['status'], 'finalized', 'T-11: A NOT un-finalized before confirmation');
chk($w->drafts[$idB]['status'], 'finalized', 'T-11: B NOT un-finalized before confirmation');
chk(count($w->audit), 0, 'T-11: no audit row before confirmation');

// Cascade re-call → both un-finalize.
$GLOBALS['JSON'] = null;
$_POST = ['draft_id' => $idA, 'reason' => 'fix zero-priced invoice', 'cascade' => 1];
MealsDB_Ajax_Invoice_Draft::unfinalize_draft();
chk(json_type(), 'success', 'T-11: cascade call returns success');
chk($w->drafts[$idA]['status'], 'draft', 'T-11: A un-finalized after cascade');
chk($w->drafts[$idB]['status'], 'draft', 'T-11: sibling B un-finalized after cascade');
chk(count($w->audit), 2, 'T-11: an audit row for each un-finalized invoice');

// ===========================================================================
// T-12 (INVOICE-DRAFT-SPREADSHEET 3b): the edit response carries the
// recomputed, formatted derived values for a VAC draft — and an empty map for
// a pipeline with no money derivation (SDNB).
// ===========================================================================
$w = reset_env();
$id = make_draft('vac');
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'resolved_rate', 'new_value' => '12.50'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'success', 'T-12: VAC edit returns success');
$derived = json_data()['derived'] ?? null;
chk_true(is_array($derived), 'T-12: response carries a derived map');
chk($derived['vet_mains_cost'] ?? null, '$150.00', 'T-12: vet_mains_cost formatted from compute (15000c)');
chk($derived['vac_total'] ?? null,      '$150.00', 'T-12: vac_total formatted from compute (15000c)');
chk($derived['remaining_sides'] ?? null, '0',       'T-12: remaining_sides formatted as a count');

// SDNB new-portal has no money derivation (the portal owns the total) → empty
// derived map. (SDNB-legacy DOES derive per-line — see T-13b.)
$w = reset_env();
$id = make_draft('sdnb_new_portal');
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'resolved_rate', 'new_value' => '12.50'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'success', 'T-12: SDNB new-portal edit returns success');
chk(json_data()['derived'] ?? 'MISSING', [], 'T-12: new-portal pipeline → empty derived map');

// ===========================================================================
// T-13 (INVOICE-DRAFT-SPREADSHEET SDNB scope): the SDNB-legacy edit endpoint
// (a) converts a dollars-edited contribution to stored cents and redisplays
// dollars, and (b) returns the per-line derived list.
// ===========================================================================
// (a) $/cents round-trip: contribution edited as dollars → stored cents.
$w = reset_env();
$id = make_draft('sdnb_legacy');
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'contribution_cents', 'new_value' => '5.00', 'edit_as' => 'dollars'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'success', 'T-13a: dollars-edited contribution saves');
chk(json_data()['value'], 500, 'T-13a: $5.00 stored as 500 cents');
chk(json_data()['value_display'] ?? null, '5.00', 'T-13a: input redisplays as dollars, not 500');
chk(json_data()['changed'], true, 'T-13a: 0 → 500 is a change');

// (b) per-line derived payload (ordered list, display strings).
$w = reset_env();
$id = make_draft('sdnb_legacy');
$_POST = ['draft_id' => $id, 'client_id' => '42', 'field' => 'allocated_mains', 'new_value' => '20'];
MealsDB_Ajax_Invoice_Draft::edit_draft_field();
chk(json_type(), 'success', 'T-13b: SDNB mains edit success');
$d = json_data()['derived'] ?? null;
chk_true(is_array($d) && isset($d['lines']), 'T-13b: SDNB derived carries a per-line list (not a flat map)');
chk(count($d['lines'] ?? []), 2, 'T-13b: two lines from the split');
chk($d['lines'][0]['units'] ?? null,            '8',      'T-13b: line-1 units display');
chk($d['lines'][0]['line_total_cents'] ?? null, '$89.56', 'T-13b: line-1 total formatted from cents');
chk($d['lines'][1]['rate'] ?? null,             '$10.20', 'T-13b: line-2 (derived) rate formatted');

// ---------------------------------------------------------------------------
echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
