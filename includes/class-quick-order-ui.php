<?php
/**
 * Quick Order admin page renderer.
 */

defined('ABSPATH') || exit;

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

        // Shadow mode: Quick Order is disabled. Render an explanatory notice
        // instead of the order form so an operator can't place a live order
        // that the legacy system would see during the parallel trial.
        if (MealsDB_Shadow_Mode::is_enabled()) {
            echo '<div class="wrap"><h1>' . esc_html__('Quick Order', 'meals-db') . '</h1>';
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('Quick Order is disabled while the system is running in shadow mode (parallel trial). Place orders through the existing system until cutover.', 'meals-db')
                . '</p></div></div>';
            return;
        }

        self::enqueue_scripts();

        $clone_order_id = self::get_requested_clone_order_id();
        $products_array   = [];
        $categories_array = [];

        if (class_exists('MealsDB_Quick_Order_Products')) {
            $products_array   = MealsDB_Quick_Order_Products::get_all_quick_order_products();
            $categories_array = MealsDB_Quick_Order_Products::get_categories();
        }

        if (function_exists('wp_add_inline_script')) {
            $preload = [
                'products'   => is_array($products_array) ? array_values($products_array) : [],
                'categories' => is_array($categories_array) ? array_values($categories_array) : [],
            ];
            wp_add_inline_script(
                'mealsdb-quick-order',
                'window.mealsdb_qo_preload = ' . wp_json_encode($preload) . ';',
                'before'
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

            <div id="mealsdb-qo-next-dates" class="mealsdb-quick-order__next-dates" style="display:none; margin:12px 0; padding:12px; background:#f0f6fc; border:1px solid #c5d9ed;">
                <h3 style="margin:0 0 8px;"><?php esc_html_e('Next Cycle', 'meals-db'); ?></h3>
                <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-end;">
                    <div>
                        <label for="mealsdb-qo-next-order-date"><strong><?php esc_html_e('Next Order Date:', 'meals-db'); ?></strong></label><br>
                        <input type="date" id="mealsdb-qo-next-order-date" name="next_order_date">
                        <div><small id="mealsdb-qo-next-order-default" style="color:#555;"></small></div>
                    </div>
                    <div>
                        <label for="mealsdb-qo-next-delivery-date"><strong><?php esc_html_e('Next Delivery Date:', 'meals-db'); ?></strong></label><br>
                        <input type="date" id="mealsdb-qo-next-delivery-date" name="next_delivery_date">
                        <div><small id="mealsdb-qo-next-delivery-default" style="color:#555;"></small></div>
                    </div>
                    <div>
                        <button type="button" class="button" id="mealsdb-qo-next-reset">
                            <?php esc_html_e('Reset to normal', 'meals-db'); ?>
                        </button>
                    </div>
                </div>
                <p class="description" style="margin:8px 0 0;">
                    <?php esc_html_e('Editable. These values become the anchor for the client\'s next cycle. "Normally" shows the date implied by the standard frequency.', 'meals-db'); ?>
                </p>
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

                    <div id="mealsdb-qo-allocation" class="mealsdb-quick-order__allocation" style="display: none;"></div>

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
            $clone_order = wp_unslash($_GET['clone_order']);
        } elseif (isset($_GET['clone_order_id'])) {
            $clone_order = wp_unslash($_GET['clone_order_id']);
        }

        if ($clone_order === null) {
            return 0;
        }

        return absint($clone_order);
    }
}
