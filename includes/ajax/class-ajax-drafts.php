<?php
/**
 * AJAX handlers for Meals DB draft submission endpoints.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

/**
 * Handles AJAX requests for draft management.
 */
class MealsDB_Ajax_Drafts {

    /**
     * Register the AJAX actions for draft events.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_save_draft', [self::class, 'save_draft']);
        add_action('wp_ajax_mealsdb_delete_draft', [self::class, 'delete_draft']);
    }

    /**
     * Save incomplete submission to drafts.
     */
    public static function save_draft(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('client_modify')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }

        $payload = isset($_POST['form_data']) ? wp_unslash((string) $_POST['form_data']) : '';

        // Cap input length before parse_str. parse_str on a large/deeply
        // nested string allocates aggressively; an authorised but
        // malicious caller could otherwise push the worker into a
        // long parse / OOM condition.
        if (strlen($payload) > 65536) {
            wp_send_json_error(['message' => 'Form payload is too large.']);
        }

        parse_str($payload, $form);

        if (empty($form) || !is_array($form)) {
            wp_send_json_error(['message' => 'Invalid form data.']);
        }

        $draft_id = isset($form['draft_id']) ? intval($form['draft_id']) : null;
        unset($form['draft_id'], $form['resume_draft']);

        // Intersect against the known DB-column allowlist so attacker-
        // named keys parse_str happily accepts (e.g. nested arrays of
        // arbitrary depth) cannot make it into the stored draft.
        $form = MealsDB_Client_Form::filter_to_known_fields($form);

        if (empty($form)) {
            wp_send_json_error(['message' => 'No recognised form fields supplied.']);
        }

        $saved_id = MealsDB_Client_Form::save_draft($form, $draft_id);

        if ($saved_id === false) {
            wp_send_json_error(['message' => 'Failed to save draft.']);
        }

        wp_send_json_success([
            'message'  => 'Saved to drafts.',
            'draft_id' => intval($saved_id),
        ]);
    }

    /**
     * Delete a saved draft.
     */
    public static function delete_draft(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('client_modify')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }

        $draft_id = intval($_POST['id'] ?? 0);

        if ($draft_id <= 0) {
            wp_send_json_error(['message' => 'Invalid draft ID.']);
        }

        if (!MealsDB_Client_Form::delete_draft($draft_id)) {
            wp_send_json_error(['message' => 'Failed to delete draft.']);
        }

        wp_send_json_success(['message' => 'Draft deleted.']);
    }
}
