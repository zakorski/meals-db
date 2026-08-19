<?php
/**
 * AJAX handlers for Meals DB Quick Order feature.
 *
 * NAMING CONVENTION (applied consistently across this class):
 *   $wp_user_id — WordPress user ID (wp_users.ID).
 *   $client_id  — meals_clients.client_id (the PK, linked to a WP
 *                 user via meals_clients.wp_user_id).
 *
 * The JS frontend historically posts the WP user ID under the
 * parameter name 'client_id'. This class accepts both 'client_id'
 * and 'wp_user_id' as $_POST / $_REQUEST keys (with 'wp_user_id'
 * taking precedence when both are present) and uses $wp_user_id
 * internally for clarity. STRUCT-6 in the v1.0.346 audit flagged
 * the previous mixed use of $client_id, $client_db_id, and
 * $mealsdb_client_id for the same / different concepts as a
 * tripwire for future maintainers.
 *
 * Order meta keys (mealsdb_client_user_id, mealsdb_client_id) are
 * persistent on existing orders and intentionally NOT renamed.
 * Database column references (`c.client_id`, `c.wp_user_id`) use
 * the actual column names regardless of PHP variable naming.
 */

defined('ABSPATH') || exit;

class MealsDB_Quick_Order_Ajax {
    /**
     * Register AJAX actions related to the Quick Order UI.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_qo_get_categories', [self::class, 'get_categories']);
        add_action('wp_ajax_mealsdb_qo_get_products_by_category', [self::class, 'get_products_by_category']);
        add_action('wp_ajax_mealsdb_qo_search_clients', [self::class, 'search_clients']);
        add_action('wp_ajax_mealsdb_qo_search_products', [self::class, 'search_products']);
        add_action('wp_ajax_mealsdb_qo_get_all_products', [self::class, 'get_all_products']);
        add_action('wp_ajax_mealsdb_qo_create_order', [self::class, 'create_order']);
        add_action('wp_ajax_mealsdb_qo_clone_get_order', [self::class, 'clone_get_order']);
        add_action('wp_ajax_mealsdb_qo_get_client_allocation', [self::class, 'get_client_allocation']);
        add_action('wp_ajax_mealsdb_get_client_allocation_history', [self::class, 'get_client_allocation_history']);
        add_action('wp_ajax_mealsdb_qo_get_next_dates', [self::class, 'get_next_dates']);
        MealsDB_Ajax_Rates::init();
    }

    /**
     * AJAX endpoint to fetch product categories.
     */
    public static function get_categories(): void {
        self::verify_request();

        // Rate limiting
        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        try {
            $categories = MealsDB_Quick_Order_Products::get_categories();
            wp_send_json([
                'success'    => true,
                'categories' => $categories,
            ]);
        } catch (\Throwable $e) {
            error_log('[MealsDB QuickOrder] get_categories error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('An error occurred. Please try again.', 'meals-db'),
            ]);
        }
    }

    /**
     * AJAX endpoint to fetch products by category.
     */
    public static function get_products_by_category(): void {
        self::verify_request();

        // Rate limiting
        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        try {
            $category_id = isset($_REQUEST['category_id']) ? intval($_REQUEST['category_id']) : 0;
            if ($category_id <= 0) {
                wp_send_json([
                    'success' => false,
                    'message' => __('Missing or invalid category.', 'meals-db'),
                ]);
            }

            $products = MealsDB_Quick_Order_Products::get_products_by_category($category_id);
            wp_send_json([
                'success'  => true,
                'products' => $products,
            ]);
        } catch (\Throwable $e) {
            error_log('[MealsDB QuickOrder] get_products_by_category error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('An error occurred. Please try again.', 'meals-db'),
            ]);
        }
    }

    /**
     * AJAX endpoint to fetch all products across allowed categories.
     */
    public static function get_all_products(): void {
        self::verify_request();

        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        try {
            $products = MealsDB_Quick_Order_Products::get_all_quick_order_products();
            wp_send_json([
                'success'  => true,
                'products' => $products,
            ]);
        } catch (\Throwable $e) {
            error_log('[MealsDB QuickOrder] get_all_products error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('An error occurred. Please try again.', 'meals-db'),
            ]);
        }
    }

    /**
     * AJAX endpoint to search products by keyword.
     */
    public static function search_products(): void {
        self::verify_request();

        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        $keyword = isset($_REQUEST['keyword']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['keyword'])) : '';
        if (strlen($keyword) < 2) {
            wp_send_json(['success' => true, 'products' => []]);
        }

        try {
            $products = MealsDB_Quick_Order_Products::search_products($keyword);
            wp_send_json([
                'success'  => true,
                'products' => $products,
            ]);
        } catch (\Throwable $e) {
            error_log('[MealsDB QuickOrder] search_products error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('An error occurred. Please try again.', 'meals-db'),
            ]);
        }
    }

    /**
     * AJAX endpoint to search active clients by name.
     */
    public static function search_clients(): void {
        self::verify_request();

        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        $term = isset($_REQUEST['term']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['term'])) : '';
        if (strlen($term) < 2) {
            wp_send_json(['success' => true, 'clients' => []]);
        }

        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $like  = '%' . $wpdb->esc_like($term) . '%';
        $sql   = $wpdb->prepare(
            "SELECT client_id, wp_user_id, first_name, last_name, client_type
            FROM `{$table}`
            WHERE active = 1
              AND (first_name LIKE %s OR last_name LIKE %s OR CONCAT(first_name, ' ', last_name) LIKE %s)
            ORDER BY last_name ASC, first_name ASC
            LIMIT 25",
            $like,
            $like,
            $like
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            wp_send_json(['success' => true, 'clients' => []]);
        }

        $clients = [];
        foreach ($rows as $row) {
            $clients[] = [
                'client_id'   => (int) $row['client_id'],
                'wp_user_id'  => (int) $row['wp_user_id'],
                'name'        => $row['first_name'] . ' ' . $row['last_name'],
                // Feeds the JS government-invoiced check so per-meal prices are
                // suppressed for SDNB/Veteran clients on the manual-selection
                // path (the clone path already carries this). Not encrypted, so
                // no decryption needed. Empty string when unset → JS fails OPEN
                // (prices show), never suppresses a private client's prices.
                'client_type' => (string) ($row['client_type'] ?? ''),
            ];
        }

        wp_send_json(['success' => true, 'clients' => $clients]);
    }

    /**
     * Create a WC order via Quick Order.
     *
     * NAMING CONVENTION (consistent across this method and the
     * helpers it calls):
     *   $wp_user_id — WP user ID (the customer's WordPress account).
     *   $client_id  — meals_clients.client_id (the PK of the meals
     *                 client record linked to that WP user).
     *
     * The JS frontend posts the WP user ID under the historical
     * parameter name 'client_id' for backward compatibility. A
     * 'wp_user_id' POST parameter is also accepted and takes
     * precedence if both are sent. Internally we use $wp_user_id
     * to remove the previous ambiguity where $client_id sometimes
     * meant WP user and sometimes meant meals PK.
     */
    public static function create_order(): void {
        // Shadow mode: Quick Order is disabled entirely. It creates live
        // WooCommerce orders (plus order meta and usermeta) the legacy
        // system would see, so during the parallel trial it must not run.
        if (MealsDB_Shadow_Mode::is_enabled()) {
            wp_send_json([
                'success' => false,
                'message' => __('Quick Order is disabled while the system is running in shadow mode.', 'meals-db'),
            ]);
        }

        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'mealsdb_quick_order_create_order')) {
            wp_send_json([
                'success' => false,
                'message' => __('Invalid request.', 'meals-db'),
            ]);
        }

        self::verify_request(true);

        // Rate limiting - stricter for order creation
        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_create')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        // Accept either the explicit 'wp_user_id' POST parameter or
        // the historical 'client_id' name (JS frontend still sends
        // 'client_id' — the value has always been a WP user ID).
        $wp_user_id = isset($_POST['wp_user_id'])
            ? intval($_POST['wp_user_id'])
            : (isset($_POST['client_id']) ? intval($_POST['client_id']) : 0);
        $date      = isset($_POST['date']) ? sanitize_text_field(wp_unslash((string) $_POST['date'])) : '';
        // U07-quick-order-4: normalise_items() clamps any per-line qty to 100.
        // Capture which lines were clamped so a silently-reduced order can be
        // surfaced to the operator rather than reported as a plain success.
        $clamped_items = [];
        $items     = self::normalise_items($_POST['items'] ?? [], $clamped_items);
        $rate_id   = isset($_POST['rate_id']) ? intval($_POST['rate_id']) : 0;
        $order_date = self::parse_order_date($date);

        if (
            $wp_user_id <= 0
            || !$order_date instanceof DateTimeImmutable
            || empty($items)
            || !self::user_exists($wp_user_id)
        ) {
            wp_send_json([
                'success' => false,
                'message' => __('Invalid order data.', 'meals-db'),
            ]);
        }

        // Resolve the meals_clients PK for this WP user (0 if no
        // active meals_clients row exists).
        $client_id = self::get_active_client_id_for_user($wp_user_id);

        // A rate_id is meaningless without an active Meals DB client to
        // bind it to. Refuse rather than silently storing an unvalidated
        // rate as order meta — the previous behaviour let any caller stash
        // an arbitrary rate_id against any WP user.
        if ($rate_id > 0) {
            if ($client_id <= 0) {
                wp_send_json([
                    'success' => false,
                    'message' => __('A rate can only be applied to an active Meals DB client.', 'meals-db'),
                ]);
            }
            // MAJ-1: a WP user can own multiple active client records
            // (operator-confirmed dual-program case: e.g. SDNB + Veteran).
            // get_active_client_id_for_user() above picks one arbitrarily
            // (LIMIT 1). The selected rate, however, pins exactly one client
            // (meals_client_rates.rate_id is unique and carries client_id), so
            // resolve the client FROM the rate and target THAT record — this is
            // how the operator routes the order to the intended program. The
            // lookup also re-validates that the rate's client is an ACTIVE
            // record owned by this WP user, so an operator cannot apply another
            // user's rate (this supersedes the old validate_rate_for_client
            // check, which validated against the arbitrary LIMIT 1 client).
            $rate_client_id = self::resolve_active_client_for_rate_user($rate_id, $wp_user_id);
            if ($rate_client_id <= 0) {
                wp_send_json([
                    'success' => false,
                    'message' => __('Invalid rate selection.', 'meals-db'),
                ]);
            }
            $client_id = $rate_client_id;
        }

        try {
            // U07-quick-order-4: create_wc_order() drops any line whose product
            // no longer resolves (e.g. trashed while sitting in the 30-min QO
            // transient cache) rather than failing the whole order. Capture the
            // dropped lines so a short order isn't reported as a plain success.
            $dropped_items = [];
            $order = self::create_wc_order($items, $order_date, $wp_user_id, $client_id, $dropped_items);
            if (is_wp_error($order)) {
                throw new Exception($order->get_error_message());
            }

            $order->update_meta_data('mealsdb_client_user_id', $wp_user_id);

            if ($client_id > 0) {
                $order->update_meta_data('mealsdb_client_id', $client_id);
            }

            if ($rate_id > 0) {
                $order->update_meta_data('mealsdb_rate_id', $rate_id);
            }

            // Manual delivery-date override (delivery-date-override
            // directive, Section A.3): a valid posted delivery_date is
            // written to _delivery_date — the slip pipeline's
            // authoritative selection + header source. Blank/invalid
            // writes nothing (order rides the computed occurrence). This
            // is deliberately NOT fed to persist_next_dates() below:
            // the override is one-time-only and must not re-anchor the
            // client's recurring cadence.
            $delivery_override = self::apply_delivery_date_override(
                $order,
                isset($_POST['delivery_date']) ? wp_unslash((string) $_POST['delivery_date']) : ''
            );

            $order->save();

            // Update operational wp_usermeta fields (matches old admin-pos-order behavior).
            // These are read by the call-log-manager to schedule follow-up calls.
            // Use the actual order timestamp so back-dated orders don't
            // overwrite real "last order" tracking with `now`.
            // last_call_date stays here; last_order_date is written by the
            // allocation hook (MealsDB_Client_Dates::advance_on_order) as
            // Y-m-d, which is the format the next-dates reader expects.
            $order_timestamp = $order_date->format('Y-m-d H:i:s');
            update_user_meta($wp_user_id, 'last_call_date', $order_timestamp);

            // R2: persist the next-order / next-delivery dates the operator
            // confirmed on the form. These become the anchor for the
            // following cycle — see phase-R2-task-workflows Part A
            // ("rule resumes from new anchor").
            self::persist_next_dates($client_id, $wp_user_id, $order_date);

            $order_id = $order->get_id();

            // Audit the creation. Quick Order is the operator's primary
            // order-entry path and was the most significant audit-log
            // gap from directive 16 Pass A. The new_value carries the
            // small structured payload an operator would need to
            // reconstruct what happened: WC order id, target user,
            // meals_clients PK if any, the date the operator picked,
            // and the rate applied.
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log(
                    'quick_order_created',
                    $order_id,
                    'wc_order',
                    null,
                    wp_json_encode([
                        'wp_user_id'    => $wp_user_id,
                        'client_id'     => $client_id,
                        'order_date'    => $order_date->format('Y-m-d'),
                        'delivery_date' => $delivery_override !== '' ? $delivery_override : null,
                        'rate_id'       => $rate_id > 0 ? $rate_id : null,
                        'item_count'    => count($items),
                    ])
                );
            }

            // U07-quick-order-4: an order can be SHORT of what the operator
            // entered in two silent ways — a dropped line (product no longer
            // resolves) or a clamped qty (> 100 reduced to 100). On a
            // meal-delivery billing path a silently short order means a client
            // does not get food, yet the operator otherwise sees a plain
            // "Order created successfully". Record a degraded Event Log row
            // (Pattern 7: swallowed a problem, kept going — don't pretend the
            // work fully happened) so it surfaces on the dashboard/digest, and
            // return the details in the payload so quick-order.js can warn.
            if ((!empty($dropped_items) || !empty($clamped_items)) && class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'    => 'warning',
                    'category'    => 'quick_order',
                    'subsystem'   => 'quick_order_ajax',
                    'event'       => 'create_order.partial',
                    'outcome'     => 'degraded',
                    'message'     => sprintf(
                        'Quick Order %d saved short: %d dropped line(s), %d clamped qty(s).',
                        $order_id,
                        count($dropped_items),
                        count($clamped_items)
                    ),
                    'entity_type' => 'wc_order',
                    'entity_id'   => $order_id,
                    'context'     => [
                        'wp_user_id'    => $wp_user_id,
                        'client_id'     => $client_id,
                        'dropped_items' => $dropped_items,
                        'clamped_items' => $clamped_items,
                    ],
                ]);
            }

            wp_send_json([
                'success' => true,
                'order_id' => $order_id,
                // U07-quick-order-5: the site is HPOS-exclusive, so orders are
                // not real posts. get_edit_post_link() resolves against the
                // 'shop_order_placehold' stub and only yields a working URL via
                // WC's legacy post.php redirect shim (and can return null).
                // WC_Order::get_edit_order_url() is the HPOS-correct edit URL.
                'order_link' => $order->get_edit_order_url(),
                'dropped_items' => $dropped_items,
                'clamped_items' => $clamped_items,
                // Advisory only (soft-warn, don't block): the order IS
                // saved with the override; the JS surfaces this string.
                'delivery_date_warning' => $delivery_override !== ''
                    ? MealsDB_Delivery_Date_Advisor::warning_for(
                        $delivery_override,
                        MealsDB_Delivery_Date_Advisor::expected_day_for_wp_user($wp_user_id)
                    )
                    : '',
            ]);
        } catch (\Throwable $e) {
            error_log('[MealsDB QuickOrder] Order error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('Order creation failed. Please try again.', 'meals-db'),
            ]);
        }
    }

    /**
     * Sanitize + apply the operator's one-time delivery-date override to
     * a freshly built order (delivery-date-override directive, Section
     * A.3). Writes _delivery_date ONLY when the raw value is a real
     * Y-m-d calendar date; returns the applied date, or '' when nothing
     * was written. Public+static so the contract is unit-testable
     * without the full AJAX stack.
     *
     * @param object $order Order-like object exposing update_meta_data().
     * @param mixed  $raw   Raw posted delivery_date value.
     */
    public static function apply_delivery_date_override($order, $raw): string {
        $ymd = MealsDB_Delivery_Date_Advisor::sanitize_ymd($raw);
        if ($ymd === '' || !is_object($order) || !method_exists($order, 'update_meta_data')) {
            return '';
        }
        $order->update_meta_data('_delivery_date', $ymd);
        return $ymd;
    }

    /**
     * AJAX endpoint to retrieve order details and quantities for cloning.
     */
    public static function clone_get_order(): void {
        self::verify_request();

        // Rate limiting
        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        if (!self::woocommerce_is_available()) {
            wp_send_json([
                'success' => false,
                'message' => __('WooCommerce is required to clone orders.', 'meals-db'),
            ]);
        }

        try {
            $source_order_id = isset($_REQUEST['order_id']) ? absint(wp_unslash($_REQUEST['order_id'])) : 0;

            // Generic error message to prevent enumeration
            $source_order = wc_get_order($source_order_id);
            if (!$source_order instanceof WC_Order || $source_order_id <= 0) {
                wp_send_json([
                    'success' => false,
                    'message' => __('Invalid order request.', 'meals-db'),
                ]);
            }

            $items = [];
            foreach ($source_order->get_items('line_item') as $item) {
                if (!$item instanceof WC_Order_Item_Product) {
                    continue;
                }

                $quantity = (int) $item->get_quantity();
                if ($quantity <= 0) {
                    continue;
                }

                $product = $item->get_product();
                if (!$product instanceof WC_Product) {
                    continue;
                }

                $product_id = $product->get_id();
                if ($product_id <= 0) {
                    continue;
                }

                $existing_product = wc_get_product($product_id);
                if (!$existing_product instanceof WC_Product) {
                    continue;
                }

                $items[$product_id] = ($items[$product_id] ?? 0) + $quantity;
            }

            $order_date = '';
            $created    = $source_order->get_date_created();
            if ($created instanceof WC_DateTime) {
                $date = clone $created;
                if (function_exists('wp_timezone')) {
                    $date = $date->setTimezone(wp_timezone());
                }

                $order_date = $date->format('Y-m-d');
            }

            // mealsdb_client_user_id = WP user id; mealsdb_client_id =
            // meals_clients PK. The two meta keys are persistent on
            // existing orders and intentionally NOT renamed (see
            // CLAUDE.md and the directive 15 out-of-scope notes). We
            // only normalize PHP variable names.
            $wp_user_id = intval($source_order->get_meta('mealsdb_client_user_id'));
            $client_id  = intval($source_order->get_meta('mealsdb_client_id'));

            if ($wp_user_id <= 0 && $client_id > 0) {
                $wp_user_id = self::get_user_id_for_client($client_id);
            }

            // Third fallback, mirroring MealsDB_Order_Fees::resolve_government_client():
            // legacy/imported and WooCommerce-native orders carry no mealsdb_* meta, so
            // fall back to the order's native customer_id (HPOS: wc_orders.customer_id IS
            // the WP user id). Without this, cloning any non-Quick-Order order returns a
            // null client and the operator must re-pick it by hand.
            //
            // MAJ-1 guard: a single wp_user_id can LEGITIMATELY back more than one active
            // client (an SDNB recipient who is also a Veteran; a government client who also
            // buys personally). get_active_client_id_for_user() would pick one arbitrarily
            // (LIMIT 1). QO-created orders pin the exact client via mealsdb_client_id meta,
            // so this ambiguity exists only on THIS customer_id path. Rather than guess the
            // wrong duplicate — and have the operator TRUST a wrong auto-fill — only adopt
            // customer_id when it resolves to EXACTLY ONE active client. Zero or multiple
            // leaves it unresolved and the response returns null exactly as before, so the
            // operator selects the client deliberately.
            if ($wp_user_id <= 0) {
                $candidate_user_id = (int) $source_order->get_customer_id();
                if ($candidate_user_id > 0 && self::count_active_clients_for_user($candidate_user_id) === 1) {
                    $wp_user_id = $candidate_user_id;
                }
            }

            // ITEM 4: guard this residual branch with the same MAJ-1 check as the
            // customer_id path above. It's reachable when $wp_user_id came from
            // mealsdb_client_user_id meta WITHOUT mealsdb_client_id (QO writes the
            // user-id meta unconditionally, the client-id meta only when > 0), and
            // get_active_client_id_for_user() is LIMIT 1 with no ORDER BY —
            // nondeterministic when a user backs multiple active clients. Only
            // auto-resolve when the mapping is unambiguous; 0 or >1 leaves
            // $client_id at 0 so the response returns null and the operator picks
            // deliberately. (On the customer_id path this re-counts a value already
            // known to be 1 — a cheap, deliberate redundancy to keep the guard
            // uniform.)
            if ($client_id <= 0 && $wp_user_id > 0
                && self::count_active_clients_for_user($wp_user_id) === 1) {
                $client_id = self::get_active_client_id_for_user($wp_user_id);
            }

            $client_type = '';
            $client_name = '';
            if ($client_id > 0) {
                global $wpdb;

                // Fetch the name parts in the SAME query that already resolves
                // client_type (ITEM 2) so the clone can show the client's name
                // rather than the raw "Client #<wp_user_id>" JS fallback. These
                // columns are NOT in ENCRYPTED_CLIENT_COLUMNS (plaintext), so no
                // decryption is needed.
                $table_name = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT client_type, first_name, last_name FROM `{$table_name}` WHERE client_id = %d LIMIT 1",
                        $client_id
                    ),
                    ARRAY_A
                );

                if (isset($row['client_type'])) {
                    $client_type = (string) $row['client_type'];
                }

                $name_parts = array_filter([
                    isset($row['first_name']) ? trim((string) $row['first_name']) : '',
                    isset($row['last_name']) ? trim((string) $row['last_name']) : '',
                ], static function ($part) {
                    return $part !== '';
                });
                $client_name = implode(' ', $name_parts);
            }

            $rate_id = intval($source_order->get_meta('mealsdb_rate_id'));

            // Drop fee + overage PRODUCT line items from the clone payload.
            // Per-client fees (client_contribution 5675 / delivery_fee 4122)
            // and legacy overage SKUs are carried as real product line items
            // (not WC_Order_Item_Fee) so reconciliation can sum _line_subtotal
            // uniformly — so they show up in $items here. They are NOT in the
            // Quick Order catalog, so if left in they resolve to null on the
            // client and get filed under "missing", rendering as bogus
            // "unavailable" tiles and inflating the not-added warning. They
            // must not be cloned as cart lines anyway: Order_Fees re-applies
            // the fee at creation from the client's per-client columns. Use
            // the CONFIGURED ids (option overlay), matching Order_Fees.
            $excluded_ids = [];
            if (class_exists('MealsDB_Invoice_Generator')
                && method_exists('MealsDB_Invoice_Generator', 'get_fee_product_ids')) {
                $excluded_ids = array_merge($excluded_ids, array_values(MealsDB_Invoice_Generator::get_fee_product_ids()));
            } elseif (class_exists('MealsDB_Operational_Constants')) {
                $excluded_ids = array_merge($excluded_ids, array_values(MealsDB_Operational_Constants::default_fee_product_ids()));
            }
            if (class_exists('MealsDB_Operational_Constants')) {
                $excluded_ids = array_merge($excluded_ids, array_values(MealsDB_Operational_Constants::overage_product_ids()));
            }
            foreach ($excluded_ids as $excluded_id) {
                unset($items[(int) $excluded_id]);
            }

            // Resolve the QO product payloads for the remaining cloned ids so
            // the client can put them straight into the cart. Without this the
            // JS resolves every id to null and files all of them under
            // "missing" (empty cart, false success). Reuse the QO product
            // builder (transient-cached — not a per-product query storm); ids
            // that resolve to no QO payload legitimately fall through to
            // "missing" (genuinely delisted product), which the unavailable-
            // tile UI is for. Cast to object so a 0..n-sequential or empty map
            // still encodes as {} — an array would break productData[id] lookups.
            $products = [];
            if (!empty($items) && class_exists('MealsDB_Quick_Order_Products')) {
                foreach (MealsDB_Quick_Order_Products::get_all_quick_order_products() as $payload) {
                    $pid = isset($payload['product_id']) ? (int) $payload['product_id'] : 0;
                    if ($pid > 0 && isset($items[$pid])) {
                        $products[$pid] = $payload;
                    }
                }
            }

            // The JS frontend sends `client_id` containing a WP user
            // ID when it later calls create_order, so the response
            // key here stays `client_id` (=WP user id) to keep the
            // existing JS contract working. The local variable is
            // now $wp_user_id for clarity.
            //
            // Clone response contract (the full surface, in one place):
            //   success, client_id (=WP user id), client_type, client_name,
            //   order_date, items, products, rate_id.
            // client_name is '' when client_id is unresolvable (MAJ-1 guard or
            // no active client); the JS then falls back to "Client #<id>".
            wp_send_json([
                'success'     => true,
                'client_id'   => $wp_user_id > 0 ? $wp_user_id : null,
                'client_type' => $client_type,
                'client_name' => $client_name,
                'order_date'  => $order_date,
                'items'       => (object) $items,
                'products'    => (object) $products,
                'rate_id'     => $rate_id > 0 ? $rate_id : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[MealsDB QuickOrder] clone_get_order error: ' . $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('An error occurred. Please try again.', 'meals-db'),
            ]);
        }
    }

    /**
     * Confirm WooCommerce classes and helpers are available before cloning orders.
     */
    private static function woocommerce_is_available(): bool
    {
        return function_exists('wc_get_order')
            && class_exists('WC_Order')
            && class_exists('WC_Order_Item_Product')
            && class_exists('WC_Product');
    }

    /**
     * Ensure the AJAX request is valid and user is authorised.
     */
    private static function verify_request(bool $nonce_already_verified = false): void {
        if (!$nonce_already_verified) {
            $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['nonce'])) : '';
            if ($nonce === '' || !wp_verify_nonce($nonce, 'mealsdb_nonce')) {
                wp_send_json([
                    'success' => false,
                    'message' => __('Invalid or missing nonce.', 'meals-db'),
                ]);
            }
        }

        if (!self::current_user_can_access_quick_order()) {
            wp_send_json([
                'success' => false,
                'message' => __('You are not allowed to perform this action.', 'meals-db'),
            ], 403);
        }
    }

    /**
     * Determine whether the current user may access Quick Order endpoints.
     */
    private static function current_user_can_access_quick_order(): bool {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        $capability = MealsDB_Permissions::required_capability();
        if (!is_string($capability) || $capability === '') {
            $capability = 'manage_woocommerce';
        }

        return current_user_can($capability);
    }

    /**
     * Confirm the provided WordPress user exists.
     */
    private static function user_exists(int $user_id): bool {
        if ($user_id <= 0) {
            return false;
        }

        return (bool) get_userdata($user_id);
    }

    /**
     * Lookup an active Meals DB client ID for a given WordPress user.
     */
    private static function get_active_client_id_for_user(int $user_id): int {
        if ($user_id <= 0) {
            return 0;
        }

        global $wpdb;

        $table_name = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT client_id FROM `{$table_name}` WHERE wp_user_id = %d AND active = 1 LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );

        if (!is_array($row) || !isset($row['client_id'])) {
            return 0;
        }

        return (int) $row['client_id'];
    }

    /**
     * Count ACTIVE Meals DB client rows bound to a WP user id.
     *
     * Supports the MAJ-1 guard on the clone client-resolution path: a WP user
     * can legitimately back more than one active client (there is no UNIQUE on
     * meals_clients.wp_user_id — intentional, see CLAUDE.md MAJ-1), so before
     * auto-selecting a client from an order's native customer_id we confirm the
     * mapping is unambiguous (exactly one). Returns 0 for a non-positive id.
     */
    private static function count_active_clients_for_user(int $user_id): int {
        if ($user_id <= 0) {
            return 0;
        }

        global $wpdb;

        $table_name = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table_name}` WHERE wp_user_id = %d AND active = 1",
                $user_id
            )
        );

        return (int) $count;
    }

    /**
     * Resolve a WordPress user ID for an existing Meals DB client record.
     */
    private static function get_user_id_for_client(int $client_id): int {
        if ($client_id <= 0) {
            return 0;
        }

        global $wpdb;

        $table_name = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT wp_user_id FROM `{$table_name}` WHERE client_id = %d AND active = 1 LIMIT 1",
                $client_id
            ),
            ARRAY_A
        );

        if (!is_array($row) || !isset($row['wp_user_id'])) {
            return 0;
        }

        return (int) $row['wp_user_id'];
    }

    /**
     * Parse the incoming order date into a DateTimeImmutable instance.
     *
     * Strict YYYY-MM-DD only. The previous fallback to
     *   new DateTimeImmutable($date, $tz)
     * accepted loose forms like "tomorrow", "+3 days", or "1990-01-01",
     * letting a caller backdate orders to arbitrary historical timestamps.
     */
    private static function parse_order_date(string $date): ?DateTimeImmutable {
        $date = trim($date);
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $parsed   = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);

        if ($parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date) {
            return $parsed;
        }

        return null;
    }

    /**
     * Normalise the posted items list.
     *
     * @param mixed $raw_items Raw item payload from the request.
     * @param array $clamped   Out-param: lines whose qty was clamped to 100,
     *                         as {product_id, requested, applied}. U07-quick-order-4.
     *
     * @return array<int, array<string, int>>
     */
    private static function normalise_items($raw_items, array &$clamped = []): array {
        if (is_string($raw_items)) {
            // U07-quick-order-6: WordPress slash-escapes all of $_POST
            // (wp_magic_quotes), so a JSON payload arrives as [{\"product_id\":5}]
            // and json_decode() fails on the backslashes without wp_unslash().
            // Today all callers post a form-encoded array (this branch is not
            // taken), but unslash first so the JSON path actually works for any
            // future caller instead of silently decoding to null and returning [].
            $decoded = json_decode(function_exists('wp_unslash') ? wp_unslash($raw_items) : $raw_items, true);
            if (is_array($decoded)) {
                $raw_items = $decoded;
            }
        }

        if (!is_array($raw_items)) {
            return [];
        }

        $items = [];
        foreach ($raw_items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product_id = isset($item['product_id']) ? intval($item['product_id']) : 0;
            $quantity   = isset($item['quantity']) ? intval($item['quantity']) : 0;
            $variation  = isset($item['variation_id']) ? intval($item['variation_id']) : 0;

            if ($product_id <= 0 || $quantity <= 0) {
                continue;
            }

            // Cap per-line quantity so a fat-fingered "5000" can't create a
            // runaway order at catalog price. 100 meals/line is well past any
            // real single-client order. U07-quick-order-4: record the clamp so
            // the caller can WARN the operator — silently mutating a deliberate
            // large order down to 100 reads as "created successfully" while
            // billing/delivering fewer meals.
            if ($quantity > 100) {
                $clamped[] = [
                    'product_id' => $product_id,
                    'requested'  => $quantity,
                    'applied'    => 100,
                ];
                $quantity = 100;
            }

            $items[] = [
                'product_id'   => $product_id,
                'quantity'     => $quantity,
                'variation_id' => $variation,
            ];
        }

        return $items;
    }

    /**
     * Create a WooCommerce order populated with the provided items.
     *
     * @param array<int, array<string, int>> $items
     * @param DateTimeImmutable|null         $order_date
     * @param int                            $wp_user_id WordPress user ID assigned as the order customer.
     * @param int                            $client_id  meals_clients.client_id (PK); 0 if the customer has no meals client record.
     * @param array                          $dropped_items Out-param (U07-quick-order-4): lines skipped because
     *                                                      wc_get_product() returned nothing, as
     *                                                      {product_id, variation_id, quantity}.
     *
     * @return WC_Order|WP_Error
     */
    private static function create_wc_order(array $items, ?DateTimeImmutable $order_date, int $wp_user_id = 0, int $client_id = 0, array &$dropped_items = []) {
        if (!function_exists('wc_create_order') || !class_exists('WC_Order')) {
            return new WP_Error('mealsdb_missing_woocommerce', __('WooCommerce is required to create orders.', 'meals-db'));
        }

        $order = wc_create_order();
        if (is_wp_error($order)) {
            return $order;
        }

        if ($wp_user_id > 0) {
            $order->set_customer_id($wp_user_id);
        }

        $added_count   = 0;
        $dropped_items = [];

        foreach ($items as $item) {
            $product_id   = $item['product_id'];
            $variation_id = $item['variation_id'] ?? 0;
            $quantity     = $item['quantity'];

            $product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($product_id);
            if (!$product instanceof WC_Product) {
                $dropped_items[] = [
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                    'quantity'     => $quantity,
                ];
                continue;
            }

            // U07-quick-order-16: wc_get_product() still returns TRASHED
            // products, so a product trashed while it was sitting in the 30-min
            // QO transient cache would otherwise be added to the new order at its
            // stale price. Treat a trashed product (or a variation whose parent
            // product is trashed) as a dropped line so the operator is WARNED
            // (Pattern 7 degraded event, U07-quick-order-4 infra) rather than
            // silently sold a deleted item. The cache-invalidation hooks in
            // class-quick-order-products.php normally evict it first; this is the
            // race-window backstop.
            $is_trashed = $product->get_status() === 'trash';
            if (!$is_trashed && $product instanceof WC_Product_Variation) {
                $parent = wc_get_product($product->get_parent_id());
                $is_trashed = $parent instanceof WC_Product && $parent->get_status() === 'trash';
            }
            if ($is_trashed) {
                $dropped_items[] = [
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                    'quantity'     => $quantity,
                ];
                continue;
            }

            if ($variation_id > 0 && $product instanceof WC_Product_Variation) {
                $order->add_product($product, $quantity, [
                    'variation' => $product->get_attributes(),
                ]);
            } else {
                $order->add_product($product, $quantity);
            }
            $added_count++;
        }

        if (!empty($dropped_items)) {
            error_log(sprintf(
                '[MealsDB QuickOrder] Dropped %d item(s) from order (wp_user_id=%d, client_id=%d) because wc_get_product() returned no product: %s',
                count($dropped_items),
                $wp_user_id,
                $client_id,
                wp_json_encode($dropped_items)
            ));
        }

        if ($added_count === 0) {
            $order->delete(true);
            return new WP_Error('mealsdb_no_valid_products', __('No valid products could be added to the order.', 'meals-db'));
        }

        // NOTE: Quick Order no longer applies the delivery fee or monthly
        // client contribution here. Those are applied uniformly by
        // MealsDB_Order_Fees (driven from the allocation hook on
        // woocommerce_new_order / status transitions) so that EVERY
        // qualifying order gets them — not only orders placed through this
        // interface. Quick Order is now purely a faster data-entry UI and
        // does nothing WooCommerce-native order creation wouldn't do.
        // See includes/services/class-order-fees.php.

        $order->calculate_totals();

        // Sanity check: a negative total indicates fee/contribution misconfiguration
        // or a hook mutating line prices. Reject rather than persist a bad order.
        if ((float) $order->get_total() < 0) {
            error_log(sprintf(
                '[MealsDB QuickOrder] Refusing to save order %d: computed total is negative (%s) for wp_user_id=%d client_id=%d',
                $order->get_id(),
                $order->get_total(),
                $wp_user_id,
                $client_id
            ));
            $order->delete(true);
            return new WP_Error('mealsdb_invalid_total', __('Order total calculation failed. Please try again.', 'meals-db'));
        }

        if ($order_date instanceof DateTimeImmutable) {
            try {
                $wc_date = new WC_DateTime($order_date->format('Y-m-d H:i:s'), $order_date->getTimezone());
                $order->set_date_created($wc_date);
            } catch (\Throwable $e) {
                // Ignore date parsing errors and keep default creation date.
            }
        }

        // Quick Order creates operator-entered delivery orders, not card-payment
        // e-commerce orders. Put them straight into an active, slip-eligible status so
        // they (a) appear on packer/driver slip runs and (b) fire the allocation +
        // fee/contribution hooks on the pending->processing transition, like a normal
        // placed order. Without this they default to wc-pending, which the slip query
        // excludes — and because woocommerce_new_order fires inside wc_create_order()
        // on a still-EMPTY order (no customer, no items, no meta), the fee applier
        // no-ops at creation and nothing ever re-runs it. The pending->processing
        // transition at the caller's save() is what re-runs fees/allocation against
        // the populated order. Kept AFTER the rejection guards above (never activate
        // an order we're about to delete); the caller's save() persists the status
        // and fires the woocommerce_order_status_* transition hooks.
        // See DIRECTIVE-quick-order-status-fix.md.
        $order->set_status('processing', __('Created via Meals DB Quick Order.', 'meals-db'));

        return $order;
    }

    /**
     * Resolve the active meals_clients.client_id that owns a given rate FOR a
     * given WP user, or 0 if the rate does not belong to an active client of
     * that user.
     *
     * MAJ-1: a WP user can own multiple active client records (dual-program:
     * SDNB + Veteran). The selected rate pins exactly one of them
     * (meals_client_rates.rate_id is unique and carries client_id), so the
     * order can be routed to the intended program rather than the arbitrary
     * `wp_user_id ... LIMIT 1` client. The join to meals_clients enforces, in
     * one query, that the rate's client is (a) owned by this WP user and (b)
     * active — so an operator cannot apply another user's rate, and the
     * returned id is the correct program record to bill.
     *
     * @return int client_id, or 0 when the rate is not a valid selection.
     */
    private static function resolve_active_client_for_rate_user(int $rate_id, int $wp_user_id): int {
        if ($rate_id <= 0 || $wp_user_id <= 0) {
            return 0;
        }

        global $wpdb;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $rates_table   = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES);

        $client_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT c.client_id
                   FROM `{$rates_table}` r
                   JOIN `{$clients_table}` c ON c.client_id = r.client_id
                  WHERE r.rate_id = %d AND c.wp_user_id = %d AND c.active = 1
                  LIMIT 1",
                $rate_id,
                $wp_user_id
            )
        );

        return (int) $client_id;
    }

    /**
     * Save the next_order_date / next_delivery_date values the operator
     * confirmed on the Quick Order form to meals_clients. If the form
     * didn't supply values, fall back to the "rule default" — the just-
     * placed order date + the client's configured frequency — so the
     * next cycle always has an anchor.
     */
    private static function persist_next_dates(int $client_id, int $wp_user_id, DateTimeImmutable $order_date): void {
        if ($client_id <= 0) {
            return;
        }

        $submitted_order    = self::sanitize_date_input($_POST['next_order_date'] ?? null);
        $submitted_delivery = self::sanitize_date_input($_POST['next_delivery_date'] ?? null);

        // Load frequencies so we can compute the rule-default when the form
        // didn't provide them (e.g. older JS deployed against newer PHP).
        global $wpdb;
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ordering_frequency, delivery_frequency, delivery_day FROM `{$clients_table}` WHERE client_id = %d",
                $client_id
            ),
            ARRAY_A
        );

        $ordering_freq = isset($client['ordering_frequency']) ? (int) $client['ordering_frequency'] : 0;
        $delivery_freq = isset($client['delivery_frequency']) ? (int) $client['delivery_frequency'] : 0;
        $delivery_day  = $client['delivery_day'] ?? null;
        $order_date_ymd = $order_date->format('Y-m-d');

        // The form auto-fills next_order/next_delivery with the computed
        // dates and the operator may edit them; whatever is submitted wins.
        // Only fall back to computing (via the shared calculator — weeks,
        // snapped to delivery day) when the form supplied nothing.
        $patch = [];
        if ($submitted_order !== null) {
            $patch['next_order_date'] = $submitted_order;
        } else {
            $computed = MealsDB_Date_Calculator::next_date($order_date_ymd, $ordering_freq, $delivery_day);
            if ($computed !== null) {
                $patch['next_order_date'] = $computed;
            }
        }
        if ($submitted_delivery !== null) {
            $patch['next_delivery_date'] = $submitted_delivery;
        } else {
            $computed = MealsDB_Date_Calculator::next_date($order_date_ymd, $delivery_freq, $delivery_day);
            if ($computed !== null) {
                $patch['next_delivery_date'] = $computed;
            }
        }

        if (empty($patch)) {
            return;
        }

        $updated = $wpdb->update($clients_table, $patch, ['client_id' => $client_id]);
        if ($updated === false) {
            error_log('[MealsDB QuickOrder] Failed to persist next_order/delivery_date: ' . $wpdb->last_error);
            return;
        }

        // Mirror to usermeta so the sync layer and any external consumers
        // stay coherent with the map defined in class-sync.php.
        if ($wp_user_id > 0 && function_exists('update_user_meta')) {
            foreach ($patch as $col => $value) {
                $meta_key = $col === 'next_order_date' ? 'mealsdb_next_order_date' : 'mealsdb_next_delivery_date';
                update_user_meta($wp_user_id, $meta_key, $value);
            }
        }
    }

    private static function sanitize_date_input($raw): ?string {
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim(wp_unslash($raw));
        if ($raw === '') {
            return null;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null;
    }

    /**
     * AJAX endpoint: read the client's stored next_order_date /
     * next_delivery_date plus the rule-defaults that would apply if today's
     * order follows the standard cadence. Used by the Quick Order UI panel.
     */
    public static function get_next_dates(): void {
        self::verify_request();

        // Rate-limit like every sibling QO read endpoint (this one was missing it).
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
        }

        // JS posts `client_id` containing a WP user ID (historical
        // contract). Accept `wp_user_id` too for callers that prefer
        // the explicit name.
        $wp_user_id = isset($_REQUEST['wp_user_id'])
            ? (int) $_REQUEST['wp_user_id']
            : (isset($_REQUEST['client_id']) ? (int) $_REQUEST['client_id'] : 0);
        $order_date_str = isset($_REQUEST['order_date']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['order_date'])) : '';

        if ($wp_user_id <= 0) {
            wp_send_json_error(['message' => __('Client id required.', 'meals-db')]);
        }

        $client_id = self::get_active_client_id_for_user($wp_user_id);
        if ($client_id <= 0) {
            wp_send_json_success([
                'has_client'      => false,
                'next_order_date' => null,
                'next_delivery_date' => null,
                'rule_default_order'    => null,
                'rule_default_delivery' => null,
            ]);
        }

        global $wpdb;
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        // delivery_area_name feeds the zone-schedule fallback in
        // resolve_delivery_prefill() (A2/B7 directive): a not-yet-resynced
        // row has blank delivery_day / next_delivery_date, and echoing those
        // starved the QO prefill and day-mismatch warning of data.
        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ordering_frequency, delivery_frequency, delivery_day, delivery_area_name,
                        next_order_date, next_delivery_date
                 FROM `{$clients_table}` WHERE client_id = %d",
                $client_id
            ),
            ARRAY_A
        );

        if (!is_array($client)) {
            wp_send_json_error(['message' => __('Client record not found.', 'meals-db')]);
        }

        // U07-quick-order-8: anchor date math in the site timezone (matching
        // parse_order_date()), not the server default TZ. Otherwise the "now"
        // fallback resolves to tomorrow late in the local evening in Moncton.
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        try {
            $order_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $order_date_str)
                ? new DateTimeImmutable($order_date_str, $tz)
                : new DateTimeImmutable('now', $tz);
        } catch (Throwable $e) {
            $order_date = new DateTimeImmutable('now', $tz);
        }

        $ordering_freq = (int) ($client['ordering_frequency'] ?? 0);
        $delivery_freq = (int) ($client['delivery_frequency'] ?? 0);
        $delivery_day  = $client['delivery_day'] ?? null;
        $order_date_ymd = $order_date->format('Y-m-d');

        $rule_order    = MealsDB_Date_Calculator::next_date($order_date_ymd, $ordering_freq, $delivery_day);
        $rule_delivery = MealsDB_Date_Calculator::next_date($order_date_ymd, $delivery_freq, $delivery_day);

        // A2/B7 directive: derive reliable prefill values instead of echoing
        // possibly-blank stored columns. rule_default_* above deliberately
        // keep the stored-day behaviour (directive step 3: unchanged).
        $prefill = self::resolve_delivery_prefill($client, $order_date_ymd);

        wp_send_json_success([
            'has_client'            => true,
            'next_order_date'       => $client['next_order_date'] ?: null,
            'next_delivery_date'    => $prefill['next_delivery_date'],
            'rule_default_order'    => $rule_order,
            'rule_default_delivery' => $rule_delivery,
            // Delivery-date-override directive (Section A): the client's
            // canonical delivery day, so the JS soft-warning can flag an
            // off-day override without another round trip.
            'delivery_day'          => $prefill['delivery_day'],
            // U07-quick-order-1: these two keys previously emitted the
            // never-defined $ordering_days / $delivery_days — an E_WARNING on
            // every call and a null field for any consumer. Emit the actual
            // integer frequencies resolved above (matching the key names).
            'ordering_frequency'    => $ordering_freq,
            'delivery_frequency'    => $delivery_freq,
        ]);
    }

    /**
     * Reliable delivery-day + next-delivery-date for the QO prefill and
     * day-mismatch warning (A2/B7 directive). Pure: derives from the row
     * the caller already loaded — NOT expected_day_for_wp_user(), whose
     * own `wp_user_id ... LIMIT 1` query can pick a DIFFERENT client than
     * the get_active_client_id_for_user() resolution under the MAJ-1
     * duplicate-wp_user_id case.
     *
     * delivery_day: stored column first (lowercased), zone-schedule
     * fallback when blank — the same precedence expected_day_for_wp_user()
     * applies internally, so QO and the WC order-edit warning agree even
     * on a stale stored day. Do not flip to zone-first.
     *
     * next_delivery_date: stored column first; when blank, computed with
     * the SLIP occurrence semantics (delivery_occurrence_for_order:
     * same-week snap, roll a cycle only once the weekday has passed) —
     * NOT Date_Calculator::next_date(), which always projects a full
     * cycle and would prefill one week late whenever the delivery day is
     * still upcoming. The prefill is written as the _delivery_date
     * override on create, so a late prefill would actively delay real
     * deliveries; parity with the slip's no-override fallback is the
     * correctness bar.
     *
     * @param array<string, mixed> $client         Row with delivery_day,
     *                                             delivery_area_name,
     *                                             next_delivery_date,
     *                                             delivery_frequency.
     * @param string               $order_date_ymd Anchor date (site-TZ Y-m-d).
     * @return array{delivery_day: ?string, next_delivery_date: ?string}
     */
    public static function resolve_delivery_prefill(array $client, string $order_date_ymd): array {
        $delivery_day = strtolower(trim((string) ($client['delivery_day'] ?? '')));
        if ($delivery_day === '') {
            $delivery_day = (string) (MealsDB_Zone_Day::day_for_zone(
                isset($client['delivery_area_name']) ? (string) $client['delivery_area_name'] : null
            ) ?? '');
        }

        $next_delivery = trim((string) ($client['next_delivery_date'] ?? ''));
        if ($next_delivery === '' && $delivery_day !== '') {
            // delivery_occurrence_for_order defaults a missing/zero
            // frequency to 1 (weekly) — deliberate parity with the slip
            // pipeline, not a bug.
            $next_delivery = (string) MealsDB_Delivery_Slip_Generator::delivery_occurrence_for_order(
                $order_date_ymd,
                [
                    'delivery_day'       => $delivery_day,
                    'delivery_frequency' => (int) ($client['delivery_frequency'] ?? 0),
                ]
            );
        }

        return [
            'delivery_day'       => $delivery_day !== '' ? $delivery_day : null,
            'next_delivery_date' => $next_delivery !== '' ? $next_delivery : null,
        ];
    }

    /**
     * AJAX endpoint to fetch the current month's allocation summary for a client.
     */
    public static function get_client_allocation(): void {
        self::verify_request();

        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        $wp_user_id = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']) : 0;
        if ($wp_user_id <= 0) {
            wp_send_json(['success' => true, 'allocation' => null]);
        }

        global $wpdb;
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT client_id, client_type, delivery_frequency,
                    delivery_fee, client_contribution, delivery_area_zone
             FROM {$clients_table}
             WHERE wp_user_id = %d AND active = 1
             LIMIT 1",
            $wp_user_id
        ), ARRAY_A);

        if (!$client) {
            wp_send_json(['success' => true, 'allocation' => null]);
        }

        $client_id   = (int) $client['client_id'];
        $client_type = $client['client_type'];
        // Plain column (not in ENCRYPTED_CLIENT_COLUMNS). Sent at the TOP LEVEL
        // of the response — NOT inside `allocation`, which only exists for
        // SDNB/Veteran — so private clients (the bulk of zoned clients) get it too.
        $delivery_area_zone = isset($client['delivery_area_zone']) && $client['delivery_area_zone'] !== ''
            ? (string) $client['delivery_area_zone'] : null;
        // U07-quick-order-8: orders are dated in the SITE timezone
        // (parse_order_date() uses wp_timezone()), so the allocation summary is
        // keyed on the site-local month. Deriving the preview month with UTC
        // gmdate() would query NEXT month's (empty) summary for the last few
        // evening hours of each month in Moncton (UTC-3/-4). Use current_time()
        // so the preview matches how orders are actually bucketed.
        $billing_month = current_time('Y-m');

        if (!in_array($client_type, ['SDNB', 'Veteran'], true)) {
            wp_send_json(['success' => true, 'allocation' => null, 'client_type' => $client_type, 'delivery_area_zone' => $delivery_area_zone]);
        }

        $engine  = new MealsDB_Allocation_Engine();
        $summary = $engine->get_client_month_summary($client_id, $billing_month);

        if (!$summary) {
            $engine->recalculate_month_totals($client_id, $billing_month);
            $summary = $engine->get_client_month_summary($client_id, $billing_month);
        }

        $schedule = $engine->calculate_delivery_schedule($client_id, $billing_month);
        // U07-quick-order-8: "next delivery" is compared against today's date;
        // use the site-local day (matching order dating) rather than UTC so the
        // comparison doesn't roll to tomorrow late in the local evening.
        $today    = current_time('Y-m-d');
        $next_delivery = null;
        foreach ($schedule as $delivery) {
            if ($delivery['delivery_date'] >= $today) {
                $next_delivery = $delivery['delivery_date'];
                break;
            }
        }

        $straddles        = false;
        $next_month_units = 0;
        if ($next_delivery) {
            $delivery_frequency = (int) ($client['delivery_frequency'] ?? 7);
            $coverage_end_date  = gmdate('Y-m-d', strtotime($next_delivery . ' + ' . ($delivery_frequency - 1) . ' days'));
            $delivery_month     = substr($next_delivery, 0, 7);
            $coverage_end_month = substr($coverage_end_date, 0, 7);
            if ($coverage_end_month !== $delivery_month) {
                $straddles              = true;
                $end_of_delivery_month  = gmdate('Y-m-t', strtotime($next_delivery));
                $days_next_month        = (strtotime($coverage_end_date) - strtotime($end_of_delivery_month)) / 86400;
                $next_month_units       = $days_next_month;
            }
        }

        // Compute fee preview for government clients.
        $delivery_fee = (float) ($client['delivery_fee'] ?? 0);
        $client_contribution = (float) ($client['client_contribution'] ?? 0);
        $contribution_applied = false;

        if ($summary) {
            $contribution_applied = (bool) ($summary['contribution_applied'] ?? false);
        }

        $contribution_due = (!$contribution_applied && $client_contribution > 0);
        $collect_total = $delivery_fee + ($contribution_due ? $client_contribution : 0);

        wp_send_json([
            'success'            => true,
            'client_type'        => $client_type,
            'delivery_area_zone' => $delivery_area_zone,
            'allocation'         => $summary ? [
                'billing_month'   => $summary['billing_month'],
                'permitted_mains' => (int) $summary['permitted_mains'],
                'permitted_sides' => (int) $summary['permitted_sides'],
                'used_mains'      => (int) $summary['used_mains'],
                'used_sides'      => (int) $summary['used_sides'],
                'remaining_mains' => max((int) $summary['permitted_mains'] - (int) $summary['used_mains'], 0),
                'remaining_sides' => max((int) $summary['permitted_sides'] - (int) $summary['used_sides'], 0),
                'overage_mains'   => (int) $summary['overage_mains'],
                'overage_sides'   => (int) $summary['overage_sides'],
                'is_finalized'    => (bool) $summary['is_finalized'],
            ] : null,
            'fees'              => [
                'delivery_fee'        => $delivery_fee,
                'client_contribution' => $client_contribution,
                'contribution_due'    => $contribution_due,
                'collect_total'       => $collect_total,
            ],
            'next_delivery'     => $next_delivery,
            'straddles_month'   => $straddles,
            'next_month_units'  => $next_month_units,
        ]);
    }

    /**
     * AJAX endpoint to fetch allocation history for a client.
     */
    public static function get_client_allocation_history(): void {
        self::verify_request();

        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        $client_id = isset($_REQUEST['client_id']) ? intval($_REQUEST['client_id']) : 0;
        if ($client_id <= 0) {
            wp_send_json(['success' => false, 'message' => 'Invalid client ID.']);
        }

        // Validate billing_month format (YYYY-MM). The downstream queries
        // use prepared statements so there is no injection vector, but
        // rejecting malformed input surfaces typos early rather than
        // silently returning an empty result set.
        $billing_month = isset($_REQUEST['billing_month'])
            ? sanitize_text_field(wp_unslash((string) $_REQUEST['billing_month']))
            : gmdate('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $billing_month)) {
            wp_send_json(['success' => false, 'message' => 'Invalid billing month.']);
        }

        $engine  = new MealsDB_Allocation_Engine();
        $history = $engine->get_client_history($client_id, 12);

        $details = $engine->get_client_month_details($client_id, $billing_month);
        // Flag whether each order still exists so the client-side renders a live
        // link vs. plain text — a deleted order (e.g. #28528) must not become a
        // dead link, and the ledger row can outlive the WC order. HPOS-correct.
        if (function_exists('wc_get_order')) {
            foreach ($details as &$detail_row) {
                $oid = isset($detail_row['wc_order_id']) ? (int) $detail_row['wc_order_id'] : 0;
                $detail_row['order_exists'] = ($oid > 0 && wc_get_order($oid) instanceof WC_Order);
            }
            unset($detail_row);
        }

        wp_send_json([
            'success'       => true,
            'history'       => $history,
            'month_details' => $details,
        ]);
    }
}
