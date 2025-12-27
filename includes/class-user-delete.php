<?php
/**
 * Delete non-admin WordPress users.
 */

class MealsDB_User_Delete {

    /**
     * Delete all WordPress users who are not administrators.
     *
     * @param string $confirmation Raw confirmation string from the admin UI.
     *
     * @return array<string, mixed>|WP_Error Structured result data or WP_Error on precondition failure.
     */
    public static function run(string $confirmation) {
        $normalized_confirmation = strtoupper(trim($confirmation));
        if ($normalized_confirmation !== 'DELETE') {
            return new WP_Error('confirmation_required', 'User deletion aborted: confirmation text did not match.');
        }

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $results = [
            'deleted'       => [],
            'skipped'       => [],
            'errors'        => [],
            'total_count'   => 0,
        ];

        // Get all users
        $all_users = get_users([
            'fields' => 'all',
        ]);

        $results['total_count'] = count($all_users);

        foreach ($all_users as $user) {
            // Skip administrators
            if (user_can($user, 'administrator')) {
                $results['skipped'][] = [
                    'id'       => $user->ID,
                    'login'    => $user->user_login,
                    'email'    => $user->user_email,
                    'reason'   => 'Administrator',
                ];
                continue;
            }

            // Attempt to delete the user
            try {
                $deleted = wp_delete_user($user->ID);

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

        return $results;
    }
}
