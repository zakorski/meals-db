<?php
/**
 * Contains write operations used to mutate data during Meals DB synchronization.
 */

defined('ABSPATH') || exit;

class MealsDB_Sync_Mutate {
    /**
     * Active wpdb connection, when available.
     */
    private ?wpdb $connection;

    /**
     * Primary key column name for the Meals DB clients table, when available.
     */
    private ?string $clients_primary_key;

    /**
     * Cache of discovered column names keyed by table.
     *
     * @var array<string, array<string, bool>>
     */
    private array $column_cache = [];

    public function __construct() {
        global $wpdb;
        $this->connection = $wpdb instanceof wpdb ? $wpdb : null;
        $this->clients_primary_key = MealsDB_Schema::get_primary_key_column(MealsDB_Tables::CLIENTS);
    }

    /**
     * Whether a wp_usermeta key is allowed to be written from a sync
     * override. Derived from the canonical field-to-meta map at request
     * time; cached per-process.
     */
    private static function is_allowed_user_meta_key(string $meta_key): bool {
        static $allowed = null;
        if ($allowed === null) {
            $allowed = [];
            foreach (MealsDB_Sync::get_field_to_wp_meta_map() as $entry) {
                if (is_array($entry) && ($entry['type'] ?? '') === 'meta' && !empty($entry['key'])) {
                    $allowed[(string) $entry['key']] = true;
                }
            }
        }
        return isset($allowed[$meta_key]);
    }

    /**
     * Update a WordPress user with the provided field values.
     *
     * @param int                  $user_id Identifier of the WordPress user to update.
     * @param array<string, mixed> $fields  Associative array of field names and values to persist.
     *
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    public function update_wp_user(int $user_id, array $fields) {
        // Shadow mode: the WP user record is legacy-visible. Suppress ALL
        // write-back here — this is the single chokepoint every
        // wp_update_user / update_user_meta call flows through, so guarding
        // it covers every caller (push_to_woocommerce, the address/customer
        // sync hooks, and the nightly sync alike).
        if (MealsDB_Shadow_Mode::is_enabled()) {
            return new WP_Error(
                'mealsdb_shadow_mode',
                __('Shadow mode is active; write-back to WordPress users is suppressed.', 'meals-db')
            );
        }

        $user = get_userdata($user_id);

        if (!$user instanceof WP_User) {
            return new WP_Error(
                'mealsdb_sync_user_missing',
                __('Unable to locate the WooCommerce customer for this override.', 'meals-db')
            );
        }

        foreach ($fields as $field => $value) {
            $error = $this->apply_wp_user_update($user, $field, $value);

            if (is_wp_error($error)) {
                return $error;
            }
        }

        return true;
    }

    /**
     * Sync a single field from Meals DB to WooCommerce.
     * Also updates the Meals DB client to ensure both storage locations are in sync.
     *
     * @return true|WP_Error
     */
    public function push_to_woocommerce(int $woo_user_id, string $field, string $new_value) {
        // (Shadow mode is enforced at the update_wp_user chokepoint below,
        // which every WP-user write flows through.)
        // Only WP-authoritative fields may be pushed to WooCommerce.
        if (!in_array($field, MealsDB_Sync::get_wp_authoritative_fields(), true)) {
            return new WP_Error(
                'mealsdb_sync_readonly_field',
                __('This field is not authoritative in WooCommerce and cannot be pushed there.', 'meals-db')
            );
        }

        // First update the WordPress user
        $wp_result = $this->update_wp_user($woo_user_id, [
            $field => $new_value,
        ]);

        if (is_wp_error($wp_result)) {
            return $wp_result;
        }

        // Now update the corresponding Meals DB client to keep both in sync.
        // The primary WP write already committed — a secondary failure here
        // leaves the two systems diverged. Capture the outcome so callers
        // (and a future reconciliation job) can see which writes landed
        // incompletely, instead of silently discarding the return value.
        $client_id = $this->get_client_id_from_wp_user($woo_user_id);

        if ($client_id > 0) {
            $connection = $this->require_connection();

            if (!is_wp_error($connection)) {
                $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
                $available_columns = $this->get_table_columns($connection, $clients_table);
                $column_map = $this->build_identity_column_map($available_columns);

                // Only update if the field exists in the client table
                if (isset($column_map[$field])) {
                    $column = $column_map[$field];
                    $reconciled = $this->update_meals_client($client_id, [
                        $column => $new_value,
                    ]);
                    if ($reconciled === false) {
                        self::record_partial_sync_failure(
                            $woo_user_id,
                            $client_id,
                            $field,
                            $new_value,
                            'wp_to_meals_db',
                            'WP user update succeeded but Meals DB client reconciliation failed'
                        );
                    }
                }
            } else {
                self::record_partial_sync_failure(
                    $woo_user_id,
                    $client_id,
                    $field,
                    $new_value,
                    'wp_to_meals_db',
                    'WP user update succeeded but Meals DB connection unavailable for reconciliation'
                );
            }
        }

        return true;
    }

