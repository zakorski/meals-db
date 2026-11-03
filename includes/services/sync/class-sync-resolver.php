<?php
/**
 * Resolution writes for sync conflicts (directive K21 ITEMS 10-12).
 *
 * The operator picks one side of a conflict — WordPress or a specific linked
 * client — and the chosen value is written to every OTHER side of the
 * account. This is the ONLY path in the sync permitted to overwrite a
 * populated value, so it is deliberately narrow:
 *
 *   - The chosen value is READ HERE from the chosen side (never taken from the
 *     browser) and must be non-blank and parse for its comparator: a blank
 *     choice would blank the other sides; an unparseable one (commence_date
 *     '202409-02-03') would write garbage into a typed column.
 *   - Only sides that DIFFER from the chosen value are written. A side that
 *     is already equal after normalisation is left byte-for-byte alone, which
 *     is what keeps 'sdnb rural' from being "corrected" to 'sdnb' (ITEM 3's
 *     lossy-direction rule) and keeps phone formatting untouched.
 *   - WordPress is written FIRST. The likeliest failure (a user_email
 *     collision) then stops the resolution before MealsDB changes at all.
 *   - delivery_initials is never written: only two-way registry columns are
 *     resolvable.
 *   - Writes run with the sync hooks suppressed (MealsDB_Sync::
 *     without_sync_hooks) so profile_update cannot re-enter the sync.
 *   - Changing a user_email suppresses WordPress's "your email changed"
 *     notice to the old address (ITEM 11); many are noemail### placeholders.
 *   - Shadow mode refuses any choice that would write WordPress (ITEM 12).
 *   - Every write is audit-logged (old, new, direction via action+source,
 *     user via MealsDB_Logger).
 *   - Derived fields follow their source (ITEM 6): a resolution recomputes
 *     delivery_area_zone / delivery_day and MAY overwrite them; a null
 *     computed value never writes.
 *   - The conflict closes only if a fresh re-read shows every side now
 *     agrees. A partial failure leaves it open with the reason; the sides
 *     already written hold the chosen value, which is what the operator asked
 *     for.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

final class MealsDB_Sync_Resolver {

    /**
     * Resolve one conflict. The caller has verified the stale-page hash and
     * that the account's values still conflict.
     *
     * @param array<string, mixed>             $row     Live conflict row.
     * @param string                           $side    'wp' | 'client:<id>'
     * @param array<int, array<string, mixed>> $clients Active linked clients (fresh).
     * @return array{ok: bool, code: string, message: string}
     */
    public static function resolve(MealsDB_Sync_Conflicts $store, array $row, string $side, WP_User $user, array $clients, int $operator_id, string $now): array {
        $column = (string) $row['field'];
        $field  = MealsDB_Sync_Registry::get($column);
        if ($field === null || $field['direction'] !== MealsDB_Sync_Registry::TWO_WAY) {
            return self::result(false, 'invalid_field', __('This field cannot be resolved here.', 'meals-db'));
        }
        $cmp   = $field['comparator'];
        $wp    = MealsDB_Sync_Reconciler::read_wp_value($user, $field['wp']);
        $meals = MealsDB_Sync_Reconciler::meals_values_for($clients, $column);

        // The chosen value, read server-side from the chosen side.
        if ($side === 'wp') {
            $chosen      = $wp;
            $chosen_side = 'wp';
        } elseif (preg_match('/^client:([1-9]\d*)$/', $side, $m) && array_key_exists((int) $m[1], $meals)) {
            $chosen      = (string) $meals[(int) $m[1]];
            $chosen_side = 'meals';
        } else {
            return self::result(false, 'invalid_side', __('That record is not linked to this account.', 'meals-db'));
        }

        if (MealsDB_Sync_Registry::is_blank($column, $chosen_side === 'wp' ? 'wp' : 'meals', $chosen)) {
            return self::result(false, 'blank_choice', __('The chosen value is blank. Choose a side that has a value.', 'meals-db'));
        }
        if (!MealsDB_Sync_Comparator::parses($cmp, $chosen)) {
            return self::result(false, 'unparseable_choice', __('The chosen value is not valid for this field. Correct it at its source, or keep the other value.', 'meals-db'));
        }

        // Targets: every side that is not the chosen one and does not
        // already equal the chosen value.
        $wp_target = null;
        if ($side !== 'wp' && !MealsDB_Sync_Comparator::equals($cmp, $wp, $chosen)) {
            $wp_target = self::value_for_wp($column, $cmp, $chosen);
        }
        $client_targets = [];
        foreach ($meals as $client_id => $value) {
            if ($side === 'client:' . $client_id) {
                continue;
            }
            if (!MealsDB_Sync_Comparator::equals($cmp, (string) $value, $chosen)) {
                $client_targets[$client_id] = self::value_for_meals($cmp, $chosen);
            }
        }

        if ($wp_target !== null && MealsDB_Shadow_Mode::is_enabled()) {
            return self::result(false, 'shadow_mode', __('Shadow mode is on, so WordPress cannot be changed. Nothing was written.', 'meals-db'));
        }

        $wp_user_id = (int) $user->ID;
        $failures   = [];

        MealsDB_Sync::without_sync_hooks(static function () use (
            $wp_target, $client_targets, $user, $field, $column, $side, $clients, $wp_user_id, &$failures
        ): void {
            if ($wp_target !== null) {
                $written = self::write_wp_field($user, $field, $column, $wp_target, 'sync_resolve_wp', 'resolution:' . $side);
                if (is_wp_error($written)) {
                    // Stop here: MealsDB is not touched when WordPress refused.
                    $failures[] = $written->get_error_message();
                    return;
                }
            }

            $mutate = new MealsDB_Sync_Mutate();
            foreach ($client_targets as $client_id => $value) {
                $old = self::client_value($clients, $client_id, $column);
                if ($mutate->update_meals_client($client_id, [$column => $value])) {
                    MealsDB_Logger::log('sync_resolve_client', $client_id, $column, $old, $value, 'resolution:' . $side);
                } else {
                    $failures[] = sprintf(__('Client #%d could not be updated.', 'meals-db'), $client_id);
                }
            }

            if ($field['derived'] !== []) {
                self::recompute_derived($mutate, $wp_user_id, $column, $field['derived'], self::DERIVED_OVERWRITE);
            }
        });

        if ($failures !== []) {
            return self::result(false, 'write_failed', implode(' ', $failures));
        }

        // Close only if a fresh read shows the account now agrees.
        $fresh_user    = get_userdata($wp_user_id);
        $fresh_clients = MealsDB_Sync_Reconciler::fetch_clients([$wp_user_id]);
        if (!$fresh_user instanceof WP_User || $fresh_clients === null) {
            return self::result(false, 'verify_failed', __('Written, but the result could not be re-read. The next nightly scan will settle it.', 'meals-db'));
        }
        $after = MealsDB_Sync_Reconciler::evaluate_field(
            $column,
            MealsDB_Sync_Reconciler::read_wp_value($fresh_user, $field['wp']),
            MealsDB_Sync_Reconciler::meals_values_for($fresh_clients[$wp_user_id] ?? [], $column)
        );
        if ($after['outcome'] !== MealsDB_Sync_Reconciler::O_EQUAL) {
            return self::result(false, 'not_converged', __('Written, but the values still differ. The conflict stays open.', 'meals-db'));
        }

        if (!$store->resolve($row, $side, $operator_id, $now)) {
            return self::result(false, 'error', __('Values were written, but the conflict could not be closed. The next nightly scan will close it.', 'meals-db'));
        }
        return self::result(true, 'resolved', __('Resolved.', 'meals-db'));
    }

    /**
     * Would resolving to $side write WordPress? Used by the view to disable
     * such choices under shadow mode.
     *
     * @param array<int, string> $meals
     */
    public static function writes_wp(string $column, string $side, string $wp, array $meals): bool {
        if ($side === 'wp') {
            return false;
        }
        $field = MealsDB_Sync_Registry::get($column);
        if ($field === null || !preg_match('/^client:([1-9]\d*)$/', $side, $m) || !array_key_exists((int) $m[1], $meals)) {
            return false;
        }
        return !MealsDB_Sync_Comparator::equals($field['comparator'], $wp, (string) $meals[(int) $m[1]]);
    }

    /**
     * Value to store in WordPress. client_type writes the CANONICAL
     * customer_group for the class (ITEM 3); dates are written canonical.
     * Shared with the nightly fills (MealsDB_Sync_Reconciler).
     */
    public static function value_for_wp(string $column, string $cmp, string $chosen): string {
        if ($cmp === MealsDB_Sync_Comparator::CLIENT_TYPE) {
            return (string) MealsDB_Migration_Consolidated::client_type_to_customer_group(
                MealsDB_Sync_Comparator::client_type_class($chosen)
            );
        }
        return self::canonical($cmp, $chosen);
    }

    /** Value to store in meals_clients: the class for client_type (ENUM). Shared with the nightly fills. */
    public static function value_for_meals(string $cmp, string $chosen): string {
        if ($cmp === MealsDB_Sync_Comparator::CLIENT_TYPE) {
            return (string) MealsDB_Sync_Comparator::client_type_class($chosen);
        }
        return self::canonical($cmp, $chosen);
    }

    private static function canonical(string $cmp, string $chosen): string {
        if ($cmp === MealsDB_Sync_Comparator::DATE) {
            return (string) MealsDB_Sync_Comparator::parse_date(trim($chosen));
        }
        if ($cmp === MealsDB_Sync_Comparator::INTEGER) {
            return (string) (int) trim($chosen);
        }
        return $chosen;
    }

    /**
     * Write one registry field to WordPress — the ONE WordPress writer for
     * K21, used by resolution and by the nightly fills, so ITEM 11's email
     * guards apply to both. Meta fields also write their also_write keys
     * (ITEM 4: both address_2 keys). Returns WP_Error on the first failure.
     *
     * @param array<string, mixed> $field
     * @param string $audit_action    sync_resolve_wp | sync_fill_wp
     * @param string $source          audit source, e.g. resolution:client:12 / nightly
     * @param bool   $fill_blank_only true for the automated fills: a key whose
     *                                current value is NOT blank is skipped,
     *                                never overwritten. Needed for also_write
     *                                keys: a delivery_area_name fill used to
     *                                overwrite a populated billing_address_2
     *                                whenever shipping_address_2 was blank
     *                                (caught by Test 32). Resolution passes
     *                                false: an operator choice may overwrite.
     * @return true|WP_Error
     */
    public static function write_wp_field(WP_User $user, array $field, string $column, string $value, string $audit_action, string $source, bool $fill_blank_only = false) {
        $wp_user_id = (int) $user->ID;

        if ($field['wp']['type'] === 'core') {
            $key = (string) $field['wp']['key'];
            $old = isset($user->$key) ? (string) $user->$key : '';
            if (MealsDB_Sync::would_blank_value($value, $old)) {
                return self::refuse_blank($wp_user_id, $column, $key);
            }
            if ($fill_blank_only && $old !== $value && !MealsDB_Sync_Registry::is_blank($column, 'wp', $old)) {
                return true; // populated: a fill never replaces it
            }

            // ITEM 11: WordPress mails the OLD address when user_email
            // changes. Suppress it for this write only.
            $is_email = $key === 'user_email';
            if ($is_email) {
                add_filter('send_email_change_email', '__return_false', 999);
            }
            try {
                $result = wp_update_user(['ID' => $wp_user_id, $key => $value]);
            } finally {
                if ($is_email) {
                    remove_filter('send_email_change_email', '__return_false', 999);
                }
            }
            if (is_wp_error($result)) {
                // e.g. existing_user_email: user_email is unique. The
                // conflict stays open with the reason (ITEM 11).
                return new WP_Error('mealsdb_resolve_wp_failed', sprintf(
                    /* translators: %s: WordPress error message */
                    __('WordPress refused the change: %s', 'meals-db'),
                    $result->get_error_message()
                ));
            }
            MealsDB_Logger::log($audit_action, $wp_user_id, $column, $old, $value, $source);
            return true;
        }

        $keys = array_merge([(string) $field['wp']['key']], array_map('strval', $field['wp']['also_write'] ?? []));
        foreach ($keys as $meta_key) {
            $old = get_user_meta($wp_user_id, $meta_key, true);
            $old = is_scalar($old) ? (string) $old : '';
            if ($old === $value) {
                // Already holds the value: success without a write
                // (update_user_meta() returns false on a no-op, which would
                // otherwise read as a failure — audit-2026-08 B07 / H11).
                continue;
            }
            if (MealsDB_Sync::would_blank_value($value, $old)) {
                return self::refuse_blank($wp_user_id, $column, $meta_key);
            }
            if ($fill_blank_only && !MealsDB_Sync_Registry::is_blank($column, 'wp', $old)) {
                continue; // populated: a fill never replaces it
            }
            if (update_user_meta($wp_user_id, $meta_key, $value) === false) {
                return new WP_Error('mealsdb_resolve_wp_failed', sprintf(
                    /* translators: %s: user meta key */
                    __('WordPress could not save %s.', 'meals-db'),
                    $meta_key
                ));
            }
            MealsDB_Logger::log($audit_action, $wp_user_id, $meta_key, $old, $value, $source);
        }
        return true;
    }

    /** recompute_derived() modes. */
    public const DERIVED_OVERWRITE = 'overwrite';
    public const DERIVED_FILL      = 'fill';

    /**
     * K13 ITEM 3, kept on the ONE live WordPress writer: an empty value never
     * replaces a populated one. Every caller already guarantees a non-blank
     * value (resolution refuses a blank choice; a fill's source is
     * non-blank), so this is defence in depth — it is what the K13 guard on
     * the retired MealsDB_Sync_Mutate::apply_wp_user_update() protected, and
     * it must not disappear with that method.
     */
    private static function refuse_blank(int $wp_user_id, string $column, string $key): WP_Error {
        MealsDB_Event_Log::record([
            'severity' => 'warning', 'category' => 'sync', 'subsystem' => 'sync',
            'event' => 'sync.empty_overwrite_refused', 'outcome' => 'degraded',
            'message' => sprintf('DB->WP: refused to blank populated %s for user %d', $key, $wp_user_id),
            'context' => ['user_id' => $wp_user_id, 'field' => 'field:' . $column, 'key' => $key],
        ]);
        return new WP_Error('mealsdb_sync_empty_overwrite_refused', __('Refused to overwrite a populated field with an empty value.', 'meals-db'));
    }

    /**
     * ITEM 6: recompute derived columns from each client's source value.
     *
     * DERIVED_OVERWRITE (resolution — an operator action): a populated
     * derived value may be replaced. DERIVED_FILL (nightly, after the sync
     * filled the source): write only when the derived value is BLANK; a
     * populated value that differs is logged as sync.derived_mismatch and
     * left alone — otherwise filling a blank service_center_charged would
     * silently overwrite a hand-set delivery_area_zone and move the client
     * to a different invoice and rate. In both modes a null computed value
     * never writes, and every change is audited and evented with old and new.
     *
     * @param string[]   $derived
     * @param int[]|null $only_client_ids limit to these clients (fill mode: the ones just filled)
     */
    public static function recompute_derived(MealsDB_Sync_Mutate $mutate, int $wp_user_id, string $source_column, array $derived, string $mode, ?array $only_client_ids = null): void {
        $clients = MealsDB_Sync_Reconciler::fetch_clients([$wp_user_id]);
        foreach ($clients[$wp_user_id] ?? [] as $client) {
            $client_id = (int) $client['client_id'];
            if ($only_client_ids !== null && !in_array($client_id, $only_client_ids, true)) {
                continue;
            }
            $source    = (string) ($client[$source_column] ?? '');

            foreach ($derived as $column) {
                $new = null;
                if ($column === 'delivery_area_zone') {
                    $new = MealsDB_Operational_Constants::zone_for_service_centre($source);
                } elseif ($column === 'delivery_day') {
                    $new = MealsDB_Zone_Day::day_for_zone($source);
                }
                $old = isset($client[$column]) ? (string) $client[$column] : '';

                if ($new === null) {
                    if (trim($source) !== '') {
                        MealsDB_Event_Log::record([
                            'severity' => 'warning', 'category' => 'sync', 'subsystem' => 'sync_resolve',
                            'event' => 'sync.derived_unresolved', 'outcome' => MealsDB_Event_Log::OUTCOME_DEGRADED,
                            'message' => sprintf('%s for client %d could not be derived from %s; left unchanged.', $column, $client_id, $source_column),
                            'entity_type' => 'client', 'entity_id' => $client_id,
                            'context' => ['client_id' => $client_id, 'column' => $column, 'source' => $source],
                        ]);
                    }
                    continue;
                }
                if (strcasecmp(trim($old), $new) === 0) {
                    continue;
                }
                if ($mode === self::DERIVED_FILL && trim($old) !== '') {
                    MealsDB_Event_Log::record([
                        'severity' => 'warning', 'category' => 'sync', 'subsystem' => 'sync_fill',
                        'event' => 'sync.derived_mismatch', 'outcome' => MealsDB_Event_Log::OUTCOME_DEGRADED,
                        'message' => sprintf('%s for client %d is %s but %s implies %s; left unchanged (not blank).', $column, $client_id, $old, $source_column, $new),
                        'entity_type' => 'client', 'entity_id' => $client_id,
                        'context' => ['client_id' => $client_id, 'column' => $column, 'stored' => $old, 'computed' => $new],
                    ]);
                    continue;
                }
                if (!$mutate->update_meals_client($client_id, [$column => $new])) {
                    MealsDB_Event_Log::record([
                        'severity' => 'error', 'category' => 'sync', 'subsystem' => 'sync_resolve',
                        'event' => 'sync.derived_write_failed', 'outcome' => MealsDB_Event_Log::OUTCOME_FAILED,
                        'message' => sprintf('Could not update %s for client %d.', $column, $client_id),
                        'entity_type' => 'client', 'entity_id' => $client_id,
                    ]);
                    continue;
                }
                MealsDB_Logger::log('sync_derived_recompute', $client_id, $column, $old, $new, 'derived:' . $source_column);
                MealsDB_Event_Log::record([
                    'severity' => 'info', 'category' => 'sync', 'subsystem' => 'sync_resolve',
                    'event' => $column === 'delivery_area_zone' ? 'sync.derived_zone_changed' : 'sync.derived_day_changed',
                    'outcome' => MealsDB_Event_Log::OUTCOME_SUCCEEDED,
                    'message' => sprintf('%s for client %d: %s -> %s', $column, $client_id, $old === '' ? '(blank)' : $old, $new),
                    'entity_type' => 'client', 'entity_id' => $client_id,
                    'context' => ['client_id' => $client_id, 'column' => $column, 'old' => $old, 'new' => $new],
                ]);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $clients */
    private static function client_value(array $clients, int $client_id, string $column): ?string {
        foreach ($clients as $client) {
            if ((int) $client['client_id'] === $client_id) {
                return isset($client[$column]) ? (string) $client[$column] : null;
            }
        }
        return null;
    }

    /** @return array{ok: bool, code: string, message: string} */
    private static function result(bool $ok, string $code, string $message): array {
        return ['ok' => $ok, 'code' => $code, 'message' => $message];
    }
}
