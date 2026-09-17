<?php
/**
 * Receivables ledger (K17). Append-only, immutable financial events for a
 * client or program account: a charge (positive cents), a payment (negative),
 * or an adjustment. The balance for a payer is SUM(amount_cents); receivables
 * are payers with a positive balance.
 *
 * Discipline (CLAUDE.md):
 *   - Money is INTEGER CENTS throughout (MealsDB_Money). No float, ever.
 *   - Entries are IMMUTABLE: amount_cents / entry_type / payer_* are never
 *     UPDATEd after insert. The only permitted UPDATE is stamping
 *     voided_by_entry_id on an original entry that a later offsetting row
 *     corrects — a link, not the value. Corrections are new rows, so a balance
 *     stays reconstructable.
 *   - Charges are idempotent per (source_type, source_id, payer_type, payer_id)
 *     via the charge_dedup UNIQUE key, so a repeated finalize (after an
 *     unfinalize) is a no-op, never a double-bill.
 *   - Pattern 7: every method swallows its own \Throwable and returns a
 *     sentinel (0 / [] / int) — a ledger failure must never break finalize.
 */
defined('ABSPATH') || exit;

class MealsDB_Ledger {

    public const PAYER_CLIENT  = 'client';
    public const PAYER_PROGRAM = 'program';

    public const TYPE_CHARGE     = 'charge';
    public const TYPE_PAYMENT    = 'payment';
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const SOURCE_ORDER   = 'order';
    public const SOURCE_INVOICE = 'invoice';
    public const SOURCE_MANUAL  = 'manual';

    public const MAX_NOTE_LEN = 500;

    /** @var wpdb */
    private $wpdb;

    public function __construct($wpdb = null) {
        if ($wpdb === null) {
            global $wpdb;
        }
        $this->wpdb = $wpdb;
    }

    // -----------------------------------------------------------------------
    // Posting
    // -----------------------------------------------------------------------

    /**
     * Post a CHARGE (positive cents). Idempotent per
     * (source_type, source_id, payer_type, payer_id): a duplicate is a no-op
     * that returns the existing entry_id, never a second bill. A zero/negative
     * amount posts nothing (0). Returns the entry_id, or 0.
     */
    public function post_charge(array $args): int {
        try {
            $amount = (int) ($args['amount_cents'] ?? 0);
            if ($amount <= 0) {
                // No client-side amount → no charge (directive ITEM 2).
                return 0;
            }
            $payer_type  = (string) ($args['payer_type'] ?? '');
            $payer_id    = (string) ($args['payer_id'] ?? '');
            $source_type = (string) ($args['source_type'] ?? '');
            $source_id   = isset($args['source_id']) ? (int) $args['source_id'] : 0;

            $dedup = self::charge_dedup($source_type, $source_id, $payer_type, $payer_id);

            // Idempotency: already posted → return the existing row.
            $existing = $this->existing_charge_id($dedup);
            if ($existing > 0) {
                return $existing;
            }

            $id = $this->insert_entry([
                'payer_type'   => $payer_type,
                'payer_id'     => $payer_id,
                'entry_type'   => self::TYPE_CHARGE,
                'source_type'  => $source_type,
                'source_id'    => $source_id > 0 ? $source_id : null,
                'entry_date'   => (string) ($args['entry_date'] ?? gmdate('Y-m-d')),
                'amount_cents' => $amount,
                'note'         => self::clip((string) ($args['note'] ?? '')),
                'charge_dedup' => $dedup,
                'created_by'   => isset($args['created_by']) ? (int) $args['created_by'] : null,
            ]);
            if ($id === 0) {
                // A concurrent insert won the UNIQUE race — return that row.
                $raced = $this->existing_charge_id($dedup);
                return $raced > 0 ? $raced : 0;
            }
            $this->audit_log('ledger_charge', $id, $payer_type . ':' . $payer_id, null, (string) $amount);
            return $id;
        } catch (\Throwable $e) {
            $this->log_error('post_charge', $e);
            return 0;
        }
    }

