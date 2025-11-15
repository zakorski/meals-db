<?php
/**
 * AJAX handlers for Meals DB Quick Order feature.
 */

class MealsDB_Quick_Order_Ajax {
    /**
     * Register AJAX actions related to the Quick Order UI.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_qo_find_clients', [self::class, 'find_clients']);
        add_action('wp_ajax_mealsdb_qo_get_categories', [self::class, 'get_categories']);
        add_action('wp_ajax_mealsdb_qo_get_products_by_category', [self::class, 'get_products_by_category']);
        add_action('wp_ajax_mealsdb_qo_search_products', [self::class, 'search_products']);
        add_action('wp_ajax_mealsdb_qo_create_order', [self::class, 'create_order']);
        add_action('wp_ajax_mealsdb_qo_clone_order', [self::class, 'clone_order']);
    }

    /**
     * AJAX endpoint for finding clients.
     */
    public static function find_clients(): void {
        self::verify_request();

        $search = isset($_REQUEST['search']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['search'])) : '';
        $search = trim($search);

        if ($search === '') {
            wp_send_json_success([
                'clients' => [],
            ]);
        }

        $conn = MealsDB_DB::get_connection();
        if (!$conn instanceof mysqli) {
            wp_send_json_error([
                'message' => __('Unable to connect to the Meals DB database.', 'meals-db'),
            ]);
        }

