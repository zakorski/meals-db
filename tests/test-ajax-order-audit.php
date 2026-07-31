<?php
/**
 * Tests for MealsDB_Ajax_Order_Audit (Weekly Order Audit, Task 6).
 *
 * The AJAX layer is a thin guard + wiring shell over the (separately tested)
 * MealsDB_Order_Audit service. These tests exercise the wiring in isolation:
 * the service is STUBBED (class defined before the autoloader can load the real
 * one), so the assertions are about the endpoint's own logic — guard spine,
 * server-side week validation, the derived week_end, the int-map coercion that
 * must NOT clamp negatives, and the success/error envelopes.
 *
 * Run: php tests/test-ajax-order-audit.php
 *
 * Harness technique mirrors tests/test-ajax-invoice-draft.php: controllable
 * $GLOBALS guard flags (NONCE_OK / CAP_OK / RL_OK), a captured $GLOBALS['JSON']
 * response, WP function stubs, and a stubbed service that RECORDS its calls so
 * the wiring can be asserted.
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

// Pin server-local timezone to the real deployment locale so tz-dependent date
// functions in the code under test fail loudly instead of coinciding with UTC.
date_default_timezone_set('America/Moncton');

// ---------------------------------------------------------------------------
// Controllable guard flags + recorded JSON response + service call log.
// ---------------------------------------------------------------------------
$GLOBALS['NONCE_OK'] = true;
$GLOBALS['CAP_OK']   = true;
$GLOBALS['RL_OK']    = true;
$GLOBALS['JSON']     = null;
$GLOBALS['SVC']      = [];   // recorded service calls
$GLOBALS['SVC_RET']  = [];   // per-method canned return values

// ---------------------------------------------------------------------------
// Minimal WP function stubs.
// ---------------------------------------------------------------------------
if (!function_exists('wp_unslash')) {
    function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return is_string($v) ? trim(preg_replace('/[\r\n\t]+/', ' ', $v)) : $v; }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($v) { return is_string($v) ? trim($v) : $v; }
}
if (!function_exists('absint')) {
    function absint($v) { return abs((int) $v); }
}
if (!function_exists('__')) {
    function __($t, $d = null) { return $t; }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($a, $n = false, $die = true) { return (bool) $GLOBALS['NONCE_OK']; }
}
if (!function_exists('current_user_can')) {
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
// load): Permissions, Rate_Limiter, Logger, and the Order_Audit SERVICE.
// ---------------------------------------------------------------------------
class MealsDB_Permissions {
    public static function required_capability(): string { return 'manage_woocommerce'; }
}
class MealsDB_Rate_Limiter {
    public static function check_rate_limit(string $bucket, ?int $uid = null): bool {
        $GLOBALS['SVC'][] = ['rate_limit', $bucket];
        return (bool) $GLOBALS['RL_OK'];
    }
}
class MealsDB_Logger {
    public static function error($m) { /* swallow in tests */ }
}

/**
 * Stubbed service: every method records its args in $GLOBALS['SVC'] and returns
 * a canned value from $GLOBALS['SVC_RET'] (with sensible defaults). This lets
 * the AJAX-layer wiring be asserted without a DB.
 */
