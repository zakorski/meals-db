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
        'quick_order_create'     => 50,   // Creating orders
        'quick_order_read'       => 200,  // Reading products/categories
        'client_search'          => 100,  // Client search operations
        'client_modify'          => 50,   // Creating/updating clients
        'sync_operations'        => 100,  // Sync operations
        'delivery_slips'         => 100,  // Delivery slip generation
        'task_modify'            => 100,  // Task / rule mutations
        // Per-cell edits to an invoice draft's review grid (INV-DRAFT-2).
        // Generous because Janet tabs through many cells in one sitting;
        // still a write, so it fails CLOSED (see MUTATING_ACTIONS).
        'invoice_draft_edit'     => 300,  // Invoice-draft review-grid field edits
        'settings_modify'        => 20,   // Settings + bulk client backfills
        'migration_destructive'  => 5,    // Migration phases, cleanup, reset
        'schema_rebuild'         => 2,    // Catastrophic: drops every plugin table
        'default'                => 100,  // Default for unlisted actions
    ];

    /**
     * Actions that mutate state. If the rate-limit backend is
     * unavailable, these fail CLOSED — an adversary can't otherwise
     * loop a mutating endpoint at wire speed during a cache outage.
     * Reads continue to fail open so a brief cache blip doesn't
     * visibly break the admin UI.
     */
    private const MUTATING_ACTIONS = [
        'quick_order_create'    => true,
        'client_modify'         => true,
        'sync_operations'       => true,
        'task_modify'           => true,
        'invoice_draft_edit'    => true,
        'settings_modify'       => true,
        'migration_destructive' => true,
        'schema_rebuild'        => true,
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

        if ($count === 0) {
            // atomic_increment signalled "no backend available".
            // Refuse mutating actions so an adversary can't loop
            // create/modify endpoints at wire speed during a cache
            // outage; allow reads so the admin UI doesn't die on
            // transient infra glitches.
            if (self::is_mutating_action($action)) {
                error_log(sprintf(
                    '[MealsDB Rate Limit] Fail-closed: no backend available for mutating action "%s" (identity: %s)',
                    $action,
                    $identity
                ));
                return false;
            }
            return true;
        }

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
     * Whether an action is classified as a mutation for the
     * fail-closed-on-backend-loss policy in check_rate_limit.
     */
    public static function is_mutating_action(string $action): bool {
        return isset(self::MUTATING_ACTIONS[$action]);
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
            // No DB AND no usable object cache — signal "no backend" to
            // the caller. 0 is distinct from every real counter value
            // (the cache path returns at least 1 on first hit, the DB
            // path takes max(1, $count)), so check_rate_limit can
            // apply fail-closed-or-open policy based on action kind.
            return 0;
        }

        $option_name = '_transient_' . $key;
        $timeout_key = '_transient_timeout_' . $key;
        $now         = time();
        $expiry      = $now + $ttl;

        // Distinguish "row missing" from "row value is 0" — both used to
        // hit the same branch, leaving stale rows that never re-rotated.
        $raw_timeout      = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $timeout_key
        ));
        $existing_timeout = $raw_timeout === null ? null : (int) $raw_timeout;

        if ($existing_timeout !== null && $existing_timeout > 0 && $existing_timeout < $now) {
            // Window expired — set the new timeout FIRST so a racing reader
            // never sees a counter without a timeout row, then reset the
            // counter. The previous order had a window where the counter
            // could be cleared while the old timeout still pointed at the
            // past, letting subsequent increments grow unbounded.
            // Row-alias form (INSERT ... AS new): VALUES(col) in ON DUPLICATE KEY
            // UPDATE is deprecated as of MySQL 8.0.20; new.col is the equivalent
            // replacement (needs MySQL 8.0.19+, deployment target is MySQL 8.x).
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, %s, 'no') AS new
                 ON DUPLICATE KEY UPDATE option_value = new.option_value",
                $timeout_key,
                (string) $expiry
            ));
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, %s, 'no') AS new
                 ON DUPLICATE KEY UPDATE option_value = new.option_value",
                $option_name,
                '0'
            ));
        } elseif ($existing_timeout === null) {
            // No timeout row yet — seed it. INSERT IGNORE pattern via
            // ON DUPLICATE KEY UPDATE option_value = option_value lets
            // concurrent seeders coexist without overwriting each other.
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
     * Get the client IP address used as the rate-limit identity for
     * unauthenticated requests.
     *
     * Defaults to REMOTE_ADDR (set by the web server, not spoofable from
     * the wire). HTTP_X_FORWARDED_FOR / HTTP_CLIENT_IP / HTTP_X_REAL_IP
     * are honoured ONLY when the immediate peer is in the trusted-proxy
     * allowlist published via the mealsdb_trusted_proxies filter or the
     * MEALSDB_TRUSTED_PROXIES constant. The previous unconditional-trust
     * behaviour let any HTTP client rotate the rate-limit identity at
     * will and bypass throttling entirely.
     */
    private static function get_client_ip(): string {
        $remote = !empty($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        $remote_validated = $remote !== '' ? filter_var($remote, FILTER_VALIDATE_IP) : false;
        $remote_ip = $remote_validated !== false ? $remote_validated : '0.0.0.0';

        if (!self::is_trusted_proxy($remote_ip)) {
            return $remote_ip;
        }

        // Trusted proxy: take the leftmost validated entry from
        // X-Forwarded-For (RFC 7239 list of client, proxy1, proxy2),
        // then fall back to X-Real-IP / Client-IP.
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $list = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
            foreach ($list as $candidate) {
                $candidate = trim($candidate);
                $valid     = $candidate !== '' ? filter_var($candidate, FILTER_VALIDATE_IP) : false;
                if ($valid !== false) {
                    return $valid;
                }
            }
        }

        foreach (['HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'] as $header) {
            if (!empty($_SERVER[$header])) {
                $candidate = sanitize_text_field(wp_unslash($_SERVER[$header]));
                $valid     = filter_var($candidate, FILTER_VALIDATE_IP);
                if ($valid !== false) {
                    return $valid;
                }
            }
        }

        return $remote_ip;
    }

    /**
     * Whether the given IP is a trusted reverse proxy that may set
     * forwarded-for headers on our behalf.
     */
    private static function is_trusted_proxy(string $ip): bool {
        $proxies = [];
        if (defined('MEALSDB_TRUSTED_PROXIES') && is_array(MEALSDB_TRUSTED_PROXIES)) {
            $proxies = MEALSDB_TRUSTED_PROXIES;
        }
        $proxies = (array) apply_filters('mealsdb_trusted_proxies', $proxies);
        if (empty($proxies)) {
            return false;
        }
        $proxies = array_filter(array_map('strval', $proxies), function ($entry) {
            return $entry !== '';
        });
        return in_array($ip, $proxies, true);
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

        // Always purge the DB fallback rows even when the object cache is
        // in use — earlier writes may have hit the DB path before the
        // cache flipped on, and stale rows would otherwise keep counting
        // forever.
        global $wpdb;
        if ($wpdb instanceof wpdb) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
                '_transient_' . $transient_key,
                '_transient_timeout_' . $transient_key
            ));
        }
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
