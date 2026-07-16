<?php
/**
 * Advanced-tools menu visibility toggle (admin UI consolidation spec
 * 2026-07-16, PR 1).
 *
 * Rarely-used / destructive admin pages (Rate Definitions, Data Ops,
 * Migration) are hidden from the Meals DB menu unless the operator has
 * ticked "Show advanced tools" in Settings. Hiding is a CONVENIENCE, not
 * a security layer: the pages stay registered (direct URLs and bookmarks
 * keep working) and every governed page keeps its own capability gate,
 * which is enforced on render regardless of menu visibility.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Advanced_Tools {

    /** Key within the mealsdb_settings option array. */
    const SETTING_KEY = 'show_advanced_tools';

    /**
     * Submenu slugs governed by the toggle, in menu order. Cron Status and
     * Event Log are deliberately NOT governed — they are how the team
     * notices breakage (design decision, spec 2026-07-16).
     */
    const GOVERNED_SLUGS = [
        'mealsdb_rate_definitions',
        'mealsdb-data-ops',
        'mealsdb-migration',
    ];

    public static function init(): void {
        // Priority 99: the governed pages register their menu entries at
        // admin_menu 10 (Data Ops via MealsDB_Admin_UI), 22 (Migration)
        // and 23 (Rate Definitions); removal must run after all of them.
        add_action('admin_menu', [self::class, 'maybe_hide_governed_menu_items'], 99);
    }

    /**
     * Whether advanced tools are shown in the menu. Opposite fail-safe to
     * shadow mode: anything other than an explicit, readable "on" keeps
     * the tools HIDDEN (missing option, non-array option, absent key,
     * '0'/''/0/false all mean hidden).
     */
    public static function is_enabled(): bool {
        $settings = get_option('mealsdb_settings', null);
        if (!is_array($settings)) {
            return false;
        }
        // empty() treats '0', '', 0, false and an absent key all as "off".
        return !empty($settings[self::SETTING_KEY]);
    }

    /**
     * Remove the governed submenu entries when the toggle is off.
     *
     * remove_submenu_page() only removes the MENU ENTRY — the page stays
     * registered and reachable at admin.php?page={slug} with its original
     * hook suffix, so asset enqueues keyed on the hook and the pages' own
     * capability checks are untouched.
     */
    public static function maybe_hide_governed_menu_items(): void {
        if (self::is_enabled()) {
            return;
        }
        if (!function_exists('remove_submenu_page')) {
            return;
        }
        foreach (self::GOVERNED_SLUGS as $slug) {
            remove_submenu_page('mealsdb', $slug);
        }
    }
}
