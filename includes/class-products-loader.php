<?php
/**
 * Helper for loading product metadata from the external Meals DB.
 */

class MealsDB_Products_Loader {
    /**
     * Number of products to load per batch to avoid memory exhaustion.
     */
    private const BATCH_SIZE = 100;

    /**
     * Load WooCommerce products with metadata sourced from the Meals DB.
     *
     * Products are fetched in batches to keep memory usage bounded.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function load_all_products(): array {
        if (!function_exists('wc_get_products')) {
            return [];
        }

        $allowed_slugs = [
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

        $args = [
            'status'   => 'publish',
            'limit'    => self::BATCH_SIZE,
            'page'     => 1,
            'orderby'  => 'title',
            'order'    => 'ASC',
            'return'   => 'objects',
            'category' => $allowed_slugs,
        ];

        if (function_exists('apply_filters')) {
            $args = apply_filters('mealsdb_products_loader_args', $args);
        }

        // Ensure limit is never unbounded; honour filter overrides only when positive.
        if (!isset($args['limit']) || (int) $args['limit'] <= 0) {
            $args['limit'] = self::BATCH_SIZE;
        }

        $loaded = [];
        $page   = 1;

        do {
            $args['page'] = $page;
            $products = wc_get_products($args);

            if (!is_array($products) || empty($products)) {
                break;
            }

            $product_ids = [];
            foreach ($products as $product) {
                if ($product instanceof WC_Product) {
                    $product_ids[] = $product->get_id();
                }
            }

            $metadata_batch = self::batch_get_product_data($product_ids);

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

                $metadata = isset($metadata_batch[$product_id]) ? $metadata_batch[$product_id] : MealsDB_Products::get_product_data($product_id);
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

            // Free references so WC_Product objects can be garbage-collected.
            unset($products, $metadata_batch);

            $page++;
        } while (true);

        return $loaded;
    }

    /**
     * Determine whether a client can order a product based on allergens.
     */
    public static function client_can_order(array $client_allergens, array $product_allergens): bool {
        $client = array_filter(array_map('strval', $client_allergens));
        $product = array_filter(array_map('strval', $product_allergens));

        if (empty($client) || empty($product)) {
            return true;
        }

        return empty(array_intersect($client, $product));
    }

    /**
     * Fetch product metadata for multiple product IDs in a single query.
     *
     * @param array<int, int> $product_ids
     *
     * @return array<int, array<string, mixed>> Keyed by product ID.
     */
    private static function batch_get_product_data(array $product_ids): array {
        if (empty($product_ids)) {
            return [];
        }

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return [];
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $table = str_replace('`', '``', $table);

        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
        $sql = "SELECT wc_product_id, product_type, taxable, main_ingredient, dietary_tags, allergen_flags, case_size, unit_cost, last_updated FROM `{$table}` WHERE wc_product_id IN ({$placeholders})";

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return [];
        }

        $types = str_repeat('i', count($product_ids));
        $stmt->bind_param($types, ...$product_ids);

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $rows = [];

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if (MealsDB_DB::is_mysqli_result($result)) {
                while ($row = $result->fetch_assoc()) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $id = (int) $row['wc_product_id'];
                    $rows[$id] = [
                        'wc_product_id'   => $id,
                        'product_type'    => in_array($row['product_type'], ['meal', 'side'], true) ? (string) $row['product_type'] : 'meal',
                        'taxable'         => (int) $row['taxable'],
                        'main_ingredient' => (string) $row['main_ingredient'],
                        'dietary_tags'    => self::decode_json_field($row['dietary_tags']),
                        'allergen_flags'  => self::decode_json_field($row['allergen_flags']),
                        'case_size'       => (int) $row['case_size'],
                        'unit_cost'       => number_format((float) $row['unit_cost'], 2, '.', ''),
                        'last_updated'    => $row['last_updated'],
                    ];
                }

                $result->free();
            }
        }

        $stmt->close();

        return $rows;
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
}
