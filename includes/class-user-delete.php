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
}
