<?php
/**
 * Tests for MealsDB_Invoice_Draft (directive INV-DRAFT-1) and the shared
 * payload helpers promoted to MealsDB_Encryption (Step 2).
 *
 * Covers T-1 … T-9 from the directive:
 *   T-1 create→get round-trips (generated + current both present)
 *   T-2 payload encrypted at rest (cleartext name absent from stored column)
 *   T-3 fail-closed (encryption unavailable → create returns 0, no row written)
 *   T-4 edit_field mutates current, leaves generated, bumps edit_count
 *   T-5 edit refused after finalize
 *   T-6 finalize freezes the month via finalize_month + is idempotent
 *   T-7 shared-helper parity (round-trip + legacy plaintext JSON)
 *   T-8 list() returns meta only (no payload / no PII)
 *   T-9 unknown pipeline rejected
 *
 * Run: php tests/test-invoice-draft.php
 *
 * Uses an in-memory $wpdb stub (no real DB), and the REAL MealsDB_Encryption
 * with the key sourced from the mealsdb_settings option so T-3 can revoke it.
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

// ---------------------------------------------------------------------------
// Minimal WP function stubs.
// ---------------------------------------------------------------------------
$GLOBALS['TEST_OPTIONS'] = [
    // Real 32-byte key so encryption genuinely works for T-1/T-2/T-4…T-8.
    'mealsdb_settings' => ['encryption_key' => 'base64:' . base64_encode(str_repeat('k', 32))],
];
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['TEST_OPTIONS'][$name] ?? $default;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int { return 7; }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) { return gmdate('Y-m-d H:i:s'); }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!class_exists('wpdb')) { class wpdb {} }

/**
 * In-memory wpdb stub. Stores the meals_invoice_drafts rows; treats the audit
 * log and allocation-finalize writes as permissive no-ops (recording the
 * latter so T-6 can assert the freeze fired).
 */
class DraftWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public array $drafts = [];        // draft_id => raw column row
    public array $finalize_calls = []; // each finalize_month() WHERE clause
    public bool $force_finalize_update_zero = false; // simulate the lost finalize race
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
        $this->insert_id = 1; // audit log etc.
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
            // list() never SELECTs payload — model that by stripping it.
            $out = [];
            foreach ($this->drafts as $row) {
                unset($row['payload']);
                $out[] = $row;
            }
            return $out;
        }
        return [];
    }

    public function query($q) {
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
        return true; // audit-log INSERT etc.
    }

    public function update($table, $data, $where, $df = null, $wf = null) {
        if (strpos($table, 'meals_invoice_drafts') !== false) {
            // Simulate a concurrent finalize winning the race: get() saw
            // status='draft' but our guarded WHERE now matches 0 rows.
            if ($this->force_finalize_update_zero && isset($where['status']) && $where['status'] === 'draft') {
                return 0;
            }
            $id = (int) ($where['draft_id'] ?? 0);
            if (!isset($this->drafts[$id])) { return 0; }
            if (isset($where['status']) && ($this->drafts[$id]['status'] ?? '') !== $where['status']) {
                return 0;
            }
            foreach ($data as $k => $v) { $this->drafts[$id][$k] = $v; }
            return 1;
        }
        if (strpos($table, 'meals_client_allocations') !== false) {
            $this->finalize_calls[] = $where; // finalize_month()
            return 1;
        }
        return 0;
    }
}

// ---------------------------------------------------------------------------
// Tiny assertion harness.
// ---------------------------------------------------------------------------
$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; } else {
        $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true);
    }
}
function chk_true($cond, $label) { chk((bool) $cond, true, $label); }

function fresh_wpdb(): DraftWpdb {
    $w = new DraftWpdb();
    $GLOBALS['wpdb'] = $w;
    return $w;
}

// A representative VAC row map keyed by client_id. last_name is plaintext in
// the DB (only the 5 PII columns are encrypted), so it's a good T-2 canary.
function sample_rows(): array {
    return [
        42 => [
            'client_id'       => 42,
            'last_name'       => 'Zubrowski',
            'first_name'      => 'Helena',
            'individual_id'   => 'IND-001',
            'allocated_mains' => 12,
            'resolved_rate'   => 9.05,
            'tax_cents'       => 0,
        ],
        77 => [
            'client_id'       => 77,
            'last_name'       => 'McCready',
            'first_name'      => 'Paul',
            'individual_id'   => 'IND-002',
            'allocated_mains' => 5,
            'resolved_rate'   => 9.05,
            'tax_cents'       => 0,
        ],
    ];
}