        $like = '%' . strtolower($search) . '%';
        $sql = 'SELECT id, first_name, last_name, customer_type, client_email FROM meals_clients WHERE active = 1 AND (
            LOWER(first_name) LIKE ? OR LOWER(last_name) LIKE ? OR LOWER(CONCAT(first_name, " ", last_name)) LIKE ? OR LOWER(client_email) LIKE ?
        ) ORDER BY last_name ASC, first_name ASC LIMIT 20';

        $stmt = $conn->prepare($sql);
        if (!$stmt instanceof mysqli_stmt) {
            wp_send_json_error([
                'message' => __('Failed to prepare client lookup.', 'meals-db'),
            ]);
        }

        if (!$stmt->bind_param('ssss', $like, $like, $like, $like)) {
            $stmt->close();
            wp_send_json_error([
                'message' => __('Failed to bind parameters for client lookup.', 'meals-db'),
            ]);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            wp_send_json_error([
                'message' => __('Failed to execute client lookup.', 'meals-db'),
            ]);
        }

        $result = $stmt->get_result();
        $clients = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $client_id = isset($row['id']) ? (int) $row['id'] : 0;
                if ($client_id <= 0) {
                    continue;
                }

                $first_name = isset($row['first_name']) ? (string) $row['first_name'] : '';
                $last_name  = isset($row['last_name']) ? (string) $row['last_name'] : '';
                $name = trim($first_name . ' ' . $last_name);
                if ($name === '') {
                    $name = sprintf(__('Client #%d', 'meals-db'), $client_id);
                }

                $clients[] = [
                    'id'            => $client_id,
                    'name'          => $name,
                    'first_name'    => $first_name,
                    'last_name'     => $last_name,
                    'customer_type' => isset($row['customer_type']) ? (string) $row['customer_type'] : '',
                    'email'         => isset($row['client_email']) ? (string) $row['client_email'] : '',
                ];
            }
        }

        $stmt->close();

        wp_send_json_success([
            'clients' => $clients,
        ]);
    }

    /**
     * AJAX endpoint to fetch product categories.
     */
    public static function get_categories(): void {
        self::verify_request();

        $categories = MealsDB_Quick_Order_Products::get_categories();
        wp_send_json_success([
            'categories' => $categories,
        ]);
    }

    /**
     * AJAX endpoint to fetch products by category.
     */
    public static function get_products_by_category(): void {
        self::verify_request();

        $category_id = isset($_REQUEST['category_id']) ? intval($_REQUEST['category_id']) : 0;
        if ($category_id <= 0) {
            wp_send_json_error([
                'message' => __('Invalid category.', 'meals-db'),
            ]);
        }

        $products = MealsDB_Quick_Order_Products::get_products_by_category($category_id);
        wp_send_json_success([
            'products' => $products,
        ]);
    }

    /**
     * AJAX endpoint to search for products.
     */
    public static function search_products(): void {
        self::verify_request();

        $keyword = isset($_REQUEST['keyword']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['keyword'])) : '';
        $products = MealsDB_Quick_Order_Products::search_products($keyword);

        wp_send_json_success([
            'products' => $products,
        ]);
    }

    /**
     * AJAX endpoint to create a new WooCommerce order for a Meals DB client.
     */
    public static function create_order(): void {
        self::verify_request();

        $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
        if ($client_id <= 0) {
            wp_send_json_error([
                'message' => __('A valid client is required to create an order.', 'meals-db'),
            ]);
        }

        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash((string) $_POST['date'])) : '';
        $order_date = self::parse_order_date($date);

        $items = self::normalise_items($_POST['items'] ?? []);
        if (empty($items)) {
            wp_send_json_error([
                'message' => __('At least one product must be supplied.', 'meals-db'),
            ]);
        }

        $order = self::create_wc_order($items, $order_date);
        if (is_wp_error($order)) {
            wp_send_json_error([
                'message' => $order->get_error_message(),
            ]);
        }

        $order->update_meta_data('mealsdb_client_id', $client_id);
        $order->save();

        if (!self::log_transaction($order, $client_id, $order_date)) {
            $order_id = $order->get_id();
            if ($order_id > 0) {
                wp_trash_post($order_id);
            }

            wp_send_json_error([
                'message' => __('Failed to record Meals DB transaction.', 'meals-db'),
            ]);
        }

        wp_send_json_success([
            'order_id' => $order->get_id(),
            'message'  => __('Order created successfully.', 'meals-db'),
        ]);
    }

    /**
     * AJAX endpoint to clone an existing WooCommerce order.
     */
    public static function clone_order(): void {
        self::verify_request();

        $source_order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if ($source_order_id <= 0) {
            wp_send_json_error([
                'message' => __('An order to clone must be specified.', 'meals-db'),
            ]);
        }

        $source_order = wc_get_order($source_order_id);
        if (!$source_order instanceof WC_Order) {
            wp_send_json_error([
                'message' => __('The specified order could not be found.', 'meals-db'),
            ]);
        }

        $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
        if ($client_id <= 0) {
            $client_id = intval($source_order->get_meta('mealsdb_client_id'));
        }

        if ($client_id <= 0) {
            wp_send_json_error([
                'message' => __('A client could not be determined for the cloned order.', 'meals-db'),
            ]);
        }

        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash((string) $_POST['date'])) : '';
        $order_date = self::parse_order_date($date);

        $items = [];
        foreach ($source_order->get_items('line_item') as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();
            if (!$product instanceof WC_Product) {
                continue;
            }

            $items[] = [
                'product_id'   => $product->get_id(),
                'variation_id' => $item->get_variation_id(),
                'quantity'     => (int) $item->get_quantity(),
            ];
        }

        if (empty($items)) {
            wp_send_json_error([
                'message' => __('No products were found on the source order.', 'meals-db'),
            ]);
        }

        $order = self::create_wc_order($items, $order_date);
        if (is_wp_error($order)) {
            wp_send_json_error([
                'message' => $order->get_error_message(),
            ]);
        }

        $order->update_meta_data('mealsdb_client_id', $client_id);
        $order->save();

        if (!self::log_transaction($order, $client_id, $order_date)) {
            $order_id = $order->get_id();
            if ($order_id > 0) {
                wp_trash_post($order_id);
            }

            wp_send_json_error([
                'message' => __('Failed to record Meals DB transaction.', 'meals-db'),
            ]);
        }

        wp_send_json_success([
            'order_id' => $order->get_id(),
            'message'  => __('Order cloned successfully.', 'meals-db'),
        ]);
    }

    /**
     * Ensure the AJAX request is valid and user is authorised.
     */
    private static function verify_request(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!self::current_user_can_access_quick_order()) {
            wp_send_json_error([
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
        if (!$conn instanceof mysqli) {
            return false;
        }

        $table_name = MealsDB_DB::get_table_name('meals_transactions');

        $sql = sprintf('INSERT INTO `%s` (client_id, order_id, order_date, created_at) VALUES (?, ?, ?, NOW())',
            str_replace('`', '``', $table_name)
        );

        $stmt = $conn->prepare($sql);
        if (!$stmt instanceof mysqli_stmt) {
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