    /**
     * Sync a single field from WooCommerce to Meals DB.
     * Also updates the WordPress user to ensure both storage locations are in sync.
     *
     * @return true|WP_Error
     */
    public function push_to_meals_db(int $client_id, string $field, string $new_value) {
        if ($client_id <= 0) {
            return new WP_Error(
                'mealsdb_sync_invalid_client',
                __('A valid Meals DB client is required to sync this field.', 'meals-db')
            );
        }

        // Only WP-authoritative fields may be written from WP to meals_clients.
        if (!in_array($field, MealsDB_Sync::get_wp_authoritative_fields(), true)) {
            return new WP_Error(
                'mealsdb_sync_readonly_field',
                __('This field is authoritative in Meals DB and cannot be overwritten from WooCommerce.', 'meals-db')
            );
        }

        $connection = $this->require_connection();

        if (is_wp_error($connection)) {
            return $connection;
        }

        // NO client-type gate here. Private clients used to be rejected because
        // they had no record in the old external encrypted DB. Since Phase S
        // Private customers are first-class rows in meals_clients (created by
        // MealsDB_Private_Intake) and the sync SELECTs include them (see
        // class-sync.php nightly + real-time WHERE client_type IN
        // ('SDNB','Veteran','Private')). The old gate made this method a silent
        // no-op that still reported "synced", leaving the Private row stale
        // forever. Government and Private clients are written identically here.

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $available_columns = $this->get_table_columns($connection, $clients_table);
        $column_map = $this->build_identity_column_map($available_columns);

        if (!isset($column_map[$field])) {
            return new WP_Error(
                'mealsdb_sync_unsupported_field',
                __('This field cannot be overridden from WooCommerce.', 'meals-db')
            );
        }

        $column = $column_map[$field];
        $primary_key = $this->resolve_primary_key_column($available_columns);

        if ($primary_key === null) {
            return new WP_Error(
                'mealsdb_sync_failed',
                __('Unable to determine the Meals DB client primary key.', 'meals-db')
            );
        }

        $escaped_table = str_replace('`', '``', $clients_table);
        $escaped_column = str_replace('`', '``', $column);
        $escaped_pk = str_replace('`', '``', $primary_key);
        $select_sql = sprintf('SELECT `%s` FROM `%s` WHERE `%s` = %%d LIMIT 1', $escaped_column, $escaped_table, $escaped_pk);

        $existing_value = $connection->get_var($connection->prepare($select_sql, $client_id));

        if ($existing_value === null && $connection->last_error !== '') {
            error_log('[MealsDB Sync] Failed to execute Meals DB client lookup: ' . self::redact_sql_error($connection->last_error));

            return new WP_Error(
                'mealsdb_sync_failed',
                __('Unable to read the current Meals DB value for this client.', 'meals-db')
            );
        }

        if ($existing_value === null) {
            return new WP_Error(
                'mealsdb_sync_client_missing',
                __('The Meals DB client could not be found.', 'meals-db')
            );
        }

        $existing_value = is_scalar($existing_value) ? (string) $existing_value : '';

        $update_success = $this->update_meals_client($client_id, [
            $column => $new_value,
        ]);

        if (!$update_success) {
            return new WP_Error(
                'mealsdb_sync_failed',
                __('Unable to update the Meals DB client record.', 'meals-db')
            );
        }

        MealsDB_Logger::log(
            'sync_override',
            $client_id,
            $field,
            $existing_value,
            $new_value,
            'woocommerce'
        );

        // Now update the corresponding WordPress user to keep both in
        // sync. Primary Meals-DB write already committed; capture the
        // secondary WP update's outcome so a failure here is logged
        // and audited instead of silently dropped.
        $wp_user_id = $this->get_wp_user_id_from_client($client_id);

        if ($wp_user_id > 0) {
            $secondary = $this->update_wp_user($wp_user_id, [
                $field => $new_value,
            ]);
            if (is_wp_error($secondary)) {
                self::record_partial_sync_failure(
                    $wp_user_id,
                    $client_id,
                    $field,
                    $new_value,
                    'meals_db_to_wp',
                    'Meals DB update succeeded but WP user reconciliation failed: ' . $secondary->get_error_message()
                );
            }
        }

        return true;
    }