class MealsDB_Order_Audit {
    private static function ret(string $method, $default) {
        return array_key_exists($method, $GLOBALS['SVC_RET']) ? $GLOBALS['SVC_RET'][$method] : $default;
    }
    public static function find_by_week(string $week_start): int {
        $GLOBALS['SVC'][] = ['find_by_week', $week_start];
        return (int) self::ret('find_by_week', 0);
    }
    public static function build_week_rows(string $week_start, string $week_end): ?array {
        $GLOBALS['SVC'][] = ['build_week_rows', $week_start, $week_end];
        return self::ret('build_week_rows', []);
    }
    public static function create_for_week(string $week_start, string $week_end, array $rows): int {
        $GLOBALS['SVC'][] = ['create_for_week', $week_start, $week_end, count($rows)];
        return (int) self::ret('create_for_week', 99);
    }
    public static function get(int $audit_id): ?array {
        $GLOBALS['SVC'][] = ['get', $audit_id];
        return self::ret('get', ['row_count' => 3, 'confirmed_count' => 1, 'edited_count' => 1]);
    }
    public static function confirm_row(int $audit_id, int $order_id) {
        $GLOBALS['SVC'][] = ['confirm_row', $audit_id, $order_id];
        return self::ret('confirm_row', 'confirmed');
    }
    public static function edit_row(int $audit_id, int $order_id, array $qtys, string $note) {
        $GLOBALS['SVC'][] = ['edit_row', $audit_id, $order_id, $qtys, $note];
        return self::ret('edit_row', true);
    }
    public static function revert_row(int $audit_id, int $order_id) {
        $GLOBALS['SVC'][] = ['revert_row', $audit_id, $order_id];
        return self::ret('revert_row', true);
    }
    public static function finalize(int $audit_id) {
        $GLOBALS['SVC'][] = ['finalize', $audit_id];
        return self::ret('finalize', true);
    }
    public static function unfinalize(int $audit_id, string $reason) {
        $GLOBALS['SVC'][] = ['unfinalize', $audit_id, $reason];
        return self::ret('unfinalize', true);
    }
    public static function delete_draft(int $audit_id) {
        $GLOBALS['SVC'][] = ['delete_draft', $audit_id];
        return self::ret('delete_draft', true);
    }
}

// Load ONLY the AJAX class under test (not the autoloader — every dependency
// it touches is stubbed above, and loading the autoloader would try to define
// the real MealsDB_Order_Audit, colliding with our stub).
require_once __DIR__ . '/../includes/ajax/class-ajax-order-audit.php';

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

function reset_env(): void {
    $GLOBALS['NONCE_OK'] = true;
    $GLOBALS['CAP_OK']   = true;
    $GLOBALS['RL_OK']    = true;
    $GLOBALS['JSON']     = null;
    $GLOBALS['SVC']      = [];
    $GLOBALS['SVC_RET']  = [];
    $_POST = [];
}
function json_type() { return $GLOBALS['JSON']['type'] ?? null; }
function json_data() { return $GLOBALS['JSON']['data'] ?? null; }
function json_status() { return $GLOBALS['JSON']['status'] ?? null; }
/** Was the named service method invoked at least once? */
function svc_called(string $method): bool {
    foreach ($GLOBALS['SVC'] as $c) { if (($c[0] ?? '') === $method) { return true; } }
    return false;
}
/** Return the FIRST recorded call to $method, or null. */
function svc_call(string $method): ?array {
    foreach ($GLOBALS['SVC'] as $c) { if (($c[0] ?? '') === $method) { return $c; } }
    return null;
}

// A Monday and a non-Monday, in Y-m-d.
$MONDAY   = '2026-07-27'; // 2026-07-27 is a Monday
$TUESDAY  = '2026-07-28'; // not a Monday
$WEEK_END = '2026-08-02'; // Monday + 6 days

// ===========================================================================
// 1. Guard spine on a representative endpoint (create).
// ===========================================================================
// Bad nonce → error, no service touched.
reset_env(); $GLOBALS['NONCE_OK'] = false;
$_POST = ['week_start' => $MONDAY];
MealsDB_Ajax_Order_Audit::create();
chk(json_type(), 'error', 'guard: bad nonce → error');
chk(svc_called('find_by_week'), false, 'guard: service not called on bad nonce');

// Capability denied → error.
reset_env(); $GLOBALS['CAP_OK'] = false;
$_POST = ['week_start' => $MONDAY];
MealsDB_Ajax_Order_Audit::create();
chk(json_type(), 'error', 'guard: no capability → error');
chk(svc_called('find_by_week'), false, 'guard: service not called without capability');

