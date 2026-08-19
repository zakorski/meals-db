<?php
/**
 * AJAX handlers for the Purchase Order draft workflow (spec 2026-07-10).
 *
 * Ten endpoints, each carrying the plugin guard spine in order:
 *   1. nonce       (check_ajax_referer, fail-closed; one context for the
 *                   family, like the invoice-draft endpoints)
 *   2. capability  (BASELINE plugin capability, NOT manage_options: PO rows
 *                   are SKUs and case counts — no client PII, no billing —
 *                   matching the rest of the purchasing area. Deliberate,
 *                   operator-approved divergence from invoice drafts.)
 *   3. rate limit  (mutating buckets, fail-closed)
 *   4. validate    (server-side; SKUs are checked against the stored payload
 *                   in the service — never trusted from the form)
 *   5. act + JSON  (outer catch(\Throwable) — never a bare 500)
 *
 * Committed changes (draft created/edited/approved/received/reconciled) are
 * audited in the SERVICE layer — do not double-log here (STR-LOG boundary).
 *
 * The task system is deliberately untouched: approving a PO spawns no task,
 * and the two inventory statics the service reuses do not create tasks.
 *
 * Author: Fishhorn Design
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Ajax_Purchase_Orders {

    /** One nonce context covers the whole PO-workflow family. */
    public const NONCE_ACTION = 'mealsdb_po_nonce';

    public static function init(): void {
        add_action('wp_ajax_mealsdb_po_save_draft',         [__CLASS__, 'save_draft']);
        add_action('wp_ajax_mealsdb_po_edit_cases',         [__CLASS__, 'edit_cases']);
        add_action('wp_ajax_mealsdb_po_reconcile_edit',     [__CLASS__, 'reconcile_edit']);
        add_action('wp_ajax_mealsdb_po_approve',            [__CLASS__, 'approve']);
        add_action('wp_ajax_mealsdb_po_unapprove',          [__CLASS__, 'unapprove']);
        add_action('wp_ajax_mealsdb_po_mark_accepted',      [__CLASS__, 'mark_accepted']);
        add_action('wp_ajax_mealsdb_po_unaccept',           [__CLASS__, 'unaccept']);
        add_action('wp_ajax_mealsdb_po_mark_received',      [__CLASS__, 'mark_received']);
        add_action('wp_ajax_mealsdb_po_complete_reconcile', [__CLASS__, 'complete_reconcile']);
        add_action('wp_ajax_mealsdb_po_cancel',             [__CLASS__, 'cancel']);
    }

    /**
     * Forecast tab "Generate draft PO" (one-click flow, spec 2026-07-11).
     * The rows are REGENERATED server-side rather than accepted from the
     * browser: the on-screen page is display data, not a trusted payload.
     * Drafts are ALWAYS pallet-optimized — the preview and its optimize
     * toggle were removed; the optimizer is a pure post-processor over the
     * forecast rows (test-po-freight-optimization.php) and the draft page
     * is where the operator reviews and edits the result.
     */
    public static function save_draft(): void {
        if (!self::guard('client_modify')) {
            return;
        }
        try {
            $reports   = new MealsDB_Reports($GLOBALS['wpdb']);
            $optimized = MealsDB_Reports::optimize_po_for_pallets($reports->generate_purchase_order());
            $rows      = $optimized['rows'];
            $service = new MealsDB_Purchase_Orders();
            $po_id   = $service->create_draft($rows);
            if ($po_id <= 0) {
                wp_send_json_error(['message' => __('Could not save the draft purchase order.', 'meals-db')]);
                return;
            }
            wp_send_json_success(['po_id' => $po_id]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB PO AJAX] save_draft failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save the draft. Please try again.', 'meals-db')]);
        }
    }

    /** Draft-mode +/- row save. */
    public static function edit_cases(): void {
        if (!self::guard('po_draft_edit')) {
            return;
        }
        try {
            $po_id = isset($_POST['po_id']) ? absint($_POST['po_id']) : 0;
            $sku   = sanitize_text_field(wp_unslash($_POST['sku'] ?? ''));
            $cases = self::read_int_param('cases');
            if ($po_id <= 0 || $sku === '' || $cases === null) {
                wp_send_json_error(['message' => __('Missing or malformed parameters.', 'meals-db')]);
                return;
            }
            $service = new MealsDB_Purchase_Orders();
            self::respond($service->edit_draft_cases($po_id, $sku, $cases));
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB PO AJAX] edit_cases failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save the change. Please try again.', 'meals-db')]);
        }
    }

    /** Reconcile-mode +/- and note row save. */
    public static function reconcile_edit(): void {
        if (!self::guard('po_draft_edit')) {
            return;
        }
        try {
            $po_id = isset($_POST['po_id']) ? absint($_POST['po_id']) : 0;
            $sku   = sanitize_text_field(wp_unslash($_POST['sku'] ?? ''));
            $cases = self::read_int_param('received_cases');
            // Raw here; the service sanitizes + length-caps.
            $note  = (string) wp_unslash($_POST['note'] ?? '');
            if ($po_id <= 0 || $sku === '' || $cases === null) {
                wp_send_json_error(['message' => __('Missing or malformed parameters.', 'meals-db')]);
                return;
            }
            $service = new MealsDB_Purchase_Orders();
            self::respond($service->edit_reconcile_row($po_id, $sku, $cases, $note));
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB PO AJAX] reconcile_edit failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save the change. Please try again.', 'meals-db')]);
        }
    }

    public static function approve(): void            { self::transition_endpoint('approve'); }
    public static function unapprove(): void          { self::transition_endpoint('unapprove'); }
    public static function mark_accepted(): void      { self::transition_endpoint('mark_accepted'); }
    public static function unaccept(): void           { self::transition_endpoint('unaccept'); }
    public static function mark_received(): void      { self::transition_endpoint('mark_received'); }
    public static function complete_reconcile(): void { self::transition_endpoint('complete_reconcile'); }
    public static function cancel(): void             { self::transition_endpoint('cancel_draft'); }

    // -----------------------------------------------------------------
    // Shared plumbing
    // -----------------------------------------------------------------

    /**
     * All lifecycle transitions share one shape: settings_modify bucket
     * (destructive-ish, 20/hr), a po_id, and for unapprove/unaccept a required
     * reason (accept/receive/cancel/complete need only the po_id).
     */
    private static function transition_endpoint(string $method): void {
        if (!self::guard('settings_modify')) {
            return;
        }
        try {
            $po_id = isset($_POST['po_id']) ? absint($_POST['po_id']) : 0;
            if ($po_id <= 0) {
                wp_send_json_error(['message' => __('Missing purchase order id.', 'meals-db')]);
                return;
            }
            $service = new MealsDB_Purchase_Orders();
            if ($method === 'unapprove' || $method === 'unaccept') {
                $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));
                $result = $service->{$method}($po_id, $reason);
            } elseif ($method === 'approve') {
                // Optional expected-arrival date for the confirm-arrival task's
                // due date; the service normalizes (malformed → null → the
                // bridge falls back to +7 days).
                $expected = sanitize_text_field(wp_unslash($_POST['expected_arrival'] ?? ''));
                $result   = $service->approve($po_id, $expected !== '' ? $expected : null);
            } else {
                $result = $service->{$method}($po_id);
            }
            self::respond($result);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB PO AJAX] ' . $method . ' failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('The action failed. Please try again.', 'meals-db')]);
        }
    }

    /** Map a service result (true | array | WP_Error) onto the JSON contract. */
    private static function respond($result): void {
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
                'data'    => $result->get_error_data(),
            ]);
            return;
        }
        wp_send_json_success(is_array($result) ? $result : ['done' => true]);
    }

    /**
     * Read a whole-number POST param. Returns null on anything non-numeric or
     * fractional ('1e3'-style scientific input is normalized via round(), the
     * same rule the invoice-draft editor applies to counts).
     */
    private static function read_int_param(string $key): ?int {
        $raw = wp_unslash($_POST[$key] ?? '');
        if (!is_scalar($raw) || !is_numeric($raw) || (float) $raw != floor((float) $raw)) {
            return null;
        }
        return (int) round((float) $raw);
    }

    /**
     * Guard spine: nonce → capability → rate limit, each failing CLOSED with
     * a JSON error. Returns true only if all three pass.
     */
    private static function guard(string $rate_bucket): bool {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token.', 'meals-db')]);
            return false;
        }
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'meals-db')], 403);
            return false;
        }
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit($rate_bucket)) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
            return false;
        }
        return true;
    }
}