// ===========================================================================
// T-1: create → get round-trips.
// ===========================================================================
fresh_wpdb();
$rows = sample_rows();
$id = MealsDB_Invoice_Draft::create(
    MealsDB_Invoice_Draft::PIPELINE_VAC, '2026-01', '2026-01-01', '2026-01-31', $rows, []
);
chk_true($id > 0, 'T-1: create returns id > 0');
$draft = MealsDB_Invoice_Draft::get($id);
chk_true(is_array($draft), 'T-1: get returns array');
chk($draft['pipeline'], 'vac', 'T-1: pipeline meta');
chk($draft['billing_month'], '2026-01', 'T-1: billing_month meta');
chk((int) $draft['row_count'], 2, 'T-1: row_count = 2');
chk((int) $draft['edit_count'], 0, 'T-1: edit_count starts at 0');
// JSON round-trip stringifies the int client_id keys.
chk($draft['payload']['generated']['42']['last_name'], 'Zubrowski', 'T-1: generated[42] round-trips');
chk($draft['payload']['current']['42']['last_name'],   'Zubrowski', 'T-1: current[42] round-trips');
chk((int) $draft['payload']['current']['77']['allocated_mains'], 5, 'T-1: current[77] mains round-trips');

// ===========================================================================
// T-2: payload encrypted at rest.
// ===========================================================================
$w = $GLOBALS['wpdb'];
$raw_stored = (string) $w->drafts[$id]['payload'];
chk(strpos($raw_stored, 'Zubrowski'), false, 'T-2: cleartext name ABSENT from stored payload column');
chk($draft['payload']['current']['42']['last_name'], 'Zubrowski', 'T-2: get() recovers the cleartext');

// ===========================================================================
// T-3: fail-closed — encryption unavailable → create returns 0, no row.
// ===========================================================================
$w3 = fresh_wpdb();
// Revoke the key so MealsDB_Encryption::encrypt throws → encode_payload
// returns false → create must refuse and write nothing.
$saved_opts = $GLOBALS['TEST_OPTIONS'];
$GLOBALS['TEST_OPTIONS']['mealsdb_settings'] = [];
$id3 = MealsDB_Invoice_Draft::create(
    MealsDB_Invoice_Draft::PIPELINE_VAC, '2026-01', '2026-01-01', '2026-01-31', sample_rows(), []
);
chk($id3, 0, 'T-3: create returns 0 when encryption unavailable');
chk(count($w3->drafts), 0, 'T-3: no row persisted (table empty)');
$GLOBALS['TEST_OPTIONS'] = $saved_opts; // restore the key

// ===========================================================================
// T-4: edit_field mutates current, leaves generated, bumps edit_count.
// ===========================================================================
fresh_wpdb();
$id = MealsDB_Invoice_Draft::create(
    MealsDB_Invoice_Draft::PIPELINE_VAC, '2026-01', '2026-01-01', '2026-01-31', sample_rows(), []
);
$old1 = MealsDB_Invoice_Draft::edit_field($id, '42', 'last_name', 'Zubrowski-Smith');
chk($old1, 'Zubrowski', 'T-4: first edit returns the prior (generated) value');
$after1 = MealsDB_Invoice_Draft::get($id);
chk($after1['payload']['current']['42']['last_name'], 'Zubrowski-Smith', 'T-4: current updated');
chk($after1['payload']['generated']['42']['last_name'], 'Zubrowski', 'T-4: generated baseline untouched');
chk((int) $after1['edit_count'], 1, 'T-4: edit_count bumped to 1');
// Second edit to the same field diffs against CURRENT, not generated.
$old2 = MealsDB_Invoice_Draft::edit_field($id, '42', 'last_name', 'Smith');
chk($old2, 'Zubrowski-Smith', 'T-4: second edit returns the FIRST edit value as old');
$after2 = MealsDB_Invoice_Draft::get($id);
chk((int) $after2['edit_count'], 2, 'T-4: edit_count bumped to 2');
chk($after2['payload']['generated']['42']['last_name'], 'Zubrowski', 'T-4: generated STILL untouched after 2 edits');

