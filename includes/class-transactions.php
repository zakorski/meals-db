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
        if (!MealsDB_DB::is_mysqli($connection)) {
            return false;
        }

        $table_name   = MealsDB_DB::get_table_name(MealsDB_Tables::TRANSACTIONS);
        $escaped_table = str_replace('`', '``', $table_name);

        $metadata = json_encode($items);
        if ($metadata === false) {
            $metadata = '[]';
        }

        $subtotal = isset($totals['subtotal']) ? (float) $totals['subtotal'] : 0.0;
        $total    = isset($totals['total']) ? (float) $totals['total'] : 0.0;
        $taxes    = isset($totals['taxes']) ? (float) $totals['taxes'] : 0.0;
        $status   = 'Ordered';

        $order_date    = isset($totals['order_date']) ? (string) $totals['order_date'] : date('Y-m-d');
        $delivery_date = isset($totals['delivery_date']) ? (string) $totals['delivery_date'] : $order_date;

        $wp_order_id       = (int) $order_id;
        $wp_order_item_id  = isset($totals['wp_order_item_id']) ? (int) $totals['wp_order_item_id'] : null;
        $client_identifier = (int) $client_id;

        $sql = sprintf(
            'INSERT INTO `%s` (client_id, wp_order_id, wp_order_item_id, order_date, delivery_date, subtotal, taxes, total, metadata, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            $escaped_table
        );

        $statement = $connection->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($statement)) {
            return false;
        }

        $metadata_param = $metadata;
        if (!$statement->bind_param(
            'iiissdddsss',
            $client_identifier,
            $wp_order_id,
            $wp_order_item_id,
            $order_date,
            $delivery_date,
            $subtotal,
            $taxes,
            $total,
            $metadata_param,
            $status
        )) {
            $statement->close();
            return false;
        }

        $result = $statement->execute();
        $statement->close();

        return (bool) $result;
    }

    /**
     * Retrieve a single transaction and its client summary by ID.
     *
     * @param int $transaction_id
     * @return array<string, mixed>
     */
    public static function get_transaction($transaction_id): array {
        $connection = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($connection)) {
            return [];
        }

        $transactions_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::TRANSACTIONS));
        $clients_table      = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));

        $sql = "SELECT ".
            " t.transaction_id, t.client_id, t.wp_order_id, t.wp_order_item_id, t.order_date, t.delivery_date, t.subtotal, t.taxes, t.total, t.metadata, t.status, t.created_at, t.updated_at,"
            . " c.first_name, c.last_name, c.client_type, c.phone, c.email"
            . " FROM `{$transactions_table}` t"
            . " LEFT JOIN `{$clients_table}` c ON c.client_id = t.client_id"
            . ' WHERE t.transaction_id = ?'
            . ' LIMIT 1';

        $stmt = $connection->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return [];
        }

        $transaction_id = (int) $transaction_id;
        if (!$stmt->bind_param('i', $transaction_id)) {
            $stmt->close();
            return [];
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $row = [];

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if (MealsDB_DB::is_mysqli_result($result)) {
                $row = $result->fetch_assoc() ?: [];
                $result->free();
            }
        } else {
            $statement_row = [
                'transaction_id'   => null,
                'client_id'         => null,
                'wp_order_id'       => null,
                'wp_order_item_id'  => null,
                'order_date'        => null,
                'delivery_date'     => null,
                'subtotal'          => null,
                'taxes'             => null,
                'total'             => null,
                'metadata'          => null,
                'status'            => null,
                'created_at'        => null,
                'updated_at'        => null,
                'first_name'        => null,
                'last_name'         => null,
                'client_type'       => null,
                'phone'             => null,
                'email'             => null,
            ];

            $bound = $stmt->bind_result(
                $statement_row['transaction_id'],
                $statement_row['client_id'],
                $statement_row['wp_order_id'],
                $statement_row['wp_order_item_id'],
                $statement_row['order_date'],
                $statement_row['delivery_date'],
                $statement_row['subtotal'],
                $statement_row['taxes'],
                $statement_row['total'],
                $statement_row['metadata'],
                $statement_row['status'],
                $statement_row['created_at'],
                $statement_row['updated_at'],
                $statement_row['first_name'],
                $statement_row['last_name'],
                $statement_row['client_type'],
                $statement_row['phone'],
                $statement_row['email']
            );

            if ($bound && $stmt->fetch()) {
                $row = [
                    'transaction_id'  => $statement_row['transaction_id'],
                    'client_id'       => $statement_row['client_id'],
                    'wp_order_id'     => $statement_row['wp_order_id'],
                    'wp_order_item_id'=> $statement_row['wp_order_item_id'],
                    'order_date'      => $statement_row['order_date'],
                    'delivery_date'   => $statement_row['delivery_date'],
                    'subtotal'        => $statement_row['subtotal'],
                    'taxes'           => $statement_row['taxes'],
                    'total'           => $statement_row['total'],
                    'metadata'        => $statement_row['metadata'],
                    'status'          => $statement_row['status'],
                    'created_at'      => $statement_row['created_at'],
                    'updated_at'      => $statement_row['updated_at'],
                    'first_name'      => $statement_row['first_name'],
                    'last_name'       => $statement_row['last_name'],
                    'client_type'     => $statement_row['client_type'],
                    'phone'           => $statement_row['phone'],
                    'email'           => $statement_row['email'],
                ];
            }
        }

        $stmt->close();

        return $row;
    }

    /**
     * Retrieve items associated with a transaction.
     *
     * @param int $transaction_id
     * @return array<int, array<string, mixed>>
     */
    public static function get_transaction_items($transaction_id): array {
        $connection = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($connection)) {
            return [];
        }

        $items_table    = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::TRANSACTION_ITEMS));
        $products_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS));

        $sql = "SELECT ".
            " ti.transaction_item_id, ti.transaction_id, ti.product_id, ti.quantity, ti.line_subtotal, ti.line_taxes, ti.line_total,"
            . " p.product_name, p.category, p.main_ingredient"
            . " FROM `{$items_table}` ti"
            . " LEFT JOIN `{$products_table}` p ON p.product_id = ti.product_id"
            . ' WHERE ti.transaction_id = ?'
            . ' ORDER BY p.product_name ASC';

        $stmt = $connection->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return [];
        }

        $transaction_id = (int) $transaction_id;
        if (!$stmt->bind_param('i', $transaction_id)) {
            $stmt->close();
            return [];
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $rows = [];

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if (MealsDB_DB::is_mysqli_result($result)) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
                $result->free();
            }
        } else {
            $statement_row = [
                'transaction_item_id' => null,
                'transaction_id'      => null,
                'product_id'          => null,
                'quantity'            => null,
                'line_subtotal'       => null,
                'line_taxes'          => null,
                'line_total'          => null,
                'product_name'        => null,
                'category'            => null,
                'main_ingredient'     => null,
            ];

            $bound = $stmt->bind_result(
                $statement_row['transaction_item_id'],
                $statement_row['transaction_id'],
                $statement_row['product_id'],
                $statement_row['quantity'],
                $statement_row['line_subtotal'],
                $statement_row['line_taxes'],
                $statement_row['line_total'],
                $statement_row['product_name'],
                $statement_row['category'],
                $statement_row['main_ingredient']
            );

            if ($bound) {
                while ($stmt->fetch()) {
                    $rows[] = [
                        'transaction_item_id' => $statement_row['transaction_item_id'],
                        'transaction_id'      => $statement_row['transaction_id'],
                        'product_id'          => $statement_row['product_id'],
                        'quantity'            => $statement_row['quantity'],
                        'line_subtotal'       => $statement_row['line_subtotal'],
                        'line_taxes'          => $statement_row['line_taxes'],
                        'line_total'          => $statement_row['line_total'],
                        'product_name'        => $statement_row['product_name'],
                        'category'            => $statement_row['category'],
                        'main_ingredient'     => $statement_row['main_ingredient'],
                    ];
                }
            }
        }

        $stmt->close();

        return $rows;
    }

    /**
     * Fetch transactions from the external Meals DB with optional filters.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function get_transactions(array $filters): array {
        $connection = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($connection)) {
            return [];
        }

        $transactions_table = MealsDB_DB::get_table_name(MealsDB_Tables::TRANSACTIONS);
        $clients_table      = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $transactions_table = str_replace('`', '``', $transactions_table);
        $clients_table      = str_replace('`', '``', $clients_table);

        $per_page = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 25;
        $offset   = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        [$where_sql, $types, $params] = self::build_transactions_where_clause($filters);

        $sql = "SELECT "
            . "t.transaction_id, t.client_id, t.wp_order_id, t.wp_order_item_id, t.order_date, t.delivery_date, t.subtotal, t.taxes, t.total, t.metadata, t.status, t.created_at, t.updated_at,"
            . " c.first_name, c.last_name, c.client_type FROM `{$transactions_table}` t"
            . " LEFT JOIN `{$clients_table}` c ON t.client_id = c.client_id"
            . ' WHERE 1=1'
            . $where_sql
            . ' ORDER BY t.order_date DESC'
            . sprintf(' LIMIT %d, %d', $offset, $per_page);

        $has_params = $types !== '' && !empty($params);

        $stmt = $connection->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return [];
        }

        if ($has_params) {
            $bind_result = $stmt->bind_param($types, ...$params);
            if (!$bind_result) {
                $stmt->close();
                return [];
            }
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $rows = [];

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if (MealsDB_DB::is_mysqli_result($result)) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
                $result->free();
            }
        } else {
            $statement_row = [
                'transaction_id'   => null,
                'client_id'        => null,
                'wp_order_id'      => null,
                'wp_order_item_id' => null,
                'order_date'       => null,
                'delivery_date'    => null,
                'subtotal'         => null,
                'taxes'            => null,
                'total'            => null,
                'metadata'         => null,
                'status'           => null,
                'created_at'       => null,
                'updated_at'       => null,
                'first_name'       => null,
                'last_name'        => null,
                'client_type'      => null,
            ];

            $bound = $stmt->bind_result(
                $statement_row['transaction_id'],
                $statement_row['client_id'],
                $statement_row['wp_order_id'],
                $statement_row['wp_order_item_id'],
                $statement_row['order_date'],
                $statement_row['delivery_date'],
                $statement_row['subtotal'],
                $statement_row['taxes'],
                $statement_row['total'],
                $statement_row['metadata'],
                $statement_row['status'],
                $statement_row['created_at'],
                $statement_row['updated_at'],
                $statement_row['first_name'],
                $statement_row['last_name'],
                $statement_row['client_type']
            );

            if ($bound) {
                while ($stmt->fetch()) {
                    $rows[] = [
                        'transaction_id' => $statement_row['transaction_id'],
                        'client_id'      => $statement_row['client_id'],
                        'wp_order_id'    => $statement_row['wp_order_id'],
                        'wp_order_item_id' => $statement_row['wp_order_item_id'],
                        'order_date'     => $statement_row['order_date'],
                        'delivery_date'  => $statement_row['delivery_date'],
                        'subtotal'       => $statement_row['subtotal'],
                        'taxes'          => $statement_row['taxes'],
                        'total'          => $statement_row['total'],
                        'metadata'       => $statement_row['metadata'],
                        'status'         => $statement_row['status'],
                        'created_at'     => $statement_row['created_at'],
                        'updated_at'     => $statement_row['updated_at'],
                        'first_name'     => $statement_row['first_name'],
                        'last_name'      => $statement_row['last_name'],
                        'client_type'    => $statement_row['client_type'],
                    ];
                }
            }
        }

        $stmt->close();

        return $rows;
    }

    /**
     * Count transactions for the provided filter set.
     */
    public static function count_transactions(array $filters): int {
        $connection = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($connection)) {
            return 0;
        }

        $transactions_table = MealsDB_DB::get_table_name(MealsDB_Tables::TRANSACTIONS);
        $clients_table      = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $transactions_table = str_replace('`', '``', $transactions_table);
        $clients_table      = str_replace('`', '``', $clients_table);

        [$where_sql, $types, $params] = self::build_transactions_where_clause($filters);

        $sql = "SELECT COUNT(*) as total FROM `{$transactions_table}` t"
            . " LEFT JOIN `{$clients_table}` c ON t.client_id = c.client_id"
            . ' WHERE 1=1'
            . $where_sql;

        $has_params = $types !== '' && !empty($params);

        $stmt = $connection->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return 0;
        }

        if ($has_params) {
            $bind_result = $stmt->bind_param($types, ...$params);
            if (!$bind_result) {
                $stmt->close();
                return 0;
            }
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }

        $total = 0;

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if (MealsDB_DB::is_mysqli_result($result)) {
                $row = $result->fetch_assoc();
                if (isset($row['total'])) {
                    $total = (int) $row['total'];
                }
                $result->free();
            }
        } else {
            $row_total = null;
            $bound     = $stmt->bind_result($row_total);

            if ($bound && $stmt->fetch()) {
                $total = (int) $row_total;
            }
        }

        $stmt->close();

        return $total;
    }

    /**
     * Build the WHERE clause for transaction queries.
     *
     * @param array<string, mixed> $filters
     * @return array{string, string, array<int, mixed>}
     */
    private static function build_transactions_where_clause(array $filters): array {
        $where  = [];
        $params = [];
        $types  = '';

        $client_id = isset($filters['client_id']) ? (int) $filters['client_id'] : 0;
        if ($client_id > 0) {
            $where[]  = ' AND t.client_id = ?';
            $types   .= 'i';
            $params[] = $client_id;
        }

        $client_type = isset($filters['client_type']) ? trim((string) $filters['client_type']) : '';
        if ($client_type !== '') {
            $where[]  = ' AND c.client_type = ?';
            $types   .= 's';
            $params[] = $client_type;
        }

        $status        = isset($filters['status']) ? trim((string) $filters['status']) : '';
        $allowedStatus = ['Ordered', 'Delivered', 'Cancelled'];
        if ($status !== '' && in_array($status, $allowedStatus, true)) {
            $where[]  = ' AND t.status = ?';
            $types   .= 's';
            $params[] = $status;
        }

        $start_date = isset($filters['start_date']) ? trim((string) $filters['start_date']) : '';
        if ($start_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
            $where[]  = ' AND t.order_date >= ?';
            $types   .= 's';
            $params[] = $start_date;
        }

        $end_date = isset($filters['end_date']) ? trim((string) $filters['end_date']) : '';
        if ($end_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            $where[]  = ' AND t.order_date <= ?';
            $types   .= 's';
            $params[] = $end_date;
        }

        return [implode('', $where), $types, $params];
    }
}
