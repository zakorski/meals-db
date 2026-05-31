<?php
/**
 * Tests for MealsDB_Encryption_Migrator::run_full_harden() — the combined
 * STR-10a/b migration pass.
 *
 * Covers directive tests T-5 (re-encrypt + re-index a legacy row, idempotent on
 * a second run) and T-6 (exact-match lookups work against v2 indexes after the
 * pass flips the format). Also asserts the circuit breaker stops a run-away.
 *
 * Uses an in-memory wpdb fake: wpdb->update takes a structured ($data, $where)
 * so no SQL parsing is needed; the SELECT cursor honours `client_id > after`
 * and LIMIT so run_full_harden() converges instead of looping.
 *
 * Run with: php tests/test-encryption-harden-migrator.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $tag, $value, ...$a) { return $value; } }

// Option store backing index_format_is_v2() / activate_index_v2().
$GLOBALS['__opts'] = [];
if (!function_exists('get_option')) {
    function get_option(string $name, $default = false) {
        return array_key_exists($name, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null): bool {
        $GLOBALS['__opts'][$name] = $value;
        return true;
    }
}
if (!function_exists('delete_transient')) { function delete_transient($k) { return true; } }
if (!function_exists('get_transient')) { function get_transient($k) { return false; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $t = 0) { return true; } }

// 32-byte master key.
$master_bytes = str_repeat("\x7e", 32);
if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode($master_bytes));
}

/**
 * Minimal in-memory wpdb. Rows are assoc arrays keyed by column name.
 */
class HardenFakeWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $rows = [];
    private array $lastArgs = [];
    public int $fail_update_for = -1; // client_id whose update() should fail (circuit-breaker test)

    public function prepare($query, ...$args) {
        // Stash the bound args so get_results() can read the [after, limit]
        // cursor that harden_encryption() binds, then emulate %d/%s so the
        // returned string is harmless if anything inspects it.
        $this->lastArgs = $args;
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $v = $args[$i] ?? '';
            $i++;
            return $m[0] === '%d' ? (string) (int) $v : "'" . $v . "'";
        }, $query);
    }

    public function get_results($query, $output = ARRAY_A) {
        $after = (int) ($this->lastArgs[0] ?? 0);
        $limit = (int) ($this->lastArgs[1] ?? PHP_INT_MAX);
        usort($this->rows, fn($a, $b) => $a['client_id'] <=> $b['client_id']);
        $out = [];
        foreach ($this->rows as $r) {
            if ((int) $r['client_id'] > $after) {
                $out[] = $r;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }

    public function update($table, $data, $where) {
        $cid = (int) ($where['client_id'] ?? 0);
        if ($cid === $this->fail_update_for) {
            $this->last_error = 'simulated update failure';
            return false;
        }
        foreach ($this->rows as &$r) {
            if ((int) $r['client_id'] === $cid) {
                foreach ($data as $k => $v) {
                    $r[$k] = $v;
                }
                return 1;
            }
        }
        return false;
    }

    public function get_col($query, $col = 0) {
        // inventory() builds: SELECT `<column>` AS v FROM ... LIMIT %d OFFSET %d
        // (column baked in via sprintf; LIMIT/OFFSET bound through prepare()).
        if (!preg_match('/SELECT `([^`]+)` AS v/i', $query, $m)) {
            return [];
        }
        $column = $m[1];
        $limit  = (int) ($this->lastArgs[0] ?? PHP_INT_MAX);
        $offset = (int) ($this->lastArgs[1] ?? 0);
        usort($this->rows, fn($a, $b) => $a['client_id'] <=> $b['client_id']);
        $slice = array_slice($this->rows, $offset, $limit);
        return array_map(fn($r) => $r[$column] ?? null, $slice);
    }

    public function query($sql) { return true; } // transactions are no-ops here

    public function find($cid) {
        foreach ($this->rows as $r) {
            if ((int) $r['client_id'] === (int) $cid) {
                return $r;
            }
        }
        return null;
    }
}

$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}
function assert_true($c, string $l) { assert_equal(true, (bool) $c, $l); }
function assert_false($c, string $l) { assert_equal(false, (bool) $c, $l); }