// ===========================================================================
// T-5: edit refused after finalize.
// ===========================================================================
fresh_wpdb();
$id = MealsDB_Invoice_Draft::create(
    MealsDB_Invoice_Draft::PIPELINE_VAC, '2026-01', '2026-01-01', '2026-01-31', sample_rows(), []
);
MealsDB_Invoice_Draft::finalize($id);
$before = MealsDB_Invoice_Draft::get($id);
$ret = MealsDB_Invoice_Draft::edit_field($id, '42', 'last_name', 'ShouldNotStick');
chk($ret, false, 'T-5: edit_field returns false on a finalized draft');
$after = MealsDB_Invoice_Draft::get($id);
chk($after['payload']['current']['42']['last_name'], $before['payload']['current']['42']['last_name'],
    'T-5: payload unchanged after refused edit');
chk((int) $after['edit_count'], (int) $before['edit_count'], 'T-5: edit_count unchanged');

// ===========================================================================
// T-6: finalize freezes the month via finalize_month + is idempotent.
// ===========================================================================
$w6 = fresh_wpdb();
$idA = MealsDB_Invoice_Draft::create(
    MealsDB_Invoice_Draft::PIPELINE_VAC, '2026-02', '2026-02-01', '2026-02-28', sample_rows(), []
);
$out = MealsDB_Invoice_Draft::finalize($idA);
chk_true($out !== null, 'T-6: finalize returns non-null output');
$draftA = MealsDB_Invoice_Draft::get($idA);
chk($draftA['status'], 'finalized', 'T-6: status → finalized');
// finalize_month called once per client (2 clients).
chk(count($w6->finalize_calls), 2, 'T-6: finalize_month fired for each client');
chk_true((int) $w6->finalize_calls[0]['client_id'] > 0, 'T-6: finalize_month carried a client_id');
chk($w6->finalize_calls[0]['billing_month'], '2026-02', 'T-6: finalize_month carried the billing_month');
// Re-finalizing the SAME draft is refused (already finalized).
chk(MealsDB_Invoice_Draft::finalize($idA), null, 'T-6: re-finalize same draft returns null');
// A SECOND draft for the SAME month finalizes without error (idempotent month lock).
$idB = MealsDB_Invoice_Draft::create(
    MealsDB_Invoice_Draft::PIPELINE_VAC, '2026-02', '2026-02-01', '2026-02-28', sample_rows(), []
);
$outB = MealsDB_Invoice_Draft::finalize($idB);
chk_true($outB !== null, 'T-6: second draft for an already-finalized month finalizes without throwing');
chk(MealsDB_Invoice_Draft::get($idB)['status'], 'finalized', 'T-6: second draft status → finalized');

// T-6b (PR #390 review): lost finalize race — get() sees 'draft' but the
// guarded UPDATE matches 0 rows (a concurrent request finalized first). The
// method must treat 0 affected rows as a refusal: return null and NOT claim
// the finalized artifact.
$w6b = fresh_wpdb();
$idR = MealsDB_Invoice_Draft::create(
    MealsDB_Invoice_Draft::PIPELINE_VAC, '2026-04', '2026-04-01', '2026-04-30', sample_rows(), []
);
$w6b->force_finalize_update_zero = true;
chk(MealsDB_Invoice_Draft::finalize($idR), null, 'T-6b: finalize returns null when the status UPDATE affects 0 rows');
chk(MealsDB_Invoice_Draft::get($idR)['status'], 'draft', 'T-6b: draft NOT recorded finalized after a lost race');

// ===========================================================================
// T-A4 (INV-DRAFT-3): finalize SERIALIZES `current` per pipeline, persists the
// exact bytes ENCRYPTED in finalized_output, and get_finalized_output reads
// them back. Uses the REAL MealsDB_Invoice_Generator serializers (the VAC PDF
// is best-effort and absent here — no dompdf — so only the CSV is captured).
// ===========================================================================
$wA  = fresh_wpdb();
$idF = MealsDB_Invoice_Draft::create(
    MealsDB_Invoice_Draft::PIPELINE_VAC, '2026-05', '2026-05-01', '2026-05-31', sample_rows(), []
);
$outF = MealsDB_Invoice_Draft::finalize($idF);
chk_true(is_array($outF) && ($outF['pipeline'] ?? '') === 'vac', 'T-A4: finalize returns the structured VAC output map');
chk_true(isset($outF['files']['csv']['content']) && is_string($outF['files']['csv']['content']),
    'T-A4: finalize output carries the VAC CSV string');
