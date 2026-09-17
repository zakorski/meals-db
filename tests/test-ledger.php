<?php
/**
 * MealsDB_Ledger service (receivables ledger, K17 ITEM 1/5).
 *
 * Append-only, immutable financial events: charge (+cents) / payment (-cents) /
 * adjustment. Balance for a payer = SUM(amount_cents). Charges are idempotent
 * per (source_type, source_id, payer_type, payer_id) so a repeated finalize
 * never double-bills. Corrections are new offsetting rows + a voided_by link on
 * the original — never edits or deletes.
 *
 * Run: php tests/test-ledger.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __($t, $d = 'default') { return $t; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 7; } }

// In-memory ledger table. Enforces UNIQUE(charge_dedup) for non-null values and
// interprets the handful of SELECT shapes the service emits.
if (!class_exists('wpdb')) { class wpdb {} }
class LedgerWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public array $entries = []; // entry_id => row
    private $next_id = 1;

    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        foreach ($args as $a) {
            $repl = is_int($a) || is_float($a) ? (string) $a : "'" . addslashes((string) $a) . "'";
            $sql = preg_replace('/%[sdf]/', str_replace('$', '\\$', $repl), $sql, 1);
        }
        return $sql;
    }
    public function query($sql) { return 1; } // MealsDB_Logger::log() path, ignored here
    public function insert($table, $data, $formats = null) {
        // Enforce UNIQUE(charge_dedup) for non-null dedup values.
        $dedup = $data['charge_dedup'] ?? null;
        if ($dedup !== null && $dedup !== '') {
            foreach ($this->entries as $e) {
                if (($e['charge_dedup'] ?? null) === $dedup) { $this->last_error = 'Duplicate entry'; return false; }
            }
        }
        $id = $this->next_id++;
        $this->insert_id = $id;
        $this->entries[$id] = array_merge(['entry_id' => $id, 'voided_by_entry_id' => null], $data);
        return 1;
    }
    public function update($table, $data, $where, $f1 = null, $f2 = null) {
        $id = (int) ($where['entry_id'] ?? 0);
        if (!isset($this->entries[$id])) { return 0; }
        $this->entries[$id] = array_merge($this->entries[$id], $data);
        return 1;
    }
    public function get_var($sql) {
        $sql = (string) $sql;
        // Balance: SUM(amount_cents) for a payer.
        if (stripos($sql, 'SUM(amount_cents)') !== false
            && preg_match("/payer_type = '([^']*)'.*payer_id = '([^']*)'/s", $sql, $m)) {
            $sum = 0;
            foreach ($this->entries as $e) {
                if (($e['payer_type'] ?? '') === $m[1] && ($e['payer_id'] ?? '') === $m[2]) {
                    $sum += (int) $e['amount_cents'];
                }
            }
            return $sum;
        }
        // Charge existence by dedup key.
        if (preg_match("/charge_dedup = '([^']*)'/", $sql, $m)) {
            foreach ($this->entries as $e) {
                if (($e['charge_dedup'] ?? null) === $m[1]) { return (int) $e['entry_id']; }
            }
            return null;
        }
        return null;
    }
    public function get_row($sql, $o = ARRAY_A) {
        if (preg_match('/entry_id = (\\d+)/', (string) $sql, $m)) {
            return $this->entries[(int) $m[1]] ?? null;
        }
        return null;
    }
    public function get_results($sql, $o = ARRAY_A) {
        $sql = (string) $sql;
        $type = null; $sid = null; $etype = null; $nonvoided = false;
        if (preg_match("/source_type = '([^']*)'/", $sql, $m)) { $type = $m[1]; }
        if (preg_match('/source_id = (\\d+)/', $sql, $m)) { $sid = (int) $m[1]; }
        if (preg_match("/entry_type = '([^']*)'/", $sql, $m)) { $etype = $m[1]; }
        if (stripos($sql, 'voided_by_entry_id IS NULL') !== false) { $nonvoided = true; }
        $out = [];
        foreach ($this->entries as $e) {
            if ($type !== null && ($e['source_type'] ?? '') !== $type) { continue; }
            if ($sid !== null && (int) ($e['source_id'] ?? 0) !== $sid) { continue; }
            if ($etype !== null && ($e['entry_type'] ?? '') !== $etype) { continue; }
            if ($nonvoided && ($e['voided_by_entry_id'] ?? null) !== null) { continue; }
            $out[] = $e;
        }
        return $out;
    }
}

$failures = []; $passed = 0;
function lc(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = 'FAIL: ' . $label; }
}
function lreset(): LedgerWpdb { $w = new LedgerWpdb(); $GLOBALS['wpdb'] = $w; return $w; }

// ---------------------------------------------------------------------------
$w = lreset();
$ledger = new MealsDB_Ledger($w);

// 1. Charge posts a positive, dedup-keyed row.
$c1 = $ledger->post_charge([
    'payer_type' => 'client', 'payer_id' => '42', 'source_type' => 'order', 'source_id' => 501,
    'entry_date' => '2026-07-22', 'amount_cents' => 1234, 'created_by' => 7,
]);
lc($c1 > 0, '1: charge returns entry_id');
lc((int) $w->entries[$c1]['amount_cents'] === 1234, '1: charge amount positive as given');
lc($w->entries[$c1]['entry_type'] === 'charge', '1: entry_type charge');
lc(($w->entries[$c1]['charge_dedup'] ?? '') === 'order:501:client:42', '1: dedup key populated for charge');

// 2. IDEMPOTENT: re-posting the same source+payer charge is a no-op returning the same id.
$c1b = $ledger->post_charge([
    'payer_type' => 'client', 'payer_id' => '42', 'source_type' => 'order', 'source_id' => 501,
    'entry_date' => '2026-07-22', 'amount_cents' => 1234, 'created_by' => 7,
]);
lc($c1b === $c1 && count($w->entries) === 1, '2: re-finalize idempotent — no duplicate charge');

// 3. A zero (or negative) charge posts nothing.
$cz = $ledger->post_charge([
    'payer_type' => 'client', 'payer_id' => '99', 'source_type' => 'order', 'source_id' => 777,
    'entry_date' => '2026-07-22', 'amount_cents' => 0, 'created_by' => 7,
]);
lc($cz === 0 && count($w->entries) === 1, '3: zero-amount charge posts nothing');

// 4. Payment stores a NEGATIVE amount and is NOT deduped (partial payments allowed).
$p1 = $ledger->record_payment([
    'payer_type' => 'client', 'payer_id' => '42', 'source_type' => 'order', 'source_id' => 501,
    'entry_date' => '2026-07-22', 'amount_cents' => 1000, 'method' => 'cash', 'created_by' => 7,
]);
lc($p1 > 0 && (int) $w->entries[$p1]['amount_cents'] === -1000, '4: payment stored negative');
lc(($w->entries[$p1]['charge_dedup'] ?? null) === null, '4: payment has no dedup key');
$p2 = $ledger->record_payment([
    'payer_type' => 'client', 'payer_id' => '42', 'source_type' => 'order', 'source_id' => 501,
    'entry_date' => '2026-07-23', 'amount_cents' => 200, 'method' => 'cash', 'created_by' => 7,
]);
lc($p2 > 0 && $p2 !== $p1, '4: a second payment against the same source is allowed');

// 5. Balance = SUM (1234 - 1000 - 200 = 34).
lc($ledger->balance_for('client', '42') === 34, '5: balance = SUM(signed cents)');

// 6. Void: offsetting adjustment + voided_by link on the original; refuses double-void.
$w2 = lreset(); $ledger = new MealsDB_Ledger($w2);
$c = $ledger->post_charge(['payer_type' => 'client', 'payer_id' => '5', 'source_type' => 'order',
    'source_id' => 88, 'entry_date' => '2026-07-01', 'amount_cents' => 500, 'created_by' => 7]);
$v = $ledger->void_entry($c, 'keyed twice', 7);
lc($v > 0 && (int) $w2->entries[$v]['amount_cents'] === -500, '6: void posts inverse amount');
lc(($w2->entries[$v]['entry_type'] ?? '') === 'adjustment', '6: void is an adjustment');
lc((int) ($w2->entries[$c]['voided_by_entry_id'] ?? 0) === $v, '6: original stamped voided_by');
lc($ledger->balance_for('client', '5') === 0, '6: voided charge nets to zero');
lc($ledger->void_entry($c, 'again', 7) === 0, '6: double-void refused');

// 7. Payments-against-source (unfinalize guard) + reverse-charges (no delete).
$w3 = lreset(); $ledger = new MealsDB_Ledger($w3);
$ch = $ledger->post_charge(['payer_type' => 'program', 'payer_id' => 'SDNB', 'source_type' => 'invoice',
    'source_id' => 900, 'entry_date' => '2026-07-31', 'amount_cents' => 8182900, 'created_by' => 7]);
lc(count($ledger->payments_for_source('invoice', 900)) === 0, '7: no payments yet against the invoice');
$reversed = $ledger->reverse_charges_for_source('invoice', 900, 'unfinalized', 7);
lc($reversed === 1, '7: one charge reversed');
lc((int) ($w3->entries[$ch]['voided_by_entry_id'] ?? 0) > 0, '7: reversed charge is marked voided (not deleted)');
lc(isset($w3->entries[$ch]), '7: original charge row still exists (append-only, never deleted)');
lc($ledger->balance_for('program', 'SDNB') === 0, '7: reversal nets the program balance to zero');

// 8. payments_for_source sees a recorded payment.
$pp = $ledger->record_payment(['payer_type' => 'program', 'payer_id' => 'SDNB', 'source_type' => 'invoice',
    'source_id' => 901, 'entry_date' => '2026-08-01', 'amount_cents' => 8100000, 'method' => 'remittance', 'created_by' => 7]);
$found = $ledger->payments_for_source('invoice', 901);
lc(count($found) === 1 && (int) $found[0]['amount_cents'] === -8100000, '8: payments_for_source returns the recorded payment');

// 12. No float arithmetic anywhere in the ledger source.
$src = file_get_contents(__DIR__ . '/../includes/services/class-ledger.php');
lc(!preg_match('/\(float\)|floatval|\(double\)/', (string) $src), '12: no float casts in the ledger service');

// --- Schema shape -----------------------------------------------------------
lc(defined('MealsDB_Tables::LEDGER_ENTRIES') && MealsDB_Tables::LEDGER_ENTRIES === 'meals_ledger_entries',
    'schema: LEDGER_ENTRIES constant');
lc(in_array(MealsDB_Tables::LEDGER_ENTRIES, MealsDB_Tables::all(), true),
    'schema: LEDGER_ENTRIES in all() (install/uninstall coverage)');
$ls = MealsDB_Schema::get_table_schema(MealsDB_Tables::LEDGER_ENTRIES);
lc(is_array($ls), 'schema: ledger canonical entry exists');
$lcols = array_keys($ls['columns'] ?? []);
foreach (['entry_id', 'payer_type', 'payer_id', 'entry_type', 'source_type', 'source_id',
          'entry_date', 'amount_cents', 'method', 'reference', 'note', 'charge_dedup',
          'created_by', 'created_at', 'voided_by_entry_id'] as $c) {
    lc(in_array($c, $lcols, true), "schema: ledger column {$c}");
}
lc(stripos($ls['columns']['amount_cents'] ?? '', 'BIGINT') !== false, 'schema: amount_cents is BIGINT (integer cents, not DECIMAL)');
$has_charge_uniq = false;
foreach ($ls['indexes'] ?? [] as $i) {
    if (($i['type'] ?? '') === 'UNIQUE' && ($i['columns'] ?? []) === ['charge_dedup']) { $has_charge_uniq = true; }
}
lc($has_charge_uniq, 'schema: UNIQUE(charge_dedup) enforces one charge per source+payer');

// Collection columns landed on the audit rows table.
$rs = MealsDB_Schema::get_table_schema(MealsDB_Tables::ORDER_AUDIT_ROWS);
$rcols = array_keys($rs['columns'] ?? []);
foreach (['expected_charge_cents', 'collection_state', 'collected_amount_cents', 'collection_method'] as $c) {
    lc(in_array($c, $rcols, true), "schema: audit-row collection column {$c}");
}
lc(stripos($rs['columns']['collection_state'] ?? '', "ENUM('unreviewed','collected','outstanding')") !== false,
    'schema: collection_state ENUM is unreviewed|collected|outstanding');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
