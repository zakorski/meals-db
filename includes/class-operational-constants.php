<?php
/**
 * Single source of truth for operational constants that define the
 * business logic of the Meals DB plugin.
 *
 * These values were previously scattered across multiple files (rates
 * in class-invoice-generator.php, fee/overage product IDs hardcoded
 * in slip generator defaults and views/settings.php, category IDs in
 * the slip generator and PO algorithm, etc.). Consolidating them here
 * lets a maintainer understand the operational vocabulary in one
 * place (STRUCT-4 in the v1.0.346 audit).
 *
 * IMPORTANT: Changing any value here changes plugin behavior. Before
 * modifying, confirm with the operator (Janet) that the change
 * reflects an actual operational change. The constants here are
 * intentionally NOT filterable via WP hooks and NOT overridable via
 * wp-config.php — they represent real-world contractual values, not
 * configuration. The few values that ARE operator-tunable (fee/
 * overage product IDs) remain configurable via wp_options; the
 * constants here are the install-time defaults that seed those
 * options.
 *
 * Author: Fishhorn Design
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Operational_Constants {

    // -------------------------------------------------------------
    // WC Product IDs — fee mechanism (operator-tunable via option
    // mealsdb_fee_product_ids; these are the install defaults).
    // -------------------------------------------------------------
    //
    // The legacy Enzebra system used these product IDs as line items
    // to represent fees. Quick Order now uses WC_Order_Item_Fee.
    // Both mechanisms coexist; see MealsDB_Order_Fees for the
    // unified read path. Source: confirmed via the production
    // WooCommerce store.

    /** Client Contribution fee product ID (legacy line-item mechanism). */
    const PRODUCT_ID_CLIENT_CONTRIBUTION = 5675;

    /** Delivery Fee product ID (legacy line-item mechanism). */
    const PRODUCT_ID_DELIVERY_FEE = 4122;

    // -------------------------------------------------------------
    // WC Product IDs — overage workaround (legacy; tunable via
    // option mealsdb_overage_product_ids).
    // -------------------------------------------------------------
    //
    // The legacy system used these products as line items to
    // represent "overage" (meals delivered beyond the SDNB
    // allowance). The new allocation engine tracks overage as
    // ledger entries instead; these IDs remain as fallbacks while
    // clients still have use_legacy_billing = 1. CLAUDE.md flags
    // this pattern as "do NOT extend" — these defaults exist only
    // to support the legacy path until cutover completes.

    /** Overage workaround for mains (legacy). */
    const PRODUCT_ID_OVERAGE_MAIN = 5056;

    /** Overage workaround for non-taxable Z sides (legacy). */
    const PRODUCT_ID_OVERAGE_SIDE_NONTAX = 5059;

    /** Overage workaround for taxable Z sides (legacy). */
    const PRODUCT_ID_OVERAGE_SIDE_TAX = 5180;

    // -------------------------------------------------------------
    // WC Category IDs
    // -------------------------------------------------------------
    //
    // These IDs match the production WC taxonomy. If categories are
    // ever rebuilt (e.g. import from staging), these IDs may shift
    // and need updating. Source: production WC product_cat taxonomy.

    const CATEGORY_ID_MAINS   = 35;
    const CATEGORY_ID_SOUP    = 43;
    const CATEGORY_ID_MUFFINS = 37;
    const CATEGORY_ID_CEREAL  = 23;
    const CATEGORY_ID_DESSERT = 25;

    // -------------------------------------------------------------
    // SDNB billing rates (CAD dollars)
    // -------------------------------------------------------------
    //
    // Source: SDNB contract; rates reviewed annually. Rural rates
    // apply to Sussex-zone ('S') clients per the SDNB contract addendum;
    // see SDNB_RURAL_ZONE_CODES / is_rural_zone().
    //
    // SEED DEFAULTS (directive DEFINITIONS-1): these six values are no longer
    // the LIVE source for the SDNB program rates. They now SEED
    // MealsDB_Rate_Definitions, which the operator edits on the Rate
    // Definitions admin page (option mealsdb_rate_definitions). The accessor
    // returns the stored option value if present, else the seed below — so
    // editing a constant here changes only the seed/fallback, NOT the live
    // billed rate. To change a live rate, edit it on the page (it's audited);
    // a constant edit here would do nothing once an override is stored.
    //
    // HST is unaffected — it is still sourced live from WooCommerce (LB-7);
    // Definitions introduces no HST constant or field.
    const SDNB_RATE_PRIMARY_MAIN         = 14.66;
    const SDNB_RATE_PRIMARY_MAIN_RURAL   = 15.47;
    const SDNB_RATE_SECONDARY_MAIN       = 10.18;
    const SDNB_RATE_SECONDARY_MAIN_RURAL = 10.93;
    const SDNB_RATE_SIDE                 = 4.48;
    const SDNB_RATE_SIDE_RURAL           = 4.54;

    /**
     * SDNB service-zone codes that bill at the RURAL rate.
     *
     * Rurality is a property of the client's delivery zone
     * (meals_clients.delivery_area_zone), NOT of the rate value. Zone
     * 'S' is the Sussex service centre (rural per the SDNB contract
     * addendum); 'M' is Moncton (urban). Deriving rurality from the
     * zone — not from the price — is what lets the rate VALUES change
     * at cutover without the code silently picking the wrong tier
     * (LB-7). Add a code here if a new rural service zone is onboarded.
     */
    const SDNB_RURAL_ZONE_CODES = ['S'];

    // -------------------------------------------------------------
    // VAC billing rates (CAD dollars)
    // -------------------------------------------------------------
    //
    // Source: Veterans Affairs Canada contract.
    //   VAC_PER_MAIN_ALLOWANCE — monthly $ allowance per allowed main.
    //   VAC_RATE_SIDE          — per-side cost rate.
    //   VAC_SIDES_CONVERSION_RATE — remaining allowance ÷ this gives
    //                               sides allowed for the month.
    //
    // SEED DEFAULTS (directive DEFINITIONS-1): VAC_PER_MAIN_ALLOWANCE (now the
    // VAC per-main COVERAGE) and VAC_RATE_SIDE seed MealsDB_Rate_Definitions
    // ('vac_per_main_coverage' / 'vac_side'). The coverage is the
    // annually-changing number — Janet edits it on the Rate Definitions page
    // (10.64 → 11.14 at cutover), not here. VAC_SIDES_CONVERSION_RATE and
    // VAC_SIDES_HST_RATE are NOT rates that move on a business cadence and are
    // NOT moved to Definitions. The actual VAC consumption of the accessor
    // values is wired in INV-DRAFT-3; this directive only exposes them.

    const VAC_PER_MAIN_ALLOWANCE     = 10.64;
    const VAC_RATE_SIDE              = 4.10;
    const VAC_SIDES_CONVERSION_RATE  = 4.715;
    const VAC_SIDES_HST_RATE         = 0.15;

    // -------------------------------------------------------------
    // HST
    // -------------------------------------------------------------
    //
    // Harmonized Sales Tax applies to taxable SIDES only — mains are
    // NEVER taxed, and prices are PRE-TAX, so HST is simply added:
    //   hst = taxable_side_price * hst_rate.
    //
    // This replaces the old baked-in net-portion multipliers
    // (0.672/0.82/0.681), which were derived from the OLD combined
    // prices and silently mis-billed once the prices changed (LB-7).
    //
    // The SDNB invoice HST RATE is sourced LIVE from WooCommerce
    // (MealsDB_Invoice_Generator::resolve_hst_rate) per the operator's
    // decision — there is intentionally no SDNB HST constant here, so a
    // rate change is made once in WC Settings → Tax. The VAC path keeps
    // its own VAC_SIDES_HST_RATE (above); if VAC should also source from
    // WC, that is a separate change.

    // -------------------------------------------------------------
    // Apetito supplier configuration
    // -------------------------------------------------------------
    //
    // Source: confirmed with Janet (May 2026).

    /** Standard Apetito pallet size in cases. */
    const APETITO_CASES_PER_PALLET = 75;

    // -------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------

    /**
     * Fee product IDs as the keyed array shape consumers expect.
     *
     * @return array{client_contribution: int, delivery_fee: int}
     */
    public static function default_fee_product_ids(): array {
        return [
            'client_contribution' => self::PRODUCT_ID_CLIENT_CONTRIBUTION,
            'delivery_fee'        => self::PRODUCT_ID_DELIVERY_FEE,
        ];
    }

    /**
     * Overage product IDs as the keyed array shape consumers expect.
     *
     * @return array{mains: int, taxable_sides: int, nontax_sides: int}
     */
    public static function default_overage_product_ids(): array {
        return [
            'mains'         => self::PRODUCT_ID_OVERAGE_MAIN,
            'taxable_sides' => self::PRODUCT_ID_OVERAGE_SIDE_TAX,
            'nontax_sides'  => self::PRODUCT_ID_OVERAGE_SIDE_NONTAX,
        ];
    }

    /**
     * Overage product IDs as actually CONFIGURED on this install: the
     * operator-set `mealsdb_overage_product_ids` option overlaid on the
     * seed defaults. Same keyed shape as default_overage_product_ids().
     *
     * Why this exists: default_overage_product_ids() is only the seed
     * constants. The Settings page (views/settings.php) lets the operator
     * re-point an overage SKU, and the save path
     * (includes/ajax/class-ajax-settings.php) persists those values to the
     * `mealsdb_overage_product_ids` option. On such installs the CONFIGURED
     * ID — not the seed — is what the OLD system injects into orders, so any
     * consumer that must recognise overage SKUs (e.g. the allocation
     * rebuilder's meal-count exclusion) has to honour the option or those
     * orders inflate the count. A per-key 0/missing falls back to the seed,
     * mirroring the settings UI's own `?? default` convention.
     *
     * Guarded for non-WP contexts (CLI/tests) where get_option is absent —
     * there we return the seeds, matching default_overage_product_ids().
     *
     * @return array{mains: int, taxable_sides: int, nontax_sides: int}
     */
    public static function overage_product_ids(): array {
        $defaults = self::default_overage_product_ids();
        if (!function_exists('get_option')) {
            return $defaults;
        }
        $saved = get_option('mealsdb_overage_product_ids', []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $resolved = [];
        foreach ($defaults as $key => $default) {
            $value = isset($saved[$key]) ? (int) $saved[$key] : 0;
            $resolved[$key] = $value > 0 ? $value : (int) $default;
        }
        return $resolved;
    }

    /**
     * Get the SDNB main rate for a tier and rurality.
     *
     * Reads through MealsDB_Rate_Definitions (directive DEFINITIONS-1) so the
     * operator-edited program rate wins, falling back to the seed constant.
     * The signature is unchanged — every generator call site (lines ~246/317/
     * 354) is untouched; this is facade discipline. The class_exists guard
     * keeps this safe if the accessor somehow hasn't loaded (it always does
     * via the autoloader); in that degenerate case it returns the seed.
     *
     * @param string $tier 'primary' or 'secondary'
     * @param bool   $rural Whether the client is in a rural zone.
     * @return float Rate in dollars.
     */
    public static function get_sdnb_main_rate(string $tier, bool $rural = false): float {
        $key = ($tier === 'primary')
            ? ($rural ? 'sdnb_primary_main_rural' : 'sdnb_primary_main')
            : ($rural ? 'sdnb_secondary_main_rural' : 'sdnb_secondary_main');

        if (class_exists('MealsDB_Rate_Definitions')) {
            $rate = MealsDB_Rate_Definitions::get($key);
            if ($rate !== null) {
                return (float) $rate;
            }
        }

        // Seed fallback (accessor unavailable / unknown key).
        if ($tier === 'primary') {
            return $rural ? self::SDNB_RATE_PRIMARY_MAIN_RURAL : self::SDNB_RATE_PRIMARY_MAIN;
        }
        return $rural ? self::SDNB_RATE_SECONDARY_MAIN_RURAL : self::SDNB_RATE_SECONDARY_MAIN;
    }

    /**
     * Get the SDNB side rate.
     *
     * Reads through MealsDB_Rate_Definitions (directive DEFINITIONS-1); see
     * get_sdnb_main_rate() for the facade/fallback rationale.
     *
     * @param bool $rural Whether the client is in a rural zone.
     * @return float Rate in dollars.
     */
    public static function get_sdnb_side_rate(bool $rural = false): float {
        $key = $rural ? 'sdnb_side_rural' : 'sdnb_side';

        if (class_exists('MealsDB_Rate_Definitions')) {
            $rate = MealsDB_Rate_Definitions::get($key);
            if ($rate !== null) {
                return (float) $rate;
            }
        }

        return $rural ? self::SDNB_RATE_SIDE_RURAL : self::SDNB_RATE_SIDE;
    }

    /**
     * Whether an SDNB service-zone code bills at the rural rate.
     *
     * Centralises the rural-zone test so callers derive rurality from
     * the client's zone, not from the rate value (LB-7). Comparison is
     * case-insensitive and trims surrounding whitespace so a stored
     * ' s ' still resolves correctly.
     *
     * @param string|null $zone delivery_area_zone code ('M', 'S', ...).
     * @return bool
     */
    public static function is_rural_zone(?string $zone): bool {
        if ($zone === null) {
            return false;
        }
        return in_array(strtoupper(trim($zone)), self::SDNB_RURAL_ZONE_CODES, true);
    }
}