    /**
     * Record a partial-sync divergence between WP users and meals_clients.
     *
     * A "partial" sync is one where the primary (authoritative) write
     * committed but the secondary reconciliation write to the other
     * system failed. The two systems are now out of step. This helper:
     *
     *   1. Emits a loud error_log entry so admin tooling / log scrapers
     *      notice.
     *   2. Writes an audit row via MealsDB_Logger so a reconciliation
     *      job (future) can pick the drift up and retry.
     *
     * We intentionally do NOT bubble a WP_Error back to the caller
     * here: returning an error for a partial failure would render as
     * "sync failed" in the admin UI when in fact the primary write
     * did land, and operators would be tempted to retry — doubling
     * the reconciliation load.
     */
    /**
     * Redact row-level values from a MySQL error string before
     * error_log. MySQL surfaces data values inside single quotes in
     * classic errors ("Duplicate entry 'x@y.com' for key 'email'",
     * "Data too long for column 'foo' at row 1"). The error class is
     * diagnostically useful; the quoted value might be cleartext PII
     * that shouldn't land in a shared error log. Replace every
     * single-quoted run with a fixed placeholder.
     *
     * Ciphertext values from encrypted columns are safe to log but
     * they're base64 — noisy and also get scrubbed by this regex.
     * Net result: logs are cleaner AND never leak cleartext.
     */
    private static function redact_sql_error(string $error): string {
        if ($error === '') {
            return 'unknown error';
        }
        $redacted = preg_replace("/'[^']*'/", "'[redacted]'", $error);
        return $redacted ?? $error;
    }