// Persisted AND encrypted at rest (no cleartext leaks into the column).
$stored_out = (string) ($wA->drafts[$idF]['finalized_output'] ?? '');
chk_true($stored_out !== '', 'T-A4: finalized_output column populated');
chk(strpos($stored_out, 'K#'), false, 'T-A4: finalized_output encrypted (CSV header not cleartext)');
chk(strpos($stored_out, 'Zubrowski'), false, 'T-A4: finalized_output encrypted (client name not cleartext)');
// get_finalized_output decrypts back to the structured map.
$gotF = MealsDB_Invoice_Draft::get_finalized_output($idF);
chk_true(is_array($gotF) && isset($gotF['files']['csv']['content']), 'T-A4: get_finalized_output decrypts the artifact');
chk($gotF['files']['csv']['filename'], 'vac-2026-05.csv', 'T-A4: VAC CSV filename derived from billing month');

// ===========================================================================
// T-A5 (INV-DRAFT-3): the download getter requires a FINALIZED draft —
// a draft-status draft (and an unknown id) yield null (download fails closed).
// ===========================================================================
$wB  = fresh_wpdb();
$idD = MealsDB_Invoice_Draft::create(
    MealsDB_Invoice_Draft::PIPELINE_SDNB_NEW, '2026-06', '2026-06-01', '2026-06-30', sample_rows(), []
);
chk(MealsDB_Invoice_Draft::get_finalized_output($idD), null, 'T-A5: draft-status draft has no downloadable output');
MealsDB_Invoice_Draft::finalize($idD);
$gotD = MealsDB_Invoice_Draft::get_finalized_output($idD);
chk_true(is_array($gotD) && isset($gotD['files']['csv']), 'T-A5: finalized draft yields a downloadable CSV');
chk(MealsDB_Invoice_Draft::get_finalized_output(999999), null, 'T-A5: unknown draft id → null');

// ===========================================================================
// T-A6 (PR #393 review): a serialization failure must NOT freeze the months.
// The freeze is a one-way LB-3 lock; if finalize froze first and then failed to
// produce the artifact, the draft would be left editable but its month locked
// (un-rebuildable) with NO finalized invoice. We simulate the failure with a
// draft whose pipeline serialize_current() can't handle (unknown pipeline),
// and assert: finalize returns null, status stays 'draft', and finalize_month
// fired ZERO times.
// ===========================================================================
$wC  = fresh_wpdb();
$idX = MealsDB_Invoice_Draft::create(
    MealsDB_Invoice_Draft::PIPELINE_VAC, '2026-07', '2026-07-01', '2026-07-31', sample_rows(), []
);
// Corrupt the stored pipeline so serialize_current() returns null AFTER get()
// successfully decrypts the (validly-encrypted) payload.
$wC->drafts[$idX]['pipeline'] = 'no_such_pipeline';
chk(MealsDB_Invoice_Draft::finalize($idX), null, 'T-A6: finalize returns null when serialization fails');
chk(MealsDB_Invoice_Draft::get($idX)['status'], 'draft', 'T-A6: draft stays editable after a serialize failure');
chk(count($wC->finalize_calls), 0, 'T-A6: months NOT frozen when the artifact could not be produced');

