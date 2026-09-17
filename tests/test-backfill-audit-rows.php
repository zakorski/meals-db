<?php
/**
 * Backfill for the order-audit storage normalization: migrate legacy encrypted
 * payload blobs into normalized meals_order_audit_rows.
 *
 * Asserts: dry-run writes nothing but counts; a real run materialises one row
 * per order; finalized audits migrate byte-faithfully (frozen edited state
 * reproduced); the backfill is idempotent (a second run skips); empty and
 * undecodable audits are classified, not miscounted.
 *
 * Run: php tests/test-backfill-audit-rows.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('MEALS_DB_KEY')) { define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32))); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__'))             { function __($t, $d = 'default') { return $t; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 7; } }

if (!class_exists('wpdb')) { class wpdb {} }
class BFWpdb extends wpdb {
    public $prefix = 'wp_';
    public array $audits = [];      // audit_id => header row
    public array $audit_rows = [];  // row_id => per-order row
    private $next_row_id = 1;

    private static function is_rows_table($s): bool { return stripos((string) $s, 'order_audit_rows') !== false; }

    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        foreach ($args as $a) {
            $repl = is_int($a) || is_float($a) ? (string) $a : "'" . addslashes((string) $a) . "'";
            $sql = preg_replace('/%[sdf]/', str_replace('$', '\\$', $repl), $sql, 1);
        }
        return $sql;
    }
    public function query($sql) { return 1; } // audit_log writes, ignored here
    public function get_col($sql) {
        $ids = array_keys($this->audits);
        sort($ids);
        return array_map('strval', $ids);
    }
    public function get_var($sql) {
        if (self::is_rows_table($sql) && preg_match('/audit_id = (\\d+)/', (string) $sql, $m)) {
            $aid = (int) $m[1]; $n = 0;
            foreach ($this->audit_rows as $r) { if ((int) ($r['audit_id'] ?? 0) === $aid) { $n++; } }
            return $n;
        }
        return 0;
    }
    public function get_row($sql, $o = ARRAY_A) {
        if (preg_match('/audit_id = (\\d+)/', (string) $sql, $m)) { return $this->audits[(int) $m[1]] ?? null; }
        return null;
    }
    public function insert($table, $data, $formats = null) {
        if (stripos($table, 'audit_log') !== false || stripos($table, 'event_log') !== false) { return 1; }
        if (self::is_rows_table($table)) {
            $rid = $this->next_row_id++;
            $this->audit_rows[$rid] = array_merge(['row_id' => $rid], $data);
            return 1;
        }
        return 1;
    }
    public function delete($table, $where, $f = null) {
        if (self::is_rows_table($table)) {
            $aid = (int) ($where['audit_id'] ?? 0);
            foreach ($this->audit_rows as $rid => $r) {
                if ((int) ($r['audit_id'] ?? 0) === $aid) { unset($this->audit_rows[$rid]); }
            }
        }
        return 1;
    }
    public function get_results($sql, $o = ARRAY_A) {
        if (self::is_rows_table($sql) && preg_match('/audit_id = (\\d+)/', (string) $sql, $m)) {
            $aid = (int) $m[1]; $out = [];
            foreach ($this->audit_rows as $r) { if ((int) ($r['audit_id'] ?? 0) === $aid) { $out[] = $r; } }
            usort($out, static fn($a, $b) => (int) $a['row_id'] <=> (int) $b['row_id']);
            return $out;
        }
        return [];
    }
}

$failures = []; $passed = 0;
function bf_chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = 'FAIL: ' . $label; }
}

// A legacy audit header holding an encrypted payload blob (as written before
// the refactor). One confirmed order + one edited order → byte-faithful target.
function bf_entry(int $oid, string $name, string $status, array $edited = [], string $note = ''): array {
    return [
        'order_id' => $oid, 'wp_user_id' => $oid + 100, 'client_id' => $oid,
        'client_name' => $name, 'client_last_name' => 'X', 'zone' => 'M',
        'delivery_date' => '2026-07-22',
        'items' => [['item_key' => 1, 'product_name' => 'Beef', 'sku' => 'B1', 'qty' => 2]],
        'mains_count' => 2, 'sides_count' => 0,
        'audit_status' => $status, 'edited_items' => $edited, 'added_items' => [],
        'note' => $note, 'audited_by' => 7, 'audited_at' => '2026-07-25 12:00:00',
    ];
}
function bf_seed_legacy(BFWpdb $wpdb, int $aid, string $status, array $current, int $row_count): void {
    $wpdb->audits[$aid] = [
        'audit_id' => $aid, 'week_start' => '2026-07-20', 'week_end' => '2026-07-26',
        'status' => $status, 'row_count' => $row_count,
        'payload' => MealsDB_Encryption::encode_payload(
            ['schema' => 1, 'generated' => $current, 'current' => $current]
        ),
    ];
}

// ---------------------------------------------------------------------------
$wpdb = new BFWpdb(); $GLOBALS['wpdb'] = $wpdb;
$finalized_current = [
    801 => bf_entry(801, 'Alpha', 'confirmed'),
    802 => bf_entry(802, 'Bravo', 'edited', [1 => 1], 'one short'),
];
bf_seed_legacy($wpdb, 1, 'finalized', $finalized_current, 2);
bf_seed_legacy($wpdb, 2, 'draft', [901 => bf_entry(901, 'Charlie', 'pending')], 1);
// Empty audit (0 orders) and an undecodable one.
$wpdb->audits[3] = ['audit_id' => 3, 'week_start' => '2026-06-01', 'week_end' => '2026-06-07',
    'status' => 'draft', 'row_count' => 0, 'payload' => ''];
$wpdb->audits[4] = ['audit_id' => 4, 'week_start' => '2026-05-01', 'week_end' => '2026-05-07',
    'status' => 'draft', 'row_count' => 1, 'payload' => 'not-decodable-ciphertext'];

// 1. Dry-run counts but writes nothing.
$stats = MealsDB_Backfill_Audit_Rows::run(true);
bf_chk($stats['dry_run'] === true, '1: dry-run flagged');
bf_chk($stats['audits'] === 4, '1: all four audits visited');
bf_chk($stats['migrated'] === 2, '1: two migratable audits (1 finalized, 1 draft)');
bf_chk($stats['rows'] === 3, '1: three rows would be written (2 + 1)');
bf_chk($stats['empty'] === 1 && $stats['undecodable'] === 1, '1: empty + undecodable classified');
bf_chk(count($wpdb->audit_rows) === 0, '1: dry-run wrote no rows');

// 2. Real run materialises the rows.
$stats = MealsDB_Backfill_Audit_Rows::run(false);
bf_chk($stats['migrated'] === 2 && $stats['rows'] === 3, '2: real run migrates 2 audits / 3 rows');
bf_chk(count($wpdb->audit_rows) === 3, '2: three rows written');

// 3. Byte-faithful: the finalized audit reconstructs its exact frozen state.
$a = MealsDB_Order_Audit::get(1);
bf_chk($a !== null && $a['status'] === 'finalized', '3: finalized audit still finalized');
bf_chk(($a['payload']['current'][801]['audit_status'] ?? '') === 'confirmed', '3: confirmed row preserved');
bf_chk(($a['payload']['current'][802]['audit_status'] ?? '') === 'edited', '3: edited row preserved');
bf_chk(($a['payload']['current'][802]['edited_items'] ?? []) === [1 => 1], '3: edited quantities preserved (byte-faithful)');
bf_chk(($a['payload']['current'][802]['note'] ?? '') === 'one short', '3: note preserved');
bf_chk(($a['payload']['current'][801]['client_name'] ?? '') === 'Alpha', '3: PII round-trips via detail_enc');

// 4. Idempotent: a second run skips the already-migrated audits.
$stats = MealsDB_Backfill_Audit_Rows::run(false);
bf_chk($stats['skipped'] === 2 && $stats['migrated'] === 0, '4: second run skips already-migrated audits');
bf_chk(count($wpdb->audit_rows) === 3, '4: no duplicate rows on re-run');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
