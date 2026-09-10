<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Create a Draft WooCommerce product (plus its meals_products row) from parsed
 * Apetito data. The duplicate guard keys on SKU (= the Apetito code); a match
 * blocks creation. WooCommerce enforces SKU uniqueness natively, so this check
 * is belt-and-braces, not the only wall.
 */
class MealsDB_Apetito_Product_Creator {

    /**
     * Find an existing product whose meals_products.sku equals the code.
     *
     * @return array|null ['wc_product_id'=>int,'product_name'=>string,'status'=>string] or null
     */
    public static function find_by_sku(string $code): ?array {
        global $wpdb;
        if (!$wpdb) { return null; }
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $wc_id = $wpdb->get_var($wpdb->prepare(
            "SELECT wc_product_id FROM `{$table}` WHERE sku = %s LIMIT 1",
            $code
        ));
        if (!$wc_id) { return null; }
        $wc_id = (int) $wc_id;
        $name = ''; $status = '';
        // Products still live in wp_posts — HPOS only moved ORDERS out — so
        // get_the_title / get_post_status are the correct, HPOS-safe lookups here.
        if (function_exists('get_the_title')) { $name = (string) get_the_title($wc_id); }
        if (function_exists('get_post_status')) { $status = (string) get_post_status($wc_id); }
        return ['wc_product_id' => $wc_id, 'product_name' => $name, 'status' => $status];
    }

    /**
     * Pure: build the meals_products payload by merging parsed Apetito fields
     * ONTO the existing (category-derived) row. Preserves product_type/taxable
     * (owned by class-product-display-sync via WC categories — writing them here
     * would reintroduce the same-request clobber that the override removal fixed).
     * main_ingredient maps from the Apetito subcategory, truncated to the
     * VARCHAR(40) column with no silent overflow.
     *
     * @param array $existing meals_products row (from MealsDB_Products::get_product_data)
     * @param array $parsed   ['subcategory'=>?string,'allergens'=>array,'diet_tags'=>array,'portions_per_case'=>?int]
     */
    public static function build_meals_products_payload(array $existing, array $parsed): array {
        $main_ingredient = isset($parsed['subcategory']) ? (string) $parsed['subcategory'] : '';
        if (strlen($main_ingredient) > 40) {
            $main_ingredient = substr($main_ingredient, 0, 40); // VARCHAR(40); no mb_* (mbstring absent locally)
        }
        $case_size = isset($parsed['portions_per_case']) && (int) $parsed['portions_per_case'] > 0
            ? (int) $parsed['portions_per_case']
            : (int) ($existing['case_size'] ?? 1);

        return array_merge($existing, [
            'main_ingredient' => $main_ingredient,
            'allergen_flags'  => isset($parsed['allergens']) && is_array($parsed['allergens']) ? $parsed['allergens'] : [],
            'dietary_tags'    => isset($parsed['diet_tags']) && is_array($parsed['diet_tags']) ? $parsed['diet_tags'] : [],
            'case_size'       => $case_size,
        ]);
    }

    /**
     * Pure: the operator-facing block message. Empty string when there is no
     * duplicate (caller treats empty as "allowed").
     */
    public static function duplicate_message(?array $existing, string $code, string $apetito_name): string {
        if ($existing === null) { return ''; }
        // `?? ''` on the reads: this method accepts an arbitrary ?array, so guard
        // against a partial array (missing keys) rather than warning on it.
        $existing_name = ($existing['product_name'] ?? '') !== '' ? $existing['product_name'] : sprintf('#%s', $code);
        return sprintf(
            /* translators: 1: apetito code, 2: apetito name, 3: existing product name, 4: product id, 5: status */
            __('Apetito lists %1$s as "%2$s". You already have "%3$s" (product %4$d, %5$s). Codes must be unique — the packing slip, the purchase order and the delivery slip all identify items by this number.', 'meals-db'),
            $code,
            $apetito_name,
            $existing_name,
            (int) ($existing['wc_product_id'] ?? 0),
            ($existing['status'] ?? '') !== '' ? $existing['status'] : 'unknown'
        );
    }

