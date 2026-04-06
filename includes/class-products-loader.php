<?php
/**
 * Helper for loading product metadata from the external Meals DB.
 */

class MealsDB_Products_Loader {
    /**
     * Load published WooCommerce products in the allowed Quick Order categories.
     *
     * Uses a direct $wpdb query to fetch only the fields the Quick Order grid
     * needs (ID, title, price, SKU, image ID) instead of instantiating full
     * WC_Product objects which consume 50-150 KB each and trigger N+1 queries
     * for post meta, terms, and attachments.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function load_all_products(): array {
        global $wpdb;

        if (!function_exists('wc_get_products')) {
            return [];
        }

        $allowed_slugs = MealsDB_Quick_Order_Products::get_allowed_category_slugs();

        // Resolve term IDs for the allowed category slugs.
        $slug_placeholders = implode(',', array_fill(0, count($allowed_slugs), '%s'));
        $term_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT t.term_id
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'product_cat'
               AND t.slug IN ({$slug_placeholders})",
            $allowed_slugs
        ));

        if (empty($term_ids)) {
            return [];
        }

        // Fetch published products in those categories with a single query.
        $term_placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT
                p.ID         AS product_id,
                p.post_title AS name,
                price_meta.meta_value   AS price,
                sku_meta.meta_value     AS sku,
                thumb_meta.meta_value   AS image_id
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                AND tt.taxonomy = 'product_cat'
                AND tt.term_id IN ({$term_placeholders})
             LEFT JOIN {$wpdb->postmeta} price_meta
                ON price_meta.post_id = p.ID AND price_meta.meta_key = '_price'
             LEFT JOIN {$wpdb->postmeta} sku_meta
                ON sku_meta.post_id = p.ID AND sku_meta.meta_key = '_sku'
             LEFT JOIN {$wpdb->postmeta} thumb_meta
                ON thumb_meta.post_id = p.ID AND thumb_meta.meta_key = '_thumbnail_id'
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
             ORDER BY p.post_title ASC",
            $term_ids
        ), ARRAY_A);

        if (empty($rows)) {
            return [];
        }

        // Collect all product IDs for batch lookups.
        $product_ids = array_map(function ($row) {
            return (int) $row['product_id'];
        }, $rows);

        // Batch-fetch categories for all products in one query.
        $categories_map = self::batch_get_product_categories($product_ids);

        // Batch-fetch Meals DB metadata in one query.
        $metadata_map = self::batch_get_product_data($product_ids);

        // Batch-fetch image URLs.
        $image_ids = array_filter(array_unique(array_map(function ($row) {
            return (int) ($row['image_id'] ?? 0);
        }, $rows)));
        $image_url_map = self::batch_get_image_urls($image_ids);

        $placeholder_url = function_exists('wc_placeholder_img_src') ? wc_placeholder_img_src() : '';

        // Build the result array.
        $loaded = [];
        foreach ($rows as $row) {
            $product_id = (int) $row['product_id'];
            $image_id   = (int) ($row['image_id'] ?? 0);
            $image_url  = isset($image_url_map[$image_id]) ? $image_url_map[$image_id] : $placeholder_url;
            $price      = $row['price'] !== null && $row['price'] !== '' ? (float) $row['price'] : 0.0;

            $metadata = isset($metadata_map[$product_id]) ? $metadata_map[$product_id] : [];
            $categories = isset($categories_map[$product_id]) ? $categories_map[$product_id] : [];

            $loaded[$product_id] = array_merge(
                [
                    'id'         => $product_id,
                    'name'       => (string) $row['name'],
                    'price'      => $price,
                    'image'      => $image_url ?: $placeholder_url,
                    'sku'        => (string) ($row['sku'] ?? ''),
                    'categories' => $categories,
                ],
                self::normalize_metadata($metadata, $product_id, (string) $row['name'])
            );
        }

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
    public static function batch_get_product_data(array $product_ids): array {
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
     * Fetch categories for multiple product IDs in a single query.
     *
     * @param array<int, int> $product_ids
     *
     * @return array<int, array<int, array<string, mixed>>> Keyed by product ID.
     */
    private static function batch_get_product_categories(array $product_ids): array {
        global $wpdb;

        if (empty($product_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.object_id AS product_id, t.term_id, t.name, t.slug
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                AND tt.taxonomy = 'product_cat'
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE tr.object_id IN ({$placeholders})",
            $product_ids
        ), ARRAY_A);

        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $pid = (int) $row['product_id'];
                if (!isset($map[$pid])) {
                    $map[$pid] = [];
                }

                $map[$pid][] = [
                    'id'   => (int) $row['term_id'],
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                ];
            }
        }

        return $map;
    }

    /**
     * Resolve medium-size image URLs for a batch of attachment IDs.
     *
     * @param array<int, int> $image_ids
     *
     * @return array<int, string> Keyed by attachment ID.
     */
    private static function batch_get_image_urls(array $image_ids): array {
        global $wpdb;

        if (empty($image_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($image_ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN ({$placeholders})
               AND meta_key = '_wp_attached_file'",
            $image_ids
        ), ARRAY_A);

        $upload_dir = wp_get_upload_dir();
        $base_url   = isset($upload_dir['baseurl']) ? trailingslashit($upload_dir['baseurl']) : '';

        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $id   = (int) $row['post_id'];
                $file = $row['meta_value'];

                if ($file && $base_url) {
                    $map[$id] = $base_url . $file;
                }
            }
        }

        return $map;
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
}
