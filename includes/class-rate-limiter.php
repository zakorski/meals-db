<?php
/**
 * Rate limiting for Meals DB AJAX endpoints.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

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
     * Increments the counter atomically and returns the decision based on the
     * post-increment count. Two backends are supported:
     *
     *   1. A persistent external object cache (Redis / Memcached), via
     *      wp_cache_add() + wp_cache_incr(). Atomic on the backend.
     *   2. A MySQL-backed fallback that uses INSERT ... ON DUPLICATE KEY UPDATE
     *      against the options table, which is atomic at the row level.
     *
     * The previous implementation used get_transient() / set_transient(), which
     * is a textbook TOCTOU race: two concurrent requests can both read N < limit
     * and both write N+1, so a burst of N requests can slip through in the same
     * window. That's fine for eventual-consistency counters but not for
     * security-relevant throttling.
     *
     * @param string $action The action being performed.
     * @param int|null $user_id Optional user ID. Uses current user if not provided.
     * @return bool True if the request is allowed, false if rate limit exceeded.
     */
    public static function check_rate_limit(string $action, ?int $user_id = null): bool {
        $identity = self::resolve_identity($user_id);
        $key      = self::get_transient_key($action, $identity);
        $limit    = self::get_limit_for_action($action);

        $count = self::atomic_increment($key, HOUR_IN_SECONDS);

        if ($count > $limit) {
            error_log(sprintf(
                '[MealsDB Rate Limit] Action "%s" blocked for user/IP "%s" (attempts: %d, limit: %d)',
                $action,
                $identity,
                $count,
                $limit
            ));
            return false;
        }

        return true;
    }

    /**
     * Atomically increment a counter keyed on $key and return the new value.
     *
     * Chooses the best available backend. Counters expire after $ttl seconds.
     *
     * @param string $key Fully-qualified counter key.
     * @param int    $ttl Time-to-live in seconds.
     * @return int   The counter value after this increment.
     */
    private static function atomic_increment(string $key, int $ttl): int {
        // Preferred path: persistent object cache with atomic incr.
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            $group = 'mealsdb_rate';
            if (wp_cache_add($key, 1, $group, $ttl)) {
                return 1;
            }
            $incr = wp_cache_incr($key, 1, $group);
            if (is_int($incr) && $incr > 0) {
                return $incr;
            }
            // Fall through to the DB path if incr failed for any reason.
        }

        // Fallback: atomic UPSERT on the options table.
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            // No DB — fail open; better than hard-erroring legitimate traffic.
            return 1;
        }

        $option_name = '_transient_' . $key;
        $timeout_key = '_transient_timeout_' . $key;
        $now         = time();
        $expiry      = $now + $ttl;

        // If the existing window has already expired, reset both the counter and
        // the timeout in a single atomic statement. The timeout row drives that
        // decision; read it first so the decision and the write agree.
        $existing_timeout = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $timeout_key
        ));

        if ($existing_timeout > 0 && $existing_timeout < $now) {
            // Window expired — reset counter and extend the window.
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, %s, 'no')
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                $option_name,
                '0'
            ));
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, %s, 'no')
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                $timeout_key,
                (string) $expiry
            ));
        } elseif ($existing_timeout === 0) {
            // No window yet — seed the timeout row. Use INSERT IGNORE-style so
            // concurrent seeders don't fight.
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, %s, 'no')
                 ON DUPLICATE KEY UPDATE option_value = option_value",
                $timeout_key,
                (string) $expiry
            ));
        }

        // Atomic counter increment.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
            $option_name
        ));

        // Read the new value back. Not strictly atomic with the UPDATE above
        // (a concurrent incr may race us), but the returned count is always
        // >= the count produced by our UPDATE, so if we're past the limit we
        // still see it.
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $option_name
        ));

        return max(1, $count);
    }

    /**
     * Resolve the rate-limit identity (user id or IP bucket) for a call.
     */
    private static function resolve_identity(?int $user_id) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        if ($user_id <= 0) {
            return 'ip_' . self::get_client_ip();
        }
        return $user_id;
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
        $identity      = self::resolve_identity($user_id);
        $transient_key = self::get_transient_key($action, $identity);

        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            wp_cache_delete($transient_key, 'mealsdb_rate');
        }
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
        $identity      = self::resolve_identity($user_id);
        $transient_key = self::get_transient_key($action, $identity);

        $attempts = 0;
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            $cached = wp_cache_get($transient_key, 'mealsdb_rate');
            if ($cached !== false) {
                $attempts = (int) $cached;
            }
        }
        if ($attempts === 0) {
            $value = get_transient($transient_key);
            if ($value !== false) {
                $attempts = (int) $value;
            }
        }

        $limit = self::get_limit_for_action($action);
        return max(0, $limit - $attempts);
    }
}
