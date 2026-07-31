# Weekly Order Audit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A weekly order-audit workflow — pull every order delivered in a Mon–Sun week into an encrypted draft, confirm or correct each order against the delivery paperwork, finalize when every row is resolved, with invoice-draft-style unfinalize.

**Architecture:** New `meals_order_audits` table + `MealsDB_Order_Audit` service copying the `MealsDB_Invoice_Draft` pattern (encrypted `{generated, current}` payload, draft→finalized lifecycle), fed by the packing-slip order selection (`MealsDB_Delivery_Slip_Generator::get_orders_for_delivery_range`). Record-keeping only — never touches allocations, billing, or WC orders. Spec: `docs/superpowers/specs/2026-07-30-weekly-order-audit-design.md`.

**Tech Stack:** WordPress plugin PHP 8.2 (`$wpdb`, no mysqli), standalone `php tests/test-*.php` test scripts, jQuery admin JS. Tests are plain scripts with stub WP functions — mirror `tests/test-invoice-draft.php` / `tests/test-ajax-invoice-draft.php` style.

**Conventions that bind every task** (from CLAUDE.md): `\Throwable` catches that log + return sentinels; `gmdate('Y-m-d H:i:s')` for stored timestamps; `MealsDB_Encryption::encode_payload()`/`decode_payload()` fail-CLOSED; audit log via `MealsDB_Logger::log(string $action, int $target_id, string $field, ?string $old, ?string $new)`; three-layer permission gating; esc_* at output; text domain `'meals-db'`. Do NOT bump `MEALS_DB_VERSION` manually — CI bumps it on merge, which triggers the schema upgrade hook.

**Branch:** create `feat/weekly-order-audit` off current `main` before Task 1. Do NOT commit the operator's pre-existing uncommitted `directives/` moves — stage only files this plan names.

---

### Task 1: Table constant + canonical schema

**Files:**
- Modify: `includes/class-tables.php` (add constant + registry entry, next to `INVOICE_DRAFTS`)
- Modify: `includes/class-schema.php` (new table entry after the `SLIP_BATCHES` entry, ~line 820)
- Test: `tests/test-order-audit-schema.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Schema shape for meals_order_audits (weekly order audit — spec 2026-07-30).
 * Run with: php tests/test-order-audit-schema.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function oa_chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = 'FAIL: ' . $label; }
}

oa_chk(defined('MealsDB_Tables::ORDER_AUDITS') && MealsDB_Tables::ORDER_AUDITS === 'meals_order_audits',
    'ORDER_AUDITS constant is meals_order_audits');
oa_chk(in_array(MealsDB_Tables::ORDER_AUDITS, MealsDB_Tables::all(), true),
    'ORDER_AUDITS is in the MealsDB_Tables::all() registry (installer + uninstall coverage)');

$schema = MealsDB_Schema::get_table_schema(MealsDB_Tables::ORDER_AUDITS);
oa_chk(is_array($schema), 'canonical schema entry exists');
$cols = array_keys($schema['columns'] ?? []);
foreach (['audit_id', 'week_start', 'week_end', 'status', 'payload', 'row_count',
          'confirmed_count', 'edited_count', 'created_by', 'created_at',
          'finalized_by', 'finalized_at', 'unfinalized_at', 'unfinalize_reason'] as $c) {
    oa_chk(in_array($c, $cols, true), "column {$c} declared");
}
oa_chk(($schema['primary_key'] ?? null) === ['audit_id'], 'primary key is audit_id');
oa_chk(stripos($schema['columns']['status'] ?? '', "ENUM('draft','finalized')") !== false,
    'status ENUM is draft|finalized');
$index_cols = array_map(static fn($i) => $i['columns'], $schema['indexes'] ?? []);
oa_chk(in_array(['week_start'], $index_cols, true), 'week_start is indexed');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-order-audit-schema.php`
Expected: FAIL — `ORDER_AUDITS` constant undefined (fatal or failed checks).

- [ ] **Step 3: Add the constant + registry entry in `includes/class-tables.php`**

Next to the `INVOICE_DRAFTS` constant (~line 47):

```php
    public const ORDER_AUDITS = 'meals_order_audits';
```

And add `self::ORDER_AUDITS,` to the array returned by `all()` (~line 85, next to `self::INVOICE_DRAFTS,`).

- [ ] **Step 4: Add the schema entry in `includes/class-schema.php`**

After the `SLIP_BATCHES` entry (inside `get_canonical_schema()`), mirroring its shape:

```php
            // Weekly order audit (spec 2026-07-30). One row per audited
            // Mon–Sun week. `payload` is the encrypted {generated, current}
            // snapshot of the week's delivered orders (client names = PII,
            // hence encryption at rest like the invoice-draft payload).
            // One-audit-per-week is enforced in the SERVICE
            // (MealsDB_Order_Audit::find_by_week before insert), not by a
            // UNIQUE index — Schema_Sync is additive-only and its index
            // support is exercised only with plain INDEX entries; a service
            // check also lets create() surface the existing audit instead
            // of erroring. Additive table — STR-11 schema-sync ADDS it.
            MealsDB_Tables::ORDER_AUDITS => [
                'table'   => MealsDB_Tables::ORDER_AUDITS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'audit_id'          => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    // Monday and Sunday of the audited week.
                    'week_start'        => 'DATE NOT NULL',
                    'week_end'          => 'DATE NOT NULL',
                    'status'            => "ENUM('draft','finalized') NOT NULL DEFAULT 'draft'",
                    'payload'           => 'LONGTEXT NOT NULL',
                    'row_count'         => 'INT UNSIGNED NOT NULL DEFAULT 0',
                    'confirmed_count'   => 'INT UNSIGNED NOT NULL DEFAULT 0',
                    'edited_count'      => 'INT UNSIGNED NOT NULL DEFAULT 0',
                    'created_by'        => 'BIGINT UNSIGNED NULL',
                    'created_at'        => 'DATETIME NOT NULL',
                    'finalized_by'      => 'BIGINT UNSIGNED NULL',
                    'finalized_at'      => 'DATETIME NULL',
                    'unfinalized_at'    => 'DATETIME NULL',
                    'unfinalize_reason' => 'VARCHAR(500) NULL',
                ],
                'primary_key' => ['audit_id'],
                'indexes' => [
                    [
                        'name'    => 'idx_week_start',
                        'type'    => 'INDEX',
                        'columns' => ['week_start'],
                    ],
                    [
                        'name'    => 'idx_status',
                        'type'    => 'INDEX',
                        'columns' => ['status'],
                    ],
                ],
            ],
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/test-order-audit-schema.php`
Expected: all checks pass, exit 0.

- [ ] **Step 6: Run the existing schema-adjacent tests**

Run: `php tests/test-advanced-tools.php; php tests/test-event-log.php`
Expected: both pass (registry change must not break table enumeration consumers).

- [ ] **Step 7: Commit**

```bash
git add includes/class-tables.php includes/class-schema.php tests/test-order-audit-schema.php
git commit -m "feat(audit): meals_order_audits table schema

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Rate-limit bucket `order_audit_edit`

**Files:**
- Modify: `includes/class-rate-limiter.php` (~line 28 DEFAULT_LIMITS array; ~line 50 fail-closed list)
- Test: extend `tests/test-order-audit-schema.php` is WRONG — this belongs in its own tiny test: `tests/test-order-audit-rate-bucket.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * order_audit_edit rate bucket exists, sized for a full week's confirms
 * (~300+ rows in one sitting), and fails CLOSED (it gates writes).
 * Run with: php tests/test-order-audit-rate-bucket.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$rc = new ReflectionClass('MealsDB_Rate_Limiter');
$limits      = $rc->getConstant('DEFAULT_LIMITS');
$fail_closed = $rc->getConstant('FAIL_CLOSED_BUCKETS');

$ok  = isset($limits['order_audit_edit']) && $limits['order_audit_edit'] === 1000;
$ok2 = isset($fail_closed['order_audit_edit']) && $fail_closed['order_audit_edit'] === true;

