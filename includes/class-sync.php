<?php
/**
 * Handles data sync comparison between Meals DB and WooCommerce.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Sync {

    /**
     * Guard against recursive sync triggers.
     *
     * @var bool
     */
    private static bool $syncing = false;

    /**
     * K13 ITEM 4, restated for K21 fill-blank: if a single nightly run would
     * fill over a NON-EMPTY raw value in one field on more than this
     * percentage of tracked accounts, the run is not a legitimate sync — it
     * is a comparator or mapping bug making real values read as blank (the
     * K13 failure class). The apply pass is refused and writes nothing. See
     * MealsDB_Sync_Reconciler::breaker(). The only legitimate non-empty fill
     * targets are placeholders (shipping_phone '0': 157 of ~1,031, ~15%).
     */
    public const SYNC_MASS_BLANK_THRESHOLD_PCT = 20;

    /**
     * Run $fn with the sync hooks suppressed (K21 ITEM 10). A resolution
     * write calls wp_update_user() / update_user_meta(), which fire
     * profile_update / woocommerce_customer_save_address; without this the
     * real-time hooks would re-enter the sync mid-resolution. Restores the
     * previous flag in finally, so a throw can never leave every later sync
     * hook silently disabled for the rest of the request.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function without_sync_hooks(callable $fn) {
        $previous      = self::$syncing;
        self::$syncing = true;
        try {
            return $fn();
        } finally {
            self::$syncing = $previous;
        }
    }

    /** Whether a sync write is in progress (hooks must not re-enter). */
    public static function is_syncing(): bool {
        return self::$syncing;
    }

    /**
     * Fields the pre-K21 nightly syncs WP -> meals_clients (WP wins).
     *
     * K21 ITEM 1: a thin accessor over MealsDB_Sync_Registry — the field list
     * is no longer defined here. Returns the registry's transitional `k13`
     * subset (the same 17 columns, same order) so existing callers behave
     * exactly as before until ITEM 7 replaces the nightly.
     *
     * @return string[]
     */
    public static function get_wp_authoritative_fields(): array {
        return MealsDB_Sync_Registry::k13_columns();
    }

    /**
     * K13 ITEM 3: an empty incoming value means "no data", never "delete the
     * data". Returns true only when we are about to overwrite a populated value
     * with an empty one — the single hazard that destroyed 992 addresses.
     */
    public static function would_blank_value(string $new_value, string $existing_value): bool {
        return trim($new_value) === '' && trim($existing_value) !== '';
    }

    /**
     * K13 ITEM 2/3: the single per-field decision shared by both WP->meals_db
     * loops. Kept pure so it is unit-testable without $wpdb.
     *
     * @param bool   $present      Does the mapped WP meta key EXIST for this user?
     * @param string $wp_value     Value read from WP ('' when absent OR empty).
     * @param string $client_value Current meals_clients column value.
     * @return string skip_absent | skip_blank | noop | write
     */
    public static function decide_field_action(bool $present, string $wp_value, string $client_value): string {
        // Absent wins first: get_user_meta() returns '' both for "set to empty"
        // and "key absent". An absent key is "no opinion" — never touch the DB,
        // and it is the case that blanked every client nightly (K13).
        if (!$present) {
            return 'skip_absent';
        }
        if (self::normalize_for_comparison($wp_value) === self::normalize_for_comparison($client_value)) {
            return 'noop';
        }
        if (self::would_blank_value($wp_value, $client_value)) {
            return 'skip_blank';
        }
        return 'write';
    }

    /**
     * K13 ITEM 4: strictly-greater-than-threshold, integer-safe (no float,
     * no divide-by-zero).
     */
    public static function is_over_mass_blank_threshold(int $blank_count, int $active_count): bool {
        if ($active_count <= 0) {
            return false;
        }
        return ($blank_count * 100) > ($active_count * self::SYNC_MASS_BLANK_THRESHOLD_PCT);
    }

    /**
     * Presence-aware read: like read_wp_field_value() but also reports whether
     * the mapped key EXISTS. For core fields presence is always true.
     *
     * @return array{0: string, 1: bool} [value, present]
     */
    private static function read_wp_field_value_with_presence(WP_User $user, array $descriptor): array {
        if ($descriptor['type'] === 'core') {
            return [self::read_wp_field_value($user, $descriptor), true];
        }
        $present = metadata_exists('user', (int) $user->ID, $descriptor['key']);
        return [self::read_wp_field_value($user, $descriptor), (bool) $present];
    }

    /**
     * Fields that exist only in meals_clients and are never overwritten by WP data.
     *
     * @return string[]
     */
    public static function get_mealsdb_authoritative_fields(): array {
        return [
            'client_type',
            'sdnb_service_request_id',
            'requisition_id',
            'individual_id',
            'individual_id_index',
            'service_id',
            'vendor_number',
            'service_center_charged',
            'use_legacy_billing',
            'default_rate_id',
            'meal_type',
            'delivery_initials',
            'delivery_initials_index',
            'delivery_area_zone',
            'delivery_area_name',
            'delivery_day',
            'delivery_frequency',
            'ordering_frequency',
            'ordering_contact_method',
            'next_order_date',
            'next_delivery_date',
            'units',
            'client_contribution',
            'vet_health_card',
            'vet_health_card_index',
            'open_date',
            'birth_date',
            'gender',
            'assigned_worker_name',
            'assigned_worker_email',
            'requisition_id_index',
            'requisition_period',
            'service_name_zone',
            'service_commence_date',
            'expected_termination_date',
            'initial_renewal_termination_date',
            'most_recent_renewal_termination_date',
            'notes_to_service_provider',
            'freezer_capacity',
            'delivery_fee',
            'diet_concerns',
            'customer_comments',
            'payment_method',
            'do_not_call_client_phone',
            'required_start_date',
            'active',
        ];
    }

    /**
     * Map meals_clients field names to their WP user data source.
     *
     * Each entry describes how to read/write the WP-side value:
     *   - type "core": read from WP_User property (key = property name)
     *   - type "meta": read via get_user_meta (key = meta_key)
     *
     * K21 ITEM 1: a thin accessor over MealsDB_Sync_Registry — mappings are
     * no longer defined here (the K13/K19 history of each key now lives on
     * the registry entry). Scoped to the same 17 columns as
     * get_wp_authoritative_fields(), in the historical shape, because
     * MealsDB_Sync_Mutate derives its usermeta write allowlist from it.
     *
     * The old next_order_date / next_delivery_date entries (pointing at
     * mealsdb_* keys with 0-9 rows) are gone: those columns are COMPUTED,
     * K21 excludes them, and they were never in the synced field list, so
     * no sync path read or wrote them.
     *
     * @return array<string, array{type: string, key: string}>
     */
    public static function get_field_to_wp_meta_map(): array {
        $map = [];
        foreach (MealsDB_Sync_Registry::k13_columns() as $column) {
            $map[$column] = MealsDB_Sync_Registry::wp_read_descriptor($column);
        }
        return $map;
    }

    /**
     * Register WordPress hooks for real-time sync triggers and nightly cron.
     */
    public static function register_hooks(): void {
        add_action('profile_update', [self::class, 'on_wp_user_updated'], 10, 2);
        add_action('woocommerce_customer_save_address', [self::class, 'on_wc_address_saved'], 10, 2);
        add_action('woocommerce_created_customer', [self::class, 'on_wc_customer_created'], 10, 1);
        // Unlink clients from a WP user when it's deleted, so the dangling
        // wp_user_id link doesn't make the nightly sync log a
        // sync_nightly_missing_user row for it every night forever.
        add_action('deleted_user', [self::class, 'on_wp_user_deleted'], 10, 1);

        if (!wp_next_scheduled('mealsdb_nightly_sync')) {
            wp_schedule_event(strtotime('tomorrow 02:00:00'), 'daily', 'mealsdb_nightly_sync');
        }
        add_action('mealsdb_nightly_sync', [self::class, 'run_nightly_sync']);
    }

    /**
     * A WP user was deleted — NULL the wp_user_id link on any clients that
     * pointed at it. Without this the link dangles, and the nightly sync writes
     * one sync_nightly_missing_user audit row per orphaned client per night,
     * forever, into the append-only (never-pruned) audit log. Each unlink is
     * audit-logged once here. Never throws (instrumentation must not break the
     * user-deletion flow).
     *
     * @param int $user_id
     */
    public static function on_wp_user_deleted(int $user_id): void {
        if ($user_id <= 0) {
            return;
        }
        try {
            global $wpdb;
            $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
            $wp_column     = self::resolve_wp_user_column($wpdb, $clients_table);
            if ($wp_column === null) {
                return;
            }

            $client_ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT client_id FROM `{$clients_table}` WHERE `{$wp_column}` = %d",
                $user_id
            )));

            if (empty($client_ids)) {
                self::safe_record_hook('deleted_user', 'user', $user_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED, ['reason' => 'no_linked_clients']);
                return;
            }

            $wpdb->query($wpdb->prepare(
                "UPDATE `{$clients_table}` SET `{$wp_column}` = NULL WHERE `{$wp_column}` = %d",
                $user_id
            ));

            foreach ($client_ids as $cid) {
                MealsDB_Logger::log('wp_user_deleted_unlink', $cid, 'wp_user_id', (string) $user_id, null);
            }

            // K21 ITEM 8: the account no longer exists, so its open/dismissed
            // conflicts describe nothing. Close them now rather than leaving
            // them visible until the next nightly sweep.
            (new MealsDB_Sync_Conflicts($wpdb))->supersede_account($user_id, gmdate('Y-m-d H:i:s'));

            self::safe_record_hook('deleted_user', 'user', $user_id,
                MealsDB_Hook_Logger::OUTCOME_PROCESSED, ['unlinked' => count($client_ids)]);
        } catch (\Throwable $e) {
            self::safe_record_hook('deleted_user', 'user', $user_id,
                MealsDB_Hook_Logger::OUTCOME_ERRORED, ['exception' => get_class($e)], $e->getMessage());
        }
    }

    /**
     * Record a hook fire if the logger is available. Never throws.
     */
    private static function safe_record_hook(
        string $hook,
        string $target_type,
        int $target_id,
        string $outcome = 'processed',
        array $context = [],
        ?string $error = null
    ): void {
        if (!class_exists('MealsDB_Hook_Logger')) {
            return;
        }
        try {
            MealsDB_Hook_Logger::record($hook, $target_type, $target_id, $context, $outcome, $error);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[Sync] safe_record_hook threw: ' . $e->getMessage());
        }
    }

    /**
     * Handle WP user profile updates by pushing WP-authoritative fields to meals_clients.
     *
     * @param int              $user_id       WordPress user ID.
     * @param WP_User|mixed    $old_user_data Previous user data (WP_User on WP 4.3+).
     */
    public static function on_wp_user_updated(int $user_id, $old_user_data): void {
        try {
            if (self::$syncing) {
                self::safe_record_hook(
                    'profile_update', 'user', $user_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'recursion_guard']
                );
                return;
            }

            $client = self::find_tracked_client_by_wp_user($user_id);
            if ($client === null) {
                self::safe_record_hook(
                    'profile_update', 'user', $user_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'not_tracked_client']
                );
                return;
            }

            $client_id = (int) ($client['client_id'] ?? 0);
            if ($client_id <= 0) {
                self::safe_record_hook(
                    'profile_update', 'user', $user_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'invalid_client_id']
                );
                return;
            }

            $user = get_userdata($user_id);
            if (!$user instanceof WP_User) {
                self::safe_record_hook(
                    'profile_update', 'user', $user_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'no_userdata']
                );
                return;
            }

            self::sync_wp_fields_to_meals_db($user, $client, $client_id, 'sync_wp_to_mealsdb');
            self::safe_record_hook('profile_update', 'user', $user_id);
        } catch (\Throwable $e) {
            self::safe_record_hook(
                'profile_update', 'user', $user_id,
                MealsDB_Hook_Logger::OUTCOME_ERRORED,
                ['exception' => get_class($e)],
                $e->getMessage()
            );
        }
    }

    /**
     * Handle WooCommerce customer address save events.
     *
     * @param int    $user_id      WordPress user ID.
     * @param string $address_type Address type being saved ("billing" or "shipping").
     */
    public static function on_wc_address_saved(int $user_id, string $address_type): void {
        try {
            if (self::$syncing) {
                self::safe_record_hook(
                    'woocommerce_customer_save_address', 'user', $user_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'recursion_guard', 'address_type' => $address_type]
                );
                return;
            }

            if ($address_type !== 'billing' && $address_type !== 'shipping') {
                self::safe_record_hook(
                    'woocommerce_customer_save_address', 'user', $user_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'unknown_address_type', 'address_type' => $address_type]
                );
                return;
            }

            $client = self::find_tracked_client_by_wp_user($user_id);
            if ($client === null) {
                self::safe_record_hook(
                    'woocommerce_customer_save_address', 'user', $user_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'not_tracked_client', 'address_type' => $address_type]
                );
                return;
            }

            $client_id = (int) ($client['client_id'] ?? 0);
            if ($client_id <= 0) {
                self::safe_record_hook(
                    'woocommerce_customer_save_address', 'user', $user_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'invalid_client_id', 'address_type' => $address_type]
                );
                return;
            }

            $user = get_userdata($user_id);
            if (!$user instanceof WP_User) {
                self::safe_record_hook(
                    'woocommerce_customer_save_address', 'user', $user_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'no_userdata', 'address_type' => $address_type]
                );
                return;
            }

            self::sync_wp_fields_to_meals_db($user, $client, $client_id, 'sync_wc_address_to_mealsdb');
            self::safe_record_hook(
                'woocommerce_customer_save_address', 'user', $user_id,
                MealsDB_Hook_Logger::OUTCOME_PROCESSED,
                ['address_type' => $address_type]
            );
        } catch (\Throwable $e) {
            self::safe_record_hook(
                'woocommerce_customer_save_address', 'user', $user_id,
                MealsDB_Hook_Logger::OUTCOME_ERRORED,
                ['exception' => get_class($e), 'address_type' => $address_type],
                $e->getMessage()
            );
        }
    }

    /**
     * Handle new WooCommerce customer creation.
     *
     * @param int $customer_id WordPress user ID of the new customer.
     */
    public static function on_wc_customer_created(int $customer_id): void {
        try {
            if (self::$syncing) {
                self::safe_record_hook(
                    'woocommerce_created_customer', 'user', $customer_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'recursion_guard']
                );
                return;
            }

            $client = self::find_tracked_client_by_wp_user($customer_id);
            if ($client === null) {
                self::safe_record_hook(
                    'woocommerce_created_customer', 'user', $customer_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'not_tracked_client']
                );
                return;
            }

            $client_id = (int) ($client['client_id'] ?? 0);
            if ($client_id <= 0) {
                self::safe_record_hook(
                    'woocommerce_created_customer', 'user', $customer_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'invalid_client_id']
                );
                return;
            }

            $user = get_userdata($customer_id);
            if (!$user instanceof WP_User) {
                self::safe_record_hook(
                    'woocommerce_created_customer', 'user', $customer_id,
                    MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'no_userdata']
                );
                return;
            }

            self::sync_wp_fields_to_meals_db($user, $client, $client_id, 'sync_wc_customer_created');
            self::safe_record_hook('woocommerce_created_customer', 'user', $customer_id);
        } catch (\Throwable $e) {
            self::safe_record_hook(
                'woocommerce_created_customer', 'user', $customer_id,
                MealsDB_Hook_Logger::OUTCOME_ERRORED,
                ['exception' => get_class($e)],
                $e->getMessage()
            );
        }
    }

    /**
     * Nightly cron sweep (K21 ITEM 7): fill-blank reconcile in both
     * directions for every tracked account, recording conflicts for the
     * operator. See MealsDB_Sync_Reconciler for the rules and breakers.
     *
     * This replaced the pre-K21 loop, which synced 17 fields WP -> MealsDB
     * and let WordPress WIN whenever both sides were populated and differed —
     * overwriting operator edits nightly. Under K21 no automated path
     * overwrites a populated value; a difference becomes a conflict. That
     * loop's K13 pre-flight (count WP keys present-but-empty over a populated
     * column) went with it: in a fill-blank sync that situation is a fill of
     * WordPress, not a blanking, and counting it would abort every run once a
     * field is commonly blank in WordPress. The breaker is restated in the
     * reconciler for fill-blank.
     *
     * The job name ('wp_to_mealsdb_sync') is unchanged so the daily report
     * keeps monitoring it. run_reconcile() never throws; the guard below only
     * protects the cron event from a bug in it.
     */
    public static function run_nightly_sync(): void {
        try {
            MealsDB_Sync_Reconciler::run_reconcile();
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Sync] nightly reconcile threw: ' . $e->getMessage());
            MealsDB_Event_Log::record([
                'severity' => 'error', 'category' => 'sync', 'subsystem' => 'sync',
                'event' => 'sync.nightly_threw', 'outcome' => MealsDB_Event_Log::OUTCOME_FAILED,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pick wp_user_id / wordpress_user_id by inspecting INFORMATION_SCHEMA
     * once. Cached for the request.
     */
    private static function resolve_wp_user_column(wpdb $wpdb, string $clients_table): ?string {
        static $cache = [];
        if (array_key_exists($clients_table, $cache)) {
            return $cache[$clients_table];
        }

        $columns = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME IN ('wp_user_id', 'wordpress_user_id')",
                $clients_table
            )
        );

        $columns = is_array($columns) ? array_map('strval', $columns) : [];
        if (in_array('wp_user_id', $columns, true)) {
            return $cache[$clients_table] = 'wp_user_id';
        }
        if (in_array('wordpress_user_id', $columns, true)) {
            return $cache[$clients_table] = 'wordpress_user_id';
        }
        return $cache[$clients_table] = null;
    }

    // ------------------------------------------------------------------
    // Existing facade methods
    // ------------------------------------------------------------------

    /**
     * Get a list of mismatched fields between Meals DB and WooCommerce.
     *
     * @return array|WP_Error
     */
    public static function get_mismatches() {
        $query   = new MealsDB_Sync_Query();
        $compare = new MealsDB_Sync_Compare();

        return $compare->get_mismatches($query);
    }

    /**
     * Link a Meals DB client to a WordPress user account.
     *
     * @param int $client_id
     * @param int $wp_user_id
     * @return true|WP_Error
     */
    public static function link_client_to_wordpress_user(int $client_id, int $wp_user_id) {
        $mutate = new MealsDB_Sync_Mutate();

        return $mutate->link_meals_client_to_wc_user($client_id, $wp_user_id);
    }

    /**
     * Link a Meals DB client to a WooCommerce user.
     *
     * @param int $client_id
     * @param int $user_id
     * @return true|WP_Error
     */
    public static function link_client_to_user(int $client_id, int $user_id) {
        // U13-sync-core-4: thin alias over link_client_to_wordpress_user so the
        // two public facades (this one called from views/dashboard.php, the
        // other from ajax/class-ajax-clients.php) share ONE body — a guard
        // added to the canonical can't be silently missing here.
        return self::link_client_to_wordpress_user($client_id, $user_id);
    }

    /**
     * Find probable WordPress user matches for a Meals DB client.
     *
     * @param array<string, mixed> $client
     * @return array<int, array<string, mixed>>
     */
    public static function find_probable_matches_for_client(array $client): array {
        $query   = new MealsDB_Sync_Query();
        $compare = new MealsDB_Sync_Compare();

        return $compare->find_probable_matches_for_client($client, $query);
    }

    /**
     * Sync a single field from Meals DB to WooCommerce.
     *
     * @param int    $woo_user_id
     * @param string $field
     * @param string $new_value
     *
     * @return true|WP_Error Whether the field was synced successfully.
     */
    public static function push_to_woocommerce(int $woo_user_id, string $field, string $new_value) {
        $mutate = new MealsDB_Sync_Mutate();

        return $mutate->push_to_woocommerce($woo_user_id, $field, $new_value);
    }

    /**
     * Sync a single field from WooCommerce to Meals DB.
     *
     * @param int    $client_id
     * @param string $field
     * @param string $new_value
     *
     * @return true|WP_Error Whether the field was synced successfully.
     */
    public static function push_to_meals_db(int $client_id, string $field, string $new_value) {
        $mutate = new MealsDB_Sync_Mutate();

        return $mutate->push_to_meals_db($client_id, $field, $new_value);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Look up a meals_clients record linked to a WordPress user.
     *
     * Historically this gated on SDNB/Veteran only; as of Phase S,
     * Private customers are first-class records too so the sync hooks
     * push WP-authoritative identity fields to every tracked client.
     *
     * @return array<string, mixed>|null Client row, or null if no record exists.
     */
    private static function find_tracked_client_by_wp_user(int $wp_user_id): ?array {
        global $wpdb;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $wp_column     = self::resolve_wp_user_column($wpdb, $clients_table);
        if ($wp_column === null) {
            return null;
        }

        $escaped_table = str_replace('`', '``', $clients_table);
        $escaped_col   = str_replace('`', '``', $wp_column);
        $sql = $wpdb->prepare(
            "SELECT * FROM `{$escaped_table}` WHERE `{$escaped_col}` = %d AND client_type IN ('SDNB', 'Veteran', 'Private') LIMIT 1",
            $wp_user_id
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Compare all WP-authoritative fields and push any diffs to meals_clients.
     *
     * @param WP_User              $user      WordPress user.
     * @param array<string, mixed> $client    meals_clients row.
     * @param int                  $client_id Client primary key.
     * @param string               $action    Audit log action label.
     */
    private static function sync_wp_fields_to_meals_db(WP_User $user, array $client, int $client_id, string $action): void {
        $field_map = self::get_field_to_wp_meta_map();
        $wp_fields = self::get_wp_authoritative_fields();

        // try/finally so an exception or fatal in push_to_meals_db can't
        // leave the global $syncing flag stuck on for the rest of the
        // worker (which would silently suppress every subsequent sync hook).
        self::$syncing = true;
        try {
            foreach ($wp_fields as $field) {
                if (!isset($field_map[$field])) {
                    continue;
                }

                [$wp_value, $present] = self::read_wp_field_value_with_presence($user, $field_map[$field]);
                $client_value = isset($client[$field]) ? (string) $client[$field] : '';

                $decision = self::decide_field_action($present, $wp_value, $client_value);
                if ($decision === 'skip_absent' || $decision === 'noop') {
                    continue;
                }
                if ($decision === 'skip_blank') {
                    MealsDB_Event_Log::record([
                        'severity' => 'warning', 'category' => 'sync', 'subsystem' => 'sync',
                        'event' => 'sync.empty_overwrite_refused', 'outcome' => 'degraded',
                        'message' => sprintf('%s: refused to blank a populated column for client %d', $field, $client_id),
                        'context' => ['client_id' => $client_id, 'field' => $field],
                    ]);
                    continue;
                }

                $result = self::push_to_meals_db($client_id, $field, $wp_value);

                if (is_wp_error($result)) {
                    error_log(sprintf(
                        '[MealsDB Sync] %s error for client %d, field %s: %s',
                        $action, $client_id, $field, $result->get_error_message()
                    ));
                }
            }
        } finally {
            self::$syncing = false;
        }
    }

    /**
     * Read a WP-side field value for a given user using the meta map descriptor.
     *
     * @param WP_User                       $user     WordPress user.
     * @param array{type: string, key: string} $descriptor Meta map entry.
     *
     * @return string
     */
    private static function read_wp_field_value(WP_User $user, array $descriptor): string {
        if ($descriptor['type'] === 'core') {
            $key = $descriptor['key'];
            if ($key === 'user_email') {
                return isset($user->user_email) ? (string) $user->user_email : '';
            }
            return isset($user->$key) ? (string) $user->$key : '';
        }

        // type === 'meta'
        $value = get_user_meta($user->ID, $descriptor['key'], true);
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Normalize a value for case-insensitive, whitespace-trimmed comparison.
     */
    private static function normalize_for_comparison(string $value): string {
        return strtolower(trim($value));
    }

    /**
     * Normalize a value before it is folded into an ignored-conflict key.
     *
     * Shared single source of truth for the two halves of the
     * ignore-conflict contract: MealsDB_Sync_Query builds keys from the
     * stored IGNORED_CONFLICTS rows, while MealsDB_Sync_Compare builds
     * keys from live mismatch values. filter_ignored() only suppresses a
     * mismatch when BOTH sides md5 to the identical key, so the sanitizer
     * and the key format MUST stay byte-identical on both paths. These
     * used to be duplicated as private copies in each service class; a
     * future edit to one copy (a different separator, a different
     * sanitizer) would have silently broken conflict-ignoring with no
     * error — previously-ignored mismatches would just reappear. Keeping
     * one home on the facade both service classes already run through
     * removes that two-sources-of-truth trap.
     *
     * @param mixed $value
     */
    public static function sanitize_ignore_value($value): string {
        if (!is_scalar($value)) {
            $value = '';
        }

        $value = (string) $value;

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        return trim($value);
    }

    /**
     * Build the lookup key used for ignored conflicts. See
     * sanitize_ignore_value() for why this canonical implementation lives
     * on the facade instead of being duplicated in the sync services.
     */
    public static function build_ignore_key(string $field, string $source, string $target): string {
        return md5($field . '|' . $source . '|' . $target);
    }
}
