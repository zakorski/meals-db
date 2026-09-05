<?php
/**
 * Quick Order product data helpers.
 */

defined('ABSPATH') || exit;

class MealsDB_Quick_Order_Products {
    private const ALLOWED_CATEGORY_SLUGS = [
        'main',
        'cereal',
        'dessert',
        'beef-main',
        'chicken-turkey-main',
        'diabetic-main',
        'fish-main',
        'gluten-free-main',
        'low-calorie-main',
        'low-fat-main',
        'low-sodium-main',
        'minced',
        'pork-main',
        'pureed',
        'special-diet',
        'vegan-main',
        'vegetarian-main',
        'muffin',
        'soup',
        'thickened',
    ];
    private const PRODUCTS_TRANSIENT_KEY   = 'mealsdb_qo_all_products';
    private const CATEGORIES_TRANSIENT_KEY = 'mealsdb_qo_all_categories';

    /**
     * Duration in seconds for cached product and category data.
     */
    private const CACHE_TTL = 30 * MINUTE_IN_SECONDS;

    /**
     * Tracks whether hooks have already been registered.
     */
    private static bool $hooks_registered = false;

    /**
     * Retrieve the allowed category slugs for Quick Order filtering.
     *
     * @return array<int, string>
     */
    public static function get_allowed_category_slugs(): array {
        return self::ALLOWED_CATEGORY_SLUGS;
    }

    /**
     * Filter a category list to only include allowed slugs, in the configured order.
     *
     * @param array<int, array<string, mixed>> $categories
     *
     * @return array<int, array<string, mixed>>
     */
    private static function filter_allowed_categories(array $categories): array {
        $allowed = self::get_allowed_category_slugs();
        $filtered = [];

        foreach ($allowed as $slug) {
            foreach ($categories as $category) {
                if (!is_array($category)) {
                    continue;
                }

                $category_slug = isset($category['slug']) ? (string) $category['slug'] : '';
                if ($category_slug !== $slug) {
                    continue;
                }

                $filtered[$slug] = [
                    'id'   => isset($category['id']) ? (int) $category['id'] : 0,
                    'name' => isset($category['name']) ? (string) $category['name'] : '',
                    'slug' => $slug,
                ];
            }
        }

        return array_values($filtered);
    }

    /**
     * Register hooks for keeping Quick Order caches up to date.
     */
    public static function init(): void {
        if (self::$hooks_registered) {
            return;
        }

        self::$hooks_registered = true;

        if (function_exists('add_action')) {
            add_action('save_post_product', [self::class, 'clear_cache_on_product_save'], 10, 2);
            add_action('edited_product_cat', [self::class, 'clear_cache']);
            add_action('mealsdb_plugin_updated', [self::class, 'clear_cache']);

            // U07-quick-order-16: trashing/deleting a product fires
            // trashed_post / before_delete_post, NOT save_post_product, so
            // without these a trashed product lingered orderable in the QO grid
            // for up to CACHE_TTL (30 min). before_delete_post is used (not
            // deleted_post) so get_post_type() still resolves — the row is gone
            // by deleted_post. untrashed_post re-adds a restored product.
            // woocommerce_update_product covers programmatic CRUD/price updates
            // that touch meta without a full wp_update_post save.
            add_action('trashed_post', [self::class, 'clear_cache_on_product_change']);
            add_action('untrashed_post', [self::class, 'clear_cache_on_product_change']);
            add_action('before_delete_post', [self::class, 'clear_cache_on_product_change']);
            add_action('woocommerce_update_product', [self::class, 'clear_cache']);
        }
    }

    /**
     * Clear caches when a product post is trashed, untrashed, or deleted.
     *
     * These generic post hooks fire for every post type, so guard on the
     * product post type to avoid needlessly evicting the QO cache on unrelated
     * content changes. U07-quick-order-16.
     *
     * @param int|string $post_id Post ID.
     */
    public static function clear_cache_on_product_change($post_id): void {
        if (function_exists('get_post_type') && get_post_type((int) $post_id) === 'product') {
            self::clear_cache();
        }
    }

    /**
     * Clear all Quick Order product/category caches.
     */
    public static function clear_cache(): void {
        if (function_exists('delete_transient')) {
            delete_transient(self::PRODUCTS_TRANSIENT_KEY);
            delete_transient(self::CATEGORIES_TRANSIENT_KEY);
        }
    }

    /**
     * Clear caches when a WooCommerce product is saved.
     *
     * @param int          $post_id Post ID.
     * @param WP_Post|null $post    The post object.
     */
    public static function clear_cache_on_product_save(int $post_id, $post = null): void {
        if ($post instanceof WP_Post && $post->post_type !== 'product') {
            return;
        }

        self::clear_cache();
    }