echo ($ok ? 'PASS' : 'FAIL') . ": order_audit_edit limit is 1000\n";
echo ($ok2 ? 'PASS' : 'FAIL') . ": order_audit_edit is fail-closed\n";
exit(($ok && $ok2) ? 0 : 1);
```

**Note to implementer:** first open `includes/class-rate-limiter.php` and confirm the two constant names (`DEFAULT_LIMITS` at ~line 26, the fail-closed map at ~line 48). If the actual constant names differ, adjust the test to the real names before running it — the assertion targets (bucket present at 1000, fail-closed true) stay the same.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-order-audit-rate-bucket.php`
Expected: FAIL lines (bucket absent).

- [ ] **Step 3: Add the bucket**

In `includes/class-rate-limiter.php`, in the limits map next to `'invoice_draft_edit' => 300`:

```php
        // Weekly order audit — per-row confirm/edit/revert clicks. An auditor
        // works ~300+ orders in one sitting (16k orders/yr ≈ 300/wk), so the
        // 300/hr draft-edit sizing would 429 a normal session mid-audit.
        'order_audit_edit'       => 1000,
```

And in the fail-closed map next to `'invoice_draft_edit' => true`:

```php
        'order_audit_edit'      => true,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-order-audit-rate-bucket.php`
Expected: 2× PASS, exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes/class-rate-limiter.php tests/test-order-audit-rate-bucket.php
git commit -m "feat(audit): order_audit_edit rate bucket (1000/hr, fail-closed)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: `MealsDB_Order_Audit` service — snapshot builder + create/get/list

**Files:**
- Create: `includes/services/class-order-audit.php`
- Test: `tests/test-order-audit.php`

The service is static (stateless persistence layer, like `MealsDB_Invoice_Draft`). This task delivers: `build_week_rows()`, `find_by_week()`, `create_for_week()`, `get()`, `list_audits()`. Row mutations and lifecycle come in Tasks 4–5 **in the same file**.

- [ ] **Step 1: Write the failing test**

Create `tests/test-order-audit.php`. The stub block and harness below are shared by Tasks 3–5 — later tasks append checks to THIS file.

```php
<?php
/**
 * MealsDB_Order_Audit service tests (spec 2026-07-30).
 * Run with: php tests/test-order-audit.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('MEALS_DB_KEY')) { define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32))); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__'))            { function __($t, $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 7; } }
if (!function_exists('current_user_can'))    { function current_user_can($c) { return true; } }
if (!function_exists('is_user_logged_in'))   { function is_user_logged_in() { return true; } }
if (!function_exists('get_option'))    { function get_option($k, $d = false) { return $d; } }
// Classification stub: product 100 is a Main, everything else a Side.
if (!function_exists('has_term')) {
    function has_term($term, $tax, $pid) { return (int) $pid === 100; }
}

if (!class_exists('wpdb')) { class wpdb {} }
class OAWpdb extends wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public array $rows = [];       // audit_id => stored row (assoc)
    public array $audit_log = [];  // captured MealsDB_Logger rows
    private $next_id = 1;

    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        foreach ($args as $a) {
            $repl = is_int($a) || is_float($a) ? (string) $a : "'" . addslashes((string) $a) . "'";
            $sql = preg_replace('/%[sdf]/', str_replace('$', '\\$', $repl), $sql, 1);
        }
        return $sql;
    }
    public function insert($table, $data, $formats = null) {
        if (stripos($table, 'audit_log') !== false) { $this->audit_log[] = $data; return 1; }
        if (stripos($table, 'event_log') !== false) { return 1; }
        $id = $this->next_id++;
        $this->insert_id = $id;
        $this->rows[$id] = array_merge(['audit_id' => $id], $data);
        return 1;
    }
    public function update($table, $data, $where, $f1 = null, $f2 = null) {
        $id = (int) ($where['audit_id'] ?? 0);
        if (!isset($this->rows[$id])) { return 0; }
        $this->rows[$id] = array_merge($this->rows[$id], $data);
        return 1;
    }
    public function delete($table, $where, $formats = null) {
        $id = (int) ($where['audit_id'] ?? 0);
        if (!isset($this->rows[$id])) { return 0; }
        unset($this->rows[$id]);
        return 1;
    }
    public function get_row($sql, $output = ARRAY_A) {
        // find_by_week / get() both SELECT ... WHERE; emulate by scanning.
        if (preg_match("/week_start = '(\d{4}-\d{2}-\d{2})'/", (string) $sql, $m)) {
            foreach ($this->rows as $r) {
                if (($r['week_start'] ?? '') === $m[1]) { return $r; }
            }
            return null;
        }
        if (preg_match('/audit_id = (\d+)/', (string) $sql, $m)) {
            return $this->rows[(int) $m[1]] ?? null;
        }
        return null;
    }
    public function get_results($sql, $output = ARRAY_A) {
        // list_audits(): return all rows minus payload.
        $out = [];
        foreach ($this->rows as $r) { $x = $r; unset($x['payload']); $out[] = $x; }
        return $out;
    }
}

$failures = []; $passed = 0;
function oa_chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = 'FAIL: ' . $label; }
}
function oa_reset(): OAWpdb {
    $wpdb = new OAWpdb();
    $GLOBALS['wpdb'] = $wpdb;
    return $wpdb;
}

// Fixture: two orders as get_orders_for_delivery_range() returns them
// (order_id, wp_user_id, date_created_gmt, delivery_occurrence, items[]),
// plus the client rows keyed by wp_user_id the builder joins against.
function oa_orders(): array {
    return [
        [
            'order_id' => 501, 'wp_user_id' => 60, 'date_created_gmt' => '2026-07-20 10:00:00',
            'delivery_occurrence' => '2026-07-22',
            'items' => [
                ['order_item_id' => 1, 'order_item_name' => 'Beef Stew',  'wc_product_id' => 100, 'quantity' => 5],
                ['order_item_id' => 2, 'order_item_name' => 'Side Salad', 'wc_product_id' => 200, 'quantity' => 3],
                ['order_item_id' => 3, 'order_item_name' => 'Client Contribution', 'wc_product_id' => 5675, 'quantity' => 1],
            ],
        ],
        [
            'order_id' => 502, 'wp_user_id' => 61, 'date_created_gmt' => '2026-07-21 09:00:00',
            'delivery_occurrence' => '2026-07-23',
            'items' => [
                ['order_item_id' => 4, 'order_item_name' => 'Chicken Pie', 'wc_product_id' => 100, 'quantity' => 2],
            ],
        ],
    ];
}
function oa_clients(): array {
    return [
        60 => ['client_id' => 9, 'wp_user_id' => 60, 'first_name' => 'Pat', 'last_name' => 'Doe',
               'delivery_area_zone' => 'M', 'delivery_day' => 'wednesday', 'delivery_frequency' => 1],
        61 => ['client_id' => 10, 'wp_user_id' => 61, 'first_name' => 'Sam', 'last_name' => 'Roe',
               'delivery_area_zone' => 'S', 'delivery_day' => 'thursday', 'delivery_frequency' => 1],
    ];
}

// ---------------------------------------------------------------------------
// Task 3 checks: snapshot builder + create/get/list
// ---------------------------------------------------------------------------

// 1. build_rows_from_orders(): one row per order, summary counts classified
//    Main (product 100) vs Side, fee product 5675 excluded from items.
oa_reset();
$rows = MealsDB_Order_Audit::build_rows_from_orders(oa_orders(), oa_clients());
oa_chk(count($rows) === 2 && isset($rows[501], $rows[502]), '3.1: one row per order, keyed by order_id');
oa_chk($rows[501]['client_name'] === 'Pat Doe', '3.1: client name joined from client row');
oa_chk($rows[501]['delivery_date'] === '2026-07-22', '3.1: delivery date is the occurrence');
oa_chk($rows[501]['mains_count'] === 5 && $rows[501]['sides_count'] === 3, '3.1: mains/sides classified');
oa_chk(count($rows[501]['items']) === 2, '3.1: fee line (5675) excluded from items');
oa_chk($rows[501]['audit_status'] === 'pending', '3.1: rows start pending');
oa_chk($rows[501]['note'] === '' && $rows[501]['edited_items'] === [], '3.1: empty note/edits');

// 2. create_for_week(): persists encrypted payload, generated == current,
//    counts denormalized; get() round-trips.
$wpdb = oa_reset();
$audit_id = MealsDB_Order_Audit::create_for_week('2026-07-20', '2026-07-26', $rows);
oa_chk($audit_id === 1, '3.2: create returns audit_id');
$stored = $wpdb->rows[1];
oa_chk($stored['status'] === 'draft' && (int) $stored['row_count'] === 2, '3.2: draft with row_count 2');
oa_chk((int) $stored['confirmed_count'] === 0 && (int) $stored['edited_count'] === 0, '3.2: zero progress counts');
oa_chk(strpos((string) $stored['payload'], 'Pat Doe') === false, '3.2: payload is NOT plaintext (encrypted at rest)');
$loaded = MealsDB_Order_Audit::get(1);
oa_chk(is_array($loaded) && $loaded['payload']['generated'] == $loaded['payload']['current'],
    '3.2: get() decrypts; generated == current at creation');
oa_chk($loaded['payload']['current'][501]['client_name'] === 'Pat Doe', '3.2: row content round-trips');

// 3. find_by_week(): returns the existing audit_id; create is expected to be
//    guarded by the caller (AJAX) via find_by_week — one audit per week.
oa_chk(MealsDB_Order_Audit::find_by_week('2026-07-20') === 1, '3.3: find_by_week finds the audit');
oa_chk(MealsDB_Order_Audit::find_by_week('2026-01-05') === 0, '3.3: find_by_week returns 0 when absent');

// 4. Encryption failure → create returns 0 (fail closed, no plaintext row).
$wpdb = oa_reset();
// Force encode failure by clearing the key the encryption layer resolved.
// MEALS_DB_KEY is a constant, so instead pass an unencodable payload:
$bad = [501 => ['bin' => "\xB1\x31" . substr((string) json_encode(NAN), 0, 0), 'x' => NAN]];
$audit_id = MealsDB_Order_Audit::create_for_week('2026-07-27', '2026-08-02', $bad);
oa_chk($audit_id === 0 && empty($wpdb->rows), '3.4: unencodable payload → create fails closed, nothing stored');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-order-audit.php`
Expected: fatal — class `MealsDB_Order_Audit` not found.

