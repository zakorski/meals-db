<?php
/**
 * Operator-editable program-wide billing rate definitions (directive
 * DEFINITIONS-1).
 *
 * This is the single accessor every consumer of a PROGRAM-WIDE rate calls.
 * It supersedes the LB-7 "constants are the single source of truth" decision
 * FOR PROGRAM-WIDE RATES ONLY — the per-client rate table
 * (meals_client_rates) and every non-rate constant (product/category IDs,
 * zone codes, pallet size, the WC-sourced HST rate) are deliberately NOT
 * touched here. See the directive's BOUNDARY section.
 *
 * Storage (v1): a single non-autoloaded wp_options row,
 *   mealsdb_rate_definitions => ['schema' => 1, 'rates' => [key => value, ...]].
 * The constants on MealsDB_Operational_Constants remain as the SEED defaults
 * this accessor reads when a key is absent from the option — so the page
 * ships pre-populated with today's numbers and there is exactly ONE live
 * source (the option), with the constants as documented fallback, NOT a
 * constants-AND-option dual-maintenance trap.
 *
 * Effective-dating is intentionally NOT built (operator: not needed yet —
 * STR-5). This accessor is the seam that makes adding it later a one-file
 * change: the option becomes "current effective set" and a dated table holds
 * history, with get()/all()/save() unchanged for callers.
 *
 * Rate values are dollars (not cents) — they seed from the dollar-valued
 * constants and feed methods (get_sdnb_*_rate) that already return dollars.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Rate_Definitions {

    /** Non-autoloaded option holding the versioned rate set. */
    public const OPTION = 'mealsdb_rate_definitions';

    /** Stored-payload schema version (bump only on a shape change). */
    public const SCHEMA = 1;

    /**
     * Per-rate ceiling for save-time validation. A program rate is a single
     * meal/side dollar price — none plausibly exceeds this — so the ceiling
     * catches a fat-fingered 1114 (where 11.14 was meant) before it reaches a
     * government invoice, while comfortably clearing every legitimate value.
     */
    public const MAX_DOLLARS = 1000.00;

    /**
     * Canonical key list + their seed (constant) defaults. The ONLY place the
     * program-wide rate vocabulary is defined.
     *
     * The SDNB/VAC seeds are the existing constants (today's live numbers).
     * The private/veteran prices are BORN here — they were never constants;
     * veteran prices equal private prices per the operator, so they are
     * modelled as the same values, not a separate set.
     *
     * @return array<string,float>
     */
    private static function defaults(): array {
        return [
            'sdnb_primary_main'         => (float) MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN,
            'sdnb_primary_main_rural'   => (float) MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN_RURAL,
            'sdnb_secondary_main'       => (float) MealsDB_Operational_Constants::SDNB_RATE_SECONDARY_MAIN,
            'sdnb_secondary_main_rural' => (float) MealsDB_Operational_Constants::SDNB_RATE_SECONDARY_MAIN_RURAL,
            'sdnb_side'                 => (float) MealsDB_Operational_Constants::SDNB_RATE_SIDE,
            'sdnb_side_rural'           => (float) MealsDB_Operational_Constants::SDNB_RATE_SIDE_RURAL,
            // VAC per-main COVERAGE (the annually-changing number; seeds at
            // today's constant 10.64, edited to 11.14 on the page at cutover —
            // that edit is the first audited rate_definition_edit).
            'vac_per_main_coverage'     => (float) MealsDB_Operational_Constants::VAC_PER_MAIN_ALLOWANCE,
            'vac_side'                  => (float) MealsDB_Operational_Constants::VAC_RATE_SIDE,
            // Janet's private/veteran prices — NEW, born here (not constants).
            'private_main'              => 9.50,
            'private_side'              => 4.25,
            'private_combo'             => 13.75,
        ];
    }

    /**
     * Read one program rate. The stored option value wins; otherwise the
     * constant seed. Never throws. An unknown key returns null (a caller bug —
     * do NOT invent a value).
     *
     * @param string $key One of the keys in defaults().
     * @return float|null
     */
    public static function get(string $key): ?float {
        $defaults = self::defaults();
        if (!array_key_exists($key, $defaults)) {
            return null;
        }
        $rates = self::stored_rates();
        return (array_key_exists($key, $rates) && is_numeric($rates[$key]))
            ? (float) $rates[$key]
            : (float) $defaults[$key];
    }

    /**
     * All current effective rates (seed defaults overlaid with stored
     * overrides), keyed by the canonical key. For the edit form and any
     * caller that needs the whole set.
     *
     * @return array<string,float>
     */
    public static function all(): array {
        $defaults = self::defaults();
        $rates    = self::stored_rates();
        $out = [];
        foreach ($defaults as $key => $seed) {
            $out[$key] = (array_key_exists($key, $rates) && is_numeric($rates[$key]))
                ? (float) $rates[$key]
                : (float) $seed;
        }
        return $out;
    }

    /**
     * The seed (constant) defaults, exposed read-only so the admin page can
     * render a "default:" hint where the current value differs from the seed.
     *
     * @return array<string,float>
     */
    public static function seeds(): array {
        return self::defaults();
    }

    /**
     * Persist edited rates. Called ONLY by the audited admin endpoint.
     *
     * Validates every value (numeric, non-negative, within MAX_DOLLARS) and
     * SILENTLY DROPS unknown keys (a stale form field can't inject a phantom
     * rate). Returns false — writing nothing — if any KNOWN key's value is
     * invalid, so a bad submission never half-persists. The cleaned set fully
     * replaces the option's `rates` map; keys absent from the submission fall
     * back to their seed via get()/all().
     *
     * @param array<string,mixed> $rates key => value.
     * @return bool True if the option now reflects the cleaned set.
     */
    public static function save(array $rates): bool {
        $defaults = self::defaults();
        $clean    = [];

        foreach ($rates as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                continue; // unknown key — drop it, don't fail the whole save.
            }
            if (!is_numeric($value)) {
                return false;
            }
            $f = (float) $value;
            if ($f < 0 || $f > self::MAX_DOLLARS) {
                return false;
            }
            $clean[$key] = round($f, 2);
        }

        $payload = ['schema' => self::SCHEMA, 'rates' => $clean];

        // Guarded for non-WP contexts (WP-CLI / test fixtures): without the
        // options API there is nowhere to persist, so report failure rather
        // than fatal.
        if (!function_exists('update_option') || !function_exists('get_option')) {
            return false;
        }

        // autoload='no': rates are read on the invoice/billing path, not on
        // every page load. update_option() returns false on an unchanged
        // value, so confirm by read-back rather than trusting its bool.
        update_option(self::OPTION, $payload, false);
        $saved = get_option(self::OPTION, []);
        return is_array($saved)
            && isset($saved['rates']) && is_array($saved['rates'])
            && $saved['rates'] == $clean;
    }

    /**
     * The stored `rates` map (possibly empty), defensively unwrapped.
     *
     * @return array<string,mixed>
     */
    private static function stored_rates(): array {
        // Guarded for non-WP contexts (WP-CLI / test fixtures that exercise the
        // rate methods without the options API): with no option store, every
        // key falls back to its seed constant — never fatal.
        if (!function_exists('get_option')) {
            return [];
        }
        $stored = get_option(self::OPTION, []);
        if (is_array($stored) && isset($stored['rates']) && is_array($stored['rates'])) {
            return $stored['rates'];
        }
        return [];
    }
}
