<?php
$client_id   = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$client_type = isset($_GET['client_type']) ? $_GET['client_type'] : '';
$status      = isset($_GET['status']) ? $_GET['status'] : '';
$start_date  = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date    = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$paged       = isset($_GET['paged']) ? intval($_GET['paged']) : 1;

if (function_exists('wp_unslash')) {
    $client_type = wp_unslash($client_type);
    $status      = wp_unslash($status);
    $start_date  = wp_unslash($start_date);
    $end_date    = wp_unslash($end_date);
}

if (function_exists('sanitize_text_field')) {
    $client_type = sanitize_text_field($client_type);
    $status      = sanitize_text_field($status);
    $start_date  = sanitize_text_field($start_date);
    $end_date    = sanitize_text_field($end_date);
} else {
    $client_type = trim((string) $client_type);
    $status      = trim((string) $status);
    $start_date  = trim((string) $start_date);
    $end_date    = trim((string) $end_date);
}

$allowed_statuses = ['Ordered', 'Delivered', 'Cancelled'];
if (!in_array($status, $allowed_statuses, true)) {
    $status = '';
}

$paged = max(1, $paged);

$per_page = 25;
$offset   = ($paged - 1) * $per_page;

$conn = MealsDB_DB::get_connection();
$clients_list = [];

if (MealsDB_DB::is_mysqli($conn)) {
    $clients_result = MealsDB_DB::get_all_clients($conn);

    if (MealsDB_DB::is_mysqli_result($clients_result)) {
        while ($row = $clients_result->fetch_assoc()) {
            $clients_list[] = $row;
        }
        $clients_result->free();
    }
}

$filters = [
    'client_id'   => $client_id,
    'client_type' => $client_type,
    'status'      => $status,
    'start_date'  => $start_date,
    'end_date'    => $end_date,
    'per_page'    => $per_page,
    'offset'      => $offset,
];

$transactions       = MealsDB_Transactions::get_transactions($filters);
$total_transactions = MealsDB_Transactions::count_transactions($filters);
$total_pages        = $total_transactions > 0 ? (int) ceil($total_transactions / $per_page) : 1;

$query_args = [
    'page'        => 'mealsdb-transactions',
    'client_id'   => $client_id > 0 ? $client_id : null,
    'client_type' => $client_type !== '' ? $client_type : null,
    'status'      => $status !== '' ? $status : null,
    'start_date'  => $start_date !== '' ? $start_date : null,
    'end_date'    => $end_date !== '' ? $end_date : null,
];
$query_args = array_filter(
    $query_args,
    function ($value) {
        return $value !== null && $value !== '';
    }
);
?>

