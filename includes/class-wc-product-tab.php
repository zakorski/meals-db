<?php
/**
 * WooCommerce product data tab integration for Meals DB metadata.
 */

defined('ABSPATH') || exit;

class MealsDB_WC_Product_Tab {
    private const TAB_ID = 'mealsdb_product_data';

    /**
     * Available dietary tags presented to editors.
     *
     * @var array<string, string>
     */
    private $dietary_tags = [
        'gluten_free'  => 'Gluten Free',
        'dairy_free'   => 'Dairy Free',
        'low_sodium'   => 'Low Sodium',
        'keto'         => 'Keto Friendly',
        'paleo'        => 'Paleo',
        'vegetarian'   => 'Vegetarian',
        'vegan'        => 'Vegan',
    ];

    /**
     * Available allergen flags for Meals DB products.
     *
     * @var array<string, string>
     */
    private $allergen_flags = [
        'milk'       => 'Milk',
        'eggs'       => 'Eggs',
        'fish'       => 'Fish',
        'shellfish'  => 'Shellfish',
        'tree_nuts'  => 'Tree Nuts',
        'peanuts'    => 'Peanuts',
        'wheat'      => 'Wheat',
        'soy'        => 'Soy',
    ];

    /**
     * Register hooks for WooCommerce product edit integration.
     */
    public static function init(): void {
        $instance = new self();

        add_filter('woocommerce_product_data_tabs', [$instance, 'add_product_tab']);
        add_action('woocommerce_product_data_panels', [$instance, 'render_product_panel']);
        add_action('woocommerce_admin_process_product_object', [$instance, 'save_product_data']);
        add_action('admin_enqueue_scripts', [$instance, 'enqueue_admin_assets']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_product_tab_assets']);
    }

    /**
     * Add the Meals DB tab to the product data tabs list.
     *
     * @param array<string, array> $tabs
     *
     * @return array<string, array>
     */
    public function add_product_tab(array $tabs): array {
        $tabs[self::TAB_ID] = [
            'label'  => __('Meals DB Data', 'meals-db'),
            'target' => self::TAB_ID . '_panel',
            'class'  => [],
        ];

        return $tabs;
    }

    /**
     * Enqueue admin assets needed for the tab interactions.
     */
    public function enqueue_admin_assets(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }

        $style_path    = MEALS_DB_PLUGIN_DIR . 'assets/css/product-tab.css';
        $style_version = file_exists($style_path) ? filemtime($style_path) : MEALS_DB_VERSION;

        wp_enqueue_style(
            'mealsdb-wc-product-tab',
            MEALS_DB_PLUGIN_URL . 'assets/css/product-tab.css',
            ['woocommerce_admin_styles'],
            $style_version
        );

        // Inline JS for syncing WC tax fields with the Meals DB
        // product-type / taxable controls was previously appended here via
        // wp_add_inline_script. It now lives in
        // assets/js/wc-product-tab-tax-sync.js and is registered by
        // self::enqueue_product_tab_assets() with a dependency on
        // wc-admin-product-meta-boxes so the execution order — and thus the
        // resulting DOM state — matches the previous inline behavior.
    }

    /**
     * Register and enqueue the tax-field sync script on the WC product
     * edit screen. Gated to product post.php / post-new.php so the
     * script is never loaded on unrelated admin pages.
     *
     * The script depends on wc-admin-product-meta-boxes to preserve the
     * load order that the previous wp_add_inline_script attachment had.
     */
    public static function enqueue_product_tab_assets(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }

        $script_path    = MEALS_DB_PLUGIN_DIR . 'assets/js/wc-product-tab-tax-sync.js';
        $script_version = file_exists($script_path) ? filemtime($script_path) : MEALS_DB_VERSION;

