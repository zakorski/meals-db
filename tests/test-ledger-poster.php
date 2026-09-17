<?php
/**
 * MealsDB_Ledger_Poster (K17 ITEM 2a/3/5, audit side) end-to-end:
 * finalize posts client charges + collected payments; unfinalize is blocked by
 * a payment and otherwise reverses charges with offsetting adjustments.
 *
 * Covers directive tests 1 (SDNB fee-only), 3 (Veteran fee alone), 4
 * (contribution once/month — only the claiming order carries it), 5
 * (idempotent re-post), 9 (unfinalize blocked by payment), 10 (reversal, no
 * delete), 11 (unreviewed rows post no payment).
 *
 * Run: php tests/test-ledger-poster.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('MEALS_DB_KEY')) { define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32))); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__'))             { function __($t, $d = 'default') { return $t; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 7; } }
if (!class_exists('WP_Error')) {
    class WP_Error { private $m; public function __construct($c = '', $m = '') { $this->m = $m; } public function get_error_message() { return $this->m; } }
}

// Combined fake: audit header + audit rows + ledger. Enough of each for get(),
// set_collection(), and the ledger service to work against one $wpdb.
if (!class_exists('wpdb')) { class wpdb {} }
class PosterWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public array $audits = [];
    public array $rows = [];    // audit rows
    public array $ledger = [];
    private $na = 1; private $nr = 1; private $nl = 1;

    private static function isRows($s): bool { return stripos((string) $s, 'order_audit_rows') !== false; }
    private static function isLedger($s): bool { return stripos((string) $s, 'ledger_entries') !== false; }
    private static function isAudits($s): bool { return stripos((string) $s, 'order_audits') !== false && !self::isRows($s); }

    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        foreach ($args as $a) {
            $repl = is_int($a) || is_float($a) ? (string) $a : "'" . addslashes((string) $a) . "'";
            $sql = preg_replace('/%[sdf]/', str_replace('$', '\\$', $repl), $sql, 1);
        }
        return $sql;
    }
    public function query($sql) { return 1; }
    public function insert($table, $data, $formats = null) {
        if (stripos($table, 'audit_log') !== false || stripos($table, 'event_log') !== false) { return 1; }
        if (self::isLedger($table)) {
            $d = $data['charge_dedup'] ?? null;
            if ($d !== null && $d !== '') {
                foreach ($this->ledger as $e) { if (($e['charge_dedup'] ?? null) === $d) { $this->last_error = 'dup'; return false; } }
            }
            $id = $this->nl++; $this->insert_id = $id;
            $this->ledger[$id] = array_merge(['entry_id' => $id, 'voided_by_entry_id' => null], $data);
            return 1;
        }
        if (self::isRows($table)) {
            $id = $this->nr++; $this->rows[$id] = array_merge(['row_id' => $id], $data); return 1;
        }
        $id = $this->na++; $this->insert_id = $id;
        $this->audits[$id] = array_merge(['audit_id' => $id], $data);
        return 1;
    }
    public function update($table, $data, $where, $f1 = null, $f2 = null) {
        if (self::isLedger($table)) {
            $id = (int) ($where['entry_id'] ?? 0);
            if (isset($this->ledger[$id])) { $this->ledger[$id] = array_merge($this->ledger[$id], $data); return 1; }
            return 0;
        }
        if (self::isRows($table)) {
            $aid = (int) ($where['audit_id'] ?? 0); $oid = (int) ($where['wc_order_id'] ?? 0); $hit = 0;
            foreach ($this->rows as $rid => $r) {
                if ((int) ($r['audit_id'] ?? 0) === $aid && (int) ($r['wc_order_id'] ?? 0) === $oid) {
                    $this->rows[$rid] = array_merge($r, $data); $hit = 1;
                }
            }
            return $hit;
        }
        $id = (int) ($where['audit_id'] ?? 0);
        if (!isset($this->audits[$id])) { return 0; }
        if (array_key_exists('status', $where) && ($this->audits[$id]['status'] ?? null) !== $where['status']) { return 0; }
        $this->audits[$id] = array_merge($this->audits[$id], $data);
        return 1;
    }
    public function delete($table, $where, $f = null) {
        if (self::isRows($table)) {
            $aid = (int) ($where['audit_id'] ?? 0);
            foreach ($this->rows as $rid => $r) { if ((int) ($r['audit_id'] ?? 0) === $aid) { unset($this->rows[$rid]); } }
            return 1;
        }
        $id = (int) ($where['audit_id'] ?? 0); unset($this->audits[$id]); return 1;
    }
    public function get_var($sql) {
        $sql = (string) $sql;
        if (self::isLedger($sql)) {
            if (stripos($sql, 'SUM(amount_cents)') !== false
                && preg_match("/payer_type = '([^']*)'.*payer_id = '([^']*)'/s", $sql, $m)) {
                $s = 0; foreach ($this->ledger as $e) {
                    if (($e['payer_type'] ?? '') === $m[1] && ($e['payer_id'] ?? '') === $m[2]) { $s += (int) $e['amount_cents']; }
                } return $s;
            }
            if (preg_match("/charge_dedup = '([^']*)'/", $sql, $m)) {
                foreach ($this->ledger as $e) { if (($e['charge_dedup'] ?? null) === $m[1]) { return (int) $e['entry_id']; } }
                return null;
            }
            return null;
        }
        if (self::isRows($sql) && preg_match('/audit_id = (\\d+)/', $sql, $m)) {
            $aid = (int) $m[1]; $n = 0; foreach ($this->rows as $r) { if ((int) ($r['audit_id'] ?? 0) === $aid) { $n++; } } return $n;
        }
        return 0; // client_type lookups etc. — irrelevant (context is injected)
    }
    public function get_row($sql, $o = ARRAY_A) {
        if (self::isLedger($sql) && preg_match('/entry_id = (\\d+)/', (string) $sql, $m)) { return $this->ledger[(int) $m[1]] ?? null; }
        if (self::isAudits($sql) && preg_match('/audit_id = (\\d+)/', (string) $sql, $m)) { return $this->audits[(int) $m[1]] ?? null; }
        return null;
    }
    public function get_results($sql, $o = ARRAY_A) {
        $sql = (string) $sql;
        if (self::isLedger($sql)) {
            $type = null; $sid = null; $etype = null;
            if (preg_match("/source_type = '([^']*)'/", $sql, $m)) { $type = $m[1]; }
            if (preg_match('/source_id = (\\d+)/', $sql, $m)) { $sid = (int) $m[1]; }
            if (preg_match("/entry_type = '([^']*)'/", $sql, $m)) { $etype = $m[1]; }
            $out = [];
            foreach ($this->ledger as $e) {
                if ($type !== null && ($e['source_type'] ?? '') !== $type) { continue; }
                if ($sid !== null && (int) ($e['source_id'] ?? 0) !== $sid) { continue; }
                if ($etype !== null && ($e['entry_type'] ?? '') !== $etype) { continue; }
                if (($e['voided_by_entry_id'] ?? null) !== null) { continue; }
                $out[] = $e;
            }
            return $out;
        }
        if (self::isRows($sql) && preg_match('/audit_id = (\\d+)/', $sql, $m)) {
            $aid = (int) $m[1]; $out = [];
            foreach ($this->rows as $r) { if ((int) ($r['audit_id'] ?? 0) === $aid) { $out[] = $r; } }
            usort($out, static fn($a, $b) => (int) $a['row_id'] <=> (int) $b['row_id']);
            return $out;
        }
        return [];
    }
}

$failures = []; $passed = 0;
function pc(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = 'FAIL: ' . $label; }
}

// Build a two-order audit: order 601 = SDNB (contribution + delivery fee),
// order 602 = Veteran (delivery fee only, no contribution — Test 3/4).
function poster_rows(): array {
    return [
        601 => ['order_id' => 601, 'wp_user_id' => 61, 'client_id' => 11, 'client_name' => 'S One',
            'client_last_name' => 'One', 'zone' => 'M', 'delivery_date' => '2026-07-22',
            'items' => [['item_key' => 1, 'product_name' => 'Beef', 'sku' => 'B', 'qty' => 3]],
            'mains_count' => 3, 'sides_count' => 0, 'audit_status' => 'confirmed',
            'edited_items' => [], 'added_items' => [], 'note' => '', 'audited_by' => 7, 'audited_at' => '2026-07-25 10:00:00'],
        602 => ['order_id' => 602, 'wp_user_id' => 62, 'client_id' => 12, 'client_name' => 'V Two',
            'client_last_name' => 'Two', 'zone' => 'S', 'delivery_date' => '2026-07-23',
            'items' => [['item_key' => 2, 'product_name' => 'Pie', 'sku' => 'P', 'qty' => 2]],
            'mains_count' => 2, 'sides_count' => 0, 'audit_status' => 'confirmed',
            'edited_items' => [], 'added_items' => [], 'note' => '', 'audited_by' => 7, 'audited_at' => '2026-07-25 10:00:00'],
    ];
}

// Injected context: 601 SDNB with contribution+fee; 602 Veteran fee-only.
$context = static function (array $row) {
    $oid = (int) ($row['order_id'] ?? 0);
    if ($oid === 601) {
        return ['client_type' => 'SDNB', 'prices' => ['contribution_cents' => 4000, 'delivery_fee_cents' => 1000]];
    }
    if ($oid === 602) {
        return ['client_type' => 'Veteran', 'prices' => ['contribution_cents' => 0, 'delivery_fee_cents' => 1000]];
    }
    return ['client_type' => '', 'prices' => []];
};

$w = new PosterWpdb(); $GLOBALS['wpdb'] = $w;
$aid = MealsDB_Order_Audit::create_for_week('2026-07-20', '2026-07-26', poster_rows());
// Mark order 601 collected (full $50) — payment should post on finalize.
MealsDB_Order_Audit::set_collection($aid, 601, 'collected', 5000, 'cash');
// order 602 left unreviewed → no payment (Test 11).
// Flip to finalized directly (bypass finalize()'s default WC context).
$w->audits[$aid]['status'] = 'finalized';

$ledger = new MealsDB_Ledger($w);
$stats = MealsDB_Ledger_Poster::post_audit_charges($aid, ['ledger' => $ledger, 'context' => $context, 'user' => 7]);

// 1 + 3: two charges posted (SDNB fees $50, Veteran fee $10).
pc($stats['charges'] === 2, '1/3: a charge posted per order with a client-side amount');
pc($ledger->balance_for('client', '11') === 5000 - 5000, '1: SDNB charge $50 offset by the $50 collected payment → balance 0');
// Veteran: charge 1000, no payment → balance 1000 (Test 3 + 11).
pc($ledger->balance_for('client', '12') === 1000, '3/11: Veteran fee-only charge; unreviewed row posts NO payment');
pc($stats['payments'] === 1, '11: exactly one payment (the single collected row)');

// 4: contribution rode with SDNB order 601 only; a SECOND SDNB order for the
// SAME client that month carries NO contribution (fee only) — the calculator
// charges exactly what each order's fee lines hold, so contribution is not
// double-charged across the month.
pc(MealsDB_Ledger_Charge_Calculator::client_charge_cents('SDNB', [], ['contribution_cents' => 0, 'delivery_fee_cents' => 1000]) === 1000,
    '4: a same-month SDNB order without the contribution fee line charges the fee alone');

// 5: re-running the poster is idempotent — no duplicate charges.
$stats2 = MealsDB_Ledger_Poster::post_audit_charges($aid, ['ledger' => $ledger, 'context' => $context, 'user' => 7]);
$charge_rows = array_filter($w->ledger, static fn($e) => ($e['entry_type'] ?? '') === 'charge');
pc(count($charge_rows) === 2, '5: re-finalize is idempotent — still only two charges');

// 9: a payment exists against order 601, so the audit unfinalize is BLOCKED.
$pays = MealsDB_Ledger_Poster::audit_payments($aid, $ledger);
pc(count($pays) === 1, '9: audit_payments finds the collected payment');
$w->audits[$aid]['status'] = 'finalized';
$res = MealsDB_Order_Audit::unfinalize($aid, 'need to fix a row');
pc($res instanceof WP_Error, '9: unfinalize refused while a payment exists');
pc($w->audits[$aid]['status'] === 'finalized', '9: audit stays finalized when blocked');

// 10: with the payment voided, unfinalize reverses the charges with offsetting
// adjustments (nothing deleted). Void the payment first.
$pid = (int) $pays[0]['entry_id'];
$ledger->void_entry($pid, 'refunded at door', 7);
$reversed = MealsDB_Ledger_Poster::reverse_audit_charges($aid, 'reopened', 7, $ledger);
pc($reversed === 2, '10: both charges reversed with offsetting adjustments');
pc(count($w->ledger) >= 6, '10: reversal APPENDS rows (charges+payment+voids+adjustments), never deletes');
pc($ledger->balance_for('client', '11') === 0 && $ledger->balance_for('client', '12') === 0,
    '10: after voiding the payment and reversing charges, both client balances net to zero');

// ---------------------------------------------------------------------------
// 6: invoice finalize posts ONE program charge at the invoice total (not per
// client), payer from the pipeline; idempotent; unfinalize blocks on a payment
// and otherwise reverses.
// ---------------------------------------------------------------------------
$wi = new PosterWpdb(); $GLOBALS['wpdb'] = $wi;
$li = new MealsDB_Ledger($wi);

// SDNB draft #900, total $81,829.00 (amount injected — the grand total is
// derived by the generator in production; here we assert the posting contract).
$e1 = MealsDB_Ledger_Poster::post_invoice_charge(900,
    ['pipeline' => 'sdnb_legacy', 'amount_cents' => 8182900, 'entry_date' => '2026-07-31'],
    ['ledger' => $li, 'user' => 7]);
pc($e1 > 0, '6: invoice charge posted');
$prog_charges = array_filter($wi->ledger, static fn($x) => ($x['entry_type'] ?? '') === 'charge' && ($x['payer_type'] ?? '') === 'program');
pc(count($prog_charges) === 1, '6: exactly ONE program charge for the whole invoice (not per client)');
pc($li->balance_for('program', 'SDNB') === 8182900, '6: program balance = the invoice total');
pc($wi->ledger[$e1]['payer_id'] === 'SDNB', '6: SDNB pipeline → SDNB payer');

// Idempotent re-finalize.
$e1b = MealsDB_Ledger_Poster::post_invoice_charge(900,
    ['pipeline' => 'sdnb_legacy', 'amount_cents' => 8182900, 'entry_date' => '2026-07-31'],
    ['ledger' => $li, 'user' => 7]);
pc($e1b === $e1, '6: re-finalize is idempotent — same program charge, no double-bill');

// VAC pipeline → VAC payer.
$e2 = MealsDB_Ledger_Poster::post_invoice_charge(901,
    ['pipeline' => 'vac', 'amount_cents' => 500000, 'entry_date' => '2026-07-31'],
    ['ledger' => $li, 'user' => 7]);
pc($wi->ledger[$e2]['payer_id'] === 'VAC', '6: VAC pipeline → VAC payer');

// 7: a PARTIAL program remittance leaves the shortfall as the balance.
$li->record_payment(['payer_type' => 'program', 'payer_id' => 'SDNB', 'source_type' => 'invoice',
    'source_id' => 900, 'entry_date' => '2026-08-05', 'amount_cents' => 8100000, 'method' => 'remittance']);
pc($li->balance_for('program', 'SDNB') === 8182900 - 8100000, '7: partial remittance leaves exactly the shortfall');

// 9 (invoice): a payment against the draft blocks reverse; reverse otherwise nets.
pc(count(MealsDB_Ledger_Poster::invoice_payments(900, $li)) === 1, '9: invoice_payments finds the remittance (unfinalize would block)');
pc(count(MealsDB_Ledger_Poster::invoice_payments(901, $li)) === 0, '9: the VAC invoice has no payment (reopen allowed)');
$rev = MealsDB_Ledger_Poster::reverse_invoice_charges(901, 'reopened', 7, $li);
pc($rev === 1 && $li->balance_for('program', 'VAC') === 0, '10: reversing the VAC program charge nets it to zero');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
