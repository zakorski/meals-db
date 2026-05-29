<?php
/**
 * AJAX handler for the Rate Definitions admin page (directive DEFINITIONS-1).
 *
 * One endpoint — save — carrying the full plugin guard stack, in order:
 *   1. nonce        (check_ajax_referer, fail-closed)
 *   2. capability   (manage_options — these values bill two governments; the
 *                    same tight audience the Event Log / Invoice Draft pages use)
 *   3. rate limit   (settings_modify bucket — exactly what it is for)
 *   4. confirmation friction (an explicit confirm flag — a fat-fingered rate is
 *                    a wrong government invoice; the save must be deliberate)
 *   5. validation   (server-side: numeric, non-negative, within ceiling) +
 *                    persist via the accessor
 *   6. audit        (one meals_audit_log row PER actually-changed rate, old→new)
 *
 * The audit story (STR-LOG boundary): a committed change to billing-determining
 * data → the AUDIT log, NOT the operational trunk. One 'rate_definition_edit'
 * row per field whose value actually changed; NO row for an unchanged field, a
 * refusal, or a validation failure. Rate values are not PII, so no redaction
 * concern — but route through the same MealsDB_Logger::log() for consistency.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Ajax_Rate_Definitions {

    /** Dedicated nonce — a distinct, destructive-ish billing action. */
    public const NONCE_ACTION = 'mealsdb_rate_definitions_nonce';

    public static function init(): void {
        add_action('wp_ajax_mealsdb_save_rate_definitions', [__CLASS__, 'save']);
    }

    /**
     * Validate + persist the full rate set, then audit each actual change.
     */
    public static function save(): void {
        // 1. nonce
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token.', 'meals-db')]);
            return;
        }
        // 2. capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'meals-db')]);
            return;
        }
        // 3. rate limit
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('settings_modify')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
            return;
        }

        try {
            // 4. confirmation friction — the operator must explicitly confirm
            // these take effect immediately for newly generated invoices.
            $confirm = sanitize_text_field(wp_unslash($_POST['confirm'] ?? ''));
            if ($confirm !== '1' && $confirm !== 'true') {
                wp_send_json_error(['message' => __('Please confirm the rate change before saving.', 'meals-db')]);
                return;
            }

            $posted = isset($_POST['rates']) && is_array($_POST['rates']) ? wp_unslash($_POST['rates']) : [];
            if (empty($posted)) {
                wp_send_json_error(['message' => __('No rates submitted.', 'meals-db')]);
                return;
            }

            // 5. validation — defense in depth ahead of the accessor's own
            // validation, so we can return a precise per-field message and
            // never half-persist. Only known keys are considered.
            $seeds   = MealsDB_Rate_Definitions::seeds();
            $clean   = [];
            foreach ($posted as $key => $raw) {
                $key = is_string($key) ? $key : (string) $key;
                if (!array_key_exists($key, $seeds)) {
                    continue; // ignore unknown/stale fields silently
                }
                if (!is_scalar($raw) || !is_numeric($raw)) {
                    wp_send_json_error(['message' => sprintf(
                        /* translators: %s: rate field key */
                        __('Value for "%s" must be a number.', 'meals-db'),
                        $key
                    )]);
                    return;
                }
                $f = (float) $raw;
                if ($f < 0) {
                    wp_send_json_error(['message' => sprintf(
                        __('Value for "%s" must be non-negative.', 'meals-db'),
                        $key
                    )]);
                    return;
                }
                if ($f > MealsDB_Rate_Definitions::MAX_DOLLARS) {
                    wp_send_json_error(['message' => sprintf(
                        __('Value for "%s" is implausibly large — check it.', 'meals-db'),
                        $key
                    )]);
                    return;
                }
                $clean[$key] = round($f, 2);
            }

            if (empty($clean)) {
                wp_send_json_error(['message' => __('No recognised rates submitted.', 'meals-db')]);
                return;
            }

            // Snapshot the effective values BEFORE the write so the audit can
            // record old→new for only the fields that actually change.
            $before = MealsDB_Rate_Definitions::all();

            if (!MealsDB_Rate_Definitions::save($clean)) {
                wp_send_json_error(['message' => __('Could not save rates. Please try again.', 'meals-db')]);
                return;
            }

            // 6. audit — one row per ACTUAL change (old→new). Compare on the
            // rounded float to avoid logging a no-op as a change.
            $changed = 0;
            foreach ($clean as $key => $new) {
                $old = isset($before[$key]) ? (float) $before[$key] : null;
                if ($old === null || abs($old - $new) >= 0.005) {
                    MealsDB_Logger::log(
                        'rate_definition_edit',
                        0, // program-wide rate — no client target.
                        $key,
                        $old === null ? null : number_format($old, 2, '.', ''),
                        number_format($new, 2, '.', '')
                    );
                    $changed++;
                }
            }

            wp_send_json_success([
                'saved'   => true,
                'changed' => $changed,
                'rates'   => MealsDB_Rate_Definitions::all(),
            ]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Rate_Definitions AJAX] save failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save rates. Please contact an administrator.', 'meals-db')]);
        }
    }
}