    /**
     * Create a Draft WC product from parsed data + operator inputs and write its
     * meals_products row. Draft (never published): is_published lands 0 via
     * class-product-display-sync (get_status() !== 'publish'), keeping it out of
     * Quick Order and the PO forecast until the operator publishes.
     *
     * @param array $parsed  parser fields already reduced to values (name_en, subcategory, allergens, diet_tags, portions_per_case)
     * @param array $input   ['code'=>string,'price'=>float,'category_ids'=>int[]]
     * @return array ['ok'=>true,'product_id'=>int] | ['ok'=>false,'reason'=>string]
     */
    public static function create(array $parsed, array $input): array {
        $code = (string) ($input['code'] ?? '');
        if (!MealsDB_Apetito_Nutridata::is_valid_code($code)) {
            return ['ok' => false, 'reason' => __('Invalid code.', 'meals-db')];
        }

        // Belt-and-braces: re-check the SKU guard (WC also rejects a dupe SKU).
        $dupe = self::find_by_sku($code);
        if ($dupe !== null) {
            return ['ok' => false, 'reason' => self::duplicate_message($dupe, $code, (string) ($parsed['name_en'] ?? ''))];
        }

        if (!function_exists('wc_get_product') || !class_exists('WC_Product_Simple')) {
            return ['ok' => false, 'reason' => __('WooCommerce is required.', 'meals-db')];
        }

        try {
            $product = new WC_Product_Simple();
            $name = trim((string) ($parsed['name_en'] ?? ''));
            // Title convention: "{name} #{code}" (matches every existing product).
            $product->set_name($name !== '' ? $name . ' #' . $code : '#' . $code);
            $product->set_status('draft');
            $product->set_sku($code); // SKU IS the Apetito code
            if (isset($input['price']) && is_numeric($input['price'])) {
                $product->set_regular_price((string) $input['price']);
                $product->set_price((string) $input['price']);
            }
            if (!empty($input['category_ids']) && is_array($input['category_ids'])) {
                $product->set_category_ids(array_map('intval', $input['category_ids']));
            }
            $placeholder_id = MealsDB_Apetito_Placeholder::get_or_create();
            if ($placeholder_id > 0) {
                $product->set_image_id($placeholder_id);
            }
            $product_id = $product->save();
            if (!$product_id) {
                return ['ok' => false, 'reason' => __('WooCommerce refused to create the product (possibly a duplicate SKU).', 'meals-db')];
            }

            if ($placeholder_id > 0) {
                update_post_meta($product_id, '_mealsdb_placeholder_image', 1);
            }

            // Force the category-derivation deterministically (avoids any
            // save_post term-timing race), THEN read the row back so our payload
            // preserves the derived product_type/taxable.
            if (class_exists('MealsDB_Product_Display_Sync')) {
                MealsDB_Product_Display_Sync::sync_single_product($product);
            }
            $existing = MealsDB_Products::get_product_data($product_id);
            $payload  = self::build_meals_products_payload($existing, $parsed);
            MealsDB_Products::save_product_data($product_id, $payload);

            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity' => 'info', 'category' => 'products', 'subsystem' => 'apetito_pull',
                    'event' => 'apetito_pull.created', 'outcome' => 'succeeded',
                    'message' => sprintf('Created draft product %d from Apetito code %s', $product_id, $code),
                    'context' => ['product_id' => $product_id, 'code' => $code,
                                  'manual_fields' => $input['manual_fields'] ?? []],
                    'entity_type' => 'product', 'entity_id' => $product_id,
                ]);
            }
            return ['ok' => true, 'product_id' => (int) $product_id];
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Apetito] create failed: ' . $e->getMessage());
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity' => 'error', 'category' => 'products', 'subsystem' => 'apetito_pull',
                    'event' => 'apetito_pull.create_failed', 'outcome' => 'degraded',
                    'message' => $e->getMessage(), 'context' => ['code' => $code],
                ]);
            }
            return ['ok' => false, 'reason' => __('Could not create the product. See the event log.', 'meals-db')];
        }
    }
}
