<?php
/**
 * Quick Order product data helpers.
 */

class MealsDB_Quick_Order_Products {
    private const ALLOWED_CATEGORY_SLUGS = [
        'main',
        'cereal',
        'dessert',
        'beef',
        'chicken-turkey',
        'diabetic',
        'fish',
        'gluten-free',
        'low-calorie',
        'low-fat',
        'low-sodium',
        'minced',
        'pork',
        'pureed',
        'special-diet',
        'vegan',
        'vegetarian',
        'muffin',
        'soup',
        'thickened',
    ];
    private const CATEGORIES_TRANSIENT_KEY = 'mealsdb_qo_all_categories';

    /**
     * Duration in seconds for cached category data.
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
        }
    }

    /**
     * Clear all Quick Order product/category caches.
     */
    public static function clear_cache(): void {
        if (function_exists('delete_transient')) {
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
     * Uses the lightweight WP taxonomy query as the primary path rather
     * than loading all products just to extract categories.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_categories(): array {
        $cached = get_transient(self::CATEGORIES_TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        // Lightweight taxonomy query.
        if (function_exists('get_terms') && taxonomy_exists('product_cat')) {
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
            if (is_array($terms) && !is_wp_error($terms)) {
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
                        'name' => $term->name,
                        'slug' => $term->slug,
                    ];
                }

                if (!empty($categories)) {
                    self::set_categories_cache($categories);

                    return $categories;
                }
            }
        }

        return [];
    }

    /**
     * Retrieve products that belong to a specific category.
     *
     * Queries WooCommerce directly with a bounded limit instead of
     * loading the entire product catalog into memory.
     *
     * @param int $cat_id Category term ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_products_by_category(int $cat_id): array {
        $term = get_term($cat_id, 'product_cat');
        if (!$term instanceof WP_Term) {
            return [];
        }

        if (!function_exists('wc_get_products')) {
            return [];
        }

        $products = wc_get_products([
            'status'   => 'publish',
            'limit'    => 200,
            'orderby'  => 'title',
            'order'    => 'ASC',
            'return'   => 'objects',
            'category' => [$term->slug],
        ]);

        if (!is_array($products) || empty($products)) {
            return [];
        }

        $formatted = [];
        $product_ids = [];
        foreach ($products as $product) {
            if ($product instanceof WC_Product) {
                $product_ids[] = $product->get_id();
            }
        }

        $metadata_batch = MealsDB_Products_Loader::batch_get_product_data($product_ids);

        foreach ($products as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }

            $entry = self::build_product_cache_entry_from_wc_product($product, $metadata_batch);
            if (!empty($entry)) {
                $formatted[] = self::product_cache_entry_to_quick_order($entry);
            }
        }

        return array_values(array_filter($formatted));
    }

    /**
     * Search published products by keyword.
     *
     * Queries WooCommerce directly with a bounded limit instead of
     * loading the entire product catalog into memory.
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

        if (!function_exists('wc_get_products')) {
            return [];
        }

        $allowed_slugs = self::get_allowed_category_slugs();

        $products = wc_get_products([
            'status'   => 'publish',
            'limit'    => 20,
            'orderby'  => 'title',
            'order'    => 'ASC',
            'return'   => 'objects',
            'category' => $allowed_slugs,
            's'        => $keyword,
        ]);

        if (!is_array($products) || empty($products)) {
            return [];
        }

        $product_ids = [];
        foreach ($products as $product) {
            if ($product instanceof WC_Product) {
                $product_ids[] = $product->get_id();
            }
        }

        $metadata_batch = MealsDB_Products_Loader::batch_get_product_data($product_ids);

        $formatted = [];
        foreach ($products as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }

            $entry = self::build_product_cache_entry_from_wc_product($product, $metadata_batch);
            if (!empty($entry)) {
                $formatted[] = self::product_cache_entry_to_quick_order($entry);
            }
        }

        return array_values(array_filter($formatted));
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
     * Build a cache entry for a product.
     *
     * @param WC_Product                         $product        Product instance.
     * @param array<int, array<string, mixed>>   $metadata_batch Optional pre-fetched metadata keyed by product ID.
     */
    private static function build_product_cache_entry_from_wc_product(WC_Product $product, array $metadata_batch = []): array {
        $product_id = $product->get_id();

        $price = $product->get_price();
        if ($price === '') {
            $price_value = 0.0;
        } else {
            $price_value = (float) $price;
        }

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
        $metadata   = isset($metadata_batch[$product_id])
            ? $metadata_batch[$product_id]
            : MealsDB_Products::get_product_data($product_id);

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
                'name' => $term->name,
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
     * Store category data in a transient.
     *
     * @param array<int, array<string, mixed>> $categories Category list.
     */
    private static function set_categories_cache(array $categories): void {
        set_transient(self::CATEGORIES_TRANSIENT_KEY, $categories, self::CACHE_TTL);
    }
}

MealsDB_Quick_Order_Products::init();
