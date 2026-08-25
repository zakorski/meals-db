<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ground-truth scorecard for the delivery-date backfill (DIRECTIVE
 * delivery-date-next-week-rule ITEM 2). The operator holds the truth: a list
 * of (WooCommerce order number, actual delivery date) extracted from the
 * legacy July packer PDFs. This compares each order's currently-stored
 * `_delivery_date` against that truth and reports the match rate + the
 * nameable misses (so the residual can be classified as retroactive entry or
 * a known one-off route change). A match rate materially below ~96% after the
 * backfill means STOP and investigate, per the directive.
 *
 * The AJAX surface (mealsdb_delivery_scorecard) lives in
 * MealsDB_Ajax_Migration::delivery_scorecard(). This class is the pure
 * computation; it has no AJAX dependencies.
 */
class MealsDB_Delivery_Date_Scorecard {

    /**
     * Pure comparison over two [order_number => 'Y-m-d'] maps. The scored set
     * is the orders present in $actual (the ground truth); an order absent
     * from $stored counts as a miss with stored=''.
     *
     * Orders in $stored that have no corresponding entry in $actual are
     * ignored — the ground truth drives the join, not the stored set.
     *
     * @param array<int|string,string> $stored order_number => stored delivery date
     * @param array<int|string,string> $actual order_number => actual delivery date
     * @return array{total:int, matched:int, match_rate:float, misses:list<array{order:mixed,stored:string,actual:string}>}
     */
    public static function score_pairs( array $stored, array $actual ): array {
        $total   = 0;
        $matched = 0;
        $misses  = [];

        foreach ( $actual as $order => $actual_date ) {
            $total++;
            $stored_date = isset( $stored[ $order ] ) ? (string) $stored[ $order ] : '';

            if ( $stored_date !== '' && $stored_date === (string) $actual_date ) {
                $matched++;
            } else {
                // Cast numeric keys to int so the miss shape is consistent
                // regardless of whether the caller built the map with int or
                // string keys (PHP array keys are polymorphic).
                $misses[] = [
                    'order'  => is_numeric( $order ) ? (int) $order : $order,
                    'stored' => $stored_date,
                    'actual' => (string) $actual_date,
                ];
            }
        }

        return [
            'total'      => $total,
            'matched'    => $matched,
            'match_rate' => $total > 0 ? $matched / $total : 0.0,
            'misses'     => $misses,
        ];
    }

    /**
     * Read each ground-truth order's stored `_delivery_date` and score it.
     * $ground_truth is [order_number => 'Y-m-d actual']. Orders that don't
     * resolve to a real WC order are skipped (not counted) — report the skip
     * count separately so a bad join is visible.
     *
     * On this site the WC order ID IS the order number (no custom order-number
     * plugin is in use as of the delivery-date-next-week-rule directive). If
     * that changes, swap wc_get_order($order_number) for a lookup by the
     * custom number field.
     *
     * @param array<int|string,string> $ground_truth
     * @return array{total:int, matched:int, match_rate:float, misses:list<array>, unresolved:int}
     */
    public static function score( array $ground_truth ): array {
        $stored       = [];
        $unresolved   = 0;
        $joined_truth = [];

        foreach ( $ground_truth as $order_number => $actual_date ) {
            $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_number ) : null;
            if ( ! $order ) {
                $unresolved++;
                continue;
            }
            $joined_truth[ $order_number ] = (string) $actual_date;
            $stored[ $order_number ]       = (string) $order->get_meta( '_delivery_date', true );
        }

        $result               = self::score_pairs( $stored, $joined_truth );
        $result['unresolved'] = $unresolved;
        return $result;
    }

    /**
     * Parse operator CSV text into a [order_number => 'Y-m-d'] ground-truth map.
     * Accepts lines `order_number,delivery_date`; tolerates a header row, blank
     * lines, and surrounding whitespace. Non-numeric order numbers and
     * malformed dates are skipped silently — the caller receives only the rows
     * that parse cleanly.
     *
     * @return array<int,string>
     */
    public static function parse_csv( string $csv ): array {
        $out = [];
        foreach ( preg_split( '/\r\n|\r|\n/', $csv ) as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            $cols = array_map( 'trim', explode( ',', $line ) );
            if ( count( $cols ) < 2 ) {
                continue;
            }
            $order = $cols[0];
            $date  = $cols[1];
            if ( ! ctype_digit( $order ) ) {
                continue; // skips header row + junk
            }
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                continue;
            }
            $out[ (int) $order ] = $date;
        }
        return $out;
    }
}