// Rate-limited → error with 429.
reset_env(); $GLOBALS['RL_OK'] = false;
$_POST = ['week_start' => $MONDAY];
MealsDB_Ajax_Order_Audit::create();
chk(json_type(), 'error', 'guard: rate-limited → error');
chk((int) json_status(), 429, 'guard: rate-limit returns HTTP 429');
chk(svc_called('find_by_week'), false, 'guard: service not called when rate-limited');
// The bucket is the audit-edit bucket.
$rl = svc_call('rate_limit');
chk($rl[1] ?? null, 'order_audit_edit', 'guard: uses the order_audit_edit bucket');

// ===========================================================================
// 2. create() — validation, existing short-circuit, derived week_end.
// ===========================================================================
// Invalid week_start format → error, no build/create.
reset_env();
$_POST = ['week_start' => 'not-a-date'];
MealsDB_Ajax_Order_Audit::create();
chk(json_type(), 'error', 'create: bad format → error');
chk(svc_called('build_week_rows'), false, 'create: build not called on bad format');

// Valid Y-m-d but NOT a Monday → error.
reset_env();
$_POST = ['week_start' => $TUESDAY];
MealsDB_Ajax_Order_Audit::create();
chk(json_type(), 'error', 'create: non-Monday → error');
chk(svc_called('build_week_rows'), false, 'create: build not called for non-Monday');

// Monday + existing week → success carrying the existing id + existing:true,
// and create_for_week NOT called.
reset_env();
$GLOBALS['SVC_RET']['find_by_week'] = 5;
$_POST = ['week_start' => $MONDAY];
MealsDB_Ajax_Order_Audit::create();
chk(json_type(), 'success', 'create: existing week → success');
chk((int) (json_data()['audit_id'] ?? 0), 5, 'create: existing audit_id returned');
chk(json_data()['existing'] ?? null, true, 'create: existing flagged true');
chk(svc_called('build_week_rows'), false, 'create: no rebuild when week already exists');
chk(svc_called('create_for_week'), false, 'create: create_for_week NOT called for existing week');

// Monday + no existing → build + create, success carries audit_id + row_count,
// and the derived week_end (Monday + 6d) is passed through.
reset_env();
$GLOBALS['SVC_RET']['find_by_week']    = 0;
$GLOBALS['SVC_RET']['build_week_rows'] = [101 => ['x'], 102 => ['y']];
$GLOBALS['SVC_RET']['create_for_week'] = 77;
$_POST = ['week_start' => $MONDAY];
MealsDB_Ajax_Order_Audit::create();
chk(json_type(), 'success', 'create: new week → success');
chk((int) (json_data()['audit_id'] ?? 0), 77, 'create: new audit_id returned');
chk(json_data()['existing'] ?? null, false, 'create: existing flagged false');
chk((int) (json_data()['row_count'] ?? -1), 2, 'create: row_count reflects built rows');
$bwr = svc_call('build_week_rows');
chk($bwr[1] ?? null, $MONDAY, 'create: build_week_rows got the week_start');
chk($bwr[2] ?? null, $WEEK_END, 'create: build_week_rows got the derived week_end (Mon+6d)');
$cfw = svc_call('create_for_week');
chk($cfw[2] ?? null, $WEEK_END, 'create: create_for_week got the derived week_end');

// build_week_rows returns null → error, create_for_week NOT called.
reset_env();
$GLOBALS['SVC_RET']['find_by_week']    = 0;
$GLOBALS['SVC_RET']['build_week_rows'] = null;
$_POST = ['week_start' => $MONDAY];
MealsDB_Ajax_Order_Audit::create();
chk(json_type(), 'error', 'create: build null → error');
chk(svc_called('create_for_week'), false, 'create: create_for_week NOT called when build fails');

// create_for_week returns 0 → error.
reset_env();
$GLOBALS['SVC_RET']['find_by_week']    = 0;
$GLOBALS['SVC_RET']['build_week_rows'] = [1 => ['a']];
$GLOBALS['SVC_RET']['create_for_week'] = 0;
$_POST = ['week_start' => $MONDAY];
MealsDB_Ajax_Order_Audit::create();
chk(json_type(), 'error', 'create: create_for_week 0 → error');

