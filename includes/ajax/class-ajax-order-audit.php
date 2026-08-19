<?php
/**
 * AJAX handlers for the Weekly Order Audit UI (Task 6).
 *
 * Defense in depth — this layer is one of three guarding every mutation
 * (CLAUDE.md Pattern 1):
 *   1. HERE (transport): nonce + capability + rate limit, in that order, each
 *      failing CLOSED with a JSON error (see guard()).
 *   2. The VIEW layer (views/order-audit.php) re-enforces the capability at the
 *      top of the page — a caller reaching an endpoint never went through it.
 *   3. The SERVICE (MealsDB_Order_Audit) re-checks draft/finalized STATE on
 *      every write (mutate_row / finalize / delete refuse a finalized record).
 * Each layer is independent; none is load-bearing alone.
 *
 * This is a RECORD-KEEPING surface only. Nothing here touches allocations,
 * billing, or WC orders — the service snapshot IS the artifact. All value
 * validation and state enforcement lives in MealsDB_Order_Audit; these
 * handlers sanitize input, delegate, and translate the service's
 * true|string|int|WP_Error results into the success/error JSON envelope. The
 * outer catch(\Throwable) guarantees no bare 500 escapes to the browser.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Ajax_Order_Audit {

    /** One nonce action covers all seven endpoints (each still verifies it). */
    public const NONCE_ACTION = 'mealsdb_order_audit';

    public static function init(): void {
        add_action('wp_ajax_mealsdb_order_audit_create',     [__CLASS__, 'create']);
        add_action('wp_ajax_mealsdb_order_audit_confirm',    [__CLASS__, 'confirm']);
        add_action('wp_ajax_mealsdb_order_audit_edit',       [__CLASS__, 'edit']);
        add_action('wp_ajax_mealsdb_order_audit_revert',     [__CLASS__, 'revert']);
        add_action('wp_ajax_mealsdb_order_audit_finalize',   [__CLASS__, 'finalize']);
        add_action('wp_ajax_mealsdb_order_audit_unfinalize', [__CLASS__, 'unfinalize']);
        add_action('wp_ajax_mealsdb_order_audit_delete',     [__CLASS__, 'delete_draft']);
        add_action('wp_ajax_mealsdb_order_audit_products',   [__CLASS__, 'products']);
    }

    // -----------------------------------------------------------------
    // Endpoints
    // -----------------------------------------------------------------

    /**
     * Build a fresh audit draft for a week. The client supplies only the
     * Monday; the endpoint validates it, derives the Sunday, and (to keep one
     * audit per week) returns an existing audit unchanged rather than creating
     * a duplicate. An empty week (no delivered orders) is a valid zero-row
     * draft, not an error — only a null build result (DB failure) is an error.
     */
    public static function create(): void {
        if (!self::guard('order_audit_edit')) { return; }
        try {
            $week_start = sanitize_text_field(wp_unslash($_POST['week_start'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start)
                // gmdate (not date) so the weekday is always evaluated in UTC
                // regardless of server timezone — Pattern 11, CLAUDE.md.
                || (int) gmdate('N', (int) strtotime($week_start . ' UTC')) !== 1) {
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

    /**
     * Toggle a row confirmed <-> pending. The service returns the NEW row
     * status; we echo it plus fresh progress counters for the grid header.
     */
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

    /**
     * Record a discrepancy: per-item received quantities + a note.
     *
     * The quantity VALUES are cast with (int), NOT absint: absint('-2') is 2
     * and would silently CLAMP a negative to a plausible-looking positive,
     * bypassing the service's own negative-qty rejection. That silent rewrite
     * is exactly the bug-class this codebase documents (units clamping,
     * wp_user_id canonicalisation) — so we pass -2 through and let
     * MealsDB_Order_Audit::edit_row reject it with a clean message. Only the
     * KEY (the item id) is absint'd, since a negative id is never valid.
     */
    public static function edit(): void {
        if (!self::guard('order_audit_edit')) { return; }
        try {
            $audit_id = absint($_POST['audit_id'] ?? 0);
            $order_id = absint($_POST['order_id'] ?? 0);
            $note     = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
            $qtys     = [];
            foreach ((array) wp_unslash($_POST['qtys'] ?? []) as $k => $v) {
                $qtys[absint($k)] = (int) $v;
            }
            // Added items: product_id + qty only. Name/SKU are resolved
            // server-side in edit_row from the catalogue — anything the client
            // sends for those is ignored. qty passes through as (int) so the
            // service's own >= 1 rejection fires (not silently clamped).
            $added = [];
            foreach ((array) wp_unslash($_POST['added'] ?? []) as $entry) {
                if (!is_array($entry)) { continue; }
                $added[] = [
                    'product_id' => absint($entry['product_id'] ?? 0),
                    'qty'        => (int) ($entry['qty'] ?? 0),
                ];
            }
            $result = MealsDB_Order_Audit::edit_row($audit_id, $order_id, $qtys, $note, $added);
            if ($result instanceof WP_Error) {
                wp_send_json_error(['message' => $result->get_error_message()]);
                return;
            }
            wp_send_json_success(self::progress($audit_id, ['status' => 'edited']));
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Order_Audit AJAX] edit failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save. Please contact an administrator.', 'meals-db')]);
        }
    }

    /** Discard a row's edit/confirm back to pristine pending. */
    public static function revert(): void {
        if (!self::guard('order_audit_edit')) { return; }
        try {
            $audit_id = absint($_POST['audit_id'] ?? 0);
            $order_id = absint($_POST['order_id'] ?? 0);
            $result   = MealsDB_Order_Audit::revert_row($audit_id, $order_id);
            if ($result instanceof WP_Error) {
                wp_send_json_error(['message' => $result->get_error_message()]);
                return;
            }
            wp_send_json_success(self::progress($audit_id, ['status' => 'pending']));
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Order_Audit AJAX] revert failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save. Please contact an administrator.', 'meals-db')]);
        }
    }

    /**
     * Lock the audit read-only. The service refuses unless every row is
     * confirmed or edited (server-side gate — the JS disable is convenience,
     * not enforcement), surfacing its own message on a pending row.
     */
    public static function finalize(): void {
        if (!self::guard('order_audit_edit')) { return; }
        try {
            $audit_id = absint($_POST['audit_id'] ?? 0);
            $result   = MealsDB_Order_Audit::finalize($audit_id);
            if ($result instanceof WP_Error) {
                wp_send_json_error(['message' => $result->get_error_message()]);
                return;
            }
            wp_send_json_success(['finalized' => true]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Order_Audit AJAX] finalize failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save. Please contact an administrator.', 'meals-db')]);
        }
    }

    /**
     * Reopen a finalized audit. A non-blank reason is REQUIRED, but the
     * blank check is owned by the service (single source of truth); we pass
     * the sanitized reason straight through and translate its WP_Error.
     */
    public static function unfinalize(): void {
        if (!self::guard('order_audit_edit')) { return; }
        try {
            $audit_id = absint($_POST['audit_id'] ?? 0);
            $reason   = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
            $result   = MealsDB_Order_Audit::unfinalize($audit_id, $reason);
            if ($result instanceof WP_Error) {
                wp_send_json_error(['message' => $result->get_error_message()]);
                return;
            }
            wp_send_json_success(['unfinalized' => true]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Order_Audit AJAX] unfinalize failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save. Please contact an administrator.', 'meals-db')]);
        }
    }

    /** Delete a DRAFT audit so a bad week pull can be redone. Draft-only (service-enforced). */
    public static function delete_draft(): void {
        if (!self::guard('order_audit_edit')) { return; }
        try {
            $audit_id = absint($_POST['audit_id'] ?? 0);
            $result   = MealsDB_Order_Audit::delete_draft($audit_id);
            if ($result instanceof WP_Error) {
                wp_send_json_error(['message' => $result->get_error_message()]);
                return;
            }
            wp_send_json_success(['deleted' => true]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Order_Audit AJAX] delete failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save. Please contact an administrator.', 'meals-db')]);
        }
    }

    /**
     * Product catalogue for the Add-Item dropdown: id + name + SKU only, from
     * the shared QO product cache (no new product search). Read-only.
     */
    public static function products(): void {
        if (!self::guard('order_audit_edit')) { return; }
        try {
            $out = [];
            if (class_exists('MealsDB_Quick_Order_Products')) {
                foreach (MealsDB_Quick_Order_Products::get_all_quick_order_products() as $p) {
                    $pid = (int) ($p['product_id'] ?? 0);
                    if ($pid <= 0) { continue; }
                    $out[] = [
                        'product_id' => $pid,
                        'name'       => (string) ($p['name'] ?? ''),
                        'sku'        => (string) ($p['sku'] ?? ''),
                    ];
                }
            }
            wp_send_json_success(['products' => $out]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Order_Audit AJAX] products failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to load products. Please try again.', 'meals-db')]);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** Progress counters for the grid header / finalize button state. */
    private static function progress(int $audit_id, array $extra = []): array {
        $audit = MealsDB_Order_Audit::get($audit_id);
        return array_merge($extra, [
            'row_count'       => $audit ? (int) $audit['row_count'] : 0,
            'confirmed_count' => $audit ? (int) $audit['confirmed_count'] : 0,
            'edited_count'    => $audit ? (int) $audit['edited_count'] : 0,
        ]);
    }

    /**
     * The shared guard spine (CLAUDE.md Pattern 1): nonce → capability → rate
     * limit, in that order, each failing CLOSED with a JSON error. Returns true
     * only when all three pass; on false it has already emitted the error.
     */
    private static function guard(string $rate_bucket): bool {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token.', 'meals-db')]);
            return false;
        }
        // Baseline plugin capability (manage_woocommerce by default) — the
        // audit grid shows client names + item counts, the same exposure as
        // packing slips, NOT the decrypted-ID PII that pushed the invoice
        // draft grid to manage_options.
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
}