<div class="wrap mealsdb-transactions">
    <h1>Transactions</h1>

    <form method="get" class="mealsdb-transaction-filters">
        <input type="hidden" name="page" value="mealsdb-transactions" />

        <label for="mealsdb-filter-client" class="screen-reader-text"><?php esc_html_e('Filter by client', 'meals-db'); ?></label>
        <select id="mealsdb-filter-client" name="client_id">
            <option value="0"><?php esc_html_e('All Clients', 'meals-db'); ?></option>
            <?php foreach ($clients_list as $client) :
                $value = isset($client['client_id']) ? intval($client['client_id']) : 0;
                $client_name = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
                $client_type_value = $client['client_type'] ?? '';
            ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($client_id, $value); ?>>
                    <?php echo esc_html(trim($client_name !== '' ? $client_name : sprintf(__('Client #%d', 'meals-db'), $value))); ?>
                    <?php if ($client_type_value !== '') : ?>
                        (<?php echo esc_html($client_type_value); ?>)
                    <?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="mealsdb-filter-client-type" class="screen-reader-text"><?php esc_html_e('Filter by client type', 'meals-db'); ?></label>
        <select id="mealsdb-filter-client-type" name="client_type">
            <option value=""><?php esc_html_e('All Client Types', 'meals-db'); ?></option>
            <?php
            $client_types = ['Private', 'SDNB', 'Veterans', 'Staff'];
            foreach ($client_types as $type) :
            ?>
                <option value="<?php echo esc_attr($type); ?>" <?php selected($client_type, $type); ?>><?php echo esc_html($type); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="mealsdb-filter-status" class="screen-reader-text"><?php esc_html_e('Filter by status', 'meals-db'); ?></label>
        <select id="mealsdb-filter-status" name="status">
            <option value=""><?php esc_html_e('All Statuses', 'meals-db'); ?></option>
            <?php foreach ($allowed_statuses as $status_option) : ?>
                <option value="<?php echo esc_attr($status_option); ?>" <?php selected($status, $status_option); ?>><?php echo esc_html($status_option); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="mealsdb-filter-start-date" class="screen-reader-text"><?php esc_html_e('Start Date', 'meals-db'); ?></label>
        <input type="date" id="mealsdb-filter-start-date" name="start_date" value="<?php echo esc_attr($start_date); ?>" />

        <label for="mealsdb-filter-end-date" class="screen-reader-text"><?php esc_html_e('End Date', 'meals-db'); ?></label>
        <input type="date" id="mealsdb-filter-end-date" name="end_date" value="<?php echo esc_attr($end_date); ?>" />

        <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'meals-db'); ?></button>
        <a href="<?php echo esc_url(admin_url('admin.php?page=mealsdb-transactions')); ?>" class="button"><?php esc_html_e('Reset', 'meals-db'); ?></a>
    </form>

    <?php if (empty($transactions)) : ?>
        <p><?php esc_html_e('No transactions found.', 'meals-db'); ?></p>
    <?php else : ?>
        <table class="widefat striped mealsdb-transactions-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Transaction ID', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Client', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Client Type', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Order Date', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Delivery Date', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Status', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Actions', 'meals-db'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $transaction) :
                    $transaction_id = intval($transaction['transaction_id'] ?? 0);
                    $transaction_client_id = intval($transaction['client_id'] ?? 0);
                    $first_name = isset($transaction['first_name']) ? trim((string) $transaction['first_name']) : '';
                    $last_name = isset($transaction['last_name']) ? trim((string) $transaction['last_name']) : '';
                    $client_name = trim($first_name . ' ' . $last_name);
                    if ($client_name === '' && $transaction_client_id > 0) {
                        /* translators: %d: client ID */
                        $client_name = sprintf(__('Client #%d', 'meals-db'), $transaction_client_id);
                    }

                    $transaction_status = isset($transaction['status']) && in_array($transaction['status'], $allowed_statuses, true)
                        ? $transaction['status']
                        : 'Ordered';
                    $status_class = 'mealsdb-status-label mealsdb-status-' . strtolower($transaction_status);
                    $details_link = add_query_arg(
                        [
                            'page'            => 'mealsdb-transaction',
                            'transaction_id'  => $transaction_id,
                        ],
                        admin_url('admin.php')
                    );
                ?>
                    <tr>
                        <td><?php echo esc_html($transaction_id); ?></td>
                        <td><?php echo esc_html($client_name); ?></td>
                        <td><?php echo esc_html($transaction['client_type'] ?? ''); ?></td>
                        <td><?php echo esc_html(!empty($transaction['order_date']) ? date('Y-m-d', strtotime($transaction['order_date'])) : ''); ?></td>
                        <td><?php echo esc_html(!empty($transaction['delivery_date']) ? date('Y-m-d', strtotime($transaction['delivery_date'])) : ''); ?></td>
                        <td><span class="<?php echo esc_attr($status_class); ?>"><?php echo esc_html($transaction_status); ?></span></td>
                        <td><a class="button button-small" href="<?php echo esc_url($details_link); ?>"><?php esc_html_e('View Details', 'meals-db'); ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1) :
            $prev_page = $paged > 1 ? $paged - 1 : null;
            $next_page = $paged < $total_pages ? $paged + 1 : null;

            $prev_link = $prev_page ? add_query_arg(array_merge($query_args, ['paged' => $prev_page]), admin_url('admin.php')) : '';
            $next_link = $next_page ? add_query_arg(array_merge($query_args, ['paged' => $next_page]), admin_url('admin.php')) : '';
        ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php if ($prev_link) : ?>
                        <a class="prev-page button" href="<?php echo esc_url($prev_link); ?>">&laquo; <?php esc_html_e('Previous', 'meals-db'); ?></a>
                    <?php endif; ?>

                    <span class="pagination-links">
                        <?php printf(
                            /* translators: 1: current page, 2: total pages */
                            esc_html__('Page %1$s of %2$s', 'meals-db'),
                            esc_html(number_format_i18n($paged)),
                            esc_html(number_format_i18n($total_pages))
                        ); ?>
                    </span>

                    <?php if ($next_link) : ?>
                        <a class="next-page button" href="<?php echo esc_url($next_link); ?>"><?php esc_html_e('Next', 'meals-db'); ?> &raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
