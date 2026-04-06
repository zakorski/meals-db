<?php
/**
 * Quick Order product data helpers.
 */

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
     * Derives the category list from the cached category_data column in
     * meals_products rather than querying the WP taxonomy API.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_categories(): array {
        $cached = get_transient(self::CATEGORIES_TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return self::get_categories_fallback();
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $table = str_replace('`', '``', $table);

        $sql    = "SELECT category_data FROM `{$table}` WHERE is_published = 1 AND category_data IS NOT NULL";
        $result = $conn->query($sql);

        if (!MealsDB_DB::is_mysqli_result($result)) {
            return self::get_categories_fallback();
        }

        $allowed_slugs = self::get_allowed_category_slugs();
        $seen          = [];

        while ($row = $result->fetch_assoc()) {
            if (!is_array($row) || empty($row['category_data'])) {
                continue;
            }

            $cats = json_decode($row['category_data'], true);
            if (!is_array($cats)) {
                continue;
            }

            foreach ($cats as $cat) {
                if (!is_array($cat) || !isset($cat['slug'])) {
                    continue;
                }

                $slug = (string) $cat['slug'];
                if (!in_array($slug, $allowed_slugs, true) || isset($seen[$slug])) {
                    continue;
                }

                $seen[$slug] = [
                    'id'   => isset($cat['id']) ? (int) $cat['id'] : 0,
                    'name' => isset($cat['name']) ? (string) $cat['name'] : '',
                    'slug' => $slug,
                ];
            }
        }
        $result->free();

        if (empty($seen)) {
            return self::get_categories_fallback();
        }

        // Order by the allowed slugs list.
        $categories = [];
        foreach ($allowed_slugs as $slug) {
            if (isset($seen[$slug])) {
                $categories[] = $seen[$slug];
            }
        }

        if (!empty($categories)) {
            self::set_categories_cache($categories);
        }

        return $categories;
    }

    /**
     * Fallback to WP taxonomy query if meals_products has no data yet.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function get_categories_fallback(): array {
        if (!function_exists('get_terms') || !taxonomy_exists('product_cat')) {
            return [];
        }

        $allowed_slugs = self::get_allowed_category_slugs();
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
            'slug'       => $allowed_slugs,
        ]);

        if (!is_array($terms) || is_wp_error($terms)) {
            return [];
        }

        $categories = [];
        foreach ($terms as $term) {
            if (!$term instanceof WP_Term || !in_array($term->slug, $allowed_slugs, true)) {
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
        }

        return $categories;
    }

    /**
     * Retrieve products that belong to a specific category.
     *
     * Queries the meals_products cache table filtered by category_data JSON.
     *
     * @param int $cat_id Category term ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_products_by_category(int $cat_id): array {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return [];
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $table = str_replace('`', '``', $table);

        // Use JSON_CONTAINS to find rows where category_data contains an object with matching id.
        $sql = "SELECT * FROM `{$table}` WHERE is_published = 1 AND JSON_CONTAINS(category_data, ?, '$') ORDER BY product_name ASC LIMIT 200";

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return [];
        }

        $search_json = json_encode(['id' => $cat_id]);
        $stmt->bind_param('s', $search_json);

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        $stmt->close();

        if (!MealsDB_DB::is_mysqli_result($result)) {
            return [];
        }

        return self::rows_to_quick_order_payload($result);
    }

    /**
     * Retrieve all published products across allowed categories.
     *
     * Queries the meals_products cache table for all published rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_all_products(): array {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return [];
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $table = str_replace('`', '``', $table);

        $sql    = "SELECT * FROM `{$table}` WHERE is_published = 1 AND category_data IS NOT NULL ORDER BY product_name ASC LIMIT 500";
        $result = $conn->query($sql);

        if (!MealsDB_DB::is_mysqli_result($result)) {
            return [];
        }

        return self::rows_to_quick_order_payload($result);
    }

    /**
     * Search published products by keyword.
     *
     * Queries the meals_products cache table using product_name LIKE.
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

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return [];
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $table = str_replace('`', '``', $table);

        $sql  = "SELECT * FROM `{$table}` WHERE is_published = 1 AND category_data IS NOT NULL AND product_name LIKE ? ORDER BY product_name ASC LIMIT 20";
        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return [];
        }

        $like = '%' . $keyword . '%';
        $stmt->bind_param('s', $like);

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        $stmt->close();

        if (!MealsDB_DB::is_mysqli_result($result)) {
            return [];
        }

        return self::rows_to_quick_order_payload($result);
    }

    /**
     * Convert a mysqli_result set of meals_products rows into the Quick Order payload.
     *
     * @param mysqli_result $result Result set from a meals_products query.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rows_to_quick_order_payload(mysqli_result $result): array {
        $formatted = [];

        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }

            $entry = self::db_row_to_quick_order($row);
            if (!empty($entry)) {
                $formatted[] = $entry;
            }
        }

        $result->free();
        return $formatted;
    }

    /**
     * Convert a single meals_products DB row into the Quick Order UI payload.
     *
     * @param array<string, mixed> $row Associative array from meals_products.
     *
     * @return array<string, mixed>
     */
    private static function db_row_to_quick_order(array $row): array {
        $product_id = isset($row['wc_product_id']) ? (int) $row['wc_product_id'] : 0;
        if ($product_id <= 0) {
            return [];
        }

        $categories = [];
        if (!empty($row['category_data'])) {
            $decoded = is_string($row['category_data']) ? json_decode($row['category_data'], true) : $row['category_data'];
            if (is_array($decoded)) {
                $categories = $decoded;
            }
        }

        $product_categories = self::filter_allowed_categories($categories);
        $category_slugs     = [];
        $primary_category   = null;

        if (!empty($product_categories)) {
            foreach ($product_categories as $cat) {
                $slug = isset($cat['slug']) ? (string) $cat['slug'] : '';
                if ($slug !== '') {
                    $category_slugs[] = $slug;
                }
            }

            $primary = $product_categories[0];
            $primary_category = [
                'id'   => isset($primary['id']) ? (int) $primary['id'] : 0,
                'name' => isset($primary['name']) ? (string) $primary['name'] : '',
                'slug' => isset($primary['slug']) ? (string) $primary['slug'] : '',
            ];
        }

        $dietary_tags  = [];
        $allergen_flags = [];

        if (!empty($row['dietary_tags'])) {
            $decoded = is_string($row['dietary_tags']) ? json_decode($row['dietary_tags'], true) : $row['dietary_tags'];
            if (is_array($decoded)) {
                $dietary_tags = array_values($decoded);
            }
        }

        if (!empty($row['allergen_flags'])) {
            $decoded = is_string($row['allergen_flags']) ? json_decode($row['allergen_flags'], true) : $row['allergen_flags'];
            if (is_array($decoded)) {
                $allergen_flags = array_values($decoded);
            }
        }

        return [
            'product_id'      => $product_id,
            'name'            => isset($row['product_name']) ? (string) $row['product_name'] : '',
            'category'        => $primary_category,
            'category_slugs'  => $category_slugs,
            'price'           => isset($row['price']) ? (float) $row['price'] : 0.0,
            'image_url'       => isset($row['image_url']) ? (string) $row['image_url'] : '',
            'sku'             => isset($row['sku']) ? (string) $row['sku'] : '',
            'product_type'    => isset($row['product_type']) && in_array($row['product_type'], ['meal', 'side'], true) ? (string) $row['product_type'] : 'meal',
            'taxable'         => isset($row['taxable']) ? (int) $row['taxable'] : 0,
            'main_ingredient' => isset($row['main_ingredient']) ? (string) $row['main_ingredient'] : '',
            'dietary_tags'    => $dietary_tags,
            'allergen_flags'  => $allergen_flags,
            'case_size'       => isset($row['case_size']) ? (int) $row['case_size'] : 1,
            'unit_cost'       => isset($row['unit_cost']) ? (string) $row['unit_cost'] : '0.00',
        ];
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