    /**
     * Retrieve product categories that contain published products.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_categories(): array {
        $cached = get_transient(self::CATEGORIES_TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $products = self::get_all_products();
        if (!empty($products)) {
            $categories = self::extract_categories_from_products($products);
            self::set_categories_cache($categories);

            return $categories;
        }

        if (!function_exists('get_terms') || !taxonomy_exists('product_cat')) {
            return [];
        }

        $allowed_slugs = self::get_allowed_category_slugs();
        $args = [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
            'slug'       => $allowed_slugs,
        ];

        if (function_exists('apply_filters')) {
            $args = apply_filters('mealsdb_quick_order_category_args', $args);
        }

        $terms = get_terms($args);
        if (!is_array($terms) || is_wp_error($terms)) {
            return [];
        }

        $categories = [];
        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }

            if (!in_array($term->slug, $allowed_slugs, true)) {
                continue;
            }

            $categories[] = [
                'id'   => (int) $term->term_id,
                // Decode HTML entities WordPress stores in wp_terms.name (e.g.
                // "Chicken &amp; Turkey") so the category tab reads literally.
                // SAFE ONLY because category names are consumed as TEXT (jQuery
                // text:/textContent), never injected as HTML. If a name is ever
                // moved into .html() or a concatenated HTML string, it MUST be
                // escaped at that point or this decode becomes an XSS vector.
                'name' => html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8'),
                'slug' => $term->slug,
            ];
        }

        self::set_categories_cache($categories);

        return $categories;
    }

    /**
     * Retrieve products that belong to a specific category.
     *
     * @param int $cat_id Category term ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_products_by_category(int $cat_id): array {
        $all_products = self::get_all_products();
        if (empty($all_products)) {
            return [];
        }

        $matches = [];
        foreach ($all_products as $product) {
            foreach ($product['categories'] ?? [] as $category) {
                $category_id = isset($category['id']) ? (int) $category['id'] : 0;
                if ($category_id === $cat_id) {
                    $matches[] = $product;
                    break;
                }
            }
        }

        return array_values(array_filter(array_map([self::class, 'product_cache_entry_to_quick_order'], $matches)));
    }

    /**
     * Retrieve all products formatted for the Quick Order UI.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_all_quick_order_products(): array {
        $products = self::get_all_products();
        if (empty($products)) {
            return [];
        }

        return self::inject_stock_figures(self::format_for_quick_order($products));
    }

    /**
     * Directive 2 (ITEMS 1 & 2): attach current + available stock to each
     * product payload.
     *
     *   current_stock   = the WooCommerce _stock figure (null when the product
     *                     does not manage stock).
     *   available_stock = current_stock minus everything committed on
     *                     UNFULFILLED orders (wc-processing / wc-paid). Excludes
     *                     wc-completed (already delivered) and drafts (nothing is
     *                     committed until an order is placed).
     *
     * Computed FRESH on every load — deliberately NOT folded into the 30-minute
     * product cache: "available" answers "can I promise this today", so a stale
     * figure would mislead. Two batched queries total (stock map + committed
     * map), regardless of catalogue size.
     *
     * @param array<int, array<string, mixed>> $products Formatted QO payloads.
     * @return array<int, array<string, mixed>>
     */
    private static function inject_stock_figures(array $products): array {
        if (empty($products)) {
            return $products;
        }

        $product_ids = [];
        foreach ($products as $product) {
            $pid = isset($product['product_id']) ? (int) $product['product_id'] : 0;
            if ($pid > 0) {
                $product_ids[] = $pid;
            }
        }
        $product_ids = array_values(array_unique($product_ids));
        if (empty($product_ids)) {
            return $products;
        }

        $stock_map     = self::get_current_stock_map($product_ids);
        $committed_map = self::get_committed_quantities($product_ids);

        foreach ($products as &$product) {
            $pid = isset($product['product_id']) ? (int) $product['product_id'] : 0;

            // Null current stock = product does not manage stock; leave both
            // null so the UI can render "—" and skip the out-of-stock colour.
            if ($pid <= 0 || !array_key_exists($pid, $stock_map) || $stock_map[$pid] === null) {
                $product['current_stock']   = null;
                $product['available_stock'] = null;
                continue;
            }

            $current   = (int) $stock_map[$pid];
            $committed = isset($committed_map[$pid]) ? (int) $committed_map[$pid] : 0;

            $product['current_stock']   = $current;
            $product['available_stock'] = $current - $committed;
        }
        unset($product);

        return $products;
    }

