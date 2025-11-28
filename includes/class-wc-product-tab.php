<?php
/**
 * WooCommerce product data tab integration for Meals DB metadata.
 */

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

        $script = "jQuery(function($){\n            var productType = $('#_mealsdb_product_type');\n            var taxableCheckbox = $('#_mealsdb_taxable');\n            var taxStatus = $('#_tax_status');\n            var taxClass = $('#_tax_class');\n\n            function syncTaxFields(){\n                var isMeal = productType.val() === 'meal';\n                var isTaxable = taxableCheckbox.is(':checked');\n\n                taxableCheckbox.prop('disabled', isMeal);\n\n                if(isMeal){\n                    taxableCheckbox.prop('checked', false);\n                }\n\n                taxStatus.prop('disabled', isMeal);\n                taxClass.prop('disabled', isMeal);\n\n                if(isMeal){\n                    taxStatus.val('none').trigger('change');\n                    taxClass.val('').trigger('change');\n                    return;\n                }\n\n                taxStatus.val(isTaxable ? 'taxable' : 'none').trigger('change');\n            }\n\n            syncTaxFields();\n            productType.on('change', syncTaxFields);\n            taxableCheckbox.on('change', syncTaxFields);\n        });";
        wp_add_inline_script('wc-admin-product-meta-boxes', $script);
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

        woocommerce_wp_select([
            'id'      => '_mealsdb_product_type',
            'label'   => __('Product Type', 'meals-db'),
            'value'   => $data['product_type'],
            'options' => [
                'meal' => __('Meal', 'meals-db'),
                'side' => __('Side', 'meals-db'),
            ],
        ]);

        $this->render_checkbox_field([
            'id'          => '_mealsdb_taxable',
            'label'       => __('Taxable', 'meals-db'),
            'value'       => (int) $data['taxable'],
            'description' => __('If the product type is meal, this will remain disabled.', 'meals-db'),
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

        $product_id   = $product->get_id();
        $product_type = isset($_POST['_mealsdb_product_type'])
            ? sanitize_text_field(wp_unslash($_POST['_mealsdb_product_type']))
            : 'meal';

        $taxable = 0;
        if ($product_type !== 'meal' && isset($_POST['_mealsdb_taxable'])) {
            $taxable = 1;
        }

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

        $unit_cost = isset($_POST['_mealsdb_unit_cost'])
            ? wc_format_decimal(wp_unslash($_POST['_mealsdb_unit_cost']))
            : '0.00';

        if ($product_type === 'meal') {
            $product->set_tax_status('none');
            $product->set_tax_class('');
        } else {
            $product->set_tax_status($taxable ? 'taxable' : 'none');

            if ($taxable === 0) {
                $product->set_tax_class('');
            }
        }

        MealsDB_Products::save_product_data($product_id, [
            'product_type'    => $product_type,
            'taxable'         => $taxable,
            'main_ingredient' => $main_ingredient,
            'dietary_tags'    => $dietary_tags,
            'allergen_flags'  => $allergen_flags,
            'case_size'       => $case_size,
            'unit_cost'       => $unit_cost,
        ]);
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
        echo '<label>' . esc_html($label) . '</label>';
        $classes = ['wrap'];
        if ($wrap_class !== '') {
            $classes[] = $wrap_class;
        }

        echo '<span class="' . esc_attr(implode(' ', $classes)) . '">';

        foreach ($options as $key => $option_label) {
            $field_id = $id . '_' . $key;
            $is_checked = in_array($key, $selected, true);
            echo '<label class="mealsdb-multi-checkbox">';
            echo '<input type="checkbox" name="' . esc_attr($id) . '[]" id="' . esc_attr($field_id) . '" value="' . esc_attr($key) . '" ' . checked(true, $is_checked, false) . ' /> ' . esc_html($option_label);
            echo '</label>';
        }

        echo '</span>';
        echo '</p>';
        echo '</div>';
    }
}