        wp_enqueue_script(
            'mealsdb-wc-product-tab-tax-sync',
            MEALS_DB_PLUGIN_URL . 'assets/js/wc-product-tab-tax-sync.js',
            ['jquery', 'wc-admin-product-meta-boxes'],
            $script_version,
            true
        );
    }

    /**
     * Render the Meals DB product data panel.
     */
    public function render_product_panel(): void {
        global $post;

        if (!$post instanceof WP_Post || $post->post_type !== 'product') {
            return;
        }

        $product_id = (int) $post->ID;
        $data       = MealsDB_Products::get_product_data($product_id);

        echo '<div id="' . esc_attr(self::TAB_ID . '_panel') . '" class="panel woocommerce_options_panel hidden">';

        // All four persisted types are offered. Previously only meal/side were
        // listed, so opening + saving a 'fee'/'other' product submitted the
        // first option ('meal') and silently downgraded it (audit-2026-08 B10).
        woocommerce_wp_select([
            'id'      => '_mealsdb_product_type',
            'label'   => __('Product Type', 'meals-db'),
            'value'   => $data['product_type'],
            'options' => [
                'meal'  => __('Meal', 'meals-db'),
                'side'  => __('Side', 'meals-db'),
                'fee'   => __('Fee', 'meals-db'),
                'other' => __('Other', 'meals-db'),
            ],
        ]);

        $this->render_checkbox_field([
            'id'          => '_mealsdb_taxable',
            'label'       => __('Taxable', 'meals-db'),
            'value'       => (int) $data['taxable'],
            'description' => __('Defaults to the category (dessert/muffin are taxable). Change it to override this product; meals are never taxed.', 'meals-db'),
            'disabled'    => $data['product_type'] === 'meal',
        ]);

        $this->render_multi_checkbox_field(
            '_mealsdb_dietary_tags',
            __('Dietary Tags', 'meals-db'),
            $this->dietary_tags,
            $data['dietary_tags']
        );

        $this->render_multi_checkbox_field(
            '_mealsdb_allergen_flags',
            __('Allergen Flags', 'meals-db'),
            $this->allergen_flags,
            $data['allergen_flags'],
            'mealsdb-checkbox-grid'
        );

        woocommerce_wp_text_input([
            'id'                => '_mealsdb_case_size',
            'label'             => __('Case Size', 'meals-db'),
            'type'              => 'number',
            'custom_attributes' => [
                'min'  => '1',
                'step' => '1',
            ],
            'value'             => (int) $data['case_size'],
        ]);

        woocommerce_wp_text_input([
            'id'                => '_mealsdb_unit_cost',
            'label'             => __('Unit Cost', 'meals-db'),
            'type'              => 'number',
            'custom_attributes' => [
                'min'  => '0',
                'step' => '0.01',
            ],
            'value'             => $data['unit_cost'],
        ]);

        echo '</div>';
    }

    /**
     * Persist product metadata to the Meals DB.
     */
    public function save_product_data($product): void {
        if (!$product instanceof WC_Product) {
            return;
        }

        // Defence-in-depth: this hook is normally fired after WooCommerce
        // has run its own nonce + capability check, but a custom plugin /
        // REST endpoint that constructs a WC_Product and triggers the hook
        // outside admin would otherwise reach this writer with no auth.
        if (!current_user_can('edit_product', $product->get_id())) {
            return;
        }

        $product_id   = $product->get_id();
        $product_type = isset($_POST['_mealsdb_product_type'])
            ? sanitize_text_field(wp_unslash($_POST['_mealsdb_product_type']))
            : 'meal';

        // Honour the operator's "Taxable" checkbox, but only record it as an
        // OVERRIDE when it diverges from the category default (dessert/muffin
        // are taxable). Matching the default leaves the row category-tracked so
        // the display sync keeps re-deriving it; a genuine override is flagged
        // so the sync preserves it (audit-2026-08 B10). Meals are never taxed.
        $derived_taxable = $product_type === 'meal' ? 0 : self::determine_side_taxable($product_id);
        $posted_taxable  = !empty($_POST['_mealsdb_taxable']);
        $resolved        = MealsDB_Products::resolve_taxable_override($product_type, $posted_taxable, $derived_taxable);
        $taxable            = $resolved['taxable'];
        $taxable_overridden = $resolved['overridden'];

        $existing_data  = MealsDB_Products::get_product_data($product_id);
        $main_ingredient = is_array($existing_data) && isset($existing_data['main_ingredient'])
            ? $existing_data['main_ingredient']
            : '';

        $dietary_tags = isset($_POST['_mealsdb_dietary_tags']) && is_array($_POST['_mealsdb_dietary_tags'])
            ? array_values(array_map('sanitize_text_field', wp_unslash($_POST['_mealsdb_dietary_tags'])))
            : [];

        $allergen_flags = isset($_POST['_mealsdb_allergen_flags']) && is_array($_POST['_mealsdb_allergen_flags'])
            ? array_values(array_map('sanitize_text_field', wp_unslash($_POST['_mealsdb_allergen_flags'])))
            : [];

        $case_size = isset($_POST['_mealsdb_case_size'])
            ? absint($_POST['_mealsdb_case_size'])
            : 1;

        $unit_cost_raw = isset($_POST['_mealsdb_unit_cost'])
            ? (string) wc_format_decimal(wp_unslash($_POST['_mealsdb_unit_cost']))
            : '0.00';
        // Bound to a sane interval. Negative or astronomical unit costs
        // (the field accepts arbitrary admin input) propagate into invoice
        // and reorder calculations and corrupt them silently.
        $unit_cost_float = (float) $unit_cost_raw;
        if ($unit_cost_float < 0) {
            $unit_cost_float = 0.0;
        } elseif ($unit_cost_float > 10000) {
            $unit_cost_float = 10000.0;
        }
        $unit_cost = number_format($unit_cost_float, 2, '.', '');

        if ($product_type === 'meal') {
            $product->set_tax_status('none');
            $product->set_tax_class('');
        } else {
            $product->set_tax_status($taxable ? 'taxable' : 'none');

            if ($taxable === 0) {
                $product->set_tax_class('');
            }
        }

        $saved = MealsDB_Products::save_product_data($product_id, [
            'product_type'       => $product_type,
            'taxable'            => $taxable,
            'taxable_overridden' => $taxable_overridden,
            'main_ingredient'    => $main_ingredient,
            'dietary_tags'       => $dietary_tags,
            'allergen_flags'     => $allergen_flags,
            'case_size'          => $case_size,
            'unit_cost'          => $unit_cost,
        ]);

        // The WC tax status was already mutated above; if our own row write
        // failed the two would diverge silently on the HST-driving flag. Surface
        // it rather than pretend the save happened (audit-2026-08 B10, Pattern 7).
        if (!$saved) {
            MealsDB_Logger::error(sprintf('[MealsDB Product Tab] meals_products save failed for product_id=%d', $product_id));
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'    => 'error',
                    'category'    => 'products',
                    'subsystem'   => 'wc_product_tab',
                    'event'       => 'product_save.failed',
                    'outcome'     => 'degraded',
                    'message'     => 'meals_products write failed; WC tax status may diverge from the stored taxable flag.',
                    'context'     => ['product_id' => $product_id],
                ]);
            }
        }
    }

    /**
     * Determine whether a side product is taxable based on its WooCommerce categories.
     *
     * Desserts and muffins are taxable; cereal and soup are non-taxable.
     *
     * @param int $product_id WooCommerce product ID.
     *
     * @return int 1 if taxable, 0 otherwise.
     */
    private static function determine_side_taxable(int $product_id): int {
        // Single source of truth — the taxable-side rule lives in
        // MealsDB_Operational_Constants, not hardcoded here (audit-2026-08 B10).
        $taxable_slugs = MealsDB_Operational_Constants::taxable_side_category_slugs();

        $terms = get_the_terms($product_id, 'product_cat');
        if (!is_array($terms)) {
            return 0;
        }

        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }
            if (in_array($term->slug, $taxable_slugs, true)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Render a simple checkbox field consistent with WooCommerce meta box styling.
     *
     * @param array<string, mixed> $args
     */
    private function render_checkbox_field(array $args): void {
        $defaults = [
            'id'          => '',
            'label'       => '',
            'value'       => 0,
            'description' => '',
            'disabled'    => false,
        ];

        $args = array_merge($defaults, $args);

        woocommerce_wp_checkbox([
            'id'                => $args['id'],
            'label'             => $args['label'],
            'value'             => $args['value'],
            'cbvalue'           => '1',
            'description'       => $args['description'],
            'custom_attributes' => $args['disabled'] ? ['disabled' => 'disabled'] : [],
        ]);
    }

    /**
     * Render a group of checkbox fields representing an array value.
     *
     * @param string               $id
     * @param string               $label
     * @param array<string,string> $options
     * @param array                $selected
     */
    private function render_multi_checkbox_field(string $id, string $label, array $options, array $selected, string $wrap_class = ''): void {
        echo '<div class="options_group">';
        echo '<p class="form-field ' . esc_attr($id) . '_field">';

        $first_field_for = '';
        if ($options !== []) {
            $first_key       = array_key_first($options);
            $first_field_for = $id . '_' . $first_key;
        }

        echo '<label' . ($first_field_for ? ' for="' . esc_attr($first_field_for) . '"' : '') . '>' . esc_html($label) . '</label>';

        $classes = ['woocommerce-input-wrapper', 'mealsdb-checkbox-wrapper'];
        if ($wrap_class !== '') {
            $classes[] = $wrap_class;
        }

        echo '<div class="' . esc_attr(implode(' ', $classes)) . '">';

        foreach ($options as $key => $option_label) {
            $field_id   = $id . '_' . $key;
            $is_checked = in_array($key, $selected, true);
            echo '<label class="mealsdb-multi-checkbox" for="' . esc_attr($field_id) . '">';
            echo '<input type="checkbox" name="' . esc_attr($id) . '[]" id="' . esc_attr($field_id) . '" value="' . esc_attr($key) . '" ' . checked(true, $is_checked, false) . ' />';
            echo '<span class="mealsdb-multi-checkbox__label">' . esc_html($option_label) . '</span>';
            echo '</label>';
        }

        echo '</div>';
        echo '</p>';
        echo '</div>';
    }
}