    /**
     * Record a PAYMENT. Input amount is a positive magnitude; it is stored as a
     * NEGATIVE amount_cents (it reduces the balance). Returns the entry_id, or 0.
     *
     * By default NOT deduped — partial and repeated (manual / off-cycle)
     * payments are normal. Pass a non-empty 'dedup' key to make it idempotent:
     * the collection payment posted at audit finalize uses one so re-driving
     * the poster cannot double-pay (a manual payment passes none).
     */
    public function record_payment(array $args): int {
        try {
            $amount = (int) ($args['amount_cents'] ?? 0);
            if ($amount === 0) {
                return 0;
            }
            $dedup = isset($args['dedup']) && $args['dedup'] !== '' ? (string) $args['dedup'] : null;
            if ($dedup !== null) {
                $existing = $this->existing_charge_id($dedup);
                if ($existing > 0) {
                    return $existing; // already posted for this source+payer
                }
            }
            $source_id = isset($args['source_id']) ? (int) $args['source_id'] : 0;
            $id = $this->insert_entry([
                'payer_type'   => (string) ($args['payer_type'] ?? ''),
                'payer_id'     => (string) ($args['payer_id'] ?? ''),
                'entry_type'   => self::TYPE_PAYMENT,
                'source_type'  => (string) ($args['source_type'] ?? self::SOURCE_MANUAL),
                'source_id'    => $source_id > 0 ? $source_id : null,
                'entry_date'   => (string) ($args['entry_date'] ?? gmdate('Y-m-d')),
                // Stored negative regardless of the caller's sign.
                'amount_cents' => -abs($amount),
                'method'       => self::clip((string) ($args['method'] ?? ''), 30) ?: null,
                'reference'    => self::clip((string) ($args['reference'] ?? ''), 100) ?: null,
                'note'         => self::clip((string) ($args['note'] ?? '')),
                'charge_dedup' => $dedup,
                'created_by'   => isset($args['created_by']) ? (int) $args['created_by'] : null,
            ]);
            if ($id === 0 && $dedup !== null) {
                // Lost a UNIQUE race — return the existing row.
                $raced = $this->existing_charge_id($dedup);
                return $raced > 0 ? $raced : 0;
            }
            if ($id > 0) {
                $this->audit_log('ledger_payment', $id,
                    (string) ($args['payer_type'] ?? '') . ':' . (string) ($args['payer_id'] ?? ''),
                    null, (string) (-abs($amount)));
            }
            return $id;
        } catch (\Throwable $e) {
            $this->log_error('record_payment', $e);
            return 0;
        }
    }

    /**
     * Record an ADJUSTMENT (write-off, bounced cheque, goodwill). Amount is
     * SIGNED and stored verbatim. Never deduped. Returns the entry_id, or 0.
     */
    public function record_adjustment(array $args): int {
        try {
            $amount = (int) ($args['amount_cents'] ?? 0);
            if ($amount === 0) {
                return 0;
            }
            $source_id = isset($args['source_id']) ? (int) $args['source_id'] : 0;
            $id = $this->insert_entry([
                'payer_type'   => (string) ($args['payer_type'] ?? ''),
                'payer_id'     => (string) ($args['payer_id'] ?? ''),
                'entry_type'   => self::TYPE_ADJUSTMENT,
                'source_type'  => (string) ($args['source_type'] ?? self::SOURCE_MANUAL),
                'source_id'    => $source_id > 0 ? $source_id : null,
                'entry_date'   => (string) ($args['entry_date'] ?? gmdate('Y-m-d')),
                'amount_cents' => $amount,
                'note'         => self::clip((string) ($args['note'] ?? '')),
                'charge_dedup' => null,
                'created_by'   => isset($args['created_by']) ? (int) $args['created_by'] : null,
            ]);
            if ($id > 0) {
                $this->audit_log('ledger_adjustment', $id,
                    (string) ($args['payer_type'] ?? '') . ':' . (string) ($args['payer_id'] ?? ''),
                    null, (string) $amount);
            }
            return $id;
        } catch (\Throwable $e) {
            $this->log_error('record_adjustment', $e);
            return 0;
        }
    }

    // -----------------------------------------------------------------------
    // Corrections
    // -----------------------------------------------------------------------

    /**
     * Void an entry with an offsetting adjustment (inverse amount) and stamp the
     * original's voided_by_entry_id. Never edits or deletes. Refuses to
     * re-void an already-voided entry (returns 0). Returns the new entry_id.
     */
    public function void_entry(int $entry_id, ?string $note = null, ?int $created_by = null): int {
        try {
            $orig = $this->get_entry($entry_id);
            if ($orig === null) {
                return 0;
            }
            if (($orig['voided_by_entry_id'] ?? null) !== null) {
                return 0; // already voided
            }
            $offset = $this->insert_entry([
                'payer_type'   => (string) $orig['payer_type'],
                'payer_id'     => (string) $orig['payer_id'],
                'entry_type'   => self::TYPE_ADJUSTMENT,
                'source_type'  => (string) $orig['source_type'],
                'source_id'    => isset($orig['source_id']) ? (int) $orig['source_id'] : null,
                'entry_date'   => gmdate('Y-m-d'),
                'amount_cents' => -((int) $orig['amount_cents']),
                'note'         => self::clip((string) ($note ?? '')),
                'charge_dedup' => null,
                'created_by'   => $created_by !== null ? (int) $created_by : null,
            ]);
            if ($offset === 0) {
                return 0;
            }
            // The one permitted UPDATE: link the original to its voiding row.
            $this->wpdb->update(
                $this->table(),
                ['voided_by_entry_id' => $offset],
                ['entry_id' => $entry_id],
                ['%d'],
                ['%d']
            );
            $this->audit_log('ledger_void', $offset, 'entry:' . $entry_id, null, (string) (-((int) $orig['amount_cents'])));
            return $offset;
        } catch (\Throwable $e) {
            $this->log_error('void_entry', $e);
            return 0;
        }
    }

