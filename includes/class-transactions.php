<?php
/**
 * Records WooCommerce order transactions in the external Meals DB.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Transactions {

    /**
     * Record an order in the meals_transactions table.
     *
     * @param int   $order_id  WooCommerce order ID being logged.
     * @param int   $client_id Related Meals DB client identifier.
     * @param array $items     Array of items comprising the order.
     * @param array $totals    Totals array with subtotal, total, and taxes.
     *
     * @return bool Whether the insert succeeded.
     */
    public static function record_order($order_id, $client_id, $items, $totals) {
        $connection = MealsDB_DB::get_connection();
        if (!$connection instanceof mysqli) {
            return false;
        }

        $table_name = MealsDB_DB::get_table_name('meals_transactions');
        $escaped_table = str_replace('`', '``', $table_name);

        $metadata = json_encode($items);
        if ($metadata === false) {
            $metadata = '[]';
        }

        $subtotal = isset($totals['subtotal']) ? (float) $totals['subtotal'] : 0.0;
        $total    = isset($totals['total']) ? (float) $totals['total'] : 0.0;
        $taxes    = isset($totals['taxes']) ? (float) $totals['taxes'] : 0.0;

        $sql = sprintf(
            'INSERT INTO `%s` (order_id, client_id, metadata, subtotal, total, taxes, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            $escaped_table
        );

        $statement = $connection->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            return false;
        }

        $metadata_param = $metadata;
        if (!$statement->bind_param('iisddd', $order_id, $client_id, $metadata_param, $subtotal, $total, $taxes)) {
            $statement->close();
            return false;
        }

        $result = $statement->execute();
        $statement->close();

        return (bool) $result;
    }
}
