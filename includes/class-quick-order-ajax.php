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
        add_action('wp_ajax_mealsdb_qo_search_products', [self::class, 'search_products']);
        add_action('wp_ajax_mealsdb_qo_create_order', [self::class, 'create_order']);
        add_action('wp_ajax_mealsdb_qo_clone_order', [self::class, 'clone_order']);
        add_action('wp_ajax_mealsdb_qo_clone_get_order', [self::class, 'clone_get_order']);
    }

    private static function normalize_phone(string $value): string {
        $digits = preg_replace('/\D+/', '', $value);
        return $digits ?? '';
    }

    private static function normalize_name(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return $value ?? '';
    }

    /**
     * Build a list of keywords for the provided product for fuzzy matching.
     */
    private static function collect_product_keywords(WC_Product $product): array {
        $keywords = [];

        $tag_names = wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']);
        if (is_array($tag_names) && !is_wp_error($tag_names)) {
            $keywords = array_merge($keywords, $tag_names);
        }

        $category_names = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
        if (is_array($category_names) && !is_wp_error($category_names)) {
            $keywords = array_merge($keywords, $category_names);
        }

        return array_filter(array_map('strval', $keywords));
    }

    /**
     * Calculate a fuzzy search score for the provided fields.
     *
     * @param string              $term   Search term from user input.
     * @param array<int, string>  $fields Fields to compare against.
     */
    private static function calculate_search_score(string $term, array $fields): ?int {
        $normalized_term = self::normalize_name($term);
        if ($normalized_term === '') {
            return null;
        }

        $best_score = null;
        foreach ($fields as $field) {
            $field_normalized = self::normalize_name((string) $field);
            if ($field_normalized === '') {
                continue;
            }

            if (strpos($field_normalized, $normalized_term) !== false) {
                $best_score = 0;
                break;
            }

            if (!function_exists('levenshtein')) {
                continue;
            }

            $parts = preg_split('/\s+/', $field_normalized);
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }

                $distance = levenshtein($normalized_term, $part);
                if ($distance <= 2) {
                    if ($best_score === null || $distance < $best_score) {
                        $best_score = $distance;
                    }
                }
            }
        }

        return $best_score;
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
                'message' => 'Server error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX endpoint to fetch products by category.
     */
    public static function get_products_by_category(): void {
        self::verify_request();

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
                'message' => 'Server error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX endpoint to search for products.
     */
    public static function search_products(): void {
        self::verify_request();

        try {
            $term = '';
            if (isset($_POST['term'])) {
                $term = (string) $_POST['term'];
            } elseif (isset($_REQUEST['term'])) {
                $term = (string) $_REQUEST['term'];
            } elseif (isset($_REQUEST['keyword'])) {
                $term = (string) $_REQUEST['keyword'];
            }

            if (function_exists('wp_unslash')) {
                $term = wp_unslash($term);
            }

            $term = sanitize_text_field($term);
            $term = trim($term);

            if ($term === '') {
                wp_send_json([
                    'success' => true,
                    'html'    => '<p>' . esc_html__('Please enter a search term.', 'meals-db') . '</p>',
                ]);
            }

            global $wpdb;

            if (!$wpdb instanceof wpdb) {
                wp_send_json([
                    'success' => false,
                    'message' => __('Unable to connect to the database.', 'meals-db'),
                ]);
            }

            if (!function_exists('wc_get_product')) {
                wp_send_json([
                    'success' => false,
                    'message' => __('WooCommerce is required to search for products.', 'meals-db'),
                ]);
            }

            $like_term = '%' . $wpdb->esc_like($term) . '%';

            $sql = $wpdb->prepare(
                "SELECT DISTINCT p.ID, p.post_title, p.post_excerpt, p.post_content, sku.meta_value AS sku
                 FROM {$wpdb->posts} AS p
                 LEFT JOIN {$wpdb->postmeta} AS sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
                 WHERE p.post_type = 'product'
                   AND p.post_status = 'publish'
                   AND (p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s OR sku.meta_value LIKE %s)
                 ORDER BY p.post_title ASC
                 LIMIT 50",
                $like_term,
                $like_term,
                $like_term,
                $like_term
            );

            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (!is_array($rows)) {
                $rows = [];
            }

            $matches = [];
            foreach ($rows as $row) {
                $product_id = isset($row['ID']) ? intval($row['ID']) : 0;
                if ($product_id <= 0) {
                    continue;
                }

                $product = wc_get_product($product_id);
                if (!$product instanceof WC_Product) {
                    continue;
                }

                if (!$product->is_visible() || $product->get_status() !== 'publish') {
                    continue;
                }

                $keywords = self::collect_product_keywords($product);

                $score = self::calculate_search_score($term, [
                    $row['post_title'] ?? '',
                    $row['sku'] ?? '',
                    $row['post_excerpt'] ?? '',
                    $row['post_content'] ?? '',
                    implode(' ', $keywords),
                ]);

                if ($score === null) {
                    continue;
                }

                $payload = MealsDB_Quick_Order_Products::format_for_quick_order([$product]);
                if (empty($payload) || !isset($payload[0])) {
                    continue;
                }

                $matches[] = [
                    'score'   => $score,
                    'product' => $payload[0],
                ];
            }

            usort($matches, static function ($a, $b) {
                $score_compare = $a['score'] <=> $b['score'];
                if ($score_compare !== 0) {
                    return $score_compare;
                }

                $name_a = isset($a['product']['name']) ? (string) $a['product']['name'] : '';
                $name_b = isset($b['product']['name']) ? (string) $b['product']['name'] : '';

                return strcasecmp($name_a, $name_b);
            });

            $products = array_map(static function ($match) {
                return $match['product'];
            }, $matches);

            $html = self::render_product_tiles($products);

            wp_send_json([
                'success' => true,
                'html'    => $html,
            ]);
        } catch (Exception $e) {
            error_log('[MealsDB QuickOrder] search_products error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX endpoint to create a new WooCommerce order for a Meals DB client.
     */
    public static function create_order(): void {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'mealsdb_nonce')) {
            wp_send_json([
                'success' => false,
                'message' => 'Invalid order data.',
            ]);
        }

        self::verify_request(true);

        // WordPress user ID for the client placing the order.
        $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
        $date      = isset($_POST['date']) ? sanitize_text_field(wp_unslash((string) $_POST['date'])) : '';
        $items     = self::normalise_items($_POST['items'] ?? []);
        $order_date = self::parse_order_date($date);

        if (
            $client_id <= 0
            || !$order_date instanceof DateTimeImmutable
            || empty($items)
            || !self::user_exists($client_id)
        ) {
            wp_send_json([
                'success' => false,
                'message' => 'Invalid order data.',
            ]);
        }

        try {
            $order = self::create_wc_order($items, $order_date);
            if (is_wp_error($order)) {
                throw new Exception($order->get_error_message());
            }

            $order->update_meta_data('mealsdb_client_user_id', $client_id);

            // Meals DB client_id from the external meals_clients table for this WordPress user.
            $client_db_id = self::get_active_client_id_for_user($client_id);
            if ($client_db_id > 0) {
                $order->update_meta_data('mealsdb_client_id', $client_db_id);
            }
            $order->save();

            if ($client_db_id > 0 && !self::log_transaction($order, $client_db_id, $order_date)) {
                $order_id = $order->get_id();
                if ($order_id > 0) {
                    wp_trash_post($order_id);
                }

                throw new Exception(__('Failed to record Meals DB transaction.', 'meals-db'));
            }

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
                'message' => 'Order creation failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX endpoint to retrieve items from an existing WooCommerce order.
     */
    public static function clone_order(): void {
        self::verify_request();

        if (!self::woocommerce_is_available()) {
            wp_send_json([
                'success' => false,
                'message' => __('WooCommerce is required to clone orders.', 'meals-db'),
            ]);
        }

        try {
            $source_order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;
            if ($source_order_id <= 0) {
                wp_send_json([
                    'success' => false,
                    'message' => __('An order to clone must be specified.', 'meals-db'),
                ]);
            }

            $source_order = wc_get_order($source_order_id);
            if (!$source_order instanceof WC_Order) {
                wp_send_json([
                    'success' => false,
                    'message' => __('The specified order could not be found.', 'meals-db'),
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
                'message' => 'Server error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX endpoint to retrieve order details and quantities for cloning.
     */
    public static function clone_get_order(): void {
        self::verify_request();

        if (!self::woocommerce_is_available()) {
            wp_send_json([
                'success' => false,
                'message' => __('WooCommerce is required to clone orders.', 'meals-db'),
            ]);
        }

        try {
            $source_order_id = isset($_REQUEST['order_id']) ? absint(wp_unslash($_REQUEST['order_id'])) : 0;
            if ($source_order_id <= 0) {
                wp_send_json([
                    'success' => false,
                    'message' => __('A valid order ID is required.', 'meals-db'),
                ]);
            }

            $source_order = wc_get_order($source_order_id);
            if (!$source_order instanceof WC_Order) {
                wp_send_json([
                    'success' => false,
                    'message' => __('The specified order could not be found.', 'meals-db'),
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
                    $table_name = MealsDB_DB::get_table_name('meals_clients');
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

            wp_send_json([
                'success'     => true,
                'client_id'   => $client_id,
                'client_type' => $client_type,
                'order_date'  => $order_date,
                'items'       => $items,
            ]);
        } catch (Exception $e) {
            error_log('[MealsDB QuickOrder] clone_get_order error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage(),
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
     * Determine whether the provided Meals DB client exists and is active.
     */
    private static function client_is_active(int $client_id): bool {
        if ($client_id <= 0) {
            return false;
        }

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return false;
        }

        $table_name = MealsDB_DB::get_table_name('meals_clients');
        $sql        = sprintf('SELECT active FROM `%s` WHERE client_id = ? LIMIT 1', str_replace('`', '``', $table_name));

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return false;
        }

        $stmt->bind_param('i', $client_id);

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $result = $stmt->get_result();
        $active = false;

        if (MealsDB_DB::is_mysqli_result($result)) {
            $row = $result->fetch_assoc();
            if (isset($row['active'])) {
                $active = (int) $row['active'] === 1;
            }
        }

        $stmt->close();

        return $active;
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

        $table_name = MealsDB_DB::get_table_name('meals_clients');
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

        $table_name = MealsDB_DB::get_table_name('meals_clients');
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
     * Persist the order details in the external Meals DB transactions table.
     */
    private static function log_transaction(WC_Order $order, int $client_id, ?DateTimeImmutable $order_date): bool {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return false;
        }

        $table_name = MealsDB_DB::get_table_name('meals_transactions');

        $sql = sprintf('INSERT INTO `%s` (client_id, order_id, order_date, created_at) VALUES (?, ?, ?, NOW())',
            str_replace('`', '``', $table_name)
        );

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return false;
        }

        $order_date_value = $order_date instanceof DateTimeImmutable
            ? $order_date->format('Y-m-d H:i:s')
            : current_time('mysql');

        $order_id = $order->get_id();

        if (!$stmt->bind_param('iis', $client_id, $order_id, $order_date_value)) {
            $stmt->close();
            return false;
        }

        $executed = $stmt->execute();
        $stmt->close();

        return (bool) $executed;
    }
}
