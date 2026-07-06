<?php
/**
 * Manage Meals DB product metadata stored in the external database.
 */

defined('ABSPATH') || exit;

class MealsDB_Products {
    private const TABLE = MealsDB_Tables::PRODUCTS;

    /**
     * Allowed values for the product_type ENUM. Kept in sync with the
     * canonical schema so all four variants round-trip correctly instead
     * of being silently coerced to 'meal' on save/read.
     */
    private const PRODUCT_TYPES = ['meal', 'side', 'fee', 'other'];

    /**
     * Retrieve product metadata for a WooCommerce product.
     *
     * @param int $product_id WooCommerce product ID.
     *
     * @return array<string, mixed>
     */
    public static function get_product_data(int $product_id): array {
        $defaults = self::get_default_row($product_id);

        global $wpdb;
        if (!$wpdb) {
            return $defaults;
        }

        $table = MealsDB_DB::get_table_name(self::TABLE);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT wc_product_id, product_type, taxable, main_ingredient, dietary_tags, allergen_flags, case_size, unit_cost, last_updated FROM `{$table}` WHERE wc_product_id = %d LIMIT 1",
            $product_id
        ), ARRAY_A);

        if (!is_array($row)) {
            return $defaults;
        }

        return [
            'wc_product_id'   => (int) $row['wc_product_id'],
            'product_type'    => in_array($row['product_type'], self::PRODUCT_TYPES, true) ? (string) $row['product_type'] : 'meal',
            'taxable'         => (int) $row['taxable'],
            'main_ingredient' => (string) $row['main_ingredient'],
            'dietary_tags'    => self::decode_json_field($row['dietary_tags']),
            'allergen_flags'  => self::decode_json_field($row['allergen_flags']),
            'case_size'       => (int) $row['case_size'],
            'unit_cost'       => number_format((float) $row['unit_cost'], 2, '.', ''),
            'last_updated'    => $row['last_updated'],
        ];
    }

    /**
     * Save product metadata to the Meals DB.
     *
     * @param int   $product_id WooCommerce product ID.
     * @param array $data       Associative array of product data.
     *
     * @return bool True on success, false otherwise.
     */
    public static function save_product_data(int $product_id, array $data): bool {
        // Defence-in-depth: the product-tab UI already calls this
        // behind a save_post capability check, but any future caller
        // (WP-CLI import, REST endpoint, custom bulk editor) that
        // reaches this service method directly should not be able to
        // upsert product-cost rows without the appropriate WC cap.
        if (function_exists('current_user_can')
            && !current_user_can('edit_product', $product_id)
            && !current_user_can('manage_woocommerce')) {
            error_log(sprintf('[MealsDB Products] save_product_data blocked: insufficient capability for product_id=%d', $product_id));
            return false;
        }

        global $wpdb;
        if (!$wpdb) {
            return false;
        }

        $prepared = self::prepare_row_data($product_id, $data);

        $table = MealsDB_DB::get_table_name(self::TABLE);

        $sql = $wpdb->prepare(
            // Row-alias form (INSERT ... AS new): VALUES(col) inside ON DUPLICATE
            // KEY UPDATE is deprecated as of MySQL 8.0.20; new.col is the
            // equivalent replacement (needs MySQL 8.0.19+, target is MySQL 8.x).
            "INSERT INTO `{$table}` (wc_product_id, product_type, taxable, main_ingredient, dietary_tags, allergen_flags, case_size, unit_cost)
                VALUES (%d, %s, %d, %s, %s, %s, %d, %f) AS new
                ON DUPLICATE KEY UPDATE
                    product_type = new.product_type,
                    taxable = new.taxable,
                    main_ingredient = new.main_ingredient,
                    dietary_tags = new.dietary_tags,
                    allergen_flags = new.allergen_flags,
                    case_size = new.case_size,
                    unit_cost = new.unit_cost",
            $prepared['wc_product_id'],
            $prepared['product_type'],
            $prepared['taxable'],
            $prepared['main_ingredient'],
            $prepared['dietary_tags'],
            $prepared['allergen_flags'],
            $prepared['case_size'],
            $prepared['unit_cost']
        );

        $result = $wpdb->query($sql);

        return $result !== false;
    }

    /**
     * Get default product data structure for a WooCommerce product.
     *
     * @param int $product_id
     *
     * @return array<string, mixed>
     */
    private static function get_default_row(int $product_id): array {
        return [
            'wc_product_id'   => $product_id,
            'product_type'    => 'meal',
            'taxable'         => 0,
            'main_ingredient' => '',
            'dietary_tags'    => [],
            'allergen_flags'  => [],
            'case_size'       => 1,
            'unit_cost'       => '0.00',
            'last_updated'    => null,
        ];
    }

    /**
     * Prepare row data for insertion or update.
     *
     * @param int   $product_id
     * @param array $data
     *
     * @return array<string, mixed>
     */
    private static function prepare_row_data(int $product_id, array $data): array {
        $defaults = self::get_default_row($product_id);
        $merged   = array_merge($defaults, $data);

        $product_type = in_array($merged['product_type'], self::PRODUCT_TYPES, true)
            ? (string) $merged['product_type']
            : 'meal';

        $dietary_tags   = self::encode_json_field($merged['dietary_tags']);
        $allergen_flags = self::encode_json_field($merged['allergen_flags']);

        $taxable   = (int) (!empty($merged['taxable']));
        $case_size = isset($merged['case_size']) ? (int) $merged['case_size'] : 1;
        if ($case_size < 1) {
            $case_size = 1;
        }

        // Clamp unit_cost to a sane range. Without bounds, a typo
        // (1e9 instead of 1.9) silently accepts a price that would
        // break every downstream cost rollup and inflate resupply
        // projections. The DB column is DECIMAL(10,2) — max 99999999.99
        // — so setting a ceiling here gives a clearer error path
        // than the silent DECIMAL overflow.
        $unit_cost_raw = isset($merged['unit_cost']) && is_numeric($merged['unit_cost'])
            ? (float) $merged['unit_cost']
            : 0.00;
        $unit_cost = max(0.00, min(99999.99, $unit_cost_raw));

        return [
            'wc_product_id'   => (int) $product_id,
            'product_type'    => $product_type,
            'taxable'         => $taxable,
            'main_ingredient' => isset($merged['main_ingredient']) ? (string) $merged['main_ingredient'] : '',
            'dietary_tags'    => $dietary_tags,
            'allergen_flags'  => $allergen_flags,
            'case_size'       => $case_size,
            'unit_cost'       => $unit_cost,
        ];
    }

    /**
     * Update display-only fields cached from WooCommerce.
     *
     * This upserts the row so it works even if the product has no prior meals_products entry.
     *
     * @param int                          $product_id WooCommerce product ID.
     * @param array<string, mixed>         $display    Display fields: product_name, price, image_url, sku, category_data, is_published.
     *
     * @return bool True on success.
     */
    public static function update_display_fields(int $product_id, array $display): bool {
        // Defence-in-depth (mirrors save_product_data): a future caller
        // (WP-CLI, REST, bulk editor) reaching this service writer directly
        // must not be able to poison the display cache (name/price/is_published
        // surfaced in Quick Order) without the appropriate WC capability.
        if (function_exists('current_user_can')
            && !current_user_can('edit_product', $product_id)
            && !current_user_can('manage_woocommerce')) {
            error_log(sprintf('[MealsDB Products] update_display_fields blocked: insufficient capability for product_id=%d', $product_id));
            return false;
        }

        global $wpdb;
        if (!$wpdb) {
            return false;
        }

        $table = MealsDB_DB::get_table_name(self::TABLE);

        $product_name = isset($display['product_name']) ? mb_substr((string) $display['product_name'], 0, 200) : '';
        $price        = isset($display['price']) ? (float) $display['price'] : 0.00;
        $image_url    = isset($display['image_url']) ? mb_substr((string) $display['image_url'], 0, 500) : null;
        $sku          = isset($display['sku']) ? mb_substr((string) $display['sku'], 0, 100) : null;
        $is_published = isset($display['is_published']) ? (int) (bool) $display['is_published'] : 1;

        $category_data = null;
        if (isset($display['category_data']) && is_array($display['category_data'])) {
            $category_data = json_encode($display['category_data']);
        }

        $sql = $wpdb->prepare(
            // Row-alias form (INSERT ... AS new): VALUES(col) inside ON DUPLICATE
            // KEY UPDATE is deprecated as of MySQL 8.0.20; new.col is the
            // equivalent replacement (needs MySQL 8.0.19+, target is MySQL 8.x).
            "INSERT INTO `{$table}` (wc_product_id, product_name, price, image_url, sku, category_data, is_published, product_type, taxable, main_ingredient, case_size, buffer, unit_cost)
                VALUES (%d, %s, %f, %s, %s, %s, %d, 'meal', 0, '', 1, 0, 0.00) AS new
                ON DUPLICATE KEY UPDATE
                    product_name = new.product_name,
                    price = new.price,
                    image_url = new.image_url,
                    sku = new.sku,
                    category_data = new.category_data,
                    is_published = new.is_published",
            $product_id,
            $product_name,
            $price,
            $image_url,
            $sku,
            $category_data,
            $is_published
        );

        $result = $wpdb->query($sql);

        return $result !== false;
    }

    /**
     * Toggle the is_published flag for a product.
     *
     * @param int  $product_id   WooCommerce product ID.
     * @param bool $is_published Whether the product is published.
     *
     * @return bool True on success.
     */
    public static function set_published(int $product_id, bool $is_published): bool {
        // Defence-in-depth (mirrors save_product_data): a future direct caller
        // must not be able to flip is_published without the appropriate WC cap.
        if (function_exists('current_user_can')
            && !current_user_can('edit_product', $product_id)
            && !current_user_can('manage_woocommerce')) {
            error_log(sprintf('[MealsDB Products] set_published blocked: insufficient capability for product_id=%d', $product_id));
            return false;
        }

        global $wpdb;
        if (!$wpdb) {
            return false;
        }

        $table = MealsDB_DB::get_table_name(self::TABLE);

        $flag = $is_published ? 1 : 0;
        $sql = $wpdb->prepare(
            "UPDATE `{$table}` SET is_published = %d WHERE wc_product_id = %d",
            $flag,
            $product_id
        );

        $result = $wpdb->query($sql);

        return $result !== false;
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
     * Encode a value as JSON if it is an array, otherwise return null.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private static function encode_json_field($value): ?string {
        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
