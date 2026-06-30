<?php
/**
 * Midland packing-slip batch service (directive 04, Midland packing documents).
 *
 * Persistence + service layer for the packing-slip workflow:
 *   generate (persist the per-order doc 4 payloads) → [cancel].
 * Pure persistence/service: NO HTML, NO AJAX (those are unit 05/06).
 *
 * Modeled directly on MealsDB_Invoice_Draft — same disciplines:
 *   - QW-2: fail CLOSED — the doc4_payload carries decrypted client PII
 *     (name/address/phone), so it is encrypted at rest via
 *     MealsDB_Encryption::encode_payload(); a failure to encrypt is a refusal
 *     to persist, never a plaintext fallback.
 *   - STR-LOG boundary: a committed artifact (batch created) is audited by the
 *     AJAX layer (unit 05); an attempt/failure (encryption failed) goes to the
 *     operational trunk here.
 *   - Fail-safe: every public method swallows its own \Throwable and returns a
 *     sentinel (0 / null / false) — it never propagates out.
 *
 * The doc 4 payloads are the only artifact persisted, and they live encrypted
 * IN the table (the doc4_payload column) — there are no on-disk files. cancel()
 * is a hard delete of the row.
 */
defined('ABSPATH') || exit;

class MealsDB_Slip_Batch {

    /** Payload schema version, for forward migration of the JSON shape. */
    private const PAYLOAD_SCHEMA = 1;

    public const STATUS_GENERATED = 'generated';

    /**
     * Create a batch from the per-order doc 4 payloads. The caller (unit 05's
     * generate handler) supplies already-built, positional driver-block arrays
     * — one element per order, in the SAME order doc 2 is emitted. Returns the
     * batch_id, or 0 on failure (never throws).
     *
     * @param string             $zone_name     delivery zone for this batch
     * @param string             $delivery_date 'Y-m-d'
     * @param array<int,array>   $doc4_payloads ordered list of driver blocks
     * @return int batch_id, or 0 on failure.
     */
    public static function create(string $zone_name, string $delivery_date, array $doc4_payloads): int {
        try {
            $zone_name = trim($zone_name);
            if ($zone_name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date)) {
                return 0;
            }

            // Re-index to a clean 0..N-1 list so the positional pairing with
            // doc 2 (element N ↔ order N+1) is unambiguous downstream.
            $orders  = array_values($doc4_payloads);
            $payload = [
                'schema' => self::PAYLOAD_SCHEMA,
                'orders' => $orders,
            ];

            $encoded = MealsDB_Encryption::encode_payload($payload);
            if ($encoded === false) {
                // QW-2 fail-closed: no plaintext PII at rest. Surface as a
                // degraded operational event (STR-LOG: attempt/failure → trunk),
                // then bail without inserting.
                if (class_exists('MealsDB_Event_Log')) {
                    MealsDB_Event_Log::record([
                        'severity'  => 'error',
                        'category'  => 'slip_batch',
                        'subsystem' => 'slip_batch',
                        'event'     => 'create.encrypt_failed',
                        'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                        'message'   => 'Slip batch not created: doc4 payload encryption failed.',
                    ]);
                }
                return 0;
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::SLIP_BATCHES);
            $now   = gmdate('Y-m-d H:i:s');

            $ok = $wpdb->insert($table, [
                'zone_name'     => $zone_name,
                'delivery_date' => $delivery_date,
                'order_count'   => count($orders),
                'doc4_payload'  => $encoded,
                'status'        => self::STATUS_GENERATED,
                'created_by'    => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ], ['%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s']);

            if ($ok === false) {
                return 0;
            }

            return (int) $wpdb->insert_id;
        } catch (\Throwable $e) {
            self::log_error('create', $e);
            return 0;
        }
    }

    /**
     * Load + decrypt a batch. Returns null if missing or undecryptable.
     *
     * @return array|null Meta columns plus a decoded 'orders' key (the ordered
     *                    list of doc 4 driver blocks). Never returns ciphertext.
     */
    public static function get(int $batch_id): ?array {
        try {
            if ($batch_id <= 0) {
                return null;
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::SLIP_BATCHES);

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT batch_id, zone_name, delivery_date, order_count, doc4_payload,
                        status, created_by, created_at, updated_at
                 FROM `{$table}` WHERE batch_id = %d",
                $batch_id
            ), ARRAY_A);

            if (!is_array($row) || empty($row)) {
                return null;
            }

            $payload = MealsDB_Encryption::decode_payload((string) ($row['doc4_payload'] ?? ''));
            if ($payload === null) {
                // Undecryptable / corrupt payload — surface as missing rather
                // than handing back ciphertext.
                return null;
            }

            // Replace the raw encrypted column with the decoded order list.
            unset($row['doc4_payload']);
            $row['orders'] = (isset($payload['orders']) && is_array($payload['orders']))
                ? array_values($payload['orders'])
                : [];

            return $row;
        } catch (\Throwable $e) {
            self::log_error('get', $e);
            return null;
        }
    }

    /**
     * List batches (newest first), optionally filtered by zone / delivery date /
     * status. Meta only — does NOT decrypt or return the doc 4 payload, so the
     * history view never touches PII.
     *
     * @param array $filters ['zone_name'=>..., 'delivery_date'=>..., 'status'=>...]
     * @return array<int,array> meta rows.
     */
    public static function list_batches(array $filters = []): array {
        try {
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::SLIP_BATCHES);

            $where  = [];
            $params = [];
            if (!empty($filters['zone_name'])) {
                $where[]  = 'zone_name = %s';
                $params[] = (string) $filters['zone_name'];
            }
            if (!empty($filters['delivery_date'])) {
                $where[]  = 'delivery_date = %s';
                $params[] = (string) $filters['delivery_date'];
            }
            if (!empty($filters['status'])) {
                $where[]  = 'status = %s';
                $params[] = (string) $filters['status'];
            }

            // doc4_payload is deliberately NOT selected — list view needs no PII.
            $sql = "SELECT batch_id, zone_name, delivery_date, order_count,
                           status, created_by, created_at, updated_at
                    FROM `{$table}`";
            if (!empty($where)) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY created_at DESC, batch_id DESC';

            $prepared = !empty($params) ? $wpdb->prepare($sql, ...$params) : $sql;
            $rows     = $wpdb->get_results($prepared, ARRAY_A);
            if (!is_array($rows)) {
                return [];
            }

            return $rows;
        } catch (\Throwable $e) {
            self::log_error('list_batches', $e);
            return [];
        }
    }

    /**
     * Hard-delete a batch row. Operator decision (the directive): cancel is a
     * permanent delete; they regenerate from scratch if needed. The audit log
     * entry is written by the AJAX layer (unit 05), which owns the request
     * context. Returns true if the row was deleted.
     */
    public static function cancel(int $batch_id): bool {
        try {
            if ($batch_id <= 0) {
                return false;
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::SLIP_BATCHES);

            $deleted = $wpdb->delete($table, ['batch_id' => $batch_id], ['%d']);
            if ($deleted === false || (int) $deleted === 0) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            self::log_error('cancel', $e);
            return false;
        }
    }

    /**
     * Internal: breadcrumb a swallowed exception. Fail-safe — never throws.
     */
    private static function log_error(string $op, \Throwable $e): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Slip_Batch] ' . $op . ' failed: ' . $e->getMessage());
        } else {
            error_log('[MealsDB Slip_Batch] ' . $op . ' failed: ' . $e->getMessage());
        }
    }
}
