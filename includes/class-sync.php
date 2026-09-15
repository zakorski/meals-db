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
     * K13 ITEM 4: if a single nightly run would empty a currently-populated
     * column on more than this percentage of tracked clients, the run is not a
     * legitimate sync — it is a broken mapping, a bad usermeta migration, or a
     * partial restore. Abort the WHOLE run and write nothing. No real nightly
     * sync blanks the same field on hundreds of clients at once.
     */
    public const SYNC_MASS_BLANK_THRESHOLD_PCT = 20;

    /**
     * Fields where WP/WC is the source of truth.
     * Changes flow WP -> meals_clients.
     *
     * @return string[]
     */
    public static function get_wp_authoritative_fields(): array {
        return [
            'first_name',
            'last_name',
            'client_email',
            'client_phone_1',
            'client_phone_2',
            'street_name',
            'city',
            'province',
            'postal_code',
            'delivery_street_name',
            'delivery_city',
            'delivery_province',
            'delivery_postal_code',
            'alternate_contact_name',
            'alternate_contact_phone_1',
            'alternate_contact_phone_2',
            'alternate_contact_email',
        ];
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
     * K13 ITEM 4: count, per field, how many rows have a currently-populated
     * column that the WP side would set empty. Pure over injected data so it is
     * unit-testable without $wpdb: $read_raw($wp_user_id, $descriptor) returns
     * the raw WP value ('' when absent OR empty — deliberately, this measures
     * total blanking PRESSURE, the systemic signal, not the post-guard result).
     *
     * @param array<int,array<string,mixed>> $rows       Client rows.
     * @param string[]                       $wp_fields  WP-authoritative fields.
     * @param array<string,array>            $field_map  Field -> descriptor.
     * @param callable                       $read_raw   fn(int,$descriptor):string
     * @param string                         $wp_column  wp_user id column name.
     * @return array<string,int> field => blank-candidate count
     */
    public static function tally_blank_candidates(array $rows, array $wp_fields, array $field_map, callable $read_raw, string $wp_column): array {
        $tally = [];
        foreach ($rows as $row) {
            $uid = (int) ($row[$wp_column] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            foreach ($wp_fields as $field) {
                if (!isset($field_map[$field])) {
                    continue;
                }
                $client_value = isset($row[$field]) ? (string) $row[$field] : '';
                if (trim($client_value) === '') {
                    continue; // nothing to lose
                }
                $wp_value = (string) $read_raw($uid, $field_map[$field]);
                if (trim($wp_value) === '') {
                    $tally[$field] = ($tally[$field] ?? 0) + 1;
                }
            }
        }
        return $tally;
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
     * @return array<string, array{type: string, key: string}>
     */
    public static function get_field_to_wp_meta_map(): array {
        return [
            // Core WP user fields.
            'first_name'                    => ['type' => 'core', 'key' => 'first_name'],
            'last_name'                     => ['type' => 'core', 'key' => 'last_name'],
            'client_email'                  => ['type' => 'core', 'key' => 'user_email'],

            // Standard WC billing meta.
            'client_phone_1'                => ['type' => 'meta', 'key' => 'billing_phone'],
            'city'                          => ['type' => 'meta', 'key' => 'billing_city'],
            'province'                      => ['type' => 'meta', 'key' => 'billing_state'],
            'postal_code'                   => ['type' => 'meta', 'key' => 'billing_postcode'],

            // Standard WC shipping meta.
            'delivery_city'                 => ['type' => 'meta', 'key' => 'shipping_city'],
            'delivery_province'             => ['type' => 'meta', 'key' => 'shipping_state'],
            'delivery_postal_code'          => ['type' => 'meta', 'key' => 'shipping_postcode'],

            // K13 ITEM 1: these were mapped to `mealsdb_*` keys that have NEVER
            // existed on this install (0 usermeta rows, verified 2026-09-14).
            // get_user_meta() returned '' and the nightly sync wrote that ''
            // over the column, blanking all 992 clients. Each now points at the
            // key the migration actually reads from (class-migration-consolidated
            // .php) — the only path that populated these columns — so sync and
            // migration finally agree. ITEM 2 makes a still-absent key harmless.
            'client_phone_2'                => ['type' => 'meta', 'key' => 'billing_phone_2'],
            'street_name'                   => ['type' => 'meta', 'key' => 'billing_address_1'],
            'delivery_street_name'          => ['type' => 'meta', 'key' => 'shipping_address_1'],
            'alternate_contact_name'        => ['type' => 'meta', 'key' => 'alternate_contact_name'],
            'alternate_contact_phone_1'     => ['type' => 'meta', 'key' => 'alternate_contact_phone_1'],
            'alternate_contact_phone_2'     => ['type' => 'meta', 'key' => 'alternate_contact_phone_2'],
            'alternate_contact_email'       => ['type' => 'meta', 'key' => 'alternate_contact_email'],
            // next_order_date / next_delivery_date are COMPUTED by the next-dates
            // migration phase, not sourced from the WP user. They stay on their
            // mealsdb_* keys (0-9 rows) deliberately — ITEM 2 skips an absent key
            // so they can no longer blank. Whether they belong in this map at all
            // is a K13 follow-up, not this change.
            'next_order_date'               => ['type' => 'meta', 'key' => 'mealsdb_next_order_date'],
            'next_delivery_date'            => ['type' => 'meta', 'key' => 'mealsdb_next_delivery_date'],
        ];
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
     * Nightly cron sweep: compare all linked government clients and push WP-authoritative diffs.
     */
    public static function run_nightly_sync(): void {
        global $wpdb;

        $log_id = class_exists('MealsDB_Job_Logger')
            ? MealsDB_Job_Logger::start('wp_to_mealsdb_sync')
            : 0;

        $synced_count  = 0;
        $skipped_count = 0;
        $error_count   = 0;

        try {
            $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
            $escaped_table = str_replace('`', '``', $clients_table);

            // Pick the canonical wp_user column once via INFORMATION_SCHEMA so
            // we don't trial-and-error two SELECTs (which would also issue a
            // second large SELECT * pointlessly when the first returned zero
            // rows because no clients had wp_user_id set yet).
            $wp_column = self::resolve_wp_user_column($wpdb, $clients_table);
            if ($wp_column === null) {
                $msg = 'Nightly sync aborted: no wp_user_id column on clients table.';
                error_log('[MealsDB Sync] ' . $msg);
                if ($log_id > 0) {
                    MealsDB_Job_Logger::fail($log_id, $msg);
                }
                return;
            }

            $field_map     = self::get_field_to_wp_meta_map();
        $wp_fields     = self::get_wp_authoritative_fields();
        $batch_size    = 500;
        $offset        = 0;

        $escaped_column = str_replace('`', '``', $wp_column);

        // K13 ITEM 4: whole-run circuit breaker. The nightly sync is a streaming
        // write loop with no stage-then-commit phase, so "write nothing" on a
        // mass blanking can only be honoured by a count-only pre-flight BEFORE
        // any write. If any field is over threshold the whole run is suspect —
        // a broken mapping casts doubt on every write this run, not just the
        // offending field — so abort entirely.
        $mass_blank = self::preflight_mass_blank_scan($wpdb, $escaped_table, $wp_column, $field_map, $wp_fields);
        if ($mass_blank !== null) {
            MealsDB_Event_Log::record([
                'severity' => 'error', 'category' => 'sync', 'subsystem' => 'sync',
                'event' => 'sync.mass_blank_refused', 'outcome' => 'degraded',
                'message' => sprintf(
                    'Nightly sync aborted: %d of %d tracked clients would have %s blanked (> %d%%). Wrote nothing.',
                    $mass_blank['count'], $mass_blank['active'], $mass_blank['field'], self::SYNC_MASS_BLANK_THRESHOLD_PCT
                ),
                'context' => $mass_blank,
            ]);
            if ($log_id > 0 && class_exists('MealsDB_Job_Logger')) {
                MealsDB_Job_Logger::fail($log_id, 'Mass-blank pre-flight refused the run: ' . $mass_blank['field']);
            }
            return; // write nothing
        }

        // K13 ITEM 2: collect fields whose mapped key is absent for EVERY user
        // so we log ONE sync.meta_key_missing per field per run, not 992 rows.
        $missing_fields = []; // field => mapped key

        while (true) {
            $batch = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$escaped_table}` WHERE client_type IN ('SDNB', 'Veteran', 'Private') AND `{$escaped_column}` > 0 ORDER BY client_id ASC LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                ),
                ARRAY_A
            );

            if (!is_array($batch) || empty($batch)) {
                break;
            }

            // Pre-warm the user and user-meta caches for every wp_user_id in
            // this batch. Without this, each get_userdata() below triggers a
            // users-table query and the first get_user_meta() per user
            // triggers a usermeta-table query, giving ~1000 queries on a
            // 500-client batch. Two priming calls here cut it to 2.
            $batch_user_ids = [];
            foreach ($batch as $client) {
                $uid = (int) ($client[$wp_column] ?? 0);
                if ($uid > 0) {
                    $batch_user_ids[$uid] = $uid;
                }
            }
            if (!empty($batch_user_ids)) {
                $ids = array_values($batch_user_ids);
                if (function_exists('cache_users')) {
                    cache_users($ids);
                }
                update_meta_cache('user', $ids);
            }

            foreach ($batch as $client) {
                $wp_user_id = (int) ($client[$wp_column] ?? 0);
                $client_id  = (int) ($client['client_id'] ?? 0);

                if ($wp_user_id <= 0 || $client_id <= 0) {
                    $skipped_count++;
                    continue;
                }

                $user = get_userdata($wp_user_id);
                if (!$user instanceof WP_User) {
                    MealsDB_Logger::log('sync_nightly_missing_user', $client_id, 'wp_user_id', (string) $wp_user_id, null);
                    $skipped_count++;
                    continue;
                }

                $pushed = 0;
                foreach ($wp_fields as $field) {
                    if (!isset($field_map[$field])) {
                        continue;
                    }

                    [$wp_value, $present] = self::read_wp_field_value_with_presence($user, $field_map[$field]);
                    $client_value = isset($client[$field]) ? (string) $client[$field] : '';

                    $action = self::decide_field_action($present, $wp_value, $client_value);
                    if ($action === 'skip_absent') {
                        $missing_fields[$field] = $field_map[$field]['key'];
                        continue;
                    }
                    if ($action === 'noop') {
                        continue;
                    }
                    if ($action === 'skip_blank') {
                        // K13 ITEM 3: present-but-empty over a populated column.
                        // Refuse and surface it (bounded below the ITEM 4 mass
                        // threshold, so this is a handful of rows, not 992).
                        MealsDB_Event_Log::record([
                            'severity' => 'warning', 'category' => 'sync', 'subsystem' => 'sync',
                            'event' => 'sync.empty_overwrite_refused', 'outcome' => 'degraded',
                            'message' => sprintf('%s: refused to blank a populated column for client %d', $field, $client_id),
                            'context' => ['client_id' => $client_id, 'field' => $field],
                        ]);
                        continue;
                    }

                    // action === 'write'
                    self::$syncing = true;
                    try {
                        $push_result = self::push_to_meals_db($client_id, $field, $wp_value);
                    } finally {
                        self::$syncing = false;
                    }

                    if (is_wp_error($push_result)) {
                        $error_count++;
                        error_log(sprintf(
                            '[MealsDB Sync] Nightly sync error for client %d, field %s: %s',
                            $client_id, $field, $push_result->get_error_message()
                        ));
                    } else {
                        $pushed++;
                    }
                }

                if ($pushed > 0) {
                    $synced_count++;
                }
            }

            if (count($batch) < $batch_size) {
                break;
            }
            $offset += $batch_size;
        }

            // K13 ITEM 2: one row per absent field per run — the alarm that
            // would have surfaced the blanking in a day instead of a week.
            foreach ($missing_fields as $field => $key) {
                MealsDB_Event_Log::record([
                    'severity' => 'warning', 'category' => 'sync', 'subsystem' => 'sync',
                    'event' => 'sync.meta_key_missing', 'outcome' => 'degraded',
                    'message' => sprintf('%s -> %s absent for tracked users; column left unchanged', $field, $key),
                    'context' => ['field' => $field, 'meta_key' => $key],
                ]);
            }

            $summary = wp_json_encode([
                'synced'  => $synced_count,
                'skipped' => $skipped_count,
                'errors'  => $error_count,
            ]);

            MealsDB_Logger::log('sync_nightly_complete', 0, 'summary', null, $summary !== false ? $summary : null);

            if ($log_id > 0) {
                $stats = [
                    'records_processed' => $synced_count + $skipped_count + $error_count,
                    'records_updated'   => $synced_count,
                    'records_skipped'   => $skipped_count,
                    'records_errored'   => $error_count,
                ];

                // A nightly run that swallowed per-field push failures
                // ($error_count > 0, incremented above where push_to_meals_db
                // returned a WP_Error) must NOT log as a clean success:
                // the Event Log dashboard/digest default to failed|degraded,
                // so MealsDB_Job_Logger::finish() (which maps to 'succeeded')
                // would hide a nightly sync that failed to push government-
                // client identity fields — the records_errored counter alone
                // never surfaces. Record 'degraded' so the swallowed errors
                // show up. See CLAUDE.md Pattern 6 and the explicit warning
                // in MealsDB_Job_Logger::finish()'s docblock.
                if ($error_count > 0) {
                    MealsDB_Event_Log::finish_job($log_id, $stats, MealsDB_Event_Log::OUTCOME_DEGRADED);
                } else {
                    MealsDB_Job_Logger::finish($log_id, $stats);
                }
            }
        } catch (\Throwable $e) {
            if ($log_id > 0) {
                MealsDB_Job_Logger::fail($log_id, $e->getMessage(), [
                    'records_processed' => $synced_count + $skipped_count + $error_count,
                    'records_errored'   => $error_count,
                ]);
            }
            // Re-throw so WP-Cron records the failure on its own
            // event ledger as well — the new logger is additive, not
            // a replacement for cron's native failure surfacing.
            throw $e;
        }
    }

    /**
     * Pick wp_user_id / wordpress_user_id by inspecting INFORMATION_SCHEMA
     * once. Cached for the request.
     */
    /**
     * K13 ITEM 4: walk the tracked-client population once WITHOUT writing and
     * tally per-field blank candidates. Returns the first field over threshold,
     * or null. Uses the SAME client_type filter and wp_user column the real
     * sync walks, so the percentage measures against the real population.
     *
     * @return array{field: string, count: int, active: int}|null
     */
    private static function preflight_mass_blank_scan(wpdb $wpdb, string $escaped_table, string $wp_column, array $field_map, array $wp_fields): ?array {
        $escaped_column = str_replace('`', '``', $wp_column);
        $batch_size = 500;
        $offset = 0;
        $active = 0;
        $tally = [];

        $read_raw = static function (int $uid, array $descriptor): string {
            $u = get_userdata($uid);
            return $u instanceof WP_User ? self::read_wp_field_value($u, $descriptor) : '';
        };

        while (true) {
            $batch = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$escaped_table}` WHERE client_type IN ('SDNB', 'Veteran', 'Private') AND `{$escaped_column}` > 0 ORDER BY client_id ASC LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                ),
                ARRAY_A
            );
            if (!is_array($batch) || empty($batch)) {
                break;
            }
            $active += count($batch);
            $ids = [];
            foreach ($batch as $row) { $uid = (int) ($row[$wp_column] ?? 0); if ($uid > 0) { $ids[$uid] = $uid; } }
            if (!empty($ids)) {
                if (function_exists('cache_users')) { cache_users(array_values($ids)); }
                update_meta_cache('user', array_values($ids));
            }
            $batch_tally = self::tally_blank_candidates($batch, $wp_fields, $field_map, $read_raw, $wp_column);
            foreach ($batch_tally as $field => $count) { $tally[$field] = ($tally[$field] ?? 0) + $count; }

            if (count($batch) < $batch_size) { break; }
            $offset += $batch_size;
        }

        foreach ($tally as $field => $count) {
            if (self::is_over_mass_blank_threshold((int) $count, $active)) {
                return ['field' => $field, 'count' => (int) $count, 'active' => $active];
            }
        }
        return null;
    }

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
