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
     * K21 ITEM 13: the three real-time hooks share ONE path —
     * MealsDB_Sync_Reconciler::reconcile_account() — so a WordPress save is
     * held to the same rules as the nightly: fill a blank MealsDB value, no-op
     * when equal, record a conflict when both sides are populated and differ.
     *
     * Previously each hook pushed the 17 k13 fields WP -> MealsDB with
     * WordPress WINNING on any difference, through a LIMIT-1 client lookup:
     * a client editing their address in My Account overwrote the operator's
     * MealsDB value immediately, and on an account linked to several clients
     * only one arbitrary client was updated.
     *
     * The real-time path never writes WordPress (see reconcile_account()).
     * Recursion: every K21 write runs inside MealsDB_Sync::without_sync_hooks(),
     * so a hook fired by our own write is skipped here.
     */
    private static function handle_realtime_hook(string $hook, int $user_id, array $context = []): void {
        try {
            if (self::$syncing) {
                self::safe_record_hook($hook, 'user', $user_id, MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                    ['reason' => 'recursion_guard'] + $context);
                return;
            }
            $result = MealsDB_Sync_Reconciler::reconcile_account($user_id);
            $outcome = [
                'processed' => MealsDB_Hook_Logger::OUTCOME_PROCESSED,
                'skipped'   => MealsDB_Hook_Logger::OUTCOME_SKIPPED,
            ][$result['outcome']] ?? MealsDB_Hook_Logger::OUTCOME_ERRORED;
            $hook_context = $context;
            if (isset($result['reason'])) {
                $hook_context['reason'] = $result['reason'];
            }
            if (isset($result['stats'])) {
                $hook_context['stats'] = $result['stats'];
            }
            self::safe_record_hook($hook, 'user', $user_id, $outcome, $hook_context,
                $outcome === MealsDB_Hook_Logger::OUTCOME_ERRORED ? (string) ($result['reason'] ?? 'reconcile failed') : null);
        } catch (\Throwable $e) {
            self::safe_record_hook($hook, 'user', $user_id, MealsDB_Hook_Logger::OUTCOME_ERRORED,
                ['exception' => get_class($e)] + $context, $e->getMessage());
        }
    }

    /**
     * profile_update.
     *
     * @param int           $user_id       WordPress user ID.
     * @param WP_User|mixed $old_user_data Previous user data (unused: the
     *                                     reconcile compares current values).
     */
    public static function on_wp_user_updated(int $user_id, $old_user_data): void {
        self::handle_realtime_hook('profile_update', $user_id);
    }

    /**
     * woocommerce_customer_save_address.
     *
     * @param int    $user_id      WordPress user ID.
     * @param string $address_type "billing" or "shipping".
     */
    public static function on_wc_address_saved(int $user_id, string $address_type): void {
        if ($address_type !== 'billing' && $address_type !== 'shipping') {
            self::safe_record_hook('woocommerce_customer_save_address', 'user', $user_id,
                MealsDB_Hook_Logger::OUTCOME_SKIPPED,
                ['reason' => 'unknown_address_type', 'address_type' => $address_type]);
            return;
        }
        self::handle_realtime_hook('woocommerce_customer_save_address', $user_id, ['address_type' => $address_type]);
    }

    /**
     * woocommerce_created_customer. A brand-new customer is rarely linked to
     * a client yet; when it is, the same reconcile applies.
     *
     * @param int $customer_id WordPress user ID of the new customer.
     */
    public static function on_wc_customer_created(int $customer_id): void {
        self::handle_realtime_hook('woocommerce_created_customer', $customer_id);
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

    // K21: the push_to_woocommerce() / push_to_meals_db() facades were
    // removed once their last callers (the WP-wins nightly, the real-time
    // hooks and the mealsdb_sync_field endpoint) were. Both overwrote
    // populated values; under K21 the only path allowed to do that is
    // MealsDB_Sync_Resolver (operator resolution).

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

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
