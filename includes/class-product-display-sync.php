<?php
/**
 * Sync WooCommerce product display data into the meals_products table.
 *
 * Handles individual product saves and a full rebuild for all products.
 * Display fields (name, price, image, SKU, categories, publish status) are
 * cached so the Quick Order page can query the external DB directly instead
 * of calling WooCommerce APIs on every page load.
 */

defined('ABSPATH') || exit;

class MealsDB_Product_Display_Sync {

    /**
     * Tracks whether hooks have already been registered.
     */
    private static bool $hooks_registered = false;

    /**
     * Register WordPress hooks for keeping display data in sync.
     */
    public static function init(): void {
        if (self::$hooks_registered) {
            return;
        }

        self::$hooks_registered = true;

        // Sync display fields when a WC product is saved.
        add_action('save_post_product', [self::class, 'on_product_save'], 20, 2);

        // Toggle is_published when products are trashed / restored.
        add_action('trashed_post', [self::class, 'on_product_trashed']);
        add_action('untrashed_post', [self::class, 'on_product_untrashed']);

        // AJAX endpoint for manual full sync from the settings page.
        add_action('wp_ajax_mealsdb_sync_product_display', [self::class, 'ajax_full_sync']);

        // AJAX endpoint for the legacy case-size backfill (Data Ops button).
        add_action('wp_ajax_mealsdb_case_count_sync', [self::class, 'ajax_case_count_sync']);
    }

    /**
     * Handle save_post_product — sync a single product's display fields.
     *
     * @param int          $post_id Post ID.
     * @param WP_Post|null $post    The post object.
     */
    public static function on_product_save(int $post_id, $post = null): void {
        if ($post instanceof WP_Post && $post->post_type !== 'product') {
            return;
        }

        // Avoid syncing during autosave or revisions.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        $product = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
        if (!$product instanceof WC_Product) {
            return;
        }

        self::sync_single_product($product);
    }

    /**
     * Mark a product as unpublished when trashed.
     *
     * @param int $post_id Post ID.
     */
    public static function on_product_trashed(int $post_id): void {
        if (get_post_type($post_id) !== 'product') {
            return;
        }

        MealsDB_Products::set_published($post_id, false);
    }

    /**
     * Restore publish status when a product is untrashed.
     *
     * @param int $post_id Post ID.
     */
    public static function on_product_untrashed(int $post_id): void {
        if (get_post_type($post_id) !== 'product') {
            return;
        }

        // Re-sync the full display data so we pick up the correct status.
        $product = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
        if ($product instanceof WC_Product) {
            self::sync_single_product($product);
        }
    }

    /**
     * Sync display fields for a single WC product into meals_products.
     *
     * @param WC_Product $product WooCommerce product instance.
     */
    /**
     * @return bool True if every write for this product succeeded.
     */
    public static function sync_single_product(WC_Product $product): bool {
        $product_id = $product->get_id();
        if ($product_id <= 0) {
            return false;
        }

        $display = self::extract_display_data($product);
        $ok = MealsDB_Products::update_display_fields($product_id, $display);

        // Auto-set product_type + taxable from WC categories, but NEVER clobber
        // an operator's explicit taxable override (audit-2026-08 B10). The
        // taxable-side rule is sourced from MealsDB_Operational_Constants (the
        // single source of truth), not a local hardcoded copy.
        $terms = get_the_terms($product_id, 'product_cat');
        if (is_array($terms)) {
            $slugs = array_map(static function ($t) {
                return $t instanceof WP_Term ? $t->slug : '';
            }, $terms);

            $is_side = !empty(array_intersect($slugs, MealsDB_Operational_Constants::side_category_slugs()));
            if ($is_side) {
                $existing = MealsDB_Products::get_product_data($product_id);
                if (!empty($existing['taxable_overridden'])) {
                    // Operator forced this product's taxability — preserve it;
                    // only ensure the product_type is 'side'.
                    $taxable            = (int) $existing['taxable'];
                    $taxable_overridden = 1;
                } else {
                    $taxable            = !empty(array_intersect($slugs, MealsDB_Operational_Constants::taxable_side_category_slugs())) ? 1 : 0;
                    $taxable_overridden = 0;
                }
                $ok = MealsDB_Products::save_product_data($product_id, array_merge($existing, [
                    'product_type'       => 'side',
                    'taxable'            => $taxable,
                    'taxable_overridden' => $taxable_overridden,
                ])) && $ok;
            }
        }

        return $ok;
    }

