<?php
/**
 * Admin page: MealsDB → Rate Definitions (directive DEFINITIONS-1).
 *
 * The screen the operator uses to edit the PROGRAM-WIDE billing rates that
 * were hardcoded constants — so an annual rate change (notably the VAC
 * per-main coverage, which bumps yearly) is a form edit, not a code deploy.
 *
 * Scope boundary (non-negotiable, see the directive): this page edits ONLY
 * program-wide rates routed through MealsDB_Rate_Definitions. It does NOT
 * touch the per-client rate table (meals_client_rates), WooCommerce product/
 * category IDs, zone codes, or the HST rate (HST stays WC-sourced per LB-7 —
 * there is intentionally no HST field here).
 *
 * Capability: manage_options — these values bill two governments, so the
 * audience is kept as tight as the Event Log / Invoice Draft pages.
 *
 * XSS discipline: server-rendered, every value esc_attr'd. The interactive
 * save JS lives in an enqueued assets/js file (no inline <script> > 20 lines).
 *
 * Draft-immutability note (surfaced in the UI): changing a rate here does NOT
 * retro-alter an existing invoice draft — a draft captured its resolved rates
 * at generation time (INV-DRAFT-1), so only a NEWLY generated draft picks up
 * the new rate. Correct and desirable for government billing.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Rate_Definitions_Page {

    public const PAGE_SLUG = 'mealsdb_rate_definitions';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 23);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
    }

    public static function register_menu(): void {
        // Parent is toggle-dependent (advanced-tools visibility): 'mealsdb'
        // when shown, '' (registered but menu-less) when hidden.
        $parent = class_exists('MealsDB_Advanced_Tools')
            ? MealsDB_Advanced_Tools::menu_parent()
            : 'mealsdb';

        add_submenu_page(
            $parent,
            __('Rate Definitions', 'meals-db'),
            __('Rate Definitions', 'meals-db'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue_scripts($hook): void {
        if (!is_string($hook) || strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        wp_enqueue_script(
            'mealsdb-rate-definitions-js',
            plugins_url('assets/js/rate-definitions.js', dirname(dirname(__FILE__))),
            ['jquery'],
            defined('MEALS_DB_VERSION') ? MEALS_DB_VERSION : false,
            true
        );

        wp_add_inline_script(
            'mealsdb-rate-definitions-js',
            'window.mealsdbRateDefinitions = ' . wp_json_encode([
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(MealsDB_Ajax_Rate_Definitions::NONCE_ACTION),
                'i18n'    => [
                    'saving'     => __('Saving…', 'meals-db'),
                    'saved'      => __('Rates saved.', 'meals-db'),
                    'confirm'    => __('You must confirm the change before saving.', 'meals-db'),
                    'genericErr' => __('Something went wrong. Please try again.', 'meals-db'),
                ],
            ]) . ';',
            'before'
        );
    }

    /**
     * Field groups → key → human label. The ONLY presentation copy; the rate
     * vocabulary itself lives in MealsDB_Rate_Definitions.
     *
     * @return array<string,array<string,string>>
     */
    private static function groups(): array {
        return [
            'SDNB rates' => [
                'sdnb_primary_main'         => __('Primary main (urban)', 'meals-db'),
                'sdnb_primary_main_rural'   => __('Primary main (rural)', 'meals-db'),
                'sdnb_secondary_main'       => __('Secondary main (urban)', 'meals-db'),
                'sdnb_secondary_main_rural' => __('Secondary main (rural)', 'meals-db'),
                'sdnb_side'                 => __('Side (urban)', 'meals-db'),
                'sdnb_side_rural'           => __('Side (rural)', 'meals-db'),
            ],
            'VAC' => [
                'vac_per_main_coverage' => __('Per-main coverage', 'meals-db'),
                'vac_side'              => __('Side rate', 'meals-db'),
            ],
            'Private prices' => [
                'private_main'  => __('Main', 'meals-db'),
                'private_side'  => __('Side', 'meals-db'),
                'private_combo' => __('Main + side combo', 'meals-db'),
            ],
            'Veteran prices' => [
                'veteran_main'  => __('Main', 'meals-db'),
                'veteran_side'  => __('Side', 'meals-db'),
                'veteran_combo' => __('Main + side combo', 'meals-db'),
            ],
        ];
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'meals-db'));
        }

        $current = MealsDB_Rate_Definitions::all();
        $seeds   = MealsDB_Rate_Definitions::seeds();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Meals DB — Rate Definitions', 'meals-db') . '</h1>';

        echo '<p class="description" style="max-width:48em;">'
            . esc_html__('These are the program-wide billing rates. They are the default used wherever there is no per-client contracted rate; a client with a contracted rate (meals_client_rates) is unaffected.', 'meals-db')
            . '</p>';
        echo '<p class="description" style="max-width:48em;">'
            . esc_html__('Changes apply to newly generated invoice drafts only — existing drafts keep the rates they were generated with. HST is not set here; it is sourced live from WooCommerce → Settings → Tax.', 'meals-db')
            . '</p>';

        echo '<form id="mealsdb-rate-definitions-form">';

        foreach (self::groups() as $group_label => $fields) {
            echo '<h2>' . esc_html($group_label) . '</h2>';
            // U21-rates-money-1: only the Main rate in these two groups is read by
            // billing (resolve_program_rate). The Side and Main + side combo
            // values are informational — private/Veteran product pricing lives in
            // WooCommerce. Say so rather than presenting dead billing controls.
            if ($group_label === 'Private prices' || $group_label === 'Veteran prices') {
                echo '<p class="description" style="max-width:48em;">'
                    . esc_html__('Only the Main rate is used by billing. The Side and Main + side combo values here are informational — actual private/Veteran pricing is set in WooCommerce product pricing.', 'meals-db')
                    . '</p>';
            }
            echo '<table class="form-table" role="presentation"><tbody>';
            foreach ($fields as $key => $label) {
                $value = isset($current[$key]) ? (float) $current[$key] : 0.0;
                $seed  = isset($seeds[$key]) ? (float) $seeds[$key] : null;

                echo '<tr>';
                echo '<th scope="row"><label for="rate_' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
                echo '<td>';
                echo '<input type="number" step="0.01" min="0" '
                    . 'id="rate_' . esc_attr($key) . '" '
                    . 'class="mealsdb-rate-input small-text" '
                    . 'data-key="' . esc_attr($key) . '" '
                    . 'value="' . esc_attr(number_format($value, 2, '.', '')) . '" /> ';
                // "default:" hint only when the live value differs from the seed,
                // mirroring the draft grid's "was:" affordance.
                if ($seed !== null && abs($seed - $value) >= 0.005) {
                    echo '<span class="description" style="color:#777;">'
                        . esc_html__('default:', 'meals-db') . ' '
                        . esc_html(number_format($seed, 2, '.', '')) . '</span>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Confirmation friction — these values bill two governments.
        echo '<p style="margin-top:16px;">';
        echo '<label><input type="checkbox" id="mealsdb-rate-confirm" /> '
            . esc_html__('I understand these rates take effect immediately for newly generated invoices.', 'meals-db')
            . '</label>';
        echo '</p>';

        echo '<p>';
        echo '<button type="button" class="button button-primary" id="mealsdb-rate-save-btn" disabled>'
            . esc_html__('Save rates', 'meals-db') . '</button>';
        echo ' <span id="mealsdb-rate-save-msg" style="margin-left:8px;"></span>';
        echo '</p>';

        echo '</form>';
        echo '</div>';
    }
}
