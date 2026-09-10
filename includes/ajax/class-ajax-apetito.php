<?php
if (!defined('ABSPATH')) { exit; }

/**
 * AJAX for the Apetito Item Puller. Fetch is read-ish (external GET, rate
 * limited); create is a write (own nonce). Approach A: create re-reads the
 * cached page and re-parses server-side, trusting the browser ONLY for
 * operator-owned fields (code, price, category IDs).
 *
 * Defense in depth — this layer is one of three guarding the create path
 * (CLAUDE.md Pattern 1):
 *   1. HERE (transport): nonce + capability + rate limit in that order, each
 *      failing CLOSED with a JSON error (see guard()).
 *   2. The VIEW layer (views/apetito-puller.php) re-enforces the capability at
 *      the top of the page — a caller reaching an endpoint never went through
 *      it.
 *   3. The SERVICE (MealsDB_Apetito_Product_Creator) re-checks the SKU
 *      uniqueness guard and WC availability before writing.
 * Each layer is independent; none is load-bearing alone.
 *
 * Approach A security property: the create handler reads the HTML from the
 * transient (falling back to a live re-fetch) and re-parses server-side. It
 * does NOT trust machine-parsed field values submitted by the browser. The
 * browser is trusted ONLY for `code`, `price`, `category_ids`, and `manual`
 * (operator corrections for fields the parser could not read from the page).
 *
 * NONCE_FETCH / NONCE_CREATE are intentionally separate: fetch is bounded by
 * the apetito_fetch rate bucket (30/hr) while create burns from settings_modify
 * (20/hr, shared with other config writes) — keeping a legitimate fetch session
 * from eating the write quota.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Ajax_Apetito {

    public const NONCE_FETCH  = 'mealsdb_apetito';
    public const NONCE_CREATE = 'mealsdb_apetito_create';

    public static function init(): void {
        add_action('wp_ajax_mealsdb_apetito_fetch',  [self::class, 'fetch']);
        add_action('wp_ajax_mealsdb_apetito_create', [self::class, 'create']);
    }

    // -----------------------------------------------------------------
    // Endpoints
    // -----------------------------------------------------------------

    /**
     * Fetch and parse the Apetito Nutridata page for a given code. Returns the
     * per-field ok/reason breakdown plus a duplicate-product warning when the
     * SKU already exists. Cached in a transient (7 days); the 'cached' flag in
     * the response lets the UI indicate a stale result.
     */
    public static function fetch(): void {
        if (!self::guard(self::NONCE_FETCH, 'apetito_fetch')) { return; }
        try {
            $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash((string) $_POST['code'])) : '';
            $res  = MealsDB_Apetito_Nutridata::fetch($code);
            if (empty($res['ok'])) {
                wp_send_json_error(['message' => $res['reason']], 200);
                return;
            }
            $parsed  = MealsDB_Apetito_Parser::parse($res['html'], $code);
            $reduced = self::reduce($parsed);

            $dupe         = MealsDB_Apetito_Product_Creator::find_by_sku($code);
            $dupe_message = MealsDB_Apetito_Product_Creator::duplicate_message(
                $dupe, $code, (string) ($reduced['values']['name_en'] ?? '')
            );

            wp_send_json_success([
                'code'         => $code,
                'fields'       => $parsed['fields'],   // full per-field ok/reason for the UI
                'missing'      => $reduced['missing'],
                'duplicate'    => $dupe !== null,
                'dupe_message' => $dupe_message,
                'cached'       => !empty($res['cached']),
            ]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Apetito AJAX] fetch failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to fetch the product page. Please try again.', 'meals-db')]);
        }
    }

    /**
     * Create a WC Draft product from the cached Apetito page. Re-reads the
     * transient (falling back to a live fetch) and re-parses server-side —
     * machine field values from the browser are DISCARDED. The browser supplies
     * only operator-owned fields: code, price, category_ids, and manual (operator
     * corrections for fields the parser could not read).
     *
     * On ok=true the response carries an optional 'warning' when the product was
     * created but its metadata write was incomplete (recoverable via re-save). The
     * UI must surface this rather than treating ok alone as unconditional success.
     */
    public static function create(): void {
        if (!self::guard(self::NONCE_CREATE, 'settings_modify')) { return; }
        try {
            $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash((string) $_POST['code'])) : '';

            // Approach A: re-read the cached page and re-parse server-side.
            // Never trust machine-parsed values submitted by the browser.
            $cached = get_transient(MealsDB_Apetito_Nutridata::cache_key($code));
            if (!is_string($cached) || $cached === '') {
                // Transient expired between fetch and create; attempt a live re-fetch.
                $refetch = MealsDB_Apetito_Nutridata::fetch($code);
                if (empty($refetch['ok'])) {
                    wp_send_json_error(['message' => __('The fetched page expired. Fetch the code again.', 'meals-db')], 200);
                    return;
                }
                $cached = $refetch['html'];
            }
            $parsed  = MealsDB_Apetito_Parser::parse($cached, $code);
            $reduced = self::reduce($parsed);

            // Validate price as a plain non-negative decimal. FILTER_VALIDATE_FLOAT
            // still accepts scientific notation, so reject anything that isn't a
            // simple money string and clamp to a sane ceiling — a stray '1e5'
            // must not become a $100,000 draft price.
            $price = null;
            if (isset($_POST['price']) && $_POST['price'] !== '') {
                $raw_price = sanitize_text_field(wp_unslash((string) $_POST['price']));
                if (preg_match('/^\d+(\.\d{1,2})?$/', $raw_price)) {
                    $candidate = (float) $raw_price;
                    if ($candidate >= 0 && $candidate <= 9999.99) {
                        $price = $candidate;
                    }
                }
            }

            $category_ids = [];
            if (isset($_POST['category_ids']) && is_array($_POST['category_ids'])) {
                $category_ids = array_map('intval', wp_unslash($_POST['category_ids']));
            }

            // Operator-entered corrections for fields the parser could not read.
            // Sanitized as plain text — the service layer validates further.
            $manual = [];
            if (isset($_POST['manual']) && is_array($_POST['manual'])) {
                foreach (wp_unslash($_POST['manual']) as $k => $v) {
                    $manual[sanitize_key($k)] = sanitize_text_field((string) $v);
                }
            }

            // Manual values fill only MISSING machine fields (never override a
            // parsed one). An empty manual string is treated as not provided, so
            // a partially-filled form can't clobber a successfully-parsed field
            // with blank.
            foreach ($reduced['missing'] as $miss) {
                if (isset($manual[$miss]) && $manual[$miss] !== '') {
                    $reduced['values'][$miss] = $manual[$miss];
                }
            }

            $res = MealsDB_Apetito_Product_Creator::create($reduced['values'], [
                'code'          => $code,
                'price'         => $price,
                'category_ids'  => $category_ids,
                'manual_fields' => array_keys($manual),
            ]);
            if (empty($res['ok'])) {
                wp_send_json_error(['message' => $res['reason']], 200);
                return;
            }

            // Thread the warning through even on ok=true: a degraded metadata write
            // is not a silent success (CLAUDE.md §7 anti-pattern), but the Draft
            // exists and is recoverable, so the operator gets the product link.
            wp_send_json_success([
                'product_id' => $res['product_id'],
                'warning'    => $res['warning'] ?? null,
                'edit_url'   => function_exists('get_edit_post_link') ? get_edit_post_link($res['product_id'], 'raw') : '',
            ]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Apetito AJAX] create failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to create the product. Please contact an administrator.', 'meals-db')]);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Reduce parser fields to a flat value map + a list of unreadable field names.
     * The missing list is what the 'manual' post array is allowed to fill.
     */
    private static function reduce(array $parsed): array {
        $values  = [];
        $missing = [];
        foreach ($parsed['fields'] as $name => $f) {
            if (!empty($f['ok'])) {
                $values[$name] = $f['value'];
            } else {
                $missing[] = $name;
            }
        }
        return ['values' => $values, 'missing' => $missing];
    }

    /**
     * The shared guard spine (CLAUDE.md Pattern 1): nonce → capability → rate
     * limit, in that order, each failing CLOSED with a JSON error and exit.
     * Uses two separate nonce actions because fetch and create have different
     * rate buckets.
     *
     * Returns bool so callers do `if (!self::guard(...)) { return; }` — matching
     * MealsDB_Ajax_Order_Audit. In production each wp_send_json_error() calls
     * wp_die() and never returns, so the `return false` lines are belt (and make
     * the guard testable with a non-exiting stub without falling through to the
     * handler body).
     */
    private static function guard(string $nonce_action, string $bucket): bool {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, $nonce_action)) {
            wp_send_json_error(['message' => __('Invalid request.', 'meals-db')], 400);
            return false;
        }
        // Products capability: prefer edit_product (the narrower grant the task
        // actually requires), fall back to the plugin's configured baseline.
        $fallback = class_exists('MealsDB_Permissions') ? MealsDB_Permissions::required_capability() : 'manage_woocommerce';
        if (!current_user_can('edit_product') && !current_user_can($fallback)) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'meals-db')], 403);
            return false;
        }
        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit($bucket)) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Try again later.', 'meals-db')], 429);
            return false;
        }
        return true;
    }
}