    /**
     * AJAX handler for manual full sync triggered from the settings page.
     */
    public static function ajax_full_sync(): void {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'mealsdb_nonce')) {
            wp_send_json([
                'success' => false,
                'message' => __('Invalid request.', 'meals-db'),
            ]);
        }

        $capability = class_exists('MealsDB_Permissions')
            ? MealsDB_Permissions::required_capability()
            : 'manage_woocommerce';
        if (!current_user_can($capability)) {
            wp_send_json([
                'success' => false,
                'message' => __('You are not allowed to perform this action.', 'meals-db'),
            ], 403);
        }

        // Rate-limit: full_sync() walks the entire published-product catalog and
        // writes a meals_products row per product — an unthrottled heavy loop is
        // a cheap DoS lever for any authenticated baseline user. Use the
        // bulk-backfill bucket, matching the other catalog-wide operations.
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('settings_modify')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        $result = self::full_sync();

        $message = $result['failed'] > 0
            ? sprintf(
                /* translators: 1: number synced, 2: number failed */
                __('Synced %1$d products; %2$d failed (see the Event Log).', 'meals-db'),
                $result['synced'],
                $result['failed']
            )
            : sprintf(
                __('Synced %d products successfully.', 'meals-db'),
                $result['synced']
            );

        wp_send_json([
            'success' => $result['failed'] === 0,
            'message' => $message,
        ]);
    }

    /**
     * AJAX handler: backfill meals_products.case_size from legacy postmeta.
     * Guard stack mirrors ajax_full_sync() verbatim (nonce → capability → rate limit).
     */
    public static function ajax_case_count_sync(): void {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'mealsdb_nonce')) {
            wp_send_json([
                'success' => false,
                'message' => __('Invalid request.', 'meals-db'),
            ]);
        }

        $capability = class_exists('MealsDB_Permissions')
            ? MealsDB_Permissions::required_capability()
            : 'manage_woocommerce';
        if (!current_user_can($capability)) {
            wp_send_json([
                'success' => false,
                'message' => __('You are not allowed to perform this action.', 'meals-db'),
            ], 403);
        }

        // Rate-limit: this walks the whole published-product catalog and writes a
        // meals_products row per filled product — same heavy-loop profile as the
        // display sync, so reuse the bulk-backfill bucket.
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('settings_modify')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        $result = self::case_count_sync();

        wp_send_json([
            'success' => true,
            'message' => sprintf(
                /* translators: 1: scanned, 2: filled, 3: already correct, 4: no legacy, 5: failed */
                __('Case Count Sync complete: %1$d products scanned, %2$d filled from legacy data, %3$d already correct, %4$d had no legacy value, %5$d failed to write.', 'meals-db'),
                $result['scanned'], $result['filled'], $result['already_ok'], $result['no_legacy'], $result['failed']
            ),
        ]);
    }

    /**
     * Run a full rebuild of display fields for all published WC products.
     *
     * @return array{synced:int, failed:int} Products that wrote OK vs failed.
     *         Previously this returned a bare synced count and incremented it
     *         unconditionally — so a run where every write failed still reported
     *         "Synced N products" (audit-2026-08 B10). Now each product's write
     *         result is checked and failures are counted + surfaced.
     */
    public static function full_sync(): array {
        if (!function_exists('wc_get_products')) {
            return ['synced' => 0, 'failed' => 0];
        }

        $allowed_slugs = MealsDB_Quick_Order_Products::get_allowed_category_slugs();

        $page    = 1;
        $synced  = 0;
        $failed  = 0;
        $per_page = 100;

        do {
            $products = wc_get_products([
                'status'   => 'publish',
                'limit'    => $per_page,
                'page'     => $page,
                'orderby'  => 'ID',
                'order'    => 'ASC',
                'return'   => 'objects',
                'category' => $allowed_slugs,
            ]);

            if (!is_array($products) || empty($products)) {
                break;
            }

            foreach ($products as $product) {
                if (!$product instanceof WC_Product) {
                    continue;
                }

                if (self::sync_single_product($product)) {
                    $synced++;
                } else {
                    $failed++;
                }
            }

            $page++;
        } while (count($products) === $per_page);

        // Mark products not in allowed categories as unpublished.
        self::mark_unlisted_products_unpublished();

        // Don't let a run that partially (or wholly) failed report clean —
        // surface it on the dashboard/digest (Pattern 6/7).
        if ($failed > 0 && class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'products',
                'subsystem' => 'product_display_sync',
                'event'     => 'full_sync.partial',
                'outcome'   => 'degraded',
                'message'   => sprintf('Product display sync wrote %d products; %d failed.', $synced, $failed),
            ]);
        }

        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * Backfill meals_products.case_size from the legacy bare `case_size` postmeta.
     *
     * Column-keyed, idempotent, non-destructive. Reads the CURRENT case size from the
     * canonical meals_products.case_size COLUMN (via get_product_data) — NOT from the
     * `_mealsdb_case_size` postmeta, which the plugin never persists (it is only a WC
     * form-field name; the tab writes the column directly). Only FILLS the default
     * (column <= 1) from a real legacy value (> 1); never lowers or overwrites a column
     * that already holds a real value, and never touches the legacy meta. Running it
     * twice changes nothing the second time.
     *
     * NOTE: a product legitimately having case_size 1 cannot be distinguished from the
     * unset default 1 — acceptable because the real case sizes are 12/24/36/48/100 and
     * the operation is non-destructive (a true-1 product simply stays 1).
     *
     * @return array{scanned:int, filled:int, already_ok:int, no_legacy:int, failed:int}
     */
    public static function case_count_sync(): array {
        $stats = ['scanned' => 0, 'filled' => 0, 'already_ok' => 0, 'no_legacy' => 0, 'failed' => 0];

        if (!function_exists('wc_get_products') || !class_exists('MealsDB_Quick_Order_Products')) {
            return $stats;
        }

        $allowed_slugs = MealsDB_Quick_Order_Products::get_allowed_category_slugs();

        $page     = 1;
        $per_page = 100;

        do {
            $products = wc_get_products([
                'status'   => 'publish',
                'limit'    => $per_page,
                'page'     => $page,
                'orderby'  => 'ID',
                'order'    => 'ASC',
                'return'   => 'objects',
                'category' => $allowed_slugs,
            ]);

            if (!is_array($products) || empty($products)) {
                break;
            }

            foreach ($products as $product) {
                if (!$product instanceof WC_Product) {
                    continue;
                }
                $pid = (int) $product->get_id();
                if ($pid <= 0) {
                    continue;
                }

                $stats['scanned']++;

                $existing = MealsDB_Products::get_product_data($pid);
                $current  = (int) ($existing['case_size'] ?? 1);          // canonical COLUMN value
                $legacy   = (int) get_post_meta($pid, 'case_size', true); // legacy bare key (READ ONLY)

                if ($current > 1) {
                    // Column already holds a real (non-default) case size — leave it ALONE.
                    $stats['already_ok']++;
                } elseif ($legacy > 1) {
                    // Default column + real legacy value: fill the COLUMN via the existing
                    // upsert so every column stays consistent. The legacy meta is left intact.
                    // Reading/writing the column (never the empty _mealsdb_case_size postmeta)
                    // is what keeps this idempotent and non-destructive.
                    $ok = MealsDB_Products::save_product_data($pid, array_merge($existing, ['case_size' => $legacy]));
                    if ($ok) {
                        $stats['filled']++;
                    } else {
                        // Upsert refused (e.g. capability) — surface it, never report a phantom success.
                        $stats['failed']++;
                    }
                } else {
                    $stats['no_legacy']++;
                }
            }

            $page++;
        } while (count($products) === $per_page);

        return $stats;
    }

    /**
     * Extract display data from a WC product for caching.
     *
     * @param WC_Product $product
     *
     * @return array<string, mixed>
     */
    private static function extract_display_data(WC_Product $product): array {
        $price = $product->get_price();
        $price_value = $price === '' ? 0.0 : (float) $price;
        if (function_exists('wc_get_price_to_display')) {
            $price_value = (float) wc_get_price_to_display($product);
        }

        $image_id  = $product->get_image_id();
        $image_url = '';
        if ($image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'medium');
        }
        if (!$image_url) {
            $image_url = function_exists('wc_placeholder_img_src') ? wc_placeholder_img_src() : '';
        }

        $categories = self::get_allowed_categories_for_product($product->get_id());
        $is_published = $product->get_status() === 'publish' ? 1 : 0;

        return [
            'product_name'  => $product->get_name(),
            'price'         => $price_value,
            'image_url'     => $image_url ?: null,
            'sku'           => $product->get_sku() ?: null,
            'category_data' => $categories,
            'is_published'  => $is_published,
        ];
    }

    /**
     * Get allowed categories for a product, filtered by the Quick Order slug whitelist.
     *
     * @param int $product_id
     *
     * @return array<int, array<string, mixed>>
     */
    private static function get_allowed_categories_for_product(int $product_id): array {
        $terms = get_the_terms($product_id, 'product_cat');
        if (!is_array($terms) || empty($terms)) {
            return [];
        }

        $allowed_slugs = MealsDB_Quick_Order_Products::get_allowed_category_slugs();
        $categories    = [];

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

        return $categories;
    }

    /**
     * Set is_published = 0 for rows whose wc_product_id no longer maps to a
     * published WC product in an allowed category.
     */
    private static function mark_unlisted_products_unpublished(): void {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);

        // Fetch all wc_product_ids that are currently marked published.
        $rows = $wpdb->get_results("SELECT wc_product_id FROM `{$table}` WHERE is_published = 1", ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return;
        }

        $candidate_ids = [];
        foreach ($rows as $row) {
            $wc_id = (int) $row['wc_product_id'];
            if ($wc_id > 0) {
                $candidate_ids[] = $wc_id;
            }
        }
        if (empty($candidate_ids)) {
            return;
        }

        // Single bulk SELECT against wp_posts to find which candidate IDs
        // are still published WC products. Previous N+1 wc_get_product()
        // call per row was unworkable at hundreds of products.
        $posts_table  = $wpdb->posts;
        $placeholders = implode(',', array_fill(0, count($candidate_ids), '%d'));
        $still_published = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM `{$posts_table}` WHERE ID IN ({$placeholders}) AND post_type = 'product' AND post_status = 'publish'",
            ...$candidate_ids
        ));
        $still_published = is_array($still_published) ? array_map('intval', $still_published) : [];

        $ids_to_unpublish = array_values(array_diff($candidate_ids, $still_published));
        if (empty($ids_to_unpublish)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids_to_unpublish), '%d'));
        $sql = $wpdb->prepare(
            "UPDATE `{$table}` SET is_published = 0 WHERE wc_product_id IN ({$placeholders})",
            ...$ids_to_unpublish
        );
        $wpdb->query($sql);
    }
}
