<?php
/**
 * Admin page: MealsDB → Apetito Item Puller.
 *
 * Provides two tabs:
 *   - Pull an item: fetch a 5-digit Apetito code from Nutridata, review parsed
 *     details, and create a Draft WooCommerce product.
 *   - Audit existing: compare existing Apetito-SKU products against current
 *     Nutridata pages (read-only, paced at one request/second).
 *
 * Capability: edit_product (or the baseline plugin capability as a fallback) —
 * product creation requires edit_product; the audit tab is equally gated since
 * it fetches external data on behalf of the user. The AJAX guard in
 * MealsDB_Ajax_Apetito gates identically; keep the two in agreement.
 *
 * XSS discipline: the page itself is almost entirely static markup. Dynamic
 * output (category list, i18n strings) reaches the page via wp_localize_script
 * as JSON — WP handles escaping there. Any server-rendered dynamic value here
 * is escaped at emission.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

if (!defined('ABSPATH')) { exit; }

class MealsDB_Apetito_Page {

    public const PAGE_SLUG = 'mealsdb-apetito-puller';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 22);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'mealsdb',
            __('Apetito Item Puller', 'meals-db'),
            __('Apetito Item Puller', 'meals-db'),
            current_user_can('edit_product') ? 'edit_product' : MealsDB_Permissions::required_capability(),
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue_scripts($hook): void {
        // Submenu hook suffix is "<parent>_page_<slug>". Only load on our page.
        if (!is_string($hook) || strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        wp_enqueue_script(
            'mealsdb-apetito-js',
            plugins_url('assets/js/apetito-puller.js', dirname(dirname(__FILE__))),
            // In-page dialog helper (confirm/prompt/alert) + selectWoo for the
            // category picker. register_confirm_script() returns the handle string
            // and registers the script as a side-effect.
            ['jquery', 'selectWoo', MealsDB_Admin_UI::register_confirm_script()],
            defined('MEALS_DB_VERSION') ? MEALS_DB_VERSION : false,
            true
        );

        // selectWoo SCRIPT is registered globally in admin, but the STYLE is only
        // registered on WooCommerce's own screens — register it ourselves from
        // WC's bundled asset if needed (mirrors class-order-audit-page.php).
        if (!wp_style_is('select2', 'registered') && function_exists('WC')) {
            wp_register_style(
                'select2',
                WC()->plugin_url() . '/assets/css/select2.css',
                [],
                defined('WC_VERSION') ? WC_VERSION : false
            );
        }
        if (wp_style_is('select2', 'registered')) {
            wp_enqueue_style('select2');
        }

        wp_enqueue_style('woocommerce_admin_styles');

        // NONCE_FETCH is reused for the audit tab: both fetch and audit call the
        // same fetch handler, so the same nonce context applies.
        wp_localize_script('mealsdb-apetito-js', 'mealsdbApetito', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonceFetch'   => wp_create_nonce(MealsDB_Ajax_Apetito::NONCE_FETCH),
            'nonceCreate'  => wp_create_nonce(MealsDB_Ajax_Apetito::NONCE_CREATE),
            'nonceAudit'   => wp_create_nonce(MealsDB_Ajax_Apetito::NONCE_FETCH),
            'categories'   => self::category_choices(),
            'i18n'         => [
                'couldNotRead' => __('could not read — enter manually', 'meals-db'),
                'confirmTitle' => __('Create Apetito product?', 'meals-db'),
                'confirmBody'  => __('This creates a Draft product. Review and publish it afterward.', 'meals-db'),
            ],
        ]);
    }

    /**
     * All WooCommerce product categories as [{id, name}] for the JS category
     * picker. Returns an empty array when get_terms() is not yet available (e.g.
     * during early hooks), so the JS falls back gracefully.
     *
     * @return array<int,array{id:int,name:string}>
     */
    private static function category_choices(): array {
        $out = [];
        if (!function_exists('get_terms')) { return $out; }
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_array($terms)) {
            foreach ($terms as $t) {
                if ($t instanceof WP_Term) { $out[] = ['id' => $t->term_id, 'name' => $t->name]; }
            }
        }
        return $out;
    }

    public static function render(): void {
        // Pattern 1, view layer: re-enforce the capability at the page level.
        // A caller that reaches an AJAX endpoint never came through here.
        if (!current_user_can('edit_product') && !current_user_can(MealsDB_Permissions::required_capability())) {
            wp_die(esc_html__('You are not allowed to access this page.', 'meals-db'));
        }

        // Path: includes/admin/ → includes/ → plugin root → views/
        // dirname(__DIR__) == plugin root; confirmed against the layout of every
        // other page class in this directory (e.g. class-order-audit-page.php).
        require dirname(dirname(__DIR__)) . '/views/apetito-puller.php';
    }
}