    /**
     * Batch-fetch the WooCommerce _stock value for the given product IDs.
     * Products remain in wp_posts/wp_postmeta under HPOS (HPOS moves ORDERS,
     * not products), so _stock lives in postmeta. A product with stock
     * management off (_manage_stock != 'yes', or no _stock row) maps to null.
     *
     * @param int[] $product_ids
     * @return array<int, int|null> product_id => current stock (or null)
     */
    private static function get_current_stock_map(array $product_ids): array {
        global $wpdb;
        $map = [];
        if (empty($product_ids) || !isset($wpdb)) {
            return $map;
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT sm.post_id AS product_id, sm.meta_value AS stock
             FROM {$wpdb->postmeta} sm
             INNER JOIN {$wpdb->postmeta} mm
                     ON mm.post_id = sm.post_id AND mm.meta_key = '_manage_stock'
             WHERE sm.meta_key = '_stock'
               AND mm.meta_value = 'yes'
               AND sm.post_id IN ({$placeholders})",
            $product_ids
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $pid = (int) $row['product_id'];
                // _stock can be '' for a manage-stock product mid-configuration;
                // treat empty as null (untracked) rather than 0-in-stock.
                $map[$pid] = ($row['stock'] === null || $row['stock'] === '')
                    ? null
                    : (int) $row['stock'];
            }
        }

