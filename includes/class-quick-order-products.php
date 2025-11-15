<?php
/**
 * Quick Order product data helpers.
 */

class MealsDB_Quick_Order_Products {
    /**
     * Retrieve product categories that contain published products.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_categories(): array {
        if (!function_exists('get_terms') || !taxonomy_exists('product_cat')) {
            return [];
        }

        $args = [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
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

            $categories[] = [
                'id'   => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }

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
        if (!function_exists('wc_get_products')) {
            return [];
        }

        $term = get_term($cat_id, 'product_cat');
        if (!$term instanceof WP_Term || is_wp_error($term)) {
            return [];
        }

        $args = [
            'status'   => 'publish',
            'limit'    => -1,
            'orderby'  => 'title',
            'order'    => 'ASC',
            'category' => [$term->slug],
            'return'   => 'objects',
        ];

        if (function_exists('apply_filters')) {
            $args = apply_filters('mealsdb_quick_order_products_by_category_args', $args, $term);
        }

        $products = wc_get_products($args);
        if (!is_array($products)) {
            return [];
        }

        return self::format_for_quick_order($products);
    }

    /**
     * Search published products by keyword.
     *
     * @param string $keyword Search keyword.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search_products(string $keyword): array {
        if (!function_exists('wc_get_products')) {
            return [];
        }

        $keyword = self::sanitize_keyword($keyword);
        if ($keyword === '') {
            return [];
        }

        $args = [
            'status'  => 'publish',
            'limit'   => 20,
            'orderby' => 'title',
            'order'   => 'ASC',
            'search'  => $keyword,
            'return'  => 'objects',
        ];

        if (function_exists('apply_filters')) {
            $args = apply_filters('mealsdb_quick_order_search_product_args', $args, $keyword);
        }

        $products = wc_get_products($args);
        if (!is_array($products)) {
            return [];
        }

        return self::format_for_quick_order($products);
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
            if (is_object($product) && is_a($product, 'WC_Product')) {
                $payload = self::prepare_product_payload($product);
                if (!empty($payload)) {
                    $formatted[] = $payload;
                }
            } elseif (is_array($product)) {
                $formatted[] = $product;
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
        if (!is_object($product) || !is_a($product, 'WC_Product')) {
            return [];
        }

        $product_id = $product->get_id();

        $terms = get_the_terms($product_id, 'product_cat');
        $category = null;
        if (is_array($terms) && !empty($terms)) {
            $primary = $terms[0];
            if ($primary instanceof WP_Term) {
                $category = [
                    'id'   => (int) $primary->term_id,
                    'name' => $primary->name,
                    'slug' => $primary->slug,
                ];
            }
        }

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

        return [
            'product_id' => $product_id,
            'name'       => $product->get_name(),
            'category'   => $category,
            'price'      => $price_value,
            'image_url'  => $image_url,
        ];
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
}
