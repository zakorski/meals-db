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
    // apply to Sussex zone clients per the SDNB contract addendum.
    // Note: the invoice generator's $sdnb_billing array is keyed by
    // string forms of the primary-main rate ('14.66' / '15.47') —
    // those string keys are an internal lookup convention, not
    // duplicates of these constants.

    const SDNB_RATE_PRIMARY_MAIN         = 14.66;
    const SDNB_RATE_PRIMARY_MAIN_RURAL   = 15.47;
    const SDNB_RATE_SECONDARY_MAIN       = 10.18;
    const SDNB_RATE_SECONDARY_MAIN_RURAL = 10.93;
    const SDNB_RATE_SIDE                 = 4.48;
    const SDNB_RATE_SIDE_RURAL           = 4.54;

    // -------------------------------------------------------------
    // VAC billing rates (CAD dollars)
    // -------------------------------------------------------------
    //
    // Source: Veterans Affairs Canada contract.
    //   VAC_PER_MAIN_ALLOWANCE — monthly $ allowance per allowed main.
    //   VAC_RATE_SIDE          — per-side cost rate.
    //   VAC_SIDES_CONVERSION_RATE — remaining allowance ÷ this gives
    //                               sides allowed for the month.

    const VAC_PER_MAIN_ALLOWANCE     = 10.64;
    const VAC_RATE_SIDE              = 4.10;
    const VAC_SIDES_CONVERSION_RATE  = 4.715;
    const VAC_SIDES_HST_RATE         = 0.15;

    // -------------------------------------------------------------
    // HST multipliers — net portion for invoice line items
    // -------------------------------------------------------------
    //
    // SDNB invoices show base rate plus HST as separate line items.
    // These multipliers represent the NET portion (rate before HST)
    // for each gross rate value:
    //   gross_rate * multiplier = net (pre-HST)
    //   gross_rate * (1 - multiplier) = HST portion
    //
    // Values are historical, derived from the HST/rate breakdown
    // the legacy invoice generator produces. If HST rate changes,
    // recalculate these.

    const HST_MULTIPLIER_PRIMARY_MAIN   = 0.672;
    const HST_MULTIPLIER_RURAL_MAIN     = 0.82;
    const HST_MULTIPLIER_SECONDARY_MAIN = 0.681;

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
     * Get the SDNB main rate for a tier and rurality.
     *
     * @param string $tier 'primary' or 'secondary'
     * @param bool   $rural Whether the client is in a rural zone.
     * @return float Rate in dollars.
     */
    public static function get_sdnb_main_rate(string $tier, bool $rural = false): float {
        if ($tier === 'primary') {
            return $rural ? self::SDNB_RATE_PRIMARY_MAIN_RURAL : self::SDNB_RATE_PRIMARY_MAIN;
        }
        return $rural ? self::SDNB_RATE_SECONDARY_MAIN_RURAL : self::SDNB_RATE_SECONDARY_MAIN;
    }

    /**
     * Get the SDNB side rate.
     *
     * @param bool $rural Whether the client is in a rural zone.
     * @return float Rate in dollars.
     */
    public static function get_sdnb_side_rate(bool $rural = false): float {
        return $rural ? self::SDNB_RATE_SIDE_RURAL : self::SDNB_RATE_SIDE;
    }
}