- [ ] **Step 3: Implement the service (create/get/list + builder)**

Create `includes/services/class-order-audit.php`:

```php
<?php
/**
 * Weekly order audit service (spec 2026-07-30).
 *
 * Persistence + lifecycle for the weekly delivery-paperwork audit: pull the
 * week's delivered orders into an encrypted draft snapshot, let the auditor
 * confirm/correct each order, finalize when every row is resolved. Deliberately
 * copies the MealsDB_Invoice_Draft shape ({generated, current} payload,
 * draft → finalized, unfinalize-with-reason) but shares NO code with it:
 * invoice-draft finalize freezes allocation billing months and serializes
 * government CSVs — both wrong here. RECORD-KEEPING ONLY: nothing in this
 * class touches allocations, billing, or WC orders.
 *
 * Disciplines carried over (CLAUDE.md):
 *   - QW-2 fail CLOSED: payload is encrypted at rest (client names are PII);
 *     an encode failure aborts the write, never stores plaintext.
 *   - STR-LOG boundary: lifecycle + edits (committed record changes) → audit
 *     log; failures → operational trunk (degraded). Per-row CONFIRMS are
 *     attested inside the payload only (~300/week would bloat the append-only
 *     audit log for no investigative value — the discrepancies are the edits).
 *   - Pattern 7: every public method swallows its own \Throwable and returns
 *     a sentinel (0 / null / false / WP_Error).
 */
defined('ABSPATH') || exit;

class MealsDB_Order_Audit {

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_FINALIZED = 'finalized';

    public const ROW_PENDING   = 'pending';
    public const ROW_CONFIRMED = 'confirmed';
    public const ROW_EDITED    = 'edited';

    /** Payload schema version, for forward migration of the JSON shape. */
    private const PAYLOAD_SCHEMA = 1;

    public const MAX_NOTE_LEN = 500; // same cap as PO reconcile notes

    // ------------------------------------------------------------------
    // Snapshot building
    // ------------------------------------------------------------------

    /**
     * Pull the week's delivered orders. Thin orchestration over the SAME
     * selection the packing slips use (delivery-basis occurrence filter,
     * MAJ-6/GUI-SLIP-RANGE) so the audit list matches the paperwork by
     * construction. Kept separate from build_rows_from_orders() so tests
     * can exercise the row builder without WC.
     *
     * @return array<int,array> rows keyed by order_id, or null on failure.
     */
    public static function build_week_rows(string $week_start, string $week_end): ?array {
        try {
            $clients = self::get_delivery_clients();
            if (empty($clients)) {
                return [];
            }
            $generator = new MealsDB_Delivery_Slip_Generator();
            $orders    = $generator->get_orders_for_delivery_range($clients, $week_start, $week_end);
            return self::build_rows_from_orders($orders, $clients);
        } catch (\Throwable $e) {
            self::log_error('build_week_rows', $e);
            return null;
        }
    }

    /**
     * All active delivery clients (any type), keyed by wp_user_id — the
     * week-wide analogue of MealsDB_Delivery_Slip_Generator::
     * get_clients_for_delivery_date() (no day-of-week filter: the range
     * filter's occurrence test needs every client as a candidate).
     * first_name/last_name are NOT in ENCRYPTED_CLIENT_COLUMNS — no decrypt.
     */
    private static function get_delivery_clients(): array {
        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $rows  = $wpdb->get_results(
            "SELECT client_id, wp_user_id, first_name, last_name,
                    delivery_area_zone, delivery_day, delivery_frequency
             FROM `{$table}`
             WHERE active = 1 AND wp_user_id > 0",
            ARRAY_A
        );
        $clients = [];
        foreach ((array) $rows as $row) {
            $clients[(int) $row['wp_user_id']] = $row;
        }
        return $clients;
    }

    /**
     * Snapshot rows from slip-shaped orders. One row per order; items keep
     * only physical products (fee lines excluded); mains/sides summary uses
     * the SAME classification the packing slips use (Mains product_cat term,
     * default Side — see MealsDB_Slip_PDF_Generator::resolve_category()).
     *
     * @param array<int,array>        $orders  From get_orders_for_delivery_range().
     * @param array<int,array>        $clients Keyed by wp_user_id.
     * @return array<int,array> rows keyed by order_id.
     */
    public static function build_rows_from_orders(array $orders, array $clients): array {
        $fee_ids = class_exists('MealsDB_Invoice_Generator')
            ? array_map('intval', array_values(MealsDB_Invoice_Generator::get_fee_product_ids()))
            : [];
        $overage_ids = array_map('intval', (array) get_option('mealsdb_overage_product_ids', []));
        $excluded    = array_merge($fee_ids, $overage_ids);

        $rows = [];
        foreach ($orders as $order) {
            $oid = (int) ($order['order_id'] ?? 0);
            if ($oid <= 0) { continue; }
            $client = $clients[(int) ($order['wp_user_id'] ?? 0)] ?? [];

            $items = [];
            $mains = 0;
            $sides = 0;
            foreach ((array) ($order['items'] ?? []) as $item) {
                $pid = (int) ($item['wc_product_id'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 0);
                if ($pid > 0 && in_array($pid, $excluded, true)) {
                    continue; // fees/overage are not physical items to audit
                }
                $is_main = $pid > 0
                    && function_exists('has_term')
                    && has_term(MealsDB_Operational_Constants::CATEGORY_ID_MAINS, 'product_cat', $pid);
                if ($is_main) { $mains += $qty; } else { $sides += $qty; }
                $items[] = [
                    'item_key'     => (int) ($item['order_item_id'] ?? 0),
                    'product_name' => (string) ($item['order_item_name'] ?? ''),
                    'qty'          => $qty,
                ];
            }

            $rows[$oid] = [
                'order_id'      => $oid,
                'wp_user_id'    => (int) ($order['wp_user_id'] ?? 0),
                'client_id'     => (int) ($client['client_id'] ?? 0),
                'client_name'   => trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? '')),
                'zone'          => (string) ($client['delivery_area_zone'] ?? ''),
                'delivery_date' => (string) ($order['delivery_occurrence'] ?? substr((string) ($order['date_created_gmt'] ?? ''), 0, 10)),
                'items'         => $items,
                'mains_count'   => $mains,
                'sides_count'   => $sides,
                'audit_status'  => self::ROW_PENDING,
                'edited_items'  => [],
                'note'          => '',
                'audited_by'    => 0,
                'audited_at'    => '',
            ];
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Persistence
    // ------------------------------------------------------------------

    /**
     * Create a draft audit for a week. Caller enforces one-per-week via
     * find_by_week() first. Returns audit_id, or 0 on failure (never throws).
     */
    public static function create_for_week(string $week_start, string $week_end, array $rows): int {
        try {
            $payload = [
                'schema'    => self::PAYLOAD_SCHEMA,
                'generated' => $rows,
                'current'   => $rows,
            ];
            $encoded = MealsDB_Encryption::encode_payload($payload);
            if ($encoded === false) {
                self::record_degraded('create.encrypt_failed', 'Order audit not created: payload encryption failed.');
                return 0;
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $ok = $wpdb->insert($table, [
                'week_start'      => $week_start,
                'week_end'        => $week_end,
                'status'          => self::STATUS_DRAFT,
                'payload'         => $encoded,
                'row_count'       => count($rows),
                'confirmed_count' => 0,
                'edited_count'    => 0,
                'created_by'      => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ], ['%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s']);
            if ($ok === false) {
                return 0;
            }
            $audit_id = (int) $wpdb->insert_id;
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log('order_audit_created', $audit_id, 'week_start', null, $week_start);
            }
            return $audit_id;
        } catch (\Throwable $e) {
            self::log_error('create_for_week', $e);
            return 0;
        }
    }

    /** audit_id for a week_start, or 0. */
    public static function find_by_week(string $week_start): int {
        try {
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT audit_id FROM `{$table}` WHERE week_start = %s LIMIT 1",
                $week_start
            ), ARRAY_A);
            return is_array($row) ? (int) $row['audit_id'] : 0;
        } catch (\Throwable $e) {
            self::log_error('find_by_week', $e);
            return 0;
        }
    }

    /** Load + decrypt one audit. Meta columns + decoded 'payload' key, or null. */
    public static function get(int $audit_id): ?array {
        try {
            if ($audit_id <= 0) { return null; }
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE audit_id = %d LIMIT 1",
                $audit_id
            ), ARRAY_A);
            if (!is_array($row)) { return null; }
            $payload = MealsDB_Encryption::decode_payload((string) $row['payload']);
            if (!is_array($payload)) { return null; }
            $row['payload'] = $payload;
            return $row;
        } catch (\Throwable $e) {
            self::log_error('get', $e);
            return null;
        }
    }

    /** All audits, newest week first, payload omitted (list view). */
    public static function list_audits(): array {
        try {
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $rows = $wpdb->get_results(
                "SELECT audit_id, week_start, week_end, status, row_count,
                        confirmed_count, edited_count, created_by, created_at,
                        finalized_by, finalized_at
                 FROM `{$table}` ORDER BY week_start DESC LIMIT 200",
                ARRAY_A
            );
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            self::log_error('list_audits', $e);
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Shared internals
    // ------------------------------------------------------------------

    private static function record_degraded(string $event, string $message): void {
        if (class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'error',
                'category'  => 'audit',
                'subsystem' => 'order_audit',
                'event'     => $event,
                'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message'   => $message,
            ]);
        }
    }

    private static function log_error(string $op, \Throwable $e): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Order_Audit] ' . $op . ' failed: ' . $e->getMessage());
        }
        self::record_degraded($op . '.failed', $e->getMessage());
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-order-audit.php`
Expected: all Task-3 checks pass, exit 0. If check 3.4 does not fail closed (json_encode of NAN behaves differently across PHP builds), replace the bad-payload trick with a `MealsDB_Encryption::encode_payload` returning false via an uninitialized-key path ONLY if that is achievable without redefining the constant; otherwise assert the guard by reading the code path and change 3.4 to feed `$rows` containing an invalid UTF-8 sequence `"\xB1\x31"` (json_encode returns false → encode_payload returns false). One of the two MUST produce `audit_id === 0` with an empty rows store.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-order-audit.php tests/test-order-audit.php
git commit -m "feat(audit): MealsDB_Order_Audit service — snapshot build + create/get/list

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Row mutations — confirm toggle, edit, revert