// ===========================================================================
// T-10 (directive INV-2): unfinalize reverses finalize — clears the per-client
// finalized locks (unfinalize_month fires per client), restores the draft to
// editable 'draft' (clearing finalized_by/at/output), audits WITH a reason, and
// re-enables edits. Only a FINALIZED draft can be un-finalized.
// ===========================================================================
$w10 = fresh_wpdb();
$id10 = MealsDB_Invoice_Draft::create(
    MealsDB_Invoice_Draft::PIPELINE_VAC, '2026-08', '2026-08-01', '2026-08-31', sample_rows(), []
);
// A draft-status draft cannot be un-finalized.
chk(MealsDB_Invoice_Draft::unfinalize($id10, 'too soon'), false, 'T-10: unfinalize refuses a draft-status draft');
// Finalize, then un-finalize.
MealsDB_Invoice_Draft::finalize($id10);
chk(MealsDB_Invoice_Draft::get($id10)['status'], 'finalized', 'T-10: precondition — draft finalized');
$calls_after_finalize = count($w10->finalize_calls); // 2 (one per client)
$ok10 = MealsDB_Invoice_Draft::unfinalize($id10, 'setup error: zero products');
chk($ok10, true, 'T-10: unfinalize returns true on a finalized draft');
$d10 = MealsDB_Invoice_Draft::get($id10);
chk($d10['status'], 'draft', 'T-10: status restored to draft');
chk($w10->drafts[$id10]['finalized_output'], null, 'T-10: finalized_output cleared');
chk($w10->drafts[$id10]['finalized_at'], null, 'T-10: finalized_at cleared');
chk($w10->drafts[$id10]['finalized_by'], null, 'T-10: finalized_by cleared');
// unfinalize_month fired once per client (same client set as finalize).
chk(count($w10->finalize_calls) - $calls_after_finalize, 2, 'T-10: unfinalize_month fired per client');
// The restored draft is editable again.
$old10 = MealsDB_Invoice_Draft::edit_field($id10, '42', 'last_name', 'Edited-After-Unfinalize');
chk_true($old10 !== false, 'T-10: edits re-enabled after unfinalize');
// Re-unfinalizing a now-draft draft is refused (idempotency guard).
chk(MealsDB_Invoice_Draft::unfinalize($id10, 'again'), false, 'T-10: unfinalize refuses an already-draft draft');
// Unknown draft id → false (never throws).
chk(MealsDB_Invoice_Draft::unfinalize(999999, 'nope'), false, 'T-10: unknown draft id → false');

// ===========================================================================
// T-7: shared-helper parity (QW-2) — round-trip + legacy plaintext JSON.
// ===========================================================================
$arr = ['a' => 1, 'b' => ['c' => 'déjà vu', 'd' => [1, 2, 3]]];
$enc = MealsDB_Encryption::encode_payload($arr);
chk_true(is_string($enc) && $enc !== '', 'T-7: encode_payload returns a string');
chk(strpos($enc, 'déjà'), false, 'T-7: encoded payload does not contain cleartext');
chk(MealsDB_Encryption::decode_payload($enc), $arr, 'T-7: decode_payload round-trips the array');
// Legacy plaintext JSON (client-form drafts written before encryption) still reads.
$legacy = json_encode(['first_name' => 'Legacy', 'last_name' => 'Plaintext']);
chk(MealsDB_Encryption::decode_payload($legacy),
    ['first_name' => 'Legacy', 'last_name' => 'Plaintext'],
    'T-7: decode_payload still reads legacy plaintext JSON');
chk(MealsDB_Encryption::decode_payload(''), null, 'T-7: empty string decodes to null');
// Client-form delegate still works (no regression to its draft helpers).
chk(MealsDB_Client_Form::decode_draft_payload($legacy),
    ['first_name' => 'Legacy', 'last_name' => 'Plaintext'],
    'T-7: client-form decode_draft_payload delegates correctly');

// ===========================================================================
// T-8: list() returns meta only (no payload / no PII).
// ===========================================================================
fresh_wpdb();
$id = MealsDB_Invoice_Draft::create(
    MealsDB_Invoice_Draft::PIPELINE_SDNB_LEGACY, '2026-03', '2026-03-01', '2026-03-31', sample_rows(), ['zone' => 'M']
);
$listed = MealsDB_Invoice_Draft::list();
chk(count($listed), 1, 'T-8: list returns one row');
chk_true(!array_key_exists('payload', $listed[0]), 'T-8: list row has NO payload key');
$blob = json_encode($listed[0]);
chk(strpos($blob, 'Zubrowski'), false, 'T-8: list output contains no client name');
chk($listed[0]['pipeline'], 'sdnb_legacy', 'T-8: list exposes pipeline meta');
chk((int) $listed[0]['row_count'], 2, 'T-8: list exposes row_count meta');

// ===========================================================================
// T-9: unknown pipeline rejected.
// ===========================================================================
$w9 = fresh_wpdb();
chk(MealsDB_Invoice_Draft::create('bogus', '2026-01', '2026-01-01', '2026-01-31', sample_rows(), []),
    0, 'T-9: unknown pipeline returns 0');
chk(count($w9->drafts), 0, 'T-9: no row written for unknown pipeline');

// ---------------------------------------------------------------------------
echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
