<?php
/**
 * Backfill Allocation Engine — Historical Order Processing.
 *
 * Populates meals_client_allocations and meals_delivery_allocations from
 * existing WooCommerce orders. This is a one-time migration intended to be
 * triggered manually from the admin UI after the allocation engine is deployed.
 *
 * Note: This is separate from MealsDB_Backfill_Allowances, which backfills
 * allowance_mains/allowance_sides on meals_clients. This class backfills the
 * allocation TABLES from historical order data.
 *
 * @package MealsDB
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MealsDB_Backfill_Allocations_Engine {

    /**
     * Process all orders for active government clients between $start_month and $end_month (inclusive).
     *
     * Months are processed chronologically (oldest first) so that delivery
     * straddling across month boundaries is handled correctly.
     *
     * @param string $start_month Format "YYYY-MM".
     * @param string $end_month   Format "YYYY-MM".
     * @param bool   $dry_run     If true, wrap in a transaction and rollback.
     *
     * @return array{months_processed: int, clients_processed: int, orders_processed: int, allocations_created: int}|array{error: string}
     */
    public static function run( string $start_month, string $end_month, bool $dry_run = true ): array {
        global $wpdb;

        // Validate month formats.
        if ( ! preg_match( '/^\d{4}-\d{2}$/', $start_month ) || ! preg_match( '/^\d{4}-\d{2}$/', $end_month ) ) {
            return [ 'error' => 'Invalid month format. Use YYYY-MM.' ];
        }

        if ( $start_month > $end_month ) {
            return [ 'error' => 'Start month must be before or equal to end month.' ];
        }

        $clients_table       = MealsDB_DB::get_table_name( MealsDB_Tables::CLIENTS );
        $allocations_table   = MealsDB_DB::get_table_name( MealsDB_Tables::CLIENT_ALLOCATIONS );
        $delivery_alloc_table = MealsDB_DB::get_table_name( MealsDB_Tables::DELIVERY_ALLOCATIONS );

        // Fetch all active government clients (SDNB and Veteran only).
        $clients = $wpdb->get_results( $wpdb->prepare(
            "SELECT client_id, wp_user_id FROM {$clients_table}
             WHERE active = 1 AND client_type IN (%s, %s)",
            'SDNB',
            'Veteran'
        ), ARRAY_A );

        if ( ! is_array( $clients ) || empty( $clients ) ) {
            return [ 'error' => 'No active SDNB/Veteran clients found.' ];
        }

        $engine      = new MealsDB_Allocation_Engine();
        $order_query = new MealsDB_WC_Order_Query( $wpdb );

        // Build the list of months to process (chronological order).
        $months = self::build_month_range( $start_month, $end_month );

        // Pre-fetch all finalized months so we can prevent spillover writes.
        $finalized_set = self::get_finalized_months( $wpdb, $allocations_table );

        $stats = [
            'months_processed'    => 0,
            'clients_processed'   => 0,
            'orders_processed'    => 0,
            'allocations_created' => 0,
        ];

        // Track which orders have already been allocated (across months) to
        // avoid double-processing when prior-month orders are re-fetched.
        $allocated_orders = [];

        // Begin transaction for dry-run rollback.
        $wpdb->query( 'START TRANSACTION' );

        try {
            foreach ( $months as $billing_month ) {
                $year  = (int) substr( $billing_month, 0, 4 );
                $month = (int) substr( $billing_month, 5, 2 );
                $days_in_month = cal_days_in_month( CAL_GREGORIAN, $month, $year );

                $month_start = sprintf( '%04d-%02d-01', $year, $month );
                $month_end   = sprintf( '%04d-%02d-%02d', $year, $month, $days_in_month );

                // Compute the prior month's date range so we capture orders
                // whose delivery coverage spills into this billing month.
                $prior_dt        = ( new \DateTime( $month_start ) )->modify( '-1 month' );
                $prior_month_start = $prior_dt->format( 'Y-m-01' );
                $prior_month_end   = $prior_dt->format( 'Y-m-t' );

                $clients_in_month = 0;

                foreach ( $clients as $client ) {
                    $client_id  = (int) $client['client_id'];
                    $wp_user_id = (int) $client['wp_user_id'];

                    // Skip finalized months.
                    $client_month_key = $client_id . ':' . $billing_month;
                    if ( isset( $finalized_set[ $client_month_key ] ) ) {
                        continue;
                    }

                    // a. Set up the permitted summary row.
                    $engine->calculate_permitted_for_month( $client_id, $billing_month );

                    // b. Fetch WC orders for this client in this month AND the
                    //    prior month (to capture spillover coverage into this month).
                    $orders = $order_query->get_orders_for_users(
                        [ $wp_user_id ],
                        $prior_month_start,
                        $month_end
                    );

                    // c. Allocate each order.
                    $order_count_before = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$delivery_alloc_table} WHERE client_id = %d AND billing_month = %s",
                        $client_id,
                        $billing_month
                    ) );

                    if ( is_array( $orders ) ) {
                        foreach ( $orders as $order ) {
                            $order_id = (int) $order['order_id'];

                            // Skip orders already allocated in a previous iteration.
                            if ( isset( $allocated_orders[ $order_id ] ) ) {
                                continue;
                            }

                            $engine->allocate_order( $order_id );
                            $allocated_orders[ $order_id ] = true;
                            $stats['orders_processed']++;

                            // P1 fix: remove any delivery allocation rows that
                            // spilled into a finalized month and were just written.
                            self::cleanup_finalized_spillover(
                                $wpdb,
                                $delivery_alloc_table,
                                $allocations_table,
                                $order_id,
                                $client_id,
                                $finalized_set
                            );
                        }
                    }

                    // d. Recalculate month totals after all orders for this client+month.
                    $engine->recalculate_month_totals( $client_id, $billing_month );

                    $order_count_after = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$delivery_alloc_table} WHERE client_id = %d AND billing_month = %s",
                        $client_id,
                        $billing_month
                    ) );

                    $stats['allocations_created'] += max( 0, $order_count_after - $order_count_before );
                    $clients_in_month++;
                }

                if ( $clients_in_month > 0 ) {
                    $stats['months_processed']++;
                    $stats['clients_processed'] += $clients_in_month;
                }
            }
        } catch ( \Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'error' => 'Exception during backfill: ' . $e->getMessage() ];
        }

        if ( $dry_run ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( sprintf(
                '[MealsDB Backfill Allocations] Dry run %s to %s — months: %d, clients: %d, orders: %d, allocations: %d',
                $start_month,
                $end_month,
                $stats['months_processed'],
                $stats['clients_processed'],
                $stats['orders_processed'],
                $stats['allocations_created']
            ) );
        } else {
            $wpdb->query( 'COMMIT' );
            error_log( sprintf(
                '[MealsDB Backfill Allocations] Live run %s to %s — months: %d, clients: %d, orders: %d, allocations: %d',
                $start_month,
                $end_month,
                $stats['months_processed'],
                $stats['clients_processed'],
                $stats['orders_processed'],
                $stats['allocations_created']
            ) );
        }

        return $stats;
    }

    /**
     * Convenience method to process a single month.
     *
     * @param string $billing_month Format "YYYY-MM".
     * @param bool   $dry_run       If true, wrap in a transaction and rollback.
     *
     * @return array{months_processed: int, clients_processed: int, orders_processed: int, allocations_created: int}|array{error: string}
     */
    public static function run_single_month( string $billing_month, bool $dry_run = true ): array {
        return self::run( $billing_month, $billing_month, $dry_run );
    }

    /**
     * Pre-fetch all finalized client+month pairs as a lookup set.
     *
     * @param wpdb   $wpdb
     * @param string $allocations_table Fully-qualified table name.
     * @return array<string, true> Keys are "client_id:YYYY-MM".
     */
    private static function get_finalized_months( $wpdb, string $allocations_table ): array {
        $rows = $wpdb->get_results(
            "SELECT client_id, billing_month FROM {$allocations_table} WHERE is_finalized = 1",
            ARRAY_A
        );

        $set = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $set[ $row['client_id'] . ':' . $row['billing_month'] ] = true;
            }
        }

        return $set;
    }

    /**
     * After allocate_order(), remove any delivery allocation rows that spilled
     * into a finalized month. This prevents the backfill from writing detail
     * rows into months whose summary can no longer be recalculated.
     *
     * @param wpdb   $wpdb
     * @param string $delivery_alloc_table
     * @param string $allocations_table
     * @param int    $order_id
     * @param int    $client_id
     * @param array  $finalized_set
     */
    private static function cleanup_finalized_spillover(
        $wpdb,
        string $delivery_alloc_table,
        string $allocations_table,
        int $order_id,
        int $client_id,
        array $finalized_set
    ): void {
        // Fetch the billing months this order's delivery allocations touch.
        $alloc_months = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT billing_month FROM {$delivery_alloc_table}
             WHERE wc_order_id = %d AND client_id = %d",
            $order_id,
            $client_id
        ) );

        if ( ! is_array( $alloc_months ) ) {
            return;
        }

        foreach ( $alloc_months as $alloc_month ) {
            $key = $client_id . ':' . $alloc_month;
            if ( isset( $finalized_set[ $key ] ) ) {
                $wpdb->delete(
                    $delivery_alloc_table,
                    [
                        'wc_order_id'   => $order_id,
                        'client_id'     => $client_id,
                        'billing_month' => $alloc_month,
                    ],
                    [ '%d', '%d', '%s' ]
                );

                error_log( sprintf(
                    '[MealsDB Backfill Allocations] Removed spillover rows for order %d into finalized month %s (client %d).',
                    $order_id,
                    $alloc_month,
                    $client_id
                ) );
            }
        }
    }

    /**
     * Build an array of "YYYY-MM" strings from start to end (inclusive), oldest first.
     *
     * @param string $start Format "YYYY-MM".
     * @param string $end   Format "YYYY-MM".
     * @return string[]
     */
    private static function build_month_range( string $start, string $end ): array {
        $months  = [];
        $current = new \DateTime( $start . '-01' );
        $last    = new \DateTime( $end . '-01' );

        while ( $current <= $last ) {
            $months[] = $current->format( 'Y-m' );
            $current->modify( '+1 month' );
        }

        return $months;
    }
}
