<?php
/**
 * Rate limiting for Meals DB AJAX endpoints.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Rate_Limiter {

    /**
     * Default rate limits per action (requests per hour).
     */
    private const DEFAULT_LIMITS = [
        'quick_order_create' => 50,      // Creating orders
        'quick_order_read' => 200,       // Reading products/categories
        'client_search' => 100,          // Client search operations
        'client_modify' => 50,           // Creating/updating clients
        'sync_operations' => 100,        // Sync operations
        'default' => 100,                // Default for unlisted actions
    ];

    /**
     * Check if the current user has exceeded the rate limit for an action.
     *
     * @param string $action The action being performed (e.g., 'quick_order', 'client_search').
     * @param int|null $user_id Optional user ID. Uses current user if not provided.
     * @return bool True if the request is allowed, false if rate limit exceeded.
     */
    public static function check_rate_limit(string $action, ?int $user_id = null): bool {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if ($user_id <= 0) {
            // For non-logged-in users, use IP-based rate limiting
            $user_id = 'ip_' . self::get_client_ip();
        }

        $transient_key = self::get_transient_key($action, $user_id);
        $attempts = get_transient($transient_key);

        if ($attempts === false) {
            $attempts = 0;
        } else {
            $attempts = (int) $attempts;
        }

        $limit = self::get_limit_for_action($action);

        // Check if limit exceeded
        if ($attempts >= $limit) {
            error_log(sprintf(
                '[MealsDB Rate Limit] Action "%s" blocked for user/IP "%s" (attempts: %d, limit: %d)',
                $action,
                $user_id,
                $attempts,
                $limit
            ));
            return false;
        }

        // Increment counter
        set_transient($transient_key, $attempts + 1, HOUR_IN_SECONDS);

        return true;
    }

    /**
     * Get the rate limit for a specific action.
     *
     * @param string $action The action name.
     * @return int The maximum number of requests allowed per hour.
     */
    private static function get_limit_for_action(string $action): int {
        $limit = self::DEFAULT_LIMITS[$action] ?? self::DEFAULT_LIMITS['default'];

        /**
         * Filter the rate limit for a specific action.
         *
         * @param int $limit The default limit.
         * @param string $action The action name.
         */
        return (int) apply_filters('mealsdb_rate_limit', $limit, $action);
    }

    /**
     * Get the transient key for rate limiting.
     *
     * @param string $action The action name.
     * @param string|int $user_id The user ID or IP identifier.
     * @return string The transient key.
     */
    private static function get_transient_key(string $action, $user_id): string {
        return sprintf('mealsdb_rate_%s_%s', sanitize_key($action), sanitize_key((string) $user_id));
    }

    /**
     * Get the client IP address.
     *
     * @return string The client IP address.
     */
    private static function get_client_ip(): string {
        $ip = '0.0.0.0';

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        // Validate IP address
        $validated = filter_var($ip, FILTER_VALIDATE_IP);
        return $validated !== false ? $validated : '0.0.0.0';
    }

    /**
     * Reset the rate limit counter for a user/action.
     *
     * @param string $action The action name.
     * @param int|null $user_id Optional user ID. Uses current user if not provided.
     */
    public static function reset_rate_limit(string $action, ?int $user_id = null): void {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if ($user_id <= 0) {
            $user_id = 'ip_' . self::get_client_ip();
        }

        $transient_key = self::get_transient_key($action, $user_id);
        delete_transient($transient_key);
    }

    /**
     * Get remaining requests for an action.
     *
     * @param string $action The action name.
     * @param int|null $user_id Optional user ID. Uses current user if not provided.
     * @return int The number of remaining requests.
     */
    public static function get_remaining(string $action, ?int $user_id = null): int {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if ($user_id <= 0) {
            $user_id = 'ip_' . self::get_client_ip();
        }

        $transient_key = self::get_transient_key($action, $user_id);
        $attempts = get_transient($transient_key);

        if ($attempts === false) {
            $attempts = 0;
        } else {
            $attempts = (int) $attempts;
        }

        $limit = self::get_limit_for_action($action);

        return max(0, $limit - $attempts);
    }
}
