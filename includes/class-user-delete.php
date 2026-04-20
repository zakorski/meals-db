<?php
/**
 * Delete non-admin WordPress users.
 */

defined('ABSPATH') || exit;

class MealsDB_User_Delete {

    /**
     * Delete all WordPress users who are not administrators.
     *
     * @param string $confirmation Raw confirmation string from the admin UI.
     *
     * @return array<string, mixed>|WP_Error Structured result data or WP_Error on precondition failure.
     */
    public static function run(string $confirmation) {
        // Defence-in-depth: enforce capability + login state + multisite
        // safety here even if a caller (or a future code path) reaches this
        // helper without going through the admin UI gate.
        if (!is_user_logged_in() || !current_user_can('delete_users') || !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'You do not have permission to delete users.');
        }

        $normalized_confirmation = strtoupper(trim($confirmation));
        if ($normalized_confirmation !== 'DELETE') {
            return new WP_Error('confirmation_required', 'User deletion aborted: confirmation text did not match.');
        }

        $current_user_id = get_current_user_id();

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        if (is_multisite() && !function_exists('wpmu_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/ms.php';
        }

        $results = [
            'deleted'       => [],
            'skipped'       => [],
            'errors'        => [],
            'total_count'   => 0,
        ];

        // Iterate paged so we don't materialise every user at once on large
        // sites. 'fields' => 'all' is preserved so user_can() / user_login
        // remain available for the per-row logic below.
        $page     = 1;
        $per_page = 200;

        while (true) {
            $batch = get_users([
                'fields' => 'all',
                'number' => $per_page,
                'paged'  => $page,
            ]);

            if (empty($batch)) {
                break;
            }

            $results['total_count'] += count($batch);

            foreach ($batch as $user) {
                // Never delete the calling administrator.
                if ((int) $user->ID === (int) $current_user_id) {
                    $results['skipped'][] = [
                        'id'     => $user->ID,
                        'login'  => $user->user_login,
                        'email'  => $user->user_email,
                        'reason' => 'Current user',
                    ];
                    continue;
                }

                // Skip administrators.
                if (user_can($user, 'administrator')) {
                    $results['skipped'][] = [
                        'id'       => $user->ID,
                        'login'    => $user->user_login,
                        'email'    => $user->user_email,
                        'reason'   => 'Administrator',
                    ];
                    continue;
                }

                // Strip PII from the linked meals_clients row BEFORE deleting
                // the WP user. Without this step a deleted user's name,
                // address, phone, diet notes, individual_id, requisition_id
                // and vet_health_card all remain indexed in meals_clients
                // forever — orphaned, no longer traceable back to a live
                // account, and a clear GDPR / PIPEDA violation on a
                // right-to-be-forgotten request.
                //
                // The anonymisation is best-effort: a failure here is
                // logged but does not block wp_delete_user — leaving an
                // orphaned row with PII is bad, but leaving the WP user
                // alive after the admin asked for deletion is worse.
                // Anonymisation failures are surfaced in the results for
                // the UI to flag.
                $anonymised = self::anonymise_meals_client_for_wp_user((int) $user->ID);
                if (is_wp_error($anonymised)) {
                    $results['errors'][] = [
                        'id'    => $user->ID,
                        'login' => $user->user_login,
                        'email' => $user->user_email,
                        'error' => 'meals_clients anonymisation failed: ' . $anonymised->get_error_message(),
                    ];
                }

                // Reassign content to the calling administrator so we never
                // silently drop posts/comments authored by the deleted user.
                try {
                    $deleted = is_multisite()
                        ? wpmu_delete_user($user->ID)
                        : wp_delete_user($user->ID, $current_user_id);

                    if ($deleted) {
                        $results['deleted'][] = [
                            'id'       => $user->ID,
                            'login'    => $user->user_login,
                            'email'    => $user->user_email,
                        ];
                    } else {
                        $results['errors'][] = [
                            'id'       => $user->ID,
                            'login'    => $user->user_login,
                            'email'    => $user->user_email,
                            'error'    => 'wp_delete_user returned false',
                        ];
                    }
                } catch (Throwable $exception) {
                    $results['errors'][] = [
                        'id'       => $user->ID,
                        'login'    => $user->user_login,
                        'email'    => $user->user_email,
                        'error'    => $exception->getMessage(),
                    ];
                }
            }

            if (count($batch) < $per_page) {
                break;
            }
            $page++;
        }

        return $results;
    }

