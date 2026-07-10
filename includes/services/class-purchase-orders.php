<?php
/**
 * Purchase Order service — CRUD for meals_purchase_orders, plus the
 * draft-workflow lifecycle (spec 2026-07-10).
 *
 * Workflow states (status column doubles as state):
 *   planned=Draft → placed=Approved → arrived=Received → reconciled
 * Transitions are guarded here; each step is driven by a direct service
 * call (create_draft, approve_draft, etc.), NOT by task on_complete callbacks.
 *
 * payload IS NULL ⇒ legacy task-created PO.  The workflow refuses to touch
 * those rows — their lifecycle still belongs to the task chain (prevents a
 * task and a list action from double-applying the same inventory bump).
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Purchase_Orders {

    public const STATUS_PLANNED    = 'planned';
    public const STATUS_PLACED     = 'placed';
    public const STATUS_ARRIVED    = 'arrived';
    public const STATUS_RECONCILED = 'reconciled';
    public const STATUS_CANCELLED  = 'cancelled';

    /**
     * PO draft workflow (spec 2026-07-10). The existing statuses double as
     * the workflow states — displayed via status_label():
     *   planned=Draft, placed=Approved, arrived=Received, reconciled, cancelled.
     * payload IS NULL ⇒ legacy task-created PO: the new workflow refuses to
     * touch it (its lifecycle belongs to the task chain — prevents a task and
     * a list action double-applying the same inventory bump).
     */
    public const PAYLOAD_SCHEMA = 1;

    /** Mirrors the forecast model's 9-week coverage target (class-reports.php). */
    public const COVERAGE_TARGET_WEEKS = 9.0;

    /** Mirrors the pallet-optimizer's 7-week safety floor (class-reports.php). */
    public const COVERAGE_FLOOR_WEEKS = 7.0;

    public const DEFAULT_SUPPLIER = 'Apetito';

    private const MAX_CASES    = 10000; // fat-finger ceiling — enforced by edit_draft_cases (Task 3)
    private const MAX_NOTE_LEN = 500;   // reconcile note length cap — enforced by reconcile_draft (Task 6)

    /**
     * Valid PO statuses.
     *
     * The lifecycle is: PLANNED → PLACED → ARRIVED → RECONCILED,
     * with CANCELLED available as a terminal state from any prior
     * state.
     *
     * HISTORY: A `STATUS_COUNTED` constant was previously declared
     * but never set anywhere. The physical_count task handler does
     * both the count and the reconcile in one operation, so the
     * intermediate state had no place in the workflow. Removed for
     * clarity. The schema ENUM in class-schema.php still tolerates
     * 'counted' for now — a dev-side `SELECT COUNT(*) ... WHERE
     * status='counted'` check is required before tightening the
     * ENUM, since any orphan row would fail the new constraint.
     */
    public const ALLOWED_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_PLACED,
        self::STATUS_ARRIVED,
        self::STATUS_RECONCILED,
        self::STATUS_CANCELLED,
    ];

    /** @var wpdb */
    private $wpdb;

    public function __construct($wpdb = null) {
        if ($wpdb === null) {
            global $wpdb;
        }
        $this->wpdb = $wpdb;
    }

    /**
     * Create a PO row. Returns po_id, or 0 on failure (including uniqueness
     * conflicts on po_number).
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): int {
        $po_number = isset($data['po_number']) ? trim((string) $data['po_number']) : '';
        if ($po_number === '') {
            error_log('[MealsDB Purchase Orders] create: po_number required.');
            return 0;
        }

        $status = isset($data['status']) ? (string) $data['status'] : self::STATUS_PLANNED;
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = self::STATUS_PLANNED;
        }

        $items = [];
        if (isset($data['items']) && is_array($data['items'])) {
            $items = array_values($data['items']);
        }

        $row = [
            'po_number'        => $po_number,
            'supplier'         => isset($data['supplier']) ? (string) $data['supplier'] : null,
            'placed_date'      => self::normalize_date($data['placed_date'] ?? null),
            'expected_arrival' => self::normalize_date($data['expected_arrival'] ?? null),
            'arrival_date'     => self::normalize_date($data['arrival_date'] ?? null),
            'status'           => $status,
            'items'            => MealsDB_Task_Engine::encode_json($items),
            'notes'            => isset($data['notes']) ? (string) $data['notes'] : null,
        ];

        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::PURCHASE_ORDERS);
        $result = $this->wpdb->insert($table, $row);
        if ($result === false) {
            error_log('[MealsDB Purchase Orders] insert failed: ' . $this->wpdb->last_error);
            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $po_id): ?array {
        if ($po_id <= 0) {
            return null;
        }
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PURCHASE_ORDERS);
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE po_id = %d", $po_id),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        return self::hydrate($row);
    }

    /**
     * Apply a partial update.
     *
     * @param array<string, mixed> $updates
     */
    public function update(int $po_id, array $updates): bool {
        if ($po_id <= 0) {
            return false;
        }

        $row = [];
        foreach (['po_number', 'supplier', 'notes'] as $field) {
            if (array_key_exists($field, $updates)) {
                $row[$field] = $updates[$field] !== null ? (string) $updates[$field] : null;
            }
        }
        foreach (['placed_date', 'expected_arrival', 'arrival_date'] as $field) {
            if (array_key_exists($field, $updates)) {
                $row[$field] = self::normalize_date($updates[$field]);
            }
        }
        if (array_key_exists('status', $updates)) {
            $status = (string) $updates['status'];
            if (in_array($status, self::ALLOWED_STATUSES, true)) {
                $row['status'] = $status;
            }
        }
        if (array_key_exists('items', $updates)) {
            $items = is_array($updates['items']) ? array_values($updates['items']) : [];
            $row['items'] = MealsDB_Task_Engine::encode_json($items);
        }
        if (array_key_exists('reconciled_at', $updates)) {
            $row['reconciled_at'] = $updates['reconciled_at'] !== null
                ? (string) $updates['reconciled_at']
                : null;
        }

        if (empty($row)) {
            return true;
        }

        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::PURCHASE_ORDERS);
        $result = $this->wpdb->update($table, $row, ['po_id' => $po_id]);
        if ($result === false) {
            error_log('[MealsDB Purchase Orders] update failed: ' . $this->wpdb->last_error);
            return false;
        }
        return true;
    }

    /**
     * Query POs.
     *
     * Supported filters: status (string|array), placed_date_after/before,
     * expected_arrival_after/before, limit, offset, order_by.
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(array $filters): array {
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PURCHASE_ORDERS);
        $where = [];
        $args = [];

        if (!empty($filters['status'])) {
            $statuses = (array) $filters['status'];
            $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $where[] = "status IN ({$placeholders})";
            foreach ($statuses as $s) { $args[] = (string) $s; }
        }
        foreach (['placed_date', 'expected_arrival', 'arrival_date'] as $col) {
            if (!empty($filters[$col . '_after'])) {
                $where[] = "{$col} >= %s";
                $args[] = (string) $filters[$col . '_after'];
            }
            if (!empty($filters[$col . '_before'])) {
                $where[] = "{$col} <= %s";
                $args[] = (string) $filters[$col . '_before'];
            }
        }

        $where_sql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

        // Whitelist order_by.
        $order = 'created_at DESC';
        if (!empty($filters['order_by']) && is_string($filters['order_by'])) {
            $parts = preg_split('/\s+/', trim($filters['order_by']));
            $col   = $parts[0] ?? '';
            $dir   = strtoupper($parts[1] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
            if (in_array($col, ['po_number', 'placed_date', 'expected_arrival', 'arrival_date', 'status', 'created_at'], true)) {
                $order = sprintf('`%s` %s', $col, $dir);
            }
        }

        $limit  = isset($filters['limit']) ? max(1, min(1000, (int) $filters['limit'])) : 200;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $sql = "SELECT * FROM `{$table}` {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d";
        $args[] = $limit;
        $args[] = $offset;

        $prepared = $this->wpdb->prepare($sql, $args);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        return array_map([self::class, 'hydrate'], $rows);
    }

    /**
     * Hydrate DB row: decode items JSON and cast ints.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function hydrate(array $row): array {
        $row['po_id'] = (int) ($row['po_id'] ?? 0);
        if (isset($row['items']) && is_string($row['items'])) {
            $decoded = json_decode($row['items'], true);
            $row['items'] = is_array($decoded) ? $decoded : [];
        } elseif (!isset($row['items']) || !is_array($row['items'])) {
            $row['items'] = [];
        }
        return $row;
    }

    /**
     * Accept a YYYY-MM-DD string or null; anything malformed becomes null.
     *
     * @param mixed $value
     */
    private static function normalize_date($value): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    // -----------------------------------------------------------------
    // Draft workflow (spec 2026-07-10) — creation + reads
    // -----------------------------------------------------------------

    /**
     * Persist a generated forecast as a Draft PO. $rows is the output of
     * MealsDB_Reports::generate_purchase_order() (optionally pallet-optimized);
     * each row is snapshotted with its demand/stock context so the coverage
     * warnings stay deterministic for the life of the draft.
     *
     * Returns po_id, or 0 on failure.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $meta supplier / notes overrides
     */
    public function create_draft(array $rows, array $meta = []): int {
        // Defense-in-depth: service-layer capability re-check (Pattern 1).
        if (class_exists('MealsDB_Permissions') && !MealsDB_Permissions::can_access_plugin()) {
            return 0;
        }

        $payload_rows = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $sku = trim((string) ($r['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $case_size = max(1, (int) ($r['case_size'] ?? 1));
            $cases     = max(0, (int) ($r['cases_to_buy'] ?? 0));
            $payload_rows[] = [
                'sku'                 => $sku,
                'product_name'        => (string) ($r['product_name'] ?? ''),
                'case_size'           => $case_size,
                'cases'               => $cases,
                'order_quantity'      => $cases * $case_size,
                'adjusted_weekly'     => round((float) ($r['adjusted_weekly'] ?? 0), 2),
                'current_stock'       => (int) ($r['current_stock'] ?? 0),
                'seasonal_index'      => round((float) ($r['seasonal_index'] ?? 1), 2),
                'freight_delta_cases' => (int) ($r['freight_delta_cases'] ?? 0),
                'seasonal_note'       => (string) ($r['seasonal_note'] ?? ''),
            ];
        }
        if (empty($payload_rows)) {
            error_log('[MealsDB Purchase Orders] create_draft: no usable rows.');
            return 0;
        }

        $payload = [
            'schema'    => self::PAYLOAD_SCHEMA,
            'generated' => $payload_rows,
            'current'   => $payload_rows,
            'received'  => [], // sku => {received_cases, note}, reconcile session
        ];

        // Fail CLOSED: a draft whose payload didn't encode would read back as
        // payload=NULL and masquerade as a legacy task PO (Pattern 7 — never
        // pretend the work happened). Bad UTF-8 in a product name is the
        // realistic trigger; reject it here rather than persisting an
        // uneditable row.
        $encoded = wp_json_encode($payload);
        if (!is_string($encoded) || $encoded === '' || $encoded === 'null') {
            error_log('[MealsDB Purchase Orders] create_draft: payload encode failed.');
            return 0;
        }

        $row = [
            'po_number'  => 'PO-' . gmdate('Ymd-His'),
            'supplier'   => isset($meta['supplier']) ? (string) $meta['supplier'] : self::DEFAULT_SUPPLIER,
            'status'     => self::STATUS_PLANNED,
            // items stays empty until approval — it is the "what was actually
            // ordered" contract consumed by apply_inventory_bump/_adjustments.
            'items'      => MealsDB_Task_Engine::encode_json([]),
            'notes'      => isset($meta['notes']) ? (string) $meta['notes'] : null,
            'payload'    => $encoded,
            'edit_count' => 0,
            'created_by' => get_current_user_id() ?: null,
        ];

        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::PURCHASE_ORDERS);
        $result = $this->wpdb->insert($table, $row);
        if ($result === false) {
            $is_dup = stripos((string) $this->wpdb->last_error, 'duplicate') !== false;
            if (!$is_dup) {
                error_log('[MealsDB Purchase Orders] create_draft insert failed: ' . $this->wpdb->last_error);
                return 0;
            }
            // uniq_po_number backstop: a second save in the same second collides.
            // One suffixed retry covers the realistic single-operator case; a third
            // same-second save fails loudly rather than guessing further.
            $row['po_number'] .= '-2';
            $result = $this->wpdb->insert($table, $row);
            if ($result === false) {
                error_log('[MealsDB Purchase Orders] create_draft insert failed: ' . $this->wpdb->last_error);
                return 0;
            }
        }

        $po_id = (int) $this->wpdb->insert_id;
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('po_draft_created', $po_id, 'status', null, self::STATUS_PLANNED);
        }
        return $po_id;
    }

    /**
     * get() plus decoded workflow payload. payload === null ⇒ legacy
     * task-created PO (or corrupt JSON, treated the same: read-only).
     *
     * @return array<string, mixed>|null
     */
    public function get_with_payload(int $po_id): ?array {
        $po = $this->get($po_id);
        if ($po === null) {
            return null;
        }
        if (isset($po['payload']) && is_string($po['payload']) && $po['payload'] !== '') {
            $decoded = json_decode($po['payload'], true);
            $po['payload'] = (is_array($decoded) && isset($decoded['current']) && is_array($decoded['current']))
                ? $decoded : null;
        } else {
            $po['payload'] = null;
        }
        return $po;
    }

    /**
     * Weeks of coverage for a payload row: (stock snapshot + cases×case_size)
     * ÷ adjusted weekly demand. Null when demand is zero (coverage undefined —
     * the UI shows no warning). $cases overrides the row's stored count so the
     * same math serves draft edits and reconcile previews.
     */
    public static function coverage_weeks(array $row, ?int $cases = null): ?float {
        $weekly = (float) ($row['adjusted_weekly'] ?? 0);
        if ($weekly <= 0) {
            return null;
        }
        $cases = $cases ?? (int) ($row['cases'] ?? 0);
        $units = (int) ($row['current_stock'] ?? 0) + $cases * max(1, (int) ($row['case_size'] ?? 1));
        return round($units / $weekly, 1);
    }

    /** Operator-facing label for a status (planned displays as Draft, etc). */
    public static function status_label(string $status): string {
        switch ($status) {
            case self::STATUS_PLANNED:    return __('Draft', 'meals-db');
            case self::STATUS_PLACED:     return __('Approved', 'meals-db');
            case self::STATUS_ARRIVED:    return __('Received', 'meals-db');
            case self::STATUS_RECONCILED: return __('Reconciled', 'meals-db');
            case self::STATUS_CANCELLED:  return __('Cancelled', 'meals-db');
            default:                      return $status; // legacy 'counted'
        }
    }

    // -----------------------------------------------------------------
    // Draft workflow — case edits (+/- buttons)
    // -----------------------------------------------------------------

    /**
     * Set the ordered case count for one row of a Draft PO. Validates status,
     * SKU membership, and range; bumps edit_count and audits only on an actual
     * change. Coverage warnings are the caller's concern — this never blocks
     * on the 9/7-week thresholds (spec: warnings, not clamps).
     *
     * @return array{changed: bool, cases: int, order_quantity: int, coverage_weeks: float|null}|WP_Error
     */
    public function edit_draft_cases(int $po_id, string $sku, int $cases) {
        if (class_exists('MealsDB_Permissions') && !MealsDB_Permissions::can_access_plugin()) {
            return new WP_Error('forbidden', __('Insufficient permissions.', 'meals-db'));
        }
        if ($cases < 0 || $cases > self::MAX_CASES) {
            return new WP_Error('bad_cases', __('Case count is out of the allowed range.', 'meals-db'));
        }

        $po = $this->require_workflow_po($po_id, self::STATUS_PLANNED,
            __('Only draft purchase orders can be edited.', 'meals-db'));
        if (is_wp_error($po)) {
            return $po;
        }

        $idx = self::find_row_index($po['payload']['current'], $sku);
        if ($idx === null) {
            return new WP_Error('unknown_sku', __('Unknown SKU for this purchase order.', 'meals-db'));
        }

        $row = $po['payload']['current'][$idx];
        $old = (int) ($row['cases'] ?? 0);
        if ($old === $cases) {
            return [
                'changed'        => false,
                'cases'          => $old,
                'order_quantity' => (int) ($row['order_quantity'] ?? 0),
                'coverage_weeks' => self::coverage_weeks($row),
            ];
        }

        $po['payload']['current'][$idx]['cases']          = $cases;
        $po['payload']['current'][$idx]['order_quantity'] = $cases * max(1, (int) ($row['case_size'] ?? 1));

        if (!$this->write_payload($po_id, $po['payload'], self::STATUS_PLANNED, (int) $po['edit_count'] + 1)) {
            return new WP_Error('save_failed',
                __('Could not save the change (the draft may have just been approved) — reload.', 'meals-db'));
        }
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('po_draft_edit', $po_id, $sku, (string) $old, (string) $cases);
        }

        $updated = $po['payload']['current'][$idx];
        return [
            'changed'        => true,
            'cases'          => $cases,
            'order_quantity' => (int) $updated['order_quantity'],
            'coverage_weeks' => self::coverage_weeks($updated),
        ];
    }

    /**
     * Load a PO and require it to be a workflow PO (payload present) in the
     * expected status. Returns the hydrated PO array or a WP_Error.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function require_workflow_po(int $po_id, string $expected_status, string $locked_message) {
        $po = $this->get_with_payload($po_id);
        if ($po === null) {
            return new WP_Error('not_found', __('Purchase order not found.', 'meals-db'));
        }
        if (!is_array($po['payload'])) {
            return new WP_Error('legacy',
                __('This purchase order was created by the task workflow and cannot be modified here.', 'meals-db'));
        }
        if ((string) ($po['status'] ?? '') !== $expected_status) {
            return new WP_Error('locked', $locked_message);
        }
        return $po;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function find_row_index(array $rows, string $sku): ?int {
        foreach ($rows as $i => $row) {
            if ((string) ($row['sku'] ?? '') === $sku) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Persist the payload under the same status guard the transitions use, so
     * an edit racing an approve loses cleanly (0 rows) instead of mutating a
     * locked PO. edit_count is written as a value (not col+1): a lost
     * increment between two same-second edits is acceptable for an
     * informational counter.
     */
    private function write_payload(int $po_id, array $payload, string $expected_status, int $edit_count): bool {
        $table  = MealsDB_DB::get_table_name(MealsDB_Tables::PURCHASE_ORDERS);
        $result = $this->wpdb->update(
            $table,
            [
                'payload'    => MealsDB_Task_Engine::encode_json($payload),
                'edit_count' => $edit_count,
            ],
            ['po_id' => $po_id, 'status' => $expected_status]
        );
        return $result === 1;
    }
}