**Files:**
- Modify: `includes/services/class-order-audit.php` (append methods)
- Test: append to `tests/test-order-audit.php` (before the final `echo 'Ran …'` block)

- [ ] **Step 1: Append the failing checks**

```php
// ---------------------------------------------------------------------------
// Task 4 checks: confirm toggle / edit / revert
// ---------------------------------------------------------------------------

function oa_make_audit(): array {
    $wpdb = oa_reset();
    $rows = MealsDB_Order_Audit::build_rows_from_orders(oa_orders(), oa_clients());
    $id   = MealsDB_Order_Audit::create_for_week('2026-07-20', '2026-07-26', $rows);
    return [$wpdb, $id];
}

// 1. Confirm marks the row confirmed and bumps confirmed_count.
[$wpdb, $id] = oa_make_audit();
$res = MealsDB_Order_Audit::confirm_row($id, 501);
oa_chk($res === 'confirmed', '4.1: confirm returns new status');
$a = MealsDB_Order_Audit::get($id);
oa_chk($a['payload']['current'][501]['audit_status'] === 'confirmed', '4.1: row status stored');
oa_chk((int) $wpdb->rows[$id]['confirmed_count'] === 1, '4.1: confirmed_count denormalized');
oa_chk((int) $a['payload']['current'][501]['audited_by'] === 7, '4.1: confirm attested by user in payload');
oa_chk($a['payload']['generated'][501]['audit_status'] === 'pending', '4.1: generated snapshot untouched');

// 2. Confirm again toggles back to pending (misclick recovery).
$res = MealsDB_Order_Audit::confirm_row($id, 501);
oa_chk($res === 'pending', '4.2: second confirm toggles to pending');
oa_chk((int) $GLOBALS['wpdb']->rows[$id]['confirmed_count'] === 0, '4.2: count restored');

// 3. Edit stores per-item quantities + note, sets edited, audit-logs the deltas.
$res = MealsDB_Order_Audit::edit_row($id, 501, [1 => 4, 2 => 3], 'one stew damaged');
oa_chk($res === true, '4.3: edit accepted');
$a = MealsDB_Order_Audit::get($id);
oa_chk($a['payload']['current'][501]['audit_status'] === 'edited', '4.3: row edited');
oa_chk($a['payload']['current'][501]['edited_items'] === [1 => 4, 2 => 3], '4.3: edited quantities stored');
oa_chk($a['payload']['current'][501]['note'] === 'one stew damaged', '4.3: note stored');
oa_chk((int) $GLOBALS['wpdb']->rows[$id]['edited_count'] === 1, '4.3: edited_count denormalized');
$log_actions = array_column($GLOBALS['wpdb']->audit_log, 'action');
oa_chk(in_array('order_audit_row_edited', $log_actions, true), '4.3: edit hits the audit log');

// 4. Edit validation: negative qty rejected; overlong note rejected; unknown
//    item_key rejected; unknown order rejected.
oa_chk(MealsDB_Order_Audit::edit_row($id, 501, [1 => -2], '') instanceof WP_Error, '4.4: negative qty → WP_Error');
oa_chk(MealsDB_Order_Audit::edit_row($id, 501, [999 => 1], '') instanceof WP_Error, '4.4: unknown item_key → WP_Error');
oa_chk(MealsDB_Order_Audit::edit_row($id, 501, [1 => 1], str_repeat('x', 501)) instanceof WP_Error, '4.4: overlong note → WP_Error');
oa_chk(MealsDB_Order_Audit::edit_row($id, 777, [1 => 1], '') instanceof WP_Error, '4.4: unknown order → WP_Error');

// 5. Revert clears the edit back to pending.
$res = MealsDB_Order_Audit::revert_row($id, 501);
oa_chk($res === true, '4.5: revert accepted');
$a = MealsDB_Order_Audit::get($id);
oa_chk($a['payload']['current'][501]['audit_status'] === 'pending'
    && $a['payload']['current'][501]['edited_items'] === []
    && $a['payload']['current'][501]['note'] === '', '4.5: row back to pristine pending');
oa_chk((int) $GLOBALS['wpdb']->rows[$id]['edited_count'] === 0, '4.5: edited_count restored');
```