    /**
     * Anonymise the meals_clients row linked to a WP user, if one exists.
     *
     * Blanks every PII column, zeros the wp_user_id link, clears the
     * deterministic hash index columns (they would otherwise let an
     * attacker with a dump confirm individual_id values by hash-
     * matching), and marks the client inactive. The row is kept rather
     * than deleted so billing history (meals_client_allocations,
     * meals_delivery_allocations) remains intact — those FKs reference
     * client_id, not wp_user_id.
     *
     * Returns the affected client_id (>0) on success, 0 if no row was
     * linked to that wp_user_id, or a WP_Error on failure.
     *
     * @return int|WP_Error
     */
    public static function anonymise_meals_client_for_wp_user(int $wp_user_id) {
        if ($wp_user_id <= 0) {
            return 0;
        }

        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return new WP_Error('mealsdb_no_wpdb', 'Database connection unavailable.');
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $client_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT client_id FROM `{$clients_table}` WHERE wp_user_id = %d LIMIT 1",
            $wp_user_id
        ));

        if ($client_id <= 0) {
            return 0;
        }

        // Columns to blank. Split by NULL vs NOT-NULL-with-default so we
        // can update each group with the right sentinel — set NULL on
        // nullable PII, '' on NOT-NULL-but-string, and leave the
        // handful of NOT-NULL columns that don't carry PII (client_type,
        // use_legacy_billing) alone.
        $null_cols = [
            'client_email',
            'client_phone_1',
            'client_phone_2',
            'alternate_contact_name',
            'alternate_contact_phone_1',
            'alternate_contact_phone_2',
            'alternate_contact_email',
            'individual_id',
            'individual_id_index',
            'assigned_worker_name',
            'assigned_worker_email',
            'requisition_id',
            'requisition_id_index',
            'vet_health_card',
            'vet_health_card_index',
            'delivery_initials_index',
            'diet_concerns',
            'customer_comments',
            'per_sdnb_req',
            'notes_to_service_provider',
            'street_name',
            'city',
            'province',
            'postal_code',
            'delivery_street_name',
            'delivery_city',
            'delivery_province',
            'delivery_postal_code',
            'birth_date',
        ];

        $empty_string_cols = [
            // NOT NULL VARCHARs — blank rather than NULL.
            'first_name',
            'last_name',
            'delivery_initials',
        ];

        $set_clauses = [];
        foreach ($null_cols as $col) {
            $set_clauses[] = sprintf('`%s` = NULL', str_replace('`', '``', $col));
        }
        foreach ($empty_string_cols as $col) {
            $set_clauses[] = sprintf("`%s` = ''", str_replace('`', '``', $col));
        }
        // wp_user_id is NOT NULL; 0 is the canonical "no linked user" value.
        $set_clauses[] = '`wp_user_id` = 0';
        // Mark inactive so the client drops out of every sync / reporting / slip path.
        $set_clauses[] = '`active` = 0';

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `client_id` = %%d LIMIT 1',
            str_replace('`', '``', $clients_table),
            implode(', ', $set_clauses)
        );

        $result = $wpdb->query($wpdb->prepare($sql, $client_id));
        if ($result === false) {
            return new WP_Error(
                'mealsdb_anonymise_failed',
                $wpdb->last_error !== '' ? $wpdb->last_error : 'Unknown database error.'
            );
        }

        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log(
                'client_anonymised',
                $client_id,
                'wp_user_deletion',
                (string) $wp_user_id,
                null,
                'user_delete'
            );
        }

        return $client_id;
    }
}
