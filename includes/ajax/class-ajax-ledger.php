<?php
/**
 * AJAX handlers for the receivables ledger's off-cycle payment entry (K17
 * ITEM 4). Two entry points, both financial → gated at manage_options (the
 * financial tier, matching the reconciliation reports), nonce, and the
 * settings_modify rate bucket, each failing CLOSED.
 *
 *   - record_client_payment: a private client's off-cycle payment (cheque /
 *     e-transfer / cash), NOT tied to an order (source_type='manual'). Reduces
 *     the client's balance; FIFO application to specific charges is deferred.
 *   - record_program_remittance: a government remittance against an invoice
 *     (source_type='invoice', source_id=draft_id), including a PARTIAL one
 *     (SDNB paying $81,000 against an $81,829 invoice is the normal case).
 *
 * Amounts arrive in DOLLARS and are converted to integer cents server-side
 * (MealsDB_Money) — the value is cast, never absint'd, so a bad amount surfaces
 * rather than silently clamping. All posting/immutability lives in
 * MealsDB_Ledger; this layer sanitizes, gates, delegates, and envelopes.
 */
defined('ABSPATH') || exit;

class MealsDB_Ajax_Ledger {

    public const NONCE_ACTION = 'mealsdb_ledger';

    public static function init(): void {
        add_action('wp_ajax_mealsdb_ledger_client_payment',      [__CLASS__, 'record_client_payment']);
        add_action('wp_ajax_mealsdb_ledger_program_remittance',  [__CLASS__, 'record_program_remittance']);
    }

    private static function guard(): bool {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Reload and try again.', 'meals-db')], 403);
            return false;
        }
        // Financial write → admin tier (mirrors the reconciliation reports).
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'meals-db')], 403);
            return false;
        }
        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('settings_modify')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
            return false;
        }
        return true;
    }

    /** Amount (dollars) → integer cents, server-side. Cast, not absint. */
    private static function amount_cents(): int {
        $raw = isset($_POST['amount']) ? sanitize_text_field(wp_unslash($_POST['amount'])) : '';
        return class_exists('MealsDB_Money') ? MealsDB_Money::to_cents($raw) : (int) round((float) $raw * 100);
    }

    private static function opt_date(): string {
        $d = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : gmdate('Y-m-d');
    }

    public static function record_client_payment(): void {
        if (!self::guard()) { return; }
        try {
            $client_id = absint($_POST['client_id'] ?? 0);
            $amount    = self::amount_cents();
            if ($client_id <= 0) {
                wp_send_json_error(['message' => __('A client is required.', 'meals-db')]);
                return;
            }
            if ($amount <= 0) {
                wp_send_json_error(['message' => __('Enter a payment amount greater than zero.', 'meals-db')]);
                return;
            }
            $ledger = new MealsDB_Ledger();
            $id = $ledger->record_payment([
                'payer_type'   => MealsDB_Ledger::PAYER_CLIENT,
                'payer_id'     => (string) $client_id,
                'source_type'  => MealsDB_Ledger::SOURCE_MANUAL,
                'entry_date'   => self::opt_date(),
                'amount_cents' => $amount,
                'method'       => sanitize_text_field(wp_unslash($_POST['method'] ?? '')),
                'reference'    => sanitize_text_field(wp_unslash($_POST['reference'] ?? '')),
                'note'         => sanitize_text_field(wp_unslash($_POST['note'] ?? '')),
                'created_by'   => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            ]);
            if ($id <= 0) {
                wp_send_json_error(['message' => __('Could not record the payment.', 'meals-db')]);
                return;
            }
            wp_send_json_success([
                'entry_id' => $id,
                'balance'  => MealsDB_Money::format($ledger->balance_for(MealsDB_Ledger::PAYER_CLIENT, (string) $client_id)),
            ]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Ledger AJAX] client_payment failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to record the payment. Please contact an administrator.', 'meals-db')]);
        }
    }

    public static function record_program_remittance(): void {
        if (!self::guard()) { return; }
        try {
            $payer_id = strtoupper(sanitize_text_field(wp_unslash($_POST['payer_id'] ?? '')));
            $draft_id = absint($_POST['draft_id'] ?? 0);
            $amount   = self::amount_cents();
            if (!in_array($payer_id, ['SDNB', 'VAC'], true)) {
                wp_send_json_error(['message' => __('Program must be SDNB or VAC.', 'meals-db')]);
                return;
            }
            if ($amount <= 0) {
                wp_send_json_error(['message' => __('Enter a remittance amount greater than zero.', 'meals-db')]);
                return;
            }
            $ledger = new MealsDB_Ledger();
            $id = $ledger->record_payment([
                'payer_type'   => MealsDB_Ledger::PAYER_PROGRAM,
                'payer_id'     => $payer_id,
                // Tie to the invoice when given (partial remittances allowed); a
                // blank draft_id records an on-account remittance (source manual).
                'source_type'  => $draft_id > 0 ? MealsDB_Ledger::SOURCE_INVOICE : MealsDB_Ledger::SOURCE_MANUAL,
                'source_id'    => $draft_id > 0 ? $draft_id : 0,
                'entry_date'   => self::opt_date(),
                'amount_cents' => $amount,
                'method'       => sanitize_text_field(wp_unslash($_POST['method'] ?? '')),
                'reference'    => sanitize_text_field(wp_unslash($_POST['reference'] ?? '')),
                'created_by'   => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            ]);
            if ($id <= 0) {
                wp_send_json_error(['message' => __('Could not record the remittance.', 'meals-db')]);
                return;
            }
            wp_send_json_success([
                'entry_id' => $id,
                'balance'  => MealsDB_Money::format($ledger->balance_for(MealsDB_Ledger::PAYER_PROGRAM, $payer_id)),
            ]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Ledger AJAX] program_remittance failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to record the remittance. Please contact an administrator.', 'meals-db')]);
        }
    }
}
