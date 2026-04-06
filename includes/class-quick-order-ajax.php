<?php
/**
 * AJAX handlers for Meals DB Quick Order feature.
 */

class MealsDB_Quick_Order_Ajax {
    /**
     * Register AJAX actions related to the Quick Order UI.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_qo_get_categories', [self::class, 'get_categories']);
        add_action('wp_ajax_mealsdb_qo_get_products_by_category', [self::class, 'get_products_by_category']);
        add_action('wp_ajax_mealsdb_qo_search_clients', [self::class, 'search_clients']);
        add_action('wp_ajax_mealsdb_qo_search_products', [self::class, 'search_products']);
        add_action('wp_ajax_mealsdb_qo_get_all_products', [self::class, 'get_all_products']);
        add_action('wp_ajax_mealsdb_qo_create_order', [self::class, 'create_order']);
        add_action('wp_ajax_mealsdb_qo_clone_order', [self::class, 'clone_order']);
        add_action('wp_ajax_mealsdb_qo_clone_get_order', [self::class, 'clone_get_order']);
        MealsDB_Ajax_Rates::init();
    }

    /**
     * Render product tiles in the same structure as the category loader.
     *
     * @param array<int, array<string, mixed>> $products
     */
    private static function render_product_tiles(array $products): string {
        if (empty($products)) {
            return '<p>' . esc_html__('No products matched your search.', 'meals-db') . '</p>';
        }

        $buffer = '<div class="mealsdb-quick-order__product-grid mealsdb-qo-grid" id="mealsdb-qo-grid">';

        foreach ($products as $product) {
            $product_id = isset($product['product_id']) ? intval($product['product_id']) : 0;
            if ($product_id <= 0) {
                continue;
            }

            $name       = isset($product['name']) ? (string) $product['name'] : sprintf(__('Product #%d', 'meals-db'), $product_id);
            $price      = isset($product['price']) ? (float) $product['price'] : 0.0;
            $image_url  = isset($product['image_url']) ? (string) $product['image_url'] : '';
            $json_data  = wp_json_encode($product);
            $price_html = function_exists('wc_price') ? wc_price($price) : number_format($price, 2);

            $buffer .= '<div class="mealsdb-qo-tile">';
            $buffer .= '<div class="mealsdb-quick-order__product" data-product-id="' . esc_attr((string) $product_id) . '"';
            if (!empty($json_data)) {
                $buffer .= ' data-product="' . esc_attr($json_data) . '"';
            }
            $buffer .= '>';

            if ($image_url !== '') {
                $buffer .= '<div class="mealsdb-quick-order__product-image">';
                $buffer .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($name) . '" class="mealsdb-qo-image" loading="lazy" />';
                $buffer .= '</div>';
            }

            $buffer .= '<div class="mealsdb-quick-order__product-content">';
            $buffer .= '<h3 class="mealsdb-quick-order__product-title">' . esc_html($name) . '</h3>';
            $buffer .= '<div class="mealsdb-quick-order__product-price">' . wp_kses_post($price_html) . '</div>';
            $buffer .= '<div class="mealsdb-quick-order__product-actions mealsdb-qo-qty-controls">';
            $buffer .= '<button type="button" class="button mealsdb-quick-order__qty-decrease mealsdb-qo-btn" aria-label="' . esc_attr__('Decrease quantity', 'meals-db') . '">-</button>';
            $buffer .= '<input type="number" min="0" class="small-text mealsdb-quick-order__qty-input mealsdb-qo-qty" value="0" />';
            $buffer .= '<button type="button" class="button mealsdb-quick-order__qty-increase mealsdb-qo-btn" aria-label="' . esc_attr__('Increase quantity', 'meals-db') . '">+</button>';
            $buffer .= '</div>';
            $buffer .= '</div>';
            $buffer .= '</div>';
            $buffer .= '</div>';
        }

        $buffer .= '</div>';

        return $buffer;
    }

