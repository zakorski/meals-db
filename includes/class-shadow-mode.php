<?php
/**
 * Shadow Mode flag.
 *
 * Shadow mode lets the new MealsDB plugin run alongside the legacy system
 * it replaces, on the SAME live database, so its computed outputs
 * (invoices, allocations, reports) can be compared against legacy WITHOUT
 * the new plugin affecting day-to-day operations or anything the legacy
 * system can see.
 *
 * When shadow mode is ON, the plugin suppresses exactly the side effects
 * that escape its own meals_* tables and would be visible to legacy:
 *
 *   1. Quick Order            — disabled entirely (creates orders/meta/usermeta).
 *   2. Order fee application   — suppressed (mutates WooCommerce orders).
 *   3. Sync write-back to WP   — suppressed (wp_update_user / update_user_meta).
 *
 * Everything else keeps running so the comparison has data: the allocation
 * hooks still observe legacy's live orders and populate meals_*, private
 * intake still records clients, and all invoice/report GENERATION runs
 * normally (it only reads + writes meals_*). The daily-report email and the
 * admin product-tax write are intentionally left live per operator decision.
 *
 * FAIL-SAFE: if the flag is missing, unset, or unreadable for any reason,
 * is_enabled() returns TRUE (shadow ON). The dangerous failure is a
 * misconfiguration silently letting writes through during the trial; the
 * safe failure is an inert plugin you notice and fix. So uncertainty
 * defaults to "suppress".
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Shadow_Mode {

    /** Key within the mealsdb_settings option array. */
    const SETTING_KEY = 'shadow_mode';

    /**
     * Whether shadow mode is active. Fail-safe: anything other than an
     * explicit, readable "off" is treated as ON.
     */
    public static function is_enabled(): bool {
        // An explicit constant override wins (useful for WP-CLI / wp-config
        // pinning during the trial). Define MEALSDB_SHADOW_MODE=false to
        // force live writes; anything else (or undefined) stays safe.
        if (defined('MEALSDB_SHADOW_MODE')) {
            return (bool) constant('MEALSDB_SHADOW_MODE');
        }

        $settings = get_option('mealsdb_settings', null);

        // Missing/!array option => cannot confirm "off" => stay safe (ON).
        if (!is_array($settings)) {
            return true;
        }

        // Key absent => never explicitly turned off => stay safe (ON).
        if (!array_key_exists(self::SETTING_KEY, $settings)) {
            return true;
        }

        // Only an explicit, strict false/0/'0'/'' turns shadow OFF.
        $value = $settings[self::SETTING_KEY];
        if ($value === false || $value === 0 || $value === '0' || $value === '') {
            return false;
        }
        // Any truthy or unexpected value => ON.
        return true;
    }

    /** Convenience inverse for readability at call sites. */
    public static function writes_allowed(): bool {
        return !self::is_enabled();
    }
}