// Build a legacy SHARED-KEY value (the pre-STR-10b on-disk format: HMAC'd and
// encrypted under the single master key).
function legacy_shared_value(string $plain, string $master): string {
    $iv  = random_bytes(16);
    $ct  = openssl_encrypt($plain, 'aes-256-cbc', $master, OPENSSL_RAW_DATA, $iv);
    $mac = hash_hmac('sha256', $iv . $ct, $master, true);
    return base64_encode($mac . $iv . $ct);
}

// Build a pre-HMAC legacy value (IV + ciphertext, no HMAC). With a long
// plaintext this is >= 49 bytes and collides with the authenticated length —
// the regression case that must still migrate.
function legacy_prehmac_value(string $plain, string $master): string {
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', $master, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $ct);
}

// Column set the harden SELECT expects to exist on each row.
function blank_row(int $cid): array {
    return [
        'client_id'                => $cid,
        'individual_id'            => null,
        'requisition_id'           => null,
        'vet_health_card'          => null,
        'diet_concerns'            => null,
        'customer_comments'        => null,
        'delivery_initials'        => '',
        'individual_id_index'      => null,
        'requisition_id_index'     => null,
        'vet_health_card_index'    => null,
        'delivery_initials_index'  => null,
    ];
}

// ---------------------------------------------------------------------------
// Seed two clients with legacy shared-key PII + v1 indexes.
// ---------------------------------------------------------------------------
$ID1 = 'SD-0001';
$ID2 = 'SD-0002';
$INIT1 = 'JAD';

$db = new HardenFakeWpdb();

// A long free-text comment stored as a pre-HMAC legacy value (multi-block →
// >= 49 bytes). This is the regression payload: before the fix, harden could
// not decrypt it and the run aborted, blocking v2 activation.
$LONG_COMMENT = str_repeat('No dairy, no shellfish; deliver to side door after 4pm. ', 4);

$r1 = blank_row(1);
$r1['individual_id']           = legacy_shared_value($ID1, $master_bytes);
$r1['individual_id_index']     = MealsDB_Encryption::create_index_v1($ID1); // v1 hash on disk
$r1['customer_comments']       = legacy_prehmac_value($LONG_COMMENT, $master_bytes);
$r1['delivery_initials']       = $INIT1;
$r1['delivery_initials_index'] = MealsDB_Encryption::create_index_v1($INIT1);

$r2 = blank_row(2);
$r2['individual_id']       = legacy_shared_value($ID2, $master_bytes);
$r2['individual_id_index'] = MealsDB_Encryption::create_index_v1($ID2);

$db->rows = [$r1, $r2];
$GLOBALS['wpdb'] = $db;

// Pre-condition sanity.
assert_false(MealsDB_Encryption::is_split_key_payload($db->find(1)['individual_id']), 'pre: row1 is shared-key, not split');
assert_false(MealsDB_Encryption::index_format_is_v2(), 'pre: index format is still v1');

// inventory() must count the LONG pre-HMAC customer_comments as legacy, NOT as
// 'new' — otherwise the admin notice would invite disabling the legacy path and
// lock the row out. This is the reclassification guard.
$inv = MealsDB_Encryption_Migrator::inventory();
assert_equal(1, $inv['customer_comments']['legacy'], 'pre: long pre-HMAC comment is counted legacy (not new)');
assert_equal(0, $inv['customer_comments']['new'], 'pre: long pre-HMAC comment is NOT miscounted as new');

// ---------------------------------------------------------------------------
// T-5 — run the harden pass: convert blobs to split-key AND indexes to v2.
// ---------------------------------------------------------------------------
$res = MealsDB_Encryption_Migrator::run_full_harden(50);

assert_false($res['aborted'], 'T-5: clean run, not aborted');
assert_equal(0, $res['failed'], 'T-5: no per-row failures');
assert_equal(2, $res['rows_changed'], 'T-5: both rows changed');
assert_true($res['reencrypted'] >= 2, 'T-5: at least the two individual_id blobs re-encrypted');
assert_true($res['index_v2_activated'], 'T-5: v2 index format activated after a clean full run');

