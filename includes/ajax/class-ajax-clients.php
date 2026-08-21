<?php
/**
 * AJAX handlers for Meals DB client management endpoints.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

/**
 * Handles AJAX requests for client management.
 */
class MealsDB_Ajax_Clients {

    /**
     * Register the AJAX actions for client management events.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_link_client', [self::class, 'link_client']);
        add_action('wp_ajax_mealsdb_link_client_to_wp_user', [self::class, 'link_client_to_wp_user']);
        add_action('wp_ajax_mealsdb_activate_client', [self::class, 'activate_client']);
        add_action('wp_ajax_mealsdb_deactivate_client', [self::class, 'deactivate_client']);
        add_action('wp_ajax_mealsdb_delete_client', [self::class, 'delete_client']);
        // GUI-F3F5-v2: anchor client creation to a validated WP user.
        add_action('wp_ajax_mealsdb_validate_wp_user', [self::class, 'validate_wp_user']);
        add_action('wp_ajax_mealsdb_pull_wp_user_data', [self::class, 'pull_wp_user_data']);
    }

    /**
     * Validate that a WordPress User ID maps to a real user, echoing the
     * user's billing name so the operator can visually confirm the right
     * person before linking. Read-only. Directive GUI-F3F5-v2 STEP 1a.
     *
     * Also reports whether that WP user is ALREADY linked to a client — a
     * warning, not a block: per audit MAJ-1 a shared wp_user_id is rare but
     * legitimate (dual SDNB/Veteran recipient, or a govt client buying extra
     * meals personally), so the operator is informed rather than stopped.
     */
    public static function validate_wp_user(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('Unauthorized', 'meals-db')]);
        }

        self::enforce_lookup_rate_limit();

        $uid = isset($_POST['wp_user_id']) ? absint(wp_unslash($_POST['wp_user_id'])) : 0;
        if ($uid <= 0) {
            wp_send_json_error(['message' => __('Enter a positive WordPress User ID.', 'meals-db')]);
        }

        try {
            $user = get_userdata($uid);
            if (!$user instanceof WP_User) {
                wp_send_json_error(['message' => __('No WordPress user with that ID.', 'meals-db')]);
            }

            // Optional current client_id: present on the Edit form, 0/absent on Add. We use it
            // to distinguish a (correct, expected) self-link from a real dual-use warning, so the
            // operator isn't alarmed by a client linked to its own WP user.
            $current_client_id = isset($_POST['client_id']) ? absint(wp_unslash($_POST['client_id'])) : 0;

            $existing = MealsDB_Clients_Repository::find_client_id_by_wp_user($uid);
            // Ask whether the CURRENT client row itself carries this wp_user_id, rather than
            // whether the lowest match equals it. find_client_id_by_wp_user() collapses a shared
            // wp_user_id to the lowest client_id, so an equality test would mis-flag every
            // later client in a legitimately-shared set (audit MAJ-1) as an alarming dual-use
            // link to a different client instead of recognising its own WP user.
            $is_self = ($current_client_id > 0)
                && MealsDB_Clients_Repository::client_has_wp_user($current_client_id, $uid);

            wp_send_json_success([
                'wp_user_id'     => $uid,
                'name'           => self::resolve_billing_name($uid, $user),
                'already_linked' => $existing ? (int) $existing : null,
                // True when the WP user is linked to the client currently being edited.
                'already_linked_self' => $is_self,
            ]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Ajax] validate_wp_user failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to validate the WordPress user.', 'meals-db')]);
        }
    }

    /**
     * Pull a validated WP user's identity/contact/address/preference usermeta
     * and return a form-field map the client form drops into its inputs, so the
     * operator isn't re-typing data that already exists on the WP user. Reuses
     * the migration's proven mapping via MealsDB_WP_User_Mapper (no drift).
     * Directive GUI-F3F5-v2 STEP 1b.
     */
    public static function pull_wp_user_data(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('Unauthorized', 'meals-db')]);
        }

        self::enforce_lookup_rate_limit();

        $uid = isset($_POST['wp_user_id']) ? absint(wp_unslash($_POST['wp_user_id'])) : 0;
        if ($uid <= 0) {
            wp_send_json_error(['message' => __('Enter a positive WordPress User ID.', 'meals-db')]);
        }

        try {
            $user = get_userdata($uid);
            if (!$user instanceof WP_User) {
                wp_send_json_error(['message' => __('No WordPress user with that ID.', 'meals-db')]);
            }

            $fields = MealsDB_WP_User_Mapper::map_usermeta_to_client_fields($uid);

            wp_send_json_success([
                'wp_user_id' => $uid,
                'name'       => self::resolve_billing_name($uid, $user),
                'fields'     => $fields,
                'count'      => count($fields),
            ]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Ajax] pull_wp_user_data failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to load data from the WordPress user.', 'meals-db')]);
        }
    }

    /**
     * Resolve a WP user's display name for inline confirmation: billing name
     * first (the operator's choice), then the WP account name, then the
     * display name. Mirrors the migration's name resolution.
     */
    private static function resolve_billing_name(int $uid, WP_User $user): string {
        $first = (string) get_user_meta($uid, 'billing_first_name', true);
        if ($first === '') {
            $first = (string) ($user->first_name ?? '');
        }
        $last = (string) get_user_meta($uid, 'billing_last_name', true);
        if ($last === '') {
            $last = (string) ($user->last_name ?? '');
        }
        $name = trim($first . ' ' . $last);
        if ($name === '') {
            $name = (string) ($user->display_name ?? '');
        }
        return $name;
    }

    /**
     * Rate-limit the read-only WP-user lookup endpoints. Uses the client_search
     * bucket (a read bucket, 100/hour) rather than the client_modify mutate
     * bucket, since Validate/Pull only READ WP user data during data entry and
     * a hand-entry session may legitimately fire several lookups.
     */
    private static function enforce_lookup_rate_limit(): void {
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('client_search')) {
            wp_send_json_error(
                ['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')],
                429
            );
        }
    }

    /**
     * Link a Meals DB client record to a WordPress user.
     */
    public static function link_client(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        self::enforce_rate_limit();

        $client_id = intval($_POST['client_id'] ?? 0);
        $wp_user_id = intval($_POST['wp_user_id'] ?? 0);

        if ($client_id <= 0 || $wp_user_id <= 0) {
            wp_send_json_error(['message' => __('Invalid client or WordPress user.', 'meals-db')]);
        }

        $result = MealsDB_Sync::link_client_to_wordpress_user($client_id, $wp_user_id);

        if (is_wp_error($result)) {
            $message = $result->get_error_message();
            if ($message === '') {
                $message = __('Failed to link client.', 'meals-db');
            }

            wp_send_json_error(['message' => $message]);
        }

        wp_send_json_success([
            'message' => __('Client linked successfully.', 'meals-db'),
        ]);
    }

    /**
     * Link a Meals DB client record directly to a WordPress user ID.
     *
     * COLUMN NAME NOTE: The DB column on meals_clients is `wp_user_id`,
     * NOT `wordpress_user_id` (which is the form-side vocabulary, and
     * also the canonical name on meals_staff — they're different
     * tables). A previous version used `wordpress_user_id` here; the
     * repository's filter_to_known_columns silently dropped it, the
     * read returned null, and the handler reported success while
     * doing nothing. See CLAUDE.md "Form-side vs DB-side column
     * names".
     */
    public static function link_client_to_wp_user(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        self::enforce_rate_limit();

        $client_id = intval($_POST['client_id'] ?? 0);
        $wp_user_id = isset($_POST['wp_user_id']) ? intval($_POST['wp_user_id']) : null;

        if ($client_id <= 0 || $wp_user_id === null || $wp_user_id < 0) {
            wp_send_json_error(['message' => __('Invalid client or WordPress user.', 'meals-db')]);
        }

        // A positive id must reference a real WP user — the allocation rebuilder
        // routes orders via wp_user_id ↔ wc_orders.customer_id, so linking to a
        // nonexistent user silently breaks billing. (0 means "unlink".) The form
        // path already enforces this; mirror it here.
        if ($wp_user_id > 0 && !get_userdata($wp_user_id)) {
            wp_send_json_error(['message' => __('That WordPress user does not exist.', 'meals-db')]);
        }

        $conn = MealsDB_DB::get_connection();

        if (!$conn) {
            wp_send_json_error(['message' => __('Database connection failed.', 'meals-db')]);
        }

        $repository = new MealsDB_Clients_Repository($conn);

        $client_row = $repository->get_client_by_id($client_id);
        if (!$client_row) {
            wp_send_json_error(['message' => __('Client not found.', 'meals-db')]);
        }

        $existing_wp_user_id = null;
        $raw_existing = $client_row['wp_user_id'] ?? null;
        if ($raw_existing !== null && $raw_existing !== '') {
            $existing_wp_user_id = (int) $raw_existing;
        }

        // Duplicate-wp_user_id soft guard (audit MAJ-1 / U22-ajax-misc-2).
        // wp_user_id is intentionally NOT UNIQUE — one WordPress user legitimately
        // maps to two client rows (a dual SDNB/Veteran recipient, or a government
        // client buying extra meals personally). But the allocation rebuilder routes
        // orders via wp_user_id <-> wc_orders.customer_id, so a SILENT duplicate makes
        // routing nondeterministic. The sibling guarded path (MealsDB_Sync_Mutate::
        // link_meals_client_to_wc_user) warns-and-allows here; this direct-update path
        // skipped the check entirely. Mirror the sibling: allow the link, but record a
        // degraded trunk event so the duplicate is greppable rather than silent (an
        // attempt/outcome -> the operational trunk, NOT the audit log below, which is
        // reserved for the committed data change itself). 0 = unlink, never a duplicate.
        if ($wp_user_id > 0) {
            $duplicate_of = MealsDB_Clients_Repository::find_client_id_by_wp_user($wp_user_id);
            if ($duplicate_of !== null && $duplicate_of !== $client_id
                && class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'    => 'warning',
                    'category'    => 'sync',
                    'subsystem'   => 'ajax_clients',
                    'event'       => 'link_wp_user.duplicate_allowed',
                    'outcome'     => MealsDB_Event_Log::OUTCOME_DEGRADED,
                    'message'     => sprintf(
                        'WP user %d now linked to multiple clients (existing %d, new %d) — dual-program; resolver routes orders by rate.',
                        $wp_user_id,
                        (int) $duplicate_of,
                        $client_id
                    ),
                    'entity_type' => 'user',
                    'entity_id'   => $wp_user_id,
                    'context'     => [
                        'existing_client' => (int) $duplicate_of,
                        'new_client'      => $client_id,
                    ],
                ]);
            }
        }

        if (!$repository->update_client($client_id, ['wp_user_id' => $wp_user_id])) {
            wp_send_json_error(['message' => __('Failed to update Meals DB.', 'meals-db')]);
        }

        MealsDB_Logger::log(
            'link_client_to_wp_user',
            $client_id,
            'wp_user_id',
            $existing_wp_user_id !== null ? (string) $existing_wp_user_id : null,
            (string) $wp_user_id
        );

        wp_send_json(['success' => true]);
    }

    /**
     * Activate a client record.
     */
    public static function activate_client(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        self::ensure_client_permissions();
        self::enforce_rate_limit();

        $client_id = self::get_requested_client_id();

        if (!MealsDB_Clients::activate_client($client_id)) {
            wp_send_json_error(['message' => __('Unable to activate the client.', 'meals-db')]);
        }

        wp_send_json_success([
            'message' => __('Client activated successfully.', 'meals-db'),
            'active'  => 1,
        ]);
    }

    /**
     * Deactivate a client record.
     */
    public static function deactivate_client(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        self::ensure_client_permissions();
        self::enforce_rate_limit();

        $client_id = self::get_requested_client_id();

        // v561 ITEM 4a: warn before deactivating a client who still has recent
        // non-cancelled orders — "inactive" must not silently hide someone who
        // is still ordering. The JS re-posts with confirm=1 after the operator
        // acknowledges. This is a warning, not a hard block.
        $confirmed = isset($_POST['confirm']) && $_POST['confirm'] === '1';
        if (!$confirmed) {
            $recent = MealsDB_Clients::recent_order_summary($client_id);
            if ((int) ($recent['count'] ?? 0) > 0) {
                // Recent-orders warning (v561 ITEM 4a) — unchanged wording.
                wp_send_json_error([
                    'needs_confirm' => true,
                    'count'         => (int) $recent['count'],
                    'last_date'     => (string) $recent['last_date'],
                    'message'       => sprintf(
                        /* translators: 1: order count, 2: latest order date */
                        __('This client has %1$d recent order(s), most recently %2$s. Deactivate anyway?', 'meals-db'),
                        (int) $recent['count'],
                        (string) $recent['last_date']
                    ),
                ]);
            }
            // v562 ITEM 3: baseline confirm even with NO recent orders —
            // deactivation silently removes the client from Quick Order search
            // (search_clients filters active = 1), so it must never be a single
            // unconfirmed click. Server-driven so exactly ONE dialog shows and it
            // can't stack with the order-count warning above.
            $name = MealsDB_Clients::display_name($client_id);
            wp_send_json_error([
                'needs_confirm' => true,
                'count'         => 0,
                'message'       => sprintf(
                    /* translators: %s: client name */
                    __('Deactivate %s? They will no longer appear in Quick Order search.', 'meals-db'),
                    $name !== '' ? $name : __('this client', 'meals-db')
                ),
            ]);
        }

        if (!MealsDB_Clients::deactivate_client($client_id)) {
            wp_send_json_error(['message' => __('Unable to deactivate the client.', 'meals-db')]);
        }

        wp_send_json_success([
            'message' => __('Client deactivated successfully.', 'meals-db'),
            'active'  => 0,
        ]);
    }

    /**
     * Permanently delete a client record.
     */
    public static function delete_client(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        self::ensure_client_permissions();
        self::enforce_rate_limit();

        $client_id = self::get_requested_client_id();

        if (!MealsDB_Clients::delete_client($client_id)) {
            wp_send_json_error(['message' => __('Unable to delete the client.', 'meals-db')]);
        }

        wp_send_json_success([
            'message' => __('Client deleted successfully.', 'meals-db'),
        ]);
    }

    /**
     * Enforce the per-user rate limit for client write operations.
     */
    private static function enforce_rate_limit(): void {
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('client_modify')) {
            wp_send_json_error(
                ['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')],
                429
            );
        }
    }

    /**
     * Ensure the current user has permission to perform AJAX actions.
     */
    private static function ensure_client_permissions(): void {
        if (current_user_can('manage_options')) {
            return;
        }

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'meals-db'),
            ]);
        }
    }

    /**
     * Retrieve and validate the requested client ID from the request.
     */
    private static function get_requested_client_id(): int {
        $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;

        if ($client_id <= 0) {
            wp_send_json_error(['message' => __('Invalid client.', 'meals-db')]);
        }

        return $client_id;
    }
}
