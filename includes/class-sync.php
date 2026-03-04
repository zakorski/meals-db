<?php
/**
 * Handles data sync comparison between Meals DB and WooCommerce.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Sync {

    /**
     * Guard against recursive sync triggers.
     *
     * @var bool
     */
    private static bool $syncing = false;

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
            'street_number',
            'street_name',
            'apartment_number',
            'city',
            'province',
            'postal_code',
            'delivery_street_number',
            'delivery_street_name',
            'delivery_apartment_number',
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

            // Plugin-managed custom meta (no standard WC equivalent).
            'client_phone_2'                => ['type' => 'meta', 'key' => 'mealsdb_client_phone_2'],
            'street_number'                 => ['type' => 'meta', 'key' => 'mealsdb_street_number'],
            'street_name'                   => ['type' => 'meta', 'key' => 'mealsdb_street_name'],
            'apartment_number'              => ['type' => 'meta', 'key' => 'mealsdb_apartment_number'],
            'delivery_street_number'        => ['type' => 'meta', 'key' => 'mealsdb_delivery_street_number'],
            'delivery_street_name'          => ['type' => 'meta', 'key' => 'mealsdb_delivery_street_name'],
            'delivery_apartment_number'     => ['type' => 'meta', 'key' => 'mealsdb_delivery_apartment_number'],
            'alternate_contact_name'        => ['type' => 'meta', 'key' => 'mealsdb_alternate_contact_name'],
            'alternate_contact_phone_1'     => ['type' => 'meta', 'key' => 'mealsdb_alternate_contact_phone_1'],
            'alternate_contact_phone_2'     => ['type' => 'meta', 'key' => 'mealsdb_alternate_contact_phone_2'],
            'alternate_contact_email'       => ['type' => 'meta', 'key' => 'mealsdb_alternate_contact_email'],
        ];
    }

    /**
     * Register WordPress hooks for real-time sync triggers and nightly cron.
     */
    public static function register_hooks(): void {
        add_action('profile_update', [self::class, 'on_wp_user_updated'], 10, 2);
        add_action('woocommerce_customer_save_address', [self::class, 'on_wc_address_saved'], 10, 2);
        add_action('woocommerce_created_customer', [self::class, 'on_wc_customer_created'], 10, 1);

        if (!wp_next_scheduled('mealsdb_nightly_sync')) {
            wp_schedule_event(strtotime('tomorrow 02:00:00'), 'daily', 'mealsdb_nightly_sync');
        }
        add_action('mealsdb_nightly_sync', [self::class, 'run_nightly_sync']);
    }

    /**
     * Handle WP user profile updates by pushing WP-authoritative fields to meals_clients.
     *
     * @param int              $user_id       WordPress user ID.
     * @param WP_User|mixed    $old_user_data Previous user data (WP_User on WP 4.3+).
     */
    public static function on_wp_user_updated(int $user_id, $old_user_data): void {
        if (self::$syncing) {
            return;
        }

        $client = self::find_government_client_by_wp_user($user_id);
        if ($client === null) {
            return;
        }

        $client_id = (int) ($client['client_id'] ?? 0);
        if ($client_id <= 0) {
            return;
        }

        $user = get_userdata($user_id);
        if (!$user instanceof WP_User) {
            return;
        }

        self::sync_wp_fields_to_meals_db($user, $client, $client_id, 'sync_wp_to_mealsdb');
    }

    /**
     * Handle WooCommerce customer address save events.
     *
     * @param int    $user_id      WordPress user ID.
     * @param string $address_type Address type being saved ("billing" or "shipping").
     */
    public static function on_wc_address_saved(int $user_id, string $address_type): void {
        if (self::$syncing) {
            return;
        }

        if ($address_type !== 'billing' && $address_type !== 'shipping') {
            return;
        }

        $client = self::find_government_client_by_wp_user($user_id);
        if ($client === null) {
            return;
        }

        $client_id = (int) ($client['client_id'] ?? 0);
        if ($client_id <= 0) {
            return;
        }

        $user = get_userdata($user_id);
        if (!$user instanceof WP_User) {
            return;
        }

        self::sync_wp_fields_to_meals_db($user, $client, $client_id, 'sync_wc_address_to_mealsdb');
    }

    /**
     * Handle new WooCommerce customer creation.
     *
     * @param int $customer_id WordPress user ID of the new customer.
     */
    public static function on_wc_customer_created(int $customer_id): void {
        if (self::$syncing) {
            return;
        }

        $client = self::find_government_client_by_wp_user($customer_id);
        if ($client === null) {
            return;
        }

        $client_id = (int) ($client['client_id'] ?? 0);
        if ($client_id <= 0) {
            return;
        }

        $user = get_userdata($customer_id);
        if (!$user instanceof WP_User) {
            return;
        }

        self::sync_wp_fields_to_meals_db($user, $client, $client_id, 'sync_wc_customer_created');
    }

    /**
     * Nightly cron sweep: compare all linked government clients and push WP-authoritative diffs.
     */
    public static function run_nightly_sync(): void {
        $conn = MealsDB_DB::get_connection();
        if (!$conn) {
            error_log('[MealsDB Sync] Nightly sync aborted: no database connection.');
            return;
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $escaped_table = str_replace('`', '``', $clients_table);

        $sql = sprintf(
            "SELECT * FROM `%s` WHERE client_type IN ('SDNB', 'Veteran') AND wp_user_id > 0",
            $escaped_table
        );

        // Try the preferred column name first; fall back to the alternate.
        $result = $conn->query($sql);
        if (!($result instanceof \mysqli_result)) {
            $sql = sprintf(
                "SELECT * FROM `%s` WHERE client_type IN ('SDNB', 'Veteran') AND wordpress_user_id > 0",
                $escaped_table
            );
            $result = $conn->query($sql);
        }

        if (!($result instanceof \mysqli_result)) {
            error_log('[MealsDB Sync] Nightly sync aborted: failed to query clients.');
            return;
        }

        $synced_count   = 0;
        $skipped_count  = 0;
        $error_count    = 0;
        $field_map      = self::get_field_to_wp_meta_map();
        $wp_fields      = self::get_wp_authoritative_fields();

        while ($client = $result->fetch_assoc()) {
            $wp_user_id = (int) ($client['wp_user_id'] ?? $client['wordpress_user_id'] ?? 0);
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

                $wp_value     = self::read_wp_field_value($user, $field_map[$field]);
                $client_value = isset($client[$field]) ? (string) $client[$field] : '';

                if (self::normalize_for_comparison($wp_value) === self::normalize_for_comparison($client_value)) {
                    continue;
                }

                self::$syncing = true;
                $push_result = self::push_to_meals_db($client_id, $field, $wp_value);
                self::$syncing = false;

                if (is_wp_error($push_result)) {
                    $error_count++;
                    error_log(sprintf(
                        '[MealsDB Sync] Nightly sync error for client %d, field %s: %s',
                        $client_id,
                        $field,
                        $push_result->get_error_message()
                    ));
                } else {
                    $pushed++;
                }
            }

            if ($pushed > 0) {
                $synced_count++;
            }
        }

        $result->free();

        $summary = wp_json_encode([
            'synced'  => $synced_count,
            'skipped' => $skipped_count,
            'errors'  => $error_count,
        ]);

        MealsDB_Logger::log('sync_nightly_complete', 0, 'summary', null, $summary !== false ? $summary : null);
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
        $mutate = new MealsDB_Sync_Mutate();

        return $mutate->link_meals_client_to_wc_user($client_id, $user_id);
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
     * Look up a government client record linked to a WordPress user.
     *
     * @return array<string, mixed>|null Client row, or null if not found or not government.
     */
    private static function find_government_client_by_wp_user(int $wp_user_id): ?array {
        $conn = MealsDB_DB::get_connection();
        if (!$conn) {
            return null;
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $escaped_table = str_replace('`', '``', $clients_table);

        // Try the preferred column name first; fall back to the alternate.
        $wp_columns = ['wp_user_id', 'wordpress_user_id'];
        foreach ($wp_columns as $wp_col) {
            $escaped_col = str_replace('`', '``', $wp_col);
            $sql = sprintf(
                "SELECT * FROM `%s` WHERE `%s` = ? AND client_type IN ('SDNB', 'Veteran') LIMIT 1",
                $escaped_table,
                $escaped_col
            );

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                continue;
            }

            if (!$stmt->bind_param('i', $wp_user_id) || !$stmt->execute()) {
                $stmt->close();
                continue;
            }

            if (method_exists($stmt, 'get_result')) {
                $res = $stmt->get_result();
                if ($res instanceof \mysqli_result) {
                    $row = $res->fetch_assoc();
                    $res->free();
                    $stmt->close();
                    if (is_array($row)) {
                        return $row;
                    }
                } else {
                    $stmt->close();
                }
            } else {
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $stmt->close();
                    // Fall back to a plain query for environments without get_result.
                    $fallback_sql = sprintf(
                        "SELECT * FROM `%s` WHERE `%s` = %d AND client_type IN ('SDNB', 'Veteran') LIMIT 1",
                        $escaped_table,
                        $escaped_col,
                        $wp_user_id
                    );
                    $fallback_result = $conn->query($fallback_sql);
                    if ($fallback_result instanceof \mysqli_result) {
                        $row = $fallback_result->fetch_assoc();
                        $fallback_result->free();
                        return is_array($row) ? $row : null;
                    }
                } else {
                    $stmt->close();
                }
            }
        }

        return null;
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

        self::$syncing = true;

        foreach ($wp_fields as $field) {
            if (!isset($field_map[$field])) {
                continue;
            }

            $wp_value     = self::read_wp_field_value($user, $field_map[$field]);
            $client_value = isset($client[$field]) ? (string) $client[$field] : '';

            if (self::normalize_for_comparison($wp_value) === self::normalize_for_comparison($client_value)) {
                continue;
            }

            $result = self::push_to_meals_db($client_id, $field, $wp_value);

            if (is_wp_error($result)) {
                error_log(sprintf(
                    '[MealsDB Sync] %s error for client %d, field %s: %s',
                    $action,
                    $client_id,
                    $field,
                    $result->get_error_message()
                ));
            }
        }

        self::$syncing = false;
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
}
