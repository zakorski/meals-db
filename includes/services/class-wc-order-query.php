<?php
/**
 * WooCommerce HPOS order query service for Meals DB.
 *
 * Single point of access for all queries against WC HPOS tables.
 * Invoice generators, slip generators, and reports should use this
 * class rather than querying WC tables directly.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_WC_Order_Query {

    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * @param wpdb $wpdb WordPress database abstraction.
     */
    public function __construct(wpdb $wpdb) {
        $this->wpdb = $wpdb;
    }

    /**
     * Fetch orders for the given WP user IDs within a date range.
     *
     * @param int[]    $wp_user_ids      WordPress user IDs.
     * @param string   $start_date       Start date (Y-m-d).
     * @param string   $end_date         End date (Y-m-d).
     * @param string[] $exclude_statuses Order statuses to exclude.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_orders_for_users(
        array $wp_user_ids,
        string $start_date,
        string $end_date,
        array $exclude_statuses = ['wc-cancelled', 'wc-on-hold', 'wc-draft', 'draft', 'wc-trash', 'trash']
    ): array {
        $wp_user_ids = array_filter(array_map('intval', $wp_user_ids));
        if (empty($wp_user_ids)) {
            return [];
        }

        $orders_table = $this->orders_table();
        $meta_table   = $this->orders_meta_table();

        $user_placeholders   = implode(',', array_fill(0, count($wp_user_ids), '%d'));
        $status_placeholders = implode(',', array_fill(0, count($exclude_statuses), '%s'));

        // The LEFT JOIN to wc_orders_meta on a non-unique (order_id,
        // meta_key) is unsafe — multiple matching rows multiply the
        // result set and double-count orders. Pull the rate via a
        // correlated subquery that returns at most one value per order.
        $sql = "
            SELECT
                o.id              AS order_id,
                o.customer_id     AS wp_user_id,
                o.status,
                o.date_created_gmt,
                o.total_amount,
                o.tax_amount,
                (
                    SELECT m.meta_value
                    FROM {$meta_table} m
                    WHERE m.order_id = o.id AND m.meta_key = 'mealsdb_rate_id'
                    ORDER BY m.id ASC
                    LIMIT 1
                ) AS mealsdb_rate_id
            FROM {$orders_table} o
            WHERE o.customer_id IN ({$user_placeholders})
                AND o.date_created_gmt >= %s
                AND o.date_created_gmt < %s
                AND o.status NOT IN ({$status_placeholders})
                AND o.type = 'shop_order'
            ORDER BY o.date_created_gmt ASC
        ";

        // End date is inclusive (full day), so advance to the next day for
        // < comparison. Use gmdate() so the boundary doesn't drift across
        // DST transitions in the server-local timezone.
        $end_date_exclusive = gmdate('Y-m-d', strtotime($end_date . ' +1 day UTC'));

        $params = array_merge(
            $wp_user_ids,
            [$start_date, $end_date_exclusive],
            $exclude_statuses
        );

        $prepared = $this->wpdb->prepare($sql, $params);
        $rows     = $this->wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Fetch line items for the given order IDs.
     *
     * @param int[] $order_ids WC order IDs.
     *
     * @return array<int, array<string, mixed>> Flat array of item rows.
     */
    public function get_order_items(array $order_ids): array {
        $order_ids = array_filter(array_map('intval', $order_ids));
        if (empty($order_ids)) {
            return [];
        }

        $items_table    = $this->order_items_table();
        $itemmeta_table = $this->order_itemmeta_table();

        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));

        $sql = "
            SELECT
                oi.order_item_id,
                oi.order_id,
                oi.order_item_name,
                product_meta.meta_value   AS wc_product_id,
                qty_meta.meta_value       AS quantity,
                subtotal_meta.meta_value  AS line_subtotal,
                tax_meta.meta_value       AS line_tax,
                total_meta.meta_value     AS line_total
            FROM {$items_table} oi
            INNER JOIN {$itemmeta_table} product_meta
                ON product_meta.order_item_id = oi.order_item_id
                AND product_meta.meta_key = '_product_id'
            INNER JOIN {$itemmeta_table} qty_meta
                ON qty_meta.order_item_id = oi.order_item_id
                AND qty_meta.meta_key = '_qty'
            INNER JOIN {$itemmeta_table} subtotal_meta
                ON subtotal_meta.order_item_id = oi.order_item_id
                AND subtotal_meta.meta_key = '_line_subtotal'
            LEFT JOIN {$itemmeta_table} tax_meta
                ON tax_meta.order_item_id = oi.order_item_id
                AND tax_meta.meta_key = '_line_tax'
            INNER JOIN {$itemmeta_table} total_meta
                ON total_meta.order_item_id = oi.order_item_id
                AND total_meta.meta_key = '_line_total'
            WHERE oi.order_id IN ({$placeholders})
                AND oi.order_item_type = 'line_item'
            ORDER BY oi.order_id, oi.order_item_id
        ";

        $prepared = $this->wpdb->prepare($sql, $order_ids);
        $rows     = $this->wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Fetch orders with their line items attached.
     *
     * @param int[]    $wp_user_ids      WordPress user IDs.
     * @param string   $start_date       Start date (Y-m-d).
     * @param string   $end_date         End date (Y-m-d).
     * @param string[] $exclude_statuses Order statuses to exclude.
     *
     * @return array<int, array<string, mixed>> Orders with 'items' key.
     */
    public function get_orders_with_items_for_users(
        array $wp_user_ids,
        string $start_date,
        string $end_date,
        array $exclude_statuses = ['wc-cancelled', 'wc-on-hold', 'wc-draft', 'draft', 'wc-trash', 'trash']
    ): array {
        $orders = $this->get_orders_for_users($wp_user_ids, $start_date, $end_date, $exclude_statuses);
        if (empty($orders)) {
            return [];
        }

        $order_ids = array_column($orders, 'order_id');
        $items     = $this->get_order_items(array_map('intval', $order_ids));

        // Group items by order_id.
        $items_by_order = [];
        foreach ($items as $item) {
            $oid = (int) $item['order_id'];
            if (!isset($items_by_order[$oid])) {
                $items_by_order[$oid] = [];
            }
            $items_by_order[$oid][] = $item;
        }

        // Attach items to each order.
        foreach ($orders as &$order) {
            $oid = (int) $order['order_id'];
            $order['items'] = isset($items_by_order[$oid]) ? $items_by_order[$oid] : [];
        }
        unset($order);

        return $orders;
    }

    /**
     * Look up product metadata from the external meals_products table.
     *
     * @param int[] $wc_product_ids WooCommerce product IDs.
     *
     * @return array<int, array<string, mixed>> Keyed by wc_product_id.
     */
    public function get_product_types_for_ids(array $wc_product_ids): array {
        $wc_product_ids = array_filter(array_map('intval', $wc_product_ids));
        if (empty($wc_product_ids)) {
            return [];
        }

        global $wpdb;

        $products_table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $placeholders   = implode(',', array_fill(0, count($wc_product_ids), '%d'));

        $sql = $wpdb->prepare(
            "SELECT wc_product_id, product_type, taxable, case_size, unit_cost FROM `{$products_table}` WHERE wc_product_id IN ({$placeholders})",
            ...$wc_product_ids
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        $products = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $pid = (int) $row['wc_product_id'];
                $products[$pid] = [
                    'wc_product_id' => $pid,
                    'product_type'  => (string) $row['product_type'],
                    'taxable'       => (int) $row['taxable'],
                    'case_size'     => (int) $row['case_size'],
                    'unit_cost'     => (float) $row['unit_cost'],
                ];
            }
        }

        return $products;
    }

    /**
     * Resolve the billing rate for an order.
     *
     * Looks up the rate from meals_client_rates. Falls back to the client's
     * default rate if the given rate_id is 0 or not found.
     *
     * @param int $rate_id   Rate ID from order meta (0 if unset).
     * @param int $client_id External meals_clients client_id.
     *
     * @return float
     */
    public function resolve_rate_for_order(int $rate_id, int $client_id): float {
        global $wpdb;

        $rates_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES);

        // Try the explicit rate_id first.
        if ($rate_id > 0) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT rate FROM `{$rates_table}` WHERE rate_id = %d AND client_id = %d LIMIT 1",
                $rate_id,
                $client_id
            ), ARRAY_A);

            if (is_array($row) && isset($row['rate'])) {
                return (float) $row['rate'];
            }
        }

        // Fall back to the client's default rate.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT rate FROM `{$rates_table}` WHERE client_id = %d AND is_default = 1 LIMIT 1",
            $client_id
        ), ARRAY_A);

        if (is_array($row) && isset($row['rate'])) {
            return (float) $row['rate'];
        }

        return 0.00;
    }

    /**
     * @return string Fully-prefixed wc_orders table name.
     */
    private function orders_table(): string {
        return $this->wpdb->prefix . 'wc_orders';
    }

    /**
     * @return string Fully-prefixed wc_orders_meta table name.
     */
    private function orders_meta_table(): string {
        return $this->wpdb->prefix . 'wc_orders_meta';
    }

    /**
     * Get total paid for a specific product by a specific user in a date range.
     *
     * Uses HPOS tables to sum line_subtotal for matching product/user/date combinations.
     *
     * @param int    $wp_user_id
     * @param int    $wc_product_id
     * @param string $start_date Y-m-d
     * @param string $end_date   Y-m-d
     * @return float
     */
    /**
     * Sum the total a user has paid for a specific fee category in a
     * date range, considering BOTH fee mechanisms used by the plugin:
     *
     *   Mechanism A (legacy): WC line items keyed to a specific
     *     product ID (5675 = Client Contribution, 4122 = Delivery Fee).
     *     This is what the Enzebra system produced.
     *   Mechanism B (Quick Order): WC_Order_Item_Fee rows whose name
     *     matches "Client Contribution" or "Delivery Fee".
     *
     * Reports that queried only one mechanism showed systematic gaps
     * after Quick Order entered production (CRIT-3 in the v1.0.346
     * audit). This helper unifies them so the comparison shadow-mode
     * trial only flags real discrepancies.
     *
     * Both mechanisms restrict to the same paid-status set as
     * get_total_paid_for_product.
     *
     * @param int    $wp_user_id
     * @param string $fee_kind 'contribution' or 'delivery_fee'
     * @param string $start_date Y-m-d
     * @param string $end_date   Y-m-d
     * @return float Total paid across both mechanisms.
     */
    public function get_total_fee_paid_for_user(int $wp_user_id, string $fee_kind, string $start_date, string $end_date): float {
        $fee_ids = MealsDB_Operational_Constants::default_fee_product_ids();
        if (class_exists('MealsDB_Invoice_Generator')
            && method_exists('MealsDB_Invoice_Generator', 'get_fee_product_ids')) {
            // Honor the operator-tunable override.
            $fee_ids = MealsDB_Invoice_Generator::get_fee_product_ids();
        }

        if ($fee_kind === 'contribution') {
            $product_id = (int) ($fee_ids['client_contribution'] ?? MealsDB_Operational_Constants::PRODUCT_ID_CLIENT_CONTRIBUTION);
            $fee_name   = 'client contribution';
        } else {
            $product_id = (int) ($fee_ids['delivery_fee'] ?? MealsDB_Operational_Constants::PRODUCT_ID_DELIVERY_FEE);
            $fee_name   = 'delivery fee';
        }

        // Mechanism A: line item with matching product_id.
        $mechanism_a = $this->get_total_paid_for_product(
            $wp_user_id,
            $product_id,
            $start_date,
            $end_date
        );

        // Mechanism B: WC_Order_Item_Fee with matching name.
        // Name comparison is case-insensitive to defend against
        // small typos in legacy data ("delivery fee" vs "Delivery Fee").
        $orders_table   = $this->orders_table();
        $items_table    = $this->order_items_table();
        $itemmeta_table = $this->order_itemmeta_table();

        $end_exclusive = gmdate('Y-m-d', strtotime($end_date . ' +1 day'));

        $sql = "
            SELECT COALESCE(SUM(CAST(total_meta.meta_value AS DECIMAL(20,6))), 0) AS total_paid
            FROM {$orders_table} o
            INNER JOIN {$items_table} oi
                ON oi.order_id = o.id
                AND oi.order_item_type = 'fee'
                AND LOWER(oi.order_item_name) = %s
            INNER JOIN {$itemmeta_table} total_meta
                ON total_meta.order_item_id = oi.order_item_id
                AND total_meta.meta_key = '_line_total'
            WHERE o.customer_id = %d
                AND o.type = 'shop_order'
                AND o.status IN ('wc-processing', 'wc-completed', 'wc-paid',
                                 'processing', 'completed', 'paid')
                AND o.date_created_gmt >= %s
                AND o.date_created_gmt < %s
        ";

        $mechanism_b = (float) $this->wpdb->get_var($this->wpdb->prepare(
            $sql,
            $fee_name,
            $wp_user_id,
            $start_date,
            $end_exclusive
        ));

        return $mechanism_a + $mechanism_b;
    }

    public function get_total_paid_for_product(int $wp_user_id, int $wc_product_id, string $start_date, string $end_date): float {
        $orders_table   = $this->orders_table();
        $items_table    = $this->order_items_table();
        $itemmeta_table = $this->order_itemmeta_table();

        $end_exclusive = gmdate('Y-m-d', strtotime($end_date . ' +1 day'));

        $sql = "
            SELECT COALESCE(SUM(CAST(subtotal_meta.meta_value AS DECIMAL(20,6))), 0) AS total_paid
            FROM {$orders_table} o
            INNER JOIN {$items_table} oi ON oi.order_id = o.id
            INNER JOIN {$itemmeta_table} product_meta
                ON product_meta.order_item_id = oi.order_item_id
                AND product_meta.meta_key = '_product_id'
            INNER JOIN {$itemmeta_table} subtotal_meta
                ON subtotal_meta.order_item_id = oi.order_item_id
                AND subtotal_meta.meta_key = '_line_subtotal'
            WHERE o.customer_id = %d
                AND o.type = 'shop_order'
                -- Restrict to actually-paid statuses. Including pending /
                -- on-hold previously over-counted total-paid, which
                -- propagated into invoice and reconciliation math.
                AND o.status IN ('wc-processing', 'wc-completed', 'wc-paid',
                                 'processing', 'completed', 'paid')
                AND o.date_created_gmt >= %s
                AND o.date_created_gmt < %s
                AND CAST(product_meta.meta_value AS UNSIGNED) = %d
        ";

        $result = $this->wpdb->get_var($this->wpdb->prepare(
            $sql,
            $wp_user_id,
            $start_date,
            $end_exclusive,
            $wc_product_id
        ));

        return (float) ($result ?? 0);
    }

    /**
     * Count orders for a specific user in a date range.
     *
     * @param int    $wp_user_id
     * @param string $start_date Y-m-d
     * @param string $end_date   Y-m-d
     * @return int
     */
    public function get_order_count_for_user(int $wp_user_id, string $start_date, string $end_date): int {
        $orders_table = $this->orders_table();

        $end_exclusive = gmdate('Y-m-d', strtotime($end_date . ' +1 day'));

        $sql = "
            SELECT COUNT(*) FROM {$orders_table}
            WHERE customer_id = %d
                AND type = 'shop_order'
                -- Match the paid-status set used elsewhere; pending /
                -- on-hold are not paid orders for billing purposes.
                AND status IN ('wc-processing', 'wc-completed', 'wc-paid',
                               'processing', 'completed', 'paid')
                AND date_created_gmt >= %s
                AND date_created_gmt < %s
        ";

        $result = $this->wpdb->get_var($this->wpdb->prepare(
            $sql,
            $wp_user_id,
            $start_date,
            $end_exclusive
        ));

        return (int) ($result ?? 0);
    }

    /**
     * @return string Fully-prefixed wc_order_items table name.
     */
    private function order_items_table(): string {
        return $this->wpdb->prefix . 'woocommerce_order_items';
    }

    /**
     * @return string Fully-prefixed woocommerce_order_itemmeta table name.
     */
    private function order_itemmeta_table(): string {
        return $this->wpdb->prefix . 'woocommerce_order_itemmeta';
    }
}
