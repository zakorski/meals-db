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
}
