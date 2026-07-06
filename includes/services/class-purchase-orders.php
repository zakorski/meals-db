<?php
/**
 * Purchase Order service — CRUD for meals_purchase_orders.
 *
 * POs are first-class entities threaded through the task workflow:
 *   place_po → arrived via confirm_po_arrival → reconciled via physical_count.
 * This class is a thin CRUD layer; all lifecycle transitions are driven
 * by task on_complete callbacks.
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
}
