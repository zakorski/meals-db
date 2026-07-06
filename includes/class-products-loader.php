<?php
/**
 * Helper for loading product metadata from the Meals DB tables.
 */

defined('ABSPATH') || exit;

class MealsDB_Products_Loader {
    /**
     * Load WooCommerce products with metadata sourced from the Meals DB.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function load_all_products(): array {
        if (!function_exists('wc_get_products')) {
            return [];
        }

        $allowed_slugs = MealsDB_Quick_Order_Products::get_allowed_category_slugs();

        $args = [
            'status'   => 'publish',
            'limit'    => -1,
            'orderby'  => 'title',
            'order'    => 'ASC',
            'return'   => 'objects',
            'category' => $allowed_slugs,
        ];

        if (function_exists('apply_filters')) {
            $args = apply_filters('mealsdb_products_loader_args', $args);
        }

        $products = wc_get_products($args);
        if (!is_array($products)) {
            return [];
        }

        // Collect product IDs for batch metadata fetch.
        $product_ids = [];
        foreach ($products as $product) {
            if ($product instanceof WC_Product) {
                $product_ids[] = $product->get_id();
            }
        }

        $metadata_batch = self::batch_get_product_data($product_ids);

        // Prime WP caches in bulk before the per-product loop. Without
        // this each iteration runs a separate query for thumbnail meta
        // and another set for product term assignments — N+1 across
        // potentially hundreds of products.
        if (!empty($product_ids)) {
            if (function_exists('update_post_thumbnail_cache')) {
                $thumb_query = new WP_Query([
                    'post_type'      => 'product',
                    'post__in'       => $product_ids,
                    'posts_per_page' => count($product_ids),
                    'no_found_rows'  => true,
                    'fields'         => 'ids',
                ]);
                update_post_thumbnail_cache($thumb_query);
                wp_reset_postdata();
            }
            if (function_exists('update_object_term_cache')) {
                update_object_term_cache($product_ids, 'product');
            }
        }

        $loaded = [];

        foreach ($products as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }

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

            $metadata = isset($metadata_batch[$product_id])
                ? $metadata_batch[$product_id]
                : MealsDB_Products::get_product_data($product_id);
            $normalized_metadata = self::normalize_metadata($metadata, $product_id, $product->get_name());

            $image_id  = $product->get_image_id();
            $image_url = '';
            if ($image_id) {
                $image_url = wp_get_attachment_image_url($image_id, 'medium');
            }

            if (!$image_url) {
                $image_url = function_exists('wc_placeholder_img_src') ? wc_placeholder_img_src() : '';
            }

            $categories = self::get_product_categories($product_id);

            $loaded[$product_id] = array_merge(
                [
                    'id'         => $product_id,
                    'name'       => $product->get_name(),
                    'price'      => $price_value,
                    'image'      => $image_url,
                    'sku'        => $product->get_sku(),
                    'categories' => $categories,
                ],
                $normalized_metadata
            );
        }

        return $loaded;
    }

    /**
     * Fetch product metadata for multiple product IDs in a single query.
     *
     * @param array<int, int> $product_ids
     *
     * @return array<int, array<string, mixed>> Keyed by product ID.
     */
    public static function batch_get_product_data(array $product_ids): array {
        if (empty($product_ids)) {
            return [];
        }

        global $wpdb;
        if (!$wpdb) {
            return [];
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT wc_product_id, product_type, taxable, main_ingredient, dietary_tags, allergen_flags, case_size, unit_cost, last_updated FROM `{$table}` WHERE wc_product_id IN ({$placeholders})",
            ...$product_ids
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        $rows = [];

        if (is_array($results)) {
            foreach ($results as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $id = (int) $row['wc_product_id'];
                $rows[$id] = [
                    'wc_product_id'   => $id,
                    // U20-products-po-6: honour all product types (meal/side/fee/
                    // other) that MealsDB_Products::get_product_data() persists —
                    // the old ['meal','side'] whitelist silently coerced fee/other
                    // rows to 'meal'.
                    'product_type'    => in_array($row['product_type'], MealsDB_Products::PRODUCT_TYPES, true) ? (string) $row['product_type'] : 'meal',
                    'taxable'         => (int) $row['taxable'],
                    'main_ingredient' => (string) $row['main_ingredient'],
                    'dietary_tags'    => self::decode_json_field($row['dietary_tags']),
                    'allergen_flags'  => self::decode_json_field($row['allergen_flags']),
                    'case_size'       => (int) $row['case_size'],
                    'unit_cost'       => number_format((float) $row['unit_cost'], 2, '.', ''),
                    'last_updated'    => $row['last_updated'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Normalise Meals DB metadata for a product.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
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
            // U20-products-po-6: share MealsDB_Products' full type whitelist.
            'product_type'    => in_array($merged['product_type'], MealsDB_Products::PRODUCT_TYPES, true)
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
     * Retrieve categories for a product.
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

        return $categories;
    }

    /**
     * Decode a JSON field into an array.
     *
     * @param string|null $value
     *
     * @return array<int|string, mixed>
     */
    private static function decode_json_field($value): array {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

}