Note: `WP_Error` may not exist in the standalone test env — add this stub to the stub block at the top of the file:

```php
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $m;
        public function __construct($code = '', $message = '') { $this->m = $message; }
        public function get_error_message() { return $this->m; }
    }
}
```

- [ ] **Step 2: Run test to verify the new checks fail**

Run: `php tests/test-order-audit.php`
Expected: Task-3 checks pass; Task-4 checks fatal or fail (`confirm_row` undefined).

- [ ] **Step 3: Implement the mutations**

Append to `class-order-audit.php` (before the "Shared internals" section):

```php
    // ------------------------------------------------------------------
    // Row mutations (draft only)
    // ------------------------------------------------------------------

    /**
     * Toggle a row confirmed <-> pending. Returns the NEW row status string,
     * or WP_Error. Confirms are attested in the payload (audited_by/at), NOT
     * the audit log — see the class docblock for the volume rationale.
     */
    public static function confirm_row(int $audit_id, int $order_id) {
        return self::mutate_row($audit_id, $order_id, static function (array $row) {
            if ($row['audit_status'] === self::ROW_CONFIRMED) {
                $row['audit_status'] = self::ROW_PENDING;
                $row['audited_by']   = 0;
                $row['audited_at']   = '';
            } else {
                // From pending OR edited: an explicit confirm supersedes.
                $row['audit_status'] = self::ROW_CONFIRMED;
                $row['edited_items'] = [];
                $row['note']         = '';
                $row['audited_by']   = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
                $row['audited_at']   = gmdate('Y-m-d H:i:s');
            }
            return $row;
        });
    }

    /**
     * Record a discrepancy: adjusted per-item quantities + note. Quantities
     * are a FULL map item_key => received qty for the rows being changed;
     * items not in the map keep their snapshot qty. Edits ARE the audit's
     * reason to exist, so each one is audit-logged with its deltas.
     *
     * @param array<int,int> $qtys item_key => received qty (>= 0)
     * @return true|WP_Error
     */
    public static function edit_row(int $audit_id, int $order_id, array $qtys, string $note) {
        $note = trim($note);
        if (function_exists('mb_strlen') ? mb_strlen($note) > self::MAX_NOTE_LEN : strlen($note) > self::MAX_NOTE_LEN) {
            return new WP_Error('note_too_long', __('Note is too long (500 characters max).', 'meals-db'));
        }
        $deltas = [];
        $result = self::mutate_row($audit_id, $order_id, static function (array $row) use ($qtys, $note, &$deltas) {
            $known = [];
            foreach ($row['items'] as $item) {
                $known[(int) $item['item_key']] = (int) $item['qty'];
            }
            $clean = [];
            foreach ($qtys as $key => $qty) {
                $key = (int) $key;
                $qty = (int) $qty;
                if (!array_key_exists($key, $known)) {
                    return new WP_Error('unknown_item', __('Unknown order item.', 'meals-db'));
                }
                if ($qty < 0) {
                    return new WP_Error('bad_qty', __('Quantities must be zero or more.', 'meals-db'));
                }
                $clean[$key] = $qty;
                if ($qty !== $known[$key]) {
                    $deltas[] = $key . ':' . $known[$key] . '→' . $qty;
                }
            }
            $row['audit_status'] = self::ROW_EDITED;
            $row['edited_items'] = $clean;
            $row['note']         = $note;
            $row['audited_by']   = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            $row['audited_at']   = gmdate('Y-m-d H:i:s');
            return $row;
        });
        if ($result instanceof WP_Error) {
            return $result;
        }
        if (class_exists('MealsDB_Logger')) {
            // Deltas only — item keys and counts, no PII in old/new.
            MealsDB_Logger::log('order_audit_row_edited', $audit_id, 'order_' . $order_id,
                null, implode(', ', $deltas) . ($note !== '' ? ' (note)' : ''));
        }
        return true;
    }

    /** Discard an edit (or a confirm) back to pristine pending. @return true|WP_Error */
    public static function revert_row(int $audit_id, int $order_id) {
        $result = self::mutate_row($audit_id, $order_id, static function (array $row) {
            $row['audit_status'] = self::ROW_PENDING;
            $row['edited_items'] = [];
            $row['note']         = '';
            $row['audited_by']   = 0;
            $row['audited_at']   = '';
            return $row;
        });
        return ($result instanceof WP_Error) ? $result : true;
    }

    /**
     * Shared load → mutate one row → re-encrypt → persist path. $mutator gets
     * the current row and returns the replacement (or WP_Error to abort).
     * Returns the new audit_status string, or WP_Error. Draft-only.
     */
    private static function mutate_row(int $audit_id, int $order_id, callable $mutator) {
        try {
            $audit = self::get($audit_id);
            if ($audit === null) {
                return new WP_Error('not_found', __('Audit not found.', 'meals-db'));
            }
            if ($audit['status'] !== self::STATUS_DRAFT) {
                return new WP_Error('finalized', __('This audit is finalized and read-only.', 'meals-db'));
            }
            $payload = $audit['payload'];
            if (!isset($payload['current'][$order_id])) {
                return new WP_Error('row_not_found', __('Order not found in this audit.', 'meals-db'));
            }
            $new_row = $mutator($payload['current'][$order_id]);
            if ($new_row instanceof WP_Error) {
                return $new_row;
            }
            $payload['current'][$order_id] = $new_row;

            $confirmed = 0; $edited = 0;
            foreach ($payload['current'] as $r) {
                if (($r['audit_status'] ?? '') === self::ROW_CONFIRMED) { $confirmed++; }
                if (($r['audit_status'] ?? '') === self::ROW_EDITED)    { $edited++; }
            }

            $encoded = MealsDB_Encryption::encode_payload($payload);
            if ($encoded === false) {
                // QW-2 fail closed: refuse the mutation rather than store plaintext.
                self::record_degraded('mutate.encrypt_failed', 'Order-audit row change dropped: payload encryption failed.');
                return new WP_Error('encrypt_failed', __('Could not save the change (encryption unavailable).', 'meals-db'));
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $ok = $wpdb->update($table, [
                'payload'         => $encoded,
                'confirmed_count' => $confirmed,
                'edited_count'    => $edited,
            ], ['audit_id' => $audit_id], ['%s', '%d', '%d'], ['%d']);
            if ($ok === false) {
                return new WP_Error('db', __('Could not save the change.', 'meals-db'));
            }
            return (string) $new_row['audit_status'];
        } catch (\Throwable $e) {
            self::log_error('mutate_row', $e);
            return new WP_Error('internal', __('Could not save the change.', 'meals-db'));
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-order-audit.php`
Expected: all Task-3 + Task-4 checks pass, exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-order-audit.php tests/test-order-audit.php
git commit -m "feat(audit): row confirm toggle / edit with deltas / revert

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Lifecycle — finalize gate, unfinalize with reason, draft delete

**Files:**
- Modify: `includes/services/class-order-audit.php` (append methods)
- Test: append to `tests/test-order-audit.php`

- [ ] **Step 1: Append the failing checks**