    /**
     * AJAX endpoint to fetch product categories.
     */
    public static function get_categories(): void {
        self::verify_request();

        // Rate limiting
        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        try {
            $categories = MealsDB_Quick_Order_Products::get_categories();
            wp_send_json([
                'success'    => true,
                'categories' => $categories,
            ]);
        } catch (Exception $e) {
            error_log('[MealsDB QuickOrder] get_categories error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('An error occurred. Please try again.', 'meals-db'),
            ]);
        }
    }

    /**
     * AJAX endpoint to fetch products by category.
     */
    public static function get_products_by_category(): void {
        self::verify_request();

        // Rate limiting
        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        try {
            $category_id = isset($_REQUEST['category_id']) ? intval($_REQUEST['category_id']) : 0;
            if ($category_id <= 0) {
                wp_send_json([
                    'success' => false,
                    'message' => __('Missing or invalid category.', 'meals-db'),
                ]);
            }

            $products = MealsDB_Quick_Order_Products::get_products_by_category($category_id);
            wp_send_json([
                'success'  => true,
                'products' => $products,
            ]);
        } catch (Exception $e) {
            error_log('[MealsDB QuickOrder] get_products_by_category error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('An error occurred. Please try again.', 'meals-db'),
            ]);
        }
    }

    /**
     * AJAX endpoint to fetch all products across allowed categories.
     */
    public static function get_all_products(): void {
        self::verify_request();

        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        try {
            $products = MealsDB_Quick_Order_Products::get_all_products();
            wp_send_json([
                'success'  => true,
                'products' => $products,
            ]);
        } catch (Exception $e) {
            error_log('[MealsDB QuickOrder] get_all_products error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('An error occurred. Please try again.', 'meals-db'),
            ]);
        }
    }

    /**
     * AJAX endpoint to search products by keyword.
     */
    public static function search_products(): void {
        self::verify_request();

        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        $keyword = isset($_REQUEST['keyword']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['keyword'])) : '';
        if (strlen($keyword) < 2) {
            wp_send_json(['success' => true, 'products' => []]);
        }

        try {
            $products = MealsDB_Quick_Order_Products::search_products($keyword);
            wp_send_json([
                'success'  => true,
                'products' => $products,
            ]);
        } catch (Exception $e) {
            error_log('[MealsDB QuickOrder] search_products error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('An error occurred. Please try again.', 'meals-db'),
            ]);
        }
    }

    /**
     * AJAX endpoint to search active clients by name.
     */
    public static function search_clients(): void {
        self::verify_request();

        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        $term = isset($_REQUEST['term']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['term'])) : '';
        if (strlen($term) < 2) {
            wp_send_json(['success' => true, 'clients' => []]);
        }

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            wp_send_json(['success' => true, 'clients' => []]);
        }

        $table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $sql   = "
            SELECT client_id, wp_user_id, first_name, last_name
            FROM `{$table}`
            WHERE active = 1
              AND (first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?)
            ORDER BY last_name ASC, first_name ASC
            LIMIT 25
        ";

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            wp_send_json(['success' => true, 'clients' => []]);
        }

        $like = '%' . $term . '%';
        $stmt->bind_param('sss', $like, $like, $like);

        if (!$stmt->execute()) {
            $stmt->close();
            wp_send_json(['success' => true, 'clients' => []]);
        }

        $clients = [];
        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if (MealsDB_DB::is_mysqli_result($result)) {
                while ($row = $result->fetch_assoc()) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $clients[] = [
                        'client_id'  => (int) $row['client_id'],
                        'wp_user_id' => (int) $row['wp_user_id'],
                        'name'       => $row['first_name'] . ' ' . $row['last_name'],
                    ];
                }
            }
        }

        $stmt->close();

        wp_send_json(['success' => true, 'clients' => $clients]);
    }

    /**
     * AJAX endpoint to create a new WooCommerce order for a Meals DB client.
     */
    public static function create_order(): void {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'mealsdb_quick_order_create_order')) {
            wp_send_json([
                'success' => false,
                'message' => __('Invalid request.', 'meals-db'),
            ]);
        }

        self::verify_request(true);

        // Rate limiting - stricter for order creation
        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_create')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        // WordPress user ID for the client placing the order.
        $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
        $date      = isset($_POST['date']) ? sanitize_text_field(wp_unslash((string) $_POST['date'])) : '';
        $items     = self::normalise_items($_POST['items'] ?? []);
        $rate_id   = isset($_POST['rate_id']) ? intval($_POST['rate_id']) : 0;
        $order_date = self::parse_order_date($date);

        if (
            $client_id <= 0
            || !$order_date instanceof DateTimeImmutable
            || empty($items)
            || !self::user_exists($client_id)
        ) {
            wp_send_json([
                'success' => false,
                'message' => __('Invalid order data.', 'meals-db'),
            ]);
        }

        // Meals DB client_id from the external meals_clients table for this WordPress user.
        $client_db_id = self::get_active_client_id_for_user($client_id);

        // Validate that the selected rate belongs to this client.
        if ($rate_id > 0 && $client_db_id > 0) {
            if (!self::validate_rate_for_client($rate_id, $client_db_id)) {
                wp_send_json([
                    'success' => false,
                    'message' => __('Invalid rate selection.', 'meals-db'),
                ]);
            }
        }

        try {
            $order = self::create_wc_order($items, $order_date);
            if (is_wp_error($order)) {
                throw new Exception($order->get_error_message());
            }

            $order->update_meta_data('mealsdb_client_user_id', $client_id);

            if ($client_db_id > 0) {
                $order->update_meta_data('mealsdb_client_id', $client_db_id);
            }

            if ($rate_id > 0) {
                $order->update_meta_data('mealsdb_rate_id', $rate_id);
            }

            $order->save();

            // Update operational wp_usermeta fields (matches old admin-pos-order behavior).
            // These are read by the call-log-manager to schedule follow-up calls.
            update_user_meta($client_id, 'last_order_date', current_time('mysql'));
            update_user_meta($client_id, 'last_call_date', current_time('mysql'));

            $order_id = $order->get_id();
            wp_send_json([
                'success' => true,
                'order_id' => $order_id,
                'order_link' => get_edit_post_link($order_id),
            ]);
        } catch (Exception $e) {
            error_log('[MealsDB QuickOrder] Order error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('Order creation failed. Please try again.', 'meals-db'),
            ]);
        }
    }

    /**
     * AJAX endpoint to retrieve items from an existing WooCommerce order.
     */
    public static function clone_order(): void {
        self::verify_request();

        // Rate limiting
        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        if (!self::woocommerce_is_available()) {
            wp_send_json([
                'success' => false,
                'message' => __('WooCommerce is required to clone orders.', 'meals-db'),
            ]);
        }

        try {
            $source_order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;

            // Generic error message to prevent enumeration
            $source_order = wc_get_order($source_order_id);
            if (!$source_order instanceof WC_Order || $source_order_id <= 0) {
                wp_send_json([
                    'success' => false,
                    'message' => __('Invalid order request.', 'meals-db'),
                ]);
            }

            $items_map = [];

            foreach ($source_order->get_items('line_item') as $item) {
                if (!$item instanceof WC_Order_Item_Product) {
                    continue;
                }

                $quantity = (int) $item->get_quantity();
                if ($quantity <= 0) {
                    continue;
                }

                $product = $item->get_product();
                if (!$product instanceof WC_Product) {
                    continue;
                }

                $payload = MealsDB_Quick_Order_Products::format_for_quick_order([$product]);
                if (empty($payload) || !isset($payload[0]['product_id'])) {
                    continue;
                }

                $product_id = (int) $payload[0]['product_id'];
                if ($product_id <= 0) {
                    continue;
                }

                if (isset($items_map[$product_id])) {
                    $items_map[$product_id]['quantity'] += $quantity;
                } else {
                    $items_map[$product_id] = [
                        'product'  => $payload[0],
                        'quantity' => $quantity,
                    ];
                }
            }

            if (empty($items_map)) {
                wp_send_json([
                    'success' => false,
                    'message' => __('No products were found on the source order.', 'meals-db'),
                ]);
            }

            $order_date = '';
            $created = $source_order->get_date_created();
            if ($created instanceof WC_DateTime) {
                $date = clone $created;
                if (function_exists('wp_timezone')) {
                    $date = $date->setTimezone(wp_timezone());
                }

                $order_date = $date->format('Y-m-d');
            }

            $client_id = intval($source_order->get_meta('mealsdb_client_id'));
            if ($client_id <= 0) {
                $client_id = null;
            }

            $order_number = method_exists($source_order, 'get_order_number') ? $source_order->get_order_number() : $source_order_id;
            $message = sprintf(__('Products from order %s have been loaded into Quick Order.', 'meals-db'), $order_number);

            wp_send_json([
                'success'    => true,
                'message'    => $message,
                'items'      => array_values($items_map),
                'client_id'  => $client_id,
                'order_date' => $order_date,
                'order_id'   => $source_order_id,
            ]);
        } catch (Exception $e) {
            error_log('[MealsDB QuickOrder] clone_order error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('An error occurred. Please try again.', 'meals-db'),
            ]);
        }
    }

    /**
     * AJAX endpoint to retrieve order details and quantities for cloning.
     */
    public static function clone_get_order(): void {
        self::verify_request();

        // Rate limiting
        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        if (!self::woocommerce_is_available()) {
            wp_send_json([
                'success' => false,
                'message' => __('WooCommerce is required to clone orders.', 'meals-db'),
            ]);
        }

        try {
            $source_order_id = isset($_REQUEST['order_id']) ? absint(wp_unslash($_REQUEST['order_id'])) : 0;

            // Generic error message to prevent enumeration
            $source_order = wc_get_order($source_order_id);
            if (!$source_order instanceof WC_Order || $source_order_id <= 0) {
                wp_send_json([
                    'success' => false,
                    'message' => __('Invalid order request.', 'meals-db'),
                ]);
            }

            $items = [];
            foreach ($source_order->get_items('line_item') as $item) {
                if (!$item instanceof WC_Order_Item_Product) {
                    continue;
                }

                $quantity = (int) $item->get_quantity();
                if ($quantity <= 0) {
                    continue;
                }

                $product = $item->get_product();
                if (!$product instanceof WC_Product) {
                    continue;
                }

                $product_id = $product->get_id();
                if ($product_id <= 0) {
                    continue;
                }

                $existing_product = wc_get_product($product_id);
                if (!$existing_product instanceof WC_Product) {
                    continue;
                }

                $items[$product_id] = ($items[$product_id] ?? 0) + $quantity;
            }

            $order_date = '';
            $created    = $source_order->get_date_created();
            if ($created instanceof WC_DateTime) {
                $date = clone $created;
                if (function_exists('wp_timezone')) {
                    $date = $date->setTimezone(wp_timezone());
                }

                $order_date = $date->format('Y-m-d');
            }

            $client_id    = intval($source_order->get_meta('mealsdb_client_user_id'));
            $client_db_id = intval($source_order->get_meta('mealsdb_client_id'));

            if ($client_id <= 0 && $client_db_id > 0) {
                $client_id = self::get_user_id_for_client($client_db_id);
            }

            if ($client_db_id <= 0 && $client_id > 0) {
                $client_db_id = self::get_active_client_id_for_user($client_id);
            }

            if ($client_id <= 0) {
                $client_id = null;
            }

            $client_type = '';
            if ($client_db_id > 0) {
                $conn = MealsDB_DB::get_connection();
                if (MealsDB_DB::is_mysqli($conn)) {
                    $table_name = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
                    $sql        = sprintf(
                        'SELECT client_type FROM `%s` WHERE client_id = ? LIMIT 1',
                        str_replace('`', '``', $table_name)
                    );

                    $stmt = $conn->prepare($sql);
                    if (MealsDB_DB::is_mysqli_stmt($stmt)) {
                        $stmt->bind_param('i', $client_db_id);

                        if ($stmt->execute()) {
                            $result = $stmt->get_result();
                            if (MealsDB_DB::is_mysqli_result($result)) {
                                $row = $result->fetch_assoc();
                                if (isset($row['client_type'])) {
                                    $client_type = (string) $row['client_type'];
                                }
                            }
                        }

                        $stmt->close();
                    }
                }
            }

            $rate_id = intval($source_order->get_meta('mealsdb_rate_id'));

            wp_send_json([
                'success'     => true,
                'client_id'   => $client_id,
                'client_type' => $client_type,
                'order_date'  => $order_date,
                'items'       => $items,
                'rate_id'     => $rate_id > 0 ? $rate_id : null,
            ]);
        } catch (Exception $e) {
            error_log('[MealsDB QuickOrder] clone_get_order error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('An error occurred. Please try again.', 'meals-db'),
            ]);
        }
    }

    /**
     * Confirm WooCommerce classes and helpers are available before cloning orders.
     */
    private static function woocommerce_is_available(): bool
    {
        return function_exists('wc_get_order')
            && class_exists('WC_Order')
            && class_exists('WC_Order_Item_Product')
            && class_exists('WC_Product');
    }

    /**
     * Ensure the AJAX request is valid and user is authorised.
     */
    private static function verify_request(bool $nonce_already_verified = false): void {
        if (!$nonce_already_verified) {
            $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['nonce'])) : '';
            if ($nonce === '' || !wp_verify_nonce($nonce, 'mealsdb_nonce')) {
                wp_send_json([
                    'success' => false,
                    'message' => __('Invalid or missing nonce.', 'meals-db'),
                ]);
            }
        }

        if (!self::current_user_can_access_quick_order()) {
            wp_send_json([
                'success' => false,
                'message' => __('You are not allowed to perform this action.', 'meals-db'),
            ], 403);
        }
    }

    /**
     * Determine whether the current user may access Quick Order endpoints.
     */
    private static function current_user_can_access_quick_order(): bool {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        $capability = MealsDB_Permissions::required_capability();
        if (!is_string($capability) || $capability === '') {
            $capability = 'manage_woocommerce';
        }

        return current_user_can($capability);
    }

    /**
     * Confirm the provided WordPress user exists.
     */
    private static function user_exists(int $user_id): bool {
        if ($user_id <= 0) {
            return false;
        }

        return (bool) get_userdata($user_id);
    }

    /**
     * Lookup an active Meals DB client ID for a given WordPress user.
     */
    private static function get_active_client_id_for_user(int $user_id): int {
        if ($user_id <= 0) {
            return 0;
        }

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return 0;
        }

        $table_name = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $sql        = sprintf(
            'SELECT client_id FROM `%s` WHERE wp_user_id = ? AND active = 1 LIMIT 1',
            str_replace('`', '``', $table_name)
        );

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return 0;
        }

        $stmt->bind_param('i', $user_id);

        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }

        $result = $stmt->get_result();
        $stmt->close();

        if (!MealsDB_DB::is_mysqli_result($result)) {
            return 0;
        }

        $row = $result->fetch_assoc();
        if (!is_array($row) || !isset($row['client_id'])) {
            return 0;
        }

        return (int) $row['client_id'];
    }

    /**
     * Resolve a WordPress user ID for an existing Meals DB client record.
     */
    private static function get_user_id_for_client(int $client_id): int {
        if ($client_id <= 0) {
            return 0;
        }

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return 0;
        }

        $table_name = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $sql        = sprintf(
            'SELECT wp_user_id FROM `%s` WHERE client_id = ? AND active = 1 LIMIT 1',
            str_replace('`', '``', $table_name)
        );

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return 0;
        }

        $stmt->bind_param('i', $client_id);

        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }

        $result = $stmt->get_result();
        $stmt->close();

        if (!MealsDB_DB::is_mysqli_result($result)) {
            return 0;
        }

        $row = $result->fetch_assoc();
        if (!is_array($row) || !isset($row['wp_user_id'])) {
            return 0;
        }

        return (int) $row['wp_user_id'];
    }

    /**
     * Parse the incoming order date into a DateTimeImmutable instance.
     */
    private static function parse_order_date(string $date): ?DateTimeImmutable {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date, $timezone);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }

        try {
            return new DateTimeImmutable($date, $timezone);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Normalise the posted items list.
     *
     * @param mixed $raw_items Raw item payload from the request.
     *
     * @return array<int, array<string, int>>
     */
    private static function normalise_items($raw_items): array {
        if (is_string($raw_items)) {
            $decoded = json_decode($raw_items, true);
            if (is_array($decoded)) {
                $raw_items = $decoded;
            }
        }

        if (!is_array($raw_items)) {
            return [];
        }

        $items = [];
        foreach ($raw_items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product_id = isset($item['product_id']) ? intval($item['product_id']) : 0;
            $quantity   = isset($item['quantity']) ? intval($item['quantity']) : 0;
            $variation  = isset($item['variation_id']) ? intval($item['variation_id']) : 0;

            if ($product_id <= 0 || $quantity <= 0) {
                continue;
            }

            $items[] = [
                'product_id'   => $product_id,
                'quantity'     => $quantity,
                'variation_id' => $variation,
            ];
        }

        return $items;
    }

    /**
     * Create a WooCommerce order populated with the provided items.
     *
     * @param array<int, array<string, int>> $items
     * @param DateTimeImmutable|null         $order_date
     *
     * @return WC_Order|WP_Error
     */
    private static function create_wc_order(array $items, ?DateTimeImmutable $order_date) {
        if (!function_exists('wc_create_order') || !class_exists('WC_Order')) {
            return new WP_Error('mealsdb_missing_woocommerce', __('WooCommerce is required to create orders.', 'meals-db'));
        }

        $order = wc_create_order();
        if (is_wp_error($order)) {
            return $order;
        }

        foreach ($items as $item) {
            $product_id   = $item['product_id'];
            $variation_id = $item['variation_id'] ?? 0;
            $quantity     = $item['quantity'];

            $product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($product_id);
            if (!$product instanceof WC_Product) {
                continue;
            }

            if ($variation_id > 0 && $product instanceof WC_Product_Variation) {
                $order->add_product($product, $quantity, [
                    'variation' => $product->get_attributes(),
                ]);
            } else {
                $order->add_product($product, $quantity);
            }
        }

        $order->calculate_totals();

        if ($order_date instanceof DateTimeImmutable) {
            try {
                $wc_date = new WC_DateTime($order_date->format('Y-m-d H:i:s'), $order_date->getTimezone());
                $order->set_date_created($wc_date);
            } catch (Exception $e) {
                // Ignore date parsing errors and keep default creation date.
            }
        }

        return $order;
    }

    /**
     * Validate that a rate_id belongs to a given client in meals_client_rates.
     */
    private static function validate_rate_for_client(int $rate_id, int $client_id): bool {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return false;
        }

        $table_name = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES);
        $sql = sprintf(
            'SELECT rate_id FROM `%s` WHERE rate_id = ? AND client_id = ? LIMIT 1',
            str_replace('`', '``', $table_name)
        );

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return false;
        }

        $stmt->bind_param('ii', $rate_id, $client_id);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $result = $stmt->get_result();
        $found = false;

        if (MealsDB_DB::is_mysqli_result($result)) {
            $found = $result->fetch_assoc() !== null;
        }

        $stmt->close();

        return $found;
    }
}
