<?php
/**
 * AJAX: schema-drift preview + guarded RISKY-change apply (audit H7, slice 4).
 *
 * Backs the Data-Ops "Schema changes" tool. run_full_sync auto-applies SAFE
 * column drifts on the version-bump path; what remains — RISKY changes
 * (narrow, remove-ENUM-value, tighten-to-NOT-NULL, type/sign change, any
 * DECIMAL/money change) and SAFE-but-COPY changes — is surfaced here for an
 * operator to review and, with a typed confirmation, apply.
 *
 * SECURITY: the client only names a (table, column). The actual ALTER SQL is
 * NEVER taken from the request — apply() re-detects the drift server-side from
 * the canonical schema + live column state and hands that to the executor, so a
 * caller cannot craft arbitrary DDL. Three-layer gating: nonce + manage_options
 * (both actions) + schema_rebuild rate limit + a typed "ALTER" confirmation on
 * the mutating action.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Ajax_Schema_Alter {

    public static function init(): void {
        add_action('wp_ajax_mealsdb_schema_alter_preview', [self::class, 'ajax_preview']);
        add_action('wp_ajax_mealsdb_schema_alter_apply', [self::class, 'ajax_apply']);
    }

    /** Read-only: list the pending column drifts with their risk + pre-flight. */
    public static function ajax_preview(): void {
        self::verify();

        global $wpdb;
        $executor = new MealsDB_Schema_Alter_Executor($wpdb);
        $changes  = [];
        foreach (MealsDB_Schema_Sync::detect_column_mismatches($wpdb) as $mismatch) {
            $changes[] = $executor->preview($mismatch);
        }

        wp_send_json_success(['changes' => $changes]);
    }

    /** Apply ONE confirmed change. Re-detects server-side; never trusts POSTed SQL. */
    public static function ajax_apply(): void {
        self::verify();

        // Destructive DDL — same bucket as force-rebuild (2/hr).
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('schema_rebuild')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please wait before retrying.', 'meals-db')], 429);
        }

        $confirm = isset($_POST['confirm']) ? sanitize_text_field(wp_unslash((string) $_POST['confirm'])) : '';
        if (strtoupper($confirm) !== 'ALTER') {
            wp_send_json_error(['message' => __('Type ALTER to confirm this change.', 'meals-db')]);
        }

        $table  = isset($_POST['table']) ? sanitize_text_field(wp_unslash((string) $_POST['table'])) : '';
        $column = isset($_POST['column']) ? sanitize_text_field(wp_unslash((string) $_POST['column'])) : '';

        global $wpdb;
        // Re-detect: the ALTER is generated from the canonical schema + CURRENT
        // column state, not from anything the client sent.
        $target = null;
        foreach (MealsDB_Schema_Sync::detect_column_mismatches($wpdb) as $mismatch) {
            if ($mismatch['table'] === $table && $mismatch['column'] === $column) {
                $target = $mismatch;
                break;
            }
        }
        if ($target === null) {
            wp_send_json_error(['message' => __('That column is already in sync or no longer drifted.', 'meals-db')]);
        }

        $outcome = (new MealsDB_Schema_Alter_Executor($wpdb))->run($target, true); // confirmed RISKY
        $status  = (string) ($outcome['status'] ?? 'error');

        if ($status === 'applied') {
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'  => 'info',
                    'category'  => 'schema',
                    'subsystem' => 'schema_alter_tool',
                    'event'     => 'column.altered',
                    'outcome'   => 'succeeded',
                    'message'   => sprintf('Applied a column change to %s.%s.', $table, $column),
                    'context'   => ['table' => $table, 'column' => $column],
                ]);
            }
            wp_send_json_success([
                'status'  => 'applied',
                'message' => sprintf(__('Applied change to %1$s.%2$s.', 'meals-db'), $table, $column),
            ]);
        }

        if ($status === 'blocked') {
            wp_send_json_error([
                'status'   => 'blocked',
                'message'  => __('Pre-flight found rows that would be lost — the change was NOT applied. Fix the data first.', 'meals-db'),
                'blockers' => $outcome['blockers'] ?? [],
            ]);
        }

        wp_send_json_error([
            'status'  => $status,
            'message' => (string) ($outcome['error'] ?? $outcome['reason'] ?? __('The change could not be applied.', 'meals-db')),
        ]);
    }

    /** Nonce + capability gate for both actions. */
    private static function verify(): void {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'mealsdb_schema_alter')) {
            wp_send_json_error(['message' => __('Invalid request.', 'meals-db')], 400);
        }
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to perform this action.', 'meals-db')], 403);
        }
    }
}