```php
// ---------------------------------------------------------------------------
// Task 5 checks: finalize / unfinalize / delete
// ---------------------------------------------------------------------------

// 1. Finalize refused while any row is pending (server-side gate).
[$wpdb, $id] = oa_make_audit();
MealsDB_Order_Audit::confirm_row($id, 501); // 502 still pending
oa_chk(MealsDB_Order_Audit::finalize($id) instanceof WP_Error, '5.1: finalize refused with a pending row');
oa_chk($wpdb->rows[$id]['status'] === 'draft', '5.1: still draft');

// 2. Finalize succeeds when every row is resolved; audit becomes read-only.
MealsDB_Order_Audit::edit_row($id, 502, [4 => 1], 'pie missing');
oa_chk(MealsDB_Order_Audit::finalize($id) === true, '5.2: finalize succeeds when all resolved');
oa_chk($wpdb->rows[$id]['status'] === 'finalized' && !empty($wpdb->rows[$id]['finalized_at']), '5.2: stamped');
oa_chk(MealsDB_Order_Audit::confirm_row($id, 501) instanceof WP_Error, '5.2: finalized audit refuses row changes');
$log_actions = array_column($wpdb->audit_log, 'action');
oa_chk(in_array('order_audit_finalized', $log_actions, true), '5.2: finalize audit-logged');

// 3. Unfinalize requires a reason; restores editability with states intact.
oa_chk(MealsDB_Order_Audit::unfinalize($id, '   ') instanceof WP_Error, '5.3: blank reason refused');
oa_chk(MealsDB_Order_Audit::unfinalize($id, 'found another slip') === true, '5.3: unfinalize with reason');
oa_chk($GLOBALS['wpdb']->rows[$id]['status'] === 'draft', '5.3: back to draft');
oa_chk($GLOBALS['wpdb']->rows[$id]['unfinalize_reason'] === 'found another slip', '5.3: reason stored');
$a = MealsDB_Order_Audit::get($id);
oa_chk($a['payload']['current'][502]['audit_status'] === 'edited', '5.3: row states preserved');

// 4. Delete: allowed for drafts, refused for finalized.
oa_chk(MealsDB_Order_Audit::delete_draft($id) === true, '5.4: draft deletable');
oa_chk(MealsDB_Order_Audit::get($id) === null, '5.4: gone');
[$wpdb2, $id2] = oa_make_audit();
MealsDB_Order_Audit::confirm_row($id2, 501);
MealsDB_Order_Audit::confirm_row($id2, 502);
MealsDB_Order_Audit::finalize($id2);
oa_chk(MealsDB_Order_Audit::delete_draft($id2) instanceof WP_Error, '5.4: finalized audit not deletable');

// 5. Empty week: zero rows is a valid draft and finalizes immediately.
oa_reset();
$empty_id = MealsDB_Order_Audit::create_for_week('2026-06-01', '2026-06-07', []);
oa_chk($empty_id > 0, '5.5: empty week creates a valid draft');
oa_chk(MealsDB_Order_Audit::finalize($empty_id) === true, '5.5: empty audit finalizes');
```

- [ ] **Step 2: Run test to verify the new checks fail**

Run: `php tests/test-order-audit.php`
Expected: Task-3/4 checks pass; Task-5 checks fatal (`finalize` undefined).

- [ ] **Step 3: Implement the lifecycle methods**

Append to `class-order-audit.php` (before "Shared internals"):

```php
    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    /**
     * Finalize: every row must be confirmed or edited (server-side gate — the
     * JS disable is a convenience, not the enforcement). Locks the audit
     * read-only. No output artifact: the record IS the artifact.
     * @return true|WP_Error
     */
    public static function finalize(int $audit_id) {
        try {
            $audit = self::get($audit_id);
            if ($audit === null) {
                return new WP_Error('not_found', __('Audit not found.', 'meals-db'));
            }
            if ($audit['status'] !== self::STATUS_DRAFT) {
                return new WP_Error('not_draft', __('Only a draft audit can be finalized.', 'meals-db'));
            }
            foreach ($audit['payload']['current'] as $row) {
                if (($row['audit_status'] ?? self::ROW_PENDING) === self::ROW_PENDING) {
                    return new WP_Error('pending_rows',
                        __('Every order must be confirmed or edited before the audit can be saved.', 'meals-db'));
                }
            }
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $ok = $wpdb->update($table, [
                'status'       => self::STATUS_FINALIZED,
                'finalized_by' => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
                'finalized_at' => gmdate('Y-m-d H:i:s'),
            ], ['audit_id' => $audit_id], ['%s', '%d', '%s'], ['%d']);
            if ($ok === false) {
                return new WP_Error('db', __('Could not finalize the audit.', 'meals-db'));
            }
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log('order_audit_finalized', $audit_id, 'status', self::STATUS_DRAFT, self::STATUS_FINALIZED);
            }
            return true;
        } catch (\Throwable $e) {
            self::log_error('finalize', $e);
            return new WP_Error('internal', __('Could not finalize the audit.', 'meals-db'));
        }
    }

    /**
     * Reopen a finalized audit. Requires a non-blank typed reason (mirrors the
     * invoice-draft unfinish flow). Row states are untouched. No cascade
     * concept — nothing downstream consumes the audit. @return true|WP_Error
     */
    public static function unfinalize(int $audit_id, string $reason) {
        try {
            $reason = trim($reason);
            if ($reason === '') {
                return new WP_Error('reason_required', __('A reason is required to reopen a finalized audit.', 'meals-db'));
            }
            if (function_exists('mb_strlen') ? mb_strlen($reason) > self::MAX_NOTE_LEN : strlen($reason) > self::MAX_NOTE_LEN) {
                return new WP_Error('reason_too_long', __('Reason is too long (500 characters max).', 'meals-db'));
            }
            $audit = self::get($audit_id);
            if ($audit === null) {
                return new WP_Error('not_found', __('Audit not found.', 'meals-db'));
            }
            if ($audit['status'] !== self::STATUS_FINALIZED) {
                return new WP_Error('not_finalized', __('Only a finalized audit can be reopened.', 'meals-db'));
            }
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $ok = $wpdb->update($table, [
                'status'            => self::STATUS_DRAFT,
                'unfinalized_at'    => gmdate('Y-m-d H:i:s'),
                'unfinalize_reason' => $reason,
            ], ['audit_id' => $audit_id], ['%s', '%s', '%s'], ['%d']);
            if ($ok === false) {
                return new WP_Error('db', __('Could not reopen the audit.', 'meals-db'));
            }
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log('order_audit_unfinalized', $audit_id, 'reason', null, $reason);
            }
            return true;
        } catch (\Throwable $e) {
            self::log_error('unfinalize', $e);
            return new WP_Error('internal', __('Could not reopen the audit.', 'meals-db'));
        }
    }

    /**
     * Delete a DRAFT (never a finalized record) so a bad pull can be redone —
     * find_by_week() otherwise blocks regenerating the week. @return true|WP_Error
     */
    public static function delete_draft(int $audit_id) {
        try {
            $audit = self::get($audit_id);
            if ($audit === null) {
                return new WP_Error('not_found', __('Audit not found.', 'meals-db'));
            }
            if ($audit['status'] !== self::STATUS_DRAFT) {
                return new WP_Error('not_draft', __('A finalized audit cannot be deleted.', 'meals-db'));
            }
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $ok = $wpdb->delete($table, ['audit_id' => $audit_id], ['%d']);
            if ($ok === false || $ok === 0) {
                return new WP_Error('db', __('Could not delete the audit draft.', 'meals-db'));
            }
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log('order_audit_draft_deleted', $audit_id, 'week_start', (string) $audit['week_start'], null);
            }
            return true;
        } catch (\Throwable $e) {
            self::log_error('delete_draft', $e);
            return new WP_Error('internal', __('Could not delete the audit draft.', 'meals-db'));
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-order-audit.php`
Expected: all checks pass, exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-order-audit.php tests/test-order-audit.php
git commit -m "feat(audit): finalize gate, unfinalize with reason, draft delete

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: AJAX endpoints

**Files:**
- Create: `includes/ajax/class-ajax-order-audit.php`
- Modify: `meals-db-main.php` (~line 96, next to `MealsDB_Ajax_Invoice_Draft::init();`)
- Test: `tests/test-ajax-order-audit.php`

Endpoints (all `wp_ajax_`, nonce context `mealsdb_order_audit`):
`mealsdb_order_audit_create`, `mealsdb_order_audit_confirm`, `mealsdb_order_audit_edit`, `mealsdb_order_audit_revert`, `mealsdb_order_audit_finalize`, `mealsdb_order_audit_unfinalize`, `mealsdb_order_audit_delete`.