// Blobs are now split-key and still decrypt to the original plaintext.
$row1 = $db->find(1);
assert_true(MealsDB_Encryption::is_split_key_payload($row1['individual_id']), 'T-5: row1 blob is now split-key');
assert_equal($ID1, MealsDB_Encryption::decrypt($row1['individual_id']), 'T-5: row1 blob still decrypts to original');

// Regression: the LONG pre-HMAC comment migrated too — it is now split-key and
// decrypts back to the original multi-block plaintext.
assert_true(MealsDB_Encryption::is_split_key_payload($row1['customer_comments']), 'T-5: long legacy comment is now split-key');
assert_equal($LONG_COMMENT, MealsDB_Encryption::decrypt($row1['customer_comments']), 'T-5: long legacy comment decrypts to original after migration');

// And inventory is now clean for that column (no legacy left) — only NOW would
// it be safe to disable the legacy read path.
$inv2 = MealsDB_Encryption_Migrator::inventory();
assert_equal(0, $inv2['customer_comments']['legacy'], 'T-5: no legacy comments remain post-migration');

// Indexes are now v2 (keyed HMAC), not the old v1 bare hash.
assert_equal(MealsDB_Encryption::create_index_v2($ID1), $row1['individual_id_index'], 'T-5: row1 index recomputed to v2');
assert_true($row1['individual_id_index'] !== MealsDB_Encryption::create_index_v1($ID1), 'T-5: row1 index is no longer the v1 hash');
assert_equal(MealsDB_Encryption::create_index_v2($INIT1), $row1['delivery_initials_index'], 'T-5: plaintext-source index recomputed to v2');

// Idempotency: a second full run changes nothing.
$res2 = MealsDB_Encryption_Migrator::run_full_harden(50);
assert_equal(0, $res2['rows_changed'], 'T-5: second run is a no-op (idempotent)');
assert_equal(0, $res2['reencrypted'], 'T-5: second run re-encrypts nothing');
assert_equal(0, $res2['reindexed'], 'T-5: second run re-indexes nothing');
assert_equal(0, $res2['failed'], 'T-5: second run has no failures');

// ---------------------------------------------------------------------------
// T-6 — exact-match lookups work against v2 indexes after the flip.
// index_format_is_v2() is now true, so create_index() yields v2 — the value
// the form's check_unique_fields() / search paths would compute for a lookup.
// ---------------------------------------------------------------------------
assert_true(MealsDB_Encryption::index_format_is_v2(), 'T-6: index format is v2 post-migration');
$lookup1 = MealsDB_Encryption::create_index($ID1);
$found = null;
foreach ($db->rows as $r) {
    if (($r['individual_id_index'] ?? null) === $lookup1) {
        $found = (int) $r['client_id'];
        break;
    }
}
assert_equal(1, $found, 'T-6: lookup by create_index(ID1) finds client 1 via the v2 index');

// A normalised-equivalent lookup also matches (case/whitespace folding).
$lookup1b = MealsDB_Encryption::create_index('  sd-0001  ');
assert_equal($lookup1, $lookup1b, 'T-6: normalised lookup matches the stored v2 index');

// ---------------------------------------------------------------------------
// Circuit breaker — a run that keeps failing aborts and does NOT flip the flag.
// ---------------------------------------------------------------------------
$GLOBALS['__opts'] = []; // reset flag to v1
$db2 = new HardenFakeWpdb();
$rb = blank_row(1);
$rb['individual_id']       = legacy_shared_value('X-1', $master_bytes);
$rb['individual_id_index'] = MealsDB_Encryption::create_index_v1('X-1');
$db2->rows = [$rb];
$db2->fail_update_for = 1; // force the write to fail
$GLOBALS['wpdb'] = $db2;

$res3 = MealsDB_Encryption_Migrator::run_full_harden(50, false, 1);
assert_true($res3['aborted'], 'CB: run aborts when failures hit the threshold');
assert_false($res3['index_v2_activated'], 'CB: v2 NOT activated on an aborted run');
assert_false(MealsDB_Encryption::index_format_is_v2(), 'CB: index format stays v1 after an aborted run');

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
