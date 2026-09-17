<?php
/**
 * Posts receivables-ledger entries when a weekly order audit is finalized, and
 * enforces the unfinalize rules (K17 ITEM 2a / 3 / 5, audit side).
 *
 * On audit finalize, per order with a client-side amount:
 *   - a CHARGE (client payer) for the computed amount (SDNB/Veteran = fee
 *     lines; Private = order total at audited quantities — see the calculator);
 *   - a PAYMENT for a row the operator marked COLLECTED (the collected amount,
 *     which may be a partial). Unreviewed / outstanding rows post no payment.
 *
 * On audit unfinalize:
 *   - BLOCKED if any payment exists against the audit's orders (a collected
 *     payment is a real event; reversing the charge beneath it would leave an
 *     unexplained credit);
 *   - otherwise the charges are reversed with offsetting adjustments (nothing
 *     is deleted — history stays intact).
 *
 * The WooCommerce-dependent price resolution is isolated in resolve_context()
 * and injectable via $deps['context'], so the posting logic is unit-testable
 * with no WC and no float. Pattern 7: a ledger failure must never break
 * finalize, so post_audit_charges swallows and logs.
 */
defined('ABSPATH') || exit;

class MealsDB_Ledger_Poster {

    /**
     * Post charges (+ collected payments) for a FINALIZED audit. Idempotent on
     * the charge side (the ledger dedupes per order+client), so a repeated call
     * never double-bills. Returns stats.
     *
     * @param array $deps ledger:MealsDB_Ledger, context:callable(row):array{client_type,prices}, user:int
     * @return array{charges:int, payments:int, skipped:int}
     */
    public static function post_audit_charges(int $audit_id, array $deps = []): array {
        $stats = ['charges' => 0, 'payments' => 0, 'skipped' => 0];
        try {
            $audit = MealsDB_Order_Audit::get($audit_id);
            if ($audit === null || ($audit['status'] ?? '') !== MealsDB_Order_Audit::STATUS_FINALIZED) {
                // Charges post on finalize only — a draft contributes nothing.
                return $stats;
            }
            $ledger  = $deps['ledger']  ?? new MealsDB_Ledger();
            $context = $deps['context'] ?? static function (array $row) {
                return self::resolve_context($row);
            };
            $user = isset($deps['user']) ? (int) $deps['user']
                : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);

            $current = (isset($audit['payload']['current']) && is_array($audit['payload']['current']))
                ? $audit['payload']['current'] : [];

            foreach ($current as $row) {
                $client_id = (int) ($row['client_id'] ?? 0);
                $order_id  = (int) ($row['order_id'] ?? 0);
                if ($client_id <= 0 || $order_id <= 0) {
                    $stats['skipped']++;
                    continue;
                }
                $ctx    = (array) $context($row);
                $type   = (string) ($ctx['client_type'] ?? '');
                $prices = (array) ($ctx['prices'] ?? []);
                $charge = MealsDB_Ledger_Charge_Calculator::client_charge_cents($type, $row, $prices);
                $entry_date = (string) ($row['delivery_date'] ?? '');

                if ($charge > 0) {
                    $posted = $ledger->post_charge([
                        'payer_type'   => MealsDB_Ledger::PAYER_CLIENT,
                        'payer_id'     => (string) $client_id,
                        'source_type'  => MealsDB_Ledger::SOURCE_ORDER,
                        'source_id'    => $order_id,
                        'entry_date'   => $entry_date,
                        'amount_cents' => $charge,
                        'created_by'   => $user,
                    ]);
                    if ($posted > 0) {
                        $stats['charges']++;
                    }
                } else {
                    $stats['skipped']++;
                }

                // A collected row posts a payment (negative). Unreviewed /
                // outstanding rows post nothing — "we haven't asked" is not paid.
                if (($row['collection_state'] ?? '') === 'collected') {
                    $amount = (int) ($row['collected_amount_cents'] ?? 0);
                    if ($amount <= 0) {
                        // Default a collected-but-unspecified amount to the charge.
                        $amount = $charge;
                    }
                    if ($amount > 0) {
                        $pid = $ledger->record_payment([
                            'payer_type'   => MealsDB_Ledger::PAYER_CLIENT,
                            'payer_id'     => (string) $client_id,
                            'source_type'  => MealsDB_Ledger::SOURCE_ORDER,
                            'source_id'    => $order_id,
                            'entry_date'   => $entry_date,
                            'amount_cents' => $amount,
                            'method'       => (string) ($row['collection_method'] ?? ''),
                            // Idempotent per order+client: re-driving finalize
                            // must not double-pay this collected row (a manual
                            // off-cycle payment passes no dedup and stays free).
                            'dedup'        => 'pay:order:' . $order_id . ':client:' . $client_id,
                            'created_by'   => $user,
                        ]);
                        if ($pid > 0) {
                            $stats['payments']++;
                        }
                    }
                }
            }
            return $stats;
        } catch (\Throwable $e) {
            self::log_error('post_audit_charges', $e);
            return $stats;
        }
    }

    /**
     * Non-voided payments recorded against ANY of a finalized audit's orders.
     * The audit unfinalize guard refuses to reopen when this is non-empty.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function audit_payments(int $audit_id, ?MealsDB_Ledger $ledger = null): array {
        try {
            $audit = MealsDB_Order_Audit::get($audit_id);
            if ($audit === null) {
                return [];
            }
            $ledger  = $ledger ?? new MealsDB_Ledger();
            $current = (isset($audit['payload']['current']) && is_array($audit['payload']['current']))
                ? $audit['payload']['current'] : [];
            $out = [];
            foreach ($current as $row) {
                $order_id = (int) ($row['order_id'] ?? 0);
                if ($order_id <= 0) {
                    continue;
                }
                foreach ($ledger->payments_for_source(MealsDB_Ledger::SOURCE_ORDER, $order_id) as $p) {
                    $out[] = $p;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            self::log_error('audit_payments', $e);
            return [];
        }
    }

    /**
     * Reverse every charge posted for a (finalized→reopened) audit's orders with
     * offsetting adjustments. Nothing is deleted. Returns the count reversed.
     */
    public static function reverse_audit_charges(int $audit_id, ?string $note = null, ?int $user = null, ?MealsDB_Ledger $ledger = null): int {
        try {
            $audit = MealsDB_Order_Audit::get($audit_id);
            if ($audit === null) {
                return 0;
            }
            $ledger  = $ledger ?? new MealsDB_Ledger();
            $user    = $user ?? (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
            $current = (isset($audit['payload']['current']) && is_array($audit['payload']['current']))
                ? $audit['payload']['current'] : [];
            $count = 0;
            foreach ($current as $row) {
                $order_id = (int) ($row['order_id'] ?? 0);
                if ($order_id <= 0) {
                    continue;
                }
                $count += $ledger->reverse_charges_for_source(MealsDB_Ledger::SOURCE_ORDER, $order_id, $note, $user);
            }
            return $count;
        } catch (\Throwable $e) {
            self::log_error('reverse_audit_charges', $e);
            return 0;
        }
    }

    // -----------------------------------------------------------------------
    // Invoice side (K17 ITEM 2b): one PROGRAM charge per finalized invoice.
    // -----------------------------------------------------------------------

    /**
     * Post the single program charge for a finalized invoice draft: payer 'SDNB'
     * or 'VAC' (from the pipeline), amount = the invoice grand total (the exact
     * billed total, via the generator), source = the draft. One entry for the
     * WHOLE invoice — SDNB remits against the invoice, so a per-client breakdown
     * would only have to be re-aggregated to match a single payment. Idempotent
     * (charge dedup), so a re-finalize never double-bills the program.
     *
     * @param array $ctx pipeline, current(rows), entry_date, [amount_cents], [draft_id]
     * @return int entry_id, or 0
     */
    public static function post_invoice_charge(int $draft_id, array $ctx, array $deps = []): int {
        try {
            $pipeline = (string) ($ctx['pipeline'] ?? '');
            $payer_id = self::invoice_payer_id($pipeline);
            if ($payer_id === '') {
                return 0; // unknown pipeline → nothing to post
            }
            $amount = isset($ctx['amount_cents'])
                ? (int) $ctx['amount_cents']
                : (class_exists('MealsDB_Invoice_Generator')
                    ? MealsDB_Invoice_Generator::draft_grand_total_cents($pipeline, (array) ($ctx['current'] ?? []))
                    : 0);
            if ($amount <= 0) {
                return 0;
            }
            $ledger = $deps['ledger'] ?? new MealsDB_Ledger();
            $user = isset($deps['user']) ? (int) $deps['user']
                : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
            return $ledger->post_charge([
                'payer_type'   => MealsDB_Ledger::PAYER_PROGRAM,
                'payer_id'     => $payer_id,
                'source_type'  => MealsDB_Ledger::SOURCE_INVOICE,
                'source_id'    => $draft_id,
                'entry_date'   => (string) ($ctx['entry_date'] ?? gmdate('Y-m-d')),
                'amount_cents' => $amount,
                'created_by'   => $user,
            ]);
        } catch (\Throwable $e) {
            self::log_error('post_invoice_charge', $e);
            return 0;
        }
    }

    /** Non-voided payments against an invoice draft (unfinalize guard). */
    public static function invoice_payments(int $draft_id, ?MealsDB_Ledger $ledger = null): array {
        $ledger = $ledger ?? new MealsDB_Ledger();
        return $ledger->payments_for_source(MealsDB_Ledger::SOURCE_INVOICE, $draft_id);
    }

    /** Reverse the program charge for a (reopened) invoice draft. */
    public static function reverse_invoice_charges(int $draft_id, ?string $note = null, ?int $user = null, ?MealsDB_Ledger $ledger = null): int {
        $ledger = $ledger ?? new MealsDB_Ledger();
        $user   = $user ?? (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        return $ledger->reverse_charges_for_source(MealsDB_Ledger::SOURCE_INVOICE, $draft_id, $note, $user);
    }

    private static function invoice_payer_id(string $pipeline): string {
        if ($pipeline === 'vac') {
            return 'VAC';
        }
        if ($pipeline === 'sdnb_legacy' || $pipeline === 'sdnb_new_portal') {
            return 'SDNB';
        }
        return '';
    }

    // -----------------------------------------------------------------------
    // Production price/type resolution (WooCommerce). Injectable for tests.
    // -----------------------------------------------------------------------

    /**
     * Resolve the client type and per-order price context for one audit row,
     * from meals_clients + the WC order's line items / fee lines. Isolated here
     * so post_audit_charges stays pure and testable via $deps['context'].
     *
     * @return array{client_type:string, prices:array<string,mixed>}
     */
    public static function resolve_context(array $row): array {
        $out = ['client_type' => '', 'prices' => []];
        try {
            $client_id = (int) ($row['client_id'] ?? 0);
            $order_id  = (int) ($row['order_id'] ?? 0);
            if ($client_id <= 0 || $order_id <= 0) {
                return $out;
            }

            global $wpdb;
            $clients = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
            $out['client_type'] = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT client_type FROM `{$clients}` WHERE client_id = %d LIMIT 1",
                $client_id
            ));

            $fee_ids = [];
            if (class_exists('MealsDB_Invoice_Generator')) {
                $fee_ids = MealsDB_Invoice_Generator::get_fee_product_ids();
            }
            $contribution_pid = (int) ($fee_ids['client_contribution'] ?? 0);
            $delivery_pid     = (int) ($fee_ids['delivery_fee'] ?? 0);

            $contribution_cents = 0;
            $delivery_fee_cents = 0;
            $line_unit_cents    = [];

            if (class_exists('MealsDB_WC_Order_Query')) {
                $query = new MealsDB_WC_Order_Query($wpdb);
                foreach ($query->get_order_items([$order_id]) as $item) {
                    $pid      = (int) ($item['wc_product_id'] ?? 0);
                    $subtotal = MealsDB_Money::to_cents((string) ($item['line_subtotal'] ?? '0'));
                    $qty      = (int) ($item['quantity'] ?? 0);
                    $item_key = (int) ($item['order_item_id'] ?? 0);

                    if ($pid > 0 && $pid === $contribution_pid) {
                        $contribution_cents += $subtotal;
                        continue;
                    }
                    if ($pid > 0 && $pid === $delivery_pid) {
                        $delivery_fee_cents += $subtotal;
                        continue;
                    }
                    // A delivery line: unit price = line subtotal / ordered qty.
                    if ($item_key > 0 && $qty > 0) {
                        $line_unit_cents[$item_key] = (int) round($subtotal / $qty);
                    }
                }
            }

            // Added items (Private): price per unit from the product catalogue.
            $added_unit_cents = [];
            foreach ((array) ($row['added_items'] ?? []) as $added) {
                $pid = (int) ($added['product_id'] ?? 0);
                if ($pid > 0 && function_exists('wc_get_product')) {
                    $product = wc_get_product($pid);
                    if ($product) {
                        $added_unit_cents[$pid] = MealsDB_Money::to_cents((string) $product->get_price());
                    }
                }
            }

            $out['prices'] = [
                'contribution_cents' => $contribution_cents,
                'delivery_fee_cents' => $delivery_fee_cents,
                'line_unit_cents'    => $line_unit_cents,
                'added_unit_cents'   => $added_unit_cents,
            ];
            return $out;
        } catch (\Throwable $e) {
            self::log_error('resolve_context', $e);
            return $out;
        }
    }

    private static function log_error(string $op, \Throwable $e): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Ledger Poster] ' . $op . ' failed: ' . $e->getMessage());
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
