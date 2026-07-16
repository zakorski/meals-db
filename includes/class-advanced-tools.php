<?php
/**
 * Advanced-tools menu visibility toggle (admin UI consolidation spec
 * 2026-07-16, PR 1).
 *
 * Rarely-used / destructive admin pages (Rate Definitions, Data Ops,
 * Migration) are hidden from the Meals DB menu unless the operator has
 * ticked "Show advanced tools" in Settings. Governed pages pass
 * menu_parent() as the parent slug of their add_submenu_page() call:
 * 'mealsdb' when the toggle is on (normal menu entry), '' when off —
 * WordPress's standard hidden-page pattern, which registers the page hook
 * WITHOUT a menu entry so direct URLs and bookmarks keep working.
 *
 * remove_submenu_page() after registration was rejected for this: for
 * plugin pages it makes user_can_access_admin_page() resolve a hookname
 * ('admin_page_{slug}') that was never registered, 403-ing the page even
 * for admins.
 *
 * Cron Status and Event Log are deliberately NOT governed — they are how
 * the team notices breakage (design decision, spec 2026-07-16).
 *
 * Hiding is a CONVENIENCE, not a security layer: every governed page keeps
 * its own capability gate, which is enforced on render regardless of menu
 * visibility.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Advanced_Tools {

    /** Key within the mealsdb_settings option array. */
    const SETTING_KEY = 'show_advanced_tools';

    /**
     * Whether advanced tools are shown in the menu. Opposite fail-safe to
     * shadow mode: only an explicit, readable "on" ('1'/1/true) shows the
     * tools; a missing option, non-array option, absent key, or any other
     * value keeps them HIDDEN.
     */
    public static function is_enabled(): bool {
        $settings = get_option('mealsdb_settings', null);
        if (!is_array($settings) || !array_key_exists(self::SETTING_KEY, $settings)) {
            return false;
        }
        $value = $settings[self::SETTING_KEY];
        return $value === '1' || $value === 1 || $value === true;
    }

    /**
     * Parent slug for governed pages' add_submenu_page() registration:
     * 'mealsdb' (visible menu entry) when the toggle is on, '' (registered
     * but menu-less — the hidden-page pattern) when off.
     *
     * The page hook suffix differs by state ('meals-db_page_{slug}' when
     * visible, 'admin_page_{slug}' when hidden), so enqueue checks on
     * governed pages must accept BOTH suffixes.
     */
    public static function menu_parent(): string {
        return self::is_enabled() ? 'mealsdb' : '';
    }
}
