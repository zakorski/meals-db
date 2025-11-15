<?php
/**
 * Quick Order admin page renderer.
 */

class MealsDB_Quick_Order_UI {
    /**
     * Render the Quick Order admin page.
     */
    public static function render_quick_order_page(): void {
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'meals-db'));
        }

        $clone_order_id = self::get_requested_clone_order_id();
        $attributes = [
            'class' => 'wrap mealsdb-quick-order',
        ];

        if ($clone_order_id > 0) {
            $attributes['data-clone-order-id'] = (string) $clone_order_id;
        }

        $attribute_string = '';
        foreach ($attributes as $name => $value) {
            $attribute_string .= sprintf(' %s="%s"', esc_attr($name), esc_attr($value));
        }

        ?>
        <div<?php echo $attribute_string; ?>>
            <h1><?php esc_html_e('Quick Order', 'meals-db'); ?></h1>
            <?php
            if (function_exists('settings_errors')) {
                settings_errors();
            }
            ?>

            <div class="mealsdb-quick-order__controls">
                <div class="mealsdb-quick-order__control">
                    <label for="mealsdb-quick-order-client"><?php esc_html_e('Client', 'meals-db'); ?></label>
                    <select id="mealsdb-quick-order-client" class="mealsdb-quick-order__client-selector" data-placeholder="<?php echo esc_attr__('Client', 'meals-db'); ?>">
                        <option value=""><?php esc_html_e('Select a client…', 'meals-db'); ?></option>
                    </select>
                </div>

                <div class="mealsdb-quick-order__control">
                    <label for="mealsdb-quick-order-date"><?php esc_html_e('Order Date', 'meals-db'); ?></label>
                    <input type="date" id="mealsdb-quick-order-date" class="mealsdb-quick-order__order-date" />
                </div>

                <div class="mealsdb-quick-order__control">
                    <label for="mealsdb-quick-order-search"><?php esc_html_e('Search Products', 'meals-db'); ?></label>
                    <input type="search" id="mealsdb-quick-order-search" class="mealsdb-quick-order__search" placeholder="<?php echo esc_attr__('Search products…', 'meals-db'); ?>" />
                </div>
            </div>

            <div class="mealsdb-quick-order__categories" id="mealsdb-quick-order-categories" aria-live="polite">
                <p><?php esc_html_e('Category tabs will load here.', 'meals-db'); ?></p>
            </div>

            <div class="mealsdb-quick-order__layout">
                <div class="mealsdb-quick-order__products" id="mealsdb-quick-order-products" aria-live="polite">
                    <p><?php esc_html_e('Product grid will load here.', 'meals-db'); ?></p>
                </div>

                <aside class="mealsdb-quick-order__summary" id="mealsdb-quick-order-summary">
                    <h2><?php esc_html_e('Order Summary', 'meals-db'); ?></h2>
                    <div class="mealsdb-quick-order__summary-content">
                        <p><?php esc_html_e('Summary details will appear here.', 'meals-db'); ?></p>
                    </div>
                    <button type="button" class="button button-primary mealsdb-quick-order__create-order" id="mealsdb-quick-order-create">
                        <?php esc_html_e('Create Order', 'meals-db'); ?>
                    </button>
                </aside>
            </div>
        </div>
        <?php
    }

    /**
     * Retrieve the requested order ID to clone from the current request.
     */
    public static function get_requested_clone_order_id(): int {
        if (!isset($_GET['clone_order_id'])) {
            return 0;
        }

        $clone_order_id = $_GET['clone_order_id'];
        if (function_exists('wp_unslash')) {
            $clone_order_id = wp_unslash($clone_order_id);
        }

        return absint($clone_order_id);
    }
}