        return $map;
    }

    /**
     * Batch-sum quantities committed on UNFULFILLED orders per product.
     * Unfulfilled = wc-processing / wc-paid (placed, not yet delivered). Excludes
     * wc-completed and every draft/cancelled status. Models the PO-forecast
     * quantity roll-up in class-reports.php.
     *
     * @param int[] $product_ids
     * @return array<int, int> product_id => committed quantity
     */
    private static function get_committed_quantities(array $product_ids): array {
        global $wpdb;
        $map = [];
        if (empty($product_ids) || !isset($wpdb)) {
            return $map;
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT CAST(pm.meta_value AS UNSIGNED) AS product_id,
                    SUM(CAST(qm.meta_value AS DECIMAL(10,2))) AS committed_qty
             FROM {$wpdb->prefix}woocommerce_order_items oi
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pm
                     ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qm
                     ON qm.order_item_id = oi.order_item_id AND qm.meta_key = '_qty'
             INNER JOIN {$wpdb->prefix}wc_orders o
                     ON o.id = oi.order_id
                    AND o.type = 'shop_order'
                    AND o.status IN ('wc-processing', 'wc-paid')
             WHERE oi.order_item_type = 'line_item'
               AND CAST(pm.meta_value AS UNSIGNED) IN ({$placeholders})
             GROUP BY product_id",
            $product_ids
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $map[(int) $row['product_id']] = (int) round((float) $row['committed_qty']);
            }
        }

        return $map;
    }

    /**
     * Search published products by keyword.
     *
     * @param string $keyword Search keyword.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search_products(string $keyword): array {
        $keyword = self::sanitize_keyword($keyword);
        if ($keyword === '') {
            return [];
        }

        $all_products = self::get_all_products();
        if (empty($all_products)) {
            return [];
        }

        $keyword_lower = function_exists('mb_strtolower') ? mb_strtolower($keyword) : strtolower($keyword);

        $matches = [];
        foreach ($all_products as $product) {
            $haystacks = [
                isset($product['name']) ? (string) $product['name'] : '',
                isset($product['sku']) ? (string) $product['sku'] : '',
            ];

            foreach ($haystacks as $field) {
                if ($field === '') {
                    continue;
                }

                if (stripos($field, $keyword_lower) !== false) {
                    $matches[] = $product;
                    break;
                }
            }
        }

        usort($matches, static function ($a, $b) {
            $name_a = isset($a['name']) ? (string) $a['name'] : '';
            $name_b = isset($b['name']) ? (string) $b['name'] : '';

            return strcasecmp($name_a, $name_b);
        });

        $matches = array_slice($matches, 0, 20);

        // Directive 2 (ITEMS 1 & 2): searched tiles carry the same live stock
        // figures as the browsed grid, so a product looked up by name shows the
        // same current/available counts and out-of-stock colour.
        return self::inject_stock_figures(
            array_values(array_filter(array_map([self::class, 'product_cache_entry_to_quick_order'], $matches)))
        );
    }

    /**
     * Convert product objects into arrays that can be serialised to JSON.
     *
     * @param array<int, mixed> $products Array of products or already formatted rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function format_for_quick_order(array $products): array {
        $formatted = [];

        foreach ($products as $product) {
            $payload = self::prepare_product_payload($product);
            if (!empty($payload)) {
                $formatted[] = $payload;
            }
        }

        return $formatted;
    }

    /**
     * Build the payload for a product that will be returned to JavaScript.
     *
     * @param WC_Product $product Product instance.
     */
    private static function prepare_product_payload($product): array {
        if (is_object($product) && is_a($product, 'WC_Product')) {
            $cache_entry = self::build_product_cache_entry_from_wc_product($product);
            if (empty($cache_entry)) {
                return [];
            }

            return self::product_cache_entry_to_quick_order($cache_entry);
        }

        if (is_array($product) && self::is_cached_product_entry($product)) {
            return self::product_cache_entry_to_quick_order($product);
        }

        if (is_array($product)) {
            return $product;
        }

        return [];
    }

    /**
     * Sanitise search keyword input.
     */
    private static function sanitize_keyword(string $keyword): string {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return '';
        }

        if (function_exists('wc_clean')) {
            return wc_clean($keyword);
        }

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($keyword);
        }

        return $keyword;
    }

    /**
     * Retrieve all products, using a transient cache when available.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function get_all_products(): array {
        $cached = get_transient(self::PRODUCTS_TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $products = MealsDB_Products_Loader::load_all_products();
        if (!is_array($products)) {
            return [];
        }

        $normalized = [];
        foreach ($products as $product) {
            if (!self::is_cached_product_entry($product)) {
                continue;
            }

            $normalized[$product['id']] = $product;
        }

        set_transient(self::PRODUCTS_TRANSIENT_KEY, $normalized, self::CACHE_TTL);

        $categories = self::extract_categories_from_products($normalized);
        if (!empty($categories)) {
            self::set_categories_cache($categories);
        }

        return $normalized;
    }

    /**
     * Build a cache entry for a product.
     *
     * @param WC_Product $product Product instance.
     */
    private static function build_product_cache_entry_from_wc_product(WC_Product $product): array {
        $product_id = $product->get_id();

        // WooCommerce is necessarily loaded here (the param is type-hinted
        // WC_Product), so wc_get_price_to_display() is always available; the
        // 0.0 fallback only guards the theoretically-unreachable no-WC path.
        $price_value = 0.0;
        if (function_exists('wc_get_price_to_display')) {
            $price_value = (float) wc_get_price_to_display($product);
        }

        $image_id = $product->get_image_id();
        $image_url = '';
        if ($image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'medium');
        }

        if (!$image_url) {
            $image_url = function_exists('wc_placeholder_img_src') ? wc_placeholder_img_src() : '';
        }

        $categories = self::get_product_categories($product_id);
        $metadata   = MealsDB_Products::get_product_data($product_id);

        return array_merge(
            [
                'id'         => $product_id,
                'name'       => $product->get_name(),
                'price'      => $price_value,
                'image'      => $image_url,
                'sku'        => $product->get_sku(),
                'categories' => $categories,
            ],
            self::normalize_metadata($metadata, $product_id, $product->get_name())
        );
    }

    /**
     * Retrieve categories for a given product ID.
     *
     * @param int $product_id Product ID.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function get_product_categories(int $product_id): array {
        $terms = get_the_terms($product_id, 'product_cat');
        if (!is_array($terms) || empty($terms)) {
            return [];
        }

        $categories = [];
        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }

            $categories[] = [
                'id'   => (int) $term->term_id,
                // Decode HTML entities (e.g. "Chicken &amp; Turkey") — this
                // feeds the per-product category.name embedded in every QO
                // product payload. SAFE ONLY because category names are consumed
                // as TEXT (jQuery text:/textContent), never as HTML; buildProductTileHTML
                // touches only category.slug/id. Escape at the point of use if a
                // name is ever placed into an HTML string.
                'name' => html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8'),
                'slug' => $term->slug,
            ];
        }

        return self::filter_allowed_categories($categories);
    }

    /**
     * Determine whether an array matches the cached product schema.
     */
    private static function is_cached_product_entry(array $product): bool {
        return isset($product['id'], $product['name'], $product['price'], $product['image'], $product['categories']);
    }

    /**
     * Normalise Meals DB product metadata fields for cache entries.
     *
     * @param array<string, mixed> $metadata
     */
    private static function normalize_metadata(array $metadata, int $product_id, string $product_name): array {
        $defaults = [
            'wc_product_id'   => $product_id,
            'product_name'    => $product_name,
            'product_type'    => 'meal',
            'taxable'         => 0,
            'main_ingredient' => '',
            'dietary_tags'    => [],
            'allergen_flags'  => [],
            'case_size'       => 1,
            'unit_cost'       => '0.00',
        ];

        $merged = array_merge($defaults, $metadata);

        $dietary_tags = is_array($merged['dietary_tags']) ? array_values($merged['dietary_tags']) : [];
        $allergens    = is_array($merged['allergen_flags']) ? array_values($merged['allergen_flags']) : [];

        return [
            'wc_product_id'   => (int) $merged['wc_product_id'],
            'product_name'    => (string) $merged['product_name'],
            'product_type'    => in_array($merged['product_type'], ['meal', 'side'], true)
                ? (string) $merged['product_type']
                : 'meal',
            'taxable'         => (int) (!empty($merged['taxable'])),
            'main_ingredient' => isset($merged['main_ingredient']) ? (string) $merged['main_ingredient'] : '',
            'dietary_tags'    => $dietary_tags,
            'allergen_flags'  => $allergens,
            'case_size'       => isset($merged['case_size']) ? (int) $merged['case_size'] : 1,
            'unit_cost'       => isset($merged['unit_cost']) ? (string) $merged['unit_cost'] : '0.00',
        ];
    }

    /**
     * Convert a cached product entry into the payload expected by the UI.
     */
    private static function product_cache_entry_to_quick_order(array $product): array {
        if (!self::is_cached_product_entry($product)) {
            return [];
        }

        $category = null;
        $product_categories = self::filter_allowed_categories($product['categories'] ?? []);
        $category_slugs = [];
        if (!empty($product_categories)) {
            foreach ($product_categories as $category_entry) {
                $slug = isset($category_entry['slug']) ? (string) $category_entry['slug'] : '';
                if ($slug !== '') {
                    $category_slugs[] = $slug;
                }
            }

            $primary = $product_categories[0];
            $category = [
                'id'   => isset($primary['id']) ? (int) $primary['id'] : 0,
                'name' => isset($primary['name']) ? (string) $primary['name'] : '',
                'slug' => isset($primary['slug']) ? (string) $primary['slug'] : '',
            ];
        }

        return [
            'product_id' => (int) $product['id'],
            'name'       => (string) $product['name'],
            'category'   => $category,
            'category_slugs' => $category_slugs,
            'price'      => isset($product['price']) ? (float) $product['price'] : 0.0,
            'image_url'  => isset($product['image']) ? (string) $product['image'] : '',
            'sku'        => isset($product['sku']) ? (string) $product['sku'] : '',
            'product_type'    => isset($product['product_type']) ? (string) $product['product_type'] : 'meal',
            'taxable'         => isset($product['taxable']) ? (int) $product['taxable'] : 0,
            'main_ingredient' => isset($product['main_ingredient']) ? (string) $product['main_ingredient'] : '',
            'dietary_tags'    => isset($product['dietary_tags']) && is_array($product['dietary_tags']) ? $product['dietary_tags'] : [],
            'allergen_flags'  => isset($product['allergen_flags']) && is_array($product['allergen_flags']) ? $product['allergen_flags'] : [],
            'case_size'       => isset($product['case_size']) ? (int) $product['case_size'] : 1,
            'unit_cost'       => isset($product['unit_cost']) ? (string) $product['unit_cost'] : '0.00',
        ];
    }

    /**
     * Extract unique categories from cached product data.
     *
     * @param array<int, array<string, mixed>> $products Cached product map.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function extract_categories_from_products(array $products): array {
        $categories = [];

        foreach ($products as $product) {
            $product_categories = self::filter_allowed_categories($product['categories'] ?? []);
            foreach ($product_categories as $category) {
                $id = isset($category['id']) ? (int) $category['id'] : 0;
                if ($id <= 0) {
                    continue;
                }

                if (!isset($categories[$id])) {
                    $categories[$id] = [
                        'id'   => $id,
                        'name' => $category['name'] ?? '',
                        'slug' => $category['slug'] ?? '',
                    ];
                }
            }
        }

        usort($categories, static function ($a, $b) {
            return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
        });

        return array_values($categories);
    }

    /**
     * Store category data in a transient.
     *
     * @param array<int, array<string, mixed>> $categories Category list.
     */
    private static function set_categories_cache(array $categories): void {
        set_transient(self::CATEGORIES_TRANSIENT_KEY, $categories, self::CACHE_TTL);
    }
}

// NOTE: Hook registration is triggered explicitly from
// meals-db-main.php's plugins_loaded action, matching the pattern
// used by every other class in the plugin. A previous version
// auto-init'd here at file scope (when the autoloader loaded the
// class), which worked because init() was idempotent but made
// load order implicit and inconsistent with the rest of the codebase.