// ===========================================================================
// 3. confirm() — success carries status + progress; WP_Error → error.
// ===========================================================================
reset_env();
$GLOBALS['SVC_RET']['confirm_row'] = 'confirmed';
$GLOBALS['SVC_RET']['get'] = ['row_count' => 8, 'confirmed_count' => 4, 'edited_count' => 2];
$_POST = ['audit_id' => 12, 'order_id' => 555];
MealsDB_Ajax_Order_Audit::confirm();
chk(json_type(), 'success', 'confirm: success');
chk(json_data()['status'] ?? null, 'confirmed', 'confirm: status echoed');
chk((int) (json_data()['row_count'] ?? -1), 8, 'confirm: progress row_count');
chk((int) (json_data()['confirmed_count'] ?? -1), 4, 'confirm: progress confirmed_count');
chk((int) (json_data()['edited_count'] ?? -1), 2, 'confirm: progress edited_count');
$cc = svc_call('confirm_row');
chk($cc[1] ?? null, 12, 'confirm: audit_id passed');
chk($cc[2] ?? null, 555, 'confirm: order_id passed');

reset_env();
$GLOBALS['SVC_RET']['confirm_row'] = new WP_Error('row_not_found', 'Order not found in this audit.');
$_POST = ['audit_id' => 12, 'order_id' => 999];
MealsDB_Ajax_Order_Audit::confirm();
chk(json_type(), 'error', 'confirm: WP_Error → error');
chk(json_data()['message'] ?? null, 'Order not found in this audit.', 'confirm: error message from WP_Error');

// ===========================================================================
// 4. edit() — int-map coercion (negatives NOT clamped), note passthrough.
// ===========================================================================
reset_env();
$GLOBALS['SVC_RET']['edit_row'] = true;
$GLOBALS['SVC_RET']['get'] = ['row_count' => 3, 'confirmed_count' => 0, 'edited_count' => 1];
$_POST = [
    'audit_id' => 4,
    'order_id' => 200,
    // Keys must be absint'd; values must be (int)-cast and NOT clamped: a
    // negative must reach the service AS -2 so the service can reject it.
    'qtys'     => ['7' => '3', '9' => '-2'],
    'note'     => "  short over.  ",
];
MealsDB_Ajax_Order_Audit::edit();
chk(json_type(), 'success', 'edit: success');
chk(json_data()['status'] ?? null, 'edited', 'edit: status = edited');
$ec = svc_call('edit_row');
chk_true(is_array($ec), 'edit: edit_row was called');
chk($ec[1] ?? null, 4, 'edit: audit_id passed');
chk($ec[2] ?? null, 200, 'edit: order_id passed');
// The qty map: keys absint'd (int), values (int)-cast, negative preserved.
chk($ec[3] ?? null, [7 => 3, 9 => -2], 'edit: qty map coerced — keys int, NEGATIVE value preserved (-2, NOT 0)');
chk($ec[4] ?? null, 'short over.', 'edit: note sanitized + passed through');

// A negative that would round-trip to 0 under absint MUST still be -2 here —
// explicit second assertion for the bug-class the plan calls out.
chk_true(($ec[3][9] ?? 0) === -2, 'edit: -2 reaches the service unclamped (absint bug-class avoided)');

reset_env();
$GLOBALS['SVC_RET']['edit_row'] = new WP_Error('bad_qty', 'Quantities must be zero or more.');
$_POST = ['audit_id' => 4, 'order_id' => 200, 'qtys' => ['9' => '-2'], 'note' => ''];
MealsDB_Ajax_Order_Audit::edit();
chk(json_type(), 'error', 'edit: WP_Error → error');
chk(json_data()['message'] ?? null, 'Quantities must be zero or more.', 'edit: error message from WP_Error');