- [ ] **Step 1: Write the failing test**

Model it on `tests/test-ajax-invoice-draft.php` (read that file first and reuse its stub scaffolding for `wp_send_json_*`, `check_ajax_referer`, capability toggles, and its class-alias trick for stubbing the service). The test MUST cover, at minimum:

```php
// Gating (each endpoint): bad nonce → error; capability false → error;
// rate-limit false → 429 error. Reuse the harness pattern where
// check_ajax_referer / current_user_can / MealsDB_Rate_Limiter are stubbed
// with toggleable globals.
//
// mealsdb_order_audit_create:
//   - invalid week_start (not Y-m-d, or not a Monday) → error message
//   - week already audited (find_by_week > 0) → success WITH
//     ['audit_id' => existing, 'existing' => true] (surfaces, not errors)
//   - happy path: build_week_rows + create_for_week called; success payload
//     carries audit_id + row_count
//   - build_week_rows returns null (pull failure) → error, NO create call
// mealsdb_order_audit_confirm: happy path returns
//   ['status' => 'confirmed', 'confirmed_count' => n, 'edited_count' => m];
//   WP_Error from the service → wp_send_json_error with its message
// mealsdb_order_audit_edit: qtys array sanitized to int map before the
//   service call; note passed through; WP_Error → error with message
// mealsdb_order_audit_finalize: WP_Error (pending rows) → error with message
// mealsdb_order_audit_unfinalize: missing reason → error
// mealsdb_order_audit_delete: service WP_Error → error; success → success
```

The Monday check matters: `week_start` must satisfy `date('N', strtotime($week_start)) === '1'`; `week_end` is DERIVED server-side as `week_start + 6 days` — the client never supplies it (prevents overlapping/odd ranges by construction).

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-ajax-order-audit.php`
Expected: fatal — class `MealsDB_Ajax_Order_Audit` not found.

- [ ] **Step 3: Implement the AJAX class**

Create `includes/ajax/class-ajax-order-audit.php`. Skeleton with the full guard and the create handler; the remaining five handlers follow the identical decode → service call → `WP_Error`-check shape shown in `confirm`:

```php
<?php
/**
 * AJAX endpoints for the weekly order audit (spec 2026-07-30).
 * Defense-in-depth (Pattern 1): nonce + capability + rate limit here; the
 * page enforces at view level; the service re-checks nothing DB-destructive
 * beyond draft/finalized state (record-keeping only, no allocation writes).
 */
defined('ABSPATH') || exit;

class MealsDB_Ajax_Order_Audit {

    public const NONCE_ACTION = 'mealsdb_order_audit';

    public static function init(): void {
        add_action('wp_ajax_mealsdb_order_audit_create',     [__CLASS__, 'create']);
        add_action('wp_ajax_mealsdb_order_audit_confirm',    [__CLASS__, 'confirm']);
        add_action('wp_ajax_mealsdb_order_audit_edit',       [__CLASS__, 'edit']);
        add_action('wp_ajax_mealsdb_order_audit_revert',     [__CLASS__, 'revert']);
        add_action('wp_ajax_mealsdb_order_audit_finalize',   [__CLASS__, 'finalize']);
        add_action('wp_ajax_mealsdb_order_audit_unfinalize', [__CLASS__, 'unfinalize']);
        add_action('wp_ajax_mealsdb_order_audit_delete',     [__CLASS__, 'delete_draft']);
    }

    /**
     * Baseline plugin capability (manage_woocommerce by default) — the grid
     * shows client names + item counts, the same exposure as packing slips,
     * NOT the decrypted-ID PII that pushed invoice drafts to manage_options.
     */
    private static function guard(string $rate_bucket): bool {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token.', 'meals-db')]);
            return false;
        }
        $cap = class_exists('MealsDB_Permissions') ? MealsDB_Permissions::required_capability() : 'manage_woocommerce';
        if (!current_user_can($cap)) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'meals-db')]);
            return false;
        }
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit($rate_bucket)) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
            return false;
        }
        return true;
    }

    public static function create(): void {
        if (!self::guard('order_audit_edit')) { return; }
        try {
            $week_start = sanitize_text_field(wp_unslash($_POST['week_start'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start)
                || date('N', (int) strtotime($week_start . ' UTC')) !== '1') {
                wp_send_json_error(['message' => __('Pick the Monday of the week to audit.', 'meals-db')]);
                return;
            }
            // week_end derived server-side: Mon + 6 = Sun. The client never
            // supplies it, so audits can never overlap or span odd ranges.
            $week_end = gmdate('Y-m-d', strtotime($week_start . ' +6 days UTC'));

            $existing = MealsDB_Order_Audit::find_by_week($week_start);
            if ($existing > 0) {
                wp_send_json_success(['audit_id' => $existing, 'existing' => true]);
                return;
            }
            $rows = MealsDB_Order_Audit::build_week_rows($week_start, $week_end);
            if ($rows === null) {
                wp_send_json_error(['message' => __('Could not load the week\'s orders (see Event Log).', 'meals-db')]);
                return;
            }
            $audit_id = MealsDB_Order_Audit::create_for_week($week_start, $week_end, $rows);
            if ($audit_id <= 0) {
                wp_send_json_error(['message' => __('Could not create the audit draft (see Event Log).', 'meals-db')]);
                return;
            }
            wp_send_json_success(['audit_id' => $audit_id, 'existing' => false, 'row_count' => count($rows)]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Order_Audit AJAX] create failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to create the audit. Please contact an administrator.', 'meals-db')]);
        }
    }

    public static function confirm(): void {
        if (!self::guard('order_audit_edit')) { return; }
        try {
            $audit_id = absint($_POST['audit_id'] ?? 0);
            $order_id = absint($_POST['order_id'] ?? 0);
            $result   = MealsDB_Order_Audit::confirm_row($audit_id, $order_id);
            if ($result instanceof WP_Error) {
                wp_send_json_error(['message' => $result->get_error_message()]);
                return;
            }
            wp_send_json_success(self::progress($audit_id, ['status' => $result]));
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Order_Audit AJAX] confirm failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save. Please contact an administrator.', 'meals-db')]);
        }
    }

    /** Progress counters for the grid header / finalize button state. */
    private static function progress(int $audit_id, array $extra = []): array {
        $audit = MealsDB_Order_Audit::get($audit_id);
        return array_merge($extra, [
            'row_count'       => $audit ? (int) $audit['row_count'] : 0,
            'confirmed_count' => $audit ? (int) $audit['confirmed_count'] : 0,
            'edited_count'    => $audit ? (int) $audit['edited_count'] : 0,
        ]);
    }

    // edit(): reads audit_id, order_id, note (sanitize_textarea_field), and
    //   qtys (array; array_map absint over both keys and values → int map)
    //   then MealsDB_Order_Audit::edit_row(...) with the same WP_Error →
    //   json-error shape as confirm(), success → progress(..., ['status' => 'edited']).
    // revert(): audit_id + order_id → revert_row(), success → progress(..., ['status' => 'pending']).
    // finalize(): audit_id → finalize(), success → ['finalized' => true].
    // unfinalize(): audit_id + reason (sanitize_textarea_field) → unfinalize().
    // delete_draft(): audit_id → delete_draft(), success → ['deleted' => true].
    // Each is its own public static method with the identical try/catch guard
    // shape as confirm() — write them out in full; no shared magic dispatcher.
}
```

**The five commented handlers must be written out in full following `confirm()`'s exact structure** — the comment block above specifies each one's inputs, service call, and success payload.

- [ ] **Step 4: Register init in `meals-db-main.php`**

Next to `MealsDB_Ajax_Invoice_Draft::init();` (~line 96):

```php
    MealsDB_Ajax_Order_Audit::init();
```

- [ ] **Step 5: Run tests**

Run: `php tests/test-ajax-order-audit.php && php tests/test-order-audit.php`
Expected: all pass, exit 0.

- [ ] **Step 6: Commit**

```bash
git add includes/ajax/class-ajax-order-audit.php meals-db-main.php tests/test-ajax-order-audit.php
git commit -m "feat(audit): AJAX endpoints for weekly order audit

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: Admin page + grid JS

