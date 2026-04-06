<?php
/**
 * Quick Order admin page renderer.
 */

class MealsDB_Quick_Order_UI {
    /**
     * Enqueue Quick Order scripts and styles.
     */
    public static function enqueue_scripts(): void {
        wp_enqueue_script('mealsdb-quick-order');
    }
    /**
     * Render the Quick Order admin page.
     */
    public static function render_quick_order_page(): void {
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'meals-db'));
        }

        self::enqueue_scripts();

        $clone_order_id = self::get_requested_clone_order_id();
        $products_array   = [];
        $categories_array = [];

        if (class_exists('MealsDB_Quick_Order_Products')) {
            $products_array   = MealsDB_Quick_Order_Products::get_all_quick_order_products();
            $categories_array = MealsDB_Quick_Order_Products::get_categories();
        }

        if (function_exists('wp_localize_script')) {
            wp_localize_script(
                'mealsdb-quick-order',
                'mealsdb_qo_preload',
                [
                    'products'   => is_array($products_array) ? array_values($products_array) : [],
                    'categories' => is_array($categories_array) ? array_values($categories_array) : [],
                ]
            );
        }
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
            <?php if ($clone_order_id > 0) : ?>
                <div
                    id="qo-clone-banner"
                    style="
    background: #eaf4ff; 
    padding: 10px; 
    border-left: 4px solid #2271b1;
    margin-bottom: 15px;
"
                >
                    <?php
                    printf(
                        /* translators: %s: WooCommerce order ID. */
                        esc_html__('Loaded from Order #%s — review and submit.', 'meals-db'),
                        esc_html($clone_order_id)
                    );
                    ?>
                </div>
            <?php endif; ?>
            <?php
            if (function_exists('settings_errors')) {
                settings_errors();
            }
            ?>

            <div class="mealsdb-quick-order__control">
                <label for="mealsdb_qo_client_search">Client</label>

                <div class="mealsdb-client-combobox">
                    <input type="text"
                           id="mealsdb_qo_client_search"
                           placeholder="Search clients..."
                           autocomplete="off">

                    <div id="mealsdb_qo_client_dropdown" class="client-dropdown"></div>

                    <input type="hidden"
                           id="client_id"
                           name="client_id">
                </div>
            </div>

            <div class="mealsdb-quick-order__controls">
                <div class="mealsdb-quick-order__control">
                    <label for="mealsdb-quick-order-date"><?php esc_html_e('Order Date', 'meals-db'); ?></label>
                    <input type="date" id="mealsdb-quick-order-date" class="mealsdb-quick-order__order-date" />
                </div>
                <div class="mealsdb-quick-order__control" id="mealsdb-quick-order-rate-container" style="display: none;">
                    <label for="mealsdb-quick-order-rate">
                        <?php esc_html_e('Billing Rate', 'meals-db'); ?>
                    </label>
                    <select id="mealsdb-quick-order-rate" class="mealsdb-quick-order__rate-select" style="width: 250px;">
                        <option value="0"><?php esc_html_e('— Select rate —', 'meals-db'); ?></option>
                    </select>
                </div>
            </div>

            <div id="mealsdb-qo-categories" class="mealsdb-qo-categories">
                <p><?php esc_html_e('Loading categories…', 'meals-db'); ?></p>
            </div>

            <div id="mealsdb-qo-search-container" style="margin-bottom: 15px;">
                <input
                    type="text"
                    id="mealsdb_qo_search"
                    class="regular-text"
                    placeholder="Search products..."
                    autocomplete="off"
                    style="width: 100%; max-width: 400px;"
                >
            </div>

            <div class="mealsdb-quick-order__layout">
                <div class="mealsdb-quick-order__products" id="mealsdb-quick-order-products" aria-live="polite">
                    <p><?php esc_html_e('Product grid will load here.', 'meals-db'); ?></p>
                </div>

                <aside class="mealsdb-quick-order__summary" id="mealsdb-quick-order-summary" aria-labelledby="mealsdb-quick-order-summary-heading">
                    <header class="mealsdb-quick-order__summary-header">
                        <h2 class="mealsdb-quick-order__summary-title" id="mealsdb-quick-order-summary-heading"><?php esc_html_e('Order Summary', 'meals-db'); ?></h2>
                        <dl class="mealsdb-quick-order__summary-meta">
                            <div class="mealsdb-quick-order__summary-meta-row">
                                <dt class="mealsdb-quick-order__summary-meta-label"><?php esc_html_e('Client', 'meals-db'); ?></dt>
                                <dd class="mealsdb-quick-order__summary-meta-value" id="mealsdb-quick-order-summary-client"><?php esc_html_e('Not selected', 'meals-db'); ?></dd>
                            </div>
                            <div class="mealsdb-quick-order__summary-meta-row">
                                <dt class="mealsdb-quick-order__summary-meta-label"><?php esc_html_e('Order Date', 'meals-db'); ?></dt>
                                <dd class="mealsdb-quick-order__summary-meta-value" id="mealsdb-quick-order-summary-date"><?php esc_html_e('Not set', 'meals-db'); ?></dd>
                            </div>
                            <div class="mealsdb-quick-order__summary-meta-row">
                                <dt class="mealsdb-quick-order__summary-meta-label"><?php esc_html_e('Billing Rate', 'meals-db'); ?></dt>
                                <dd class="mealsdb-quick-order__summary-meta-value" id="mealsdb-quick-order-summary-rate"><?php esc_html_e('Not set', 'meals-db'); ?></dd>
                            </div>
                        </dl>
                    </header>

                    <div class="mealsdb-quick-order__summary-body">
                        <div class="mealsdb-quick-order__summary-empty" id="mealsdb-quick-order-summary-empty">
                            <p><?php esc_html_e('Summary details will appear here.', 'meals-db'); ?></p>
                        </div>
                        <div class="mealsdb-quick-order__summary-content" id="mealsdb-quick-order-summary-content" hidden></div>
                    </div>

                    <footer class="mealsdb-quick-order__summary-footer">
                        <dl class="mealsdb-quick-order__summary-totals">
                            <div class="mealsdb-quick-order__summary-total-row">
                                <dt class="mealsdb-quick-order__summary-total-label"><?php esc_html_e('Items', 'meals-db'); ?></dt>
                                <dd class="mealsdb-quick-order__summary-total-value" id="mealsdb-quick-order-summary-items">0</dd>
                            </div>
                            <div class="mealsdb-quick-order__summary-total-row">
                                <dt class="mealsdb-quick-order__summary-total-label"><?php esc_html_e('Total', 'meals-db'); ?></dt>
                                <dd class="mealsdb-quick-order__summary-total-value" id="mealsdb-quick-order-summary-total">0</dd>
                            </div>
                        </dl>

                        <button id="qo-create-order" class="button button-primary button-large">
                            Create Order
                        </button>
                    </footer>
                </aside>
            </div>
        </div>
        <?php
    }

    /**
     * Retrieve the requested order ID to clone from the current request.
     */
    public static function get_requested_clone_order_id(): int {
        $clone_order = null;

        if (isset($_GET['clone_order'])) {
            $clone_order = $_GET['clone_order'];
        } elseif (isset($_GET['clone_order_id'])) {
            $clone_order = $_GET['clone_order_id'];
        }

        if ($clone_order === null) {
            return 0;
        }

        if (function_exists('wp_unslash')) {
            $clone_order = wp_unslash($clone_order);
        }

        return absint($clone_order);
    }
}