// ===========================================================================
// 5. revert() — success → progress with status pending; WP_Error → error.
// ===========================================================================
reset_env();
$GLOBALS['SVC_RET']['revert_row'] = true;
$GLOBALS['SVC_RET']['get'] = ['row_count' => 3, 'confirmed_count' => 0, 'edited_count' => 0];
$_POST = ['audit_id' => 4, 'order_id' => 200];
MealsDB_Ajax_Order_Audit::revert();
chk(json_type(), 'success', 'revert: success');
chk(json_data()['status'] ?? null, 'pending', 'revert: status = pending');

reset_env();
$GLOBALS['SVC_RET']['revert_row'] = new WP_Error('finalized', 'This audit is finalized and read-only.');
$_POST = ['audit_id' => 4, 'order_id' => 200];
MealsDB_Ajax_Order_Audit::revert();
chk(json_type(), 'error', 'revert: WP_Error → error');

// ===========================================================================
// 6. finalize() — WP_Error('pending_rows') surfaces its message; success.
// ===========================================================================
reset_env();
$GLOBALS['SVC_RET']['finalize'] = new WP_Error('pending_rows',
    'Every order must be confirmed or edited before the audit can be saved.');
$_POST = ['audit_id' => 4];
MealsDB_Ajax_Order_Audit::finalize();
chk(json_type(), 'error', 'finalize: pending_rows → error');
chk(json_data()['message'] ?? null,
    'Every order must be confirmed or edited before the audit can be saved.',
    'finalize: error message from WP_Error');

reset_env();
$GLOBALS['SVC_RET']['finalize'] = true;
$_POST = ['audit_id' => 4];
MealsDB_Ajax_Order_Audit::finalize();
chk(json_type(), 'success', 'finalize: success');
chk(json_data()['finalized'] ?? null, true, 'finalize: finalized=true');

// ===========================================================================
// 7. unfinalize() — service owns the blank-reason check; delete().
// ===========================================================================
// Blank reason → the service stub returns WP_Error (the service owns the check).
reset_env();
$GLOBALS['SVC_RET']['unfinalize'] = new WP_Error('reason_required',
    'A reason is required to reopen a finalized audit.');
$_POST = ['audit_id' => 4, 'reason' => '   '];
MealsDB_Ajax_Order_Audit::unfinalize();
chk(json_type(), 'error', 'unfinalize: blank reason (service check) → error');
// The reason reaches the service verbatim (trim happens service-side) so the
// blank-check ownership is genuinely the service's.
$uc = svc_call('unfinalize');
chk_true(is_array($uc), 'unfinalize: service was called (owns the blank check)');

reset_env();
$GLOBALS['SVC_RET']['unfinalize'] = true;
$_POST = ['audit_id' => 4, 'reason' => 'finalized too early'];
MealsDB_Ajax_Order_Audit::unfinalize();
chk(json_type(), 'success', 'unfinalize: success');
chk(json_data()['unfinalized'] ?? null, true, 'unfinalize: unfinalized=true');
$uc = svc_call('unfinalize');
chk($uc[2] ?? null, 'finalized too early', 'unfinalize: reason passed through');

// delete_draft: WP_Error → error; success → {deleted:true}.
reset_env();
$GLOBALS['SVC_RET']['delete_draft'] = new WP_Error('not_draft', 'A finalized audit cannot be deleted.');
$_POST = ['audit_id' => 4];
MealsDB_Ajax_Order_Audit::delete_draft();
chk(json_type(), 'error', 'delete: WP_Error → error');
chk(json_data()['message'] ?? null, 'A finalized audit cannot be deleted.', 'delete: error message from WP_Error');

reset_env();
$GLOBALS['SVC_RET']['delete_draft'] = true;
$_POST = ['audit_id' => 4];
MealsDB_Ajax_Order_Audit::delete_draft();
chk(json_type(), 'success', 'delete: success');
chk(json_data()['deleted'] ?? null, true, 'delete: deleted=true');

// ---------------------------------------------------------------------------
echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
