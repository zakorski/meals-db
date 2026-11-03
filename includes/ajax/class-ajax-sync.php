<?php
/**
 * AJAX handlers for Meals DB synchronization endpoints.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

/**
 * Handles AJAX requests related to synchronization operations.
 */
class MealsDB_Ajax_Sync {

    /**
     * Register the AJAX actions for synchronization events.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_toggle_ignore', [self::class, 'toggle_ignore']);
        add_action('wp_ajax_mealsdb_sync_conflicts_apply', [self::class, 'apply_conflict_choices']);
        add_action('wp_ajax_mealsdb_check_updates', [self::class, 'check_updates']);
        add_action('wp_ajax_mealsdb_run_update', [self::class, 'run_update']);
        add_action('wp_ajax_mealsdb_update_database', [self::class, 'update_database']);
        add_action('wp_ajax_mealsdb_fetch_products', [self::class, 'fetch_products']);
    }

    // K21 ITEM 10: the legacy mealsdb_sync_field endpoint was removed. It
    // wrote a VALUE posted by the browser over a populated field in either
    // system, with no stale-page check — exactly what K21 forbids outside the
    // conflict resolution path. Linked-pair differences are now resolved in
    // the conflict view (mealsdb_sync_conflicts_apply), which reads the
    // chosen value server-side.

    /**
     * K21 ITEM 9: apply an operator's choices for ONE account's conflicts in
     * one request (batching — one sync_operations token per account, not per
     * field, so working a large queue does not hit the 100/hour limit).
     *
     * POST: nonce, wp_user_id, choices = JSON list of
     *   {conflict_id: int, values_hash: 64-hex, action: dismiss|undismiss|keep,
     *    side: wp|client:<id> (keep only)}
     *
     * Own nonce context (mealsdb_sync_conflicts), not the general
     * mealsdb_nonce: ITEM 10 adds value-overwriting "keep" choices to this
     * endpoint, and destructive actions get their own nonce (CLAUDE.md §13).
     * The service layer re-checks capability and re-reads every value before
     * acting (stale-page guard).
     */
    public static function apply_conflict_choices(): void {
        check_ajax_referer('mealsdb_sync_conflicts', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('Unauthorized', 'meals-db')], 403);
            return;
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('sync_operations')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
            return;
        }

        $wp_user_id = absint($_POST['wp_user_id'] ?? 0);
        $raw        = (string) wp_unslash($_POST['choices'] ?? '');
        // 64 choices of three short fields is well under 16 KB; anything
        // larger is not a real submission.
        $decoded = strlen($raw) <= 16384 ? json_decode($raw, true) : null;

        if ($wp_user_id <= 0 || !is_array($decoded) || $decoded === []
            || count($decoded) > MealsDB_Sync_Conflict_View::MAX_CHOICES) {
            wp_send_json_error(['message' => __('Invalid request.', 'meals-db')], 400);
            return;
        }

        $choices = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                wp_send_json_error(['message' => __('Invalid request.', 'meals-db')], 400);
                return;
            }
            $hash   = strtolower((string) ($item['values_hash'] ?? ''));
            $action = (string) ($item['action'] ?? '');
            $side   = (string) ($item['side'] ?? '');
            if (!preg_match('/^[0-9a-f]{64}$/', $hash) || !in_array($action, MealsDB_Sync_Conflict_View::ACTIONS, true)
                || ($action === MealsDB_Sync_Conflict_View::ACTION_KEEP && !preg_match('/^(wp|client:[1-9]\d*)$/', $side))) {
                wp_send_json_error(['message' => __('Invalid request.', 'meals-db')], 400);
                return;
            }
            // Only WHICH side to keep travels from the browser — never a value.
            $choices[] = [
                'conflict_id' => absint($item['conflict_id'] ?? 0),
                'values_hash' => $hash,
                'action'      => $action,
                'side'        => $action === MealsDB_Sync_Conflict_View::ACTION_KEEP ? $side : '',
            ];
        }

        try {
            $result = MealsDB_Sync_Conflict_View::apply_choices($wp_user_id, $choices, get_current_user_id());
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB AJAX] apply_conflict_choices failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Could not apply the choices.', 'meals-db')], 500);
            return;
        }

        if ($result['error'] !== null) {
            wp_send_json_error(['message' => $result['error']], 400);
            return;
        }

        wp_send_json_success(['results' => $result['results']]);
    }

    /**
     * Toggle a mismatch to be ignored or re-enabled.
     */
    public static function toggle_ignore(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('sync_operations')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }

        $field_name  = sanitize_text_field(wp_unslash($_POST['field'] ?? ''));
        // $source / $target go straight into the IGNORED_CONFLICTS
        // table as row values the next call compares against. Anything
        // longer than a real field value represents a probing attempt
        // or a paste accident; cap to 1 KB so a hostile caller can't
        // push arbitrarily large rows into the table and inflate the
        // DELETE-by-value path.
        $source      = substr((string) sanitize_text_field(wp_unslash($_POST['source'] ?? '')), 0, 1024);
        $target      = substr((string) sanitize_text_field(wp_unslash($_POST['target'] ?? '')), 0, 1024);
        $set_ignored = MealsDB_Helpers::bool_flag($_POST['ignored'] ?? null, false);

        // Whitelist field_name against the canonical sync field set so the
        // ignore-conflicts table can't be poisoned with arbitrary
        // attacker-supplied "fields" via this endpoint.
        $allowed_fields = array_merge(
            MealsDB_Sync::get_wp_authoritative_fields(),
            MealsDB_Sync::get_mealsdb_authoritative_fields()
        );
        if ($field_name === '' || !in_array($field_name, $allowed_fields, true)) {
            wp_send_json_error(['message' => 'Unsupported sync field.']);
        }

        $user_id = get_current_user_id();
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::IGNORED_CONFLICTS);

        if ($set_ignored) {
            // Treat duplicate (field, source, target) tuples as a no-op so a
            // double-click doesn't accumulate identical rows that the
            // unignore branch would then have to delete in bulk.
            $result = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO `{$table}` (field_name, source_value, target_value, ignored_by) VALUES (%s, %s, %s, %d)",
                $field_name, $source, $target, $user_id
            ));
        } else {
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` WHERE field_name = %s AND source_value = %s AND target_value = %s",
                $field_name, $source, $target
            ));
        }

        if ($result === false) {
            error_log('[MealsDB AJAX] Failed executing ignore toggle: ' . $wpdb->last_error);
            wp_send_json_error(['message' => 'Failed to update ignore status.']);
        }

        wp_send_json_success(['message' => $set_ignored ? 'Ignored' : 'Unignored']);
    }

    /**
     * Check Git repository for available updates.
     */
    public static function check_updates(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        // Deploying/checking plugin code is a manage_options operation, not the
        // baseline manage_woocommerce. The service layer re-checks too.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'meals-db')], 403);
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('sync_operations')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }

        $result = MealsDB_Updates::check_for_updates();

        if (is_wp_error($result)) {
            self::handle_update_failure($result);
        }

        wp_send_json_success($result);
    }

    /**
     * Pull the latest changes from the Git repository.
     */
    public static function run_update(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        // Deploys code over the live plugin dir — manage_options only. The
        // service layer (MealsDB_Updates::pull_updates) re-checks as well.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'meals-db')], 403);
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('migration_destructive')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }

        $result = MealsDB_Updates::pull_updates();

        if (is_wp_error($result)) {
            self::handle_update_failure($result);
        }

        wp_send_json_success($result);
    }

    /**
     * Log shell stderr / process detail server-side and return a
     * generic error message to the client. Git stderr can include
     * filesystem paths, remote URLs, and credentials; surfacing it
     * to the AJAX caller is information disclosure.
     */
    private static function handle_update_failure(\WP_Error $result): void {
        $data   = $result->get_error_data();
        $stderr = is_array($data) && isset($data['stderr']) ? trim((string) $data['stderr']) : '';
        if ($stderr !== '') {
            error_log('[MealsDB Updates] ' . $result->get_error_code() . ': ' . $stderr);
        }
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    /**
     * Run database maintenance to ensure the schema matches the latest version.
     */
    public static function update_database(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        // Runs the installer (DDL) — manage_options only, and rate-limited (it
        // was previously the one update endpoint with no throttle). The service
        // layer re-checks and serialises via the install lock.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'meals-db')], 403);
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('migration_destructive')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }

        $result = MealsDB_Updates::run_database_maintenance();

        if (is_wp_error($result)) {
            self::handle_update_failure($result);
        }

        wp_send_json_success($result);
    }

    /**
     * Ensure plugin products exist for every WooCommerce product.
     */
    public static function fetch_products(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('sync_operations')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }

        $result = MealsDB_Updates::fetch_products_from_woocommerce();

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
            ]);
        }

        wp_send_json_success($result);
    }
}