    /**
     * Reverse every non-voided CHARGE against a source with an offsetting
     * adjustment (used when an audit/invoice is unfinalized). Nothing is
     * deleted; the balance returns to its prior state with history intact.
     * Returns the number of charges reversed.
     */
    public function reverse_charges_for_source(string $source_type, int $source_id, ?string $note = null, ?int $created_by = null): int {
        try {
            $charges = $this->charges_for_source($source_type, $source_id);
            $count = 0;
            foreach ($charges as $c) {
                if ($this->void_entry((int) $c['entry_id'], $note, $created_by) > 0) {
                    $count++;
                }
            }
            return $count;
        } catch (\Throwable $e) {
            $this->log_error('reverse_charges_for_source', $e);
            return 0;
        }
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /** Balance for a payer = SUM(amount_cents). Integer cents. */
    public function balance_for(string $payer_type, string $payer_id): int {
        try {
            $sum = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COALESCE(SUM(amount_cents), 0) FROM `{$this->table()}`
                 WHERE payer_type = %s AND payer_id = %s",
                $payer_type, $payer_id
            ));
            return (int) $sum;
        } catch (\Throwable $e) {
            $this->log_error('balance_for', $e);
            return 0;
        }
    }

    /**
     * Non-voided PAYMENT entries against a source. The unfinalize guard uses
     * this to refuse reopening a source with real collected payments beneath it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payments_for_source(string $source_type, int $source_id): array {
        try {
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT entry_id, payer_type, payer_id, amount_cents, entry_date, reference, method
                 FROM `{$this->table()}`
                 WHERE source_type = %s AND source_id = %d
                   AND entry_type = 'payment' AND voided_by_entry_id IS NULL",
                $source_type, $source_id
            ), ARRAY_A);
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            $this->log_error('payments_for_source', $e);
            return [];
        }
    }

    /**
     * Non-voided CHARGE entries against a source.
     *
     * @return array<int, array<string, mixed>>
     */
    public function charges_for_source(string $source_type, int $source_id): array {
        try {
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT entry_id, payer_type, payer_id, amount_cents, entry_date, source_type, source_id
                 FROM `{$this->table()}`
                 WHERE source_type = %s AND source_id = %d
                   AND entry_type = 'charge' AND voided_by_entry_id IS NULL",
                $source_type, $source_id
            ), ARRAY_A);
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            $this->log_error('charges_for_source', $e);
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    private function table(): string {
        return MealsDB_DB::get_table_name(MealsDB_Tables::LEDGER_ENTRIES);
    }

    /** The per-charge idempotency key. NULL-safe callers pass real values. */
    private static function charge_dedup(string $source_type, int $source_id, string $payer_type, string $payer_id): string {
        return $source_type . ':' . $source_id . ':' . $payer_type . ':' . $payer_id;
    }

    private function existing_charge_id(string $dedup): int {
        $id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT entry_id FROM `{$this->table()}` WHERE charge_dedup = %s LIMIT 1",
            $dedup
        ));
        return $id !== null ? (int) $id : 0;
    }

    private function get_entry(int $entry_id): ?array {
        if ($entry_id <= 0) {
            return null;
        }
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM `{$this->table()}` WHERE entry_id = %d LIMIT 1",
            $entry_id
        ), ARRAY_A);
        return is_array($row) && !empty($row) ? $row : null;
    }

    /** Insert one entry row. Returns the new entry_id, or 0 on failure. */
    private function insert_entry(array $row): int {
        $row['created_at'] = gmdate('Y-m-d H:i:s');
        $ok = $this->wpdb->insert($this->table(), $row);
        if ($ok === false) {
            return 0;
        }
        return (int) $this->wpdb->insert_id;
    }

    private static function clip(string $s, int $max = self::MAX_NOTE_LEN): string {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }

    /** Committed financial change → audit log (STR-LOG boundary), isolated. */
    private function audit_log(string $action, int $entry_id, string $field, ?string $old, ?string $new): void {
        if (!class_exists('MealsDB_Logger')) {
            return;
        }
        try {
            MealsDB_Logger::log($action, $entry_id, $field, $old, $new);
        } catch (\Throwable $e) {
            error_log('[MealsDB Ledger] audit log write failed: ' . $e->getMessage());
        }
    }

    private function log_error(string $op, \Throwable $e): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Ledger] ' . $op . ' failed: ' . $e->getMessage());
        }
        if (class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'error',
                'category'  => 'billing',
                'subsystem' => 'ledger',
                'event'     => $op . '.failed',
                'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message'   => $e->getMessage(),
            ]);
        }
    }
}