**Files:**
- Create: `includes/admin/class-order-audit-page.php`
- Create: `assets/js/order-audit.js`
- Modify: `meals-db-main.php` (~line 105, next to `MealsDB_Invoice_Draft_Page::init();`) — add `MealsDB_Order_Audit_Page::init();`

No standalone test for the rendered HTML (views have no harness in this repo — consistent with the invoice-draft page). Server-side behavior is already covered by Tasks 3–6; this task is rendering + wiring. Manual verification step at the end.

- [ ] **Step 1: Implement the page class**

Model on `includes/admin/class-invoice-draft-page.php` (read it first; reuse its `init()`/`register_menu`/`enqueue_scripts` shape and its list/detail routing via `$_GET['audit_id']`). Requirements:

- `init()`: `add_action('admin_menu', [...], 22)` + `admin_enqueue_scripts`.
- `register_menu()`: `add_submenu_page` under the same parent slug the invoice-draft page uses (copy the literal parent slug from that file), page title `__('Weekly Order Audit', 'meals-db')`, capability `MealsDB_Permissions::required_capability()`, slug `mealsdb-order-audit`.
- `render()`: top of the view calls `MealsDB_Permissions::enforce();` (Pattern 1 view layer).
  - **List mode** (no `audit_id` param): a week picker `<input type="date" id="oa-week-start">` prefilled with the Monday of the last COMPLETED week, computed in site timezone:
    ```php
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $today = new DateTimeImmutable('now', $tz);
    $default_monday = $today->modify('monday last week')->format('Y-m-d');
    ```
    a "Create audit draft" button (`#oa-create`), and a table of `MealsDB_Order_Audit::list_audits()` rows: week, status badge, progress `confirmed+edited / row_count`, created date, link "Open" (`?page=mealsdb-order-audit&audit_id=N`), and for drafts a "Delete" link (`.oa-delete`, `data-audit-id`).
  - **Detail mode**: load via `MealsDB_Order_Audit::get()`; render the grid — one `<tr data-order-id="N">` per `payload['current']` row ordered by `delivery_date` then `client_name`: client name, delivery date, order id, mains count, sides count (each edited row shows `snapshot→edited` deltas computed by comparing `edited_items` against `items`), status badge (`.oa-status`), note indicator (`dashicons-edit-page` when note non-empty, `title` = note), then the controls: Confirm button (`.oa-confirm`, label ✓, `aria-pressed` reflecting state) and pencil button (`.oa-edit`, `<span class="dashicons dashicons-edit"></span>`). Below the grid: progress line (`#oa-progress`, "X of N resolved") and either a Finalize button (`#oa-finalize`, `disabled` unless resolved == row_count) for drafts, or — for finalized audits — a read-only banner with finalized-by/at and an "Unfinalize" button (`#oa-unfinalize`).
  - The pencil expands an inline editor row (`<tr class="oa-editor-row">` injected by JS from a `<script type="text/template">` block or built DOM-side from data attributes): one number input per item (`min="0"`, `data-item-key`, value = edited qty if present else snapshot qty, label = product name + snapshot qty), a note `<textarea maxlength="500">`, Save / Revert to pending / Cancel buttons.
  - ALL dynamic output escaped: `esc_html()` for text, `esc_attr()` for attributes. Item names and notes are user/operator data.
- `enqueue_scripts($hook)`: only on this page's hook; enqueue `assets/js/order-audit.js` with `wp_localize_script` providing `ajaxUrl` (`admin_url('admin-ajax.php')`), `nonce` (`wp_create_nonce(MealsDB_Ajax_Order_Audit::NONCE_ACTION)`), and translated strings for confirm/delete prompts.

- [ ] **Step 2: Implement the grid JS**

Create `assets/js/order-audit.js` (jQuery, matching the admin.js idiom). Behaviors:

```text
- #oa-create click → POST mealsdb_order_audit_create {week_start: #oa-week-start.val()}
  → on success redirect to ?page=mealsdb-order-audit&audit_id=<id>
  (if resp.data.existing, same redirect — the existing audit IS the week's audit).
- .oa-confirm click → POST mealsdb_order_audit_confirm {audit_id, order_id}
  → update the row's status badge + aria-pressed, update #oa-progress and
  the #oa-finalize disabled state from resp.data counts.
- .oa-edit click → toggle the inline editor row for that order.
- editor Save → POST mealsdb_order_audit_edit {audit_id, order_id, qtys: {item_key: val}, note}
  → on success collapse editor, set badge to Edited, show deltas, update progress.
- editor "Revert to pending" → POST mealsdb_order_audit_revert → badge Pending, clear deltas/note.
- #oa-finalize click → confirm() prompt → POST mealsdb_order_audit_finalize →
  on success location.reload().
- #oa-unfinalize click → window.prompt for the reason (required, non-blank) →
  POST mealsdb_order_audit_unfinalize {audit_id, reason} → reload.
- .oa-delete click (list) → confirm() prompt → POST mealsdb_order_audit_delete → reload.
- All error responses: alert(resp.data.message) — matches the invoice-draft grid's
  error surfacing; no silent failures.
- Escape all server-provided strings before DOM insertion (reuse the page's
  rendered DOM rather than rebuilding HTML from JSON where possible).
```

- [ ] **Step 3: Register the page in `meals-db-main.php`**

Next to `MealsDB_Invoice_Draft_Page::init();` (~line 105):

```php
    MealsDB_Order_Audit_Page::init();
```

- [ ] **Step 4: Lint + full suite**

Run: `php -l includes/admin/class-order-audit-page.php && php -l includes/ajax/class-ajax-order-audit.php && php -l includes/services/class-order-audit.php`
Expected: no syntax errors.

Run: `for t in tests/test-*.php; do php "$t" >/dev/null 2>&1 || echo "FAIL: $t"; done`
Expected: only the two known-baseline dompdf failures (`test-pdf-slip-binary-output.php`, `test-vac-pdf.php`).

- [ ] **Step 5: Commit**

```bash
git add includes/admin/class-order-audit-page.php assets/js/order-audit.js meals-db-main.php
git commit -m "feat(audit): weekly order audit admin page + review grid

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: Final review + PR

- [ ] **Step 1: Re-run everything**

Run: `for t in tests/test-*.php; do php "$t" >/dev/null 2>&1 || echo "FAIL: $t"; done`
Expected: only the two dompdf baseline failures.

- [ ] **Step 2: Self-check against the spec**

Confirm each spec section maps to landed code: table+service (Tasks 1,3–5), week selection via slip generator (Task 3), grid with confirm/pencil/notes (Task 7), finalize gate + unfinalize + draft delete (Task 5), rate bucket (Task 2), STR-LOG boundary (edits→audit log, confirms→payload, failures→trunk — Tasks 3–5), record-keeping only (no allocation/billing writes anywhere — grep `Allocation` in the new files returns nothing).

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin feat/weekly-order-audit
gh pr create --title "feat: weekly order audit (draft → confirm/edit → finalize)" --body "$(cat <<'EOF'
## Summary
Weekly delivery-paperwork audit per spec docs/superpowers/specs/2026-07-30-weekly-order-audit-design.md:
- New meals_order_audits table + MealsDB_Order_Audit service (encrypted {generated,current} payload, invoice-draft-style lifecycle)
- Week's orders pulled via the packing-slip delivery-basis selection (get_orders_for_delivery_range)
- Review grid: one order per row, ✓ confirm toggle, ✎ per-item quantity editor + note
- Finalize server-gated on zero pending rows; unfinalize requires typed reason; draft-only delete
- Record-keeping only — allocations/billing/WC orders untouched
- New order_audit_edit rate bucket (1000/hr, fail-closed)

## Tests
tests/test-order-audit-schema.php, tests/test-order-audit-rate-bucket.php, tests/test-order-audit.php, tests/test-ajax-order-audit.php — all written test-first. Full suite green except the two known-baseline dompdf failures.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Do NOT merge — the operator merges on request.