    private static function record_partial_sync_failure(
        int $wp_user_id,
        int $client_id,
        string $field,
        string $new_value,
        string $direction,
        string $reason
    ): void {
        error_log(sprintf(
            '[MealsDB Sync] Partial sync: %s direction=%s wp_user_id=%d client_id=%d field=%s',
            $reason,
            $direction,
            $wp_user_id,
            $client_id,
            $field
        ));

        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log(
                'sync_partial_failure',
                $client_id,
                $field,
                null,
                $new_value,
                $direction
            );
        }
    }

    /**
     * Update a Meals DB client with the provided field values.
     *
     * @param int                  $client_id Identifier of the Meals DB client to update.
     * @param array<string, mixed> $fields    Associative array of field names and values to persist.
     *
     * @return bool True on success, false on failure.
     */
    public function update_meals_client(int $client_id, array $fields): bool {
        $connection = $this->require_connection();

        if (is_wp_error($connection) || empty($fields)) {
            return false;
        }

        // No client-type gate: Private clients are first-class meals_clients
        // rows since Phase S and must be updatable here (see push_to_meals_db).

        try {
            $fields = MealsDB_Encryption::encrypt_columns($fields);
        } catch (\Throwable $e) {
            error_log('[MealsDB Sync] Update aborted: encryption failure (' . $e->getMessage() . ').');
            return false;
        }

        $set_clauses = [];
        $values      = [];

        foreach ($fields as $column => $value) {
            $set_clauses[] = sprintf('`%s` = %%s', str_replace('`', '``', $column));
            $values[]      = (string) $value;
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $available_columns = $this->get_table_columns($connection, $clients_table);
        $primary_key = $this->resolve_primary_key_column($available_columns);

        if ($primary_key === null) {
            return false;
        }

        $escaped_table = str_replace('`', '``', $clients_table);
        $escaped_pk    = str_replace('`', '``', $primary_key);
        $sql = sprintf('UPDATE `%s` SET %s WHERE `%s` = %%d', $escaped_table, implode(', ', $set_clauses), $escaped_pk);

        $values[] = $client_id;

        // Wrap in an explicit transaction for symmetry with
        // link_meals_client_to_wc_user and so a future caller that adds
        // an accompanying write (audit log row, related-table update)
        // gets atomicity for free. InnoDB runs a single UPDATE
        // atomically even without BEGIN/COMMIT, but the explicit bracket
        // also makes ROLLBACK available if the encryption or column-map
        // bookkeeping below ever grows past a single statement.
        $transaction_started = $connection->query('START TRANSACTION') !== false;

        try {
            $result = $connection->query($connection->prepare($sql, ...$values));

            if ($result === false) {
                if ($transaction_started) {
                    $connection->query('ROLLBACK');
                }
                error_log('[MealsDB Sync] Failed to execute Meals DB client update: ' . self::redact_sql_error((string) ($connection->last_error ?? '')));
                return false;
            }

            if ($transaction_started) {
                $commit = $connection->query('COMMIT');
                if ($commit === false) {
                    $connection->query('ROLLBACK');
                    error_log('[MealsDB Sync] Failed to commit Meals DB client update: ' . self::redact_sql_error((string) ($connection->last_error ?? '')));
                    return false;
                }
            }
        } catch (\Throwable $e) {
            if ($transaction_started) {
                $connection->query('ROLLBACK');
            }
            error_log('[MealsDB Sync] Meals DB client update threw: ' . $e->getMessage());
            // STR-LOG: rolled back and returned false (fail-closed), but a
            // sync write that threw mid-transaction should be visible.
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'  => 'error',
                    'category'  => 'sync',
                    'subsystem' => 'sync_mutate',
                    'event'     => 'update_client.threw',
                    'outcome'   => MealsDB_Event_Log::OUTCOME_FAILED,
                    'message'   => $e->getMessage(),
                ]);
            }
            return false;
        }

        return true;
    }

    /**
     * Create a Meals DB client record with the provided field values.
     *
     * @param array<string, mixed> $fields Associative array of field names and values for the new client.
     *
     * @return int|false The created client ID on success, or false on failure.
     */
    public function create_meals_client(array $fields) {
        $connection = $this->require_connection();

        if (is_wp_error($connection) || empty($fields)) {
            return false;
        }

        // No client-type gate: Private clients are first-class meals_clients
        // rows since Phase S (see push_to_meals_db). Government and Private
        // clients are inserted identically.

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $available_columns = $this->get_table_columns($connection, $clients_table);
        $column_map = $this->build_identity_column_map($available_columns);

        // Build the row keyed by DB column name first so we can encrypt
        // before assembling the prepared statement.
        $row = [];
        foreach ($fields as $field => $value) {
            if (!isset($column_map[$field])) {
                continue;
            }
            $row[$column_map[$field]] = $value;
        }

        // client_type is not an identity field in build_identity_column_map,
        // but it is a NOT-NULL discriminator column: pass it through verbatim
        // when the caller supplied it, so a created row records whether it is
        // SDNB / Veteran / Private instead of being dropped silently.
        if (isset($fields['client_type']) && is_string($fields['client_type']) && isset($available_columns['client_type'])) {
            $row['client_type'] = $fields['client_type'];
        }

        try {
            $row = MealsDB_Encryption::encrypt_columns($row);
        } catch (\Throwable $e) {
            error_log('[MealsDB Sync] Create aborted: encryption failure (' . $e->getMessage() . ').');
            return false;
        }

        $prepared_columns = [];
        $placeholders = [];
        $values = [];

        foreach ($row as $column => $value) {
            $prepared_columns[] = sprintf('`%s`', str_replace('`', '``', $column));
            $placeholders[] = '%s';
            $values[] = (string) $value;
        }

        if (empty($prepared_columns)) {
            return false;
        }

        $escaped_table = str_replace('`', '``', $clients_table);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $escaped_table,
            implode(', ', $prepared_columns),
            implode(', ', $placeholders)
        );

        // Match update_meals_client: wrap the INSERT in an explicit
        // transaction so a concurrent failure (disk full, key conflict
        // from a racing create for the same identity) rolls back
        // cleanly and so a future caller extending this method with an
        // audit-log insert gets atomicity for free.
        $transaction_started = $connection->query('START TRANSACTION') !== false;

        try {
            $result = $connection->query($connection->prepare($sql, ...$values));

            if ($result === false) {
                if ($transaction_started) {
                    $connection->query('ROLLBACK');
                }
                error_log('[MealsDB Sync] Failed to execute Meals DB client insert: ' . self::redact_sql_error((string) ($connection->last_error ?? '')));
                return false;
            }

            $insert_id = (int) $connection->insert_id;

            if ($transaction_started) {
                $commit = $connection->query('COMMIT');
                if ($commit === false) {
                    $connection->query('ROLLBACK');
                    error_log('[MealsDB Sync] Failed to commit Meals DB client insert: ' . self::redact_sql_error((string) ($connection->last_error ?? '')));
                    return false;
                }
            }

            return $insert_id > 0 ? $insert_id : false;
        } catch (\Throwable $e) {
            if ($transaction_started) {
                $connection->query('ROLLBACK');
            }
            error_log('[MealsDB Sync] Meals DB client insert threw: ' . $e->getMessage());
            // STR-LOG: rolled back and returned false (fail-closed), but a
            // sync write that threw mid-transaction should be visible.
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'  => 'error',
                    'category'  => 'sync',
                    'subsystem' => 'sync_mutate',
                    'event'     => 'create_client.threw',
                    'outcome'   => MealsDB_Event_Log::OUTCOME_FAILED,
                    'message'   => $e->getMessage(),
                ]);
            }
            return false;
        }
    }

    /**
     * Resolve a synchronization conflict using the provided descriptor.
     *
     * @param array<string, mixed> $conflict Conflict metadata describing the resolution to apply.
     *
     * @return bool True when the conflict has been resolved, false otherwise.
     */
    public function resolve_conflict(array $conflict): bool {
        return false;
    }

    /**
     * Link a Meals DB client to a WooCommerce user.
     *
     * @param int $client_id
     * @param int $user_id
     * @return true|WP_Error
     */
    public function link_meals_client_to_wc_user(int $client_id, int $user_id) {
        if ($client_id <= 0 || $user_id <= 0) {
            return new WP_Error(
                'mealsdb_invalid_link_request',
                __('A valid client ID and WordPress user ID are required to create a link.', 'meals-db')
            );
        }

        $connection = $this->require_connection();

        if (is_wp_error($connection)) {
            return $connection;
        }

        // No client-type gate: Private clients are first-class meals_clients
        // rows since Phase S and the admin link UI must work for them (see
        // push_to_meals_db). The duplicate-wp_user_id soft guard below still
        // applies to every client type.

        $wp_user = get_userdata($user_id);
        if (!$wp_user instanceof WP_User) {
            return new WP_Error(
                'mealsdb_sync_user_missing',
                __('Unable to locate the selected WordPress user.', 'meals-db')
            );
        }

        $transaction_started = false;
        $connection->query('START TRANSACTION');
        $transaction_started = true;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $available_columns = $this->get_table_columns($connection, $clients_table);
        $primary_key = $this->resolve_primary_key_column($available_columns);
        $wp_column = $this->choose_column(['wordpress_user_id', 'wp_user_id'], $available_columns);

        if ($primary_key === null || $wp_column === null) {
            if ($transaction_started) {
                $connection->query('ROLLBACK');
            }

            return new WP_Error(
                'mealsdb_link_prepare_failed',
                __('Required Meals DB columns for linking could not be found.', 'meals-db')
            );
        }

        $escaped_table  = str_replace('`', '``', $clients_table);
        $escaped_pk     = str_replace('`', '``', $primary_key);
        $escaped_column = str_replace('`', '``', $wp_column);

        // Detect whether this WP user is already linked to a DIFFERENT client.
        //
        // The schema intentionally does NOT declare wp_user_id as UNIQUE: the
        // operator has confirmed a legitimate case where one WordPress user
        // maps to two client records — a person who is both an SDNB recipient
        // AND a Veteran (distinct programs, distinct billing). Per directive
        // MAJ-1 we ALLOW the duplicate link instead of hard-refusing it.
        //
        // The hard refusal used to exist because the order->client resolvers
        // (MealsDB_Allocation_Engine::resolve_client_for_order and
        // MealsDB_Order_Fees::resolve_government_client) fall back to a
        // `wp_user_id ... LIMIT 1` lookup, which would arbitrarily pick one of
        // the two records and silently mis-route an order's billing. That risk
        // is now handled at the resolver layer: those resolvers disambiguate a
        // multi-client user by the order's mealsdb_rate_id (a rate row pins
        // exactly one client) and emit a `degraded` trunk event when they
        // cannot — so a mis-route is logged and greppable rather than silent.
        // We therefore permit the link and record the duplicate as an
        // operational warning (an attempt/outcome -> the trunk, NOT the audit
        // log, which is for committed data changes).
        $existing_client_sql = sprintf(
            'SELECT `%s` FROM `%s` WHERE `%s` = %%d AND `%s` <> %%d LIMIT 1',
            $escaped_pk,
            $escaped_table,
            $escaped_column,
            $escaped_pk
        );
        $existing_client = $connection->get_var(
            $connection->prepare($existing_client_sql, $user_id, $client_id)
        );
        if ($existing_client !== null) {
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'    => 'warning',
                    'category'    => 'sync',
                    'subsystem'   => 'sync_mutate',
                    'event'       => 'link_wp_user.duplicate_allowed',
                    'outcome'     => MealsDB_Event_Log::OUTCOME_DEGRADED,
                    'message'     => sprintf(
                        'WP user %d now linked to multiple clients (existing %d, new %d) — dual-program; resolver routes orders by rate.',
                        $user_id,
                        (int) $existing_client,
                        $client_id
                    ),
                    'entity_type' => 'user',
                    'entity_id'   => $user_id,
                    'context'     => [
                        'existing_client' => (int) $existing_client,
                        'new_client'      => $client_id,
                    ],
                ]);
            }
            // Fall through to the UPDATE in the same transaction: a duplicate
            // is no longer fatal.
        }

        $update_sql = sprintf('UPDATE `%s` SET `%s` = %%d WHERE `%s` = %%d', $escaped_table, $escaped_column, $escaped_pk);

        $result = $connection->query($connection->prepare($update_sql, $user_id, $client_id));

        if ($result === false) {
            $message = $connection->last_error ?: __('Unknown database error.', 'meals-db');

            if ($transaction_started) {
                $connection->query('ROLLBACK');
            }

            error_log('[MealsDB Sync] Failed executing client link statement: ' . $message);

            return new WP_Error(
                'mealsdb_link_execute_failed',
                sprintf(__('Unable to link the client to the WordPress user: %s', 'meals-db'), $message)
            );
        }

        $affected = $connection->rows_affected;

        if ($affected === 0) {
            if ($transaction_started) {
                $connection->query('ROLLBACK');
            }

            return new WP_Error(
                'mealsdb_link_no_rows',
                __('No Meals DB client record was updated. The client may not exist.', 'meals-db')
            );
        }

        if ($transaction_started) {
            $commit_result = $connection->query('COMMIT');
            if ($commit_result === false) {
                $connection->query('ROLLBACK');

                return new WP_Error(
                    'mealsdb_link_commit_failed',
                    __('Failed to finalize the client link transaction.', 'meals-db')
                );
            }
        }

        return true;
    }

    /**
     * Link a Meals DB client to a WordPress user account.
     *
     * @return true|WP_Error
     */
    public function link_client_to_wordpress_user(int $client_id, int $wp_user_id) {
        return $this->link_meals_client_to_wc_user($client_id, $wp_user_id);
    }

    /**
     * Get the Meals DB client ID associated with a WordPress user.
     *
     * @param int $wp_user_id WordPress user ID
     * @return int Client ID, or 0 if not found
     */
    private function get_client_id_from_wp_user(int $wp_user_id): int {
        $connection = $this->require_connection();

        if (is_wp_error($connection)) {
            return 0;
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $available_columns = $this->get_table_columns($connection, $clients_table);
        $primary_key = $this->resolve_primary_key_column($available_columns);
        $wp_column = $this->choose_column(['wordpress_user_id', 'wp_user_id'], $available_columns);

        if ($primary_key === null || $wp_column === null) {
            return 0;
        }

        $escaped_table = str_replace('`', '``', $clients_table);
        $escaped_pk = str_replace('`', '``', $primary_key);
        $escaped_wp_col = str_replace('`', '``', $wp_column);
        $sql = sprintf('SELECT `%s` FROM `%s` WHERE `%s` = %%d LIMIT 1', $escaped_pk, $escaped_table, $escaped_wp_col);

        $client_id = $connection->get_var($connection->prepare($sql, $wp_user_id));

        return is_numeric($client_id) ? (int) $client_id : 0;
    }

    /**
     * Get the WordPress user ID associated with a Meals DB client.
     *
     * @param int $client_id Meals DB client ID
     * @return int WordPress user ID, or 0 if not found
     */
    private function get_wp_user_id_from_client(int $client_id): int {
        $connection = $this->require_connection();

        if (is_wp_error($connection)) {
            return 0;
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $available_columns = $this->get_table_columns($connection, $clients_table);
        $primary_key = $this->resolve_primary_key_column($available_columns);
        $wp_column = $this->choose_column(['wordpress_user_id', 'wp_user_id'], $available_columns);

        if ($primary_key === null || $wp_column === null) {
            return 0;
        }

        $escaped_table = str_replace('`', '``', $clients_table);
        $escaped_pk = str_replace('`', '``', $primary_key);
        $escaped_wp_col = str_replace('`', '``', $wp_column);
        $sql = sprintf('SELECT `%s` FROM `%s` WHERE `%s` = %%d LIMIT 1', $escaped_wp_col, $escaped_table, $escaped_pk);

        $wp_user_id = $connection->get_var($connection->prepare($sql, $client_id));

        return is_numeric($wp_user_id) ? (int) $wp_user_id : 0;
    }

    /**
     * Ensure a wpdb connection is available for mutations.
     *
     * @return wpdb|WP_Error
     */
    private function require_connection() {
        if ($this->connection instanceof wpdb) {
            return $this->connection;
        }

        global $wpdb;

        if ($wpdb instanceof wpdb) {
            $this->connection = $wpdb;
            return $this->connection;
        }

        return new WP_Error(
            'mealsdb_db_connection_failed',
            __('Unable to connect to the Meals DB database. Please try again later.', 'meals-db')
        );
    }

    /**
     * Build a safe mapping of identity fields to Meals DB columns.
     *
     * @param array<string, bool> $available_columns
     * @return array<string, string>
     */
    private function build_identity_column_map(array $available_columns): array {
        $map = [];

        // Core identity fields with possible column name variants.
        $field_candidates = [
            'first_name'        => ['first_name'],
            'last_name'         => ['last_name'],
            'client_email'      => ['client_email'],
            'phone_primary'     => ['phone_primary', 'client_phone_1', 'phone'],
            'wordpress_user_id' => ['wordpress_user_id', 'wp_user_id'],
        ];

        foreach ($field_candidates as $field => $candidates) {
            $column = $this->choose_column($candidates, $available_columns);

            if ($column !== null) {
                $map[$field] = $column;
            }
        }

        // WP-authoritative fields where the field name matches the column name directly.
        $direct_fields = [
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

        foreach ($direct_fields as $field) {
            if (!isset($map[$field]) && isset($available_columns[$field])) {
                $map[$field] = $field;
            }
        }

        return $map;
    }

    /**
     * Resolve the Meals DB clients primary key column.
     *
     * @param array<string, bool> $available_columns
     */
    private function resolve_primary_key_column(array $available_columns): ?string {
        return $this->choose_column([
            $this->clients_primary_key,
            'client_id',
            'id',
        ], $available_columns);
    }

    /**
     * Pick the first available column from a candidate list.
     *
     * @param array<int, string|null> $candidates
     * @param array<string, bool>     $available_columns
     */
    private function choose_column(array $candidates, array $available_columns): ?string {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && isset($available_columns[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Retrieve and cache the available columns for a table.
     *
     * @param wpdb $connection
     * @return array<string, bool>
     */
    private function get_table_columns(wpdb $connection, string $table): array {
        if (isset($this->column_cache[$table])) {
            return $this->column_cache[$table];
        }

        $escaped_table = str_replace('`', '``', $table);
        $columns = [];

        $sql = sprintf('SHOW COLUMNS FROM `%s`', $escaped_table);
        $results = $connection->get_results($sql, ARRAY_A);

        if (is_array($results)) {
            foreach ($results as $row) {
                if (!empty($row['Field'])) {
                    $columns[(string) $row['Field']] = true;
                }
            }
        }

        $this->column_cache[$table] = $columns;

        return $columns;
    }

    /**
     * Apply an individual update operation to a WordPress user field.
     *
     * @param WP_User $user
     * @param string  $field
     * @param mixed   $value
     *
     * @return true|WP_Error
     */
    private function apply_wp_user_update(WP_User $user, string $field, $value) {
        $woo_user_id = (int) $user->ID;
        $new_value   = is_scalar($value) ? (string) $value : '';
        $old_value   = null;
        $update_success = false;
        $error_code = 'mealsdb_sync_failed';
        $error_message = '';

        // Resolve the WP meta map entry for this field, if available.
        $meta_map = MealsDB_Sync::get_field_to_wp_meta_map();
        $descriptor = $meta_map[$field] ?? null;

        switch ($field) {
            case 'first_name':
            case 'last_name':
                $old_value = $field === 'first_name' ? $user->first_name : $user->last_name;
                $result = wp_update_user([
                    'ID'    => $woo_user_id,
                    $field  => $new_value,
                ]);
                if (!is_wp_error($result)) {
                    $update_success = true;
                } else {
                    $error_message = $result->get_error_message();
                    error_log('[MealsDB Sync] Failed to sync ' . $field . ' for user ' . $woo_user_id . ': ' . $error_message);
                }
                break;
            case 'client_email':
                $old_value = $user->user_email;
                $result = wp_update_user([
                    'ID'         => $woo_user_id,
                    'user_email' => $new_value,
                ]);
                if (!is_wp_error($result)) {
                    $update_success = true;
                } else {
                    $error_message = $result->get_error_message();
                    error_log('[MealsDB Sync] Failed to sync email for user ' . $woo_user_id . ': ' . $error_message);
                }
                break;
            case 'phone_primary':
            case 'client_phone_1':
                $old_value = get_user_meta($woo_user_id, 'billing_phone', true);
                $update_success = update_user_meta($woo_user_id, 'billing_phone', $new_value) !== false;
                if (!$update_success) {
                    $error_message = __('Unable to update the customer phone number.', 'meals-db');
                    error_log('[MealsDB Sync] Failed to sync phone for user ' . $woo_user_id . '.');
                }
                break;
            default:
                // Handle any WP-authoritative field that maps to user meta.
                if ($descriptor !== null && $descriptor['type'] === 'meta') {
                    $meta_key = $descriptor['key'];

                    // Defence-in-depth meta_key allowlist. Today the
                    // descriptor map is hardcoded, but a future expansion
                    // that drew from filterable input would let a caller
                    // rewrite arbitrary user meta. Restrict to the keys
                    // referenced by the canonical WP-side map.
                    if (!self::is_allowed_user_meta_key($meta_key)) {
                        $error_code    = 'mealsdb_sync_unsupported_field';
                        $error_message = __('This field cannot be overridden from Meals DB.', 'meals-db');
                        break;
                    }

                    $old_value = get_user_meta($woo_user_id, $meta_key, true);
                    $update_success = update_user_meta($woo_user_id, $meta_key, $new_value) !== false;
                    if (!$update_success) {
                        $error_message = sprintf(
                            __('Unable to update user meta "%s".', 'meals-db'),
                            $meta_key
                        );
                        error_log('[MealsDB Sync] Failed to sync meta ' . $meta_key . ' for user ' . $woo_user_id . '.');
                    }
                } else {
                    $error_code = 'mealsdb_sync_unsupported_field';
                    $error_message = __('This field cannot be overridden from Meals DB.', 'meals-db');
                }
                break;
        }

        if (!$update_success) {
            if ($error_message === '') {
                $error_message = __('An unexpected error prevented the override from completing.', 'meals-db');
            }

            return new WP_Error($error_code, $error_message);
        }

        MealsDB_Logger::log(
            'sync_override',
            $woo_user_id,
            $field,
            is_scalar($old_value) ? (string) $old_value : null,
            $new_value,
            'mealsdb'
        );

        return true;
    }
}
